<?php

namespace App\Support;

use App\Models\WorkProcess;
use App\Models\WorkProcessTaskDefinition;
use Illuminate\Support\Carbon;

/**
 * Due-date engine (Task 3.2). Single pure implementation used by both
 * materialization paths (2.2 association-apply and 3.1 competence-open).
 *
 * - `fixed_day` + day D: offset `due_month` ⇒ due = competence-month day D;
 *   offset `previous_month` ⇒ due = NEXT-month day D (Sept ⇒ Oct 20). D is
 *   clamped to the last day of the target month (31→Apr 30, Feb 2026→28,
 *   Feb 2024→29 leap). Null day ⇒ null dates.
 * - `estimated` ⇒ due = competence-month-01 + N days (null/0 ⇒ null dates).
 * - `target_date` = due − lead days when both present, else null.
 * - Missing/unrecognized `due_mode`, missing/invalid competence ⇒ nulls,
 *   never throws (except the model wrapper never throws either).
 */
class WorkDueDates
{
    /**
     * @return array{due_on: ?string, target_date: ?string}
     */
    public static function compute(
        ?string $dueMode,
        ?int $dueDay,
        ?int $estimatedDurationDays,
        ?string $competenceOffset,
        ?int $targetLeadDays,
        ?string $competence,
    ): array {
        $nulls = ['due_on' => null, 'target_date' => null];

        if ($competence === null || $competence === '' || ! WorkCompetence::isValid($competence)) {
            return $nulls;
        }

        try {
            $base = Carbon::parse($competence.'-01')->startOfMonth();
        } catch (\Throwable) {
            return $nulls;
        }

        $due = null;

        if ($dueMode === 'fixed_day') {
            if ($dueDay === null || $dueDay < 1 || $dueDay > 31) {
                return $nulls;
            }

            $target = $base->copy()->startOfMonth();

            if ($competenceOffset === 'previous_month') {
                $target->addMonthNoOverflow();
            }

            $day = min($dueDay, $target->daysInMonth);
            $due = $target->day($day);
        } elseif ($dueMode === 'estimated') {
            if ($estimatedDurationDays === null || $estimatedDurationDays <= 0) {
                return $nulls;
            }

            $due = $base->copy()->startOfMonth()->addDays($estimatedDurationDays);
        } else {
            return $nulls;
        }

        $dueOn = $due->format('Y-m-d');

        if ($targetLeadDays === null || $targetLeadDays < 0) {
            return ['due_on' => $dueOn, 'target_date' => null];
        }

        return [
            'due_on' => $dueOn,
            'target_date' => $due->copy()->subDays($targetLeadDays)->format('Y-m-d'),
        ];
    }

    /**
     * Model wrapper: effective day/offset prefer the definition when set and
     * fall back to the process; mode/duration/lead always come from the
     * process. Null competence (timeless) stays dateless.
     *
     * @return array{due_on: ?string, target_date: ?string}
     */
    public static function forTask(
        WorkProcess $process,
        ?WorkProcessTaskDefinition $definition,
        ?string $competence,
    ): array {
        $dueDay = $process->due_day;
        $offset = $process->competence_offset;

        if ($definition !== null) {
            if ($definition->due_day !== null) {
                $dueDay = $definition->due_day;
            }

            if ($definition->competence_offset !== null) {
                $offset = $definition->competence_offset;
            }
        }

        return self::compute(
            $process->due_mode,
            $dueDay !== null ? (int) $dueDay : null,
            $process->estimated_duration_days !== null ? (int) $process->estimated_duration_days : null,
            $offset,
            $process->target_lead_days !== null ? (int) $process->target_lead_days : null,
            $competence,
        );
    }
}
