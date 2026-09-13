<?php

namespace App\Services\Fiscal;

/**
 * Stable internal contract for SEFAZ document distribution.
 *
 * Vendor types never leak through this interface: batches are plain DTOs
 * and documents are plain arrays, so tests fake this interface (never the
 * vendor) and no test ever touches the real SEFAZ.
 */
interface DistributionChannel
{
    /**
     * Fetch the next incremental batch after the given NSU.
     */
    public function fetchSince(string $lastNsu): ChannelBatch;

    /**
     * Look up a single document by its 44-digit access key.
     *
     * @return array<string, mixed>|null Null when the key is unknown at SEFAZ.
     */
    public function fetchByKey(string $key): ?array;

    /**
     * Register automatic Ciencia da Operacao (event 210210) for the given key.
     *
     * True when SEFAZ accepts the event, including the idempotent
     * already-manifested case. CT-e has no ciencia event: the CT-e wrapper
     * always throws LogicException, and callers only manifest the nfe family.
     *
     * @throws \LogicException when the family has no science event (CT-e).
     */
    public function manifestScience(string $key): bool;
}
