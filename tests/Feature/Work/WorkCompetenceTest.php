<?php

use App\Models\Account;
use App\Models\AccountDepartment;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use App\Models\WorkProcessTaskDefinition;
use App\Models\WorkTask;
use Illuminate\Database\QueryException;

// Inertia renders resolve without built assets, mirroring WorkTaskTest.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('competenceProcess')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function competenceProcess(Account $account, array $overrides = []): WorkProcess
    {
        $process = WorkProcess::factory()->create(array_merge(['account_id' => $account->id], $overrides));
        WorkProcessTaskDefinition::factory()->create([
            'work_process_id' => $process->id, 'title' => 'Etapa um', 'position' => 0, 'priority' => 'high',
        ]);
        WorkProcessTaskDefinition::factory()->create([
            'work_process_id' => $process->id, 'title' => 'Etapa dois', 'position' => 1,
        ]);

        return $process;
    }
}

if (! function_exists('competenceAssociate')) {
    function competenceAssociate(WorkProcess $process, Client $client): void
    {
        WorkProcessClient::factory()->create([
            'work_process_id' => $process->id,
            'client_id' => $client->id,
        ]);
    }
}

it('materializes missing instances on competence open and converges on double open', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = competenceProcess($account);
    competenceAssociate($process, $client);
    $this->actingAs($admin);

    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk();
    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk();

    $tasks = WorkTask::where('work_process_id', $process->id)
        ->where('client_id', $client->id)
        ->orderBy('position')->get();

    expect($tasks)->toHaveCount(2)
        ->and($tasks->pluck('competence')->all())->toBe(['2026-09', '2026-09'])
        ->and($tasks[0]->title)->toBe('Etapa um')
        ->and($tasks[0]->priority)->toBe('high')
        ->and($tasks[0]->start_at)->toBeNull()
        ->and($tasks[0]->due_on)->toBeNull();
});

it('keeps timeless rows visible under every competence and blocks dated duplicates', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = competenceProcess($account);
    $this->actingAs($admin);

    $this->put(route('work.processes.clients.update', $process), ['client_ids' => [$client->id]])->assertRedirect();
    expect(WorkTask::where('work_process_id', $process->id)->whereNull('competence')->count())->toBe(2);

    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk();
    $this->get(route('work.tasks.index', ['competence' => '2026-10']))->assertOk();

    expect(WorkTask::where('work_process_id', $process->id)->count())->toBe(2);

    foreach (['2026-09', '2026-10'] as $competence) {
        $this->get(route('work.tasks.index', ['competence' => $competence]))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Work/Tasks/Index', false)
                ->has('tasks.data', 2));
    }

    $this->get(route('work.tasks.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('tasks.data', 2));
});

