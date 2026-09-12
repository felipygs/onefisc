<?php

use App\Models\Account;
use App\Models\User;
use App\Support\CurrentAccount;

if (! function_exists('switcherUser')) {
    function switcherUser(string $role, string $profile = 'B'): User
    {
        $account = Account::factory()->create(['profile' => $profile]);

        return User::factory()->create(['account_id' => $account->id, 'role' => $role]);
    }
}

it('denies the account switcher to non platform roles', function (string $role) {
    $user = switcherUser($role);
    $target = Account::factory()->create(['profile' => 'B']);
    $this->actingAs($user);

    $this->get(route('switcher.index'))->assertForbidden();
    $this->post(route('switcher.select', $target))->assertForbidden();
    $this->delete(route('switcher.destroy'))->assertForbidden();
})->with(['admin', 'operador', 'user']);

it('redirects guests to login on switcher routes', function () {
    $target = Account::factory()->create(['profile' => 'B']);

    $this->get(route('switcher.index'))->assertRedirect(route('login'));
    $this->post(route('switcher.select', $target))->assertRedirect(route('login'));
    $this->delete(route('switcher.destroy'))->assertRedirect(route('login'));
});

it('lets super_admin select a target account and resolves the effective context', function () {
    $origin = Account::factory()->create(['profile' => 'A']);
    $superAdmin = User::factory()->create(['account_id' => $origin->id, 'role' => 'super_admin']);
    $target = Account::factory()->create(['profile' => 'B']);
    $this->actingAs($superAdmin);

    $this->post(route('switcher.select', $target))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('switch_account_id', $target->id);

    $this->get(route('dashboard'))->assertOk();

    expect(CurrentAccount::resolve()?->id)->toBe($target->id)
        ->and(CurrentAccount::isSwitching())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'switcher.enter',
        'actor_user_id' => $superAdmin->id,
        'origin_account_id' => $origin->id,
        'target_account_id' => $target->id,
    ]);
});

it('clears the switch session on exit and restores the origin account', function () {
    $origin = Account::factory()->create(['profile' => 'A']);
    $superAdmin = User::factory()->create(['account_id' => $origin->id, 'role' => 'super_admin']);
    $target = Account::factory()->create(['profile' => 'B']);
    $this->actingAs($superAdmin)->withSession(['switch_account_id' => $target->id]);

    $this->delete(route('switcher.destroy'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionMissing('switch_account_id');

    $this->get(route('dashboard'))->assertOk();

    expect(CurrentAccount::resolve()?->id)->toBe($origin->id)
        ->and(CurrentAccount::isSwitching())->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'switcher.exit',
        'actor_user_id' => $superAdmin->id,
        'origin_account_id' => $origin->id,
        'target_account_id' => $target->id,
    ]);
});
