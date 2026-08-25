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
     * `$resultSet` IS THE PAIRED SENTINEL FOR THAT `mixed`, AND IT IS WHAT MAKES
     * `"result": null` REPRESENTABLE. `null` is a conforming JSON-RPC result, and
     * the value alone cannot say whether the peer sent it or sent nothing:
     * `$result === null` is `{"jsonrpc":"2.0","id":"1","result":null}` AND
     * `{"jsonrpc":"2.0"}` AND every error response, all three at once. This is the
     * `bool $XSet` convention the rest of the repo uses for exactly that ambiguity
     * (canonical `candy-sprinkles/src/Style.php`), and the two halves go together:
     * without it {@see parse()} had to REJECT the null-result message outright,
     * and {@see toJson()} DROPPED a null result on the way back out.
     *
     * @param array<string, mixed>|null $params
     * @param mixed $result any JSON value the peer may put in `result`
     * @param array<string, mixed>|null $error
     * @param bool $resultSet whether `result` was PRESENT — which is not the same
     *        question as whether it is non-null
     */
    private function __construct(
        public readonly ?string $id,
        public readonly ?string $method,
        public readonly ?array $params,
        public readonly mixed $result,
        public readonly ?array $error,
        public readonly bool $isNotification,
        public readonly bool $resultSet = false,
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
        $resultSet = array_key_exists('result', $decoded);
        $result = $resultSet ? $decoded['result'] : null;
        $error = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : null;

        $isNotification = $method !== null && !array_key_exists('id', $decoded);

        // WHAT THIS SAID BEFORE: `$result === null` was the rejection, and it
        // swallowed one legal message. `{"jsonrpc":"2.0","id":"1","result":null}`
        // is a conforming JSON-RPC success response and `parse()` returned null
        // for it. The reasoning was sound at the time — with `method`, `error` AND
        // `result` all null there was no discriminator left in the decoded array,
        // so the message was indistinguishable from `{"jsonrpc":"2.0"}` and from a
        // reply whose `result` key is simply absent — and the comment said the
        // proper fix was a paired `bool $resultSet` sentinel, deferred because a
        // half-threaded one is worse than a documented rejection.
        //
        // WHAT IS TRUE NOW: the sentinel exists and is threaded — through
        // {@see toJson()}, {@see toArray()} and both of
        // {@see \SugarCraft\Crush\MCP\StdioMcpServer}'s `result === null` tests
        // — so `array_key_exists()` IS the discriminator and the three cases are
        // told apart. A null-result reply now parses, reaches `callTool()`, and is
        // rendered as the text `null` by the same branch that renders `false` and
        // `0`, rather than being answered with `['error' => 'Tool call failed']`.
        //
        // WHY THE GUARD STILL EARNS ITS PLACE: it is what rejects
        // `{"jsonrpc":"2.0"}` and any other envelope carrying no method, no error
        // and no result key. That message is not a request, not a response and not
        // a notification; letting it through would hand `readResponse()` an object
        // with nothing to match on.
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
     *        not `?array`, and for why `success($id, null)` is a REAL null result
     *        rather than an absent one
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
        // `$this->resultSet`, NOT `$this->result !== null`: the second dropped a
        // legal null result on the way out, so a message that arrived as
        // `{"…","result":null}` re-serialised as `{"…"}` — a different message.
        if ($this->resultSet) {
            $payload['result'] = $this->result;
        }
        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * `resultSet` is carried too, because `result => null` in this array has the
     * same ambiguity the sentinel exists to resolve and a consumer reading the
     * array rather than the object would otherwise lose it.
     *
     * @return array{jsonrpc: string, id: string|null, method: string|null, params: array<string, mixed>|null, result: mixed|null, error: array<string, mixed>|null, isNotification: bool, resultSet: bool}
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
            'resultSet' => $this->resultSet,
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
