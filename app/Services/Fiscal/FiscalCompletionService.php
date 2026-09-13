<?php

namespace App\Services\Fiscal;

use App\Models\Client;
use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Completion step for pending fiscal documents (task 4.1).
 *
 * The sync persists summary ROWS first (task 3.3: `has_xml=false`); this
 * step runs at the end of every runner cycle and, for each still-pending
 * document of the subscription family, downloads the full XML through the
 * active channel (`fetchByKey` — NF-e post-ciencia, CT-e directly, NFS-e
 * through the active ADN/portal channel), stores the bytes on the private
 * disk, enriches the metadata the summary did not carry (issuer, recipient,
 * number, series, emission — only blank fields are filled), and flips
 * `has_xml=true`. A best-effort DANFE/DANFSe render follows for nfe/nfse
 * (CT-e honestly keeps `has_danfe=false`: no DACTE in v1).
 *
 * Failures (unknown key, transport error, invalid XML, render error) skip
 * the document without fatal error so the next cycle retries it.
 *
 * Volume: completion only UPDATES existing rows, so it never counts again
 * toward the Plan volume — counting happened once at row persistence time
 * (PlanLimitService counts `fiscal_documents` rows).
 */
final class FiscalCompletionService
{
    private const MAX_COMPLETIONS_PER_RUN = 20;

    public function __construct(
        private readonly FiscalStorageService $storage = new FiscalStorageService,
        private readonly FiscalPdfService $pdfs = new FiscalPdfService,
    ) {}

    /**
     * Complete pending documents for one client/family.
     *
     * @return int How many documents were completed this run.
     */
    public function completePending(Client $client, DistributionChannel $channel, string $family): int
    {
        $family = strtolower($family);

        if (! in_array($family, ['nfe', 'cte', 'nfse'], true)) {
            return 0;
        }

        $pendings = FiscalDocument::withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->where('family', $family)
            ->where('has_xml', false)
            ->orderBy('id')
            ->limit(self::MAX_COMPLETIONS_PER_RUN)
            ->get();

        $completed = 0;

        foreach ($pendings as $document) {
            try {
                if ($this->completeOne($document, $channel, $family)) {
                    $completed++;
                }
            } catch (Throwable $e) {
                Log::warning('fiscal.completion.failed', [
                    'client_id' => $client->id,
                    'fiscal_document_id' => $document->id,
                    'error' => $e::class,
                ]);
            }
        }

        return $completed;
    }

    private function completeOne(FiscalDocument $document, DistributionChannel $channel, string $family): bool
    {
        $key = (string) $document->key;

        if ($key === '') {
            return false;
        }

        try {
            $found = $channel->fetchByKey($key);
        } catch (Throwable $e) {
            Log::warning('fiscal.completion.fetch_failed', [
                'fiscal_document_id' => $document->id,
                'error' => $e::class,
            ]);

            return false;
        }

        $xml = is_array($found) ? ($found['xml'] ?? null) : null;

        if (! is_string($xml) || trim($xml) === '') {
            // Unknown at SEFAZ or empty payload: retry next cycle.
            return false;
        }

        $meta = FiscalXmlMeta::extract($xml, $family);

        if (! self::hasAnyMeta($meta)) {
            // Not a parseable fiscal document (garbage/foreign payload):
            // store nothing, flip nothing, retry next cycle.
            Log::warning('fiscal.completion.invalid_xml', [
                'fiscal_document_id' => $document->id,
                'family' => $family,
            ]);

            return false;
        }

        $this->storage->putXml($document, $xml);
        $this->fillFromMeta($document, $meta);

        if (in_array($family, ['nfe', 'nfse'], true)) {
            $this->pdfs->tryRender($document);
        }

        return true;
    }

    /**
     * @param  array<string, string|null>  $meta
     */
    private static function hasAnyMeta(array $meta): bool
    {
        foreach ($meta as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fill only the fields the summary row left blank — never overwrite
     * metadata the distribution summary already provided.
     *
     * @param  array<string, string|null>  $meta  Pre-extracted (and validated) XML metadata.
     */
    private function fillFromMeta(FiscalDocument $document, array $meta): void
    {
        $fillable = [];

        foreach ($meta as $field => $value) {
            if ($value !== null && blank($document->getAttribute($field))) {
                $fillable[$field] = $value;
            }
        }

        if ($fillable !== []) {
            $document->forceFill($fillable)->save();
        }
    }
}
