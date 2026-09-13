<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use App\Models\WorkTask;
use Illuminate\Support\Facades\DB;

// Inertia renders resolve without built assets, mirroring WorkProcessTest.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('workspaceAccountUser')) {
    /**
     * @return array{0: Account, 1: User}
     */
    function workspaceAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

if (! function_exists('workspacePair')) {
    /**
     * Associated process–Client pair with its link row.
     *
     * @param  array<string, mixed>  $processOverrides
     * @param  array<string, mixed>  $clientOverrides
     * @return array{0: WorkProcess, 1: Client}
     */
    function workspacePair(Account $account, array $processOverrides = [], array $clientOverrides = []): array
    {
        $process = WorkProcess::factory()->create(['account_id' => $account->id, ...$processOverrides]);
        $client = Client::factory()->create(['account_id' => $account->id, ...$clientOverrides]);
        WorkProcessClient::factory()->create([
            'work_process_id' => $process->id,
            'client_id' => $client->id,
        ]);

        return [$process, $client];
    }
}

if (! function_exists('workspaceAssign')) {
    function workspaceAssign(Client $client, User $user): void
    {
        DB::table('client_user')->insert([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('returns progress summary and ordered tasks for the pair', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    $member = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);
    [$process, $client] = workspacePair($account, [
        'title' => 'Fechamento mensal',
        'description' => 'Rotina do escritório.',
        'target_lead_days' => 2,
    ], [
        'razao_social' => 'Empresa Exemplo Ltda',
        'cnpj' => '12345678000190',
    ]);
    $this->actingAs($admin);

    // Created out of position order on purpose: the payload must sort.
    WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id, 'client_id' => $client->id,
        'title' => 'Terceira etapa', 'status' => 'todo', 'position' => 2,
        'priority' => 'low', 'due_on' => '2026-09-20',
    ]);
    WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id, 'client_id' => $client->id,
        'title' => 'Primeira etapa', 'status' => 'done', 'position' => 0,
        'priority' => 'urgent', 'due_on' => '2026-09-01', 'assigned_user_id' => $member->id,
    ]);
    WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id, 'client_id' => $client->id,
        'title' => 'Segunda etapa', 'status' => 'done', 'position' => 1,
        'priority' => 'high', 'due_on' => '2026-09-05',
    ]);
    // Noise from another pair: never leaks into this workspace.
    [$otherProcess, $otherClient] = workspacePair($account);
    WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $otherProcess->id, 'client_id' => $otherClient->id,
        'title' => 'Etapa alheia', 'status' => 'todo', 'position' => 0,
    ]);

    $response = $this->getJson(route('work.processes.clients.show', [$process, $client]))->assertOk();

    $response->assertJson([
        'process' => ['id' => $process->id, 'title' => 'Fechamento mensal', 'description' => 'Rotina do escritório.'],
        'client' => ['id' => $client->id, 'name' => 'Empresa Exemplo Ltda', 'tax_id' => '12345678000190'],
        'progress' => ['done' => 2, 'total' => 3],
        // Earliest OPEN due only: done rows due earlier do not count.
        'summary' => ['next_due' => '2026-09-20', 'highest_open_priority' => 'low', 'open_count' => 1, 'done_count' => 2],
        'can_create_task' => true,
        'documents_available' => false,
    ]);

    $tasks = $response->json('tasks');
    expect($tasks)->toHaveCount(3)
        ->and(array_column($tasks, 'title'))->toBe(['Primeira etapa', 'Segunda etapa', 'Terceira etapa'])
        ->and(array_column($tasks, 'position'))->toBe([0, 1, 2]);

    expect($tasks[0])->toMatchArray([
        'status' => 'done', 'priority' => 'urgent', 'due_on' => '2026-09-01',
        // Derived target: due minus the process lead (3.2), never stored.
        'target_date' => '2026-08-30',
    ])->and($tasks[0]['assignee'])->toMatchArray(['id' => $member->id, 'name' => $member->name]);

    expect($tasks[2])->toMatchArray([
        'status' => 'todo', 'priority' => 'low', 'due_on' => '2026-09-20',
        'target_date' => '2026-09-18',
    ])->and($tasks[2]['assignee'])->toBeNull();
});

it('summarizes an empty pair with nulls instead of zeros-as-dates', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    [$process, $client] = workspacePair($account);
    $this->actingAs($admin);

    $this->getJson(route('work.processes.clients.show', [$process, $client]))
        ->assertOk()
        ->assertJson([
            'progress' => ['done' => 0, 'total' => 0],
            'summary' => ['next_due' => null, 'highest_open_priority' => null, 'open_count' => 0, 'done_count' => 0],
            'tasks' => [],
        ]);
});

