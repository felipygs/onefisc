<?php

use App\Models\Client;
use App\Models\WorkProcess;
use App\Models\WorkProcessTaskDefinition;
use App\Models\WorkTask;
use App\Support\WorkDueDates;

// Inertia renders resolve without built assets, mirroring WorkCompetenceTest.
beforeEach(function () {
    $this->withoutVite();
});

it('computes previous-month fixed day with target lead', function () {
    $result = WorkDueDates::compute('fixed_day', 20, null, 'previous_month', 5, '2026-09');

    expect($result['due_on'])->toBe('2026-10-20')
        ->and($result['target_date'])->toBe('2026-10-15');
});

it('computes due-month fixed day', function () {
    $result = WorkDueDates::compute('fixed_day', 20, null, 'due_month', 5, '2026-09');

    expect($result['due_on'])->toBe('2026-09-20')
        ->and($result['target_date'])->toBe('2026-09-15');
});

it('clamps fixed day to the last day of the target month', function () {
    expect(WorkDueDates::compute('fixed_day', 31, null, 'due_month', null, '2026-04')['due_on'])->toBe('2026-04-30')
        ->and(WorkDueDates::compute('fixed_day', 31, null, 'due_month', null, '2026-02')['due_on'])->toBe('2026-02-28')
        ->and(WorkDueDates::compute('fixed_day', 31, null, 'due_month', null, '2024-02')['due_on'])->toBe('2024-02-29');
});

it('computes estimated due from the first of the competence month', function () {
    $result = WorkDueDates::compute('estimated', null, 7, 'due_month', null, '2026-09');

    expect($result['due_on'])->toBe('2026-09-08')
        ->and($result['target_date'])->toBeNull();
});

it('returns nulls without config and never crashes on unknown mode', function () {
    expect(WorkDueDates::compute(null, 20, null, 'due_month', 5, '2026-09'))->toBe(['due_on' => null, 'target_date' => null])
        ->and(WorkDueDates::compute('fixed_day', null, null, 'due_month', 5, '2026-09'))->toBe(['due_on' => null, 'target_date' => null])
        ->and(WorkDueDates::compute('estimated', null, null, 'due_month', 5, '2026-09'))->toBe(['due_on' => null, 'target_date' => null])
        ->and(WorkDueDates::compute('estimated', null, 0, 'due_month', 5, '2026-09'))->toBe(['due_on' => null, 'target_date' => null])
        ->and(WorkDueDates::compute('desconhecido', 20, null, 'due_month', 5, '2026-09'))->toBe(['due_on' => null, 'target_date' => null])
        ->and(WorkDueDates::compute('fixed_day', 20, null, 'due_month', null, '2026-09')['target_date'])->toBeNull()
        ->and(WorkDueDates::compute('fixed_day', 20, null, 'due_month', 5, 'ops')['due_on'])->toBeNull();
});

it('resolves effective day and offset from the definition with process fallback', function () {
    [$account] = workAccountUser('admin');
    $process = WorkProcess::factory()->create([
        'account_id' => $account->id,
        'due_mode' => 'fixed_day',
        'due_day' => 10,
        'competence_offset' => 'due_month',
        'target_lead_days' => 5,
    ]);
    $override = WorkProcessTaskDefinition::factory()->create([
        'work_process_id' => $process->id,
        'due_day' => 20,
        'competence_offset' => 'previous_month',
    ]);
    $fallback = WorkProcessTaskDefinition::factory()->create([
        'work_process_id' => $process->id,
        'due_day' => null,
        'competence_offset' => null,
    ]);

    expect(WorkDueDates::forTask($process, $override, '2026-09'))->toBe(['due_on' => '2026-10-20', 'target_date' => '2026-10-15'])
        ->and(WorkDueDates::forTask($process, $fallback, '2026-09'))->toBe(['due_on' => '2026-09-10', 'target_date' => '2026-09-05'])
        ->and(WorkDueDates::forTask($process, $fallback, null))->toBe(['due_on' => null, 'target_date' => null]);
});

