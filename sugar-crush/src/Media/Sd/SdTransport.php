<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

/**
 * The single HTTP seam between crush's media layer and an SD backend.
 *
 * Plan W1.2 (plan_crush_media.md) defines it; W1.3 lands the Guzzle-backed
 * implementation. Keeping the seam an interface from the start matters for
 * two plan laws: capability discovery must be testable with canned fakes and
 * real timers never armed in-suite (§10 E646 lineage), and the W1.3
 * implementation MUST route through the same SSRF dial-guard rules the WebFetch
 * host enforces (W0.2 finding — FetchTarget is the permission-rule host; lane b
 * owns that, not this interface).
 *
 * Contract: $path is server-root-relative (e.g. '/sdapi/v1/txt2img');
 * resolution against the configured base URL, auth headers, redirects and
 * timeouts are the implementation's business; failures may be thrown as any
 * Throwable — every CALLER in this layer is fail-open by contract and catches
 * broadly, so throwing is the correct failure signal here.
 */
interface SdTransport
{
    /**
     * @param  array<string, mixed>  $json  request body (JSON-encoded by the implementation)
     * @param  array<string, scalar>  $query  query-string parameters
     */
    public function request(string $method, string $path, array $json = [], array $query = []): SdTransportResult;
}