it('picks the highest open priority ignoring done rows', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    [$process, $client] = workspacePair($account);
    $this->actingAs($admin);

    WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id, 'client_id' => $client->id,
        'title' => 'Etapa urgente feita', 'status' => 'done', 'position' => 0, 'priority' => 'urgent',
    ]);
    WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id, 'client_id' => $client->id,
        'title' => 'Etapa alta aberta', 'status' => 'in_progress', 'position' => 1, 'priority' => 'high',
        'due_on' => '2026-09-12',
    ]);
    WorkTask::factory()->create([
        'account_id' => $account->id, 'work_process_id' => $process->id, 'client_id' => $client->id,
        'title' => 'Etapa baixa aberta', 'status' => 'todo', 'position' => 2, 'priority' => 'low',
        'due_on' => '2026-09-09',
    ]);

    $this->getJson(route('work.processes.clients.show', [$process, $client]))
        ->assertOk()
        ->assertJson([
            'summary' => ['next_due' => '2026-09-09', 'highest_open_priority' => 'high', 'open_count' => 2, 'done_count' => 1],
        ]);
});

it('lets collaborators see assigned pairs and 404s unassigned pairs', function () {
    [$account, $user] = workspaceAccountUser('user');
    [$process, $assigned] = workspacePair($account);
    $other = Client::factory()->create(['account_id' => $account->id]);
    WorkProcessClient::factory()->create(['work_process_id' => $process->id, 'client_id' => $other->id]);
    workspaceAssign($assigned, $user);
    $this->actingAs($user);

    $this->getJson(route('work.processes.clients.show', [$process, $assigned]))->assertOk();
    $this->getJson(route('work.processes.clients.show', [$process, $other]))->assertNotFound();
});

it('404s foreign process and client ids without leaking', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    [$process, $client] = workspacePair($account);
    $foreignAccount = Account::factory()->create(['profile' => 'B']);
    $foreignProcess = WorkProcess::factory()->create(['account_id' => $foreignAccount->id]);
    $foreignClient = Client::factory()->create(['account_id' => $foreignAccount->id]);
    $this->actingAs($admin);

    $this->getJson(route('work.processes.clients.show', [$foreignProcess, $client]))->assertNotFound();
    $this->getJson(route('work.processes.clients.show', [$process, $foreignClient]))->assertNotFound();
});

it('404s same-account pairs without an association row', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $this->getJson(route('work.processes.clients.show', [$process, $client]))->assertNotFound();
});

it('mirrors the store gate for ad-hoc creation', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    $operador = User::factory()->create(['account_id' => $account->id, 'role' => 'operador']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => 'user']);
    [$process, $client] = workspacePair($account);
    workspaceAssign($client, $user);

    $this->actingAs($admin);
    $this->getJson(route('work.processes.clients.show', [$process, $client]))
        ->assertOk()->assertJson(['can_create_task' => true]);

    $this->actingAs($operador);
    $this->getJson(route('work.processes.clients.show', [$process, $client]))
        ->assertOk()->assertJson(['can_create_task' => true]);

    // Assigned collaborators see the same affordance the store route
    // authorizes for them — and the end-to-end create through the pair
    // succeeds.
    $this->actingAs($user);
    $this->getJson(route('work.processes.clients.show', [$process, $client]))
        ->assertOk()->assertJson(['can_create_task' => true]);

    $this->post(route('work.tasks.store'), [
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'title' => 'Etapa do colaborador',
    ])->assertRedirect();

    expect(WorkTask::where('account_id', $account->id)
        ->where('work_process_id', $process->id)
        ->where('client_id', $client->id)
        ->where('title', 'Etapa do colaborador')
        ->exists())->toBeTrue();
});

it('reports documents unavailable without fiscal presence', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    [$process, $client] = workspacePair($account);
    $this->actingAs($admin);

    expect(DB::table('fiscal_documents')->where('client_id', $client->id)->count())->toBe(0)
        ->and(DB::table('client_credentials')->where('client_id', $client->id)->count())->toBe(0);

    $this->getJson(route('work.processes.clients.show', [$process, $client]))
        ->assertOk()->assertJson(['documents_available' => false]);
});

it('reports documents available with a fiscal document present', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    [$process, $client] = workspacePair($account);
    $this->actingAs($admin);

    // Raw insert: no fiscal domain code runs, the check stays read-only.
    DB::table('fiscal_documents')->insert([
        'client_id' => $client->id,
        'family' => 'nfe',
        'doc_type' => 'nfe',
        'number' => '123',
        'series' => '1',
        'key' => '35123456780001901234550010000001231234567890',
        'derived_from_key' => false,
        'emission_at' => now(),
        'status' => 'authorized',
        'has_xml' => false,
        'has_danfe' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson(route('work.processes.clients.show', [$process, $client]))
        ->assertOk()->assertJson(['documents_available' => true]);
});

it('reports documents available with a filled credential present', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    [$process, $client] = workspacePair($account);
    $this->actingAs($admin);

    DB::table('client_credentials')->insert([
        'client_id' => $client->id,
        'pfx_data' => 'stub-certificate-bytes',
        'thumbprint' => 'smoke-thumbprint',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson(route('work.processes.clients.show', [$process, $client]))
        ->assertOk()->assertJson(['documents_available' => true]);
});

it('treats an empty credential row as documents unavailable', function () {
    [$account, $admin] = workspaceAccountUser('admin');
    [$process, $client] = workspacePair($account);
    $this->actingAs($admin);

    DB::table('client_credentials')->insert([
        'client_id' => $client->id,
        'pfx_data' => null,
        'thumbprint' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson(route('work.processes.clients.show', [$process, $client]))
        ->assertOk()->assertJson(['documents_available' => false]);
});
