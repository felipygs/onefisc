<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\MonitoringCheck;
use App\Models\Plan;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\MonitoringService;
use App\Services\PlanLimitService;
use Illuminate\Validation\ValidationException;

if (! function_exists('makeLimitedPlan')) {
    function makeLimitedPlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Limitado',
            'price_cents' => 0,
            'max_users' => 2,
            'max_clients' => 2,
            'modules' => ['clients', 'monitoring'],
            'monthly_query_volume' => 5,
            'is_default' => false,
        ], $overrides));
    }
}

if (! function_exists('makeLimitedAccount')) {
    function makeLimitedAccount(?Plan $plan, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'profile' => 'B',
            'plan_id' => $plan?->id,
        ], $overrides));
    }
}

// ---- users: capacity counts users + valid pending invites ----

it('blocks invites when users plus pending invites reach max_users', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['max_users' => 2]));
    User::factory()->create(['account_id' => $account->id]);
    Invitation::factory()->create(['account_id' => $account->id, 'expires_at' => now()->addDays(7)]);

    try {
        app(InvitationService::class)->invite($account, 'Novo', 'novo@example.com', 'user');
        expect(false)->toBeTrue('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        $text = mb_strtolower($e->getMessage().' '.collect($e->errors())->flatten()->implode(' '));
        expect($text)->toContain('upgrade')->toContain('usuário');
    }
});

it('allows invites within user limits', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['max_users' => 3]));
    User::factory()->create(['account_id' => $account->id]);

    $result = app(InvitationService::class)->invite($account, 'Novo', 'novo@example.com', 'user');

    expect($result['invitation']->exists)->toBeTrue();
});

it('ignores expired invites when checking user capacity', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['max_users' => 2]));
    User::factory()->create(['account_id' => $account->id]);
    Invitation::factory()->create(['account_id' => $account->id, 'expires_at' => now()->subDay()]);

    $result = app(InvitationService::class)->invite($account, 'Novo', 'novo@example.com', 'user');

    expect($result['invitation']->exists)->toBeTrue();
});

it('blocks invitation accept when the account is at user capacity', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['max_users' => 1]));
    User::factory()->create(['account_id' => $account->id]);
    $inv = Invitation::factory()->create(['account_id' => $account->id]);

    try {
        app(InvitationService::class)->accept($inv->token, 'secret123', 'secret123');
        expect(false)->toBeTrue('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        $text = mb_strtolower($e->getMessage().' '.collect($e->errors())->flatten()->implode(' '));
        expect($text)->toContain('upgrade')->toContain('usuário');
    }
});

it('allows invitation accept within user limits', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['max_users' => 3]));
    User::factory()->create(['account_id' => $account->id]);
    $inv = Invitation::factory()->create(['account_id' => $account->id]);

    $user = app(InvitationService::class)->accept($inv->token, 'secret123', 'secret123');

    expect($user->account_id)->toBe($account->id);
});

it('allows invitation accept at the exact boundary excluding the accepting invite', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['max_users' => 2]));
    User::factory()->create(['account_id' => $account->id]);
    $inv = Invitation::factory()->create(['account_id' => $account->id]);

    $user = app(InvitationService::class)->accept($inv->token, 'secret123', 'secret123');

    expect($user->account_id)->toBe($account->id);
});

// ---- clients ----

it('blocks client creation checks when max_clients is reached', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['max_clients' => 1]));
    Client::factory()->create(['account_id' => $account->id]);

    try {
        app(PlanLimitService::class)->ensureClientCapacity($account);
        expect(false)->toBeTrue('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        $text = mb_strtolower($e->getMessage().' '.collect($e->errors())->flatten()->implode(' '));
        expect($text)->toContain('upgrade')->toContain('clients');
    }
});

it('allows client creation checks within limits', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['max_clients' => 2]));
    Client::factory()->create(['account_id' => $account->id]);

    app(PlanLimitService::class)->ensureClientCapacity($account);

    expect(true)->toBeTrue();
});

// ---- modules ----

