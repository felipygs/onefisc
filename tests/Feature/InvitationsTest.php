<?php

use App\Models\Account;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Validation\ValidationException;

it('accepts a valid invitation creating the user', function () {
    $inv = Invitation::factory()->create(['expires_at' => now()->addDays(7)]);

    app(InvitationService::class)->accept($inv->token, 'secret123', 'secret123');

    expect(User::where('email', $inv->email)->exists())->toBeTrue()
        ->and($inv->fresh()->accepted_at)->not->toBeNull();
});

it('rejects expired invitations', function () {
    $inv = Invitation::factory()->create(['expires_at' => now()->subDay()]);

    expect(fn () => app(InvitationService::class)->accept($inv->token, 'secret123', 'secret123'))
        ->toThrow(ValidationException::class);
});

it('invites with a 7 day expiry storing only the token hash', function () {
    $account = Account::factory()->create(['profile' => 'B']);

    $result = app(InvitationService::class)->invite($account, 'Ada', 'ada@example.com', 'admin');

    $expiresAt = strtotime((string) $result['invitation']->expires_at);

    expect($result['token'])->toBeString()->not->toBeEmpty()
        ->and($result['invitation']->token_hash)->toBe(hash('sha256', $result['token']))
        ->and($expiresAt)->toBeGreaterThan(time() + 6 * 86400)
        ->and($expiresAt)->toBeLessThan(time() + 8 * 86400);
});
