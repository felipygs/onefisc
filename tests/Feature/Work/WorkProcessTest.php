<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use App\Models\WorkProcessTaskDefinition;
use App\Models\WorkTask;
use Illuminate\Support\Facades\DB;

// Inertia renders resolve without built assets, mirroring ClientTest.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('workAccountUser')) {
    /**
     * @return array{0: Account, 1: User}
     */
    function workAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

if (! function_exists('validProcessPayload')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function validProcessPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Fechamento mensal',
            'description' => 'Rotina de fechamento do escritório.',
            'status' => 'active',
            'definitions' => [
                ['title' => 'Conferir notas', 'position' => 0, 'due_day' => 10, 'priority' => 'high'],
                ['title' => 'Gerar guia', 'position' => 1, 'due_day' => 20],
            ],
        ], $overrides);
    }
}

if (! function_exists('assignClientToUser')) {
    function assignClientToUser(Client $client, User $user): void
    {
        DB::table('client_user')->insert([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('creates a process with checklist asserting order and fields', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $this->post(route('work.processes.store'), validProcessPayload())
        ->assertRedirect(route('work.processes.index'));

    $process = WorkProcess::where('account_id', $account->id)->firstOrFail();
    expect($process->title)->toBe('Fechamento mensal')
        ->and($process->source)->toBe('manual');

    $definitions = $process->definitions()->orderBy('position')->get();
    expect($definitions)->toHaveCount(2)
        ->and($definitions[0]->title)->toBe('Conferir notas')
        ->and($definitions[0]->due_day)->toBe(10)
        ->and($definitions[0]->priority)->toBe('high')
        ->and($definitions[1]->title)->toBe('Gerar guia')
        ->and($definitions[1]->due_day)->toBe(20);

    $this->get(route('work.processes.index'))->assertOk();
    $this->get(route('work.processes.show', $process))->assertOk();
});

it('forces source to manual even when another source is sent', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $this->post(route('work.processes.store'), validProcessPayload(['source' => 'marketplace']))
        ->assertRedirect(route('work.processes.index'));

    $process = WorkProcess::where('account_id', $account->id)->firstOrFail();
    expect($process->source)->toBe('manual');
});

it('replaces the checklist keeping definition ids and deleting omitted ones', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $first = WorkProcessTaskDefinition::factory()->create([
        'work_process_id' => $process->id, 'title' => 'Item A', 'position' => 0,
    ]);
    $second = WorkProcessTaskDefinition::factory()->create([
        'work_process_id' => $process->id, 'title' => 'Item B', 'position' => 1,
    ]);

    $this->put(route('work.processes.update', $process), [
        'title' => 'Fechamento atualizado',
        'definitions' => [
            ['id' => $first->id, 'title' => 'Item A editado', 'position' => 0],
            ['title' => 'Item C novo', 'position' => 1],
        ],
    ])->assertRedirect(route('work.processes.index'));

    expect($process->fresh()?->title)->toBe('Fechamento atualizado');
    expect($first->fresh()?->title)->toBe('Item A editado');
    expect(WorkProcessTaskDefinition::find($second->id))->toBeNull();

    $definitions = $process->definitions()->orderBy('position')->get();
    expect($definitions)->toHaveCount(2)
        ->and($definitions[0]->id)->toBe($first->id)
        ->and($definitions[1]->title)->toBe('Item C novo');
});

it('keeps task linkage when only editing a definition title', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $definition = WorkProcessTaskDefinition::factory()->create([
        'work_process_id' => $process->id, 'title' => 'Título original', 'position' => 0,
    ]);
    $client = Client::factory()->create(['account_id' => $account->id]);
    $task = WorkTask::factory()->create([
        'account_id' => $account->id,
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'work_process_task_definition_id' => $definition->id,
    ]);

    $this->put(route('work.processes.update', $process), [
        'title' => $process->title,
        'definitions' => [
            ['id' => $definition->id, 'title' => 'Título alterado', 'position' => 0],
        ],
    ])->assertRedirect(route('work.processes.index'));

    expect($definition->fresh()?->title)->toBe('Título alterado')
        ->and($task->fresh()?->work_process_task_definition_id)->toBe($definition->id);
});

