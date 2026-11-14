<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Select;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;
use SugarCraft\Dashboard\Support\ListWindow;

/**
 * A dropdown select component.
 *
 * Displays a compact select control, one option per row, with one option
 * marked as selected:
 * - Vertical list of options with selection indicator
 * - Custom selection marker and colors
 * - Optional width constraint
 * - When an allocation height is given, the list is scroll-windowed
 *   around the selected row instead of painting every option (finding
 *   sugar-dashboard-051; same ListWindow rule as the calendar list)
 *
 * Mirrors the select/dropdown concept from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Select implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{label: string}> $options
     */
    public function __construct(
        private readonly array $options,
        private readonly int $selectedIndex = 0,
        private readonly ?Color $selectedColor = null,
        private readonly ?Color $unselectedColor = null,
        private readonly string $selectedChar = '▶',
        private readonly string $unselectedChar = '○',
    ) {}

    /**
     * Create a new select component with default styling.
     *
     * Default: purple selected item, gray unselected items.
     */
    public static function new(array $options): self
    {
        return new self(
            options: $options,
            selectedIndex: 0,
            selectedColor: Color::hex('#874BFD'),
            unselectedColor: Color::ansi(8),
            selectedChar: '▶',
            unselectedChar: '○',
        );
    }

    /**
     * Set the allocated dimensions for this select component.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the select component.
     *
     * With an allocation height the list is a scroll window around the
     * selected row (support ListWindow, same rule as the calendar list);
     * without one it paints every option at natural height.
     */
    public function render(): string
    {
        if (empty($this->options)) {
            return '';
        }

        $safeIndex = max(0, min($this->selectedIndex, count($this->options) - 1));

        $total = count($this->options);
        $visible = $this->height !== null && $this->height > 0 ? min($this->height, $total) : $total;
        $top = ListWindow::scrollTop($safeIndex, $visible, $total);

        $lines = [];
        for ($index = $top; $index < $top + $visible; $index++) {
            $option = $this->options[$index];
            $lines[] = $this->renderOption($option, $index, $index === $safeIndex);
        }

        $result = implode("\n", $lines);

        if ($this->selectedColor !== null || $this->unselectedColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a single select option.
     */
    private function renderOption(array $option, int $index, bool $isSelected): string
    {
        $label = $option['label'];

        $char = $isSelected ? $this->selectedChar : $this->unselectedChar;
        $color = $isSelected ? $this->selectedColor : $this->unselectedColor;

        $line = $char . ' ' . $label;

        if ($color !== null) {
            return $color->toFg(ColorMode::profile()) . $line . Ansi::reset();
        }

        return $line;
    }

    /**
     * Calculate the natural dimensions of this select component.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        $maxWidth = 0;
        foreach ($this->options as $option) {
            // char + space + label
            $lineWidth = Width::string($this->selectedChar) + 1 + Width::string($option['label']);
            if ($lineWidth > $maxWidth) {
                $maxWidth = $lineWidth;
            }
        }

        return [$maxWidth, $this->height !== null && $this->height > 0 ? min($this->height, count($this->options)) : count($this->options)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Carry the current allocation onto a wither result.
     *
     * The withers rebuild via new self(...); without this the setSize()
     * width/height would silently evaporate on the first styling tweak
     * (the Popover/Dropdown allocation-drop class).
     */
    private function carryAllocation(self $next): self
    {
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the selected option index.
     */
    public function withSelectedIndex(int $index): self
    {
        return $this->carryAllocation(new self(
            options: $this->options,
            selectedIndex: max(0, min($index, count($this->options) - 1)),
            selectedColor: $this->selectedColor,
            unselectedColor: $this->unselectedColor,
            selectedChar: $this->selectedChar,
            unselectedChar: $this->unselectedChar,
        ));
    }

    /**
     * Set new options.
     *
     * @param array<int, array{label: string}> $options
     */
    public function withOptions(array $options): self
    {
        return $this->carryAllocation(new self(
            options: $options,
            selectedIndex: min($this->selectedIndex, count($options) - 1),
            selectedColor: $this->selectedColor,
            unselectedColor: $this->unselectedColor,
            selectedChar: $this->selectedChar,
            unselectedChar: $this->unselectedChar,
        ));
    }

    /**
     * Set the color for selected items.
     */
    public function withSelectedColor(?Color $color): self
    {
        return $this->carryAllocation(new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            selectedColor: $color,
            unselectedColor: $this->unselectedColor,
            selectedChar: $this->selectedChar,
            unselectedChar: $this->unselectedChar,
        ));
    }

    /**
     * Set the color for unselected items.
     */
    public function withUnselectedColor(?Color $color): self
    {
        return $this->carryAllocation(new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            selectedColor: $this->selectedColor,
            unselectedColor: $color,
            selectedChar: $this->selectedChar,
            unselectedChar: $this->unselectedChar,
        ));
    }

    /**
     * Set custom characters for selected and unselected states.
     */
    public function withChars(string $selected, string $unselected): self
    {
        return $this->carryAllocation(new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            selectedColor: $this->selectedColor,
            unselectedColor: $this->unselectedColor,
            selectedChar: $selected,
            unselectedChar: $unselected,
        ));
    }
}