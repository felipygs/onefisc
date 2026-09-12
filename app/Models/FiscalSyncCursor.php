<?php

namespace App\Models;

use App\Concerns\BelongsToAccountThroughClient;
use Database\Factories\FiscalSyncCursorFactory;
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
 * @property string $last_nsu
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'family', 'environment', 'last_nsu'])]
class FiscalSyncCursor extends Model
{
    /** @use HasFactory<FiscalSyncCursorFactory> */
    use BelongsToAccountThroughClient, HasFactory;

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