it('denies collaborators from storing updating and destroying processes', function () {
    [$account, $user] = workAccountUser('user');
    $this->actingAs($user);

    $process = WorkProcess::factory()->create(['account_id' => $account->id]);

    $this->post(route('work.processes.store'), validProcessPayload())->assertForbidden();
    $this->put(route('work.processes.update', $process), validProcessPayload())->assertForbidden();
    $this->delete(route('work.processes.destroy', $process))->assertForbidden();
});

it('lets operadores manage processes', function () {
    [$account, $operator] = workAccountUser('operador');
    $this->actingAs($operator);

    $this->post(route('work.processes.store'), validProcessPayload())
        ->assertRedirect(route('work.processes.index'));

    $process = WorkProcess::where('account_id', $account->id)->firstOrFail();

    $this->put(route('work.processes.update', $process), ['title' => 'Ajuste do operador'])
        ->assertRedirect(route('work.processes.index'));

    $this->delete(route('work.processes.destroy', $process))
        ->assertRedirect(route('work.processes.index'));

    expect(WorkProcess::find($process->id))->toBeNull();
});

it('returns 404 instead of 403 for foreign account ids', function () {
    [$accountA] = workAccountUser('admin');
    [$accountB, $adminB] = workAccountUser('admin');

    $foreign = WorkProcess::factory()->create(['account_id' => $accountA->id]);

    $this->actingAs($adminB);

    $this->get(route('work.processes.show', $foreign))->assertNotFound();
    $this->get(route('work.processes.edit', $foreign))->assertNotFound();
    $this->put(route('work.processes.update', $foreign), validProcessPayload())->assertNotFound();
    $this->delete(route('work.processes.destroy', $foreign))->assertNotFound();
});

it('validates missing title and bad checklist due day', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $payload = validProcessPayload();
    unset($payload['title']);

    $this->post(route('work.processes.store'), $payload)
        ->assertSessionHasErrors('title');

    $this->post(route('work.processes.store'), validProcessPayload([
        'definitions' => [
            ['title' => 'Item inválido', 'position' => 0, 'due_day' => 99],
        ],
    ]))->assertSessionHasErrors('definitions.0.due_day');

    expect(WorkProcess::where('account_id', $account->id)->count())->toBe(0);
});

it('shows collaborators only processes with clients assigned to them', function () {
    [$account, $user] = workAccountUser('user');
    $this->actingAs($user);

    $mine = Client::factory()->create(['account_id' => $account->id]);
    $other = Client::factory()->create(['account_id' => $account->id]);
    assignClientToUser($mine, $user);

    $assigned = WorkProcess::factory()->create(['account_id' => $account->id, 'title' => 'Processo atribuído']);
    $unassigned = WorkProcess::factory()->create(['account_id' => $account->id, 'title' => 'Processo alheio']);
    WorkProcessClient::create(['work_process_id' => $assigned->id, 'client_id' => $mine->id]);
    WorkProcessClient::create(['work_process_id' => $unassigned->id, 'client_id' => $other->id]);

    $this->get(route('work.processes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Work/Processes/Index', false)
            ->has('processes.data', 1)
            ->where('processes.data.0.title', 'Processo atribuído'));
});

it('lets collaborators view assigned processes but not unassigned ones', function () {
    [$account, $user] = workAccountUser('user');
    $this->actingAs($user);

    $mine = Client::factory()->create(['account_id' => $account->id]);
    $other = Client::factory()->create(['account_id' => $account->id]);
    assignClientToUser($mine, $user);

    $assigned = WorkProcess::factory()->create(['account_id' => $account->id]);
    $unassigned = WorkProcess::factory()->create(['account_id' => $account->id]);
    WorkProcessClient::create(['work_process_id' => $assigned->id, 'client_id' => $mine->id]);
    WorkProcessClient::create(['work_process_id' => $unassigned->id, 'client_id' => $other->id]);

    $this->get(route('work.processes.show', $assigned))->assertOk();
    $this->get(route('work.processes.show', $unassigned))->assertForbidden();
});
