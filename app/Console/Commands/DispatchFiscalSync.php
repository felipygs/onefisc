<?php

namespace App\Console\Commands;

use App\Jobs\FiscalSyncJob;
use App\Models\FiscalSyncSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchFiscalSync extends Command
{
    protected $signature = 'fiscal:sync-dispatch';

    protected $description = 'Enqueue due fiscal sync subscriptions onto the fiscal queue, exactly once per hourly window';

    public function handle(): int
    {
        $dispatched = 0;
        $skipped = 0;
        $now = now();

        FiscalSyncSubscription::query()->withoutGlobalScopes()
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('blocked_until')->orWhere('blocked_until', '<=', $now);
            })
            ->chunkById(100, function ($subscriptions) use (&$dispatched, &$skipped): void {
                foreach ($subscriptions as $subscription) {
                    DB::transaction(function () use ($subscription, &$dispatched, &$skipped): void {
                        $query = FiscalSyncSubscription::query()->withoutGlobalScopes()->whereKey($subscription->id);

                        // SQLite (tests) does not support SKIP LOCKED; lock only where supported.
                        if (DB::getDriverName() === 'pgsql') {
                            $query->lock('for update skip locked');
                        }

                        $fresh = $query->first();

                        // Revalidate inside the lock: another dispatcher may have taken it first.
                        if ($fresh === null || ! $this->isDue($fresh)) {
                            $skipped++;

                            return;
                        }

                        // Advance the window in the SAME transaction: this is what prevents duplicates.
                        // Saved before dispatch so a queue failure rolls the window back for retry.
                        $fresh->next_run_at = now()->addHour()->addSeconds(random_int(0, 600));
                        $fresh->save();

                        FiscalSyncJob::dispatch($fresh->id);

                        $dispatched++;
                    });
                }
            });

        $this->info("Fiscal sync: {$dispatched} enfileirados, {$skipped} ignorados.");

        return self::SUCCESS;
    }

    private function isDue(FiscalSyncSubscription $subscription): bool
    {
        $now = now();

        return ($subscription->next_run_at === null || $subscription->next_run_at->lessThanOrEqualTo($now))
            && ($subscription->blocked_until === null || $subscription->blocked_until->lessThanOrEqualTo($now));
    }
}
