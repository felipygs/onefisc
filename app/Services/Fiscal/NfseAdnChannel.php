<?php

namespace App\Services\Fiscal;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Primary NFS-e channel: Ambiente de Dados Nacional (ADN) distribution.
 *
 * Thin HTTP client (Laravel HTTP + mTLS from the Client PFX) against the
 * official resource `GET {base}/contribuintes/DFe/{nsu}`. No vendor package:
 * `nfephp-org/sped-nfse` is abandoned and covers municipal layouts, not the
 * national ADN distribution, so this wrapper speaks the documented JSON
 * envelope directly (official `StatusProcessamento`/`LoteDFe` shape plus the
 * equivalent `items`/`documentos` gateway shapes).
 *
 * Cursor semantics for the `nfse` family: the marker is the ADN NSU (opaque
 * decimal string, exactly like the legacy `nfse_adn` sync context). 404 with
 * `NENHUM_DOCUMENTO_LOCALIZADO*` is normal covered emptiness, NOT a coverage
 * problem. 404 WITH an explicit coverage signal (body `coverage_status` /
 * `coverage` / `reason`, or the `x-adn-coverage: coverage_limited` header)
 * is terminal `limited`; a bare 404 is ambiguous `unknown`.
 *
 * Secrets (PFX bytes/password) live in memory only. The PFX is converted to
 * a 0600 temp PEM for the TLS handshake and unlinked on destruction; it is
 * never placed in messages, evidence, or logs.
 */
final class NfseAdnChannel implements DistributionChannel
{
    private const RESOURCE_PREFIX = '/contribuintes/DFe';

    /**
     * @var array<int, string>
     */
    private array $tempCerts = [];

