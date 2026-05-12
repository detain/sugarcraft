<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;

/**
 * A horizontal divider line with optional label.
 *
 * Features:
 * - Configurable character (default: ─)
 * - Optional label text displayed in the center
 * - Configurable color
 * - Horizontal alignment of label (left, center, right)
 *
 * Mirrors lipgloss Divider functionality adapted to PHP with
 * wither-style immutable setters.
 */
final class Divider implements Sizer
{
    private ?int $width = null;

    public const CHAR_BOX = '─';
    public const CHAR_HEAVY = '━';
    public const CHAR_DASHED = '-';
    public const CHAR_DOTTED = '·';
    public const CHAR_DOUBLE = '═';

    public function __construct(
        private readonly string $char = self::CHAR_BOX,
        private readonly ?string $label = null,
        private readonly ?Color $color = null,
        private readonly HAlign $labelAlign = HAlign::Center,
    ) {}

    /**
     * Create a divider with the default style.
     */
    public static function new(?string $label = null): self
    {
        return new self(
            char: self::CHAR_BOX,
            label: $label,
            color: null,
            labelAlign: HAlign::Center,
        );
    }

    /**
     * Set the allocated dimensions for this divider.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        return $clone;
    }

    /**
     * Render the divider line.
     */
    public function render(): string
    {
        $w = $this->width ?? 80;

        if ($w <= 0) {
            return '';
        }

        if ($this->label === null || $this->label === '') {
            return str_repeat($this->char, $w);
        }

        $labelWidth = Width::string($this->label);
        if ($labelWidth >= $w) {
            return str_repeat($this->char, $w);
        }

        // Space available for lines on either side of label
        $remainingSpace = $w - $labelWidth;

        return match ($this->labelAlign) {
            HAlign::Left => $this->label . str_repeat($this->char, $remainingSpace),
            HAlign::Right => str_repeat($this->char, $remainingSpace) . $this->label,
            HAlign::Center => $this->centeredWithLabel($w, $labelWidth),
        };
    }

    /**
     * Calculate natural dimensions (width only, height is always 1).
     *
     * @return array{0:int,1:int}
     */
    public function getInnerSize(): array
    {
        return [$this->width ?? 80, 1];
    }

    /**
     * Create a divider with label centered.
     */
    private function centeredWithLabel(int $totalWidth, int $labelWidth): string
    {
        $remainingSpace = $totalWidth - $labelWidth;
        $leftSpace = (int) floor($remainingSpace / 2);
        $rightSpace = $remainingSpace - $leftSpace;

        return str_repeat($this->char, $leftSpace) . $this->label . str_repeat($this->char, $rightSpace);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the divider character.
     */
    public function withChar(string $char): self
    {
        return new self(
            char: $char,
            label: $this->label,
            color: $this->color,
            labelAlign: $this->labelAlign,
        );
    }

    /**
     * Set the label text.
     */
    public function withLabel(?string $label): self
    {
        return new self(
            char: $this->char,
            label: $label,
            color: $this->color,
            labelAlign: $this->labelAlign,
        );
    }

    /**
     * Set the divider color.
     */
    public function withColor(Color $color): self
    {
        return new self(
            char: $this->char,
            label: $this->label,
            color: $color,
            labelAlign: $this->labelAlign,
        );
    }

    /**
     * Set the label alignment.
     */
    public function withLabelAlign(HAlign $align): self
    {
        return new self(
            char: $this->char,
            label: $this->label,
            color: $this->color,
            labelAlign: $align,
        );
    }
}