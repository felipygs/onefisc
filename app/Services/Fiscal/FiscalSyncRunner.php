<?php

namespace App\Services\Fiscal;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalCoverageEvidence;
use App\Models\FiscalDocument;
use App\Models\FiscalSyncCursor;
use App\Models\FiscalSyncSubscription;
use App\Services\AuditService;
use App\Services\PlanLimitService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one incremental sync cycle: cursor mechanics + SEFAZ pause handling
 * + automatic ciencia with pending persistence (task 3.3) + XML completion
 * on private disk with DANFE/DANFSe (task 4.1). Each persisted
 * document counts 1 toward the Plan volume (task 3.5): an exhausted volume
 * suspends the batch as `volume_exhausted` WITHOUT persisting anything and
 * WITHOUT moving the cursor past the unpersisted items.
 *
 * No valid credential (missing, incomplete or expired) suspends the run
 * without ever calling SEFAZ. A SEFAZ pause (cStat 137/656) records blocked_until at the
 * next full hour without advancing past the confirmed NSU.
 *
 * NFS-e (tasks 3.4 + 4.1) rides a dedicated branch: the ADN primary is paged
 * with the NSU cursor, rows persist BEFORE the cursor advances (national
 * keys are 50 digits, hence the key-50 widening), and honest coverage is
 * enforced — terminal `limited` evidence short-circuits the run without
 * touching any channel, `unknown` retries normally, and an ADN transport
 * failure with zero progress falls back to the portal channel (whose
 * key-addressed content never moves the ADN cursor).
 *
 * Every cycle ends with the completion step (FiscalCompletionService): pending
 * `has_xml=false` rows of the subscription family download their full XML
 * through the active channel, land on the private disk, and get their
 * metadata enriched. Completion never fails the run and never recounts
 * volume (it only updates existing rows).
 */
final class FiscalSyncRunner
{
    private const MAX_PAGES_PER_RUN = 20;

    public const AUDIT_CYCLE = 'fiscal.sync.cycle';

    public function __construct(
        private readonly FiscalChannelFactory $channels = new FiscalChannelFactory,
        private readonly ScienceService $science = new ScienceService,
        private readonly PlanLimitService $limits = new PlanLimitService,
        private readonly FiscalCompletionService $completion = new FiscalCompletionService,
        private readonly ?AuditService $audit = null,
    ) {}

    /**
     * Run one incremental sync cycle and audit its outcome (task 5.1): every
     * executed cycle writes exactly one `fiscal.sync.cycle` audit with a
     * NULL (system) actor, the Client's Account as origin+target, and a
     * secret-free metadata payload (client_id, family, new_documents,
     * result, plus a short detail such as blocked_until or reason).
     *
     * A channel/transport exception never escapes: it becomes a `failed`
     * result with the exception CLASS as the short reason (never the
     * message, which may carry provider payloads or secrets).
     */
    public function run(FiscalSyncSubscription $subscription): SyncResult
    {
        $subscription = FiscalSyncSubscription::withoutGlobalScopes()->findOrFail($subscription->id);

        $failureReason = null;

        try {
            $result = $this->runInner($subscription);
        } catch (Throwable $e) {
            Log::warning('fiscal.sync.failed', [
                'client_id' => $subscription->client_id,
                'family' => $subscription->family,
                'error' => $e::class,
            ]);

            $failureReason = class_basename($e);
            $result = new SyncResult(status: 'failed', lastNsu: $this->currentNsu($subscription));
        }

        $this->auditCycle($subscription, $result, $failureReason);

        return $result;
    }

