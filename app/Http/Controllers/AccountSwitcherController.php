<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountSwitcherController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-platform');

        return Inertia::render('admin/Switcher/Index', [
            'accounts' => Account::query()->with('plan')->orderBy('name')->paginate(15),
        ]);
    }

    public function select(Account $account, AuditService $audit): RedirectResponse
    {
        Gate::authorize('manage-platform');

        session()->put('switch_account_id', $account->id);

        $audit->record(
            action: 'switcher.enter',
            targetAccountId: $account->id,
            metadata: ['account_name' => $account->name]
        );

        return redirect()->route('dashboard')->with('status', "Atuando como {$account->name}.");
    }

    public function destroy(AuditService $audit): RedirectResponse
    {
        Gate::authorize('manage-platform');

        $targetId = session()->pull('switch_account_id');

        if ($targetId) {
            $audit->record(
                action: 'switcher.exit',
                targetAccountId: (int) $targetId,
                metadata: []
            );
        }

        return redirect()->route('dashboard')->with('status', 'De volta à Account de origem.');
    }
}
