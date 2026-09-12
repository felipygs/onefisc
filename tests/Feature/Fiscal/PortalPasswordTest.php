<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

if (! function_exists('portalPasswordAccountUser')) {
    /**
     * @return array{Account, User}
     */
    function portalPasswordAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

it('stores the portal password encrypted, never re-exposed', function () {
    [$account, $admin] = portalPasswordAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $secret = 'portal-'.Str::random(12);

    $response = $this->put(route('certificates.portal-password', $client), [
        'portal_password' => $secret,
    ]);

    $response->assertRedirect()->assertSessionHas('status');
    expect($response->getContent() ?: '')->not->toContain($secret);

    $credential = ClientCredential::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail();

    // Stored value is ciphertext, and decrypts back to the original (portal channel use).
    expect($credential->getRawOriginal('portal_password'))->not->toBeNull()
        ->and($credential->getRawOriginal('portal_password'))->not->toBe($secret)
        ->and(Crypt::decryptString($credential->getRawOriginal('portal_password')))->toBe($secret);

    // Never re-exposed via serialization.
    $serialized = $credential->toArray();
    expect($serialized)->not->toHaveKey('portal_password')
        ->and((string) json_encode($serialized))->not->toContain($secret);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'certificates.portal-password',
        'actor_user_id' => $admin->id,
        'origin_account_id' => $account->id,
        'target_account_id' => $account->id,
    ]);

    $log = AuditLog::query()->where('action', 'certificates.portal-password')->firstOrFail();
    expect($log->metadata)->toBeArray()
        ->and((string) json_encode($log->metadata))->not->toContain($secret);
});

it('denies operador and user on portal password update', function (string $role) {
    [$account, $user] = portalPasswordAccountUser($role);
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($user);

    $this->put(route('certificates.portal-password', $client), [
        'portal_password' => 'portal-'.Str::random(12),
    ])->assertForbidden();

    expect(ClientCredential::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'certificates.portal-password')->count())->toBe(0);
})->with(['operador', 'user']);

it('isolates portal password updates to the current account', function () {
    [$accountA, $adminA] = portalPasswordAccountUser('admin');
    $accountB = Account::factory()->create(['profile' => 'B']);
    $foreignClient = Client::factory()->create(['account_id' => $accountB->id]);
    $this->actingAs($adminA);

    $status = $this->put(route('certificates.portal-password', $foreignClient), [
        'portal_password' => 'portal-'.Str::random(12),
    ])->getStatusCode();

    expect($status)->toBeIn([403, 404]);

    expect(ClientCredential::withoutGlobalScopes()->where('client_id', $foreignClient->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'certificates.portal-password')->count())->toBe(0)
        ->and($foreignClient->account_id)->toBe($accountB->id)
        ->and($accountA->id)->not->toBe($accountB->id);
});
