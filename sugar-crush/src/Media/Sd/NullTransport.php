<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

use RuntimeException;

/**
 * A transport that is wired but reaches nothing (plan W1.2 default).
 *
 * Used when no provider is configured or a lane deliberately dials discovery
 * down: every request raises, which the fail-open probe ladder converts
 * straight into "no capability" — the throwing shape is what lets
 * CapabilityDiscoverer's error-vs-absence paths share one code path instead
 * of null-checking transports.
 */
final readonly class NullTransport implements SdTransport
{
    public function request(string $method, string $path, array $json = [], array $query = [], ?float $totalTimeoutSeconds = null): SdTransportResult
    {
        throw new RuntimeException("NullTransport: no SD endpoint wired (refused {$method} {$path})");
    }
}
