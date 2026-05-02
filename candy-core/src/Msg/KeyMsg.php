<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\KeyType;
use CandyCore\Core\Modifiers;
use CandyCore\Core\Msg;

/**
 * A single keypress. For named keys (arrows, function keys, etc.) the
 * {@see $type} field is sufficient; for printable text {@see $type} is
 * {@see KeyType::Char} and {@see $rune} carries the character.
 *
 * The original `alt` / `ctrl` booleans remain for back-compat. New
 * code can use {@see modifiers()} to get the bundled
 * {@see Modifiers} value, or {@see text()} / {@see code()} as
 * v2-style aliases for `$rune` / `$type`.
 */
final class KeyMsg implements Msg
{
    public function __construct(
        public readonly KeyType $type,
        public readonly string $rune = '',
        public readonly bool $alt = false,
        public readonly bool $ctrl = false,
        public readonly bool $shift = false,
    ) {}

    /** Human-readable label, e.g. "ctrl+c", "shift+up", "alt+a", "a". */
    public function string(): string
    {
        $base = $this->type === KeyType::Char ? $this->rune : $this->type->value;
        $prefix = '';
        if ($this->ctrl)  $prefix .= 'ctrl+';
        if ($this->alt)   $prefix .= 'alt+';
        if ($this->shift) $prefix .= 'shift+';
        return $prefix . $base;
    }

    /**
     * v2-style alias for {@see $rune}: the literal text the user
     * typed (empty for named keys).
     */
    public function text(): string
    {
        return $this->type === KeyType::Char ? $this->rune : '';
    }

    /**
     * v2-style alias for {@see $type}: the logical key code.
     */
    public function code(): KeyType
    {
        return $this->type;
    }

    /**
     * Bundle the modifier flags into a single {@see Modifiers} value.
     */
    public function modifiers(): Modifiers
    {
        return new Modifiers(shift: $this->shift, alt: $this->alt, ctrl: $this->ctrl);
    }
}
