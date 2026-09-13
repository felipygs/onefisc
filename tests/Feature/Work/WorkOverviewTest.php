<?php

use App\Models\Client;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkTask;

// TDD Task 3.4: `/work/overview` central (situação, donut N de M, carga,
// envelhecimento, atenção, equipe) sobre a competência com a regra 3.1 de
// matching + o predicado de visibilidade das leituras. Factories only.

beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('overviewTask')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function overviewTask(Client $client, WorkProcess $process, string $title, array $overrides = []): WorkTask
    {
        return WorkTask::factory()->create(array_merge([
            'account_id' => $client->account_id,
            'work_process_id' => $process->id,
            'client_id' => $client->id,
            'title' => $title,
        ], $overrides));
    }
}

if (! function_exists('overviewUrl')) {
    function overviewUrl(?string $competence = null): string
    {
        return $competence === null
            ? route('work.overview')
            : route('work.overview', ['competence' => $competence]);
    }
}

it('defaults to the current month and shows exact counters per status plus overdue', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $past = now()->subDays(3)->format('Y-m-d');
    $future = now()->addDays(5)->format('Y-m-d');

    overviewTask($client, $process, 'Backlog', ['competence' => '2026-10', 'status' => 'backlog', 'due_on' => $future]);
    overviewTask($client, $process, 'A fazer futura', ['competence' => '2026-10', 'status' => 'todo', 'due_on' => $future]);
    overviewTask($client, $process, 'A fazer atrasada', ['competence' => '2026-10', 'status' => 'todo', 'due_on' => $past]);
    overviewTask($client, $process, 'Em andamento', ['competence' => '2026-10', 'status' => 'in_progress', 'due_on' => $future]);
    overviewTask($client, $process, 'Concluída vencida', ['competence' => '2026-10', 'status' => 'done', 'due_on' => $past]);
    overviewTask($client, $process, 'Outra competência', ['competence' => '2026-09', 'status' => 'todo', 'due_on' => $future]);

    // Sem competência: default = mês corrente.
    $this->get(overviewUrl())->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('work/Overview', false)
            ->where('competence', now()->format('Y-m')));

    $this->get(overviewUrl('2026-10'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('work/Overview', false)
            ->where('competence', '2026-10')
            ->where('overview.hasData', true)
            ->where('overview.counters.backlog', 1)
            ->where('overview.counters.todo', 2)
            ->where('overview.counters.in_progress', 1)
            ->where('overview.counters.done', 1)
            ->where('overview.counters.overdue', 1)
            ->where('overview.counters.total', 5)
            ->has('overview.links.backlog')
            ->has('overview.links.todo')
            ->has('overview.links.in_progress')
            ->has('overview.links.done')
            ->has('overview.links.overdue'));
});

it('computes the donut over completed associations, ignoring partial ones', function () {
    [$account, $admin] = workAccountUser('admin');
    $done = Client::factory()->create(['account_id' => $account->id]);
    $partial = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    overviewTask($done, $process, 'Par A um', ['competence' => '2026-10', 'status' => 'done']);
    overviewTask($done, $process, 'Par A dois', ['competence' => '2026-10', 'status' => 'done']);
    overviewTask($partial, $process, 'Par B feito', ['competence' => '2026-10', 'status' => 'done']);
    overviewTask($partial, $process, 'Par B aberto', ['competence' => '2026-10', 'status' => 'todo']);

    $this->get(overviewUrl('2026-10'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('overview.donut.done', 1)
            ->where('overview.donut.total', 2)
            ->where('overview.donut.label', '1 de 2'));
});

it('orders load by total and restricts collaborators to themselves', function () {
    [$account, $admin] = workAccountUser('admin');
    $member = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);
    $other = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);
    $client = Client::factory()->create(['account_id' => $account->id]);
    assignClientToUser($client, $member);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);

    foreach (['Pesada um', 'Pesada dois', 'Pesada três'] as $title) {
        overviewTask($client, $process, $title, [
            'competence' => '2026-10', 'status' => 'todo', 'assigned_user_id' => $member->id,
        ]);
    }
    foreach (['Chefia um', 'Chefia dois'] as $title) {
        overviewTask($client, $process, $title, [
            'competence' => '2026-10', 'status' => 'in_progress', 'assigned_user_id' => $admin->id,
        ]);
    }
    overviewTask($client, $process, 'Avulsa', [
        'competence' => '2026-10', 'status' => 'todo', 'assigned_user_id' => $other->id,
    ]);
    // Sem responsável: conta na situação, nunca vira linha de membro.
    overviewTask($client, $process, 'Sem dono', ['competence' => '2026-10', 'status' => 'todo']);

    $this->actingAs($admin);
    $this->get(overviewUrl('2026-10'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('overview.load', 3)
            ->where('overview.load.0.member.id', $member->id)
            ->where('overview.load.0.total', 3)
            ->where('overview.load.1.member.id', $admin->id)
            ->where('overview.load.1.total', 2)
            ->where('overview.load.2.member.id', $other->id)
            ->where('overview.load.2.total', 1)
            ->where('overview.counters.total', 7));

    $this->actingAs($member);
    $this->get(overviewUrl('2026-10'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('overview.load', 1)
            ->where('overview.load.0.member.id', $member->id)
            ->where('overview.load.0.total', 3)
            ->has('overview.team', 1)
            ->where('overview.team.0.member.id', $member->id));
});

