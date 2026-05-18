<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Keys;

/**
 * Represents a keyboard key combination.
 */
final class Key
{
    public function __construct(
        public readonly string $key,
        public readonly bool $ctrl = false,
        public readonly bool $alt = false,
        public readonly bool $shift = false,
    ) {}

    /**
     * Create a key with Ctrl modifier.
     */
    public function withCtrl(): self
    {
        return new self($this->key, true, $this->alt, $this->shift);
    }

    /**
     * Create a key with Alt modifier.
     */
    public function withAlt(): self
    {
        return new self($this->key, $this->ctrl, true, $this->shift);
    }

    /**
     * Create a key with Shift modifier.
     */
    public function withShift(): self
    {
        return new self($this->key, $this->ctrl, $this->alt, true);
    }

    /**
     * Get the normalized key string representation.
     */
    public function toString(): string
    {
        $parts = [];
        if ($this->ctrl) {
            $parts[] = 'Ctrl';
        }
        if ($this->alt) {
            $parts[] = 'Alt';
        }
        if ($this->shift) {
            $parts[] = 'Shift';
        }
        $parts[] = strtoupper($this->key);
        return implode('+', $parts);
    }

    /**
     * Check if this key matches a given key string and modifiers.
     */
    public function matches(string $key, bool $ctrl = false, bool $alt = false, bool $shift = false): bool
    {
        return $this->key === $key
            && $this->ctrl === $ctrl
            && $this->alt === $alt
            && $this->shift === $shift;
    }
}
