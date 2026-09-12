<?php

namespace App\Models;

use App\Concerns\BelongsToAccountThroughClient;
use Database\Factories\ClientCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property string|null $pfx_data
 * @property string|null $pfx_password
 * @property string|null $thumbprint
 * @property Carbon|null $expires_at
 * @property string|null $portal_password
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'pfx_data', 'pfx_password', 'thumbprint', 'expires_at', 'portal_password'])]
#[Hidden(['pfx_data', 'pfx_password', 'portal_password'])]
class ClientCredential extends Model
{
    /** @use HasFactory<ClientCredentialFactory> */
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
            'expires_at' => 'datetime',
        ];
    }
}
