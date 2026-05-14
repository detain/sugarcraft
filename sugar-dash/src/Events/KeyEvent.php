<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Events;

/**
 * Keyboard event.
 */
final class KeyEvent extends Event
{
    public function __construct(
        int $timestamp,
        public readonly string $key,
        public readonly bool $ctrl = false,
        public readonly bool $alt = false,
        public readonly bool $shift = false,
        public readonly bool $meta = false,
    ) {
        parent::__construct($timestamp);
    }

    public function getType(): string
    {
        return 'key';
    }

    /**
     * Check if this is a specific key combination.
     */
    public function is(string $key, bool $ctrl = false, bool $alt = false, bool $shift = false): bool
    {
        return $this->key === $key
            && $this->ctrl === $ctrl
            && $this->alt === $alt
            && $this->shift === $shift;
    }

    /**
     * Check if this is a special key (arrow, function, etc).
     */
    public function isSpecial(): bool
    {
        return in_array($this->key, [
            'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
            'Enter', 'Escape', 'Tab', 'Backspace', 'Delete',
            'Home', 'End', 'PageUp', 'PageDown',
            'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
        ], true);
    }
}
