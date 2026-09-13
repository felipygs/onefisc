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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function overview(Request $request): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('viewAny', WorkProcess::class);

        $validated = $request->validate([
            'competence' => ['sometimes', 'string', 'regex:'.WorkCompetence::PATTERN],
        ], [
            'competence.regex' => 'A competência deve estar no formato AAAA-MM.',
        ]);

        $competence = $validated['competence'] ?? null;

        if (! is_string($competence) || $competence === '') {
            $competence = Carbon::now()->format('Y-m');
        }

        $user = $request->user();
        $member = $user instanceof User ? $user : null;

        // GET with side effect (legacy-mandated, same as the 3.1 lists and
        // the 3.3 full month): opening a competence materializes the missing
        // checklist instances before aggregating.
        WorkCompetence::materialize($account->id, $member, $competence);

        $today = Carbon::today()->format('Y-m-d');

        $tasks = WorkTaskPolicy::scopeVisible(
            WorkTask::query()->where('work_tasks.account_id', $account->id),
            $member
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

        return Inertia::render('work/Overview', [
            'competence' => $competence,
            'today' => $today,
            'overview' => $this->buildOverview($tasks, $member, $competence, $today),
        ]);
    }

    /**
     * Central aggregates for one competence (Task 3.4).
     *
     * Every panel reads the SAME visible-task set: account scope plus
     * `WorkTaskPolicy::scopeVisible` (collaborators only reach
     * assigned-client rows) plus the shared 3.1 `forCompetence` rule.
     * Overdue reuses the lists' rule (open task with `due_on` before today).
     *
     * "Atenção" is a documented judgment call: the spec names the panel but
     * fixes no content, so it shows the top-5 overdue tasks by age (each
     * with its task link) plus the stale-backlog count (backlog tasks in the
     * competence that never started). Unassigned tasks count in situação and
     * envelhecimento but never gain a member row in carga/equipe — those
     * rows are members only, and members without tasks are omitted. A
     * collaborator (`user`) sees only their own member row.
     *
     * Out-of-scope fiscal concepts stay out of this payload entirely (the
     * four banned concepts from the work-overview spec appear neither in
     * keys nor in texts).
     *
     * @param  Collection<int, WorkTask>  $tasks
     * @return array<string, mixed>
     */
    protected function buildOverview(Collection $tasks, ?User $user, string $competence, string $today): array
    {
        $counts = array_fill_keys(self::BOARD_STATUSES, 0);

        /** @var list<array{task: WorkTask, age: int}> $overdue */
        $overdue = [];

        foreach ($tasks as $task) {
            $status = in_array($task->status, self::BOARD_STATUSES, true) ? $task->status : 'backlog';
            $counts[$status]++;

            $dueOn = $task->due_on?->format('Y-m-d');

            if ($task->status !== 'done' && $dueOn !== null && $dueOn < $today) {
                $overdue[] = [
                    'task' => $task,
                    // Carbon 3 diffs are signed by default: absolute age in
                    // days, since overdue rows are always due before today.
                    'age' => (int) Carbon::parse($today)->diffInDays($task->due_on, true),
                ];
            }
        }

        // Donut: an association (process–Client pair) counts as completed
        // WHEN every visible task in the competence is `done`.
        $openByPair = [];

        foreach ($tasks as $task) {
            $pair = ((int) $task->work_process_id).':'.((int) $task->client_id);
            $openByPair[$pair] = ($openByPair[$pair] ?? 0) + ($task->status === 'done' ? 0 : 1);
        }

        $associationsDone = 0;

        foreach ($openByPair as $open) {
            if ($open === 0) {
                $associationsDone++;
            }
        }

        $associationsTotal = count($openByPair);

        $manager = $this->manages($user);
        $selfId = $user instanceof User && ! $manager ? $user->id : null;

        /** @var array<int, array{member: array{id: int, name: string}, backlog: int, todo: int, in_progress: int, done: int, total: int}> $byMember */
        $byMember = [];

        foreach ($tasks as $task) {
            if ($task->assigned_user_id === null) {
                continue;
            }

            $uid = (int) $task->assigned_user_id;

            if ($selfId !== null && $uid !== $selfId) {
                continue;
            }

            if (! isset($byMember[$uid])) {
                $assigneeName = $task->assignee === null ? "Membro {$uid}" : $task->assignee->name;
                $byMember[$uid] = [
                    'member' => ['id' => $uid, 'name' => $assigneeName],
                    'backlog' => 0,
                    'todo' => 0,
                    'in_progress' => 0,
                    'done' => 0,
                    'total' => 0,
                ];
            }

            $status = in_array($task->status, self::BOARD_STATUSES, true) ? $task->status : 'backlog';
            $byMember[$uid][$status]++;
            $byMember[$uid]['total']++;
        }

        $load = array_values($byMember);

        usort($load, fn (array $a, array $b): int => [$b['total'], $a['member']['name'], $a['member']['id']]
            <=> [$a['total'], $b['member']['name'], $b['member']['id']]);

        $team = array_map(fn (array $row): array => [
            ...$row,
            'completion_pct' => $row['total'] > 0 ? (int) round($row['done'] / $row['total'] * 100) : 0,
            'link' => ['route' => 'work.tasks.index', 'competence' => $competence, 'assignee_id' => $row['member']['id']],
        ], $load);

        $bands = ['1-7' => 0, '8-15' => 0, '16-30' => 0, '31+' => 0];

        foreach ($overdue as $row) {
            $age = $row['age'];

            if ($age <= 7) {
                $bands['1-7']++;
            } elseif ($age <= 15) {
                $bands['8-15']++;
            } elseif ($age <= 30) {
                $bands['16-30']++;
            } else {
                $bands['31+']++;
            }
        }

        usort($overdue, fn (array $a, array $b): int => [$b['age'], $a['task']->id] <=> [$a['age'], $b['task']->id]);

        $attention = array_map(
            fn (array $row): array => $this->serializeAttentionTask($row['task'], $row['age']),
            array_slice($overdue, 0, 5)
        );

        $links = [];

        foreach (self::BOARD_STATUSES as $status) {
            $links[$status] = ['route' => 'work.tasks.index', 'competence' => $competence, 'status' => $status];
        }

        $links['overdue'] = ['route' => 'work.processos', 'view' => 'tarefas', 'competence' => $competence];

        return [
            'competence' => $competence,
            'today' => $today,
            'hasData' => $tasks->isNotEmpty(),
            'counters' => [
                ...$counts,
                'overdue' => count($overdue),
                'total' => $tasks->count(),
            ],
            'links' => $links,
            'donut' => [
                'done' => $associationsDone,
                'total' => $associationsTotal,
                'label' => "{$associationsDone} de {$associationsTotal}",
            ],
            'load' => $load,
            'aging' => ['bands' => $bands, 'total' => count($overdue)],
            'attention' => ['overdue' => $attention, 'stale_backlog' => $counts['backlog']],
            'team' => $team,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeAttentionTask(WorkTask $task, int $age): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'due_on' => $task->due_on?->format('Y-m-d'),
            'age_days' => $age,
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
            'link' => ['route' => 'work.tasks.show', 'task' => $task->id],
        ];
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
            'cal' => ['sometimes', 'string', 'in:month,week,day'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ], [
            'competence.regex' => 'A competência deve estar no formato AAAA-MM.',
            'cal.in' => 'A visão do calendário deve ser month, week ou day.',
            'date.date_format' => 'A data deve estar no formato AAAA-MM-DD.',
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

        if ($view === 'calendario') {
            $props['calendar'] = $this->calendar(
                $account->id,
                $user instanceof User ? $user : null,
                $validated['cal'] ?? null,
                $validated['date'] ?? null
            );
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
            ->with(['assignee:id,name', 'process:id,target_lead_days'])
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
            'target_date' => $task->target_date,
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
                'process:id,title,target_lead_days',
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
                'target_date' => $task->target_date,
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

    /**
     * Calendar payload for the `calendario` shell view (Task 3.3).
     *
     * Ranges by `cal` (default `month`) around `date` (default today): month
     * is the full civil month (first–last day), week is Monday–Sunday
     * containing the date, day is the single date. The grid set holds every
     * visible task with `due_on` inside the range (inclusive) — visibility
     * reuses the 3.1 `scopeVisible` rule and there is intentionally NO
     * `forCompetence` filter, so a prior-competence task due in range shows
     * by due date even when it shares nothing with the range's competence.
     * Competence inference runs ONLY for a full civil month (`cal=month`,
     * which by construction IS the full month): the 3.1 open-time
     * materialization for that competence happens before listing, exactly
     * like the list/tree/board opens. Week/day NEVER materialize, even when
     * both bounds sit in one month. Rows without a valid `due_on` are
     * listed separately as `dateless` — never plotted.
     *
     * `due_on` serializes as `Y-m-d` strings (calendar payloads stay
     * consistent; list/detail keep their own format, out of scope here).
     *
     * @return array<string, mixed>
     */
    protected function calendar(int $accountId, ?User $user, ?string $cal, ?string $date): array
    {
        $cal ??= 'month';
        $anchor = $date !== null && $date !== ''
            ? Carbon::createFromFormat('Y-m-d', $date)->startOfDay()
            : Carbon::today();

        [$start, $end] = match ($cal) {
            'day' => [$anchor->copy(), $anchor->copy()],
            'week' => [
                $anchor->copy()->startOfWeek(Carbon::MONDAY),
                $anchor->copy()->endOfWeek(Carbon::SUNDAY),
            ],
            default => [
                $anchor->copy()->startOfMonth(),
                $anchor->copy()->endOfMonth(),
            ],
        };

        $startString = $start->format('Y-m-d');
        $endString = $end->format('Y-m-d');
        $today = Carbon::today()->format('Y-m-d');

        // ONLY a full civil month infers competence — `cal=month` always
        // qualifies because the range above IS the whole month.
        $competence = $cal === 'month' ? $start->format('Y-m') : null;

        if ($competence !== null) {
            // GET with side effect (legacy-mandated, same as 3.1): opening
            // the month materializes the missing checklist instances first.
            WorkCompetence::materialize($accountId, $user, $competence);
        }

        $base = function () use ($accountId, $user) {
            return WorkTaskPolicy::scopeVisible(
                WorkTask::query()->where('work_tasks.account_id', $accountId),
                $user
            );
        };

        $tasks = $base()
            // whereDate (not whereBetween): the `date` cast persists
            // `Y-m-d H:i:s` strings under SQLite, and a bare string bound
            // would lexicographically miss same-day rows.
            ->whereDate('work_tasks.due_on', '>=', $startString)
            ->whereDate('work_tasks.due_on', '<=', $endString)
            ->with([
                'process:id,title,target_lead_days',
                'client:id,razao_social',
                'assignee:id,name',
            ])
            ->orderBy('work_tasks.due_on')
            ->orderBy('work_tasks.position')
            ->orderBy('work_tasks.id')
            ->get();

        $dateless = $base()
            ->whereNull('work_tasks.due_on')
            ->with([
                'process:id,title,target_lead_days',
                'client:id,razao_social',
                'assignee:id,name',
            ])
            ->orderBy('work_tasks.position')
            ->orderBy('work_tasks.id')
            ->get();

        return [
            'cal' => $cal,
            'date' => $anchor->format('Y-m-d'),
            'start' => $startString,
            'end' => $endString,
            'competence' => $competence,
            'today' => $today,
            'tasks' => $tasks->map(fn (WorkTask $task): array => $this->serializeCalendarTask($task, $today))->all(),
            'dateless' => $dateless->map(fn (WorkTask $task): array => $this->serializeCalendarTask($task, $today))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeCalendarTask(WorkTask $task, string $today): array
    {
        $dueOn = $task->due_on?->format('Y-m-d');

        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'position' => $task->position,
            'priority' => $task->priority,
            'due_on' => $dueOn,
            'target_date' => $task->target_date,
            'is_overdue' => $dueOn !== null && $task->status !== 'done' && $dueOn < $today,
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
