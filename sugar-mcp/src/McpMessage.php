<?php

declare(strict_types=1);

namespace SugarCraft\Mcp;

/**
 * A single JSON-RPC 2.0 envelope — request, notification, success response
 * or error response — as one immutable value object.
 *
 * Extracted from the sugar-crush MCP stack (src/McpMessage.php); the wire
 * algorithm is faithful, the naming follows this library.
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

    private function __construct(
        public readonly ?string $id,
        public readonly ?string $method,
        public readonly ?array $params,
        public readonly mixed $result,
        public readonly ?array $error,
        public readonly bool $isNotification,
        /** True iff the wire carried a "result" key — even when its value is null. */
        public readonly bool $resultSet = false,
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
     * @param array<string,mixed>|null $data optional structured error detail
     */
    public static function error(string $id, int $code, string $message, ?array $data = null): self
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

        if ($this->id !== null) {
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
     * Inspection view: every key present, including the two booleans.
     *
     * This is NOT the wire shape (toJson owns that). Callers such as
     * StdioMcpServer::start() navigate `$message->toArray()['result']['tools']`
     * and rely on the unconditional keyset; conditioning it would make
     * absent-key handling a caller-side minefield.
     *
     * @return array<string,mixed>
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

    public function errorCode(): ?int
    {
        if ($this->error === null) {
            return null;
        }

        return isset($this->error['code']) ? (int) $this->error['code'] : null;
    }

    public function errorMessage(): ?string
    {
        if ($this->error === null) {
            return null;
        }

        return isset($this->error['message']) ? (string) $this->error['message'] : null;
    }
}
