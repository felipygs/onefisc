<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\AccountProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-platform');

        $accounts = Account::query()->with('plan')->orderBy('name')->paginate(15);

        return Inertia::render('admin/Accounts/Index', [
            'accounts' => $accounts,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('manage-platform');

        return Inertia::render('admin/Accounts/Create');
    }
    /**
     * Create an account B with its initial admin invitation (platform only).
     */
    public function store(Request $request, AccountProvisioningService $provisioning): RedirectResponse
    {
        Gate::authorize('manage-platform');

        $validated = $request->validate([
            'account_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
        ]);

        /** @var string $accountName */
        $accountName = $validated['account_name'];
        /** @var string $adminName */
        $adminName = $validated['admin_name'];
        /** @var string $adminEmail */
        $adminEmail = $validated['admin_email'];

        $provisioning->createForAdmin($accountName, $adminName, $adminEmail);

        return back()->with('status', 'Account criada e convite enviado.');
    }

    /**
     * Switch an account to another plan (platform only, Account A).
     */
    public function updatePlan(Request $request, Account $account): RedirectResponse
    {
        Gate::authorize('manage-platform');

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        /** @var int $planId */
        $planId = $validated['plan_id'];

        $account->update(['plan_id' => $planId]);

        return back()->with('status', 'Plan atualizado.');
    }
}
