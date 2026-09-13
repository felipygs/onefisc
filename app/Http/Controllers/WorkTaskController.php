<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveWorkTaskRequest;
use App\Http\Requests\StoreWorkTaskRequest;
use App\Http\Requests\UpdateWorkTaskRequest;
use App\Models\User;
use App\Models\WorkTask;
use App\Policies\WorkTaskPolicy;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkTaskController extends Controller
{
    public function index(Request $request): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('viewAny', WorkTask::class);

        $filters = $request->validate([
            'client_id' => [
                'sometimes', 'integer',
                Rule::exists('clients', 'id')->where('account_id', $account->id),
            ],
            'process_id' => [
                'sometimes', 'integer',
                Rule::exists('work_processes', 'id')->where('account_id', $account->id),
            ],
            'assignee_id' => [
                'sometimes', 'integer',
                Rule::exists('users', 'id')->where('account_id', $account->id),
            ],
            'status' => ['sometimes', 'string', 'in:backlog,todo,in_progress,done'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ], [
            'client_id.exists' => 'A empresa não pertence a esta Account.',
            'process_id.exists' => 'O processo não pertence a esta Account.',
            'assignee_id.exists' => 'O responsável não pertence a esta Account.',
            'status.in' => 'O status deve ser backlog, todo, in_progress ou done.',
            'from.date_format' => 'A data inicial deve estar no formato AAAA-MM-DD.',
            'to.date_format' => 'A data final deve estar no formato AAAA-MM-DD.',
        ]);

        $tasks = $this->scopedQuery($account->id)
            ->with(['process', 'client', 'assignee'])
            ->when(
                array_key_exists('client_id', $filters),
                fn ($query) => $query->where('work_tasks.client_id', $filters['client_id'])
            )
            ->when(
                array_key_exists('process_id', $filters),
                fn ($query) => $query->where('work_tasks.work_process_id', $filters['process_id'])
            )
            ->when(
                array_key_exists('assignee_id', $filters),
                fn ($query) => $query->where('work_tasks.assigned_user_id', $filters['assignee_id'])
            )
            ->when(
                array_key_exists('status', $filters),
                fn ($query) => $query->where('work_tasks.status', $filters['status'])
            )
            ->when(
                array_key_exists('from', $filters) || array_key_exists('to', $filters),
                fn ($query) => $this->applyDateRange(
                    $query,
                    $filters['from'] ?? null,
                    $filters['to'] ?? null
                )
            )
            ->orderBy('work_tasks.position')
            ->orderBy('work_tasks.id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Work/Tasks/Index', [
            'tasks' => $tasks,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', WorkTask::class);

        return Inertia::render('Work/Tasks/Create');
    }

    /**
     * Ad-hoc create under a process–Client pair. Rows dedupe at app layer on
     * process + client + trimmed title + null competence (the unique index
     * cannot cover NULL definition ids), returning the existing row instead
     * of duplicating. Omitted priority stores `medium`.
     */
    public function store(StoreWorkTaskRequest $request): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);

        $validated = $request->validated();
        $title = trim((string) $validated['title']);

        DB::transaction(function () use ($account, $validated, $title): void {
            $existing = WorkTask::query()
                ->where('work_tasks.account_id', $account->id)
                ->where('work_tasks.work_process_id', $validated['work_process_id'])
                ->where('work_tasks.client_id', $validated['client_id'])
                ->whereNull('work_tasks.competence')
                ->whereNull('work_tasks.work_process_task_definition_id')
                ->whereRaw('TRIM(work_tasks.title) = TRIM(?)', [$validated['title']])
                ->first();

            if ($existing instanceof WorkTask) {
                return;
            }

            WorkTask::create([
                'account_id' => $account->id,
                'work_process_id' => $validated['work_process_id'],
                'client_id' => $validated['client_id'],
                'work_process_task_definition_id' => null,
                'title' => $title,
                'status' => $validated['status'] ?? 'todo',
                'position' => $validated['position'] ?? 0,
                'priority' => $validated['priority'] ?? 'medium',
                'assigned_user_id' => $validated['assigned_user_id'] ?? null,
                'department_id' => $validated['department_id'] ?? null,
                'competence' => null,
                'start_at' => $validated['start_at'] ?? null,
                'due_on' => $validated['due_on'] ?? null,
            ]);
        });

        return redirect()->back()->with('status', 'Tarefa criada.');
    }

    public function show(int|string $task): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findTask($task, $account->id);
        Gate::authorize('view', $model);

        return Inertia::render('Work/Tasks/Show', [
            'task' => $model->load(['process', 'client', 'assignee']),
        ]);
    }

    public function edit(int|string $task): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findTask($task, $account->id);
        Gate::authorize('update', $model);

        return Inertia::render('Work/Tasks/Edit', [
            'task' => $model->load(['process', 'client', 'assignee']),
        ]);
    }

    /**
     * Definition linkage is immutable: any `definition_id` input is ignored
     * because it never reaches validated data.
     */
    public function update(UpdateWorkTaskRequest $request, int|string $task): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findTask($task, $account->id);
        $validated = $request->validated();

        if (array_key_exists('title', $validated)) {
            $validated['title'] = trim((string) $validated['title']);
        }

        $model->update($validated);

        return redirect()->back()->with('status', 'Tarefa atualizada.');
    }

    public function destroy(int|string $task): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findTask($task, $account->id);
        Gate::authorize('delete', $model);

        $model->delete();

        return redirect()->back()->with('status', 'Tarefa removida.');
    }

    /**
     * Atomic board move: status and position update together in one
     * transaction so the board never observes a half-moved task.
     */
    public function move(MoveWorkTaskRequest $request, int|string $task): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $model = $this->findTask($task, $account->id);
        $validated = $request->validated();

        DB::transaction(function () use ($model, $validated): void {
            $model->update([
                'status' => $validated['status'],
                'position' => $validated['position'],
            ]);
        });

        return redirect()->back()->with('status', 'Tarefa movida.');
    }

    /**
     * Resolve a task through the account- and visibility-scoped query.
     * Foreign or unassigned ids fail with 404 here, before any policy check
     * can leak a 403.
     */
    protected function findTask(int|string $id, int $accountId): WorkTask
    {
        return $this->scopedQuery($accountId)->findOrFail($id);
    }

    /**
     * Account-scoped listing: managers see every task, collaborators only
     * tasks whose client is assigned to them via client_user.
     *
     * @return Builder<WorkTask>
     */
    protected function scopedQuery(int $accountId): Builder
    {
        $base = WorkTask::query()->where('work_tasks.account_id', $accountId);

        $user = request()->user();

        return WorkTaskPolicy::scopeVisible($base, $user instanceof User ? $user : null);
    }

    /**
     * Per-field overlap: a task matches WHEN its due date falls in range OR
     * its start falls in range, never mixing bounds across fields. Tasks with
     * neither date are excluded whenever a range is given.
     *
     * @param  Builder<WorkTask>  $query
     * @return Builder<WorkTask>
     */
    protected function applyDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query->where(function ($outer) use ($from, $to): void {
            $outer->where(function ($due) use ($from, $to): void {
                $due->whereNotNull('work_tasks.due_on');

                if ($from !== null) {
                    $due->where('work_tasks.due_on', '>=', $from);
                }

                if ($to !== null) {
                    $due->where('work_tasks.due_on', '<=', $to);
                }
            })->orWhere(function ($start) use ($from, $to): void {
                $start->whereNotNull('work_tasks.start_at');

                if ($from !== null) {
                    $start->where('work_tasks.start_at', '>=', $from);
                }

                if ($to !== null) {
                    $start->where('work_tasks.start_at', '<=', $to);
                }
            });
        });
    }
}
