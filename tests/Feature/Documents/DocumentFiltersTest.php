<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Support\Collection;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// Task 4.3: advanced documents table — backend filters, sort and
// query-string pagination on DocumentDashboardController@all.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('filtersAccountUser')) {
    function filtersAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

it('filters by family scoped to the account', function () {
    [$accountA, $adminA] = filtersAccountUser('admin');
    [$accountB] = filtersAccountUser('admin');

    $clientA = Client::factory()->create(['account_id' => $accountA->id]);
    FiscalDocument::factory()->create(['client_id' => $clientA->id, 'family' => 'nfe']);
    FiscalDocument::factory()->create(['client_id' => $clientA->id, 'family' => 'cte']);

    $clientB = Client::factory()->create(['account_id' => $accountB->id]);
    FiscalDocument::factory()->create(['client_id' => $clientB->id, 'family' => 'nfe']);

    actingAs($adminA);

    get(route('documents.all', ['family' => 'nfe']))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/All')
        ->where('documents.total', 1));
});

it('searches by access key via q', function () {
    [$account, $admin] = filtersAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);

    FiscalDocument::factory()->create([
        'client_id' => $client->id,
        'key' => '35260112345678000190550010000012341000012340',
    ]);
    FiscalDocument::factory()->create([
        'client_id' => $client->id,
        'key' => '35260199999999000190550010000099991000099990',
    ]);

    actingAs($admin);

    get(route('documents.all', ['q' => '00001234']))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/All')
        ->where('documents.total', 1)
        ->where('documents.data.0.key', '35260112345678000190550010000012341000012340'));
});

it('filters by origin', function () {
    [$account, $admin] = filtersAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);

    FiscalDocument::factory()->create(['client_id' => $client->id, 'origin' => 'distribuicao']);
    FiscalDocument::factory()->create(['client_id' => $client->id, 'origin' => 'portal']);

    actingAs($admin);

    get(route('documents.all', ['origin' => 'portal']))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/All')
        ->where('documents.total', 1)
        ->where('documents.data.0.origin', 'portal'));
});

it('sorts by emission ascending and descending', function () {
    [$account, $admin] = filtersAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);

    $oldest = FiscalDocument::factory()->create(['client_id' => $client->id, 'emission_at' => now()->subDays(9)]);
    $newest = FiscalDocument::factory()->create(['client_id' => $client->id, 'emission_at' => now()->subDay()]);

    actingAs($admin);

    get(route('documents.all', ['sort' => 'emissao', 'dir' => 'asc']))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/All')
        ->where('documents.data.0.id', $oldest->id)
        ->where('documents.data.1.id', $newest->id));

    get(route('documents.all', ['sort' => 'emissao', 'dir' => 'desc']))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/All')
        ->where('documents.data.0.id', $newest->id)
        ->where('documents.data.1.id', $oldest->id));
});

it('keeps active filters in the pagination query string', function () {
    [$account, $admin] = filtersAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);

    FiscalDocument::factory()->count(16)->create(['client_id' => $client->id, 'family' => 'nfe']);

    actingAs($admin);

    get(route('documents.all', ['family' => 'nfe']))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/All')
        ->where('documents.total', 16)
        ->where('documents.next_page_url', function (mixed $url) {
            expect($url)->toBeString()->toContain('family=nfe')->toContain('page=2');

            return true;
        }));
});

it('denies the user role with 403 on the filtered table', function () {
    [$account, $user] = filtersAccountUser('user');

    actingAs($user);

    get(route('documents.all', ['family' => 'nfe', 'q' => '123']))->assertForbidden();
});

it('exposes the origin flag on every row', function () {
    [$account, $admin] = filtersAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);

    FiscalDocument::factory()->create(['client_id' => $client->id, 'origin' => 'distribuicao']);

    actingAs($admin);

    get(route('documents.all'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('documents/All')
        ->where('documents.data', function (mixed $rows) {
            $rows = $rows instanceof Collection ? $rows->all() : $rows;

            expect($rows)->toHaveCount(1)
                ->and(array_key_exists('origin', $rows[0]))->toBeTrue()
                ->and($rows[0]['origin'])->toBe('distribuicao');

            return true;
        }));
});
