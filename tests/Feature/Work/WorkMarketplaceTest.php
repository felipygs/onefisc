<?php

use App\Models\Account;
use App\Models\User;
use App\Models\WorkMarketplaceProcess;
use App\Models\WorkMarketplaceTaskDefinition;
use App\Models\WorkProcess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

// TDD Task 4.2: browse publicado + install idempotente (duplo e concorrente).
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('marketplaceAccountUser')) {
    /**
     * @return array{0: Account, 1: User}
     */
    function marketplaceAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

if (! function_exists('marketplaceListing')) {
    function marketplaceListing(array $overrides = [], int $definitions = 3): WorkMarketplaceProcess
    {
        $listing = WorkMarketplaceProcess::factory()->create($overrides);

        foreach (range(0, $definitions - 1) as $position) {
            WorkMarketplaceTaskDefinition::factory()->create([
                'marketplace_process_id' => $listing->id,
                'position' => $position,
            ]);
        }

        return $listing;
    }
}

it('mostra apenas publicados com flags added por account', function () {
    [$accountA, $adminA] = marketplaceAccountUser('admin');
    [$accountB, $adminB] = marketplaceAccountUser('admin');

    $installed = marketplaceListing(['title' => 'PGDAS Mensal']);
    $available = marketplaceListing(['title' => 'Folha Mensal']);
    marketplaceListing(['title' => 'Rascunho interno', 'published' => false], 2);

    $this->actingAs($adminA);
    $this->post(route('work.marketplace.install', $installed))->assertStatus(303);

    $processA = WorkProcess::where('account_id', $accountA->id)
        ->where('marketplace_process_id', (string) $installed->id)
        ->firstOrFail();

    $this->getJson(route('work.marketplace.index'))
        ->assertOk()
        ->assertJsonCount(2, 'listings')
        ->assertJsonPath('listings.0.title', 'Folha Mensal')
        ->assertJsonPath('listings.0.added', false)
        ->assertJsonPath('listings.0.added_process_id', null)
        ->assertJsonPath('listings.1.title', 'PGDAS Mensal')
        ->assertJsonPath('listings.1.added', true)
        ->assertJsonPath('listings.1.added_process_id', $processA->id);

    $response = $this->getJson(route('work.marketplace.index'));
    $titles = collect($response->json('listings'))->pluck('title')->all();
    expect($titles)->not->toContain('Rascunho interno');

    // Outra account vê os mesmos publicados, mas nada como adicionado.
    $this->actingAs($adminB);
    $this->getJson(route('work.marketplace.index'))
        ->assertOk()
        ->assertJsonCount(2, 'listings')
        ->assertJsonPath('listings.1.added', false)
        ->assertJsonPath('listings.1.added_process_id', null);

    expect($available->fresh()?->exists())->toBeTrue();
});

it('expõe task_count por listing no browse', function () {
    [$account, $admin] = marketplaceAccountUser('admin');
    $this->actingAs($admin);

    marketplaceListing(['title' => 'Com três'], 3);
    marketplaceListing(['title' => 'Com cinco'], 5);

    $listings = collect($this->getJson(route('work.marketplace.index'))->json('listings'))
        ->keyBy('title');

    expect((int) $listings['Com três']['task_count'])->toBe(3)
        ->and((int) $listings['Com cinco']['task_count'])->toBe(5);
});

it('instala copiando checklist com posições e campos ricos', function () {
    [$account, $admin] = marketplaceAccountUser('operador');
    $this->actingAs($admin);

    $listing = WorkMarketplaceProcess::factory()->create([
        'title' => 'PGDAS Mensal',
        'description' => 'Apuração mensal do Simples.',
        'category' => 'Fiscal',
    ]);
    WorkMarketplaceTaskDefinition::factory()->create([
        'marketplace_process_id' => $listing->id,
        'title' => 'Apurar débitos',
        'position' => 0,
        'description' => 'Conferir notas do mês anterior.',
        'due_day' => 20,
        'competence_offset' => 'previous_month',
        'priority' => 'high',
        'requires_document' => true,
    ]);
    WorkMarketplaceTaskDefinition::factory()->create([
        'marketplace_process_id' => $listing->id,
        'title' => 'Gerar DAS',
        'position' => 1,
        'due_day' => 20,
        'competence_offset' => 'previous_month',
        'priority' => 'medium',
        'requires_document' => false,
    ]);

    $this->post(route('work.marketplace.install', $listing))
        ->assertStatus(303);

    $process = WorkProcess::where('account_id', $account->id)
        ->where('marketplace_process_id', (string) $listing->id)
        ->firstOrFail();

    $this->post(route('work.marketplace.install', $listing))
        ->assertRedirect(route('work.catalog.show', ['process' => $process->id, 'tab' => 'association']));

    expect($process->title)->toBe('PGDAS Mensal')
        ->and($process->description)->toBe('Apuração mensal do Simples.')
        ->and($process->source)->toBe('marketplace');

    $definitions = $process->definitions()->orderBy('position')->get();
    expect($definitions)->toHaveCount(2)
        ->and($definitions[0]->title)->toBe('Apurar débitos')
        ->and($definitions[0]->position)->toBe(0)
        ->and($definitions[0]->description)->toBe('Conferir notas do mês anterior.')
        ->and($definitions[0]->due_day)->toBe(20)
        ->and($definitions[0]->competence_offset)->toBe('previous_month')
        ->and($definitions[0]->priority)->toBe('high')
        ->and($definitions[0]->requires_document)->toBeTrue()
        ->and($definitions[1]->title)->toBe('Gerar DAS')
        ->and($definitions[1]->position)->toBe(1);
});

it('converge o install duplo para o mesmo processo sem duplicar', function () {
    [$account, $admin] = marketplaceAccountUser('admin');
    $this->actingAs($admin);

    $listing = marketplaceListing(['title' => 'PGDAS Mensal'], 4);

    $this->post(route('work.marketplace.install', $listing))->assertStatus(303);
    $this->post(route('work.marketplace.install', $listing))->assertStatus(303);

    $processes = WorkProcess::where('account_id', $account->id)
        ->where('marketplace_process_id', (string) $listing->id)
        ->get();

    expect($processes)->toHaveCount(1)
        ->and($processes->first()->definitions()->count())->toBe(4);
});

it('responde 404 para listing não publicado ou inexistente', function () {
    [$account, $admin] = marketplaceAccountUser('admin');
    $this->actingAs($admin);

    $draft = marketplaceListing(['published' => false], 2);

    $this->post(route('work.marketplace.install', $draft))->assertNotFound();
    $this->post(route('work.marketplace.install', 999999))->assertNotFound();

    expect(WorkProcess::where('account_id', $account->id)->count())->toBe(0);
});

it('permite browse ao user mas nega o install com 403', function () {
    [$account, $user] = marketplaceAccountUser('user');
    $this->actingAs($user);

    $listing = marketplaceListing(['title' => 'PGDAS Mensal'], 2);

    $this->getJson(route('work.marketplace.index'))->assertOk();

    $this->post(route('work.marketplace.install', $listing))->assertForbidden();

    expect(WorkProcess::where('account_id', $account->id)->count())->toBe(0);
});

it('instala na account chamadora sem vazar o processo de outra account', function () {
    [$accountA, $adminA] = marketplaceAccountUser('admin');
    [$accountB, $adminB] = marketplaceAccountUser('admin');

    $listing = marketplaceListing(['title' => 'PGDAS Mensal'], 2);

    $this->actingAs($adminA);
    $this->post(route('work.marketplace.install', $listing))->assertStatus(303);

    $this->actingAs($adminB);
    $this->post(route('work.marketplace.install', $listing))->assertStatus(303);

    $processA = WorkProcess::withoutGlobalScopes()->where('account_id', $accountA->id)
        ->where('marketplace_process_id', (string) $listing->id)
        ->firstOrFail();
    $processB = WorkProcess::withoutGlobalScopes()->where('account_id', $accountB->id)
        ->where('marketplace_process_id', (string) $listing->id)
        ->firstOrFail();

    expect($processA->id)->not->toBe($processB->id)
        ->and(WorkProcess::withoutGlobalScopes()->where('marketplace_process_id', (string) $listing->id)->count())->toBe(2);
});

it('guarda a idempotência no índice único account + marketplace', function () {
    [$account, $admin] = marketplaceAccountUser('admin');
    $this->actingAs($admin);

    $listing = marketplaceListing(['title' => 'PGDAS Mensal'], 1);

    // Convergência sob "concorrência": dois installs em sequência contra o
    // guard único resultam numa única linha.
    $this->post(route('work.marketplace.install', $listing))->assertStatus(303);
    $this->post(route('work.marketplace.install', $listing))->assertStatus(303);

    expect(WorkProcess::where('account_id', $account->id)
        ->where('marketplace_process_id', (string) $listing->id)->count())->toBe(1);

    // O guard é o índice único — escrita direta duplicada viola 23000.
    $duplicate = function () use ($account, $listing): void {
        WorkProcess::create([
            'account_id' => $account->id,
            'title' => 'Duplicado',
            'source' => 'marketplace',
            'marketplace_process_id' => (string) $listing->id,
        ]);
    };

    expect($duplicate)->toThrow(QueryException::class);

    $indexes = collect(Schema::getIndexes('work_processes'));
    expect($indexes->contains(
        fn (array $index): bool => ($index['unique'] ?? false) === true
            && collect($index['columns'] ?? [])->sort()->values()->all() === ['account_id', 'marketplace_process_id']
    ))->toBeTrue();
});