    private function runInner(FiscalSyncSubscription $subscription): SyncResult
    {
        if ($subscription->blocked_until !== null && $subscription->blocked_until->isFuture()) {
            return new SyncResult(status: 'paused', fetched: 0, lastNsu: $this->currentNsu($subscription));
        }

        if (! $this->hasValidCredential($subscription->client_id)) {
            return new SyncResult(status: 'suspended');
        }

        if (strtolower($subscription->family) === 'nfse' && $this->hasTerminalCoverage($subscription)) {
            return new SyncResult(status: 'limited', lastNsu: $this->currentNsu($subscription));
        }

        $channel = $this->channels->for($subscription);
        $client = Client::withoutGlobalScopes()->findOrFail($subscription->client_id);

        $cursor = FiscalSyncCursor::withoutGlobalScopes()->firstOrCreate(
            [
                'client_id' => $subscription->client_id,
                'family' => $subscription->family,
                'environment' => $subscription->environment,
            ],
            ['last_nsu' => '0']
        );

        if (strtolower($subscription->family) === 'nfse') {
            return $this->runNfse($subscription, $cursor, $channel);
        }

        $fetched = 0;
        $lastNsu = (string) $cursor->last_nsu;

        for ($page = 0; $page < self::MAX_PAGES_PER_RUN; $page++) {
            $before = $lastNsu;
            $batch = $channel->fetchSince($lastNsu);

            if ($batch->pause !== null) {
                $subscription->blocked_until = now()->startOfHour()->addHour();
                $subscription->save();

                return new SyncResult(status: 'paused', fetched: $fetched, lastNsu: $lastNsu, pause: $batch->pause);
            }

            if ($batch->items === []) {
                break;
            }

            // Ciencia + pending persistence BEFORE the cursor moves: the
            // service fail-stops, so a SEFAZ failure holds the cursor on
            // the unprocessed item for the next cycle.
            $items = array_values($batch->items);

            try {
                $account = Account::withoutGlobalScopes()->findOrFail($client->account_id);
                $this->limits->ensureVolume($account);
            } catch (ValidationException) {
                // Exhausted Plan volume: persist nothing, keep the cursor on
                // the unpersisted items, report suspension (no fatal error).
                return new SyncResult(status: 'volume_exhausted', fetched: $fetched, lastNsu: $lastNsu);
            }

            $processed = $this->science->applyPending($client, $items, $channel, $subscription->family);
            $fetched += $processed;

            if ($processed > 0) {
                $lastNsu = $this->advanceNsu($items, $processed, $batch->lastNsu);
                $cursor->last_nsu = $lastNsu;
                $cursor->save();
            }

            if ($processed < count($items)) {
                break;
            }

            if ($batch->lastNsu === $before) {
                break;
            }
        }

        $this->runCompletion($client, $channel, $subscription->family);

        return new SyncResult(status: $fetched > 0 ? 'synced' : 'empty', fetched: $fetched, lastNsu: $lastNsu);
    }

    /**
     * Map the precise runner status onto the five audited cycle results:
     * ok (synced/empty), blocked (paused/limited), suspended, volume_exhausted
     * or failed (unknown/failed). The precise status rides along as detail.
     */
    private function cycleResult(SyncResult $result): string
    {
        return match ($result->status) {
            'synced', 'empty' => 'ok',
            'paused', 'limited' => 'blocked',
            'suspended' => 'suspended',
            'volume_exhausted' => 'volume_exhausted',
            default => 'failed',
        };
    }

    /**
     * Best-effort cycle audit (task 5.1): one row per executed cycle, system
     * actor, secret-free metadata. Never throws.
     */
    private function auditCycle(FiscalSyncSubscription $subscription, SyncResult $result, ?string $failureReason = null): void
    {
        try {
            $client = Client::withoutGlobalScopes()->find($subscription->client_id);

            if ($client === null) {
                return;
            }

            $metadata = [
                'client_id' => $client->id,
                'family' => $subscription->family,
                'new_documents' => $result->fetched,
                'result' => $this->cycleResult($result),
                'status' => $result->status,
            ];

            $reason = $failureReason ?? match ($result->status) {
                'paused' => $result->pause,
                'suspended' => 'credential_missing_or_expired',
                'volume_exhausted' => 'plan_volume_exhausted',
                'limited', 'unknown' => $this->coverageReason($subscription),
                default => null,
            };

            if (is_string($reason) && $reason !== '') {
                $metadata['reason'] = $reason;
            }

            $blockedUntil = $subscription->blocked_until;

            if ($blockedUntil !== null && $result->status === 'paused') {
                $metadata['blocked_until'] = $blockedUntil->toIso8601String();
            }

            ($this->audit ?? new AuditService)->recordSystem(
                action: self::AUDIT_CYCLE,
                accountId: (int) $client->account_id,
                metadata: $metadata,
            );
        } catch (Throwable $e) {
            Log::warning('fiscal.sync.audit_skipped', [
                'client_id' => $subscription->client_id,
                'family' => $subscription->family,
                'error' => $e::class,
            ]);
        }
    }

