<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
