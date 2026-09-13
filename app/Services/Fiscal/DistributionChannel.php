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
     * Fetch the next incremental batch after the given marker.
     *
     * For the nfe/cte families the marker is the SEFAZ NSU. For the nfse
     * family the marker is the ADN NSU (the portal fallback ignores it: its
     * content is key-addressed and the runner keeps the ADN NSU as the
     * cursor of record, so the portal never pollutes the cursor).
     */
    public function fetchSince(string $lastNsu): ChannelBatch;

    /**
     * Look up a single document by its access key (44 digits for nfe/cte,
     * 50 digits for the national NFS-e).
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
