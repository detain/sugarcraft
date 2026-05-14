<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Keys;

/**
 * Unique identifier for a key binding.
 *
 * @readonly
 */
final class KeyIdentifier
{
    public function __construct(
        public readonly string $value,
    ) {}

    /**
     * Create from a string.
     */
    public static function of(string $value): self
    {
        return new self($value);
    }

    /**
     * Create the standard identifiers.
     */
    public static function quit(): self
    {
        return new self('quit');
    }

    public static function help(): self
    {
        return new self('help');
    }

    public static function refresh(): self
    {
        return new self('refresh');
    }

    public static function focusNext(): self
    {
        return new self('focus.next');
    }

    public static function focusPrev(): self
    {
        return new self('focus.prev');
    }
}
