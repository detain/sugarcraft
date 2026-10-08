<?php

declare(strict_types=1);

namespace SugarCraft\Mcp;

/**
 * A single JSON-RPC 2.0 envelope — request, notification, success response
 * or error response — as one immutable value object.
 *
 * Extracted from the sugar-crush MCP stack (src/McpMessage.php); the wire
 * algorithm is faithful, the naming follows this library. As of the lane-A2
 * fold this class is the ONE JSON-RPC envelope in the monorepo: sugar-crush's
 * twin was deleted into it, carrying its id-preserving variant
 * ({@see parsePreservingId()}, {@see withWireId()}, {@see MAX_DEPTH}) — the
 * ACP adapter's machinery — and the `mixed` error data payload the product's
 * error relay builds.
 *
 * WHY one class for all four shapes: the transport must decide, from a raw
 * line, whether it is looking at a request (has method AND id), a
 * notification (method, no id) or a response (no method, has id, result
 * and/or error). Splitting into subclasses would push that discrimination
 * into every `instanceof` chain while the parse already has all the facts.
 *
 * WHY `result` is `mixed` and paired with a `resultSet` sentinel: MCP tools
 * legitimately return `null`, `false`, `0` and `""`. When `result` was
 * narrowed to `?array` in the product, a server returning a scalar result
 * raised a TypeError at construction — which the MCP error handling (catching
 * RuntimeException) could not see, and the failure took down every registered
 * server rather than the one bad response. Null-preserving storage is the
 * whole point: `array_key_exists('result', $wire)` distinguishes "the server
 * answered with null" from "this is an error response".
 */
final class McpMessage
{
    private const JSONRPC_VERSION = '2.0';

    /**
     * How deep {@see parsePreservingId()} lets a message nest: a peer's
     * deeply nested document is refused unread rather than recursed into.
     * The plain {@see parse()} uses PHP's default bound instead — the
     * variant exists precisely for the adapter that faces foreign-authored
     * ids and must treat the whole envelope as hostile input.
     */
    public const MAX_DEPTH = 64;

    private function __construct(
        public readonly ?string $id,
        public readonly ?string $method,
        public readonly ?array $params,
        public readonly mixed $result,
        public readonly ?array $error,
        public readonly bool $isNotification,
        /** True iff the wire carried a "result" key — even when its value is null. */
        public readonly bool $resultSet = false,
        /**
         * The id EXACTLY as the peer sent it, or as it must go back out — `7`,
         * not `"7"` (decision D12, the ACP adapter). Only meaningful when
         * {@see $wireIdSet}; {@see $id} stays the string form every MCP caller
         * matches on.
         */
        public readonly string|int|null $wireId = null,
        /**
         * Whether this message is the id-preserving variant
         * ({@see parsePreservingId()}, {@see withWireId()}); false for every
         * message MCP builds, whose wire form is unchanged.
         */
        public readonly bool $wireIdSet = false,
    ) {
    }

