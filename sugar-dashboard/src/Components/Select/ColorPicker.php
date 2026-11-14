<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Select;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;

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
final class ColorPicker implements \SugarCraft\Dashboard\Foundation\SizedItem
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
        private readonly ?int $selectedIndex = 0,
        private readonly bool $showHex = true,
        private readonly int $columns = 4,
        private readonly ?Color $selectedColor = null,
        private readonly ?Color $hoverColor = null,
    ) {}

    /**
     * Create a new color picker with default palette.
     *
     * Passing no index is an honest NO SELECTION (D-146): nothing is
     * highlighted and the hex line reads '  (none)'. The old seam
     * silently defaulted to index 10 — a positional magic coupling to
     * the palette order (index 10 was '#3B82F6' by arrangement, and the
     * separate hardcoded selectedColor hex duplicated that coupling).
     */
    public static function new(?int $selectedIndex = null): self
    {
        return new self(
            palette: self::$DEFAULT_PALETTE,
            selectedIndex: $selectedIndex,
            showHex: true,
            columns: 4,
            selectedColor: Color::hex('#3B82F6'),
            hoverColor: Color::hex('#F59E0B'),
        );
    }

    /**
     * Create with a custom palette.
     *
     * @param array<int, string> $hexColors Array of hex color codes
     */
    public static function fromPalette(array $hexColors, ?int $selectedIndex = null): self
    {
        return new self(
            palette: $hexColors,
            selectedIndex: $selectedIndex,
            showHex: true,
            columns: 4,
            selectedColor: Color::hex('#3B82F6'),
            hoverColor: Color::hex('#F59E0B'),
        );
    }

    /**
     * Set the allocated dimensions for this color picker.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
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

        if ($this->showHex) {
            if ($this->selectedIndex !== null
                && $this->selectedIndex >= 0
                && $this->selectedIndex < count($this->palette)
            ) {
                $rows[] = '  ' . $this->palette[$this->selectedIndex];
            } else {
                // Honest none-row (D-146): keepHex reserves the line and
                // says so, instead of silently shrinking below innerSize().
                $rows[] = '  (none)';
            }
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
        $isSelected = $this->selectedIndex !== null && $index === $this->selectedIndex;

        $bgColor = Color::hex($hex);

        // When showHex is false, don't display hex codes even for selected swatch
        if (!$this->showHex) {
            $result = $bgColor->toBg(ColorMode::profile());
            $result .= $bgColor->toFg(ColorMode::profile());
            $result .= '██';
            $result .= Ansi::reset();
            return $result;
        }

        if ($isSelected && $this->selectedColor !== null) {
            // Selected: show [##] format
            $result = $bgColor->toBg(ColorMode::profile());
            $result .= $bgColor->toFg(ColorMode::profile());
            $result .= '[█' . substr($hex, 1) . ']';
            $result .= Ansi::reset();
            return $result;
        }

        // Normal: show ██ hex
        $result = $bgColor->toBg(ColorMode::profile());
        $result .= $bgColor->toFg(ColorMode::profile());
        $result .= '██' . substr($hex, 1);
        $result .= Ansi::reset();
        return $result;
    }

    /**
     * Calculate the natural dimensions of this color picker.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
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
            selectedIndex: $this->selectedIndex === null
                ? null
                : min($this->selectedIndex, count($palette) - 1),
            showHex: $this->showHex,
            columns: $this->columns,
            selectedColor: $this->selectedColor,
            hoverColor: $this->hoverColor,
        );
    }
}