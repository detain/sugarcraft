<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

/**
 * A `sugarcrush.v1` method's refusal: thrown by a method, answered by the
 * {@see Dispatcher} as a JSON-RPC error object whose `data.kind` is the
 * machine-readable reason (Appendix O §6.2). Nothing a client sent is echoed
 * in the message; `data` carries only what the method chose to put there.
 */
final class RpcError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $data extra `data` fields beside `kind`
     */
    private function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly string $kind,
        public readonly array $data,
    ) {
        parent::__construct($message, $errorCode->value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function of(ErrorCode $code, string $message, ?string $kind = null, array $data = []): self
    {
        return new self($code, $message, $kind ?? $code->kind(), $data);
    }

    public static function invalidParams(string $message, string $kind = 'invalid_params'): self
    {
        return self::of(ErrorCode::InvalidParams, $message, $kind);
    }

    public static function notFound(string $message, string $kind = 'not_found'): self
    {
        return self::of(ErrorCode::NotFound, $message, $kind);
    }

    /**
     * The JSON-RPC `error` member.
     *
     * @return array{code: int, message: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        $data = ['kind' => $this->kind, ...$this->data];
        if ($this->errorCode->isRetryable() && !isset($data['retryable'])) {
            $data['retryable'] = true;
        }

        return ['code' => $this->errorCode->value, 'message' => $this->getMessage(), 'data' => $data];
    }
}
