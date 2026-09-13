<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalSyncSubscription;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// Task 4.5: Fiscal tab on the client page — read-only SyncState,
// client-scoped documents paginator with the global filter contract,
// certificate state kept, gates unchanged.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('syncStateAccountUser')) {
    function syncStateAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

it('exposes the sync state read-only with block and volume flags', function () {
    [$account, $admin] = syncStateAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    FiscalSyncSubscription::factory()->create([
        'client_id' => $client->id,
        'blocked_until' => now()->addMinutes(30),
    ]);

    actingAs($admin);

    get(route('clients.show', $client))->assertOk()->assertInertia(fn ($page) => $page
        ->component('clients/Show')
        ->whereNotNull('sync.blocked_until')
        ->where('sync.volume_exhausted', false));
});

it('shares honest empty sync and document props without credential or documents', function () {
    [$account, $admin] = syncStateAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);

    actingAs($admin);

    get(route('clients.show', $client))->assertOk()->assertInertia(fn ($page) => $page
        ->component('clients/Show')
        ->where('certificate.status', 'missing')
        ->where('documents.total', 0)
        ->where('sync.last_run_at', null)
        ->where('sync.next_run_at', null)
        ->where('sync.blocked_until', null)
        ->where('sync.new_documents', 0)
        ->where('sync.volume_exhausted', false));
});

it('scopes the client documents paginator with the same family filter', function () {
    [$account, $admin] = syncStateAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'nfe']);
    FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'cte']);

    $other = Client::factory()->create(['account_id' => $account->id]);
    FiscalDocument::factory()->create(['client_id' => $other->id, 'family' => 'nfe']);

    actingAs($admin);

    get(route('clients.show', ['client' => $client->id, 'family' => 'nfe']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/Show')
            ->where('documents.total', 1)
            ->where('documents.data.0.family', 'nfe')
            ->where('filters.family', 'nfe'));
});

it('denies the user role with 403 on the client page', function () {
    [$account, $user] = syncStateAccountUser('user');
    $client = Client::factory()->create(['account_id' => $account->id]);

    actingAs($user);

    get(route('clients.show', $client))->assertForbidden();
});

it('matches literal percent in the client documents search instead of wildcards', function () {
    [$account, $admin] = syncStateAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);

    FiscalDocument::factory()->create(['client_id' => $client->id, 'issuer_name' => '100% legit LTDA']);
    FiscalDocument::factory()->create(['client_id' => $client->id, 'issuer_name' => '100X legit LTDA']);

    actingAs($admin);

    get(route('clients.show', ['client' => $client->id, 'q' => '100%']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clients/Show')
            ->where('documents.total', 1)
            ->where('documents.data.0.issuer_name', '100% legit LTDA'));
});
