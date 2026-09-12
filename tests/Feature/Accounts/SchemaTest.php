<?php

use App\Models\Account;
use App\Models\Client;

it('allows the same cnpj in different accounts', function () {
    $a = Account::factory()->create(['profile' => 'A']);
    $b = Account::factory()->create(['profile' => 'B']);

    Client::factory()->create(['account_id' => $a->id, 'cnpj' => '11222333000181']);
    Client::factory()->create(['account_id' => $b->id, 'cnpj' => '11222333000181']);

    expect(Client::where('cnpj', '11222333000181')->count())->toBe(2);
});
