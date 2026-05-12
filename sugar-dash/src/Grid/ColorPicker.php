<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A color picker component with preset color swatches.
 *
 * Features:
 * - Grid of preset color swatches
 * - Color selection highlighting
 * - Custom color input support
 * - Optional hex code display
 *
 * Mirrors color picker UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class ColorPicker implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * Default color palette - classic web-safe + extras.
     */
    private static array $DEFAULT_PALETTE = [
        '#EF4444', '#F97316', '#F59E0B', '#EAB308',
        '#84CC16', '#22C55E', '#10B981', '#14B8A6',
        '#06B6D4', '#0EA5E9', '#3B82F6', '#6366F1',
        '#8B5CF6', '#A855F7', '#D946EF', '#EC4899',
        '#F43F5E', '#78716C', '#64748B', '#1E293B',
    ];

    public function __construct(
        private readonly array $palette = [],
        private readonly int $selectedIndex = 0,
        private readonly bool $showHex = true,
        private readonly int $columns = 4,
        private readonly ?Color $selectedColor = null,
        private readonly ?Color $hoverColor = null,
    ) {}

    /**
     * Create a new color picker with default palette.
     */
    public static function new(?int $selectedIndex = null): self
    {
        $currentIndex = $selectedIndex ?? 10; // Default to blue

        return new self(
            palette: self::$DEFAULT_PALETTE,
            selectedIndex: $currentIndex,
            showHex: true,
            columns: 4,
            selectedColor: Color::hex('#FFFFFF')->withBackground(Color::hex('#3B82F6')),
            hoverColor: Color::hex('#F59E0B'),
        );
    }

    /**
     * Create with a custom palette.
     *
     * @param array<int, string> $hexColors Array of hex color codes
     */
    public static function withPalette(array $hexColors, ?int $selectedIndex = null): self
    {
        return new self(
            palette: $hexColors,
            selectedIndex: $selectedIndex ?? 0,
            showHex: true,
            columns: 4,
            selectedColor: Color::hex('#FFFFFF')->withBackground(Color::hex('#3B82F6')),
            hoverColor: Color::hex('#F59E0B'),
        );
    }

    /**
     * Set the allocated dimensions for this color picker.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the color picker as a string.
     */
    public function render(): string
    {
        if (empty($this->palette)) {
            return '';
        }

        $rows = $this->buildRows();

        if ($this->showHex && $this->selectedIndex < count($this->palette)) {
            $selectedHex = $this->palette[$this->selectedIndex];
            $hexLine = '  ' . $selectedHex;
            $rows[] = $hexLine;
        }

        return implode("\n", $rows);
    }

    /**
     * Build the color swatch grid rows.
     *
     * @return array<int, string>
     */
    private function buildRows(): array
    {
        $rows = [];
        $totalColors = count($this->palette);
        $cols = max(1, $this->columns);

        for ($i = 0; $i < $totalColors; $i += $cols) {
            $row = '';
            for ($j = 0; $j < $cols; $j++) {
                $index = $i + $j;
                if ($index >= $totalColors) {
                    $row .= str_repeat(' ', 7); // Empty cell
                } else {
                    $row .= $this->renderSwatch($index);
                }
                if ($j < $cols - 1) {
                    $row .= ' ';
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Render a single color swatch.
     */
    private function renderSwatch(int $index): string
    {
        $hex = $this->palette[$index];
        $isSelected = ($index === $this->selectedIndex);

        $bgColor = Color::hex($hex);

        if ($isSelected && $this->selectedColor !== null) {
            // Selected: show [##] format
            $result = $bgColor->toBg(ColorProfile::TrueColor);
            $result .= $bgColor->toFg(ColorProfile::TrueColor);
            $result .= '[█' . substr($hex, 1) . ']';
            $result .= Ansi::reset();
            return $result;
        }

        // Normal: show ██ hex
        $result = $bgColor->toBg(ColorProfile::TrueColor);
        $result .= $bgColor->toFg(ColorProfile::TrueColor);
        $result .= '██' . substr($hex, 1);
        $result .= Ansi::reset();
        return $result;
    }

    /**
     * Calculate the natural dimensions of this color picker.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->palette)) {
            return [0, 0];
        }

        // Each swatch: 2 bg chars + 4 hex chars = 6 visible + 2 brackets if selected
        $swatchWidth = 6;
        $cols = max(1, $this->columns);
        $gapWidth = 1;
        $width = ($swatchWidth * $cols) + ($gapWidth * ($cols - 1));

        $rows = (int) ceil(count($this->palette) / $cols);
        $height = $rows;

        if ($this->showHex) {
            $height += 1;
            $hexWidth = 2 + 7; // padding + hex code
            $width = max($width, $hexWidth);
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the selected color index.
     */
    public function withSelectedIndex(int $index): self
    {
        $totalColors = count($this->palette);

        return new self(
            palette: $this->palette,
            selectedIndex: max(0, min($index, $totalColors - 1)),
            showHex: $this->showHex,
            columns: $this->columns,
            selectedColor: $this->selectedColor,
            hoverColor: $this->hoverColor,
        );
    }

    /**
     * Set whether to show hex code.
     */
    public function withShowHex(bool $show): self
    {
        return new self(
            palette: $this->palette,
            selectedIndex: $this->selectedIndex,
            showHex: $show,
            columns: $this->columns,
            selectedColor: $this->selectedColor,
            hoverColor: $this->hoverColor,
        );
    }

    /**
     * Set the number of columns.
     */
    public function withColumns(int $cols): self
    {
        return new self(
            palette: $this->palette,
            selectedIndex: $this->selectedIndex,
            showHex: $this->showHex,
            columns: max(1, $cols),
            selectedColor: $this->selectedColor,
            hoverColor: $this->hoverColor,
        );
    }

    /**
     * Set the selected color highlight style.
     */
    public function withSelectedColor(?Color $color): self
    {
        return new self(
            palette: $this->palette,
            selectedIndex: $this->selectedIndex,
            showHex: $this->showHex,
            columns: $this->columns,
            selectedColor: $color,
            hoverColor: $this->hoverColor,
        );
    }

    /**
     * Set the hover color style.
     */
    public function withHoverColor(?Color $color): self
    {
        return new self(
            palette: $this->palette,
            selectedIndex: $this->selectedIndex,
            showHex: $this->showHex,
            columns: $this->columns,
            selectedColor: $this->selectedColor,
            hoverColor: $color,
        );
    }

    /**
     * Set a new palette.
     *
     * @param array<int, string> $palette Array of hex color codes
     */
    public function withPalette(array $palette): self
    {
        return new self(
            palette: $palette,
            selectedIndex: min($this->selectedIndex, count($palette) - 1),
            showHex: $this->showHex,
            columns: $this->columns,
            selectedColor: $this->selectedColor,
            hoverColor: $this->hoverColor,
        );
    }
}
