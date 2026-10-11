<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

/**
 * Widening seam over {@see HeaderAwareSdTransport} for the routes the live
 * servers post as MULTIPART FORMS rather than JSON bodies (plan W7 sglang
 * adapter; the W0.1 openapi capture proves `POST /v1/videos`,
 * `POST /v1/images/edits` and friends take `multipart/form-data` — FastAPI's
 * shape for file-bearing requests).
 *
 * The same capability law the header seam was cut under (lane a, §4.7): the
 * generic JSON seam stays frozen, form neediness is stated as a capability,
 * tested for with instanceof at the call site, and answered with an honest
 * protocol error on transports that do not speak it — never smuggled through
 * the JSON array.
 *
 * Fields are stringified by the implementation (bools spell `true`/`false`);
 * nulls must already be stripped by the caller (Client::stripExplicitNulls),
 * and non-scalar values are refused rather than JSON-embedded — a form part
 * is a string or a file, anything else is a caller bug.
 */
interface MultipartSdTransport extends HeaderAwareSdTransport
{
    /**
     * @param array<string, scalar|\BackedEnum>  $fields  form fields; enums are sent as their ->value
     * @param list<array{name: string, filename: string, contents: string}>  $files  binary parts (name = form field, contents = raw bytes)
     * @param ?float $totalTimeoutSeconds per-request total bound, NULL for
     *      generation POSTs — see {@see SdTransport::request()} for the E646 law.
     */
    public function requestMultipart(
        string $method,
        string $path,
        array $fields = [],
        array $files = [],
        ?float $totalTimeoutSeconds = null,
    ): SdTransportResult;
}
