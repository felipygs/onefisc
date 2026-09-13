<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkProcessTaskDefinition;
use App\Models\WorkTask;
use App\Policies\WorkProcessPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Competence scoping with idempotent open-time materialization (Task 3.1),
 * shared by the task list, the process tree and the board.
 *
 * Matching rule for an explicit competence X (`YYYY-MM`): a task matches
 * WHEN its stored competence is X, OR it is timeless (`competence` NULL
 * from Onda 1, kept visible under every competence) AND it is dateless
 * (`due_on` NULL) OR due inside X's calendar month. Without a competence
 * param every row matches — there is no implicit current-month default.
 */
class WorkCompetence
{
    /**
     * Strict `YYYY-MM` shape. A `date_format:Y-m` rule would accept month
     * 13 through parser overflow, so controllers validate against this
     * pattern and reject anything else with 422.
     */
    public const PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public static function isValid(?string $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Inclusive calendar-month window (`X-01`..`X-last-day`) for a competence.
     * Dating/recurrence math belongs to Task 3.2 — this only bounds the
     * timeless due-date window.
     *
     * @return array{0: string, 1: string}
     */
    public static function monthRange(string $competence): array
    {
        if (! self::isValid($competence)) {
            throw new InvalidArgumentException('A competência deve estar no formato AAAA-MM.');
        }

        // Day pinned to 01 so short months never overflow from "today".
        $month = Carbon::createFromFormat('Y-m-d', $competence.'-01');

        if (! $month instanceof Carbon) {
            throw new InvalidArgumentException('A competência deve estar no formato AAAA-MM.');
        }

        return [$month->copy()->startOfMonth()->format('Y-m-d'), $month->copy()->endOfMonth()->format('Y-m-d')];
    }

    /**
     * THE competence matching rule — the single implementation reused by
     * the task list, the process tree and the board (via
     * `WorkTask::forCompetence`). Null means no filter.
     *
     * @param  Builder<WorkTask>  $query
     * @return Builder<WorkTask>
     */
    public static function applyScope(Builder $query, ?string $competence): Builder
    {
        if ($competence === null || $competence === '') {
            return $query;
        }

        [$start, $end] = self::monthRange($competence);

        return $query->where(function ($outer) use ($competence, $start, $end): void {
            $outer->where('work_tasks.competence', $competence)
                ->orWhere(function ($timeless) use ($start, $end): void {
                    $timeless->whereNull('work_tasks.competence')
                        ->where(function ($due) use ($start, $end): void {
                            $due->whereNull('work_tasks.due_on')
                                ->orWhereBetween('work_tasks.due_on', [$start, $end]);
                        });
                });
        });
    }

    /**
     * Materialize missing checklist instances for a competence before
     * listing.
     *
     * NOTE — GET with side effect (legacy-mandated behavior): opening a
     * competence listing writes the missing rows first, then reads. For
     * every association row of the visible processes and every current
     * definition, the triple (process, Client, definition) plus competence
     * is created once: triples already carrying a timeless NULL-competence
     * row are skipped (those rows stay visible under every competence and
     * block their dated counterpart — no backfill), triples already stored
     * for X are skipped, and the rest are inserted with the non-null
     * competence so the materialization unique index converges racing
     * opens into a single instance. New rows reuse the 2.2 cascade status
     * and copy priority/assignee/department from the definition with the
     * due date from the shared WorkTask::materializedRow builder (3.2).
     *
     * Lazy backfill (3.2, no data migration by decision): rows stored dateless
     * before 3.2 (non-null competence + null due_on) get their computed dates
     * on the next open of that same competence+process, in this same
     * transaction. Timeless NULL-competence rows stay dateless forever —
     * timeless means no anchor month.
     *
     * Write visibility matches the reads: managers materialize every
     * association of the visible processes, while collaborators only
     * materialize pairs whose Client is assigned to them via `client_user`
     * — the same assigned-client rule `WorkTaskPolicy::scopeVisible`
     * enforces on reads — so an open never writes rows the opener cannot
     * reach.
     */
    public static function materialize(int $accountId, ?User $user, string $competence): void
    {
        if (! self::isValid($competence)) {
            throw new InvalidArgumentException('A competência deve estar no formato AAAA-MM.');
        }

        $manager = $user instanceof User && in_array($user->role, WorkProcessPolicy::MANAGING_ROLES, true);

        $processes = WorkProcess::query()->where('work_processes.account_id', $accountId);

        if (! $manager) {
            $processes->whereExists(function ($exists) use ($user): void {
                $exists->select(DB::raw('1'))
                    ->from('work_process_clients as wpc')
                    ->join('client_user as cu', 'cu.client_id', '=', 'wpc.client_id')
                    ->whereColumn('wpc.work_process_id', 'work_processes.id')
                    ->where('cu.user_id', $user?->id);
            });
        }

        $processIds = $processes->pluck('work_processes.id')->map(fn ($id) => (int) $id)->all();

        if ($processIds === []) {
            return;
        }

        // ONE query up front: the opener's reachable clients, account-scoped.
        // Managers keep null (every pair); collaborators intersect each
        // process's associations with this list below.
        $assignedClientIds = null;

        if (! $manager) {
            $assignedClientIds = array_values(DB::table('client_user as cu')
                ->join('clients as c', 'c.id', '=', 'cu.client_id')
                ->where('c.account_id', $accountId)
                ->where('cu.user_id', $user?->id)
                ->pluck('cu.client_id')->map(fn ($id) => (int) $id)->all());
        }

        DB::transaction(function () use ($accountId, $competence, $processIds, $assignedClientIds): void {
            foreach ($processIds as $processId) {
                self::materializeProcess($accountId, $processId, $competence, $assignedClientIds);
            }
        });
    }

    /**
     * Materialize one process for a competence. Serializes per process with
     * the same `lockForUpdate` pattern as 2.2's association apply: the row
     * lock — not the index — is what keeps racing opens from
     * double-materializing, while the unique index stays as backstop for
     * the dated rows.
     *
     * @param  list<int>|null  $assignedClientIds  Null for managers (every
     *                                             pair); collaborators only
     *                                             write their assigned pairs.
     */
    protected static function materializeProcess(int $accountId, int $processId, string $competence, ?array $assignedClientIds = null): void
    {
        $locked = WorkProcess::query()
            ->where('work_processes.account_id', $accountId)
            ->whereKey($processId)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof WorkProcess) {
            return;
        }

        $definitions = $locked->definitions()->orderBy('position')->get();

        if ($definitions->isEmpty()) {
            return;
        }

        $clientIds = array_values($locked->processClients()->pluck('client_id')->map(fn ($id) => (int) $id)->all());

        if ($assignedClientIds !== null) {
            $clientIds = array_values(array_intersect($clientIds, $assignedClientIds));
        }

        if ($clientIds === []) {
            return;
        }

        // One grouped read for the process: timeless rows and this
        // competence's rows, keyed by Client + definition.
        /** @var array<string, true> $timeless */
        $timeless = [];
        /** @var array<string, true> $stored */
        $stored = [];

        $rows = WorkTask::query()
            ->where('work_tasks.work_process_id', $locked->id)
            ->whereIn('work_tasks.client_id', $clientIds)
            ->whereNotNull('work_tasks.work_process_task_definition_id')
            ->where(function ($pair) use ($competence): void {
                $pair->whereNull('work_tasks.competence')
                    ->orWhere('work_tasks.competence', $competence);
            })
            ->get(['work_tasks.client_id', 'work_tasks.work_process_task_definition_id', 'work_tasks.competence']);

        foreach ($rows as $row) {
            $key = ((int) $row->client_id).':'.((int) $row->work_process_task_definition_id);

            if ($row->competence === null) {
                $timeless[$key] = true;
            } else {
                $stored[$key] = true;
            }
        }

        $firstPosition = (int) $definitions->min('position');
        $now = now();
        $inserts = [];

        foreach ($clientIds as $clientId) {
            foreach ($definitions as $definition) {
                $key = $clientId.':'.$definition->id;

                if (isset($timeless[$key]) || isset($stored[$key])) {
                    continue;
                }

                $inserts[] = WorkTask::materializedRow($locked, $definition, $accountId, $clientId, $competence, $firstPosition, $now);
            }
        }

        if ($inserts !== []) {
            WorkTask::insertOrIgnore($inserts);
        }

        self::backfillDates($locked, $definitions, $clientIds, $competence, $now);
    }

