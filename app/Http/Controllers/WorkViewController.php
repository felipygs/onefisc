<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use App\Models\WorkTask;
use App\Policies\WorkProcessPolicy;
use App\Policies\WorkTaskPolicy;
use App\Support\CurrentAccount;
use App\Support\WorkCompetence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only shell behind `/work/*` (Task 2.4).
 *
 * No behavior change: routes are additive and every listing reuses the
 * account scoping and visibility rules owned by WorkProcessController,
 * WorkTaskController and their policies. Overview, calendar, catalog and
 * the pair workspace land in later waves — here they render honest empty
 * panels, never demo numbers.
 *
 * Task 3.1: the `processo` and `tarefas` views accept an explicit
 * `?competence=YYYY-MM` which first materializes the missing checklist
 * instances (GET with side effect, legacy-mandated) and then scopes every
 * task list through the shared WorkCompetence rule.
 */
class WorkViewController extends Controller
{
    /**
     * Shell children served by the single `work/Processos` page.
     *
     * @var list<string>
     */
    public const VIEWS = ['processo', 'tarefas', 'calendario', 'cliente'];

    /**
     * @var list<string>
     */
    public const BOARD_STATUSES = ['backlog', 'todo', 'in_progress', 'done'];

    public function overview(): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('viewAny', WorkProcess::class);

