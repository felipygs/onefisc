<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkTask;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WorkTaskPolicy
{
    /**
     * Roles allowed to see and mutate tasks of every client in the Account.
     * Collaborators (`user`) stay limited to clients assigned via client_user.
     *
     * @var list<string>
     */
    public const MANAGING_ROLES = ['super_admin', 'admin', 'operador'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, WorkTask $task): bool
    {
        return $this->canReach($user, $task);
    }

    public function create(User $user): bool
    {
        // Membership only: the store request narrows `user` collaborators to
        // clients assigned to them, since create carries no model yet.
        return $this->isMember($user);
    }

    public function update(User $user, WorkTask $task): bool
    {
        return $this->canReach($user, $task);
    }

    public function delete(User $user, WorkTask $task): bool
    {
        return $this->canReach($user, $task);
    }

    public function move(User $user, WorkTask $task): bool
    {
        return $this->canReach($user, $task);
    }

    /**
     * Narrow a task query to rows the user may reach: managers see the whole
     * Account, collaborators only tasks whose client is assigned to them via
     * client_user. Resolving through this scope keeps foreign ids answering
     * 404 instead of leaking a 403 from the policy.
     *
     * @param  Builder<WorkTask>  $query
     * @return Builder<WorkTask>
     */
    public static function scopeVisible(Builder $query, ?User $user): Builder
    {
        if ($user instanceof User && ! in_array($user->role, self::MANAGING_ROLES, true)) {
            $query->whereExists(function ($exists) use ($user): void {
                $exists->select(DB::raw('1'))
                    ->from('client_user as cu')
                    ->whereColumn('cu.client_id', 'work_tasks.client_id')
                    ->where('cu.user_id', $user->id);
            });
        }

        return $query;
    }

    protected function canReach(User $user, WorkTask $task): bool
    {
        if (! $this->isMember($user, $task->account_id)) {
            return false;
        }

        if ($this->manages($user)) {
            return true;
        }

        return $this->isAssignedToClient($user, $task->client_id);
    }

    protected function isMember(User $user, ?int $accountId = null): bool
    {
        $accountId ??= CurrentAccount::resolve()?->id;

        return $accountId !== null && (int) $user->account_id === (int) $accountId;
    }

    protected function manages(User $user): bool
    {
        return in_array($user->role, self::MANAGING_ROLES, true);
    }

    protected function isAssignedToClient(User $user, ?int $clientId): bool
    {
        if ($clientId === null) {
            return false;
        }

        return DB::table('client_user')
            ->where('client_id', $clientId)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function isClientAssignee(User $user, int $clientId): bool
    {
        if (! $this->manages($user)) {
            return $this->isAssignedToClient($user, $clientId);
        }

        return true;
    }
}
