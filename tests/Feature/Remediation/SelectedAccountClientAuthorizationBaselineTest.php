<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\User;

it('allows an Account A super_admin to create in selected Account B without granting that access to an ordinary user', function () {
    $origin = Account::factory()->create(['profile' => 'A']);
    $target = Account::factory()->create(['profile' => 'B']);
    $superAdmin = User::factory()->create(['account_id' => $origin->id, 'role' => 'super_admin']);

    $this->actingAs($superAdmin)
        ->withSession(['switch_account_id' => $target->id])
        ->post(route('clients.store'), [
            'cnpj' => '12345678000195',
            'razao_social' => 'Client da Account B',
            'regime' => 'simples',
            'contador_responsavel' => 'Contador B',
        ])
        ->assertRedirect(route('clients.index'));

    $this->assertDatabaseHas('clients', [
        'account_id' => $target->id,
        'cnpj' => '12345678000195',
    ]);

    $ordinaryUser = User::factory()->create(['account_id' => $origin->id, 'role' => 'user']);
    $foreignClient = Client::factory()->create(['account_id' => $target->id]);

    $this->actingAs($ordinaryUser)
        ->withSession(['switch_account_id' => $target->id])
        ->delete(route('clients.destroy', $foreignClient))
        ->assertNotFound();

    $this->assertDatabaseHas('clients', ['id' => $foreignClient->id]);
});
