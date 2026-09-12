<?php

use App\Models\Invitation;
use App\Models\User;

it('shows the accept page for a valid invitation', function () {
    $inv = Invitation::factory()->create(['expires_at' => now()->addDays(7)]);

    $this->get("/invitations/accept/{$inv->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invitations/Accept')
            ->where('expired', false)
            ->where('email', $inv->email));
});

it('flags expired invitations on the accept page', function () {
    $inv = Invitation::factory()->create(['expires_at' => now()->subDay()]);

    $this->get("/invitations/accept/{$inv->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invitations/Accept')
            ->where('expired', true));
});

it('accepts a valid invitation over http creating the user', function () {
    $inv = Invitation::factory()->create(['expires_at' => now()->addDays(7)]);

    $this->post("/invitations/accept/{$inv->token}", [
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ])->assertRedirect('/dashboard');

    expect(User::where('email', $inv->email)->exists())->toBeTrue();
});
