<?php

namespace App\Services\Fiscal;

/**
 * One incremental page from a distribution channel.
 */
final readonly class ChannelBatch
{
    /**
     * @param  array<int, array<string, mixed>>  $items  Decoded docZip entries (nsu/schema/xml).
     * @param  string  $lastNsu  Highest confirmed NSU in this batch.
     * @param  string|null  $pause  Pause code when SEFAZ asks for a break (137/656); null otherwise.
     */
    public function __construct(
        public array $items = [],
        public string $lastNsu = '0',
        public ?string $pause = null,
    ) {}
}
