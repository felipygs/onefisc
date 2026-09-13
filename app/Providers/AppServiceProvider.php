<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\MonitoringCheck;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkProcess;
use App\Observers\AuditObserver;
use App\Policies\WorkProcessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->defineRoleGates();
        $this->registerAuditObservers();
    }

    /**
     * Register the best-effort audit observer on audited aggregates.
     *
     * AuditLog itself is NEVER observed (prevents infinite recursion).
     */
    protected function registerAuditObservers(): void
    {
        Account::observe(AuditObserver::class);
        Plan::observe(AuditObserver::class);
        Client::observe(AuditObserver::class);
        Invitation::observe(AuditObserver::class);
        User::observe(AuditObserver::class);
        MonitoringCheck::observe(AuditObserver::class);
    }

    /**
     * Define role-based authorization gates.
     */
    protected function defineRoleGates(): void
    {
        Gate::policy(WorkProcess::class, WorkProcessPolicy::class);
        Gate::define('manage-users', fn (User $u, Account $a) => $u->account_id === $a->id && in_array($u->role, ['super_admin', 'admin']));
        Gate::define('manage-certificates', fn (User $u, Account $a) => $u->account_id === $a->id && in_array($u->role, ['super_admin', 'admin']));
        $operateClients = fn (User $u, Account $a) => $u->account_id === $a->id && in_array($u->role, ['super_admin', 'admin', 'operador']);
        Gate::define('operate-clients', $operateClients);
        Gate::define('operate', $operateClients);
        Gate::define('manage-platform', fn (User $u) => $u->isSuperAdmin());
        Gate::define('promote-super-admin', fn (User $u, User $t) => $u->isSuperAdmin() && $t->account->profile === 'A');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
