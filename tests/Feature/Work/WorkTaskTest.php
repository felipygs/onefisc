<?php

use App\Models\Account;
use App\Models\AccountDepartment;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkTask;

// Inertia renders resolve without built assets, mirroring WorkProcessTest.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('taskProcessAndClient')) {
    /**
     * @return array{0: WorkProcess, 1: Client}
     */
    function taskProcessAndClient(Account $account): array
    {
        $process = WorkProcess::factory()->create(['account_id' => $account->id]);
        $client = Client::factory()->create(['account_id' => $account->id]);

        return [$process, $client];
    }
}

if (! function_exists('validTaskPayload')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function validTaskPayload(WorkProcess $process, Client $client, array $overrides = []): array
    {
        return array_merge([
            'work_process_id' => $process->id,
            'client_id' => $client->id,
            'title' => 'Conferir guia',
        ], $overrides);
    }
}

it('stores medium priority with null definition when omitted', function () {
    [$account, $admin] = workAccountUser('admin');
    $member = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);
    $department = AccountDepartment::factory()->create(['account_id' => $account->id]);
    [$process, $client] = taskProcessAndClient($account);
    $this->actingAs($admin);

    $this->post(route('work.tasks.store'), validTaskPayload($process, $client, [
        'assigned_user_id' => $member->id,
        'department_id' => $department->id,
        'start_at' => '2026-09-05',
        'due_on' => '2026-09-10',
    ]))->assertRedirect();

    $task = WorkTask::where('account_id', $account->id)->firstOrFail();
    expect($task->priority)->toBe('medium')
        ->and($task->work_process_task_definition_id)->toBeNull()
        ->and($task->competence)->toBeNull()
        ->and($task->assigned_user_id)->toBe($member->id)
        ->and($task->department_id)->toBe($department->id)
        ->and($task->start_at?->format('Y-m-d'))->toBe('2026-09-05')
        ->and($task->due_on?->format('Y-m-d'))->toBe('2026-09-10');

    $this->get(route('work.tasks.index'))->assertOk();
    $this->get(route('work.tasks.show', $task))->assertOk();
});

it('moves status and position together and keeps the row on invalid status', function () {
    [$account, $admin] = workAccountUser('admin');
    [$process, $client] = taskProcessAndClient($account);
    $task = WorkTask::factory()->create([
        'account_id' => $account->id,
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'status' => 'todo',
        'position' => 0,
    ]);
    $this->actingAs($admin);

    $this->patch(route('work.tasks.move', $task), ['status' => 'in_progress', 'position' => 3])
        ->assertRedirect();

    expect($task->fresh()?->status)->toBe('in_progress')
        ->and($task->fresh()?->position)->toBe(3);

    $this->patchJson(route('work.tasks.move', $task), ['status' => 'flying', 'position' => 9])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($task->fresh()?->status)->toBe('in_progress')
        ->and($task->fresh()?->position)->toBe(3);
});

it('returns the existing row on ad-hoc duplicates with trimmed titles', function () {
    [$account, $admin] = workAccountUser('admin');
    [$process, $client] = taskProcessAndClient($account);
    $this->actingAs($admin);

    $this->post(route('work.tasks.store'), validTaskPayload($process, $client, ['title' => '  Conferir guia  ']))
        ->assertRedirect();
    $this->post(route('work.tasks.store'), validTaskPayload($process, $client, ['title' => 'Conferir guia']))
        ->assertRedirect();

    $tasks = WorkTask::where('account_id', $account->id)->get();
    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()?->title)->toBe('Conferir guia');
});

it('matches date ranges per field without mixing bounds', function () {
    [$account, $admin] = workAccountUser('admin');
    [$process, $client] = taskProcessAndClient($account);

    $make = fn (string $title, ?string $start, ?string $due) => WorkTask::factory()->create([
        'account_id' => $account->id,
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'title' => $title,
        'start_at' => $start,
        'due_on' => $due,
    ]);

    $make('Início setembro', '2026-09-10', null);
    $make('Vence setembro', null, '2026-09-20');
    $make('Fora do intervalo', '2030-01-15', '2020-05-01');
    $make('Sem datas', null, null);

    $this->actingAs($admin);

    $this->get(route('work.tasks.index', ['from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Work/Tasks/Index', false)
            ->has('tasks.data', 2)
            ->where('tasks.data.0.title', 'Início setembro')
            ->where('tasks.data.1.title', 'Vence setembro'));

    $this->get(route('work.tasks.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tasks.data', 4));
});

it('shows collaborators only tasks of clients assigned to them', function () {
    [$account, $user] = workAccountUser('user');
    $this->actingAs($user);

    $mine = Client::factory()->create(['account_id' => $account->id]);
    $other = Client::factory()->create(['account_id' => $account->id]);
    assignClientToUser($mine, $user);

    [$process] = taskProcessAndClient($account);
    WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id,
        'client_id' => $mine->id, 'title' => 'Tarefa minha',
    ]);
    WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id,
        'client_id' => $other->id, 'title' => 'Tarefa alheia',
    ]);

    $this->get(route('work.tasks.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Work/Tasks/Index', false)
            ->has('tasks.data', 1)
            ->where('tasks.data.0.title', 'Tarefa minha'));
});