    private function coverageReason(FiscalSyncSubscription $subscription): ?string
    {
        $evidence = FiscalCoverageEvidence::withoutGlobalScopes()
            ->where('client_id', $subscription->client_id)
            ->where('family', $subscription->family)
            ->first();

        $reason = $evidence?->reason;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * NFS-e branch: page the ADN primary with the NSU cursor, persisting rows
     * BEFORE the cursor advances (task 4.1), then complete pending XMLs.
     *
     * Coverage exceptions short-circuit into evidence + terminal/unknown
     * results. A transport failure with zero progress falls back to the
     * portal; after partial progress the cursor already moved, so the run
     * records `unknown` and retries from ADN next cycle instead of mixing
     * portal keys into the NSU cursor.
     */
    private function runNfse(
        FiscalSyncSubscription $subscription,
        FiscalSyncCursor $cursor,
        DistributionChannel $channel,
    ): SyncResult {
        $lastNsu = (string) $cursor->last_nsu;
        $fetched = 0;

        $client = Client::withoutGlobalScopes()->findOrFail($subscription->client_id);

        try {
            for ($page = 0; $page < self::MAX_PAGES_PER_RUN; $page++) {
                $before = $lastNsu;
                $batch = $channel->fetchSince($lastNsu);

                if ($batch->items === []) {
                    break;
                }

                try {
                    $account = Account::withoutGlobalScopes()->findOrFail($client->account_id);
                    $this->limits->ensureVolume($account);
                } catch (ValidationException) {
                    // Exhausted Plan volume: persist nothing, keep the cursor
                    // on the unpersisted items, report suspension (no fatal).
                    return new SyncResult(status: 'volume_exhausted', fetched: $fetched, lastNsu: $lastNsu);
                }

                $this->persistNfseItems($client, $batch->items);
                $fetched += count($batch->items);

                if ($batch->lastNsu !== '') {
                    $lastNsu = $batch->lastNsu;
                    $cursor->last_nsu = $lastNsu;
                    $cursor->save();
                }

                if ($batch->lastNsu === $before) {
                    break;
                }
            }
        } catch (NfseCoverageException $e) {
            $this->recordCoverage($subscription, $e->status, $e->reason, $e->evidence);

            return new SyncResult(status: $e->status, fetched: $fetched, lastNsu: $lastNsu);
        } catch (NfseTransportException $e) {
            if ($fetched > 0) {
                $this->recordCoverage($subscription, 'unknown', $e->reason, $e->evidence);

                return new SyncResult(status: 'unknown', fetched: $fetched, lastNsu: $lastNsu);
            }

            return $this->runNfsePortalFallback($subscription, $client, $lastNsu, $e->reason);
        }

        $this->runCompletion($client, $channel, $subscription->family);

        return new SyncResult(status: $fetched > 0 ? 'synced' : 'empty', fetched: $fetched, lastNsu: $lastNsu);
    }

    /**
     * Portal fallback after a clean ADN failure: one listing window, rows
     * persist as pending (cursor untouched: ADN NSU stays the cursor of
     * record), then the portal — the active channel here — completes XMLs.
     */
    private function runNfsePortalFallback(
        FiscalSyncSubscription $subscription,
        Client $client,
        string $lastNsu,
        string $adnReason,
    ): SyncResult {
        try {
            $portal = $this->channels->portalFor($subscription);
        } catch (\RuntimeException) {
            $this->recordCoverage($subscription, 'unknown', $adnReason, ['channel' => 'adn']);

            return new SyncResult(status: 'unknown', lastNsu: $lastNsu);
        }

        try {
            $batch = $portal->fetchSince($lastNsu);
        } catch (NfseCoverageException $e) {
            $this->recordCoverage($subscription, $e->status, $e->reason, $e->evidence);

            return new SyncResult(status: $e->status, lastNsu: $lastNsu);
        } catch (NfseTransportException $e) {
            $this->recordCoverage($subscription, 'unknown', $e->reason, $e->evidence);

            return new SyncResult(status: 'unknown', lastNsu: $lastNsu);
        }

        $fetched = count($batch->items);

        if ($fetched > 0) {
            try {
                $account = Account::withoutGlobalScopes()->findOrFail($client->account_id);
                $this->limits->ensureVolume($account);
            } catch (ValidationException) {
                return new SyncResult(status: 'volume_exhausted', fetched: 0, lastNsu: $lastNsu);
            }

            $this->persistNfseItems($client, $batch->items, 'portal');
        }

        $this->runCompletion($client, $portal, $subscription->family);

        return new SyncResult(status: $fetched > 0 ? 'synced' : 'empty', fetched: $fetched, lastNsu: $lastNsu);
    }

    /**
     * Persist one page of NFS-e summaries as pending rows (has_xml=false;
     * the bytes land in the completion step). Idempotent by client+key.
     * Items without an extractable/valid key are skipped with a warning so
     * a poison item never stalls the channel.
     *
     * National NFS-e keys are 50 digits; 44-digit keys are tolerated for
     * pre-standard payloads (both fit the widened key-50 column).
     *
     * @param  array<int, mixed>  $items  Channel payloads are untrusted boundary data.
     */
    private function persistNfseItems(Client $client, array $items, string $origin = 'distribuicao'): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = self::nfseKey($item);

            if ($key === null) {
                Log::warning('fiscal.nfse.skipped', [
                    'client_id' => $client->id,
                    'nsu' => $item['nsu'] ?? null,
                    'reason' => 'missing_key',
                ]);

                continue;
            }

            $meta = self::nfseItemMeta($item);

            FiscalDocument::withoutGlobalScopes()->firstOrCreate(
                ['client_id' => $client->id, 'key' => $key],
                [
                    'family' => 'nfse',
                    'doc_type' => 'nfse',
                    'number' => $meta['number'],
                    'series' => $meta['series'],
                    'issuer_name' => $meta['issuer_name'],
                    'issuer_tax_id' => $meta['issuer_tax_id'],
                    'emission_at' => $meta['emission_at'],
                    'status' => 'pending',
                    'origin' => $origin,
                    'has_xml' => false,
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function nfseKey(array $item): ?string
    {
        foreach (['key', 'chaveAcesso', 'ChaveAcesso', 'doc_key'] as $field) {
            $candidate = $item[$field] ?? null;

            if (is_string($candidate) && preg_match('/^\d{44}(\d{6})?$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Best-effort summary metadata from the batch item payload (full
     * enrichment happens at completion time from the stored XML).
     *
     * @param  array<string, mixed>  $item
     * @return array{number: string|null, series: string|null, emission_at: string|null, issuer_name: string|null, issuer_tax_id: string|null}
     */
    private static function nfseItemMeta(array $item): array
    {
        $meta = [
            'number' => null,
            'series' => null,
            'emission_at' => null,
            'issuer_name' => null,
            'issuer_tax_id' => null,
        ];

        $xml = $item['xml'] ?? null;

        if (! is_string($xml) || trim($xml) === '') {
            return $meta;
        }

        $extracted = FiscalXmlMeta::extract($xml, 'nfse');

        return [
            'number' => $extracted['number'],
            'series' => $extracted['series'],
            'emission_at' => $extracted['emission_at'],
            'issuer_name' => $extracted['issuer_name'],
            'issuer_tax_id' => $extracted['issuer_tax_id'],
        ];
    }

    /**
     * End-of-cycle completion: pending rows of the subscription family pull
     * their full XML through the active channel. Never fails the run: the
     * service already skips per-document failures, and this wrapper guards
     * the unexpected.
     */
    private function runCompletion(Client $client, DistributionChannel $channel, string $family): void
    {
        try {
            $this->completion->completePending($client, $channel, $family);
        } catch (Throwable $e) {
            Log::warning('fiscal.completion.skipped', [
                'client_id' => $client->id,
                'family' => $family,
                'error' => $e::class,
            ]);
        }
    }

    private function hasTerminalCoverage(FiscalSyncSubscription $subscription): bool
    {
        return FiscalCoverageEvidence::withoutGlobalScopes()
            ->where('client_id', $subscription->client_id)
            ->where('family', $subscription->family)
            ->where('status', 'limited')
            ->exists();
    }

    /**
     * @param  'limited'|'unknown'  $status
     * @param  array<string, mixed>  $evidence  Codes only; channels never put secrets here.
     */
    private function recordCoverage(
        FiscalSyncSubscription $subscription,
        string $status,
        string $reason,
        array $evidence,
    ): void {
        FiscalCoverageEvidence::withoutGlobalScopes()->updateOrCreate(
            ['client_id' => $subscription->client_id, 'family' => $subscription->family],
            ['status' => $status, 'reason' => $reason, 'evidence' => $evidence, 'ibge_code' => null],
        );

        Log::warning('fiscal.coverage.recorded', [
            'client_id' => $subscription->client_id,
            'family' => $subscription->family,
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    /**
     * Full page: follow the batch high-water mark (covers NSU gaps with no
     * documents). Partial page: stop at the last processed item so the
     * failed item is re-fetched next cycle.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function advanceNsu(array $items, int $processed, string $batchLastNsu): string
    {
        if ($processed < count($items)) {
            $nsu = $items[$processed - 1]['nsu'] ?? null;

            if (is_string($nsu) && $nsu !== '') {
                return $nsu;
            }
        }

        if ($batchLastNsu !== '') {
            return $batchLastNsu;
        }

        $nsu = $items[$processed - 1]['nsu'] ?? null;

        return is_string($nsu) && $nsu !== '' ? $nsu : '0';
    }

    private function hasValidCredential(int $clientId): bool
    {
        $credential = ClientCredential::withoutGlobalScopes()->where('client_id', $clientId)->first();

        return $credential !== null
            && filled($credential->getAttribute('pfx_data'))
            && filled($credential->getAttribute('pfx_password'))
            && $credential->expires_at !== null
            && $credential->expires_at->isFuture();
    }

    private function currentNsu(FiscalSyncSubscription $subscription): string
    {
        $cursor = FiscalSyncCursor::withoutGlobalScopes()
            ->where('client_id', $subscription->client_id)
            ->where('family', $subscription->family)
            ->where('environment', $subscription->environment)
            ->first();

        return $cursor !== null ? (string) $cursor->last_nsu : '0';
    }
}
