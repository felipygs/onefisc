<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;

it('shares switchable accounts with platform managers only', function () {
    $origin = Account::factory()->create(['profile' => 'A']);
    $superAdmin = User::factory()->create(['account_id' => $origin->id, 'role' => 'super_admin']);
    Account::factory()->create(['profile' => 'B']);

    $this->actingAs($superAdmin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('switchableAccounts', 2));

    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('switchableAccounts', null));
});

it('shares operating permissions per role', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);
    $operator = User::factory()->create(['account_id' => $account->id, 'role' => 'operador']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.operate-clients', true)
            ->where('permissions.manage-users', true));

    $this->actingAs($operator)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.operate-clients', true)
            ->where('permissions.manage-users', false));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.operate-clients', false)
            ->where('permissions.manage-users', false));
});

it('shares recent account notifications on the dashboard', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);
    AuditLog::factory()->create([
        'actor_user_id' => $user->id,
        'origin_account_id' => $account->id,
        'target_account_id' => $account->id,
        'action' => 'clients.store',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications', 1)
            ->has('notifications.0', fn ($item) => $item
                ->has('id')
                ->has('sender')
                ->has('body')
                ->has('date')));
});
