<?php

namespace App\Models;

use App\Concerns\BelongsToAccountThroughClient;
use Database\Factories\FiscalSyncSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property string $family
 * @property string $environment
 * @property Carbon|null $next_run_at
 * @property Carbon|null $blocked_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'family', 'environment', 'next_run_at', 'blocked_until'])]
class FiscalSyncSubscription extends Model
{
    /** @use HasFactory<FiscalSyncSubscriptionFactory> */
    use BelongsToAccountThroughClient, HasFactory;

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'blocked_until' => 'datetime',
        ];
    }
}
