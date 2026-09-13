<?php

namespace App\Services\Fiscal;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAction;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Automatic Ciencia da Operacao (210210) + pending persistence (task 3.3).
 *
 * "Download" here means manifesting ciencia (which RELEASES the XML at
 * SEFAZ) + persisting the PENDING row (summary metadata, has_xml=false).
 * Fetching the XML bytes + writing them to private disk stays in task 4.1:
 * this service never writes XML files and creates no storage paths.
 *
 * NF-e: manifest ciencia per summary key, then persist the document as
 * pending with a system (NULL actor) action + audit row. CT-e has no
 * ciencia event: summaries persist as pending without manifesting.
 *
 * Idempotent: already-persisted keys are never re-manifested and never
 * duplicated. Fail-stop: the first SEFAZ failure stops the batch so the
 * runner holds the cursor on the unprocessed item for the next cycle.
 */
final class ScienceService
{
    public const ACTION_AUTO_SCIENCE = 'ciencia_210210_auto';

    public const AUDIT_AUTO_SCIENCE = 'fiscal.science.auto';

    /**
     * Process one page of summaries in order.
     *
     * Items without an extractable/valid key are skipped with a warning
     * (counted as processed: there is nothing to manifest or persist, and
     * a poison item must never stall the channel).
     *
     * @param  array<int, array<string, mixed>>  $resumos  Batch items (nsu/schema/xml, or explicit key fields).
     * @return int How many leading items were fully handled (fail-stop prefix).
     */
    public function applyPending(Client $client, array $resumos, DistributionChannel $channel, string $family): int
    {
        $family = strtolower($family);

        if (! in_array($family, ['nfe', 'cte'], true)) {
            throw new InvalidArgumentException("Ciencia automatica sem suporte para a familia: {$family}.");
        }

        $processed = 0;

        foreach ($resumos as $resumo) {
            $key = self::extractKey($resumo);

            if ($key === null) {
                Log::warning('fiscal.science.skipped', [
                    'client_id' => $client->id,
                    'nsu' => $resumo['nsu'] ?? null,
                    'reason' => 'missing_key',
                ]);

                $processed++;

                continue;
            }

            if ($family !== 'nfe') {
                $this->persistPending($client, $resumo, $key, $family);
                $processed++;

                continue;
            }

            if ($this->alreadyPending($client->id, $key)) {
                $processed++;

                continue;
            }

            try {
                $manifested = $channel->manifestScience($key);
            } catch (Throwable $e) {
                Log::warning('fiscal.science.failed', [
                    'client_id' => $client->id,
                    'key' => $key,
                    'error' => $e::class,
                ]);

                break;
            }

            if (! $manifested) {
                Log::warning('fiscal.science.refused', ['client_id' => $client->id, 'key' => $key]);

                break;
            }

            $this->persistPendingWithScience($client, $resumo, $key);
            $processed++;
        }

        return $processed;
    }

