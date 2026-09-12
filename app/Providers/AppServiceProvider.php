<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\MonitoringCheck;
use App\Models\Plan;
use App\Models\User;
use App\Observers\AuditObserver;
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
    }

    /**
     * Define role-based authorization gates.
     */
    protected function defineRoleGates(): void
    {
        Gate::define('manage-users', fn (User $u, Account $a) => $u->account_id === $a->id && in_array($u->role, ['super_admin', 'admin']));
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
