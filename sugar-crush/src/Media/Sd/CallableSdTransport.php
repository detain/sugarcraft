<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

use InvalidArgumentException;

/**
 * Adapts a plain callable to the SdTransport seam (plan W1.2).
 *
 * This is the fake-injection door the discovery tests and every later lane's
 * fixtures use — no network, no timers: the callable answers synchronously.
 * It may return either an SdTransportResult or the shorthand map
 * ['status' => int, 'body' => string, 'contentType' => optional string].
 */
final readonly class CallableSdTransport implements SdTransport
{
    private \Closure $responder;

    public function __construct(callable $responder)
    {
        $this->responder = $responder(...);
    }

    public function request(string $method, string $path, array $json = [], array $query = []): SdTransportResult
    {
        $result = ($this->responder)($method, $path, $json, $query);

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
            'CallableSdTransport responder must return SdTransportResult or [status, body, contentType?], got ' . get_debug_type($result)
        );
    }
}
