<?php

use App\Models\Account;
use App\Models\Client;
use App\Support\CurrentAccount;

it('hides other accounts data via global scope', function () {
    $a = Account::factory()->create(['profile' => 'A']);
    $b = Account::factory()->create(['profile' => 'B']);
    Client::factory()->create(['account_id' => $a->id]);
    Client::factory()->create(['account_id' => $b->id]);

    CurrentAccount::set($b);

    expect(Client::count())->toBe(1)
        ->and(Client::first()->account_id)->toBe($b->id);
});
