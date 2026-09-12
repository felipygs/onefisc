<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

function certificateUiAccountUser(string $role = 'admin'): array
{
    $account = Account::factory()->create(['profile' => 'B']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

    return [$account, $user];
}

function certificateUiCredential(Client $client, ?DateTimeInterface $expiresAt): ClientCredential
{
    return ClientCredential::factory()->create([
        'client_id' => $client->id,
        'pfx_data' => Crypt::encryptString('pfx-bytes'),
        'pfx_password' => Crypt::encryptString('segredo-pfx'),
        'portal_password' => Crypt::encryptString('segredo-portal'),
        'thumbprint' => hash('sha256', (string) $client->id.$expiresAt?->toIso8601String()),
        'expires_at' => $expiresAt,
    ]);
}

it('shares a valid certificate state without secrets', function () {
    [$account, $admin] = certificateUiAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    certificateUiCredential($client, now()->addYear());
    $this->actingAs($admin);

    $this->get(route('clients.show', $client))->assertOk()->assertInertia(fn ($page) => $page
        ->where('certificate.status', 'valid')
        ->whereNotNull('certificate.expires_at')
        ->missing('certificate.pfx_data')
        ->missing('certificate.pfx_password')
        ->missing('certificate.portal_password'));
});

it('shares a missing certificate state without secrets', function () {
    [$account, $admin] = certificateUiAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $this->get(route('clients.show', $client))->assertOk()->assertInertia(fn ($page) => $page
        ->where('certificate.status', 'missing')
        ->missing('certificate.expires_at')
        ->missing('certificate.pfx_data')
        ->missing('certificate.pfx_password')
        ->missing('certificate.portal_password'));
});

it('shares an expired certificate state without secrets', function () {
    [$account, $admin] = certificateUiAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    certificateUiCredential($client, now()->subDay());
    $this->actingAs($admin);

    $this->get(route('clients.show', $client))->assertOk()->assertInertia(fn ($page) => $page
        ->where('certificate.status', 'expired')
        ->missing('certificate.expires_at')
        ->missing('certificate.pfx_data')
        ->missing('certificate.pfx_password')
        ->missing('certificate.portal_password'));
});

it('shares an expiring certificate state without secrets', function () {
    [$account, $admin] = certificateUiAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    certificateUiCredential($client, now()->addDays(10));
    $this->actingAs($admin);

    $this->get(route('clients.show', $client))->assertOk()->assertInertia(fn ($page) => $page
        ->where('certificate.status', 'expiring')
        ->whereNotNull('certificate.expires_at')
        ->missing('certificate.pfx_data')
        ->missing('certificate.pfx_password')
        ->missing('certificate.portal_password'));
});

it('shares the read-only certificate state with operador too', function () {
    [$account, $operador] = certificateUiAccountUser('operador');
    $client = Client::factory()->create(['account_id' => $account->id]);
    certificateUiCredential($client, now()->addYear());
    $this->actingAs($operador);

    $this->get(route('clients.show', $client))->assertOk()->assertInertia(fn ($page) => $page
        ->where('certificate.status', 'valid')
        ->whereNotNull('certificate.expires_at')
        ->missing('certificate.pfx_data')
        ->missing('certificate.pfx_password')
        ->missing('certificate.portal_password'));
});
