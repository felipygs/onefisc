<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\User;

it('bulk deletes only the selected account clients', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);
    $kept = Client::factory()->create(['account_id' => $account->id]);
    $first = Client::factory()->create(['account_id' => $account->id]);
    $second = Client::factory()->create(['account_id' => $account->id]);

    $this->actingAs($admin)
        ->post(route('clients.bulk-destroy'), ['ids' => [$first->id, $second->id]])
        ->assertRedirect(route('clients.index'));

    $this->assertDatabaseMissing('clients', ['id' => $first->id]);
    $this->assertDatabaseMissing('clients', ['id' => $second->id]);
    $this->assertDatabaseHas('clients', ['id' => $kept->id]);
});

it('denies bulk delete to the user role', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);
    $client = Client::factory()->create(['account_id' => $account->id]);

    $this->actingAs($user)
        ->post(route('clients.bulk-destroy'), ['ids' => [$client->id]])
        ->assertForbidden();

    $this->assertDatabaseHas('clients', ['id' => $client->id]);
});

it('ignores ids from other accounts on bulk delete', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);
    $other = Account::factory()->create(['profile' => 'B']);
    $foreign = Client::factory()->create(['account_id' => $other->id]);

    $this->actingAs($admin)
        ->post(route('clients.bulk-destroy'), ['ids' => [$foreign->id]])
        ->assertRedirect(route('clients.index'));

    $this->assertDatabaseHas('clients', ['id' => $foreign->id]);
});
