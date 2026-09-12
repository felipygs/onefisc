<?php

namespace App\Models;

use App\Concerns\BelongsToAccountThroughClient;
use Database\Factories\FiscalCoverageEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property string $family
 * @property string|null $ibge_code
 * @property string $status
 * @property string $reason
 * @property array<string, mixed>|null $evidence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'family', 'ibge_code', 'status', 'reason', 'evidence'])]
class FiscalCoverageEvidence extends Model
{
    /** @use HasFactory<FiscalCoverageEvidenceFactory> */
    use BelongsToAccountThroughClient, HasFactory;

    /**
     * @var string
     */
    protected $table = 'fiscal_coverage_evidence';

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
            'evidence' => 'array',
        ];
    }
}
