<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Minimal model for Task 3 (full model with relations comes in plan Task 2.1).
 *
 * @property int $id
 * @property int $account_id
 * @property string $cnpj
 * @property string $razao_social
 * @property string $regime
 * @property string $contador_responsavel
 */
#[Fillable(['account_id', 'cnpj', 'razao_social', 'regime', 'contador_responsavel'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * @return HasOne<ClientCredential, $this>
     */
    public function credential(): HasOne
    {
        return $this->hasOne(ClientCredential::class);
    }

    /**
     * @return HasMany<FiscalSyncSubscription, $this>
     */
    public function syncSubscriptions(): HasMany
    {
        return $this->hasMany(FiscalSyncSubscription::class);
    }

    /**
     * @return HasMany<FiscalSyncCursor, $this>
     */
    public function syncCursors(): HasMany
    {
        return $this->hasMany(FiscalSyncCursor::class);
    }

    /**
     * @return HasMany<FiscalDocument, $this>
     */
    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }
}