it('forbids collaborators from storing tasks on unassigned clients', function () {
    [$account, $user] = workAccountUser('user');
    $this->actingAs($user);

    [$process, $foreign] = taskProcessAndClient($account);

    $this->post(route('work.tasks.store'), validTaskPayload($process, $foreign))
        ->assertForbidden();

    expect(WorkTask::where('account_id', $account->id)->count())->toBe(0);
});

it('lets collaborators mutate tasks of assigned clients', function () {
    [$account, $user] = workAccountUser('user');
    $this->actingAs($user);

    [$process, $client] = taskProcessAndClient($account);
    assignClientToUser($client, $user);

    $this->post(route('work.tasks.store'), validTaskPayload($process, $client))
        ->assertRedirect();

    $task = WorkTask::where('account_id', $account->id)->firstOrFail();

    $this->get(route('work.tasks.show', $task))->assertOk();
    $this->put(route('work.tasks.update', $task), ['title' => 'Título do colaborador'])
        ->assertRedirect();
    $this->patch(route('work.tasks.move', $task), ['status' => 'done', 'position' => 1])
        ->assertRedirect();

    expect($task->fresh()?->title)->toBe('Título do colaborador')
        ->and($task->fresh()?->status)->toBe('done')
        ->and($task->fresh()?->position)->toBe(1);

    $this->delete(route('work.tasks.destroy', $task))->assertRedirect();
    expect(WorkTask::find($task->id))->toBeNull();
});

it('answers 404 for foreign task ids instead of leaking 403', function () {
    [$account, $user] = workAccountUser('user');
    $this->actingAs($user);

    [$process, $mine] = taskProcessAndClient($account);
    $other = Client::factory()->create(['account_id' => $account->id]);
    assignClientToUser($mine, $user);

    $foreign = WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id,
        'client_id' => $other->id,
    ]);

    $this->get(route('work.tasks.show', $foreign))->assertNotFound();
    $this->patch(route('work.tasks.move', $foreign), ['status' => 'done', 'position' => 0])->assertNotFound();
    $this->delete(route('work.tasks.destroy', $foreign))->assertNotFound();
});

it('answers 404 for tasks of another account', function () {
    [$accountA] = workAccountUser('admin');
    [$accountB, $adminB] = workAccountUser('admin');

    [$process, $client] = taskProcessAndClient($accountA);
    $foreign = WorkTask::factory()->create([
        'account_id' => $accountA->id, 'work_process_id' => $process->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($adminB);

    $this->get(route('work.tasks.show', $foreign))->assertNotFound();
    $this->put(route('work.tasks.update', $foreign), ['title' => 'Tentativa'])->assertNotFound();
    $this->patch(route('work.tasks.move', $foreign), ['status' => 'done', 'position' => 0])->assertNotFound();
    $this->delete(route('work.tasks.destroy', $foreign))->assertNotFound();
});

it('validates missing title and unknown references with 422', function () {
    [$account, $admin] = workAccountUser('admin');
    [$process, $client] = taskProcessAndClient($account);
    $this->actingAs($admin);

    $payload = validTaskPayload($process, $client);
    unset($payload['title']);

    $this->postJson(route('work.tasks.store'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');

    $otherAccount = Account::factory()->create(['profile' => 'B']);
    $foreignClient = Client::factory()->create(['account_id' => $otherAccount->id]);

    $this->postJson(route('work.tasks.store'), validTaskPayload($process, $foreignClient))
        ->assertStatus(422)
        ->assertJsonValidationErrors('client_id');

    expect(WorkTask::where('account_id', $account->id)->count())->toBe(0);
});

it('keeps legacy none priority untouched while new rows default to medium', function () {
    [$account, $admin] = workAccountUser('admin');
    [$process, $client] = taskProcessAndClient($account);
    $legacy = WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id,
        'client_id' => $client->id, 'priority' => 'none',
    ]);
    $this->actingAs($admin);

    $this->get(route('work.tasks.show', $legacy))->assertOk();

    expect($legacy->fresh()?->priority)->toBe('none');
});

it('ignores definition linkage input on store and update', function () {
    [$account, $admin] = workAccountUser('admin');
    [$process, $client] = taskProcessAndClient($account);
    $this->actingAs($admin);

    $this->post(route('work.tasks.store'), validTaskPayload($process, $client, [
        'work_process_task_definition_id' => 999,
        'definition_id' => 999,
    ]))->assertRedirect();

    $task = WorkTask::where('account_id', $account->id)->firstOrFail();
    expect($task->work_process_task_definition_id)->toBeNull();

    $this->put(route('work.tasks.update', $task), [
        'title' => 'Título novo',
        'work_process_task_definition_id' => 999,
        'definition_id' => 999,
    ])->assertRedirect();

    expect($task->fresh()?->title)->toBe('Título novo')
        ->and($task->fresh()?->work_process_task_definition_id)->toBeNull();
});
