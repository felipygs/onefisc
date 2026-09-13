<?php

namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Honest NFS-e coverage signal from a channel.
 *
 * `limited` is terminal: the municipality provably does not adhere to the
 * national standard (or the portal demands a captcha the v1 has no solver
 * for), so the runner records evidence and never reconsults automatically.
 * `unknown` is ambiguous: nothing proves missing coverage, so the next cycle
 * retries normally. The evidence payload carries channel/status codes only:
 * secrets (PFX, portal password) MUST never be placed here.
 */
final class NfseCoverageException extends RuntimeException
{
    /**
     * @param  'limited'|'unknown'  $status
     * @param  array<string, mixed>  $evidence  Safe codes only, never secrets.
     */
    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly array $evidence = [],
    ) {
        parent::__construct("Cobertura NFS-e {$status}: {$reason}.");
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function limited(string $reason, array $evidence = []): self
    {
        return new self('limited', $reason, $evidence);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function unknown(string $reason, array $evidence = []): self
    {
        return new self('unknown', $reason, $evidence);
    }
}
