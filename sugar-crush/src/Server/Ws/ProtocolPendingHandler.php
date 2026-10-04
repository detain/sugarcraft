<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Ws;

/**
 * The handler the transport runs with until the `sugarcrush.v1` dispatcher
 * (O-3b) replaces it: well-formed JSON-RPC 2.0 framing, no methods.
 *
 * It answers so that a client written against the transport sees the protocol
 * it will eventually get rather than silence: a request (any message with an
 * `id`) is refused `-32601` with `data.kind = "not_implemented"`; text that is
 * not JSON is `-32700`; a notification gets no answer, as JSON-RPC requires.
 * Decoding is depth-capped (Appendix O §8.7) and nothing from the client is
 * echoed back except the request `id`.
 */
final class ProtocolPendingHandler implements MessageHandler
{
    public function onOpen(Connection $connection): void
    {
    }

    public function onMessage(Connection $connection, string $payload): void
    {
        try {
            $decoded = \json_decode($payload, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $connection->send(self::error(null, -32700, 'parse error', 'parse_error'));

            return;
        }

        if (!\is_array($decoded) || ($decoded['jsonrpc'] ?? null) !== '2.0' || \array_is_list($decoded)) {
            $connection->send(self::error(null, -32600, 'invalid request', 'invalid_request'));

            return;
        }

        if (!\array_key_exists('id', $decoded)) {
            return;
        }

        $id = $decoded['id'];
        if (!\is_string($id) && !\is_int($id) && $id !== null) {
            $id = null;
        }
        $connection->send(self::error($id, -32601, 'method not found: the sugarcrush.v1 methods are not available on this server yet', 'not_implemented'));
    }

    public function onClose(Connection $connection, int $code): void
    {
    }

    private static function error(string|int|null $id, int $code, string $message, string $kind): string
    {
        // A JSON-RPC error object, not the CLI's `--output-format json`
        // error document; built apart from its envelope for that reason.
        $error = ['code' => $code, 'message' => $message, 'data' => ['kind' => $kind]];

        return (string) \json_encode(
            ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error],
            \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
