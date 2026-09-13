<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalDocument;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// Documents portfolio pages land in Task 4.2; stub Vite so Inertia page
// renders resolve in tests without manifest entries.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('documentsAccountUser')) {
    function documentsAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

it('isolates the documents portfolio so account A never sees account B documents', function () {
    [$accountA, $adminA] = documentsAccountUser('admin');
    [$accountB, $adminB] = documentsAccountUser('admin');

    $clientA = Client::factory()->create(['account_id' => $accountA->id]);
    FiscalDocument::factory()->count(3)->create(['client_id' => $clientA->id, 'has_xml' => false]);

    $clientB = Client::factory()->create(['account_id' => $accountB->id]);
    FiscalDocument::factory()->count(2)->create(['client_id' => $clientB->id, 'has_xml' => false]);

    actingAs($adminA);

    // Portfolio overview counts only A's documents.
    get(route('documents.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/Index')
        ->where('overview.totals.documents', 3));

    // Global table lists only A's documents.
    get(route('documents.all'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/All')
        ->where('documents.total', 3));

    // Wallet attention lists only A's clients.
    ClientCredential::factory()->create(['client_id' => $clientA->id, 'pfx_data' => null, 'expires_at' => null]);
    ClientCredential::factory()->create(['client_id' => $clientB->id, 'pfx_data' => null, 'expires_at' => null]);

    get(route('documents.clients'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/Clients')
        ->where('attention.0.client_id', $clientA->id));
});

it('denies the user role with 403 on all three documents routes', function () {
    [$account, $user] = documentsAccountUser('user');

    actingAs($user);

    get(route('documents.index'))->assertForbidden();
    get(route('documents.all'))->assertForbidden();
    get(route('documents.clients'))->assertForbidden();
});
