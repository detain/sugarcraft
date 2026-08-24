<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * JSON-RPC 2.0 message envelope used by the MCP protocol.
 * Covers requests, responses, errors, and notifications over stdio.
 *
 * Mirrors the MCP spec: https://modelcontextprotocol.io/
 */
final class McpMessage
{
    private const JSONRPC_VERSION = '2.0';

    /**
     * `$result` IS `mixed`, NOT `?array`, AND THE WIDENING IS THE FIX FOR A CRASH.
     *
     * JSON-RPC 2.0 says only that `result` is "determined by the method
     * invoked" — it is any JSON value, and the MCP spec inherits that. This
     * parameter was typed `?array`, so every reply whose `result` decodes to a
     * PHP scalar threw out of {@see parse()} before the object existed.
     * MEASURED on this host (PHP 8.3.6) by feeding
     * `{"jsonrpc":"2.0","id":1,"result":<v>}` to `parse()`:
     *
     *     true  false  "s"  5  1.5   ->  TypeError, argument #4 ($result)
     *     []    {"a":1}              ->  parsed
     *     null                       ->  null (rejected; see parse())
     *
     * A `TypeError` is not a swallowed warning — `@` does not suppress it — and
     * it did not stop at the one message. {@see \SugarCraft\Crush\MCP\McpClient}
     * catches `\RuntimeException` around `start()`, expressly so that "a single
     * unreachable/misbehaving server must not abort loading the rest"; a
     * `TypeError` is neither caught there nor a `RuntimeException`, so ONE server
     * answering `initialize` with a non-object `result` took down the whole MCP
     * subsystem for the session.
     *
     * @param array<string, mixed>|null $params
     * @param mixed $result any JSON value the peer may put in `result`
     * @param array<string, mixed>|null $error
     */
    private function __construct(
        public readonly ?string $id,
        public readonly ?string $method,
        public readonly ?array $params,
        public readonly mixed $result,
        public readonly ?array $error,
        public readonly bool $isNotification,
    ) {}

    /**
     * Parse a raw JSON string into an McpMessage.
     * Returns null if the JSON is invalid or missing jsonrpc version.
     */
    public static function parse(string $raw): ?self
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        if ($decoded === null || !isset($decoded['jsonrpc']) || $decoded['jsonrpc'] !== self::JSONRPC_VERSION) {
            return null;
        }

        $id = array_key_exists('id', $decoded) ? (is_string($decoded['id']) || is_int($decoded['id']) ? (string) $decoded['id'] : null) : null;
        $method = $decoded['method'] ?? null;
        $params = isset($decoded['params']) && is_array($decoded['params']) ? $decoded['params'] : null;
        $result = array_key_exists('result', $decoded) ? ($decoded['result'] ?? null) : null;
        $error = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : null;

        $isNotification = $method !== null && !array_key_exists('id', $decoded);

        // If method is null but error is set, this is an error response — id may be absent
        //
        // `$result === null` IS THE REJECTION AND IT SWALLOWS ONE LEGAL MESSAGE:
        // `{"jsonrpc":"2.0","id":"1","result":null}` is a conforming JSON-RPC
        // success response and this returns null for it. That is deliberate, not
        // an oversight of the widening above. With `method`, `error` AND `result`
        // all null there is no discriminator left in the decoded array — the
        // message is indistinguishable from `{"jsonrpc":"2.0"}` and from a reply
        // whose `result` key is simply absent, and this class carries no
        // "was the key present" sentinel to tell them apart.
        //
        // WHAT IT COSTS, measured rather than assumed: a null-`result` reply to
        // `tools/call` leaves {@see \SugarCraft\Crush\MCP\StdioMcpServer::readResponse()}
        // returning null, so `callTool()` answers `['error' => 'Tool call
        // failed']` — a wrong answer, but a GRACEFUL one that stays inside the
        // tool result. The scalars fixed above were an uncaught `TypeError` that
        // escaped the MCP subsystem entirely. Closing this one properly needs a
        // paired `bool $resultSet` sentinel (the convention this repo already
        // uses for nullable state) threaded through `toJson()` and
        // `StdioMcpServer`'s two `result === null` tests; it is recorded as a
        // follow-up rather than bolted on here, because a half-threaded sentinel
        // is worse than a documented rejection.
        if ($method === null && $error === null && $result === null) {
            return null;
        }

        return new self(
            id: $id,
            method: $method,
            params: $params,
            result: $result,
            error: $error,
            isNotification: $isNotification,
        );
    }

    /**
     * Create a JSON-RPC 2.0 request with an id.
     *
     * @param array<string, mixed>|null $params
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
     * Create a JSON-RPC 2.0 notification (no id, no result expected).
     *
     * @param array<string, mixed>|null $params
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
     * Create a JSON-RPC 2.0 success response.
     *
     * @param mixed $result any JSON value — see the constructor for why this is
     *        not `?array`
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
        );
    }

    /**
     * Create a JSON-RPC 2.0 error response.
     *
     * @param array<string, mixed>|null $error data payload for the error
     */
    public static function error(string $id, int $code, string $message, $error = null): self
    {
        /** @var array<string, mixed> $errorPayload */
        $errorPayload = ['code' => $code, 'message' => $message];
        if ($error !== null) {
            $errorPayload['data'] = $error;
        }
        return new self(
            id: $id,
            method: null,
            params: null,
            result: null,
            error: $errorPayload,
            isNotification: false,
        );
    }

    /**
     * Serialize this message to a JSON string.
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
            $payload['params'] = $this->params;
        }
        if ($this->result !== null) {
            $payload['result'] = $this->result;
        }
        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{jsonrpc: string, id: string|null, method: string|null, params: array<string, mixed>|null, result: mixed|null, error: array<string, mixed>|null, isNotification: bool}
     */
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id,
            'method' => $this->method,
            'params' => $this->params,
            'result' => $this->result,
            'error' => $this->error,
            'isNotification' => $this->isNotification,
        ];
    }

    public function isRequest(): bool
    {
        return $this->method !== null && $this->id !== null && !$this->isNotification;
    }

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
     * Extract error code from error payload, or null if not an error.
     */
    public function errorCode(): ?int
    {
        if ($this->error === null) {
            return null;
        }
        return isset($this->error['code']) ? (int) $this->error['code'] : null;
    }

    /**
     * Extract error message from error payload, or null if not an error.
     */
    public function errorMessage(): ?string
    {
        if ($this->error === null) {
            return null;
        }
        return $this->error['message'] ?? null;
    }
}
