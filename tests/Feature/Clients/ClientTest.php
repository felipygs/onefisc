<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\Plan;
use App\Models\User;

// Clients Vue pages land in Task 7; stub Vite so Inertia page renders
// resolve in tests without manifest entries.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('clientAccountUser')) {
    function clientAccountUser(string $role = 'admin', ?Plan $plan = null, string $profile = 'B'): array
    {
        $account = Account::factory()->create(['profile' => $profile, 'plan_id' => $plan?->id]);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

if (! function_exists('validClientPayload')) {
    function validClientPayload(array $overrides = []): array
    {
        return array_merge([
            'cnpj' => '12345678000195',
            'razao_social' => 'Acme Ltda',
            'regime' => 'simples',
            'contador_responsavel' => 'Contador Silva',
        ], $overrides);
    }
}

if (! function_exists('limitedClientPlan')) {
    function limitedClientPlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Cliente Limite',
            'price_cents' => 0,
            'max_users' => 10,
            'max_clients' => 1,
            'modules' => ['clients'],
            'monthly_query_volume' => 100,
            'is_default' => false,
        ], $overrides));
    }
}

it('creates a client and runs the full CRUD happy path', function () {
    [$account, $admin] = clientAccountUser('admin');
    $this->actingAs($admin);

    $this->post(route('clients.store'), validClientPayload())
        ->assertRedirect(route('clients.index'));

    $this->assertDatabaseHas('clients', [
        'account_id' => $account->id,
        'cnpj' => '12345678000195',
    ]);

    $client = Client::where('cnpj', '12345678000195')->firstOrFail();

    $this->get(route('clients.index'))->assertOk();
    $this->get(route('clients.show', $client))->assertOk();
    $this->get(route('clients.edit', $client))->assertOk();

    $this->put(route('clients.update', $client), validClientPayload(['razao_social' => 'Acme Atualizada']))
        ->assertRedirect(route('clients.index'));

    expect($client->fresh()?->razao_social)->toBe('Acme Atualizada');

    $this->delete(route('clients.destroy', $client))
        ->assertRedirect(route('clients.index'));

    $this->assertDatabaseMissing('clients', ['id' => $client->id]);
});

it('rejects client payloads missing each required field with field-pointed errors', function (string $field) {
    [$account, $admin] = clientAccountUser('admin');
    $this->actingAs($admin);

    $payload = validClientPayload();
    unset($payload[$field]);

    $this->post(route('clients.store'), $payload)
        ->assertSessionHasErrors($field);

    $this->assertDatabaseCount('clients', 0);
})->with(['cnpj', 'razao_social', 'regime', 'contador_responsavel']);

it('rejects invalid cnpj size and invalid regime', function () {
    [$account, $admin] = clientAccountUser('admin');
    $this->actingAs($admin);

    $this->post(route('clients.store'), validClientPayload(['cnpj' => '123']))
        ->assertSessionHasErrors('cnpj');

    $this->post(route('clients.store'), validClientPayload(['regime' => 'lucro_ficticio']))
        ->assertSessionHasErrors('regime');

    $this->assertDatabaseCount('clients', 0);
});

it('rejects a duplicate cnpj within the same account but allows updates keeping its own cnpj', function () {
    [$account, $admin] = clientAccountUser('admin');
    $this->actingAs($admin);

    $client = Client::factory()->create(['account_id' => $account->id, 'cnpj' => '12345678000195']);

    $this->post(route('clients.store'), validClientPayload())
        ->assertSessionHasErrors('cnpj');

    $this->put(route('clients.update', $client), validClientPayload(['razao_social' => 'Mantém CNPJ']))
        ->assertRedirect(route('clients.index'));

    expect($client->fresh()?->razao_social)->toBe('Mantém CNPJ');
});

it('allows the same cnpj to coexist in two different accounts', function () {
    [$accountA, $adminA] = clientAccountUser('admin');
    [$accountB, $adminB] = clientAccountUser('admin');

    $this->actingAs($adminA);
    $this->post(route('clients.store'), validClientPayload())
        ->assertRedirect(route('clients.index'));

    $this->actingAs($adminB);
    $this->post(route('clients.store'), validClientPayload())
        ->assertRedirect(route('clients.index'));

    expect(Client::withoutGlobalScopes()->where('cnpj', '12345678000195')->count())->toBe(2);
});

it('isolates clients so account B cannot see account A clients', function () {
    [$accountA, $adminA] = clientAccountUser('admin');
    [$accountB, $adminB] = clientAccountUser('admin');

    $clientA = Client::factory()->create(['account_id' => $accountA->id]);

    $this->actingAs($adminB);

    $this->get(route('clients.show', $clientA))->assertNotFound();
    $this->get(route('clients.edit', $clientA))->assertNotFound();
    $this->put(route('clients.update', $clientA), validClientPayload())->assertNotFound();
    $this->delete(route('clients.destroy', $clientA))->assertNotFound();

    $response = $this->get(route('clients.index'))->assertOk();
    // Component file lands in Task 7; assert name only, not file existence.
    $response->assertInertia(fn ($page) => $page
        ->component('clients/Index', false)
        ->where('clients.data', []));
});

it('allows super_admin, admin and operador but denies user on clients', function (string $role, bool $allowed) {
    [$account, $user] = clientAccountUser($role);
    $this->actingAs($user);

    if ($allowed) {
        $this->get(route('clients.index'))->assertOk();
        $this->post(route('clients.store'), validClientPayload())
            ->assertRedirect(route('clients.index'));
    } else {
        $this->get(route('clients.index'))->assertForbidden();
        $this->post(route('clients.store'), validClientPayload())->assertForbidden();
    }
})->with([
    ['super_admin', true],
    ['admin', true],
    ['operador', true],
    ['user', false],
]);

it('blocks client store at plan capacity with an upgrade message (controller path)', function () {
    $plan = limitedClientPlan(['max_clients' => 1]);
    [$account, $admin] = clientAccountUser('admin', $plan);
    Client::factory()->create(['account_id' => $account->id]);

    $this->actingAs($admin);

    $response = $this->post(route('clients.store'), validClientPayload(['cnpj' => '99999999000199']));

    $response->assertRedirect();
    $response->assertSessionHasErrors('limit');

    $text = mb_strtolower(collect(session()->get('errors')?->get('limit') ?? [])->implode(' '));
    expect($text)->toContain('upgrade');

    expect(Client::withoutGlobalScopes()->where('account_id', $account->id)->count())->toBe(1);
    $this->assertDatabaseMissing('clients', ['cnpj' => '99999999000199']);
});

it('redirects guests to login on client routes', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $client = Client::factory()->create(['account_id' => $account->id]);

    $this->get(route('clients.index'))->assertRedirect(route('login'));
    $this->post(route('clients.store'), validClientPayload())->assertRedirect(route('login'));
    $this->get(route('clients.show', $client))->assertRedirect(route('login'));
});
