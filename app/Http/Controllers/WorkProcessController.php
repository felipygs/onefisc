<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkProcessRequest;
use App\Http\Requests\UpdateWorkProcessClientsRequest;
use App\Http\Requests\UpdateWorkProcessRequest;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use App\Models\WorkProcessTaskDefinition;
use App\Models\WorkTask;
use App\Policies\WorkProcessPolicy;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkProcessController extends Controller
{
    public function index(): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('viewAny', WorkProcess::class);

        $processes = $this->scopedQuery()
            ->with(['definitions' => fn ($query) => $query->orderBy('position')])
            ->orderBy('title')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Work/Processes/Index', [
            'processes' => $processes,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', WorkProcess::class);

        return Inertia::render('Work/Processes/Create');
    }

    public function store(StoreWorkProcessRequest $request): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);

        $validated = $request->validated();
        $definitions = $validated['definitions'] ?? null;
        unset($validated['definitions']);

        // Origin is always manual here; any incoming `source` is ignored and
        // never reaches validated data. Marketplace installs land in Task 2.5.
        DB::transaction(function () use ($account, $validated, $definitions): void {
            $process = WorkProcess::create([
                ...$validated,
                'account_id' => $account->id,
                'source' => 'manual',
            ]);

            if (is_array($definitions)) {
                $this->syncDefinitions($process, $definitions);
            }
        });

        return redirect()->route('work.processes.index')->with('status', 'Processo criado.');
    }

    public function show(int|string $process): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findProcess($process, $account->id);
        Gate::authorize('view', $model);

        return Inertia::render('Work/Processes/Show', [
            'process' => $model->load(['definitions' => fn ($query) => $query->orderBy('position')]),
        ]);
    }

    public function edit(int|string $process): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findProcess($process, $account->id);
        Gate::authorize('update', $model);

        return Inertia::render('Work/Processes/Edit', [
            'process' => $model->load(['definitions' => fn ($query) => $query->orderBy('position')]),
        ]);
    }

    public function update(UpdateWorkProcessRequest $request, int|string $process): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findProcess($process, $account->id);
        $validated = $request->validated();
        $definitions = $validated['definitions'] ?? null;
        unset($validated['definitions']);

        DB::transaction(function () use ($model, $validated, $definitions): void {
            $model->update($validated);

            if (is_array($definitions)) {
                $this->syncDefinitions($model, $definitions);
            }
        });

        return redirect()->route('work.processes.index')->with('status', 'Processo atualizado.');
    }

    public function destroy(int|string $process): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findProcess($process, $account->id);
        Gate::authorize('delete', $model);

        // Hard delete: DB cascades remove definitions, associations and
        // tasks. Soft-retire stays available through the `archived` status.
        $model->delete();

        return redirect()->route('work.processes.index')->with('status', 'Processo removido.');
    }

    /**
     * Apply the association effective set and materialize tasks. An explicit
     * `client_ids` list overrides the rules stored on the process; otherwise
     * the set is computed from regimes + tags + extras − excluded.
     * Competence and dates stay null here — dating belongs to Task 3.1/3.2.
     */
    public function updateClients(UpdateWorkProcessClientsRequest $request, int|string $process): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findProcess($process, $account->id);

        $validated = $request->validated();

        DB::transaction(function () use ($model, $account, $validated): void {
            // Serialize concurrent applies per process: the NULL-competence
            // unique key cannot dedupe undated rows (NULLs compare distinct
            // in SQLite/Postgres/MySQL), so this row lock — not the index —
            // is what keeps racing applies from double-materializing. The
            // index stays as backstop for dated rows (3.1/3.2 put competence
            // in the key for monthly recurrence). Loading definitions after
            // the lock also keeps the materialized snapshot consistent.
            $locked = WorkProcess::query()
                ->where('work_processes.account_id', $account->id)
                ->whereKey($model->id)
                ->lockForUpdate()
                ->firstOrFail();

            $definitions = $locked->definitions()->orderBy('position')->get();

            $effective = array_key_exists('client_ids', $validated) && is_array($validated['client_ids'])
                ? $this->accountClientIds($account->id, $validated['client_ids'])
                : $this->effectiveClientIds($locked, $account->id);

            $previous = $locked->processClients()->pluck('client_id')->map(fn ($id) => (int) $id)->all();
            $fresh = array_values(array_diff($effective, $previous));
            $removed = array_values(array_diff($previous, $effective));

            if ($fresh !== []) {
                $now = now();
                WorkProcessClient::insertOrIgnore(array_map(fn (int $clientId) => [
                    'work_process_id' => $locked->id,
                    'client_id' => $clientId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $fresh));

                $this->materializeTasks($locked, $account->id, $fresh, $definitions);
            }

            foreach ($removed as $clientId) {
                WorkTask::where('work_process_id', $locked->id)
                    ->where('client_id', $clientId)
                    ->where('status', '!=', 'done')
                    ->delete();
                $locked->processClients()->where('client_id', $clientId)->delete();
            }
        });

        return redirect()->back();
    }

    /**
     * Resolve a process through the account-scoped query. Foreign or malformed
     * ids fail with 404 here, before any policy check can leak a 403.
     */
    protected function findProcess(int|string $id, int $accountId): WorkProcess
    {
        return WorkProcess::query()
            ->where('work_processes.account_id', $accountId)
            ->findOrFail($id);
    }

    /**
     * Account-scoped listing: managers see every process, collaborators only
     * processes holding at least one client assigned to them via client_user.
     *
     * @return Builder<WorkProcess>
     */
    protected function scopedQuery(): Builder
    {
        $query = WorkProcess::query();
        $user = request()->user();

        if ($user instanceof User && ! in_array($user->role, WorkProcessPolicy::MANAGING_ROLES, true)) {
            $query->whereExists(function ($exists) use ($user): void {
                $exists->select(DB::raw('1'))
                    ->from('work_process_clients as wpc')
                    ->join('client_user as cu', 'cu.client_id', '=', 'wpc.client_id')
                    ->whereColumn('wpc.work_process_id', 'work_processes.id')
                    ->where('cu.user_id', $user->id);
            });
        }

        return $query;
    }

    /**
     * Replace the checklist by stable identity: update rows carrying an id,
     * insert rows without one, delete rows omitted from the payload.
     * Definition ids never change, so existing WorkTask links survive.
     *
     * @param  array<int, array<string, mixed>>  $definitions
     */
    protected function syncDefinitions(WorkProcess $process, array $definitions): void
    {
        $incomingIds = collect($definitions)
            ->pluck('id')
            ->filter()
            ->all();

        $process->definitions()->whereNotIn('id', $incomingIds)->delete();

        foreach (array_values($definitions) as $index => $item) {
            $payload = [
                ...$item,
                'position' => $item['position'] ?? $index,
            ];
            unset($payload['id']);

            if (! empty($item['id'])) {
                $process->definitions()->whereKey($item['id'])->update($payload);
            } else {
                $process->definitions()->create($payload);
            }
        }
    }

    /**
     * Effective set from the rules stored on the process: regime-and-tag
     * matches ∪ extras − excluded, restricted to the process Account. Empty
     * regimes (or `all`) mean every account Client; an empty tag list means
     * no tag narrowing. Clients have no status column, so every account
     * Client is a candidate.
     *
     * @return list<int>
     */
    protected function effectiveClientIds(WorkProcess $process, int $accountId): array
    {
        $regimes = array_values(array_filter(
            (array) ($process->association_regimes ?? []),
            fn ($regime) => $regime !== '' && $regime !== 'all'
        ));

        $tagIds = array_values(array_filter(
            array_map('intval', (array) ($process->association_tag_ids ?? [])),
            fn (int $tagId) => $tagId > 0
        ));

        $matched = Client::query()
            ->where('clients.account_id', $accountId)
            ->when($regimes !== [], fn ($query) => $query->whereIn('clients.regime', $regimes))
            ->when($tagIds !== [], function ($query) use ($tagIds): void {
                $query->whereExists(function ($exists) use ($tagIds): void {
                    $exists->select(DB::raw('1'))
                        ->from('client_tag')
                        ->whereColumn('client_tag.client_id', 'clients.id')
                        ->whereIn('client_tag.client_tag_id', $tagIds);
                });
            })
            ->pluck('clients.id')->map(fn ($id) => (int) $id)->all();

        $extraIds = array_values(array_filter(
            array_map('intval', (array) ($process->extra_client_ids ?? [])),
            fn (int $id) => $id > 0
        ));

        $extras = $extraIds === []
            ? []
            : Client::query()
                ->where('clients.account_id', $accountId)
                ->whereIn('clients.id', $extraIds)
                ->pluck('clients.id')->map(fn ($id) => (int) $id)->all();

        $excluded = array_values(array_filter(
            array_map('intval', (array) ($process->excluded_client_ids ?? [])),
            fn (int $id) => $id > 0
        ));

        return array_values(array_diff(array_unique([...$matched, ...$extras]), $excluded));
    }

    /**
     * Explicit override, defensively restricted to the process Account (the
     * request already validates same-account membership).
     *
     * @param  array<int, mixed>  $clientIds
     * @return list<int>
     */
    protected function accountClientIds(int $accountId, array $clientIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $clientIds),
            fn (int $id) => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        return array_values(Client::query()
            ->where('clients.account_id', $accountId)
            ->whereIn('clients.id', $ids)
            ->pluck('clients.id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Create one task per current definition for newly associated clients,
     * skipping rows already materialized so re-applies converge. Cascade on
     * holds later-position tasks in `backlog`; cascade off leaves all `todo`.
     *
     * @param  list<int>  $clientIds
     * @param  Collection<int, WorkProcessTaskDefinition>  $definitions
     */
    protected function materializeTasks(WorkProcess $process, int $accountId, array $clientIds, Collection $definitions): void
    {
        if ($clientIds === [] || $definitions->isEmpty()) {
            return;
        }

        $firstPosition = (int) $definitions->min('position');
        $now = now();
        $rows = [];

        // One grouped read for every applying client instead of a pluck per
        // client, so re-apply convergence checks stay a single query.
        $existingByClient = [];
        foreach (WorkTask::where('work_process_id', $process->id)
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('work_process_task_definition_id')
            ->get(['client_id', 'work_process_task_definition_id']) as $existing) {
            $existingByClient[$existing->client_id][] = $existing->work_process_task_definition_id;
        }

        foreach ($clientIds as $clientId) {
            $existing = $existingByClient[$clientId] ?? [];

            foreach ($definitions as $definition) {
                if (in_array($definition->id, $existing, true)) {
                    continue;
                }

                $rows[] = [
                    'account_id' => $accountId,
                    'work_process_id' => $process->id,
                    'client_id' => $clientId,
                    'work_process_task_definition_id' => $definition->id,
                    'title' => $definition->title,
                    'status' => $process->cascade_execution && (int) $definition->position !== $firstPosition ? 'backlog' : 'todo',
                    'position' => $definition->position,
                    'priority' => $definition->priority ?? 'medium',
                    'assigned_user_id' => $definition->default_assigned_user_id,
                    'department_id' => $definition->department_id,
                    'competence' => null,
                    'start_at' => null,
                    'due_on' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            WorkTask::insertOrIgnore($rows);
        }
    }
}
