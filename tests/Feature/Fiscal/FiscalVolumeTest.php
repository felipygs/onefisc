<?php

use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalSyncCursor;
use App\Models\MonitoringCheck;
use App\Models\Plan;
use App\Services\Fiscal\ChannelBatch;
use App\Services\PlanLimitService;

// ---------------------------------------------------------------------------
// Task 3.5: each persisted fiscal document counts 1 toward the Plan volume,
// with cursor-preserving suspension when the volume is exhausted.
// Helpers (FakeDistChannel / runnerWithFakeChannel / syncClientWithCredential /
// syncSubscriptionFor / scienceResumo / scienceKey) are reused from
// SyncChannelsTest + ScienceAutoTest; makeLimitedPlan from PlanLimitsTest.
// ---------------------------------------------------------------------------

if (! function_exists('volumeAccountFor')) {
    function volumeAccountFor(object $client, int $monthlyVolume): object
    {
        $plan = Plan::create([
            'name' => 'Fiscal Volume',
            'price_cents' => 0,
            'max_users' => 10,
            'max_clients' => 10,
            'modules' => ['clients', 'monitoring'],
            'monthly_query_volume' => $monthlyVolume,
            'is_default' => false,
        ]);

        $account = Account::withoutGlobalScopes()->findOrFail($client->account_id);
        $account->update(['plan_id' => $plan->id]);

        return $account->refresh();
    }
}

if (! function_exists('volumeBatch')) {
    function volumeBatch(): ChannelBatch
    {
        return new ChannelBatch(
            items: [
                scienceResumo('000000000000001', scienceKey('2')),
                scienceResumo('000000000000002', scienceKey('3')),
            ],
            lastNsu: '000000000000002',
        );
    }
}

it('counts each persisted fiscal document as one volume unit on top of monitoring checks', function () {
    $client = syncClientWithCredential();
    $account = volumeAccountFor($client, 10);

    MonitoringCheck::factory()->create(['account_id' => $account->id, 'client_id' => $client->id]);

    expect(app(PlanLimitService::class)->volumeConsumed($account))->toBe(1);

    $subscription = syncSubscriptionFor($client);

    $fake = new FakeDistChannel;
    $fake->queuedBatches = [volumeBatch()];

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($result->status)->toBe('synced')
        ->and(FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(2)
        ->and(app(PlanLimitService::class)->volumeConsumed($account))->toBe(3)
        ->and(app(PlanLimitService::class)->usage($account)['volume'])->toBe([
            'used' => 3, 'max' => 10, 'remaining' => 7,
        ]);
});

it('suspends persistence as volume_exhausted and preserves the cursor when volume is exhausted', function () {
    $client = syncClientWithCredential();
    volumeAccountFor($client, 1);

    FiscalDocument::factory()->create(['client_id' => $client->id]);

    $subscription = syncSubscriptionFor($client);

    $fake = new FakeDistChannel;
    $fake->queuedBatches = [volumeBatch()];

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($result->status)->toBe('volume_exhausted')
        ->and($result->fetched)->toBe(0)
        ->and($fake->manifestCalls)->toBe([])
        ->and(FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(1)
        ->and(FiscalSyncCursor::withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->where('family', 'nfe')
            ->firstOrFail()
            ->getAttribute('last_nsu'))->toBe('0');
});

it('persists the pending summaries and counts them after a plan upgrade', function () {
    $client = syncClientWithCredential();
    $account = volumeAccountFor($client, 1);

    FiscalDocument::factory()->create(['client_id' => $client->id]);

    $subscription = syncSubscriptionFor($client);

    $suspended = new FakeDistChannel;
    $suspended->queuedBatches = [volumeBatch()];

    expect(runnerWithFakeChannel($suspended)->run($subscription)->status)->toBe('volume_exhausted');

    $account->plan->update(['monthly_query_volume' => 10]);

    $retry = new FakeDistChannel;
    $retry->queuedBatches = [volumeBatch()];

    $result = runnerWithFakeChannel($retry)->run($subscription);

    expect($result->status)->toBe('synced')
        ->and($result->fetched)->toBe(2)
        ->and(FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(3)
        ->and(app(PlanLimitService::class)->volumeConsumed($account))->toBe(3)
        ->and(FiscalSyncCursor::withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->where('family', 'nfe')
            ->firstOrFail()
            ->getAttribute('last_nsu'))->toBe('000000000000002');
});
