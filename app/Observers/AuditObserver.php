<?php

namespace App\Observers;

use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    /**
     * @param  array<string, string>  $actions
     */
    public function __construct(protected AuditService $audit, protected array $actions = [])
    {
        $this->actions = $actions ?: [
            'created' => 'created',
            'updated' => 'updated',
            'deleted' => 'deleted',
        ];
    }

    public function created(Model $model): void
    {
        $this->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted');
    }

    protected function record(Model $model, string $event): void
    {
        $base = $model->getTable();
        $suffix = $this->actions[$event] ?? $event;

        $targetAccountId = null;
        if ($model->getAttribute('account_id') !== null) {
            $targetAccountId = (int) $model->getAttribute('account_id');
        } elseif ($model->getKey() && $model->getTable() === 'accounts') {
            $targetAccountId = (int) $model->getKey();
        }

        $this->audit->record(
            action: "{$base}.{$suffix}",
            targetAccountId: $targetAccountId,
            metadata: ['id' => $model->getKey()]
        );
    }
}
