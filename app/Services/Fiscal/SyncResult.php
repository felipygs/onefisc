<?php

namespace App\Services\Fiscal;

/**
 * Outcome of one FiscalSyncRunner cycle (channel mechanics only;
 * document persistence and ciencia land in tasks 3.3/4.1).
 */
final readonly class SyncResult
{
    /**
     * @param  'synced'|'empty'|'paused'|'suspended'  $status
     * @param  int  $fetched  Documents seen this run (counted, not yet persisted).
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
