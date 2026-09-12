<?php

use App\Jobs\FiscalSyncJob;
use App\Models\Client;
use App\Models\FiscalSyncSubscription;
use Illuminate\Support\Facades\Queue;

function overdueSyncSubscription(array $overrides = []): FiscalSyncSubscription
{
    $client = Client::factory()->create();

    return FiscalSyncSubscription::factory()->create(array_merge([
        'client_id' => $client->id,
        'next_run_at' => now()->subMinutes(5),
        'blocked_until' => null,
    ], $overrides));
}

it('dispatches an overdue subscription once and advances next_run_at', function () {
    Queue::fake();

    $subscription = overdueSyncSubscription();

    $this->artisan('fiscal:sync-dispatch')->assertSuccessful();

    Queue::assertPushed(FiscalSyncJob::class, 1);

    $nextRunAt = FiscalSyncSubscription::withoutGlobalScopes()->findOrFail($subscription->id)->next_run_at;

    expect($nextRunAt->greaterThan(now()->addMinutes(50)))->toBeTrue()
        ->and($nextRunAt->lessThanOrEqualTo(now()->addMinutes(75)))->toBeTrue();

    // A second immediate run must not re-dispatch: the window already advanced.
    $this->artisan('fiscal:sync-dispatch')->assertSuccessful();

    Queue::assertPushed(FiscalSyncJob::class, 1);
});

it('skips future and blocked subscriptions', function () {
    Queue::fake();

    overdueSyncSubscription(['next_run_at' => now()->addHour()]);
    overdueSyncSubscription(['blocked_until' => now()->addHour()]);

    $this->artisan('fiscal:sync-dispatch')->assertSuccessful();

    Queue::assertNotPushed(FiscalSyncJob::class);
});

it('pushes the job onto the fiscal queue', function () {
    Queue::fake();

    $subscription = overdueSyncSubscription();

    $this->artisan('fiscal:sync-dispatch')->assertSuccessful();

    Queue::assertPushed(FiscalSyncJob::class, function (FiscalSyncJob $job) use ($subscription) {
        return $job->queue === 'fiscal' && $job->subscriptionId === $subscription->id;
    });
});

it('dispatches two distinct overdue subscriptions without cross-talk', function () {
    Queue::fake();

    $first = overdueSyncSubscription();
    $second = overdueSyncSubscription();

    $this->artisan('fiscal:sync-dispatch')->assertSuccessful();

    Queue::assertPushed(FiscalSyncJob::class, 2);
    Queue::assertPushed(FiscalSyncJob::class, fn (FiscalSyncJob $job) => $job->subscriptionId === $first->id);
    Queue::assertPushed(FiscalSyncJob::class, fn (FiscalSyncJob $job) => $job->subscriptionId === $second->id);
});
