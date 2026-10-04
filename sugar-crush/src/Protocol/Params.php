<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

/**
 * Typed reads of a request's `params`, each refusing with `-32602` and the
 * parameter's name — never with what the client sent, which is not echoed.
 */
final class Params
{
    /** @param array<string, mixed> $params */
    private function __construct(private readonly array $params)
    {
    }

    /** @param array<string, mixed> $params */
    public static function of(array $params): self
    {
        return new self($params);
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->params) && $this->params[$name] !== null;
    }

    public function string(string $name, int $maxLength = 1_048_576): string
    {
        $value = $this->params[$name] ?? null;
        if (!\is_string($value) || $value === '') {
            throw RpcError::invalidParams(\sprintf('%s must be a non-empty string', $name));
        }
        if (\strlen($value) > $maxLength) {
            throw RpcError::invalidParams(\sprintf('%s is longer than %d bytes', $name, $maxLength));
        }

        return $value;
    }

    public function optionalString(string $name, int $maxLength = 1_048_576): ?string
    {
        return $this->has($name) ? $this->string($name, $maxLength) : null;
    }

    public function int(string $name, ?int $default = null, int $min = \PHP_INT_MIN, int $max = \PHP_INT_MAX): int
    {
        $value = $this->params[$name] ?? $default;
        if (!\is_int($value)) {
            throw RpcError::invalidParams(\sprintf('%s must be an integer', $name));
        }
        if ($value < $min || $value > $max) {
            throw RpcError::invalidParams(\sprintf('%s must be between %d and %d', $name, $min, $max));
        }

        return $value;
    }

    public function optionalInt(string $name, int $min = \PHP_INT_MIN, int $max = \PHP_INT_MAX): ?int
    {
        return $this->has($name) ? $this->int($name, null, $min, $max) : null;
    }

    public function bool(string $name, bool $default = false): bool
    {
        $value = $this->params[$name] ?? $default;
        if (!\is_bool($value)) {
            throw RpcError::invalidParams(\sprintf('%s must be true or false', $name));
        }

        return $value;
    }

    /**
     * One of $allowed.
     *
     * @param list<string> $allowed
     */
    public function enum(string $name, array $allowed, ?string $default = null): string
    {
        $value = $this->params[$name] ?? $default;
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            throw RpcError::invalidParams(\sprintf('%s must be one of: %s', $name, \implode(', ', $allowed)));
        }

        return $value;
    }

    /**
     * A list of strings.
     *
     * @return list<string>
     */
    public function strings(string $name, int $maxItems = 1000): array
    {
        $value = $this->params[$name] ?? [];
        if (!\is_array($value) || !\array_is_list($value) || \count($value) > $maxItems) {
            throw RpcError::invalidParams(\sprintf('%s must be a list of at most %d strings', $name, $maxItems));
        }
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw RpcError::invalidParams(\sprintf('%s must be a list of strings', $name));
            }
        }

        return $value;
    }

    /**
     * An object (string keys).
     *
     * @return array<string, mixed>
     */
    public function object(string $name): array
    {
        $value = $this->params[$name] ?? [];
        if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
            throw RpcError::invalidParams(\sprintf('%s must be an object', $name));
        }

        return $value;
    }

    public function raw(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }
}