    /**
     * Parse one wire line into an envelope, or null when the payload is not a
     * JSON-RPC 2.0 object we can act on.
     *
     * Returning null (never throwing) is deliberate: the stdio reader treats an
     * unparseable line as "this peer is not speaking our protocol" and fails the
     * exchange, and a malformed line must not become a crash vector inside the
     * read loop.
     */
    public static function parse(string $json): ?self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!isset($decoded['jsonrpc']) || $decoded['jsonrpc'] !== self::JSONRPC_VERSION) {
            return null;
        }

        $id = null;
        if (isset($decoded['id']) && is_string($decoded['id'])) {
            $id = $decoded['id'];
        } elseif (isset($decoded['id']) && is_int($decoded['id'])) {
            $id = (string) $decoded['id'];
        }

        $method = isset($decoded['method']) && is_string($decoded['method'])
            ? $decoded['method']
            : null;

        $params = isset($decoded['params']) && is_array($decoded['params'])
            ? $decoded['params']
            : null;

        $resultSet = array_key_exists('result', $decoded);
        $result = $resultSet ? $decoded['result'] : null;

        $error = isset($decoded['error']) && is_array($decoded['error'])
            ? $decoded['error']
            : null;

        // A notification is a request with no id — the absence must be literal,
        // so a wire `{"id":null}` does not read as one.
        $isNotification = $method !== null && !array_key_exists('id', $decoded);

        // Reject messages with no method, no result and no error: that shape
        // ("{"jsonrpc":"2.0"}") is not a valid JSON-RPC 2.0 object of any kind,
        // and accepting it would let a garbage line masquerade as a response.
        if ($method === null && $error === null && !$resultSet) {
            return null;
        }

        return new self(
            id: $id,
            method: $method,
            params: $params,
            result: $result,
            error: $error,
            isNotification: $isNotification,
            resultSet: $resultSet,
        );
    }

    /**
     * THE ID-PRESERVING VARIANT (decision D12): {@see parse()}, except that the
     * id is also kept exactly as it arrived — an integer stays an integer —
     * in {@see $wireId}, and {@see toJson()} writes it back that way.
     *
     * WHY A VARIANT AND NOT A FIX TO {@see parse()}. MCP's own traffic is ours
     * at both ends: the stdio transport mints string ids and matches replies
     * on the string form, so folding an integer to `"7"` there costs nothing
     * and changing it would touch every MCP caller. The Agent Client Protocol
     * is the other way round: the editor mints the ids, they are integers
     * (Zed's are), and JSON-RPC 2.0 requires a response to echo the id
     * EXACTLY — `{"id":"7"}` is not an answer to `{"id":7}`, and the client
     * waits for it forever. {@see $id} is still the string form, so
     * {@see isRequest()} and {@see isResponse()} read the same either way, and
     * a response the agent receives can be matched on {@see $wireId}.
     *
     * Also refuses what {@see parse()} lets through and an RPC server must
     * not: an id that is neither a string, an integer nor null, and nesting
     * past {@see MAX_DEPTH}.
     */
    public static function parsePreservingId(string $json): ?self
    {
        try {
            $decoded = json_decode($json, true, self::MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        if (array_key_exists('id', $decoded) && !is_string($decoded['id']) && !is_int($decoded['id']) && $decoded['id'] !== null) {
            return null;
        }

        $message = self::parse($json);
        if ($message === null) {
            return null;
        }

        return $message->withWireId($decoded['id'] ?? null);
    }

    /**
     * A copy whose wire id is $id exactly as given — how the id-preserving
     * variant BUILDS a message: `McpMessage::success((string) $id, $r)->withWireId($id)`
     * answers an integer-id request with the integer, and
     * `McpMessage::error('', $code, $m)->withWireId(null)` is the `"id": null`
     * error JSON-RPC requires for a request whose id could not be read. On a
     * notification, which carries no id, it changes nothing on the wire.
     */
    public function withWireId(string|int|null $id): self
    {
        return new self(
            id: $id === null || $this->isNotification ? $this->id : (string) $id,
            method: $this->method,
            params: $this->params,
            result: $this->result,
            error: $this->error,
            isNotification: $this->isNotification,
            resultSet: $this->resultSet,
            wireId: $id,
            wireIdSet: true,
        );
    }

    /**
     * Build an outgoing request envelope (method + id + params).
     *
     * @param array<string,mixed>|null $params
     */
    public static function request(string $id, string $method, ?array $params = null): self
    {
        return new self(
            id: $id,
            method: $method,
            params: $params,
            result: null,
            error: null,
            isNotification: false,
        );
    }

    /**
     * Build an outgoing notification envelope (method, no id, no answer).
     *
     * @param array<string,mixed>|null $params
     */
    public static function notification(string $method, ?array $params = null): self
    {
        return new self(
            id: null,
            method: $method,
            params: $params,
            result: null,
            error: null,
            isNotification: true,
        );
    }

    /**
     * Build a success response. Accepts any value — MCP results are `mixed`
     * and `null` is a legal payload, which is what `resultSet` records.
     */
    public static function success(string $id, mixed $result): self
    {
        return new self(
            id: $id,
            method: null,
            params: null,
            result: $result,
            error: null,
            isNotification: false,
            resultSet: true,
        );
    }

    /**
     * Build an error response.
     *
     * `$data` is `mixed`, not `?array`: JSON-RPC 2.0 leaves the error `data`
     * member "a value indicating additional information about the error", and
     * sugar-crush's ACP error relay forwards whatever a peer's own error object
     * carried — a plain string among them. Constraining this parameter to
     * arrays made the faithful relay of a scalar `data` impossible.
     *
     * @param mixed $data optional error detail — any JSON value
     */
    public static function error(string $id, int $code, string $message, mixed $data = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return new self(
            id: $id,
            method: null,
            params: null,
            result: null,
            error: $error,
            isNotification: false,
        );
    }

    /**
     * Wire serialization — one line, no trailing newline, keys only when set.
     *
     * @throws \InvalidArgumentException when the envelope cannot be encoded
     *         (INF/NAN — a model's `1e999` decodes to float(INF) — or a
     *         string that is not valid UTF-8). The alternative, `(string)
     *         false`, is an EMPTY line: every server ignores it, so the reply
     *         the caller then waits for never comes — and callTool() waits
     *         with no deadline, i.e. forever.
     */
    public function toJson(): string
    {
        $payload = ['jsonrpc' => self::JSONRPC_VERSION];

        // The id-preserving variant writes the id as it was given — an integer
        // as an integer, and a null one on a response, which JSON-RPC requires
        // for an error to a request whose id could not be read. A notification
        // never carries one.
        if ($this->wireIdSet && !$this->isNotification) {
            $payload['id'] = $this->wireId;
        } elseif ($this->id !== null) {
            $payload['id'] = $this->id;
        }

        if ($this->method !== null) {
            $payload['method'] = $this->method;
        }

        if ($this->params !== null) {
            // MCP params are always a JSON object, but PHP cannot tell an empty
            // map from an empty list and json_encode([]) is `[]`. The official
            // TS and Python SDKs reject `"params":[]` (Zod/Pydantic "expected
            // object, received array") and, for tools/list, never send an
            // id-bearing reply at all — so an empty map must leave as `{}`.
            // Non-empty params keep their own shape; nested empty maps are the
            // caller's to spell as \stdClass, which json_encode emits as `{}`.
            $payload['params'] = $this->params === [] ? new \stdClass() : $this->params;
        }

        // Emitted on the resultSet sentinel rather than `$result !== null`, so
        // a legitimate null success result survives a round-trip.
        if ($this->resultSet) {
            $payload['result'] = $this->result;
        }

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \InvalidArgumentException(sprintf(
                'JSON-RPC %s%s cannot be encoded: %s',
                $this->method !== null ? "\"{$this->method}\"" : ($this->error !== null ? 'error response' : 'response'),
                $this->id !== null ? " (id {$this->id})" : '',
                $failure->getMessage(),
            ), 0, $failure);
        }
    }

    /**
     * Inspection view: every key present, including the booleans and the
     * wire-id pair.
     *
     * This is NOT the wire shape (toJson owns that). Callers such as
     * StdioMcpServer::start() navigate `$message->toArray()['result']['tools']`
     * and rely on the unconditional keyset; conditioning it would make
     * absent-key handling a caller-side minefield.
     *
     * @return array{jsonrpc: string, id: string|null, method: string|null, params: array<string,mixed>|null, result: mixed, resultSet: bool, error: array<string,mixed>|null, isNotification: bool, wireId: string|int|null, wireIdSet: bool}
     */
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id,
            'method' => $this->method,
            'params' => $this->params,
            'result' => $this->result,
            'resultSet' => $this->resultSet,
            'error' => $this->error,
            'isNotification' => $this->isNotification,
            'wireId' => $this->wireId,
            'wireIdSet' => $this->wireIdSet,
        ];
    }

    /** Outgoing-shaped request: has a method, has an id, is not a notification. */
    public function isRequest(): bool
    {
        return $this->method !== null && $this->id !== null && !$this->isNotification;
    }

    /** A response carries no method — only result and/or error. */
    public function isResponse(): bool
    {
        return $this->method === null && $this->id !== null;
    }

    public function isNotification(): bool
    {
        return $this->isNotification;
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * The JSON-RPC error code, or NULL when absent or malformed.
     *
     * A third-party server sending `{"code":"abc"}` would cast to `0` under a
     * plain `(int)` — a fabricated success-ish code that downstream callers
     * cannot distinguish from a real one. Non-integer shapes therefore read as
     * "no code reported", matching the sugar-crush twin accessor.
     */
    public function errorCode(): ?int
    {
        if ($this->error === null) {
            return null;
        }

        $code = $this->error['code'] ?? null;

        return is_int($code) ? $code : null;
    }

    /**
     * The JSON-RPC error message, or NULL when absent or malformed.
     *
     * An array or object message would stringify to `'Array'` (with a PHP
     * Warning under strict contexts); reading any non-string as "no message
     * reported" keeps the accessor honest. Matches the sugar-crush twin.
     */
    public function errorMessage(): ?string
    {
        if ($this->error === null) {
            return null;
        }

        $message = $this->error['message'] ?? null;

        return is_string($message) ? $message : null;
    }
}
