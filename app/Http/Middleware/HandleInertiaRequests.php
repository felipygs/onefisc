<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\AuditLog;
use App\Services\PlanLimitService;
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
            'switchableAccounts' => function () use ($request) {
                $user = $request->user();

                if (! $user || ! $user->can('manage-platform')) {
                    return null;
                }

                return Account::query()->orderBy('name')->get(['id', 'name'])->values();
            },
            'notifications' => function () {
                $account = CurrentAccount::resolve();

                if (! $account) {
                    return null;
                }

                return AuditLog::query()
                    ->with('actor:id,name')
                    ->where(function ($query) use ($account) {
                        $query->where('origin_account_id', $account->id)
                            ->orWhere('target_account_id', $account->id);
                    })
                    ->latest('id')
                    ->limit(10)
                    ->get()
                    ->map(fn (AuditLog $log): array => [
                        'id' => $log->id,
                        'sender' => ['name' => $log->senderName()],
                        'body' => self::notificationBody($log),
                        'date' => $log->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all();
            },
            'permissions' => function () use ($request) {
                $user = $request->user();
                $account = CurrentAccount::resolve();

                if (! $user || ! $account) {
                    return [
                        'manage-users' => false,
                        'operate-clients' => false,
                        'manage-platform' => false,
                        'operate' => false,
                    ];
                }

                return [
                    'manage-users' => $user->can('manage-users', [$account]),
                    'operate-clients' => $user->can('operate-clients', [$account]),
                    'manage-platform' => $user->can('manage-platform'),
                    'operate' => $user->can('operate', [$account]),
                ];
            },
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'planUsage' => function () {
                $account = CurrentAccount::resolve();

                if (! $account) {
                    return null;
                }

                return app(PlanLimitService::class)->usage($account);
            },
        ];
    }

    protected static function notificationBody(AuditLog $log): string
    {
        $metadata = $log->metadata ?? [];

        return match ($log->action) {
            'switcher.enter' => 'Passou a atuar como '.($metadata['account_name'] ?? 'outra Account').'.',
            'switcher.exit' => 'Encerrou a atuação em outra Account.',
            'clients.store' => 'Cadastrou um Client.',
            'clients.update' => 'Atualizou um Client.',
            'clients.destroy' => 'Removeu um Client.',
            'invitations.store' => 'Enviou um Convite.',
            'invitations.accept' => 'Aceitou um Convite.',
            'plans.update' => 'Trocou o Plan de uma Account.',
            'certificates.store' => ($metadata['replaced'] ?? false)
                ? 'Substituiu o certificado digital do Client.'
                : 'Instalou o certificado digital do Client.',
            'certificates.portal-password' => 'Atualizou a senha do portal do Client.',
            'certificates.destroy' => 'Removeu o certificado digital do Client.',
            'fiscal.science.auto' => 'Registrou ciência automática de um documento fiscal.',
            'fiscal.sync.cycle' => self::syncCycleBody($metadata),
            default => $log->action,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected static function syncCycleBody(array $metadata): string
    {
        return match ($metadata['result'] ?? null) {
            'ok' => match (true) {
                ($metadata['new_documents'] ?? 0) === 0 => 'Sincronização fiscal concluída: nenhum documento novo.',
                ($metadata['new_documents'] ?? 0) === 1 => 'Sincronização fiscal concluída: 1 documento novo.',
                default => 'Sincronização fiscal concluída: '.(int) ($metadata['new_documents'] ?? 0).' documentos novos.',
            },
            'blocked' => 'Sincronização fiscal pausada pela SEFAZ. Nova tentativa na próxima janela.',
            'suspended' => 'Sincronização fiscal suspensa: certificado ausente ou expirado.',
            'volume_exhausted' => 'Sincronização fiscal pausada: volume do Plan esgotado.',
            'failed' => 'Sincronização fiscal falhou. Nova tentativa no próximo ciclo.',
            default => 'Sincronização fiscal executada.',
        };
    }
}
