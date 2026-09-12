<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal model for Task 3 (full model with relations comes in plan Task 2.1).
 *
 * Table has created_at only (no updated_at).
 *
 * @property int $id
 * @property int $actor_user_id
 * @property int $origin_account_id
 * @property int|null $target_account_id
 * @property string $action
 */
#[Fillable(['actor_user_id', 'origin_account_id', 'target_account_id', 'action', 'metadata'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;
}
