<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

/**
 * DECSCUSR cursor shape values — CSI Ps SP q.
 *
 * Mirrors charmbracelet/x/vt cursor-shape enumeration.
 *
 * CSI Ps SP q mapping:
 *   0/1 = blinking block
 *   2   = steady block
 *   3   = blinking underline
 *   4   = steady underline
 *   5   = blinking bar (vertical)
 *   6   = steady bar (vertical)
 */
enum CursorShape: int
{
    case BlinkingBlock = 0;
    case SteadyBlock = 2;
    case BlinkingUnderline = 3;
    case SteadyUnderline = 4;
    case BlinkingBar = 5;
    case SteadyBar = 6;

    /**
     * @param int $shape Raw shape value from DECSCUSR
     */
    public static function fromInt(int $shape): self
    {
        return match ($shape) {
            0, 1 => self::BlinkingBlock,
            2 => self::SteadyBlock,
            3 => self::BlinkingUnderline,
            4 => self::SteadyUnderline,
            5 => self::BlinkingBar,
            6 => self::SteadyBar,
            default => self::BlinkingBlock,
        };
    }

    /**
     * Return the raw int value for storage in Cursor::$shape.
     */
    public function toInt(): int
    {
        return $this->value;
    }
}