it('filters stored competences exactly while timeless rows follow the due window', function () {
    [$account, $admin] = workAccountUser('admin');
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $client = Client::factory()->create(['account_id' => $account->id]);

    $make = fn (string $title, ?string $competence, ?string $due) => WorkTask::factory()->create([
        'account_id' => $account->id,
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'title' => $title,
        'competence' => $competence,
        'due_on' => $due,
    ]);

    $make('Setembro guardado', '2026-09', null);
    $make('Outubro guardado', '2026-10', null);
    $make('Atemporal sem data', null, null);
    $make('Atemporal em setembro', null, '2026-09-15');
    $make('Atemporal em outubro', null, '2026-10-05');

    $this->actingAs($admin);

    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Work/Tasks/Index', false)
            ->has('tasks.data', 3)
            ->where('tasks.data.0.title', 'Setembro guardado')
            ->where('tasks.data.1.title', 'Atemporal sem data')
            ->where('tasks.data.2.title', 'Atemporal em setembro'));

    $this->get(route('work.tasks.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('tasks.data', 5));
});

it('matches every 2026 competence by stored value', function () {
    [$account, $admin] = workAccountUser('admin');
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $client = Client::factory()->create(['account_id' => $account->id]);

    foreach (range(1, 12) as $month) {
        $competence = sprintf('2026-%02d', $month);
        WorkTask::factory()->create([
            'account_id' => $account->id,
            'work_process_id' => $process->id,
            'client_id' => $client->id,
            'title' => "Tarefa {$competence}",
            'competence' => $competence,
        ]);
    }

    $this->actingAs($admin);

    foreach (range(1, 12) as $month) {
        $competence = sprintf('2026-%02d', $month);
        $this->get(route('work.tasks.index', ['competence' => $competence]))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', "Tarefa {$competence}"));
    }
});

it('rejects malformed competence with 422', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $this->getJson(route('work.tasks.index', ['competence' => 'foo']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('competence');

    $this->getJson(route('work.processos', ['view' => 'tarefas', 'competence' => '2026-13']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('competence');
});

it('scopes board and process tree tasks to the requested competence', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = competenceProcess($account);
    competenceAssociate($process, $client);

    $definition = $process->definitions()->orderBy('position')->firstOrFail();
    WorkTask::factory()->create([
        'account_id' => $account->id,
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'work_process_task_definition_id' => $definition->id,
        'title' => 'Agosto guardado',
        'status' => 'todo',
        'position' => 0,
        'competence' => '2026-08',
    ]);

    $this->actingAs($admin);

    $this->get(route('work.processos', ['view' => 'tarefas', 'competence' => '2026-09']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('work/Processos', false)
            ->where('competence', '2026-09')
            ->has('board.todo', 2)
            ->where('board.todo.0.title', 'Etapa um')
            ->where('board.todo.1.title', 'Etapa dois'));

    $this->get(route('work.processos', ['view' => 'processo', 'competence' => '2026-09']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('work/Processos', false)
            ->has('processes', 1)
            ->has('processes.0.clients', 1)
            ->has('processes.0.clients.0.tasks', 2));

    expect(WorkTask::where('work_process_id', $process->id)->where('competence', '2026-09')->count())->toBe(2);
});

it('converges concurrent opens through the materialization unique index', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = competenceProcess($account);
    competenceAssociate($process, $client);
    $this->actingAs($admin);

    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk();

    $definition = $process->definitions()->orderBy('position')->firstOrFail();

    expect(fn () => WorkTask::create([
        'account_id' => $account->id,
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'work_process_task_definition_id' => $definition->id,
        'title' => 'Duplicada',
        'status' => 'todo',
        'position' => 0,
        'priority' => 'medium',
        'competence' => '2026-09',
    ]))->toThrow(QueryException::class);

    expect(WorkTask::where('work_process_id', $process->id)->where('client_id', $client->id)->count())->toBe(2);
});

it('materializes cascade statuses with definition defaults and null dates', function () {
    [$account, $admin] = workAccountUser('admin');
    $member = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);
    $department = AccountDepartment::factory()->create(['account_id' => $account->id]);
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id, 'cascade_execution' => true]);
    WorkProcessTaskDefinition::factory()->create([
        'work_process_id' => $process->id, 'title' => 'Primeira', 'position' => 0,
        'priority' => 'high', 'default_assigned_user_id' => $member->id, 'department_id' => $department->id,
    ]);
    WorkProcessTaskDefinition::factory()->create([
        'work_process_id' => $process->id, 'title' => 'Segunda', 'position' => 1,
    ]);
    competenceAssociate($process, $client);
    $this->actingAs($admin);

    $this->get(route('work.tasks.index', ['competence' => '2026-09']))->assertOk();

    $tasks = WorkTask::where('work_process_id', $process->id)
        ->where('client_id', $client->id)
        ->orderBy('position')->get();

    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]->status)->toBe('todo')
        ->and($tasks[1]->status)->toBe('backlog')
        ->and($tasks[0]->priority)->toBe('high')
        ->and($tasks[0]->assigned_user_id)->toBe($member->id)
        ->and($tasks[0]->department_id)->toBe($department->id)
        ->and($tasks[0]->competence)->toBe('2026-09')
        ->and($tasks[0]->start_at)->toBeNull()
        ->and($tasks[0]->due_on)->toBeNull();
});
