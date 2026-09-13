<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\AccountDepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $account_id
 * @property string $name
 */
#[Fillable(['account_id', 'name'])]
class AccountDepartment extends Model
{
    /** @use HasFactory<AccountDepartmentFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<WorkProcessTaskDefinition, $this>
     */
    public function taskDefinitions(): HasMany
    {
        return $this->hasMany(WorkProcessTaskDefinition::class, 'department_id');
    }

    /**
     * @return HasMany<WorkTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkTask::class, 'department_id');
    }
}
