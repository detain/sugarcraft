<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

/**
 * The `sugarcrush.v1` JSON-RPC 2.0 codec (Appendix O §6.1).
 *
 * WHY NOT `SugarCraft\Mcp\McpMessage`. Appendix O names that class as the
 * codec, on the condition that lifting a protocol-neutral one is clean. Its
 * plain {@see \SugarCraft\Crush\McpMessage::parse()} is not: it folds every
 * integer `id` into a string (a response must echo the id exactly as sent),
 * cannot build an error whose id is `null` (the answer to text that does not
 * parse), and decodes without a depth limit (§8.7). Decision D12's
 * id-preserving variant has since landed for the ACP adapter (roadmap 5.9-2):
 * {@see \SugarCraft\Crush\McpMessage::parsePreservingId()} and
 * {@see \SugarCraft\Crush\McpMessage::withWireId()}, which `Acp\AcpServer`
 * speaks through. This codec predates it and stays the `sugarcrush.v1` wire's:
 * it is not a third copy of the MCP envelope — it builds and reads exactly
 * the four shapes this protocol uses and nothing else — and moving
 * {@see decode()} onto the variant is a refactor with no behaviour to gain.
 *
 * Decoding never throws past {@see decode()}: text that is not a request comes
 * back as the {@see RpcError} the caller answers with.
 */
final class JsonRpc
{
    public const VERSION = '2.0';

    /** Appendix O §8.7: nested JSON deeper than this is refused unread. */
    public const MAX_DEPTH = 64;

    private const FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION;

    private function __construct()
    {
    }

    /**
     * One client message, or the error to answer it with. The error's id is
     * the request's when one could be read, else null.
     *
     * @return Request|array{0: RpcError, 1: string|int|null}
     */
    public static function decode(string $text): Request|array
    {
        try {
            $decoded = \json_decode($text, true, self::MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [RpcError::of(ErrorCode::ParseError, 'parse error'), null];
        }

        if (!\is_array($decoded) || $decoded === [] || \array_is_list($decoded) || ($decoded['jsonrpc'] ?? null) !== self::VERSION) {
            return [RpcError::of(ErrorCode::InvalidRequest, 'invalid request: expected a JSON-RPC 2.0 object'), null];
        }

        $hasId = \array_key_exists('id', $decoded);
        $id = $decoded['id'] ?? null;
        if (!\is_string($id) && !\is_int($id) && $id !== null) {
            return [RpcError::of(ErrorCode::InvalidRequest, 'invalid request: id must be a string, an integer or null'), null];
        }

        $method = $decoded['method'] ?? null;
        if (!\is_string($method) || $method === '') {
            return [RpcError::of(ErrorCode::InvalidRequest, 'invalid request: method must be a non-empty string'), $id];
        }

        $params = $decoded['params'] ?? [];
        if (!\is_array($params) || ($params !== [] && \array_is_list($params))) {
            return [RpcError::of(ErrorCode::InvalidParams, 'invalid params: params must be an object'), $id];
        }

        /** @var array<string, mixed> $params */
        return new Request($id, $hasId, $method, $params);
    }

    /** A success response. */
    public static function result(string|int|null $id, mixed $result): string
    {
        return self::encode(['jsonrpc' => self::VERSION, 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result]);
    }

    /** An error response. */
    public static function error(string|int|null $id, RpcError $error): string
    {
        return self::encode(['jsonrpc' => self::VERSION, 'id' => $id, 'error' => $error->toArray()]);
    }

    /**
     * A server→client notification, as an array (an {@see \SugarCraft\Crush\Server\Ws\Outbox}
     * may coalesce it before encoding).
     *
     * @param array<string, mixed> $params
     * @return array{jsonrpc: string, method: string, params: array<string, mixed>}
     */
    public static function notification(string $method, array $params): array
    {
        return ['jsonrpc' => self::VERSION, 'method' => $method, 'params' => $params];
    }

    /** @param array<string, mixed> $message */
    public static function encode(array $message): string
    {
        return (string) \json_encode($message, self::FLAGS);
    }
}
