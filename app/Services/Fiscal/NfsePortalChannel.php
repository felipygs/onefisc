<?php

namespace App\Services\Fiscal;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Fallback NFS-e channel: Emissor Nacional portal (HTML listing + official
 * XML download). Used only when the ADN primary fails; never a municipal
 * provider — this class only ever talks to the national portal base URL.
 *
 * v1 scope (no captcha solver, per design): the portal password authenticates
 * the session, and ANY captcha/anti-bot challenge aborts the run with
 * terminal `limited` reason `portal_captcha` — no solver, no blind retry, no
 * Visualizar fallback. Listing covers one trailing 30-day window per side
 * (Emitidas + Recebidas) with `rel="next"` pagination capped at 5 pages and
 * 20 notes per run; the marker is ignored (content is key-addressed and the
 * runner keeps the ADN NSU as the cursor of record).
 *
 * The password lives in memory only: it is sent in the login form and never
 * placed in messages, evidence, or logs.
 */
final class NfsePortalChannel implements DistributionChannel
{
    private const MAX_PAGES_PER_SIDE = 5;

    private const MAX_NOTES_PER_RUN = 20;

    /**
     * @var array<int, string>
     */
    private const CAPTCHA_MARKERS = ['h-captcha', 'g-recaptcha', 'cf-challenge', 'cf_challenge', 'captcha'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $cnpj,
        private readonly string $portalPassword,
    ) {
        self::assertHttpsEndpoint($baseUrl);
    }

    public function fetchSince(string $lastNsu): ChannelBatch
    {
        $this->login();

        $keys = [];

        foreach (['Emitidas', 'Recebidas'] as $side) {
            foreach ($this->listKeys($side) as $key) {
                if (! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }

                if (count($keys) >= self::MAX_NOTES_PER_RUN) {
                    break 2;
                }
            }
        }

        $items = [];

        foreach ($keys as $key) {
            $items[] = [
                'key' => $key,
                'nsu' => $key,
                'xml' => $this->download($key),
                'schema' => 'nfse_portal_signed_v1',
            ];
        }

        return new ChannelBatch(items: $items, lastNsu: $keys === [] ? $lastNsu : end($keys));
    }

    public function fetchByKey(string $key): ?array
    {
        $this->login();

        $response = $this->send(fn () => Http::timeout($this->timeout())
            ->get($this->url('/EmissorNacional/Notas/Download/NFSe/'.$key)), 'portal_transport_failed');

        if ($response->status() === 404) {
            return null;
        }

        $this->assertNoCaptcha($response, 'download');

        if ($response->status() !== 200 || ! $this->looksLikeNfseXml($response->body())) {
            throw new NfseTransportException('portal_unavailable', ['channel' => 'portal', 'phase' => 'download']);
        }

        return ['key' => $key, 'xml' => $response->body(), 'schema' => 'nfse_portal_signed_v1'];
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
    // Login + listing + download
    // ------------------------------------------------------------------

    private function login(): void
    {
        $response = $this->send(fn () => Http::timeout($this->timeout())
            ->asForm()
            ->post($this->url('/EmissorNacional/Login'), [
                'cnpj' => $this->cnpj,
                'senha' => $this->portalPassword,
            ]), 'portal_transport_failed');

        $this->assertNoCaptcha($response, 'login');

        $body = $response->body();

        if ($response->status() === 401 || $response->status() === 403
            || str_contains($body, 'name="Senha"')
            || (! str_contains($body, 'Notas/Emitidas') && ! str_contains($body, 'Painel'))) {
            throw new NfseTransportException('portal_login_rejected', ['channel' => 'portal', 'phase' => 'login']);
        }
    }

    /**
     * @return array<int, string>
     */
    private function listKeys(string $side): array
    {
        $window = $this->window();
        $url = $this->url("/EmissorNacional/Notas/{$side}").'?datainicio='.$window['from'].'&datafim='.$window['to'].'&pg=1&busca='
            .($side === 'Recebidas' ? '&executar=1' : '');

        $keys = [];
        $pages = 0;

        while ($url !== null && $pages < self::MAX_PAGES_PER_SIDE) {
            $pages++;

            $response = $this->send(fn () => Http::timeout($this->timeout())->get($url), 'portal_transport_failed');

            $this->assertNoCaptcha($response, 'listing');

            if ($response->status() !== 200) {
                throw new NfseTransportException('portal_unavailable', [
                    'channel' => 'portal',
                    'phase' => 'listing',
                    'http_status' => $response->status(),
                ]);
            }

            foreach ($this->extractKeys($response->body()) as $key) {
                if (! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }

            $url = $this->nextPage($response->body());
        }

        return $keys;
    }

    private function download(string $key): string
    {
        $response = $this->send(fn () => Http::timeout($this->timeout())
            ->get($this->url('/EmissorNacional/Notas/Download/NFSe/'.$key)), 'portal_transport_failed');

        // Any anti-bot wall on the download aborts the whole run as terminal
        // portal_captcha: v1 has no solver and retries blindly never unlock it.
        $this->assertNoCaptcha($response, 'download');

        if ($response->status() === 403 || ! $this->looksLikeNfseXml($response->body())) {
            throw new NfseTransportException('portal_unavailable', [
                'channel' => 'portal',
                'phase' => 'download',
                'http_status' => $response->status(),
            ]);
        }

        return $response->body();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  callable(): Response  $call
     */
    private function send(callable $call, string $reason): Response
    {
        try {
            return $call();
        } catch (NfseCoverageException|NfseTransportException $e) {
            throw $e;
        } catch (Throwable) {
            throw new NfseTransportException($reason, ['channel' => 'portal']);
        }
    }

    private function assertNoCaptcha(Response $response, string $phase): void
    {
        $body = strtolower($response->body());

        foreach (self::CAPTCHA_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                throw NfseCoverageException::limited('portal_captcha', [
                    'channel' => 'portal',
                    'phase' => $phase,
                ]);
            }
        }

        if ($response->status() === 403 && $phase === 'download') {
            throw NfseCoverageException::limited('portal_captcha', [
                'channel' => 'portal',
                'phase' => $phase,
            ]);
        }
    }

    private function looksLikeNfseXml(string $body): bool
    {
        return str_contains($body, '<') && stripos($body, 'nfse') !== false;
    }

    /**
     * @return array<int, string>
     */
    private function extractKeys(string $html): array
    {
        if (preg_match_all('#/EmissorNacional/Notas/Download/NFSe/([A-Za-z0-9]+)#', $html, $matches) === false) {
            return [];
        }

        $keys = [];

        foreach ($matches[1] as $candidate) {
            if (! in_array($candidate, $keys, true)) {
                $keys[] = $candidate;
            }
        }

        return $keys;
    }

    private function nextPage(string $html): ?string
    {
        if (preg_match('#<a[^>]+rel="next"[^>]+href="([^"]+)"#i', $html, $matches) !== 1) {
            return null;
        }

        $href = trim($matches[1]);

        if ($href === '') {
            return null;
        }

        if (str_starts_with($href, 'http')) {
            return str_starts_with($href, rtrim($this->baseUrl, '/')) ? $href : null;
        }

        return $this->url($href);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function window(): array
    {
        $to = now('America/Sao_Paulo');
        $from = (clone $to)->subDays(29);

        return ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    private function timeout(): int
    {
        return (int) config('fiscal.timeout_seconds', 60);
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
            throw new InvalidArgumentException('Endpoint do portal invalido: esperado https sem query/fragmento/credenciais.');
        }
    }
}
