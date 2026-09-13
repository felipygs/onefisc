<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkMarketplaceProcess;
use App\Models\WorkMarketplaceTaskDefinition;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use App\Models\WorkTask;

// Task 5.1: audit trail for Work mutations (process, checklist, association,
// task, marketplace install). Explicit `work.*` domain events via AuditService
// — the same mechanism as `plan.switch` / `certificates.store` — one row per
// action. Factories only.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('auditWorkAccount')) {
    /**
     * @return array{0: Account, 1: User}
     */
    function auditWorkAccount(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

if (! function_exists('auditWorkPayload')) {
    /**
     * @return array<string, mixed>
     */
    function auditWorkPayload(): array
    {
        return [
            'title' => 'Fechamento mensal',
            'description' => 'Rotina de fechamento do escritório.',
            'status' => 'active',
            'definitions' => [
                ['title' => 'Conferir notas', 'position' => 0, 'due_day' => 10, 'priority' => 'high'],
                ['title' => 'Gerar guia', 'position' => 1, 'due_day' => 20],
            ],
        ];
    }
}

if (! function_exists('auditWorkListing')) {
    function auditWorkListing(): WorkMarketplaceProcess
    {
        $listing = WorkMarketplaceProcess::factory()->create([
            'slug' => 'pgdas-mensal',
            'title' => 'PGDAS Mensal',
        ]);
        WorkMarketplaceTaskDefinition::factory()->create([
            'marketplace_process_id' => $listing->id,
            'title' => 'Apurar débitos',
            'position' => 0,
        ]);

        return $listing;
    }
}

if (! function_exists('auditWorkActions')) {
    /**
     * @return list<string>
     */
    function auditWorkActions(): array
    {
        return AuditLog::query()
            ->where('action', 'like', 'work.%')
            ->orderBy('id')
            ->pluck('action')
            ->all();
    }
}

it('records the full lifecycle trail with actor and account linkage', function () {
    [$account, $admin] = auditWorkAccount('admin');
    $this->actingAs($admin);

    // 1. Create process + checklist.
    $this->post(route('work.processes.store'), auditWorkPayload())
        ->assertRedirect(route('work.processes.index'));

    $process = WorkProcess::where('account_id', $account->id)->firstOrFail();

    expect(AuditLog::where('action', 'work.process.created')->count())->toBe(1);

    // 2. Apply association with an explicit client list.
    $client = Client::factory()->create(['account_id' => $account->id]);

    $this->put(route('work.processes.clients.update', $process), ['client_ids' => [$client->id]])
        ->assertRedirect();

    expect(AuditLog::where('action', 'work.process.association_applied')->count())->toBe(1);

    $task = WorkTask::where('work_process_id', $process->id)
        ->where('client_id', $client->id)
        ->orderBy('position')
        ->firstOrFail();

    // 3. Move a materialized task.
    $this->patch(route('work.tasks.move', $task), ['status' => 'in_progress', 'position' => 2])
        ->assertRedirect();

    expect(AuditLog::where('action', 'work.task.moved')->count())->toBe(1);

    // 4. Install a marketplace listing.
    $listing = auditWorkListing();

    $this->post(route('work.marketplace.install', $listing))->assertStatus(303);

    expect(AuditLog::where('action', 'work.marketplace.installed')->count())->toBe(1);

    // Event sequence follows the lifecycle order.
    expect(auditWorkActions())->toBe([
        'work.process.created',
        'work.process.association_applied',
        'work.task.moved',
        'work.marketplace.installed',
    ]);

    // Every row carries actor + account linkage.
    $logs = AuditLog::query()->where('action', 'like', 'work.%')->get();
    expect($logs)->toHaveCount(4);

    foreach ($logs as $log) {
        expect($log->actor_user_id)->toBe($admin->id)
            ->and($log->origin_account_id)->toBe($account->id)
            ->and($log->target_account_id)->toBe($account->id)
            ->and($log->created_at)->not->toBeNull();
    }

    // Payloads carry identifiers + safe labels.
    $created = AuditLog::where('action', 'work.process.created')->firstOrFail();
    expect($created->metadata)->toMatchArray([
        'process_id' => $process->id,
        'title' => 'Fechamento mensal',
        'definitions_count' => 2,
    ]);

    $applied = AuditLog::where('action', 'work.process.association_applied')->firstOrFail();
    expect($applied->metadata)->toMatchArray([
        'process_id' => $process->id,
        'added_client_ids' => [$client->id],
        'removed_client_ids' => [],
        'effective_count' => 1,
    ]);

    $moved = AuditLog::where('action', 'work.task.moved')->firstOrFail();
    expect($moved->metadata)->toMatchArray([
        'task_id' => $task->id,
        'process_id' => $process->id,
        'client_id' => $client->id,
        'from_status' => 'todo',
        'to_status' => 'in_progress',
    ]);

    $installed = AuditLog::where('action', 'work.marketplace.installed')->firstOrFail();
    expect($installed->metadata['listing_slug'] ?? null)->toBe('pgdas-mensal')
        ->and($installed->metadata['process_id'] ?? null)
        ->toBe(WorkProcess::where('account_id', $account->id)
            ->where('marketplace_process_id', (string) $listing->id)
            ->firstOrFail()->id);
});

it('audits exactly one row per process and task mutation', function () {
    [$account, $admin] = auditWorkAccount('admin');
    $this->actingAs($admin);

    $this->post(route('work.processes.store'), auditWorkPayload())->assertRedirect();
    $process = WorkProcess::where('account_id', $account->id)->firstOrFail();

    // Title-only update: checklist untouched.
    $this->put(route('work.processes.update', $process), ['title' => 'Título novo'])
        ->assertRedirect();

    $updated = AuditLog::where('action', 'work.process.updated')->firstOrFail();
    expect(AuditLog::where('action', 'work.process.updated')->count())->toBe(1)
        ->and($updated->metadata)->toMatchArray([
            'process_id' => $process->id,
            'checklist_replaced' => false,
        ]);

    // Checklist replace rides the same updated event with the new count.
    $this->put(route('work.processes.update', $process), [
        'title' => 'Título novo',
        'definitions' => [
            ['title' => 'Só esta', 'position' => 0],
        ],
    ])->assertRedirect();

    expect(AuditLog::where('action', 'work.process.updated')->count())->toBe(2);

    $replaced = AuditLog::where('action', 'work.process.updated')->orderByDesc('id')->firstOrFail();
    expect($replaced->metadata)->toMatchArray([
        'process_id' => $process->id,
        'checklist_replaced' => true,
        'definitions_count' => 1,
    ]);

    // Ad-hoc task create + dedupe hit (second call converges, audits nothing).
    $client = Client::factory()->create(['account_id' => $account->id]);

    $this->post(route('work.tasks.store'), [
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'title' => 'Conferir guia',
    ])->assertRedirect();

    expect(AuditLog::where('action', 'work.task.created')->count())->toBe(1);

    $this->post(route('work.tasks.store'), [
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'title' => 'Conferir guia',
    ])->assertRedirect();

    expect(AuditLog::where('action', 'work.task.created')->count())->toBe(1);

    $task = WorkTask::where('account_id', $account->id)->firstOrFail();

    // Task update, move and delete: one row each.
    $this->put(route('work.tasks.update', $task), ['title' => 'Guia conferida'])
        ->assertRedirect();

    expect(AuditLog::where('action', 'work.task.updated')->count())->toBe(1);

    $this->patch(route('work.tasks.move', $task), ['status' => 'done', 'position' => 1])
        ->assertRedirect();

    expect(AuditLog::where('action', 'work.task.moved')->count())->toBe(1);

    $this->delete(route('work.tasks.destroy', $task))->assertRedirect();

    expect(AuditLog::where('action', 'work.task.deleted')->count())->toBe(1);

    // Process delete closes the trail.
    $this->delete(route('work.processes.destroy', $process))->assertRedirect();

    expect(AuditLog::where('action', 'work.process.deleted')->count())->toBe(1)
        ->and(auditWorkActions())->toBe([
            'work.process.created',
            'work.process.updated',
            'work.process.updated',
            'work.task.created',
            'work.task.updated',
            'work.task.moved',
            'work.task.deleted',
            'work.process.deleted',
        ]);
});

it('emits nothing on reads', function () {
    [$account, $admin] = auditWorkAccount('admin');
    $this->actingAs($admin);

    $this->post(route('work.processes.store'), auditWorkPayload())->assertRedirect();
    $process = WorkProcess::where('account_id', $account->id)->firstOrFail();

    $before = AuditLog::where('action', 'like', 'work.%')->count();
    expect($before)->toBe(1);

    $this->get(route('work.processes.index'))->assertOk();
    $this->get(route('work.processes.show', $process))->assertOk();
    $this->get(route('work.tasks.index'))->assertOk();
    $this->get(route('work.marketplace.index'))->assertOk();
    $this->get(route('work.processes.association-preview', $process))->assertOk();
    $this->get(route('work.catalog.index'))->assertOk();
    $this->get(route('work.catalog.show', $process))->assertOk();
    $this->get(route('work.overview'))->assertOk();

    expect(AuditLog::where('action', 'like', 'work.%')->count())->toBe($before);

    // Side-effecting reads: opening a competence materializes the missing
    // checklist instances through the single shared WorkCompetence
    // materializer (task list, process tree/board, month calendar) — the
    // writes happen, the trail stays silent. Direct pivot insert (no HTTP
    // apply) leaves no timeless rows, so every open below provably writes.
    $client = Client::factory()->create(['account_id' => $account->id]);
    WorkProcessClient::create(['work_process_id' => $process->id, 'client_id' => $client->id]);

    // Task-list open writes the dated rows for 2026-10 …
    $this->get(route('work.tasks.index', ['competence' => '2026-10']))->assertOk();

    expect(WorkTask::where('work_process_id', $process->id)->where('competence', '2026-10')->count())->toBe(2)
        ->and(AuditLog::where('action', 'like', 'work.%')->count())->toBe($before);

    // … process-tree open writes 2026-11, board open writes 2026-12.
    $this->get(route('work.processos', ['view' => 'processo', 'competence' => '2026-11']))->assertOk();

    expect(WorkTask::where('work_process_id', $process->id)->where('competence', '2026-11')->count())->toBe(2)
        ->and(AuditLog::where('action', 'like', 'work.%')->count())->toBe($before);

    $this->get(route('work.processos', ['view' => 'tarefas', 'competence' => '2026-12']))->assertOk();

    expect(WorkTask::where('work_process_id', $process->id)->where('competence', '2026-12')->count())->toBe(2)
        ->and(AuditLog::where('action', 'like', 'work.%')->count())->toBe($before);
});

it('keeps the work trail invisible across accounts', function () {
    [$accountA, $adminA] = auditWorkAccount('admin');
    $this->actingAs($adminA);

    $this->post(route('work.processes.store'), auditWorkPayload())->assertRedirect();

    expect(AuditLog::where('action', 'like', 'work.%')->count())->toBe(1);

    [$accountB, $adminB] = auditWorkAccount('admin');
    $this->actingAs($adminB);

    // Same audit index scope as every other event: filtering the trail by
    // `work.` shows B none of A's rows (B only ever sees rows whose origin
    // or target is B, e.g. its own account/user creation).
    $this->get(route('audit.index', ['action' => 'work.']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Audit/Index', false)
            ->has('logs.data', 0));

    // The rows exist — invisibility is scoping, not absence.
    expect(AuditLog::where('action', 'like', 'work.%')->count())->toBe(1);

    expect($accountB->id)->not->toBe($accountA->id);
});

it('stores only identifiers and safe labels without secrets', function () {
    [$account, $admin] = auditWorkAccount('admin');
    $this->actingAs($admin);

    $this->post(route('work.processes.store'), auditWorkPayload())->assertRedirect();
    $process = WorkProcess::where('account_id', $account->id)->firstOrFail();
    $client = Client::factory()->create(['account_id' => $account->id]);

    $this->put(route('work.processes.clients.update', $process), ['client_ids' => [$client->id]])
        ->assertRedirect();

    $task = WorkTask::where('work_process_id', $process->id)->firstOrFail();

    $this->patch(route('work.tasks.move', $task), ['status' => 'done', 'position' => 0])
        ->assertRedirect();

    $this->post(route('work.marketplace.install', auditWorkListing()))->assertStatus(303);

    $logs = AuditLog::query()->where('action', 'like', 'work.%')->get();
    expect($logs->count())->toBeGreaterThan(0);

    $forbidden = ['password', 'secret', 'token', 'pfx', 'credential'];

    foreach ($logs as $log) {
        expect($log->metadata)->toBeArray();

        foreach (array_keys($log->metadata) as $key) {
            foreach ($forbidden as $word) {
                expect(strtolower((string) $key))->not->toContain($word);
            }
        }

        $json = strtolower((string) json_encode($log->metadata));

        foreach ($forbidden as $word) {
            expect($json)->not->toContain($word);
        }
    }
});
