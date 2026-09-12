<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\MonitoringCheck;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PlanLimitService
{
    public function planFor(Account $account): ?Plan
    {
        if ($account->relationLoaded('plan')) {
            return $account->plan;
        }

        return $account->plan()->first();
    }

    /**
     * @return array{users: array{used: int, max: int|null}, clients: array{used: int, max: int|null}, volume: array{used: int, max: int|null, remaining: int|null}, modules: array<int, string>}
     */
    public function usage(Account $account): array
    {
        $plan = $this->planFor($account);

        $users = User::query()->withoutGlobalScopes()->where('account_id', $account->id)->count();
        $clients = Client::query()->withoutGlobalScopes()->where('account_id', $account->id)->count();
        $volumeUsed = $this->volumeConsumed($account);

        return [
            'users' => ['used' => $users, 'max' => $plan?->max_users],
            'clients' => ['used' => $clients, 'max' => $plan?->max_clients],
            'volume' => [
                'used' => $volumeUsed,
                'max' => $plan?->monthly_query_volume,
                'remaining' => $plan ? max(0, $plan->monthly_query_volume - $volumeUsed) : null,
            ],
            'modules' => $plan->modules ?? [],
        ];
    }

    public function ensureUserCapacity(Account $account): void
    {
        $plan = $this->planFor($account);

        if (! $plan) {
            return;
        }

        $users = User::query()->withoutGlobalScopes()->where('account_id', $account->id)->count();
        $pending = Invitation::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->count();

        if ($users + $pending >= $plan->max_users) {
            throw ValidationException::withMessages([
                'limit' => 'Limite de usuários do plano atingido. Solicite upgrade.',
            ]);
        }
    }

    public function ensureClientCapacity(Account $account): void
    {
        $plan = $this->planFor($account);

        if (! $plan) {
            return;
        }

        $clients = Client::query()->withoutGlobalScopes()->where('account_id', $account->id)->count();

        if ($clients >= $plan->max_clients) {
            throw ValidationException::withMessages([
                'limit' => 'Limite de clients do plano atingido. Solicite upgrade.',
            ]);
        }
    }

    public function ensureModule(Account $account, string $module): void
    {
        $plan = $this->planFor($account);

        if (! $plan) {
            return;
        }

        /** @var array<int, string> $modules */
        $modules = $plan->modules ?? [];

        if (! in_array($module, $modules, true)) {
            throw ValidationException::withMessages([
                'limit' => "Módulo {$module} não liberado no plano atual. Solicite upgrade.",
            ]);
        }
    }

    public function ensureVolume(Account $account): void
    {
        $plan = $this->planFor($account);

        if (! $plan) {
            return;
        }

        if ($this->volumeConsumed($account) >= $plan->monthly_query_volume) {
            throw ValidationException::withMessages([
                'limit' => 'Volume mensal de consultas esgotado. Solicite upgrade ou aguarde renovação.',
            ]);
        }
    }

    public function volumeConsumed(Account $account): int
    {
        if (! Schema::hasTable('monitoring_checks')) {
            return 0;
        }

        return MonitoringCheck::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
