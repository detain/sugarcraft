<?php

declare(strict_types=1);

namespace SugarCraft\McpClient;

/**
 * Builds the wire line for an outgoing JSON-RPC request, with the
 * empty-params shim in ONE place.
 *
 * WHY THE SHIM. PHP cannot tell an empty map from an empty list, and
 * `json_encode([])` is `[]`; strict peers reject `"params":[]` (object
 * expected). An empty array therefore leaves as `{}`. A caller-supplied
 * \stdClass is passed through untouched — that is how a relay (the workspace
 * gateway) forwards the client's own `{}` byte-for-byte while non-empty
 * params keep their shape. Unlike {@see McpMessage::request()}, which
 * shims only an empty `array` and cannot carry a \stdClass, this accepts both.
 */
final class McpCall
{
    private function __construct()
    {
    }

    /**
     * @param array<string,mixed>|\stdClass $params
     * @param int $extraFlags forwarded to {@see JsonLines::encode()}
     *
     * @throws \JsonException when the envelope cannot be encoded
     */
    public static function request(string|int $id, string $method, array|\stdClass $params = [], int $extraFlags = 0): string
    {
        return JsonLines::encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ], $extraFlags);
    }
}
