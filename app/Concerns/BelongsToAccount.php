<?php

namespace App\Concerns;

use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope('account', function (Builder $builder) {
            if ($account = CurrentAccount::resolve()) {
                $builder->where($builder->getModel()->getTable().'.account_id', $account->id);
            }
        });

        static::creating(function (Model $model) {
            if (! $model->getAttribute('account_id') && ($account = CurrentAccount::resolve())) {
                $model->setAttribute('account_id', $account->id);
            }
        });
    }
}