    private ?string $pemPath = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $pfxContents = null,
        private readonly ?string $pfxPassword = null,
    ) {
        self::assertHttpsEndpoint($baseUrl);
    }

    public function __destruct()
    {
        foreach ($this->tempCerts as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function fetchSince(string $lastNsu): ChannelBatch
    {
        $response = $this->get(self::RESOURCE_PREFIX.'/'.rawurlencode($lastNsu));

        return $this->parseDistribution($response->body(), $response->header('x-adn-coverage'), $response->status(), $lastNsu);
    }

    public function fetchByKey(string $key): ?array
    {
        $response = $this->get(self::RESOURCE_PREFIX.'/'.rawurlencode($key));
        $status = $response->status();

        if ($status === 404) {
            $proof = $this->coverageProof($response);

            if ($proof !== null) {
                throw NfseCoverageException::limited('adn_coverage_unavailable', [
                    'channel' => 'adn',
                    'http_status' => 404,
                ]);
            }

            // Ambiguous: nothing proves missing coverage, so no claim.
            return null;
        }

        if ($status === 401 || $status === 403) {
            throw new NfseTransportException('adn_authentication_rejected', ['channel' => 'adn', 'http_status' => $status]);
        }

        if ($status >= 500 || $status === 429) {
            throw new NfseTransportException('adn_unavailable', ['channel' => 'adn', 'http_status' => $status]);
        }

        if ($status !== 200) {
            throw new NfseTransportException('adn_rejected', ['channel' => 'adn', 'http_status' => $status]);
        }

        $data = $this->decodeJson($response->body());

        if ($data === null) {
            throw new NfseTransportException('adn_envelope_invalid', ['channel' => 'adn']);
        }

        $items = $this->items($data);

        foreach ($items as $item) {
            $found = (string) ($item['key'] ?? $item['chaveAcesso'] ?? $item['ChaveAcesso'] ?? $item['doc_key'] ?? '');

            if ($found !== '' && $found === $key) {
                return [
                    'key' => $found,
                    'xml' => (string) ($item['xml'] ?? $item['conteudo'] ?? $item['ArquivoXml'] ?? ''),
                    'schema' => (string) ($item['schema'] ?? 'nfse_adn_v1'),
                ];
            }
        }

        return null;
    }

    /**
     * Ciencia da Operacao (210210) does not exist for NFS-e: there is nothing
     * to manifest, so this always throws and the runner never manifests the
     * nfse family.
     */
    public function manifestScience(string $key): bool
    {
        throw new LogicException('Ciencia da operacao (210210) nao se aplica a NFS-e.');
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    private function get(string $path): Response
    {
        $url = rtrim($this->baseUrl, '/').$path;

        try {
            return Http::timeout((int) config('fiscal.timeout_seconds', 30))
                ->withOptions(array_merge(['verify' => true], $this->certOption()))
                ->acceptJson()
                ->get($url);
        } catch (Throwable) {
            throw new NfseTransportException('adn_transport_failed', ['channel' => 'adn']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function certOption(): array
    {
        if ($this->pfxContents === null || $this->pfxContents === '') {
            return [];
        }

        if ($this->pemPath !== null) {
            return ['cert' => $this->pemPath];
        }

        $certs = [];
        $opened = openssl_pkcs12_read($this->pfxContents, $certs, $this->pfxPassword ?? '');

        if (! $opened || ! is_string($certs['cert'] ?? null) || ! is_string($certs['pkey'] ?? null)) {
            throw new NfseTransportException('adn_certificate_invalid', ['channel' => 'adn']);
        }

        $path = tempnam(sys_get_temp_dir(), 'nfse-adn-');

        if ($path === false) {
            throw new NfseTransportException('adn_certificate_invalid', ['channel' => 'adn']);
        }

        file_put_contents($path, $certs['cert']."\n".$certs['pkey']);
        chmod($path, 0600);

        $this->pemPath = $path;
        $this->tempCerts[] = $path;

        return ['cert' => $path];
    }

    // ------------------------------------------------------------------
    // Envelope parsing (JSON only in v1; the official ADN API answers JSON)
    // ------------------------------------------------------------------

    private function parseDistribution(string $body, string $coverageHeader, int $status, string $marker): ChannelBatch
    {
        if ($status === 429) {
            throw new NfseTransportException('adn_throttled', ['channel' => 'adn', 'http_status' => 429]);
        }

        if ($status >= 500) {
            throw new NfseTransportException('adn_unavailable', ['channel' => 'adn', 'http_status' => $status]);
        }

        if ($status === 401 || $status === 403) {
            throw new NfseTransportException('adn_authentication_rejected', ['channel' => 'adn', 'http_status' => $status]);
        }

        if ($status === 404) {
            return $this->parseNotFound($body, $coverageHeader, $marker);
        }

        if ($status !== 200) {
            throw new NfseTransportException('adn_rejected', ['channel' => 'adn', 'http_status' => $status]);
        }

        $data = $this->decodeJson($body);

        if ($data === null) {
            throw new NfseTransportException('adn_envelope_invalid', ['channel' => 'adn']);
        }

        return $this->batchFromArray($data, $marker);
    }

    private function parseNotFound(string $body, string $coverageHeader, string $marker): ChannelBatch
    {
        $data = $this->decodeJson($body);

        if (is_array($data)) {
            $providerStatus = strtoupper(trim((string) ($data['StatusProcessamento'] ?? $data['statusProcessamento'] ?? '')));

            // The official API uses 404 as the normal no-documents answer for
            // a valid contributor/NSU: covered emptiness, hold the cursor.
            if (in_array($providerStatus, ['NENHUM_DOCUMENTO_LOCALIZADO', 'NENHUM_DOCUMENTO_LOCALIZADO_PARA_O_CONTRIBUINTE'], true)) {
                return new ChannelBatch(items: [], lastNsu: $marker);
            }

            if ($this->isCoverageSignal($data) || strtolower(trim($coverageHeader)) === 'coverage_limited') {
                throw NfseCoverageException::limited('adn_coverage_unavailable', [
                    'channel' => 'adn',
                    'http_status' => 404,
                    'provider_status' => $providerStatus !== '' ? $providerStatus : null,
                ]);
            }
        } elseif (strtolower(trim($coverageHeader)) === 'coverage_limited') {
            throw NfseCoverageException::limited('adn_coverage_unavailable', [
                'channel' => 'adn',
                'http_status' => 404,
            ]);
        }

        // Bare 404 proves nothing: unknown, retry normally next cycle.
        throw NfseCoverageException::unknown('adn_endpoint_or_resource_not_found', [
            'channel' => 'adn',
            'http_status' => 404,
        ]);
    }

    /**
     * Explicit non-adherence proof: body coverage fields or the gateway
     * `x-adn-coverage` header. Checked by callers that hold the response.
     */
    private function coverageProof(Response $response): ?string
    {
        $data = $this->decodeJson($response->body());

        if (is_array($data) && $this->isCoverageSignal($data)) {
            return 'body';
        }

        if (strtolower(trim($response->header('x-adn-coverage'))) === 'coverage_limited') {
            return 'header';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isCoverageSignal(array $data): bool
    {
        $coverage = strtolower(trim((string) ($data['coverage_status'] ?? $data['coverage'] ?? $data['reason'] ?? '')));

        if ($coverage === '') {
            return false;
        }

        return in_array($coverage, [
            'coverage_limited',
            'not_participating',
            'municipality_not_participating',
            'outside_coverage',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function batchFromArray(array $data, string $marker): ChannelBatch
    {
        $cStat = $this->stringValue($data, ['cStat', 'cstat', 'StatusProcessamento', 'statusProcessamento']);
        $normalized = $cStat !== null ? strtoupper(trim($cStat)) : null;

        if (in_array($normalized, ['NENHUM_DOCUMENTO_LOCALIZADO', 'NENHUM_DOCUMENTO_LOCALIZADO_PARA_O_CONTRIBUINTE'], true)) {
            return new ChannelBatch(items: [], lastNsu: $marker);
        }

        if ($normalized === '656') {
            throw new NfseTransportException('adn_throttled', ['channel' => 'adn']);
        }

        if ($normalized === '137') {
            return new ChannelBatch(items: [], lastNsu: $marker);
        }

        if ($normalized !== null && $normalized !== '138' && $normalized !== '200' && $normalized !== '100'
            && $normalized !== 'DOCUMENTOS_LOCALIZADOS') {
            throw new NfseTransportException('adn_provider_rejected', ['channel' => 'adn', 'provider_status' => $normalized]);
        }

        $items = $this->items($data);

        $ultNsu = $this->stringValue($data, ['ultNSU', 'ult_nsu', 'NSU', 'nsu'])
            ?? $this->greatestItemNsu($items)
            ?? $marker;

        if (! self::validNsu($ultNsu)) {
            throw new NfseTransportException('adn_nsu_invalid', ['channel' => 'adn']);
        }

        if (self::compareNsu($ultNsu, $marker) < 0) {
            throw new NfseTransportException('adn_cursor_regressed', ['channel' => 'adn']);
        }

        $batchItems = [];

        foreach ($items as $item) {
            $payload = (string) ($item['xml'] ?? $item['conteudo'] ?? $item['documento'] ?? $item['docZip'] ?? $item['ArquivoXml'] ?? '');

            if ($payload === '') {
                throw new NfseTransportException('adn_payload_missing', ['channel' => 'adn']);
            }

            $itemNsu = $this->stringValue($item, ['nsu', 'NSU']);

            if ($itemNsu !== null && ! self::validNsu($itemNsu)) {
                throw new NfseTransportException('adn_item_nsu_invalid', ['channel' => 'adn']);
            }

            $batchItems[] = [
                'key' => (string) ($item['key'] ?? $item['chaveAcesso'] ?? $item['ChaveAcesso'] ?? $item['doc_key'] ?? ''),
                'nsu' => $itemNsu,
                'xml' => $payload,
                'schema' => (string) ($item['schema'] ?? $item['schema_version'] ?? 'nfse_adn_v1'),
            ];
        }

        if ($normalized === 'DOCUMENTOS_LOCALIZADOS' && $batchItems === []) {
            throw new NfseTransportException('adn_payload_missing', ['channel' => 'adn']);
        }

        return new ChannelBatch(items: $batchItems, lastNsu: $ultNsu);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function items(array $data): array
    {
        $items = $data['items'] ?? $data['documentos'] ?? $data['documents'] ?? $data['LoteDFe'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, fn ($item): bool => is_array($item)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function stringValue(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function greatestItemNsu(array $items): ?string
    {
        $greatest = null;

        foreach ($items as $item) {
            $candidate = $this->stringValue($item, ['nsu', 'NSU']);

            if ($candidate !== null && self::validNsu($candidate)
                && ($greatest === null || self::compareNsu($candidate, $greatest) > 0)) {
                $greatest = $candidate;
            }
        }

        return $greatest;
    }

    private static function validNsu(?string $value): bool
    {
        return $value !== null && $value !== '' && ctype_digit($value);
    }

    /**
     * NSUs are unbounded decimal strings: compare by length first so large
     * values never lose precision through int/float coercion.
     */
    private static function compareNsu(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right);
    }

    private static function assertHttpsEndpoint(string $baseUrl): void
    {
        $parts = parse_url(trim($baseUrl));

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new InvalidArgumentException('Endpoint ADN invalido: esperado https sem query/fragmento/credenciais.');
        }
    }
}
