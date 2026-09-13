<?php

namespace App\Services\Fiscal;

/**
 * Outcome of one FiscalSyncRunner cycle (cursor mechanics + auto-ciencia
 * with pending persistence per task 3.3; XML bytes land in task 4.1).
 */
final readonly class SyncResult
{
    /**
     * @param  'synced'|'empty'|'paused'|'suspended'|'limited'|'unknown'|'volume_exhausted'  $status
     * @param  int  $fetched  Summaries handled this run (manifested + persisted, or skipped as already known).
     * @param  string  $lastNsu  Cursor value after this run.
     * @param  string|null  $pause  Pause code when status is paused.
     */
    public function __construct(
        public string $status,
        public int $fetched = 0,
        public string $lastNsu = '0',
        public ?string $pause = null,
    ) {}
}
