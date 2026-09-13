<?php

namespace App\Concerns;

use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToAccountThroughProcess
{
    public static function bootBelongsToAccountThroughProcess(): void
    {
        static::addGlobalScope('account', function (Builder $builder) {
            if ($account = CurrentAccount::resolve()) {
                $builder->whereHas('process', function (Builder $query) use ($account) {
                    $query->where($query->getModel()->getTable().'.account_id', $account->id);
                });
            }
        });
    }
}
