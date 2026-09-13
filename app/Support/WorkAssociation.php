<?php

namespace App\Support;

use App\Models\Client;
use App\Models\WorkProcess;
use Illuminate\Support\Facades\DB;

/**
 * Effective association set shared by the 2.2 write path and the catalog
 * preview (Task 4.1). The formula lives here exactly once: regime-and-tag
 * matches ∪ extras − excluded, restricted to the process Account. Empty
 * regimes (or `all`) mean every account Client; an empty tag list means
 * no tag narrowing. Clients have no status column, so every account
 * Client is a candidate.
 */
final class WorkAssociation
{
    /**
     * Full breakdown behind a preview or an apply.
     *
     * @return array{rule: list<int>, extras: list<int>, excluded: list<int>, client_ids: list<int>}
     */
    public static function preview(WorkProcess $process, int $accountId): array
    {
        $regimes = array_values(array_filter(
            (array) ($process->association_regimes ?? []),
            fn ($regime) => $regime !== '' && $regime !== 'all'
        ));

        $tagIds = array_values(array_filter(
            array_map('intval', (array) ($process->association_tag_ids ?? [])),
            fn (int $tagId) => $tagId > 0
        ));

        $matched = Client::query()
            ->where('clients.account_id', $accountId)
            ->when($regimes !== [], fn ($query) => $query->whereIn('clients.regime', $regimes))
            ->when($tagIds !== [], function ($query) use ($tagIds): void {
                $query->whereExists(function ($exists) use ($tagIds): void {
                    $exists->select(DB::raw('1'))
                        ->from('client_tag')
                        ->whereColumn('client_tag.client_id', 'clients.id')
                        ->whereIn('client_tag.client_tag_id', $tagIds);
                });
            })
            ->pluck('clients.id')->map(fn ($id) => (int) $id)->all();

        $extraIds = array_values(array_filter(
            array_map('intval', (array) ($process->extra_client_ids ?? [])),
            fn (int $id) => $id > 0
        ));

        $extras = $extraIds === []
            ? []
            : Client::query()
                ->where('clients.account_id', $accountId)
                ->whereIn('clients.id', $extraIds)
                ->pluck('clients.id')->map(fn ($id) => (int) $id)->all();

        $excluded = array_values(array_filter(
            array_map('intval', (array) ($process->excluded_client_ids ?? [])),
            fn (int $id) => $id > 0
        ));

        $rule = array_values(array_map('intval', $matched));
        sort($rule);
        $extras = array_values(array_map('intval', $extras));
        sort($extras);
        sort($excluded);

        $clientIds = array_values(array_diff(array_unique([...$matched, ...$extras]), $excluded));
        sort($clientIds);

        return [
            'rule' => $rule,
            'extras' => $extras,
            'excluded' => $excluded,
            'client_ids' => $clientIds,
        ];
    }

    /**
     * Effective set for the write path (2.2 semantics preserved).
     *
     * @return list<int>
     */
    public static function effectiveClientIds(WorkProcess $process, int $accountId): array
    {
        return self::preview($process, $accountId)['client_ids'];
    }
}
