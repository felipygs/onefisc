<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Minimal model for Task 1 (full model with relations comes in plan Task 4).
 *
 * @property int $id
 * @property string $name
 * @property int $price_cents
 * @property int $max_users
 * @property int $max_clients
 * @property array<int, string> $modules
 * @property int $monthly_query_volume
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'price_cents', 'max_users', 'max_clients', 'modules', 'monthly_query_volume', 'is_default'])]
class Plan extends Model
{
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
