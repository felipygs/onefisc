<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\User;

it('finds a selected Account Client beyond the first page without returning the matching Client from another Account', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);

    for ($index = 1; $index <= 15; $index++) {
        Client::factory()->create([
            'account_id' => $account->id,
            'razao_social' => sprintf('Client anterior %02d', $index),
        ]);
    }

    $visibleMatch = Client::factory()->create([
        'account_id' => $account->id,
        'razao_social' => 'Zeta Pesquisa Remediação',
    ]);
    $otherAccount = Account::factory()->create(['profile' => 'B']);
    Client::factory()->create([
        'account_id' => $otherAccount->id,
        'razao_social' => 'Zeta Pesquisa Remediação',
    ]);

    $this->actingAs($admin)
        ->get(route('clients.index', ['search' => 'Zeta Pesquisa Remediação']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('clients.total', 1)
            ->where('clients.data.0.id', $visibleMatch->id)
            ->where('clients.data.0.razao_social', 'Zeta Pesquisa Remediação'));
});
