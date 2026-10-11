<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

use InvalidArgumentException;

/**
 * Test-side twin of {@see CallableSdTransport} that also speaks the multipart
 * capability (plan W7 sglang adapter). It exists as a SEPARATE class — not an
 * extra interface on the plain fake — so fakes that must stay
 * NOT header-capable (the Client interrupt refusal pin drives the plain
 * transport for exactly that reason) keep their shape.
 *
 * ONE responder drives all three methods positionally:
 *   request()            → ($method, $path, $json,   $query, $timeout)
 *   requestWithHeaders() → ($method, $path, $json,   $query, $timeout, $headers)
 *   requestMultipart()   → ($method, $path, $fields, $query, $timeout, $fields, $files)
 *
 * A multipart POST keeps the plain HTTP verb ('POST', never a 'MULTIPART POST'
 * label) and the fields map rides the 3rd — body — position as well as the 6th,
 * so the JSON-shaped fakes the W5/W6 suites already script (3 params, keyed by
 * "POST /v1/videos") observe the submitted fields naturally and ignore the
 * extras. It may return an SdTransportResult or the shorthand map
 * ['status' => int, 'body' => string, 'contentType' => optional string].
 */
final readonly class CallableMultipartSdTransport implements MultipartSdTransport
{
    private \Closure $responder;

    public function __construct(callable $responder)
    {
        $this->responder = $responder(...);
    }

    public function request(string $method, string $path, array $json = [], array $query = [], ?float $totalTimeoutSeconds = null): SdTransportResult
    {
        return $this->answer(($this->responder)($method, $path, $json, $query, $totalTimeoutSeconds));
    }

    public function requestWithHeaders(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        array $headers = [],
        ?float $totalTimeoutSeconds = null,
    ): SdTransportResult {
        return $this->answer(($this->responder)($method, $path, $json, $query, $totalTimeoutSeconds, $headers));
    }

    public function requestMultipart(
        string $method,
        string $path,
        array $fields = [],
        array $files = [],
        ?float $totalTimeoutSeconds = null,
    ): SdTransportResult {
        return $this->answer(($this->responder)($method, $path, $fields, [], $totalTimeoutSeconds, $fields, $files));
    }

    private function answer(mixed $result): SdTransportResult
    {
        if ($result instanceof SdTransportResult) {
            return $result;
        }
        if (is_array($result) && array_key_exists('status', $result) && array_key_exists('body', $result)) {
            /** @var int $status */
            $status = $result['status'];
            /** @var string $body */
            $body = $result['body'];

            return SdTransportResult::new($status, $body, (string) ($result['contentType'] ?? 'application/json'));
        }

        throw new InvalidArgumentException(
            'CallableMultipartSdTransport responder must return SdTransportResult or [status, body, contentType?], got ' . get_debug_type($result)
        );
    }
}
