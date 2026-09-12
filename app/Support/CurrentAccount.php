<?php

namespace App\Support;

use App\Models\Account;

class CurrentAccount
{
    public static function set(Account $account): void
    {
        app()->instance('currentAccount', $account);
    }

    public static function resolve(): ?Account
    {
        if (! app()->bound('currentAccount')) {
            return null;
        }

        /** @var Account $account */
        $account = app('currentAccount');

        return $account;
    }

    public static function clear(): void
    {
        app()->forgetInstance('currentAccount');
    }

    public static function isSwitching(): bool
    {
        return session('switch_account_id') !== null;
    }
}