    private function alreadyPending(int $clientId, string $key): bool
    {
        return FiscalDocument::withoutGlobalScopes()
            ->where('client_id', $clientId)
            ->where('key', $key)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $resumo
     */
    private function persistPendingWithScience(Client $client, array $resumo, string $key): void
    {
        $document = $this->persistPending($client, $resumo, $key, 'nfe');

        FiscalDocumentAction::withoutGlobalScopes()->create([
            'fiscal_document_id' => $document->id,
            'type' => self::ACTION_AUTO_SCIENCE,
            'actor_user_id' => null,
            'metadata' => ['key' => $key],
        ]);

        AuditLog::create([
            'actor_user_id' => null,
            'origin_account_id' => $client->account_id,
            'target_account_id' => $client->account_id,
            'action' => self::AUDIT_AUTO_SCIENCE,
            'metadata' => ['client_id' => $client->id, 'key' => $key],
        ]);
    }

    /**
     * @param  array<string, mixed>  $resumo
     */
    private function persistPending(Client $client, array $resumo, string $key, string $family): FiscalDocument
    {
        $meta = self::resumoMeta($resumo);

        return FiscalDocument::withoutGlobalScopes()->firstOrCreate(
            ['client_id' => $client->id, 'key' => $key],
            [
                'family' => $family,
                'doc_type' => $family,
                'number' => self::stringField($resumo, 'number'),
                'series' => self::stringField($resumo, 'series'),
                'issuer_name' => $meta['issuer_name'],
                'issuer_tax_id' => $meta['issuer_tax_id'],
                'emission_at' => $meta['emission_at'],
                'status' => 'pending',
                'has_xml' => false,
            ]
        );
    }

    /**
     * Key precedence: explicit fields first, then the summary XML
     * (chNFe/chCTe tags, falling back to the infNFe/infCTe Id suffix).
     * Only well-formed 44-digit keys are returned.
     *
     * @param  array<string, mixed>  $resumo
     */
    private static function extractKey(array $resumo): ?string
    {
        foreach (['key', 'chNFe', 'chCTe', 'chave'] as $field) {
            $candidate = $resumo[$field] ?? null;

            if (is_string($candidate) && self::isAccessKey($candidate)) {
                return $candidate;
            }
        }

        $xml = $resumo['xml'] ?? null;

        if (! is_string($xml) || $xml === '') {
            return null;
        }

        $dom = self::loadXml($xml);

        if ($dom === null) {
            return null;
        }

        foreach (['chNFe', 'chCTe'] as $tag) {
            $node = $dom->getElementsByTagName($tag)->item(0);

            if ($node instanceof DOMElement && self::isAccessKey(trim((string) $node->textContent))) {
                return trim((string) $node->textContent);
            }
        }

        foreach (['infNFe', 'infCTe'] as $tag) {
            $node = $dom->getElementsByTagName($tag)->item(0);

            if ($node instanceof DOMElement) {
                $suffix = substr($node->getAttribute('Id'), -44);

                if (self::isAccessKey($suffix)) {
                    return $suffix;
                }
            }
        }

        return null;
    }

    private static function isAccessKey(string $candidate): bool
    {
        return strlen($candidate) === 44 && ctype_digit($candidate);
    }

    /**
     * @param  array<string, mixed>  $resumo
     * @return array{issuer_name: string|null, issuer_tax_id: string|null, emission_at: string|null}
     */
    private static function resumoMeta(array $resumo): array
    {
        $meta = [
            'issuer_name' => self::stringField($resumo, 'issuer_name'),
            'issuer_tax_id' => self::stringField($resumo, 'issuer_tax_id'),
            'emission_at' => self::datetimeField($resumo, 'emission_at'),
        ];

        if ($meta['issuer_name'] !== null && $meta['issuer_tax_id'] !== null && $meta['emission_at'] !== null) {
            return $meta;
        }

        $xml = $resumo['xml'] ?? null;

        if (! is_string($xml) || $xml === '') {
            return $meta;
        }

        $dom = self::loadXml($xml);

        if ($dom === null) {
            return $meta;
        }

        $text = static fn (string $tag): ?string => self::tagText($dom, $tag);

        $issuerTaxId = $text('CNPJ') ?? $text('CPF');

        return [
            'issuer_name' => $meta['issuer_name'] ?? $text('xNome'),
            'issuer_tax_id' => $meta['issuer_tax_id'] ?? $issuerTaxId,
            'emission_at' => $meta['emission_at'] ?? self::asDatetime($text('dhEmi')),
        ];
    }

    private static function tagText(DOMDocument $dom, string $tag): ?string
    {
        $node = $dom->getElementsByTagName($tag)->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $text = trim((string) $node->textContent);

        return $text === '' ? null : $text;
    }

    private static function loadXml(string $xml): ?DOMDocument
    {
        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            if (! $dom->loadXML($xml)) {
                return null;
            }
        } finally {
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    /**
     * @param  array<string, mixed>  $resumo
     */
    private static function stringField(array $resumo, string $field): ?string
    {
        $value = $resumo[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $resumo
     */
    private static function datetimeField(array $resumo, string $field): ?string
    {
        $value = $resumo[$field] ?? null;

        if (! is_string($value)) {
            return null;
        }

        return self::asDatetime($value);
    }

    private static function asDatetime(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || strtotime($value) === false) {
            return null;
        }

        return trim($value);
    }
}
