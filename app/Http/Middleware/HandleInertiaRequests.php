<?php

namespace App\Http\Middleware;

use App\Support\CurrentAccount;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'currentAccount' => fn () => CurrentAccount::resolve(),
            'isSwitching' => fn () => CurrentAccount::isSwitching(),
            'permissions' => function () use ($request) {
                $user = $request->user();
                $account = CurrentAccount::resolve();

                if (! $user || ! $account) {
                    return [
                        'manage-users' => false,
                        'manage-clients' => false,
                        'manage-platform' => false,
                        'operate' => false,
                    ];
                }

                return [
                    'manage-users' => $user->can('manage-users', [$account]),
                    'manage-clients' => $user->can('manage-clients', [$account]),
                    'manage-platform' => $user->can('manage-platform'),
                    'operate' => $user->can('operate', [$account]),
                ];
            },
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
