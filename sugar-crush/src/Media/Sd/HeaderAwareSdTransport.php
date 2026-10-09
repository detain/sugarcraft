<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

/**
 * Widening seam over {@see SdTransport} for the handful of sdapi routes that
 * read a real HTTP HEADER (plan W1.3: the two-stage interrupt's
 * `interrupt_after_current`, §4.7). The generic seam stays header-free —
 * lane a froze it that way and every other route is pure JSON/query — so
 * header neediness is stated as a capability, tested for at the one call
 * site, and an honest protocol error elsewhere, instead of smuggling header
 * text through the query array.
 */
interface HeaderAwareSdTransport extends SdTransport
{
    /**
     * @param array<string, mixed>   $json    request body (JSON-encoded by the implementation)
     * @param array<string, scalar>  $query   query-string parameters
     * @param array<string, string>  $headers extra request headers
     */
    public function requestWithHeaders(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        array $headers = [],
    ): SdTransportResult;
}
