<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringCheck;
use App\Models\Plan;
use App\Services\MonitoringService;
use App\Services\PlanLimitService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Validation\ValidationException;

if (! function_exists('monitorPlan')) {
    function monitorPlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Monitoramento',
            'price_cents' => 0,
            'max_users' => 10,
            'max_clients' => 10,
            'modules' => ['clients', 'monitoring'],
            'monthly_query_volume' => 5,
            'is_default' => false,
        ], $overrides));
    }
}

if (! function_exists('monitorAccount')) {
    function monitorAccount(?Plan $plan, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'profile' => 'B',
            'plan_id' => $plan?->id,
        ], $overrides));
    }
}

it('creates one check row within volume counting toward current-month volume', function () {
    $account = monitorAccount(monitorPlan(['monthly_query_volume' => 5]));
    $client = Client::factory()->create(['account_id' => $account->id]);

    $before = app(PlanLimitService::class)->volumeConsumed($account);

    $check = app(MonitoringService::class)->run($client);

    expect($check->exists)->toBeTrue()
        ->and($check->account_id)->toBe($account->id)
        ->and($check->client_id)->toBe($client->id)
        ->and(app(PlanLimitService::class)->volumeConsumed($account))->toBe($before + 1);
});

it('suspends the run when monthly volume is exhausted creating no row', function () {
    $account = monitorAccount(monitorPlan(['monthly_query_volume' => 2]));
    $client = Client::factory()->create(['account_id' => $account->id]);
    MonitoringCheck::factory()->count(2)->create(['account_id' => $account->id, 'client_id' => $client->id]);

    try {
        app(MonitoringService::class)->run($client);
        expect(false)->toBeTrue('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        $text = mb_strtolower($e->getMessage().' '.collect($e->errors())->flatten()->implode(' '));
        expect($text)->toContain('upgrade')->toContain('volume');
    }

    expect(MonitoringCheck::query()->withoutGlobalScopes()->where('account_id', $account->id)->count())->toBe(2);
});

it('blocks the run when the monitoring module is locked creating no row', function () {
    $account = monitorAccount(monitorPlan(['modules' => ['clients']]));
    $client = Client::factory()->create(['account_id' => $account->id]);

    try {
        app(MonitoringService::class)->run($client);
        expect(false)->toBeTrue('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        $text = mb_strtolower($e->getMessage().' '.collect($e->errors())->flatten()->implode(' '));
        expect($text)->toContain('upgrade')->toContain('monitoring');
    }

    expect(MonitoringCheck::query()->withoutGlobalScopes()->where('account_id', $account->id)->count())->toBe(0);
});

it('keeps volume counting isolated per account', function () {
    $exhausted = monitorAccount(monitorPlan(['monthly_query_volume' => 1]));
    $exhaustedClient = Client::factory()->create(['account_id' => $exhausted->id]);
    MonitoringCheck::factory()->create(['account_id' => $exhausted->id, 'client_id' => $exhaustedClient->id]);

    $healthy = monitorAccount(monitorPlan(['monthly_query_volume' => 5]));
    $healthyClient = Client::factory()->create(['account_id' => $healthy->id]);

    $check = app(MonitoringService::class)->run($healthyClient);

    expect($check->exists)->toBeTrue()
        ->and(app(PlanLimitService::class)->volumeConsumed($healthy))->toBe(1)
        ->and(app(PlanLimitService::class)->volumeConsumed($exhausted))->toBe(1);
});

it('runs best-effort over all clients reporting ran and suspended counts', function () {
    $blocked = monitorAccount(monitorPlan(['monthly_query_volume' => 1]));
    $blockedClient = Client::factory()->create(['account_id' => $blocked->id]);
    MonitoringCheck::factory()->create(['account_id' => $blocked->id, 'client_id' => $blockedClient->id]);

    $healthy = monitorAccount(monitorPlan(['monthly_query_volume' => 5]));
    $healthyClient = Client::factory()->create(['account_id' => $healthy->id]);

    $this->artisan('monitoring:run')
        ->assertExitCode(0)
        ->expectsOutputToContain('1 ok, 1 suspensos');

    expect(MonitoringCheck::query()->withoutGlobalScopes()->where('account_id', $healthy->id)->count())->toBe(1)
        ->and(MonitoringCheck::query()->withoutGlobalScopes()->where('account_id', $blocked->id)->count())->toBe(1);
});

it('schedules monitoring:run daily', function () {
    $events = collect(app(Schedule::class)->events());

    $event = $events->first(fn ($e) => str_contains($e->command ?? '', 'monitoring:run'));

    expect($event)->not->toBeNull('monitoring:run is not scheduled')
        ->and($event->expression)->toBe('0 0 * * *');
});
