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
use App\Policies\WorkTaskPolicy;
use App\Support\CurrentAccount;
use App\Support\WorkAssociation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
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
            // Catalog (4.1) reads per-process association counts + ids from
            // this payload: both ride the same listing query (count subselect
            // + one eager load), never one query per row.
            ->withCount('processClients as clients_count')
            ->with('processClients:work_process_id,client_id')
            ->orderBy('title')
            ->paginate(15)
            ->withQueryString();

        foreach ($processes as $process) {
            $process->setAttribute('client_ids', $process->processClients
                ->pluck('client_id')->map(fn ($id) => (int) $id)->sort()->values()->all());
            $process->makeHidden('processClients');
        }

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
     * New rows go through the shared WorkTask::materializedRow builder with
     * null competence (timeless ⇒ dateless, by decision).
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
     * Dry-run of the 2.2 formula for the catalog editor: the computed
     * effective set split by source, WITHOUT writing. Read-only for
     * `admin`/`operador` (same `update` gate as the apply path); `user`
     * gets 403, foreign ids 404 through the scoped lookup.
     *
     * Optional query overrides (`regimes`, `tag_ids`, `extra_ids`,
     * `excluded_ids` as comma-joined strings, always sent even when empty)
     * compute the same formula over the given rules instead of the stored
     * ones, so the editor previews its draft before applying. Presence
     * (`exists`, not `has`) distinguishes "empty draft" from "no override".
     * Overrides never persist.
     */
    public function associationPreview(Request $request, int|string $process): JsonResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findProcess($process, $account->id);
        Gate::authorize('update', $model);

        $request->validate([
            'regimes' => ['sometimes', 'nullable', 'string'],
            'tag_ids' => ['sometimes', 'nullable', 'string'],
            'extra_ids' => ['sometimes', 'nullable', 'string'],
            'excluded_ids' => ['sometimes', 'nullable', 'string'],
        ]);

        $draft = $model;

        $overrides = [];

        if ($request->exists('regimes')) {
            $overrides['association_regimes'] = self::splitPreviewList($request->query('regimes'));
        }

        if ($request->exists('tag_ids')) {
            $overrides['association_tag_ids'] = self::splitPreviewList($request->query('tag_ids'));
        }

        if ($request->exists('extra_ids')) {
            $overrides['extra_client_ids'] = self::splitPreviewList($request->query('extra_ids'));
        }

        if ($request->exists('excluded_ids')) {
            $overrides['excluded_client_ids'] = self::splitPreviewList($request->query('excluded_ids'));
        }

        if ($overrides !== []) {
            $draft = $model->replicate()->forceFill($overrides);
        }

        $preview = WorkAssociation::preview($draft, $account->id);

        return response()->json([
            'client_ids' => $preview['client_ids'],
            'count' => count($preview['client_ids']),
            'by_source' => [
                'rule' => $preview['rule'],
                'extras' => $preview['extras'],
            ],
        ]);
    }

    /**
     * Pair execution workspace read model (Task 4.3): one JSON payload for
     * the process–Client modal — identity, derived progress, operational
     * summary, the pair task list in position order, the ad-hoc create gate
     * and the server-computed documents gate. Zero writes.
     *
     * Lookup order keeps ids indistinguishable: account-scoped `findOrFail`
     * for BOTH process and client first (foreign ids 404), then the
     * association row (non-associated same-account pairs 404), then the
     * collaborator assignment check (unassigned pairs 404, never 403).
     * Managers (`admin`/`operador`) reach every associated pair.
     */
    public function showClient(int|string $process, int|string $client): JsonResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('viewAny', WorkProcess::class);

        $model = $this->findProcess($process, $account->id);

        $clientModel = Client::query()
            ->where('clients.account_id', $account->id)
            ->findOrFail($client);

        abort_unless(WorkProcessClient::query()
            ->where('work_process_id', $model->id)
            ->where('client_id', $clientModel->id)
            ->exists(), 404);

        $user = request()->user();

        if ($user instanceof User && ! in_array($user->role, WorkProcessPolicy::MANAGING_ROLES, true)) {
            $assigned = DB::table('client_user')
                ->where('client_id', $clientModel->id)
                ->where('user_id', $user->id)
                ->exists();
            abort_unless($assigned, 404);
        }

        $tasks = WorkTask::query()
            ->where('work_tasks.account_id', $account->id)
            ->where('work_tasks.work_process_id', $model->id)
            ->where('work_tasks.client_id', $clientModel->id)
            ->with(['assignee:id,name', 'process:id,target_lead_days'])
            ->orderBy('work_tasks.position')
            ->orderBy('work_tasks.id')
            ->get();

        $open = $tasks->filter(fn (WorkTask $task): bool => $task->status !== 'done')->values();
        $doneCount = $tasks->count() - $open->count();

        $nextDue = $open
            ->map(fn (WorkTask $task): ?string => $task->due_on?->format('Y-m-d'))
            ->filter()
            ->min();

        return response()->json([
            'process' => [
                'id' => $model->id,
                'title' => $model->title,
                'description' => $model->description,
            ],
            'client' => [
                'id' => $clientModel->id,
                'name' => $clientModel->razao_social,
                'tax_id' => $clientModel->cnpj,
            ],
            'progress' => [
                'done' => $doneCount,
                'total' => $tasks->count(),
            ],
            'summary' => [
                'next_due' => $nextDue,
                'highest_open_priority' => self::highestOpenPriority($open),
                'open_count' => $open->count(),
                'done_count' => $doneCount,
            ],
            'tasks' => $tasks
                ->map(fn (WorkTask $task): array => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'position' => $task->position,
                    'priority' => $task->priority,
                    'due_on' => $task->due_on?->format('Y-m-d'),
                    'target_date' => $task->target_date,
                    'assignee' => $task->assignee === null ? null : [
                        'id' => $task->assignee->id,
                        'name' => $task->assignee->name,
                    ],
                ])->all(),
            // Ad-hoc creation mirrors the `work.tasks.store` gate exactly
            // (StoreWorkTaskRequest): managers everywhere, collaborators
            // under clients assigned to them. The same
            // `WorkTaskPolicy::isClientAssignee` predicate enforces both, so
            // the modal never hides an authorized affordance.
            'can_create_task' => $user instanceof User
                && (new WorkTaskPolicy)->isClientAssignee($user, (int) $clientModel->id),
            'documents_available' => $this->documentsAvailable((int) $clientModel->id),
        ]);
    }

    /**
     * Read-only fiscal presence check (Decision 6): true WHEN the client
     * carries a filled credential OR at least one fiscal document. Raw query
     * builder only — no fiscal model, event or scope ever runs, and nothing
     * is written. Missing or unreadable fiscal structures resolve to false,
     * never to a 500.
     */
    protected function documentsAvailable(int $clientId): bool
    {
        try {
            if (Schema::hasTable('client_credentials') && DB::table('client_credentials')
                ->where('client_id', $clientId)
                ->whereNotNull('pfx_data')
                ->exists()) {
                return true;
            }

            if (Schema::hasTable('fiscal_documents') && DB::table('fiscal_documents')
                ->where('client_id', $clientId)
                ->exists()) {
                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Highest priority among open tasks (`urgent` wins, legacy `none` and
     * unknown values rank lowest); null when nothing is open.
     *
     * @param  Collection<int, WorkTask>  $open
     */
    protected static function highestOpenPriority(Collection $open): ?string
    {
        $ranks = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'urgent' => 4];

        $best = null;
        $bestRank = -1;

        foreach ($open as $task) {
            $rank = $ranks[$task->priority] ?? 0;

            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $task->priority;
            }
        }

        return $best;
    }

    /**
     * Split a comma-joined preview override into trimmed non-empty items.
     * Non-string input (including a missing key) yields an empty list; the
     * association formula casts numeric lists itself.
     *
     * @return list<string>
     */
    protected static function splitPreviewList(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $item) => $item !== ''
        ));
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
     * Effective set from the rules stored on the process. The formula lives
     * in WorkAssociation (shared with the catalog preview); this wrapper
     * keeps the 2.2 call site untouched.
     *
     * @return list<int>
     */
    protected function effectiveClientIds(WorkProcess $process, int $accountId): array
    {
        return WorkAssociation::effectiveClientIds($process, $accountId);
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
     * Dates come from the shared WorkTask::materializedRow builder (null
     * competence here ⇒ timeless ⇒ dateless, by decision).
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

                $rows[] = WorkTask::materializedRow($process, $definition, $accountId, $clientId, null, $firstPosition, $now);
            }
        }

        if ($rows !== []) {
            WorkTask::insertOrIgnore($rows);
        }
    }
}
