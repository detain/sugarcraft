<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * Alias for the core Color class.
 *
 * Provides sugar-dash-specific Color type hints while delegating
 * all operations to SugarCraft\Core\Util\Color.
 */
final class Color
{
    public function __construct(
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
    ) {}

    /**
     * Construct from 24-bit RGB.
     */
    public static function rgb(int $r, int $g, int $b): self
    {
        return new self($r, $g, $b);
    }

    /**
     * Construct from CSS hex string.
     */
    public static function hex(string $hex): self
    {
        $core = \SugarCraft\Core\Util\Color::hex($hex);
        return new self($core->r, $core->g, $core->b);
    }

    /**
     * Construct from HSL values (hue 0-360, saturation 0-1, lightness 0-1).
     */
    public static function hsl(float $h, float $s, float $l): self
    {
        $core = \SugarCraft\Core\Util\Color::hsl($h, $s, $l);
        return new self($core->r, $core->g, $core->b);
    }

    /**
     * Render as a foreground SGR escape.
     */
    public function toFg(\SugarCraft\Core\Util\ColorProfile $profile = \SugarCraft\Core\Util\ColorProfile::TrueColor): string
    {
        return $this->toCore()->toFg($profile);
    }

    /**
     * Render as a background SGR escape.
     */
    public function toBg(\SugarCraft\Core\Util\ColorProfile $profile = \SugarCraft\Core\Util\ColorProfile::TrueColor): string
    {
        return $this->toCore()->toBg($profile);
    }

    /**
     * Lighten by amount (0-1).
     */
    public function lighten(float $amount): self
    {
        $core = $this->toCore()->lighten($amount);
        return new self($core->r, $core->g, $core->b);
    }

    /**
     * Darken by amount (0-1).
     */
    public function darken(float $amount): self
    {
        $core = $this->toCore()->darken($amount);
        return new self($core->r, $core->g, $core->b);
    }

    /**
     * Blend with another color. $t=0 returns this, $t=1 returns $other.
     */
    public function blend(Color $other, float $t): self
    {
        $core = $this->toCore()->blend($other->toCore(), $t);
        return new self($core->r, $core->g, $core->b);
    }

    /**
     * Check if this is a "dark" color (luminance < 0.5).
     */
    public function isDark(): bool
    {
        return $this->toCore()->isDark();
    }

    /**
     * Convert to hex string.
     */
    public function toHex(): string
    {
        return $this->toCore()->toHex();
    }

    /**
     * Convert to the core Color instance.
     */
    public function toCore(): \SugarCraft\Core\Util\Color
    {
        return \SugarCraft\Core\Util\Color::rgb($this->r, $this->g, $this->b);
    }
}
