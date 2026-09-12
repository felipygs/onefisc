<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Support\CurrentAccount;
use Inertia\Inertia;
use Inertia\Response;

class MembersController extends Controller
{
    public function index(): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && request()->user()?->can('manage-users', $account), 403);

        return Inertia::render('settings/Members', [
            'members' => User::query()
                ->where('account_id', $account->id)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']),
            'invitations' => Invitation::query()
                ->where('account_id', $account->id)
                ->whereNull('accepted_at')
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'email', 'role', 'expires_at']),
        ]);
    }
}
