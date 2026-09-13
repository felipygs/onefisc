<?php

namespace App\Jobs;

use App\Models\FiscalSyncSubscription;
use App\Services\Fiscal\FiscalSyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FiscalSyncJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $subscriptionId)
    {
        // Dedicated queue so fiscal sync never starves (or is starved by) other work.
        $this->onQueue('fiscal');
    }

    /**
     * Runs one incremental sync cycle through FiscalSyncRunner.
     *
     * Run recording lands in task 3.4. This must never let an exception
     * bubble out and poison the queue: failures become visible state
     * (audited), never silent exceptions.
     */
    public function handle(): void
    {
        try {
            $subscription = FiscalSyncSubscription::withoutGlobalScopes()->find($this->subscriptionId);

            // Gone, or SEFAZ asked for a pause until the next window: nothing to do.
            if ($subscription === null || $subscription->blocked_until?->isFuture()) {
                return;
            }

            // Task 3.2: incremental channel sync (cursor advance + SEFAZ pause).
            // Persistence and ciencia land in tasks 3.3/4.1.
            app(FiscalSyncRunner::class)->run($subscription);
        } catch (Throwable $e) {
            Log::warning('Fiscal sync run failed without visible state yet.', [
                'subscription_id' => $this->subscriptionId,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * A failed queue attempt is also contained: the next hourly dispatch
     * window retries. Task 3.4 records the visible failure state.
     */
    public function failed(?Throwable $exception = null): void
    {
        Log::warning('Fiscal sync job exhausted attempts; next hourly window retries.', [
            'subscription_id' => $this->subscriptionId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