it('materializes dated rows with due and exposes target in every payload', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create([
        'account_id' => $account->id,
        'due_mode' => 'fixed_day',
        'due_day' => 20,
        'competence_offset' => 'previous_month',
        'target_lead_days' => 5,
    ]);
    WorkProcessTaskDefinition::factory()->create(['work_process_id' => $process->id, 'title' => 'Etapa um', 'position' => 0]);
    WorkProcessTaskDefinition::factory()->create(['work_process_id' => $process->id, 'title' => 'Etapa dois', 'position' => 1]);
    WorkProcess::find($process->id)?->processClients()->create(['client_id' => $client->id]);
    $this->actingAs($admin);

    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Work/Tasks/Index', false)
            ->has('tasks.data', 2)
            ->where('tasks.data.0.target_date', '2026-10-15'));

    $stored = WorkTask::where('work_process_id', $process->id)->orderBy('position')->get();
    expect($stored->pluck('due_on')->map(fn ($date) => $date->format('Y-m-d'))->all())->toBe(['2026-10-20', '2026-10-20']);

    $task = $stored->firstOrFail();
    $this->get(route('work.tasks.show', $task))->assertOk()
        ->assertInertia(fn ($page) => $page->where('task.target_date', '2026-10-15'));

    $this->get(route('work.processos', ['view' => 'tarefas', 'competence' => '2026-09']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('board.todo.0.target_date', '2026-10-15'));

    $this->get(route('work.processos', ['view' => 'processo', 'competence' => '2026-09']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('processes.0.clients.0.tasks.0.target_date', '2026-10-15'));
});

it('keeps timeless rows dateless after open', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create([
        'account_id' => $account->id,
        'due_mode' => 'fixed_day',
        'due_day' => 20,
        'competence_offset' => 'previous_month',
        'target_lead_days' => 5,
    ]);
    WorkProcessTaskDefinition::factory()->create(['work_process_id' => $process->id, 'title' => 'Etapa um', 'position' => 0]);
    WorkProcessTaskDefinition::factory()->create(['work_process_id' => $process->id, 'title' => 'Etapa dois', 'position' => 1]);
    $this->actingAs($admin);

    $this->put(route('work.processes.clients.update', $process), ['client_ids' => [$client->id]])->assertRedirect();

    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk();

    $tasks = WorkTask::where('work_process_id', $process->id)->get();
    expect($tasks)->toHaveCount(2)
        ->and($tasks->pluck('competence')->all())->toBe([null, null])
        ->and($tasks->pluck('due_on')->filter()->all())->toBe([]);
});

it('backfills undated dated-competence rows on the next open', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create([
        'account_id' => $account->id,
        'due_mode' => 'fixed_day',
        'due_day' => 20,
        'competence_offset' => 'previous_month',
        'target_lead_days' => 5,
    ]);
    $definition = WorkProcessTaskDefinition::factory()->create(['work_process_id' => $process->id, 'title' => 'Etapa um', 'position' => 0]);
    WorkProcess::find($process->id)?->processClients()->create(['client_id' => $client->id]);
    WorkTask::factory()->create([
        'account_id' => $account->id,
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'work_process_task_definition_id' => $definition->id,
        'title' => 'Etapa um',
        'competence' => '2026-09',
        'due_on' => null,
    ]);
    $this->actingAs($admin);

    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk();

    expect(WorkTask::where('work_process_id', $process->id)->count())->toBe(1)
        ->and(WorkTask::where('work_process_id', $process->id)->firstOrFail()->due_on?->format('Y-m-d'))->toBe('2026-10-20');
});

it('keeps cascade statuses with dates set', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create([
        'account_id' => $account->id,
        'cascade_execution' => true,
        'due_mode' => 'fixed_day',
        'due_day' => 20,
        'competence_offset' => 'due_month',
        'target_lead_days' => 5,
    ]);
    WorkProcessTaskDefinition::factory()->create(['work_process_id' => $process->id, 'title' => 'Primeira', 'position' => 0]);
    WorkProcessTaskDefinition::factory()->create(['work_process_id' => $process->id, 'title' => 'Segunda', 'position' => 1]);
    WorkProcess::find($process->id)?->processClients()->create(['client_id' => $client->id]);
    $this->actingAs($admin);

    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk();

    $tasks = WorkTask::where('work_process_id', $process->id)->orderBy('position')->get();
    expect($tasks->pluck('status')->all())->toBe(['todo', 'backlog'])
        ->and($tasks->pluck('due_on')->map(fn ($date) => $date->format('Y-m-d'))->all())->toBe(['2026-09-20', '2026-09-20']);
});
