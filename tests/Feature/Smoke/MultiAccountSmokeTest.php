<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\MonitoringService;
use Database\Seeders\PlanSeeder;

it('drives the full multi-account journey end to end with audit coverage', function () {
    $this->seed(PlanSeeder::class);

    // 1. Onboarding: create Account A with its super_admin (shape proven in OnboardingTest).
    $this->post('/onboarding', [
        'account_name' => 'Matriz',
        'name' => 'Root',
        'email' => 'root@x.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/dashboard');

    $accountA = Account::where('profile', 'A')->firstOrFail();
    $root = User::where('email', 'root@x.com')->firstOrFail();

    expect($accountA->profile)->toBe('A')
        ->and($root->role)->toBe('super_admin')
        ->and($root->account_id)->toBe($accountA->id);

    // Note: onboarding emits no audit row by design — the observer is
    // best-effort and there is no authenticated actor before login (see
    // AuditObserverTest 'records nothing ... without an authenticated actor').

    // 2. Provision Account B with its admin invitation (shape proven in CreateAccountTest).
    $this->actingAs($root);

    $result = app(AccountProvisioningService::class)->createForAdmin('Cliente B', 'Bea Admin', 'bea@b.com');
    $accountB = $result['account'];
    $token = $result['token'];

    expect($accountB->profile)->toBe('B')
        ->and($accountB->plan_id)->toBe(Plan::default()?->id)
        ->and($result['invitation']->role)->toBe('admin');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'accounts.created',
        'actor_user_id' => $root->id,
        'target_account_id' => $accountB->id,
    ]);

    // 3. Accept B's admin invite over HTTP as a guest (shape proven in AuditObserverTest).
    auth()->logout();

    $this->post("/invitations/accept/{$token}", [
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/dashboard');

    $adminB = User::where('email', 'bea@b.com')->firstOrFail();

    expect($adminB->account_id)->toBe($accountB->id)
        ->and($adminB->role)->toBe('admin');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'invitation.accepted',
        'actor_user_id' => $adminB->id,
        'target_account_id' => $accountB->id,
    ]);

    // 4. Create a client in B (shape proven in ClientTest).
    $this->actingAs($adminB);

    $this->post(route('clients.store'), [
        'cnpj' => '12345678000195',
        'razao_social' => 'Acme Ltda',
        'regime' => 'simples',
        'contador_responsavel' => 'Contador Silva',
    ])->assertRedirect(route('clients.index'));

    $client = Client::where('account_id', $accountB->id)->firstOrFail();

    expect($client->cnpj)->toBe('12345678000195');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'clients.created',
        'actor_user_id' => $adminB->id,
        'target_account_id' => $accountB->id,
    ]);

    // 5. Switch B's plan as super_admin (shape proven in PlanSwitchTest).
    $this->actingAs($root->fresh());

    $pro = Plan::where('name', 'Intermediário')->firstOrFail();

    $this->patch(route('accounts.plan.update', $accountB), ['plan_id' => $pro->id])
        ->assertRedirect();

    expect($accountB->fresh()?->plan_id)->toBe($pro->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'plan.switch',
        'actor_user_id' => $root->id,
        'target_account_id' => $accountB->id,
    ]);

    // 6. Enter B via the account switcher (shape proven in AccountSwitcherTest).
    $this->post(route('switcher.select', $accountB))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('switch_account_id', $accountB->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'switcher.enter',
        'actor_user_id' => $root->id,
        'origin_account_id' => $accountA->id,
        'target_account_id' => $accountB->id,
    ]);

    // 7. Run monitoring for B's client (shape proven in MonitoringRunTest).
    $check = app(MonitoringService::class)->run($client->fresh());

    expect($check->exists)->toBeTrue()
        ->and($check->account_id)->toBe($accountB->id)
        ->and($check->client_id)->toBe($client->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'monitoring_checks.created',
        'target_account_id' => $accountB->id,
    ]);
});
