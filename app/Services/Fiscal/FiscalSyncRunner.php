<?php

namespace App\Services\Fiscal;

use App\Models\ClientCredential;
use App\Models\FiscalSyncCursor;
use App\Models\FiscalSyncSubscription;

/**
 * Runs one incremental sync cycle: cursor mechanics + SEFAZ pause handling.
 *
 * No valid credential (missing, incomplete or expired) suspends the run
 * without ever calling SEFAZ. A SEFAZ pause (cStat 137/656) records blocked_until at the
 * next full hour without advancing past the confirmed NSU. Document
 * persistence and ciencia land in tasks 3.3/4.1 — items are only counted.
 */
final class FiscalSyncRunner
{
    private const MAX_PAGES_PER_RUN = 20;

    public function __construct(private readonly FiscalChannelFactory $channels = new FiscalChannelFactory) {}

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
            $batch = $channel->fetchSince($lastNsu);

            if ($batch->pause !== null) {
                $subscription->blocked_until = now()->startOfHour()->addHour();
                $subscription->save();

                return new SyncResult(status: 'paused', fetched: $fetched, lastNsu: $lastNsu, pause: $batch->pause);
            }

            $fetched += count($batch->items);

            if ($batch->items === [] || $batch->lastNsu === $lastNsu) {
                break;
            }

            $lastNsu = $batch->lastNsu;
            $cursor->last_nsu = $lastNsu;
            $cursor->save();
        }

        return new SyncResult(status: $fetched > 0 ? 'synced' : 'empty', fetched: $fetched, lastNsu: $lastNsu);
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
