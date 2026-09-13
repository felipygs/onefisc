<?php

namespace App\Services\Fiscal;

use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalSyncCursor;
use App\Models\FiscalSyncSubscription;

/**
 * Runs one incremental sync cycle: cursor mechanics + SEFAZ pause handling
 * + automatic ciencia with pending persistence (task 3.3).
 *
 * No valid credential (missing, incomplete or expired) suspends the run
 * without ever calling SEFAZ. A SEFAZ pause (cStat 137/656) records blocked_until at the
 * next full hour without advancing past the confirmed NSU. The XML bytes
 * themselves land in task 4.1 (documents persist with has_xml=false here).
 */
final class FiscalSyncRunner
{
    private const MAX_PAGES_PER_RUN = 20;

    public function __construct(
        private readonly FiscalChannelFactory $channels = new FiscalChannelFactory,
        private readonly ScienceService $science = new ScienceService,
    ) {}

    public function run(FiscalSyncSubscription $subscription): SyncResult
    {
        $subscription = FiscalSyncSubscription::withoutGlobalScopes()->findOrFail($subscription->id);

        if ($subscription->blocked_until !== null && $subscription->blocked_until->isFuture()) {
            return new SyncResult(status: 'paused', fetched: 0, lastNsu: $this->currentNsu($subscription));
        }

        if (! $this->hasValidCredential($subscription->client_id)) {
            return new SyncResult(status: 'suspended');
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

        return new SyncResult(status: $fetched > 0 ? 'synced' : 'empty', fetched: $fetched, lastNsu: $lastNsu);
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
