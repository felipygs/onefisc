<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /**
     * Record an audit event. Never throws: audit is best-effort.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $action, ?int $targetAccountId = null, array $metadata = []): ?AuditLog
    {
        try {
            $actor = Auth::user();

            if (! $actor) {
                return null;
            }

            $originAccountId = $actor->account_id;

            if (! $originAccountId) {
                return null;
            }

            $effective = CurrentAccount::resolve();
            $target = $targetAccountId ?? $effective?->id;

            return AuditLog::withoutGlobalScopes()->create([
                'actor_user_id' => $actor->id,
                'origin_account_id' => $originAccountId,
                'target_account_id' => $target,
                'action' => $action,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('audit.record_failed', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Record a system-actor event (queue/runner context, no authenticated
     * user): actor NULL, origin and target both the given Account. Never
     * throws: audit is best-effort.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordSystem(string $action, int $accountId, array $metadata = []): ?AuditLog
    {
        try {
            return AuditLog::withoutGlobalScopes()->create([
                'actor_user_id' => null,
                'origin_account_id' => $accountId,
                'target_account_id' => $accountId,
                'action' => $action,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('audit.record_failed', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
