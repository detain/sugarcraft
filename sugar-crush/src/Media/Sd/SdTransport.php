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
 * implementation dials its OWN egress path, guarded by UrlGuard's configured-
 * origin pin — it MUST NOT reuse WebFetch's dialer. Per W0 finding ⚠3 / mystage
 * fact (c), WebFetch's FetchTarget SSRF blocklist refuses LAN/private hosts,
 * which is exactly where a configured SD server legitimately lives; the operator
 * who set sd.baseUrl already made the trust decision, so the guard prevents only
 * drift off that origin, never address-class screening.
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
     * @param  ?float  $totalTimeoutSeconds  WALL-CLOCK TOTAL bound for THIS
     *      request only, honoured by implementations that can bound (the
     *      discovery GET ladder passes CapabilityDiscoverer's budget). NULL —
     *      the generation-POST shape — keeps the E646 law intact: connect-
     *      bounded egress, never a total ceiling, because a loaded GPU
     *      legitimately renders minutes past any flat timeout (mirrors the
     *      sanctioned SglangServerInfo discovery-GET exception, src/Providers).
     */
    public function request(string $method, string $path, array $json = [], array $query = [], ?float $totalTimeoutSeconds = null): SdTransportResult;
}
