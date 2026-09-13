<?php

namespace App\Models;

use App\Concerns\BelongsToAccountThroughClient;
use Database\Factories\FiscalDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property string $family
 * @property string $doc_type
 * @property string|null $number
 * @property string|null $series
 * @property string|null $key
 * @property bool $derived_from_key
 * @property Carbon|null $emission_at
 * @property string|null $issuer_name
 * @property string|null $issuer_tax_id
 * @property string|null $recipient_name
 * @property string|null $recipient_tax_id
 * @property string|null $status
 * @property string|null $origin
 * @property bool $has_xml
 * @property bool $has_danfe
 * @property string|null $xml_path
 * @property string|null $pdf_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'family', 'doc_type', 'number', 'series', 'key', 'derived_from_key', 'emission_at', 'issuer_name', 'issuer_tax_id', 'recipient_name', 'recipient_tax_id', 'status', 'origin', 'has_xml', 'has_danfe', 'xml_path', 'pdf_path'])]
class FiscalDocument extends Model
{
    /** @use HasFactory<FiscalDocumentFactory> */
    use BelongsToAccountThroughClient, HasFactory;

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<FiscalDocumentAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(FiscalDocumentAction::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'derived_from_key' => 'boolean',
            'emission_at' => 'datetime',
            'has_xml' => 'boolean',
            'has_danfe' => 'boolean',
        ];
    }
}
