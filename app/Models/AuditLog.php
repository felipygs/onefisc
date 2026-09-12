<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table has created_at only (no updated_at).
 *
 * @property int $id
 * @property int $actor_user_id
 * @property int $origin_account_id
 * @property int|null $target_account_id
 * @property string $action
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['actor_user_id', 'origin_account_id', 'target_account_id', 'action', 'metadata'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function senderName(): string
    {
        $actor = $this->actor;

        if (! $actor instanceof User) {
            return 'Sistema';
        }

        return $actor->name;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'origin_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'target_account_id');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
