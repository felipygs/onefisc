<?php

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;

if (! function_exists('planSwitchUser')) {
    function planSwitchUser(string $role, string $profile = 'B'): User
    {
        $account = Account::factory()->create(['profile' => $profile]);

        return User::factory()->create(['account_id' => $account->id, 'role' => $role]);
    }
}

if (! function_exists('validPlanPayload')) {
    function validPlanPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Corporativo',
            'price_cents' => 19900,
            'max_users' => 20,
            'max_clients' => 100,
            'modules' => ['clients', 'monitoring'],
            'monthly_query_volume' => 5000,
            'is_default' => false,
        ], $overrides);
    }
}

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

it('lets super_admin view the plan catalog (screen smoke)', function () {
    $this->actingAs(planSwitchUser('super_admin', 'A'));

    $this->get(route('plans.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Plans/Index')
            ->has('plans', 3));
});

it('lets super_admin view the plan edit page (screen smoke)', function () {
    $this->actingAs(planSwitchUser('super_admin', 'A'));
    $plan = Plan::query()->firstOrFail();

    $this->get(route('plans.edit', $plan))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Plans/Edit')
            ->where('plan.id', $plan->id));
});

it('denies the plan catalog to admin, operador and user', function (string $role) {
    $this->actingAs(planSwitchUser($role));

    $this->get(route('plans.index'))->assertForbidden();
    $this->get(route('plans.edit', Plan::query()->firstOrFail()))->assertForbidden();
})->with(['admin', 'operador', 'user']);

it('lets super_admin create a plan in the catalog', function () {
    $this->actingAs(planSwitchUser('super_admin', 'A'));

    $this->post(route('plans.store'), validPlanPayload())
        ->assertRedirect(route('plans.index'));

    $this->assertDatabaseHas('plans', ['name' => 'Corporativo', 'price_cents' => 19900]);
});

it('denies plan catalog writes to non platform roles', function (string $role) {
    $this->actingAs(planSwitchUser($role));
    $plan = Plan::query()->firstOrFail();

    $this->post(route('plans.store'), validPlanPayload())->assertForbidden();
    $this->put(route('plans.update', $plan), validPlanPayload(['name' => 'Hack']))->assertForbidden();

    $this->assertDatabaseMissing('plans', ['name' => 'Corporativo']);
    expect($plan->fresh()?->name)->not->toBe('Hack');
})->with(['admin', 'operador', 'user']);

it('lets super_admin update a plan in the catalog', function () {
    $this->actingAs(planSwitchUser('super_admin', 'A'));
    $plan = Plan::query()->where('name', 'Básico')->firstOrFail();

    $this->put(route('plans.update', $plan), validPlanPayload(['name' => 'Básico Plus']))
        ->assertRedirect(route('plans.index'));

    expect($plan->fresh()?->name)->toBe('Básico Plus');
});

it('switches an account plan immediately as super_admin', function () {
    $this->actingAs(planSwitchUser('super_admin', 'A'));

    $basic = Plan::query()->where('name', 'Básico')->firstOrFail();
    $pro = Plan::query()->where('name', 'Intermediário')->firstOrFail();
    $account = Account::factory()->create(['profile' => 'B', 'plan_id' => $basic->id]);

    $this->patch(route('accounts.plan.update', $account), ['plan_id' => $pro->id])
        ->assertRedirect();

    expect($account->fresh()?->plan_id)->toBe($pro->id)
        ->and(Plan::default()?->id)->toBe($basic->id);
});

it('denies account plan switching outside the platform', function (string $role) {
    $this->actingAs(planSwitchUser($role));

    $basic = Plan::query()->where('name', 'Básico')->firstOrFail();
    $pro = Plan::query()->where('name', 'Intermediário')->firstOrFail();
    $account = Account::factory()->create(['profile' => 'B', 'plan_id' => $basic->id]);

    $this->patch(route('accounts.plan.update', $account), ['plan_id' => $pro->id])
        ->assertForbidden();

    expect($account->fresh()?->plan_id)->toBe($basic->id);
})->with(['admin', 'operador', 'user']);

it('redirects guests to login on plan routes', function () {
    $plan = Plan::query()->firstOrFail();
    $account = Account::factory()->create(['profile' => 'B']);

    $this->get(route('plans.index'))->assertRedirect(route('login'));
    $this->patch(route('accounts.plan.update', $account), ['plan_id' => $plan->id])->assertRedirect(route('login'));
});