    /**
     * Lazy backfill for pre-3.2 dateless rows (same transaction, same
     * competence+process, reachable pairs only): undated dated-competence
     * rows whose effective due config yields a date get it now. Timeless
     * rows are never touched (no anchor month). No-op when nothing matches.
     *
     * @param  Collection<int, WorkProcessTaskDefinition>  $definitions
     * @param  list<int>  $clientIds
     */
    protected static function backfillDates(
        WorkProcess $process,
        Collection $definitions,
        array $clientIds,
        string $competence,
        \DateTimeInterface $now,
    ): void {
        if ($clientIds === [] || $definitions->isEmpty()) {
            return;
        }

        $definitionsById = $definitions->keyBy('id');

        $undated = WorkTask::query()
            ->where('work_tasks.work_process_id', $process->id)
            ->where('work_tasks.competence', $competence)
            ->whereNull('work_tasks.due_on')
            ->whereIn('work_tasks.client_id', $clientIds)
            ->whereNotNull('work_tasks.work_process_task_definition_id')
            ->get(['work_tasks.id', 'work_tasks.work_process_task_definition_id']);

        if ($undated->isEmpty()) {
            return;
        }

        $idsByDefinition = [];

        foreach ($undated as $row) {
            $idsByDefinition[(int) $row->work_process_task_definition_id][] = (int) $row->id;
        }

        foreach ($idsByDefinition as $definitionId => $ids) {
            $definition = $definitionsById->get($definitionId);

            if (! $definition instanceof WorkProcessTaskDefinition) {
                continue;
            }

            $dates = WorkDueDates::forTask($process, $definition, $competence);

            if ($dates['due_on'] === null) {
                continue;
            }

            WorkTask::query()->whereIn('id', $ids)->update([
                'due_on' => $dates['due_on'],
                'updated_at' => $now,
            ]);
        }
    }
}
