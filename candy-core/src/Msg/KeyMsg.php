<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg;

/**
 * A single keypress. For named keys (arrows, function keys, etc.) the
 * {@see $type} field is sufficient; for printable text {@see $type} is
 * {@see KeyType::Char} and {@see $rune} carries the character.
 */
final class KeyMsg implements Msg
{
    public function __construct(
        public readonly KeyType $type,
        public readonly string $rune = '',
        public readonly bool $alt = false,
        public readonly bool $ctrl = false,
    ) {}

    /** Human-readable label, e.g. "ctrl+c", "up", "alt+a", "a". */
    public function string(): string
    {
        $base = $this->type === KeyType::Char ? $this->rune : $this->type->value;
        $prefix = '';
        if ($this->ctrl) $prefix .= 'ctrl+';
        if ($this->alt)  $prefix .= 'alt+';
        return $prefix . $base;
    }
}
