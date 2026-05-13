<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * An icon display component.
 *
 * Features:
 * - Display icons from popular icon sets (Nerd Font, etc.)
 * - Configurable size
 * - Color customization
 * - Optional label
 *
 * Icons are rendered as Unicode characters. The component supports
 * various icon sets including Nerd Font glyphs, Font Awesome,
 * and other Unicode icon fonts.
 *
 * Mirrors icon display concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Icon implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    // Common icon categories
    public const CATEGORY_FILE = 'file';
    public const CATEGORY_FOLDER = 'folder';
    public const CATEGORY_ARROW = 'arrow';
    public const CATEGORY_UI = 'ui';
    public const CATEGORY_STATUS = 'status';
    public const CATEGORY_MEDIA = 'media';
    public const CATEGORY_WEATHER = 'weather';
    public const CATEGORY_UI_ACTION = 'ui_action';

    public function __construct(
        private readonly string $glyph,
        private readonly ?string $label = null,
        private readonly int $size = 1,
        private readonly ?Color $color = null,
        private readonly ?Color $labelColor = null,
    ) {}

    /**
     * Create a file icon.
     */
    public static function file(?string $name = null): self
    {
        return new self('📄', $name, 1, null, null);
    }

    /**
     * Create a folder icon.
     */
    public static function folder(?string $name = null): self
    {
        return new self('📁', $name, 1, null, null);
    }

    /**
     * Create a gear/settings icon.
     */
    public static function gear(?string $label = null): self
    {
        return new self('⚙', $label, 1, null, null);
    }

    /**
     * Create a heart icon.
     */
    public static function heart(?string $label = null): self
    {
        return new self('♥', $label, 1, Color::ansi(1), null);
    }

    /**
     * Create a star icon.
     */
    public static function star(?string $label = null): self
    {
        return new self('★', $label, 1, Color::ansi(11), null);
    }

    /**
     * Create a checkmark icon.
     */
    public static function check(?string $label = null): self
    {
        return new self('✓', $label, 1, Color::ansi(2), null);
    }

    /**
     * Create an X/cross icon.
     */
    public static function cross(?string $label = null): self
    {
        return new self('✗', $label, 1, Color::ansi(1), null);
    }

    /**
     * Create an info icon.
     */
    public static function info(?string $label = null): self
    {
        return new self('ℹ', $label, 1, Color::ansi(4), null);
    }

    /**
     * Create a warning/alert icon.
     */
    public static function warning(?string $label = null): self
    {
        return new self('⚠', $label, 1, Color::ansi(11), null);
    }

    /**
     * Create an error icon.
     */
    public static function error(?string $label = null): self
    {
        return new self('⛔', $label, 1, Color::ansi(1), null);
    }

    /**
     * Create a home icon.
     */
    public static function home(?string $label = null): self
    {
        return new self('⌂', $label, 1, null, null);
    }

    /**
     * Create a search icon.
     */
    public static function search(?string $label = null): self
    {
        return new self('🔍', $label, 1, null, null);
    }

    /**
     * Create a music note icon.
     */
    public static function music(?string $label = null): self
    {
        return new self('♪', $label, 1, Color::ansi(13), null);
    }

    /**
     * Create a play icon.
     */
    public static function play(?string $label = null): self
    {
        return new self('▶', $label, 1, Color::ansi(2), null);
    }

    /**
     * Create a pause icon.
     */
    public static function pause(?string $label = null): self
    {
        return new self('⏸', $label, 1, Color::ansi(11), null);
    }

    /**
     * Create a stop icon.
     */
    public static function stop(?string $label = null): self
    {
        return new self('⏹', $label, 1, Color::ansi(1), null);
    }

    /**
     * Create an up arrow icon.
     */
    public static function arrowUp(?string $label = null): self
    {
        return new self('↑', $label, 1, null, null);
    }

    /**
     * Create a down arrow icon.
     */
    public static function arrowDown(?string $label = null): self
    {
        return new self('↓', $label, 1, null, null);
    }

    /**
     * Create a left arrow icon.
     */
    public static function arrowLeft(?string $label = null): self
    {
        return new self('←', $label, 1, null, null);
    }

    /**
     * Create a right arrow icon.
     */
    public static function arrowRight(?string $label = null): self
    {
        return new self('→', $label, 1, null, null);
    }

    /**
     * Set the allocated dimensions for this icon.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the icon as a string.
     */
    public function render(): string
    {
        $glyphWidth = Width::string($this->glyph);

        // If size is 1, just render the glyph (possibly with label)
        if ($this->size === 1) {
            return $this->renderSingle($glyphWidth);
        }

        // For larger sizes, repeat the glyph
        return $this->renderScaled($glyphWidth);
    }

    /**
     * Render a single-size icon.
     */
    private function renderSingle(int $glyphWidth): string
    {
        $output = '';

        if ($this->color !== null) {
            $output .= $this->color->toFg(ColorProfile::TrueColor);
        }

        $output .= $this->glyph;

        if ($this->label !== null) {
            $output .= ' ';
            if ($this->labelColor !== null) {
                $output .= $this->labelColor->toFg(ColorProfile::TrueColor);
            } elseif ($this->color !== null) {
                $output .= $this->color->toFg(ColorProfile::TrueColor);
            }
            $output .= $this->label;
        }

        if ($this->color !== null || $this->label !== null) {
            $output .= Ansi::reset();
        }

        return $output;
    }

    /**
     * Render a scaled icon (larger than 1).
     */
    private function renderScaled(int $glyphWidth): string
    {
        $output = '';

        // Create scaled version by repeating the glyph
        $scaled = str_repeat($this->glyph, max(1, $this->size));

        if ($this->color !== null) {
            $output .= $this->color->toFg(ColorProfile::TrueColor);
        }

        $output .= $scaled;

        if ($this->label !== null) {
            if ($this->labelColor !== null) {
                $output .= ' ' . $this->labelColor->toFg(ColorProfile::TrueColor) . $this->label;
            } else {
                $output .= ' ' . $this->label;
            }
        }

        if ($this->color !== null) {
            $output .= Ansi::reset();
        }

        return $output;
    }

    /**
     * Calculate the natural dimensions of this icon.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $glyphWidth = Width::string($this->glyph);
        $width = ($glyphWidth * $this->size) + ($this->label !== null ? Width::string($this->label) + 1 : 0);
        $height = 1;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the glyph.
     */
    public function withGlyph(string $glyph): self
    {
        return new self(
            glyph: $glyph,
            label: $this->label,
            size: $this->size,
            color: $this->color,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the label.
     */
    public function withLabel(?string $label): self
    {
        return new self(
            glyph: $this->glyph,
            label: $label,
            size: $this->size,
            color: $this->color,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the size (scaling factor).
     */
    public function withSize(int $size): self
    {
        return new self(
            glyph: $this->glyph,
            label: $this->label,
            size: max(1, $size),
            color: $this->color,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the icon color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            glyph: $this->glyph,
            label: $this->label,
            size: $this->size,
            color: $color,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        return new self(
            glyph: $this->glyph,
            label: $this->label,
            size: $this->size,
            color: $this->color,
            labelColor: $color,
        );
    }
}
