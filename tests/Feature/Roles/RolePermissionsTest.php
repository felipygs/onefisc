<?php

use App\Models\Account;
use App\Models\User;

it('denies operator from managing users but allows operating clients', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $operator = User::factory()->create(['account_id' => $account->id, 'role' => 'operador']);

    expect($operator->can('manage-users', $account))->toBeFalse()
        ->and($operator->can('operate-clients', $account))->toBeTrue();
});
