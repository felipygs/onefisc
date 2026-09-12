<?php

namespace App\Models;

use App\Concerns\BelongsToAccountThroughClient;
use Database\Factories\FiscalDocumentActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $fiscal_document_id
 * @property string $type
 * @property int|null $actor_user_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['fiscal_document_id', 'type', 'actor_user_id', 'metadata'])]
class FiscalDocumentAction extends Model
{
    /** @use HasFactory<FiscalDocumentActionFactory> */
    use BelongsToAccountThroughClient, HasFactory;

    protected function accountClientRelation(): string
    {
        return 'fiscalDocument.client';
    }

    /**
     * @return BelongsTo<FiscalDocument, $this>
     */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
