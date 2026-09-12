<?php

namespace App\Concerns;

use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;

/**
 * Account isolation for tables WITHOUT an account_id column.
 *
 * The account is derived through the owning Client (or, for models like
 * FiscalDocumentAction, through a dotted relation path such as
 * 'fiscalDocument.client'). Mirrors BelongsToAccount's read scope, but
 * there is no account_id to auto-fill on creating: the client link is
 * always explicit, so no creating hook is registered.
 */
trait BelongsToAccountThroughClient
{
    /**
     * Relation path from this model to the owning Client.
     */
    protected function accountClientRelation(): string
    {
        return 'client';
    }

    public static function bootBelongsToAccountThroughClient(): void
    {
        static::addGlobalScope('account', function (Builder $builder) {
            if ($account = CurrentAccount::resolve()) {
                $relation = $builder->getModel()->accountClientRelation();
                $builder->whereHas($relation, fn (Builder $query) => $query->where(
                    $query->getModel()->getTable().'.account_id', $account->id
                ));
            }
        });
    }
}
