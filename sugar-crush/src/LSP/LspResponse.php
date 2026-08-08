<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * Result wrapper for LSP JSON-RPC responses.
 *
 * Distinguishes between a successful result, a JSON-RPC error response,
 * and a timeout/network failure — allowing callers to handle each case.
 */
final class LspResponse
{
    private function __construct(
        public readonly mixed $result,
        public readonly bool $isError,
        public readonly ?string $errorMessage,
    ) {}

    /**
     * Create a successful response with a result.
     */
    public static function ok(mixed $result): self
    {
        return new self($result, false, null);
    }

    /**
     * Create an error response from a JSON-RPC error object.
     *
     * @param array{code: int, message: string, data?: mixed} $error
     */
    public static function error(array $error): self
    {
        return new self(
            null,
            true,
            $error['message'] ?? 'Unknown JSON-RPC error',
        );
    }

    /**
     * Create a timeout response.
     */
    public static function timeout(): self
    {
        return new self(null, true, 'Request timed out');
    }

    /**
     * Create a network/IO error response.
     */
    public static function ioError(string $reason): self
    {
        return new self(null, true, $reason);
    }

    public function isTimeout(): bool
    {
        return $this->errorMessage === 'Request timed out';
    }

    /** Returns true for any error response (I/O, timeout, or JSON-RPC error). */
    public function isFailure(): bool
    {
        return $this->isError;
    }
}
