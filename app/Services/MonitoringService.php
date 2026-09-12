<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringCheck;
use Illuminate\Validation\ValidationException;

class MonitoringService
{
    public function __construct(protected PlanLimitService $limits) {}

    public function consumed(Account $account): int
    {
        return $this->limits->volumeConsumed($account);
    }

    public function remaining(Account $account): ?int
    {
        $plan = $this->limits->planFor($account);

        if (! $plan) {
            return null;
        }

        return max(0, $plan->monthly_query_volume - $this->consumed($account));
    }

    /**
     * Run a scheduled check for a client, counting toward the plan volume.
     *
     * @throws ValidationException when the module is locked or volume is exhausted.
     */
    public function run(Client $client): MonitoringCheck
    {
        /** @var Account $account */
        $account = Account::query()->withoutGlobalScopes()->findOrFail($client->account_id);

        $this->limits->ensureModule($account, 'monitoring');
        $this->limits->ensureVolume($account);

        return MonitoringCheck::withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'status' => 'ok',
        ]);
    }
}