it('buckets delay aging with the lists overdue rule and ignores dateless rows', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $due = fn (int $daysAgo): string => now()->subDays($daysAgo)->format('Y-m-d');

    overviewTask($client, $process, 'Uma semana', ['competence' => '2026-10', 'status' => 'todo', 'due_on' => $due(3)]);
    overviewTask($client, $process, 'Duas semanas', ['competence' => '2026-10', 'status' => 'in_progress', 'due_on' => $due(10)]);
    overviewTask($client, $process, 'Vinte dias', ['competence' => '2026-10', 'status' => 'backlog', 'due_on' => $due(20)]);
    overviewTask($client, $process, 'Antiga', ['competence' => '2026-10', 'status' => 'todo', 'due_on' => $due(40)]);
    // Concluída vencida, sem data e futura: fora do envelhecimento.
    overviewTask($client, $process, 'Feita vencida', ['competence' => '2026-10', 'status' => 'done', 'due_on' => $due(40)]);
    overviewTask($client, $process, 'Sem data', ['competence' => '2026-10', 'status' => 'todo', 'due_on' => null]);
    overviewTask($client, $process, 'Futura', ['competence' => '2026-10', 'status' => 'todo', 'due_on' => now()->addDays(4)->format('Y-m-d')]);

    $this->get(overviewUrl('2026-10'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('overview.aging.bands.1-7', 1)
            ->where('overview.aging.bands.8-15', 1)
            ->where('overview.aging.bands.16-30', 1)
            ->where('overview.aging.bands.31+', 1)
            ->where('overview.aging.total', 4));
});

it('lists top overdue offenders in attention and omits zero-task members from team', function () {
    [$account, $admin] = workAccountUser('admin');
    $idle = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    for ($days = 1; $days <= 7; $days++) {
        overviewTask($client, $process, "Atrasada {$days}", [
            'competence' => '2026-10',
            'status' => 'todo',
            'due_on' => now()->subDays($days * 5)->format('Y-m-d'),
            'assigned_user_id' => $admin->id,
        ]);
    }
    overviewTask($client, $process, 'Parada', ['competence' => '2026-10', 'status' => 'backlog']);

    $this->get(overviewUrl('2026-10'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('overview.attention.overdue', 5)
            ->where('overview.attention.overdue.0.title', 'Atrasada 7')
            ->where('overview.attention.overdue.0.age_days', 35)
            ->where('overview.attention.stale_backlog', 1)
            ->has('overview.team', 1)
            ->where('overview.team.0.member.id', $admin->id)
            ->where('overview.team.0.total', 7)
            ->where('overview.team.0.completion_pct', 0)
            // O membro ocioso ($idle) não aparece: sem linha, sem divisão por zero.
            ->where('overview.team', fn ($team) => collect($team)->pluck('member.id')->doesntContain($idle->id)));
});

it('returns empty payloads with the guidance flag for an empty competence', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    overviewTask($client, $process, 'Outubro', ['competence' => '2026-10', 'status' => 'todo']);

    $this->get(overviewUrl('2026-11'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('competence', '2026-11')
            ->where('overview.hasData', false)
            ->where('overview.counters.backlog', 0)
            ->where('overview.counters.todo', 0)
            ->where('overview.counters.in_progress', 0)
            ->where('overview.counters.done', 0)
            ->where('overview.counters.overdue', 0)
            ->where('overview.counters.total', 0)
            ->where('overview.donut.done', 0)
            ->where('overview.donut.total', 0)
            ->has('overview.load', 0)
            ->has('overview.attention.overdue', 0)
            ->where('overview.attention.stale_backlog', 0)
            ->has('overview.team', 0));
});

it('materializes missing instances when opening a competence, like the lists', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = competenceProcess($account);
    competenceAssociate($process, $client);
    $this->actingAs($admin);

    expect(WorkTask::where('work_process_id', $process->id)->count())->toBe(0);

    $this->get(overviewUrl('2026-10'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('overview.hasData', true)
            ->where('overview.counters.total', 2));

    expect(WorkTask::where('work_process_id', $process->id)
        ->where('competence', '2026-10')->count())->toBe(2);
});

it('rejects malformed competence with 422', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $this->getJson(overviewUrl('foo'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('competence');

    $this->getJson(overviewUrl('2026-13'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('competence');
});

it('keeps out-of-scope concepts out of the overview payload', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    overviewTask($client, $process, 'Fechamento', [
        'competence' => '2026-10', 'status' => 'todo', 'assigned_user_id' => $admin->id,
    ]);

    $captured = null;

    $this->get(overviewUrl('2026-10'))->assertOk()
        ->assertInertia(function ($page) use (&$captured) {
            $captured = $page->toArray()['props']['overview'] ?? null;

            return $page->component('work/Overview', false);
        });

    $overview = $captured;
    expect($overview)->not->toBeNull();

    expect(array_keys($overview))->toBe([
        'competence', 'today', 'hasData', 'counters', 'links',
        'donut', 'load', 'aging', 'attention', 'team',
    ])->and(array_keys($overview['counters']))->toBe([
        'backlog', 'todo', 'in_progress', 'done', 'overdue', 'total',
    ])->and(array_keys($overview['donut']))->toBe([
        'done', 'total', 'label',
    ]);

    $flattened = mb_strtolower(json_encode($overview, JSON_UNESCAPED_UNICODE) ?: '');

    foreach (['multa', 'dispens', 'lote', 'ordem de servi', 'service order', 'service_order', 'near fine', 'bulk'] as $forbidden) {
        expect($flattened)->not->toContain($forbidden);
    }
});
