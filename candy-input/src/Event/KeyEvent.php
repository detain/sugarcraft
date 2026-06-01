<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;
use SugarCraft\Input\KeyModifier;

/**
 * A keyboard event — plain key or escape sequence decoded key.
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 * @readonly
 */
readonly class KeyEvent implements Event
{
    /**
     * @param string         $key       Symbolic key name (e.g. "ArrowUp", "Enter", "a", "F1")
     * @param KeyModifier    $modifiers  Bitmask of held modifier keys
     * @param string         $raw       Raw bytes that produced this event (for debugging / echoing)
     */
    public function __construct(
        public string $key,
        public KeyModifier $modifiers,
        public string $raw,
    ) {}

    public static function plain(string $char, KeyModifier $modifiers = null): self
    {
        return new self($char, $modifiers ?? KeyModifier::None, $char);
    }

    public static function esc(string $key, KeyModifier $modifiers = null, string $raw = ''): self
    {
        return new self($key, $modifiers ?? KeyModifier::None, $raw ?: "\x1b");
    }
}
