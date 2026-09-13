<?php

namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Transient NFS-e transport failure (5xx, timeout, throttling, rejected
 * credentials, unparseable envelope).
 *
 * Never a coverage verdict: for the nfse family the runner answers by trying
 * the portal fallback, and records `unknown` (retry next cycle) when both
 * channels are down. Carries reason codes only, never secrets.
 */
final class NfseTransportException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $evidence  Safe codes only, never secrets.
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $evidence = [],
    ) {
        parent::__construct("Canal NFS-e indisponivel: {$reason}.");
    }
}
