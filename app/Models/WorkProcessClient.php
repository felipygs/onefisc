<?php

namespace App\Models;

use App\Concerns\BelongsToAccountThroughProcess;
use Database\Factories\WorkProcessClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Account isolation is enforced via the parent process: direct queries carry a
 * global scope filtering work_processes.account_id to the current account.
 *
 * @property int $id
 * @property int $work_process_id
 * @property int $client_id
 */
#[Fillable(['work_process_id', 'client_id'])]
class WorkProcessClient extends Model
{
    /** @use HasFactory<WorkProcessClientFactory> */
    use BelongsToAccountThroughProcess, HasFactory;

    /**
     * @return BelongsTo<WorkProcess, $this>
     */
    public function process(): BelongsTo
    {
        return $this->belongsTo(WorkProcess::class, 'work_process_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
