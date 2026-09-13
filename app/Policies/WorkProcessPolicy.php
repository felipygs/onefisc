<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkProcess;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\DB;

class WorkProcessPolicy
{
    /**
     * Roles allowed to manage processes, associations and marketplace installs.
     *
     * @var list<string>
     */
    public const MANAGING_ROLES = ['super_admin', 'admin', 'operador'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, WorkProcess $process): bool
    {
        if (! $this->isMember($user, $process->account_id)) {
            return false;
        }

        if ($this->manages($user)) {
            return true;
        }

        return $this->hasAssignedClient($user, $process->id);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user) && $this->manages($user);
    }

    public function update(User $user, WorkProcess $process): bool
    {
        return $this->isMember($user, $process->account_id) && $this->manages($user);
    }

    public function delete(User $user, WorkProcess $process): bool
    {
        return $this->update($user, $process);
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

    protected function hasAssignedClient(User $user, int $processId): bool
    {
        return DB::table('work_process_clients as wpc')
            ->join('client_user as cu', 'cu.client_id', '=', 'wpc.client_id')
            ->where('wpc.work_process_id', $processId)
            ->where('cu.user_id', $user->id)
            ->exists();
    }
}
