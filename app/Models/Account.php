<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal model for Task 2 (full model with relations comes in plan Task 4).
 *
 * @property int $id
 * @property string $name
 * @property string $profile
 * @property int|null $plan_id
 */
#[Fillable(['name', 'profile', 'plan_id'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;
}
