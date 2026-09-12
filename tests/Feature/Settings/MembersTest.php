<?php

use App\Models\Account;
use App\Models\Invitation;
use App\Models\User;

it('lists account members and pending invitations for managers', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);
    User::factory()->create(['account_id' => $account->id, 'role' => 'user']);

    $this->actingAs($admin)
        ->get(route('members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('members', 2)
            ->has('invitations'));
});

it('denies the members page to the user role', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);

    $this->actingAs($user)
        ->get(route('members.index'))
        ->assertForbidden();
});

it('invites a member with a 7 day expiry', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);

    $this->actingAs($admin)
        ->post(route('invitations.store'), [
            'name' => 'Novo Operador',
            'email' => 'novo-operador@example.com',
            'role' => 'operador',
        ])
        ->assertRedirect();

    $invitation = Invitation::where('email', 'novo-operador@example.com')->firstOrFail();

    expect($invitation->account_id)->toBe($account->id)
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();
});

it('persists notification preferences per user', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);

    $this->actingAs($user)
        ->put(route('notifications.update'), [
            'invites' => false,
            'plan_limits' => true,
            'monitoring' => false,
        ])
        ->assertRedirect();

    expect($user->fresh()->notification_preferences)->toBe([
        'invites' => false,
        'plan_limits' => true,
        'monitoring' => false,
    ]);
});
