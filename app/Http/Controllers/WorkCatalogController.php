<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientTag;
use App\Models\WorkProcess;
use App\Support\CurrentAccount;
use App\Support\WorkAssociation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkCatalogController extends Controller
{
    /**
     * Catalog page (Task 4.1): `Na conta` lists the Account processes with
     * association counts + ids; `Prontos` reads the marketplace browse
     * endpoint client-side when 4.2 ships it (no marketplace backend here,
     * so the tab renders its honest empty state until then).
     *
     * Management surface: `admin`/`operador` only (the `create` gate is
     * isMember + manages), so collaborators get 403 here.
     */
    public function index(Request $request): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('create', WorkProcess::class);

        $search = trim((string) $request->query('search', ''));

        $processes = WorkProcess::query()
            ->where('work_processes.account_id', $account->id)
            ->when($search !== '', fn ($query) => $query->where('work_processes.title', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->withCount([
                'processClients as clients_count',
                'definitions as task_count',
            ])
            ->with('processClients:work_process_id,client_id')
            ->orderBy('work_processes.title')
            ->get();

        return Inertia::render('work/Catalog', [
            'search' => $search,
            'processes' => $processes->map(fn (WorkProcess $process): array => [
                'id' => $process->id,
                'title' => $process->title,
                'description' => $process->description,
                'status' => $process->status,
                'source' => $process->source,
                'clients_count' => (int) ($process->getAttribute('clients_count') ?? 0),
                'task_count' => (int) ($process->getAttribute('task_count') ?? 0),
                'client_ids' => $process->processClients
                    ->pluck('client_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            ])->all(),
        ]);
    }

    /**
     * Five-tab editor for one Account process. Same pattern as 2.1: scoped
     * findOrFail + null-account guard, `admin`/`operador` only through the
     * `update` gate (`user` ⇒ 403, foreign ⇒ 404).
     */
    public function show(int|string $process): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = WorkProcess::query()
            ->where('work_processes.account_id', $account->id)
            ->findOrFail($process);
        Gate::authorize('update', $model);

        $model->load(['definitions' => fn ($query) => $query->orderBy('position')]);

        $clients = Client::query()
            ->where('clients.account_id', $account->id)
            ->orderBy('clients.razao_social')
            ->get(['clients.id', 'clients.razao_social', 'clients.regime']);

        $tags = ClientTag::query()
            ->where('client_tags.account_id', $account->id)
            ->orderBy('client_tags.name')
            ->get(['client_tags.id', 'client_tags.name']);

        $preview = WorkAssociation::preview($model, $account->id);

        return Inertia::render('work/CatalogEditor', [
            'process' => [
                'id' => $model->id,
                'title' => $model->title,
                'description' => $model->description,
                'status' => $model->status,
                'source' => $model->source,
                'association_regimes' => array_values((array) ($model->association_regimes ?? [])),
                'association_tag_ids' => array_values(array_map('intval', (array) ($model->association_tag_ids ?? []))),
                'extra_client_ids' => array_values(array_map('intval', (array) ($model->extra_client_ids ?? []))),
                'excluded_client_ids' => array_values(array_map('intval', (array) ($model->excluded_client_ids ?? []))),
                'due_mode' => $model->due_mode,
                'due_day' => $model->due_day,
                'estimated_duration_days' => $model->estimated_duration_days,
                'competence_offset' => $model->competence_offset,
                'target_lead_days' => $model->target_lead_days,
                'recurrence_interval' => $model->recurrence_interval,
                'recurrence_unit' => $model->recurrence_unit,
                'cascade_execution' => (bool) $model->cascade_execution,
                'clients_count' => $model->processClients()->count(),
                'definitions' => $model->definitions->map(fn ($definition): array => [
                    'id' => $definition->id,
                    'title' => $definition->title,
                    'position' => $definition->position,
                    'description' => $definition->description,
                    'due_day' => $definition->due_day,
                    'competence_offset' => $definition->competence_offset,
                    'priority' => $definition->priority,
                    'default_assigned_user_id' => $definition->default_assigned_user_id,
                    'department_id' => $definition->department_id,
                    'requires_document' => (bool) $definition->requires_document,
                ])->all(),
            ],
            'clients' => $clients->map(fn (Client $client): array => [
                'id' => $client->id,
                'razao_social' => $client->razao_social,
                'regime' => $client->regime,
            ])->all(),
            'tags' => $tags->map(fn (ClientTag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->all(),
            'regimeOptions' => $clients->pluck('regime')->filter()->unique()->sort()->values()->all(),
            'preview' => [
                'client_ids' => $preview['client_ids'],
                'count' => count($preview['client_ids']),
                'by_source' => [
                    'rule' => $preview['rule'],
                    'extras' => $preview['extras'],
                ],
            ],
        ]);
    }
}
