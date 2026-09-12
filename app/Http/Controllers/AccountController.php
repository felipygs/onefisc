<?php

namespace App\Http\Controllers;

use App\Services\AccountProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * Create an account B with its initial admin invitation (platform only).
     */
    public function store(Request $request, AccountProvisioningService $provisioning): RedirectResponse
    {
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
}
