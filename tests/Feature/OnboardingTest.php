<?php

use App\Models\Account;
use App\Models\User;

it('creates account A with super_admin on empty base', function () {
    $this->post('/onboarding', ['account_name' => 'Matriz', 'name' => 'Root', 'email' => 'root@x.com', 'password' => 'password123', 'password_confirmation' => 'password123'])
        ->assertRedirect('/dashboard');

    expect(Account::first()->profile)->toBe('A')
        ->and(User::first()->role)->toBe('super_admin');
});

it('blocks public registration once accounts exist', function () {
    Account::factory()->create(['profile' => 'A']);

    $this->get('/register')->assertForbidden();
});