        // Full overview ships in Onda 2 (Task 3.4): zero aggregated numbers
        // here on purpose, only the placeholder panel.
        return Inertia::render('work/Overview');
    }

    /**
     * @return RedirectResponse|Response
     */
    public function processos(Request $request, ?string $view = null)
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('viewAny', WorkProcess::class);

        // One-time legacy redirect: `/work/processos?view=tarefas` bookmarks
        // move to the child route with the whole query dropped.
        $legacy = $request->query('view');

        if ($legacy !== null) {
            abort_unless(in_array((string) $legacy, self::VIEWS, true), 404);

            return redirect()->route('work.processos', ['view' => (string) $legacy]);
        }

        $view ??= 'processo';
        abort_unless(in_array($view, self::VIEWS, true), 404);

        $user = $request->user();

        $validated = $request->validate([
            'competence' => ['sometimes', 'string', 'regex:'.WorkCompetence::PATTERN],
        ], [
            'competence.regex' => 'A competência deve estar no formato AAAA-MM.',
        ]);

        $competence = $validated['competence'] ?? null;

        if (is_string($competence) && $competence !== '') {
            // GET with side effect (legacy-mandated): opening a competence
            // materializes the missing checklist instances before listing.
            WorkCompetence::materialize($account->id, $user instanceof User ? $user : null, $competence);
        }

        $search = trim((string) $request->query('search', ''));

        $props = [
            'view' => $view,
            'search' => $search,
            'competence' => $competence,
        ];

        if ($view === 'processo') {
            $props['processes'] = $this->processTree($account->id, $user instanceof User ? $user : null, $search, $competence);
            $props['hasAssignments'] = $this->hasAssignments($account->id, $user instanceof User ? $user : null);
        }

        if ($view === 'tarefas') {
            $props['board'] = $this->board($account->id, $user instanceof User ? $user : null, $competence);
            $props['hasAssignments'] = $this->hasAssignments($account->id, $user instanceof User ? $user : null);
        }

        return Inertia::render('work/Processos', $props);
    }

    /**
     * Account processes with associated companies and each pair's tasks in
     * position order. Managers see the whole Account; collaborators only
     * processes holding at least one client assigned to them, with nested
     * companies and tasks narrowed to those clients (same rule as
     * WorkProcessController::scopedQuery + WorkTaskPolicy::scopeVisible).
     *
     * @return list<array<string, mixed>>
     */
    protected function processTree(int $accountId, ?User $user, string $search, ?string $competence = null): array
    {
        $manager = $this->manages($user);
        $like = $this->like($search);

        $query = WorkProcess::query()->where('work_processes.account_id', $accountId);

        if (! $manager) {
            $query = $this->onlyAssignedProcesses($query, $user);
        }

        if ($like !== null) {
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('work_processes.title', 'like', $like)
                    ->orWhereExists(function ($exists) use ($like): void {
                        $exists->select(DB::raw('1'))
                            ->from('work_process_clients as wpc')
                            ->join('clients as c', 'c.id', '=', 'wpc.client_id')
                            ->whereColumn('wpc.work_process_id', 'work_processes.id')
                            ->where('c.account_id', DB::raw('work_processes.account_id'))
                            ->where('c.razao_social', 'like', $like);
                    });
            });
        }

        $processes = $query
            ->orderBy('work_processes.title')
            ->get(['work_processes.id', 'work_processes.title', 'work_processes.source', 'work_processes.status']);

        if ($processes->isEmpty()) {
            return [];
        }

        $processIds = array_values($processes->pluck('id')->map(fn ($id) => (int) $id)->all());

        $clientsByProcess = $this->treeClients($accountId, $user, $manager, $processIds);
        $tasksByPair = $this->treeTasks($accountId, $user, $processIds, $clientsByProcess, $competence);

        return array_values($processes->map(function (WorkProcess $process) use ($clientsByProcess, $tasksByPair): array {
            $clients = $clientsByProcess[$process->id] ?? [];

            return [
                'id' => $process->id,
                'title' => $process->title,
                'source' => $process->source,
                'status' => $process->status,
                'clients_count' => count($clients),
                'clients' => array_map(fn (array $client): array => [
                    ...$client,
                    'tasks' => $tasksByPair[$process->id][$client['id']] ?? [],
                ], $clients),
            ];
        })->all());
    }

    /**
     * @param  list<int>  $processIds
     * @return array<int, list<array{id: int, razao_social: string}>>
     */
    protected function treeClients(int $accountId, ?User $user, bool $manager, array $processIds): array
    {
        $query = WorkProcessClient::query()
            ->join('clients as c', 'c.id', '=', 'work_process_clients.client_id')
            ->where('c.account_id', $accountId)
            ->whereIn('work_process_clients.work_process_id', $processIds);

        if (! $manager) {
            $query->whereExists(function ($exists) use ($user): void {
                $exists->select(DB::raw('1'))
                    ->from('client_user as cu')
                    ->whereColumn('cu.client_id', 'work_process_clients.client_id')
                    ->where('cu.user_id', $user?->id);
            });
        }

        $rows = $query
            ->orderBy('c.razao_social')
            ->with('client:id,razao_social')
            ->get(['work_process_clients.work_process_id', 'work_process_clients.client_id']);

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->work_process_id][] = [
                'id' => (int) $row->client_id,
                'razao_social' => (string) ($row->client->razao_social ?? ''),
            ];
        }

        return $grouped;
    }

    /**
     * @param  list<int>  $processIds
     * @param  array<int, list<array{id: int, razao_social: string}>>  $clientsByProcess
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    protected function treeTasks(int $accountId, ?User $user, array $processIds, array $clientsByProcess, ?string $competence = null): array
    {
        $clientIds = collect($clientsByProcess)->flatten(1)->pluck('id')->unique()->values()->all();

        if ($clientIds === []) {
            return [];
        }

        $tasks = WorkTaskPolicy::scopeVisible(
            WorkTask::query()->where('work_tasks.account_id', $accountId),
            $user
        )
            ->forCompetence($competence)
            ->whereIn('work_tasks.work_process_id', $processIds)
            ->whereIn('work_tasks.client_id', $clientIds)
            ->with(['assignee:id,name'])
            ->orderBy('work_tasks.position')
            ->orderBy('work_tasks.id')
            ->get();

        $grouped = [];

        foreach ($tasks as $task) {
            $grouped[(int) $task->work_process_id][(int) $task->client_id][] = $this->serializeTreeTask($task);
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeTreeTask(WorkTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'position' => $task->position,
            'priority' => $task->priority,
            'due_on' => $task->due_on?->format('Y-m-d'),
            'assignee' => $task->assignee === null ? null : [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
            ],
        ];
    }

    /**
     * Board grouped by `backlog|todo|in_progress|done`, each column in
     * position order with the parent Client identity loaded so no card
     * renders an empty Client name.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function board(int $accountId, ?User $user, ?string $competence = null): array
    {
        $tasks = WorkTaskPolicy::scopeVisible(
            WorkTask::query()->where('work_tasks.account_id', $accountId),
            $user
        )
            ->forCompetence($competence)
            ->with([
                'process:id,title',
                'client:id,razao_social',
                'assignee:id,name',
            ])
            ->orderBy('work_tasks.position')
            ->orderBy('work_tasks.id')
            ->get();

        $board = array_fill_keys(self::BOARD_STATUSES, []);

        foreach ($tasks as $task) {
            $status = in_array($task->status, self::BOARD_STATUSES, true) ? $task->status : 'backlog';

            $board[$status][] = [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'position' => $task->position,
                'priority' => $task->priority,
                'due_on' => $task->due_on?->format('Y-m-d'),
                'process' => $task->process === null ? null : [
                    'id' => $task->process->id,
                    'title' => $task->process->title,
                ],
                'client' => $task->client === null ? null : [
                    'id' => $task->client->id,
                    'razao_social' => $task->client->razao_social,
                ],
                'assignee' => $task->assignee === null ? null : [
                    'id' => $task->assignee->id,
                    'name' => $task->assignee->name,
                ],
            ];
        }

        return $board;
    }

    protected function manages(?User $user): bool
    {
        return $user instanceof User && in_array($user->role, WorkProcessPolicy::MANAGING_ROLES, true);
    }

    /**
     * Whether the member can see any work at all: managers always can,
     * collaborators only when at least one Client is assigned to them.
     * Drives the honest "no Clients assigned" empty state.
     */
    protected function hasAssignments(int $accountId, ?User $user): bool
    {
        if ($this->manages($user)) {
            return true;
        }

        if (! $user instanceof User) {
            return false;
        }

        return DB::table('client_user as cu')
            ->join('clients as c', 'c.id', '=', 'cu.client_id')
            ->where('c.account_id', $accountId)
            ->where('cu.user_id', $user->id)
            ->exists();
    }

    /**
     * Narrow processes to those holding at least one client assigned to the
     * collaborator via `client_user` (mirrors WorkProcessController).
     *
     * @param  Builder<WorkProcess>  $query
     * @return Builder<WorkProcess>
     */
    protected function onlyAssignedProcesses(Builder $query, ?User $user): Builder
    {
        return $query->whereExists(function ($exists) use ($user): void {
            $exists->select(DB::raw('1'))
                ->from('work_process_clients as wpc')
                ->join('client_user as cu', 'cu.client_id', '=', 'wpc.client_id')
                ->whereColumn('wpc.work_process_id', 'work_processes.id')
                ->where('cu.user_id', $user?->id);
        });
    }

    /**
     * Build a LIKE pattern with `%`, `_` and `\` escaped, or null when there
     * is no search term.
     */
    protected function like(string $search): ?string
    {
        if ($search === '') {
            return null;
        }

        return '%'.addcslashes($search, '%_\\').'%';
    }
}
