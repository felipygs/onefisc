<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientTag;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use App\Models\WorkProcessTaskDefinition;
use App\Models\WorkTask;
use Illuminate\Support\Facades\DB;

// Inertia renders resolve without built assets, mirroring WorkProcessTest.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('catalogAccountUser')) {
    /**
     * @return array{0: Account, 1: User}
     */
    function catalogAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

if (! function_exists('catalogProcessWithDefinitions')) {
    function catalogProcessWithDefinitions(Account $account, array $overrides = []): WorkProcess
    {
        $process = WorkProcess::factory()->create(array_merge(['account_id' => $account->id], $overrides));
        WorkProcessTaskDefinition::factory()->create([
            'work_process_id' => $process->id, 'title' => 'Etapa um', 'position' => 0,
        ]);
        WorkProcessTaskDefinition::factory()->create([
            'work_process_id' => $process->id, 'title' => 'Etapa dois', 'position' => 1,
        ]);

        return $process;
    }
}

if (! function_exists('catalogTagClient')) {
    function catalogTagClient(Client $client, ClientTag $tag): void
    {
        DB::table('client_tag')->insertOrIgnore([
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);
    }
}

it('previews the effective set split by rule and extras without writing', function () {
    [$account, $admin] = catalogAccountUser('admin');
    $this->actingAs($admin);

    $tag = ClientTag::factory()->create(['account_id' => $account->id]);
    $ruleMatch = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $taggedOut = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $outsider = Client::factory()->create(['account_id' => $account->id, 'regime' => 'lucro_real']);
    $excluded = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    catalogTagClient($taggedOut, $tag);
    catalogTagClient($excluded, $tag);

    $process = catalogProcessWithDefinitions($account, [
        'association_regimes' => ['simples_nacional'],
        'association_tag_ids' => [$tag->id],
        'extra_client_ids' => [$outsider->id, $ruleMatch->id],
        'excluded_client_ids' => [$excluded->id],
    ]);

    $this->getJson(route('work.processes.association-preview', $process))
        ->assertOk()
        ->assertJson([
            'client_ids' => [$ruleMatch->id, $taggedOut->id, $outsider->id],
            'count' => 3,
            'by_source' => [
                'rule' => [$taggedOut->id, $excluded->id],
                'extras' => [$ruleMatch->id, $outsider->id],
            ],
        ]);

    // Dry-run: nothing materialized.
    expect(WorkProcessClient::where('work_process_id', $process->id)->count())->toBe(0)
        ->and(WorkTask::where('work_process_id', $process->id)->count())->toBe(0);
});

it('previews every account client when regimes are empty', function () {
    [$account, $admin] = catalogAccountUser('admin');
    $this->actingAs($admin);

    $first = Client::factory()->create(['account_id' => $account->id]);
    $second = Client::factory()->create(['account_id' => $account->id]);

    $process = catalogProcessWithDefinitions($account);

    $this->getJson(route('work.processes.association-preview', $process))
        ->assertOk()
        ->assertJson([
            'client_ids' => [$first->id, $second->id],
            'count' => 2,
        ]);
});

it('previews draft overrides without persisting them', function () {
    [$account, $admin] = catalogAccountUser('admin');
    $this->actingAs($admin);

    $match = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $other = Client::factory()->create(['account_id' => $account->id, 'regime' => 'lucro_real']);

    $process = catalogProcessWithDefinitions($account);

    $this->getJson(route('work.processes.association-preview', $process, [
        'regimes' => 'simples_nacional',
        'tag_ids' => '',
        'extra_ids' => (string) $other->id,
        'excluded_ids' => '',
    ]))
        ->assertOk()
        ->assertJson([
            'client_ids' => [$match->id, $other->id],
            'count' => 2,
        ]);

    // Overrides never persist: stored rules stay empty.
    expect($process->fresh()?->association_regimes)->toBeNull()
        ->and($process->fresh()?->extra_client_ids)->toBeNull()
        ->and(WorkProcessClient::where('work_process_id', $process->id)->count())->toBe(0);
});

it('round-trips the editor save: rules persist then apply materializes', function () {
    [$account, $operator] = catalogAccountUser('operador');
    $this->actingAs($operator);

    $first = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $second = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);

    $process = catalogProcessWithDefinitions($account);

    // Editor opens for operadores.
    $this->get(route('work.catalog.show', $process))->assertOk();

    // Aplicar associação, first leg: persist the rules through 2.1 update.
    $this->put(route('work.processes.update', $process), [
        'association_regimes' => ['simples_nacional'],
        'due_mode' => 'fixed_day',
        'due_day' => 10,
        'competence_offset' => 'due_month',
        'target_lead_days' => 3,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'month',
    ])->assertRedirect(route('work.processes.index'));

    expect($process->fresh()?->association_regimes)->toBe(['simples_nacional'])
        ->and($process->fresh()?->due_day)->toBe(10)
        ->and($process->fresh()?->target_lead_days)->toBe(3);

    // Aplicar associação, second leg: effective ids through 2.2 endpoint.
    $preview = $this->getJson(route('work.processes.association-preview', $process))->assertOk();

    $this->put(route('work.processes.clients.update', $process), [
        'client_ids' => $preview->json('client_ids'),
    ])->assertRedirect();

    expect(WorkProcessClient::where('work_process_id', $process->id)->pluck('client_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and(WorkTask::where('work_process_id', $process->id)->count())->toBe(4);
});

it('forbids collaborators on catalog editor and preview', function () {
    [$account, $user] = catalogAccountUser('user');
    $this->actingAs($user);

    $process = catalogProcessWithDefinitions($account);

    $this->get(route('work.catalog.index'))->assertForbidden();
    $this->get(route('work.catalog.show', $process))->assertForbidden();
    $this->getJson(route('work.processes.association-preview', $process))->assertForbidden();
});

it('answers 404 for foreign processes on catalog editor and preview', function () {
    [$accountA] = catalogAccountUser('admin');
    [$accountB, $adminB] = catalogAccountUser('admin');

    $foreign = catalogProcessWithDefinitions($accountA);

    $this->actingAs($adminB);

    $this->get(route('work.catalog.show', $foreign))->assertNotFound();
    $this->getJson(route('work.processes.association-preview', $foreign))->assertNotFound();
});

it('lists catalog processes with client ids and counts without per-process queries', function () {
    [$account, $admin] = catalogAccountUser('admin');
    $this->actingAs($admin);

    $clients = Client::factory()->count(3)->create(['account_id' => $account->id]);
    $processes = WorkProcess::factory()->count(4)->create(['account_id' => $account->id]);

    foreach ($processes as $index => $process) {
        WorkProcessClient::create([
            'work_process_id' => $process->id,
            'client_id' => $clients[$index % 3]->id,
        ]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get(route('work.catalog.index', ['search' => '']));

    $associationQueries = collect(DB::getQueryLog())
        ->filter(fn ($entry) => str_contains($entry['query'], 'work_process_clients'))
        ->values();

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('work/Catalog')
        ->has('processes', 4)
        ->where('processes.0.clients_count', 1)
        ->has('processes.0.client_ids', 1));

    // 4 processes: counts ride the listing query as subselects and the ids
    // ride one eager `where in` — never one query per row.
    expect($associationQueries)->toHaveCount(2);
});

it('extends the 2.1 index payload with client ids and counts', function () {
    [$account, $admin] = catalogAccountUser('admin');
    $this->actingAs($admin);

    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = catalogProcessWithDefinitions($account);
    WorkProcessClient::create(['work_process_id' => $process->id, 'client_id' => $client->id]);

    $this->get(route('work.processes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Work/Processes/Index', false)
            ->where('processes.data.0.clients_count', 1)
            ->where('processes.data.0.client_ids', [$client->id]));
});

it('searches the catalog by process title', function () {
    [$account, $admin] = catalogAccountUser('admin');
    $this->actingAs($admin);

    catalogProcessWithDefinitions($account, ['title' => 'Fechamento mensal Alfa']);
    catalogProcessWithDefinitions($account, ['title' => 'Folha Beta']);

    $this->get(route('work.catalog.index', ['search' => 'Alfa']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('work/Catalog')
            ->has('processes', 1)
            ->where('processes.0.title', 'Fechamento mensal Alfa'));
});
