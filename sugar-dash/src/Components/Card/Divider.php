<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A divider line component.
 *
 * Features:
 * - Horizontal or vertical orientation
 * - Multiple line styles (solid, dashed, dotted, double)
 * - Customizable color and thickness
 * - Optional label in the center
 *
 * Mirrors divider/separator patterns adapted to PHP with wither-style
 * immutable setters.
 */
final class Divider implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const StyleSolid = 'solid';
    public const StyleDashed = 'dashed';
    public const StyleDotted = 'dotted';
    public const StyleDouble = 'double';

    public function __construct(
        private readonly ?string $label = null,
        private readonly string $style = self::StyleSolid,
        private readonly ?Color $color = null,
        private readonly bool $horizontal = true,
        private readonly int $thickness = 1,
    ) {}

    /**
     * Create a new divider.
     */
    public static function new(?string $label = null): self
    {
        return new self(
            label: $label,
            style: self::StyleSolid,
            color: Color::hex('#6C7086'),
            horizontal: true,
            thickness: 1,
        );
    }

    /**
     * Create a horizontal divider.
     */
    public static function h(?string $label = null): self
    {
        return new self(label: $label, style: self::StyleSolid, color: Color::hex('#6C7086'), horizontal: true);
    }

    /**
     * Create a vertical divider.
     */
    public static function v(?string $label = null): self
    {
        return new self(label: $label, style: self::StyleSolid, color: Color::hex('#6C7086'), horizontal: false);
    }

    /**
     * Set the allocated dimensions for this divider.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this divider.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->horizontal) {
            $width = $this->width ?? 40;
            $height = $this->thickness;
        } else {
            $width = $this->thickness;
            $height = $this->height ?? 10;
        }

        return [$width, $height];
    }

    /**
     * Render the divider.
     */
    public function render(): string
    {
        if ($this->horizontal) {
            return $this->renderHorizontal();
        }
        return $this->renderVertical();
    }

    /**
     * Render horizontal divider.
     */
    private function renderHorizontal(): string
    {
        $useWidth = $this->width ?? 40;
        $char = $this->getLineChar();

        $colorStr = $this->color?->toFg(ColorProfile::TrueColor) ?? '';

        if ($this->label === null) {
            return $colorStr . str_repeat($char, $useWidth) . Ansi::reset();
        }

        $labelLen = mb_strlen($this->label, 'UTF-8');
        $availableWidth = $useWidth - $labelLen - 2; // -2 for spaces around label
        if ($availableWidth < 0) {
            return $colorStr . $this->label . Ansi::reset();
        }

        $sideWidth = (int) floor($availableWidth / 2);
        $leftSide = str_repeat($char, $sideWidth);
        $rightSide = str_repeat($char, $availableWidth - $sideWidth);

        return $colorStr . $leftSide . ' ' . $this->label . ' ' . $rightSide . Ansi::reset();
    }

    /**
     * Render vertical divider.
     */
    private function renderVertical(): string
    {
        $useHeight = $this->height ?? 10;
        $char = $this->getLineChar();

        $colorStr = $this->color?->toFg(ColorProfile::TrueColor) ?? '';

        $lines = [];
        for ($i = 0; $i < $useHeight; $i++) {
            $lines[] = $char;
        }

        return $colorStr . implode("\n", $lines) . Ansi::reset();
    }

    /**
     * Get the line character based on style.
     */
    private function getLineChar(): string
    {
        return match ($this->style) {
            self::StyleSolid => '─',
            self::StyleDashed => '┅',
            self::StyleDotted => '┄',
            self::StyleDouble => '═',
            default => '─',
        };
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            label: $this->label,
            style: $style,
            color: $this->color,
            horizontal: $this->horizontal,
            thickness: $this->thickness,
        );
    }

    /**
     * Set the color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            style: $this->style,
            color: $color,
            horizontal: $this->horizontal,
            thickness: $this->thickness,
        );
    }

    /**
     * Set the label.
     */
    public function withLabel(?string $label): self
    {
        return new self(
            label: $label,
            style: $this->style,
            color: $this->color,
            horizontal: $this->horizontal,
            thickness: $this->thickness,
        );
    }

    /**
     * Set horizontal orientation.
     */
    public function withHorizontal(bool $horizontal): self
    {
        return new self(
            label: $this->label,
            style: $this->style,
            color: $this->color,
            horizontal: $horizontal,
            thickness: $this->thickness,
        );
    }

    /**
     * Set the thickness.
     */
    public function withThickness(int $thickness): self
    {
        return new self(
            label: $this->label,
            style: $this->style,
            color: $this->color,
            horizontal: $this->horizontal,
            thickness: max(1, $thickness),
        );
    }
}
