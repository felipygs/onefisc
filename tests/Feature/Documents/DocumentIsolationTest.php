<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalDocument;
use App\Models\FiscalSyncCursor;
use App\Models\FiscalSyncSubscription;
use App\Models\User;
use Illuminate\Support\Collection;

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
        ->where('attention', function (mixed $attention) use ($clientA, $clientB) {
            // Every row must belong to A; a B leak anywhere in the list fails.
            $rows = $attention instanceof Collection ? $attention->all() : $attention;

            expect($rows)->not->toBeEmpty();

            foreach ($rows as $row) {
                expect($row['client_id'])->toBe($clientA->id);
            }

            expect(array_column($rows, 'client_id'))->not->toContain($clientB->id);

            return true;
        }));
});

it('denies the user role with 403 on all three documents routes', function () {
    [$account, $user] = documentsAccountUser('user');

    actingAs($user);

    get(route('documents.index'))->assertForbidden();
    get(route('documents.all'))->assertForbidden();
    get(route('documents.clients'))->assertForbidden();
});

it('applies the period window consistently across cards, chart, families and recent', function () {
    [$account, $admin] = documentsAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);

    FiscalDocument::factory()->count(2)->create([
        'client_id' => $client->id,
        'family' => 'nfe',
        'emission_at' => now(),
    ]);
    // Undated and out-of-window documents stay out of every period aggregate.
    FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'nfe', 'emission_at' => null]);
    FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'cte', 'emission_at' => now()->subDays(60)]);

    actingAs($admin);

    get(route('documents.index', ['period' => '30d']))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/Index')
        ->where('overview.totals.documents', 2)
        ->where('overview.families.0.count', 2)
        ->where('overview.families.1.count', 0)
        ->where('chart', function (mixed $chart) {
            // Chart total matches the cards total: same window everywhere.
            $points = $chart instanceof Collection ? $chart->all() : $chart;

            expect(array_sum(array_column($points, 'amount')))->toBe(2);

            return true;
        })
        ->where('overview.recent', fn (mixed $recent) => count($recent) === 2));
});

it('flags a subscribed channel without a cursor as stalled sync attention', function () {
    [$account, $admin] = documentsAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    FiscalSyncSubscription::factory()->create([
        'client_id' => $client->id,
        'family' => 'nfe',
        'blocked_until' => null,
    ]);
    // No cursor for the subscribed family: the channel never synced.

    actingAs($admin);

    get(route('documents.clients'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/Clients')
        ->where('attention', function (mixed $attention) use ($client) {
            $rows = $attention instanceof Collection ? $attention->all() : $attention;

            foreach ($rows as $row) {
                if ($row['client_id'] === $client->id && $row['reason'] === 'sync_failed') {
                    return true;
                }
            }

            return false;
        }));
});

it('reports no attention for a healthy client with cursor, certificate and xml', function () {
    [$account, $admin] = documentsAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    ClientCredential::factory()->create([
        'client_id' => $client->id,
        'pfx_data' => 'healthy-pfx',
        'expires_at' => now()->addYear(),
    ]);
    FiscalSyncSubscription::factory()->create(['client_id' => $client->id, 'family' => 'nfe', 'blocked_until' => null]);
    FiscalSyncCursor::factory()->create(['client_id' => $client->id, 'family' => 'nfe']);
    FiscalDocument::factory()->create(['client_id' => $client->id, 'has_xml' => true]);

    actingAs($admin);

    get(route('documents.clients'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/Clients')
        ->where('attention', []));
});
