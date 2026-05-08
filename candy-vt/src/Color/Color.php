<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Color;

/**
 * Terminal color value object.
 *
 * Mirrors charmbracelet/x/vt Color.
 */
final readonly class Color
{
    private function __construct(
        public int $kind,   // 0=Default, 1=Indexed16, 2=Indexed256, 3=Truecolor
        public int $value,  // index or 0xRRGGBB
    ) {}

    /** Default terminal color (reset). */
    public static function default(): self
    {
        return new self(0, 0);
    }

    /** 16-color palette (0–15). */
    public static function indexed16(int $index): self
    {
        if ($index < 0 || $index > 15) {
            throw new \InvalidArgumentException('index out of range [0,15]');
        }
        return new self(1, $index);
    }

    /** 256-color palette (0–255). */
    public static function indexed256(int $index): self
    {
        if ($index < 0 || $index > 255) {
            throw new \InvalidArgumentException('index out of range [0,255]');
        }
        return new self(2, $index);
    }

    /** 24-bit RGB. */
    public static function truecolor(int $r, int $g, int $b): self
    {
        if ($r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255) {
            throw new \InvalidArgumentException('rgb component out of range [0,255]');
        }
        return new self(3, ($r << 16) | ($g << 8) | $b);
    }

    public static function fromInt(int $kind, int $value): self
    {
        return new self($kind, $value);
    }

    public function red(): int
    {
        return ($this->value >> 16) & 0xFF;
    }

    public function green(): int
    {
        return ($this->value >> 8) & 0xFF;
    }

    public function blue(): int
    {
        return $this->value & 0xFF;
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->value === $other->value;
    }
}