it('blocks monitoring runs when the module is locked for the plan', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['modules' => ['clients']]));
    $client = Client::factory()->create(['account_id' => $account->id]);

    try {
        app(MonitoringService::class)->run($client);
        expect(false)->toBeTrue('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        $text = mb_strtolower($e->getMessage().' '.collect($e->errors())->flatten()->implode(' '));
        expect($text)->toContain('upgrade')->toContain('monitoring');
    }
});

it('allows monitoring runs when the module is liberated and volume remains', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['modules' => ['clients', 'monitoring']]));
    $client = Client::factory()->create(['account_id' => $account->id]);

    $check = app(MonitoringService::class)->run($client);

    expect($check->exists)->toBeTrue()
        ->and($check->account_id)->toBe($account->id);
});

// ---- volume ----

it('blocks monitoring runs when monthly query volume is exhausted', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['monthly_query_volume' => 2]));
    $client = Client::factory()->create(['account_id' => $account->id]);
    MonitoringCheck::factory()->count(2)->create(['account_id' => $account->id, 'client_id' => $client->id]);

    try {
        app(MonitoringService::class)->run($client);
        expect(false)->toBeTrue('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        $text = mb_strtolower($e->getMessage().' '.collect($e->errors())->flatten()->implode(' '));
        expect($text)->toContain('upgrade')->toContain('volume');
    }
});

it('allows monitoring runs within monthly query volume', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['monthly_query_volume' => 5]));
    $client = Client::factory()->create(['account_id' => $account->id]);
    MonitoringCheck::factory()->create(['account_id' => $account->id, 'client_id' => $client->id]);

    $check = app(MonitoringService::class)->run($client);

    expect($check->exists)->toBeTrue();
});

it('does not count previous months checks toward volume', function () {
    $account = makeLimitedAccount(makeLimitedPlan(['monthly_query_volume' => 1]));
    $client = Client::factory()->create(['account_id' => $account->id]);
    MonitoringCheck::factory()->create([
        'account_id' => $account->id,
        'client_id' => $client->id,
        'created_at' => now()->subMonth()->startOfMonth()->addDay(),
    ]);

    expect(app(PlanLimitService::class)->volumeConsumed($account))->toBe(0);

    app(PlanLimitService::class)->ensureVolume($account);

    expect(true)->toBeTrue();
});

// ---- null plan means no enforcement ----

it('skips all enforcement when the account has no plan', function () {
    $account = makeLimitedAccount(null);
    User::factory()->count(3)->create(['account_id' => $account->id]);
    $client = Client::factory()->create(['account_id' => $account->id]);

    $limits = app(PlanLimitService::class);
    $limits->ensureUserCapacity($account);
    $limits->ensureClientCapacity($account);
    $limits->ensureModule($account, 'monitoring');
    $limits->ensureVolume($account);

    $result = app(InvitationService::class)->invite($account, 'Livre', 'livre@example.com', 'user');
    $check = app(MonitoringService::class)->run($client);

    expect($result['invitation']->exists)->toBeTrue()
        ->and($check->exists)->toBeTrue()
        ->and($limits->planFor($account))->toBeNull();
});

// ---- usage reporting ----

it('reports usage against plan limits', function () {
    $plan = makeLimitedPlan(['max_users' => 2, 'max_clients' => 4, 'monthly_query_volume' => 10]);
    $account = makeLimitedAccount($plan);
    User::factory()->create(['account_id' => $account->id]);
    $client = Client::factory()->create(['account_id' => $account->id]);
    MonitoringCheck::factory()->count(3)->create(['account_id' => $account->id, 'client_id' => $client->id]);

    $usage = app(PlanLimitService::class)->usage($account);

    expect($usage['users'])->toBe(['used' => 1, 'max' => 2])
        ->and($usage['clients'])->toBe(['used' => 1, 'max' => 4])
        ->and($usage['volume']['used'])->toBe(3)
        ->and($usage['volume']['max'])->toBe(10)
        ->and($usage['volume']['remaining'])->toBe(7);
});
