<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;
use SugarCraft\Input\KeyModifier;

/**
 * A keyboard event — plain key or escape sequence decoded key.
 *
 * Mirrors charmbracelet/bubbletea's `tea.KeyMsg` (key.go).
 *
 * Instances are immutable value objects: every field is fixed at construction.
 */
final readonly class KeyEvent implements Event
{
    /** Prefix used by key-release names (Kitty event type 3). */
    public const RELEASE_PREFIX = 'Release';

    /**
     * @param string         $key       Symbolic key name (e.g. "ArrowUp", "Enter", "a", "F1");
     *                                  releases carry the "Release" prefix ("ReleaseA")
     * @param KeyModifier    $modifiers  The modifier state held at the event (a value object,
     *                                  not a raw bitmask — see {@see KeyModifier})
     * @param string         $raw       Raw bytes that produced this event (for debugging / echoing)
     * @param bool           $isRepeat  True when the wire marked the press as an auto-repeat
     *                                  (Kitty event type 2); a repeat IS a keystroke, the
     *                                  flag only annotates it
     */
    public function __construct(
        public string $key,
        public KeyModifier $modifiers,
        public string $raw,
        public bool $isRepeat = false,
    ) {}

    /**
     * True when this is a key RELEASE event: the name is the "Release" prefix
     * followed by the press name with its first letter capitalised
     * ("ReleaseA", "ReleaseArrowUp"). Only the Kitty protocol can report
     * releases, so this is false for every other source.
     */
    public function isRelease(): bool
    {
        return str_starts_with($this->key, self::RELEASE_PREFIX);
    }

    /**
     * A bare printable key: the character IS the event, so $raw defaults to
     * $char and a null $modifiers means "no modifiers held" (KeyModifier::none()
     * — PHP cannot default a parameter to a method call, hence the nullable).
     */
    public static function plain(string $char, ?KeyModifier $modifiers = null): self
    {
        return new self($char, $modifiers ?? KeyModifier::none(), $char);
    }

    /**
     * A named key (ArrowUp, Enter, F5, ...). Null $modifiers means none held;
     * empty $raw falls back to the bare ESC byte, which is honest only for
     * Escape itself — callers with real wire bytes should pass them.
     */
    public static function esc(string $key, ?KeyModifier $modifiers = null, string $raw = ''): self
    {
        return new self($key, $modifiers ?? KeyModifier::none(), $raw ?: "\x1b");
    }
}
