<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A cursor component that renders a cursor character at a fixed position.
 *
 * Supports:
 * - Different cursor styles (block, underline, bar)
 * - Optional color styling
 * - Configurable visibility (for blinking cursor implementations)
 *
 * Mirrors cursor styling from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Cursor implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * Block cursor style - filled rectangle.
     */
    public const Block = 'block';

    /**
     * Underline cursor style - single underline.
     */
    public const Underline = 'underline';

    /**
     * Bar cursor style - vertical bar.
     */
    public const Bar = 'bar';

    public function __construct(
        private readonly string $style = self::Block,
        private readonly ?Color $color = null,
        private readonly bool $visible = true,
    ) {}

    /**
     * Create a new cursor with default styling.
     *
     * Default: block cursor, no color, visible.
     */
    public static function new(): self
    {
        return new self(
            style: self::Block,
            color: null,
            visible: true,
        );
    }

    /**
     * Set the allocated dimensions for this cursor.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the cursor character based on the current style.
     */
    public function getCursorChar(): string
    {
        return match ($this->style) {
            self::Block => '█',
            self::Underline => '▁',
            self::Bar => '│',
            default => '█',
        };
    }

    /**
     * Render the cursor character.
     */
    public function render(): string
    {
        if (!$this->visible) {
            return '';
        }

        $cursor = $this->getCursorChar();

        if ($this->color !== null) {
            return $this->color->toFg(ColorProfile::TrueColor) . $cursor . Ansi::reset();
        }

        return $cursor;
    }

    /**
     * Calculate the natural dimensions of this cursor.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? 1;
        $h = $this->height ?? 1;

        return [$w, $h];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the cursor style (block, underline, bar).
     */
    public function withStyle(string $style): self
    {
        return new self(
            style: $style,
            color: $this->color,
            visible: $this->visible,
        );
    }

    /**
     * Set the cursor color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            style: $this->style,
            color: $color,
            visible: $this->visible,
        );
    }

    /**
     * Set cursor visibility (useful for blinking cursor implementations).
     */
    public function withVisible(bool $visible): self
    {
        return new self(
            style: $this->style,
            color: $this->color,
            visible: $visible,
        );
    }
}
