<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

if (! function_exists('auditActor')) {
    function auditActor(string $role = 'super_admin', string $profile = 'A'): User
    {
        $account = Account::factory()->create(['profile' => $profile]);

        return User::factory()->create(['account_id' => $account->id, 'role' => $role]);
    }
}

it('records account creation with actor origin and self target', function () {
    $actor = auditActor();
    $this->actingAs($actor);

    $account = Account::factory()->create(['profile' => 'B']);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'accounts.created',
        'actor_user_id' => $actor->id,
        'origin_account_id' => $actor->account_id,
        'target_account_id' => $account->id,
    ]);

    $row = AuditLog::query()->where('action', 'accounts.created')->firstOrFail();
    expect($row->created_at)->not->toBeNull()
        ->and($row->metadata)->toMatchArray(['id' => $account->id]);
});

it('records a manual entry when switching an account plan', function () {
    $actor = auditActor();
    $this->actingAs($actor);

    $basic = Plan::query()->where('name', 'Básico')->firstOrFail();
    $pro = Plan::query()->where('name', 'Intermediário')->firstOrFail();
    $account = Account::factory()->create(['profile' => 'B', 'plan_id' => $basic->id]);

    $this->patch(route('accounts.plan.update', $account), ['plan_id' => $pro->id])
        ->assertRedirect();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'plan.switch',
        'actor_user_id' => $actor->id,
        'origin_account_id' => $actor->account_id,
        'target_account_id' => $account->id,
    ]);

    expect(AuditLog::query()->where('action', 'plan.switch')->count())->toBe(1);
});

it('records client create update and delete events', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);
    $this->actingAs($admin);

    $payload = [
        'cnpj' => '12345678000195',
        'razao_social' => 'Acme Ltda',
        'regime' => 'simples',
        'contador_responsavel' => 'Contador Silva',
    ];

    $this->post(route('clients.store'), $payload)->assertRedirect(route('clients.index'));

    $client = Client::query()->where('account_id', $account->id)->firstOrFail();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'clients.created',
        'actor_user_id' => $admin->id,
        'origin_account_id' => $account->id,
        'target_account_id' => $account->id,
    ]);

    $this->put(route('clients.update', $client), [...$payload, 'razao_social' => 'Acme Atualizada'])
        ->assertRedirect(route('clients.index'));

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'clients.updated',
        'actor_user_id' => $admin->id,
        'origin_account_id' => $account->id,
        'target_account_id' => $account->id,
    ]);

    $this->delete(route('clients.destroy', $client))->assertRedirect(route('clients.index'));

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'clients.deleted',
        'actor_user_id' => $admin->id,
        'origin_account_id' => $account->id,
        'target_account_id' => $account->id,
    ]);
});

it('records exactly one row per switcher enter and exit', function () {
    $origin = Account::factory()->create(['profile' => 'A']);
    $actor = User::factory()->create(['account_id' => $origin->id, 'role' => 'super_admin']);
    $target = Account::factory()->create(['profile' => 'B']);
    $this->actingAs($actor);

    $this->post(route('switcher.select', $target))->assertRedirect(route('dashboard'));

    expect(AuditLog::query()->where('action', 'switcher.enter')->count())->toBe(1);

    $this->delete(route('switcher.destroy'))->assertRedirect(route('dashboard'));

    expect(AuditLog::query()->where('action', 'switcher.exit')->count())->toBe(1);
});

it('records invitation acceptance with the new user as actor', function () {
    $inv = Invitation::factory()->create(['expires_at' => now()->addDays(7)]);

    $this->post("/invitations/accept/{$inv->token}", [
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ])->assertRedirect('/dashboard');

    $user = User::query()->where('email', $inv->email)->firstOrFail();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'invitation.accepted',
        'actor_user_id' => $user->id,
        'origin_account_id' => $inv->account_id,
        'target_account_id' => $inv->account_id,
    ]);
});

it('records nothing and never throws without an authenticated actor', function () {
    $account = Account::factory()->create(['profile' => 'B']);

    expect($account->exists)->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(app(AuditService::class)->record('probe'))->toBeNull();
});
