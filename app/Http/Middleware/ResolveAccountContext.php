<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\User;
use App\Support\CurrentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAccountContext
{
    /**
     * Resolve the effective account: the switch target for super_admin,
     * otherwise the user's own account.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $account = null;

            $switchId = $request->session()->get('switch_account_id');

            if ($switchId && $user->isSuperAdmin()) {
                $account = Account::find($switchId);
            }

            $account ??= $user->account;

            if ($account instanceof Account) {
                CurrentAccount::set($account);
            }
        }

        return $next($request);
    }
}
