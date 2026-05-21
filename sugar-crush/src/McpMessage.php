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
     * @param array<string, mixed>|null $params
     * @param array<string, mixed>|null $result
     * @param array<string, mixed>|null $error
     */
    private function __construct(
        public readonly ?string $id,
        public readonly ?string $method,
        public readonly ?array $params,
        public readonly ?array $result,
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
     * @param array<string, mixed>|null $result
     */
    public static function success(string $id, $result): self
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
