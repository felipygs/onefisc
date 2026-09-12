<?php

use App\Models\Account;
use App\Models\User;

it('links a user to exactly one account with a role', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);

    expect($user->account->is($account))->toBeTrue()
        ->and($user->role)->toBe('admin');
});
