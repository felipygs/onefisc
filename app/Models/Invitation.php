<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\CarbonInterface;

/**
 * Minimal model for Task 3 (full model with relations comes in plan Task 2.1).
 *
 * @property int $id
 * @property int $account_id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $accepted_at
 */
#[Fillable(['account_id', 'name', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
