<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A dropdown select component.
 *
 * Displays a compact select control that shows all options when rendered,
 * with one option marked as selected:
 * - Vertical list of options with selection indicator
 * - Custom selection marker and colors
 * - Optional width constraint
 *
 * Mirrors the select/dropdown concept from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Select implements Sizer
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
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the select component showing all options.
     */
    public function render(): string
    {
        if (empty($this->options)) {
            return '';
        }

        $safeIndex = max(0, min($this->selectedIndex, count($this->options) - 1));

        $lines = [];
        foreach ($this->options as $index => $option) {
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
            return $color->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }

        return $line;
    }

    /**
     * Calculate the natural dimensions of this select component.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $maxWidth = 0;
        foreach ($this->options as $option) {
            // char + space + label
            $lineWidth = Width::string($this->selectedChar) + 1 + Width::string($option['label']);
            if ($lineWidth > $maxWidth) {
                $maxWidth = $lineWidth;
            }
        }

        return [$maxWidth, count($this->options)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the selected option index.
     */
    public function withSelectedIndex(int $index): self
    {
        return new self(
            options: $this->options,
            selectedIndex: max(0, min($index, count($this->options) - 1)),
            selectedColor: $this->selectedColor,
            unselectedColor: $this->unselectedColor,
            selectedChar: $this->selectedChar,
            unselectedChar: $this->unselectedChar,
        );
    }

    /**
     * Set new options.
     *
     * @param array<int, array{label: string}> $options
     */
    public function withOptions(array $options): self
    {
        return new self(
            options: $options,
            selectedIndex: min($this->selectedIndex, count($options) - 1),
            selectedColor: $this->selectedColor,
            unselectedColor: $this->unselectedColor,
            selectedChar: $this->selectedChar,
            unselectedChar: $this->unselectedChar,
        );
    }

    /**
     * Set the color for selected items.
     */
    public function withSelectedColor(?Color $color): self
    {
        return new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            selectedColor: $color,
            unselectedColor: $this->unselectedColor,
            selectedChar: $this->selectedChar,
            unselectedChar: $this->unselectedChar,
        );
    }

    /**
     * Set the color for unselected items.
     */
    public function withUnselectedColor(?Color $color): self
    {
        return new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            selectedColor: $this->selectedColor,
            unselectedColor: $color,
            selectedChar: $this->selectedChar,
            unselectedChar: $this->unselectedChar,
        );
    }

    /**
     * Set custom characters for selected and unselected states.
     */
    public function withChars(string $selected, string $unselected): self
    {
        return new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            selectedColor: $this->selectedColor,
            unselectedColor: $this->unselectedColor,
            selectedChar: $selected,
            unselectedChar: $unselected,
        );
    }
}
