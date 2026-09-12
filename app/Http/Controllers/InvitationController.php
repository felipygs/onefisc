<?php

namespace App\Http\Controllers;

use App\Services\InvitationService;
use App\Support\CurrentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    /**
     * Invite a user to the current account.
     */
    public function store(Request $request, InvitationService $invitations): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && $request->user()?->can('manage-users', $account), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:admin,operador,user'],
        ]);

        $invitations->invite($account, $validated['name'], $validated['email'], $validated['role']);

        return back()->with('status', 'Convite enviado.');
    }

    /**
     * Accept an invitation setting the password.
     */
    public function accept(Request $request, InvitationService $invitations, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        /** @var string $password */
        $password = $validated['password'];
        /** @var string $confirmation */
        $confirmation = $request->input('password_confirmation');

        $invitations->accept($token, $password, $confirmation);

        return redirect('/dashboard');
    }
}
