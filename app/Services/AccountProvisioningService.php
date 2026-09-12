<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Invitation;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AccountProvisioningService
{
    /**
     * Create an account B on the default plan with its initial admin invitation.
     *
     * @return array{account: Account, invitation: Invitation, token: string}
     */
    public function createForAdmin(string $accountName, string $adminName, string $adminEmail): array
    {
        Gate::authorize('manage-platform');

        return DB::transaction(function () use ($accountName, $adminName, $adminEmail): array {
            $account = Account::create([
                'name' => $accountName,
                'profile' => 'B',
                'plan_id' => Plan::default()?->id,
            ]);

            $result = app(InvitationService::class)->invite($account, $adminName, $adminEmail, 'admin');

            return ['account' => $account, 'invitation' => $result['invitation'], 'token' => $result['token']];
        });
    }
}
