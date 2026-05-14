<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Select;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A radio button group component.
 *
 * Displays a list of radio options with single selection behavior:
 * - Only one option can be selected at a time
 * - Custom markers for selected/unselected states
 * - Optional color coding
 *
 * Mirrors the radio button concept from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Radio implements \SugarCraft\Dash\Foundation\Sizer
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
        private readonly string $selectedChar = '◉',
        private readonly string $unselectedChar = '○',
    ) {}

    /**
     * Create a new radio group with default styling.
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
            selectedChar: '◉',
            unselectedChar: '○',
        );
    }

    /**
     * Set the allocated dimensions for this radio group.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the radio group.
     */
    public function render(): string
    {
        if (empty($this->options)) {
            return '';
        }

        $lines = [];
        foreach ($this->options as $index => $option) {
            $lines[] = $this->renderOption($option, $index);
        }

        $result = implode("\n", $lines);

        if ($this->selectedColor !== null || $this->unselectedColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a single radio option.
     */
    private function renderOption(array $option, int $index): string
    {
        $label = $option['label'];
        $isSelected = ($index === $this->selectedIndex);

        $char = $isSelected ? $this->selectedChar : $this->unselectedChar;
        $color = $isSelected ? $this->selectedColor : $this->unselectedColor;

        $prefix = $isSelected ? '>' : ' ';
        $line = $prefix . $char . ' ' . $label;

        if ($color !== null) {
            return $color->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }

        return $line;
    }

    /**
     * Calculate the natural dimensions of this radio group.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $maxWidth = 0;
        foreach ($this->options as $option) {
            // prefix (>) + char + space + label
            $lineWidth = 1 + Width::string($this->selectedChar) + 1 + Width::string($option['label']);
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