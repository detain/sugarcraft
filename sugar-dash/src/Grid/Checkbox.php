<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A checkbox group component.
 *
 * Displays a list of checkbox options with:
 * - Checked/unchecked states with custom markers
 * - Single or multi-select behavior
 * - Optional color coding for checked items
 * - Keyboard navigation support
 *
 * Mirrors the checkbox concept from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Checkbox implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{label: string, checked: bool}> $options
     */
    public function __construct(
        private readonly array $options,
        private readonly int $selectedIndex = 0,
        private readonly bool $multiSelect = true,
        private readonly ?Color $checkedColor = null,
        private readonly ?Color $uncheckedColor = null,
        private readonly string $checkedChar = '◉',
        private readonly string $uncheckedChar = '○',
    ) {}

    /**
     * Create a new checkbox group with default styling.
     *
     * Default: purple checked items, multi-select enabled.
     */
    public static function new(array $options): self
    {
        return new self(
            options: $options,
            selectedIndex: 0,
            multiSelect: true,
            checkedColor: Color::hex('#874BFD'),
            uncheckedColor: Color::ansi(8),
            checkedChar: '◉',
            uncheckedChar: '○',
        );
    }

    /**
     * Set the allocated dimensions for this checkbox group.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the checkbox group.
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

        if ($this->checkedColor !== null || $this->uncheckedColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a single checkbox option.
     */
    private function renderOption(array $option, int $index): string
    {
        $label = $option['label'];
        $checked = $option['checked'];
        $isSelected = ($index === $this->selectedIndex);

        $char = $checked ? $this->checkedChar : $this->uncheckedChar;
        $color = $checked ? $this->checkedColor : $this->uncheckedColor;

        $prefix = $isSelected ? '>' : ' ';
        $line = $prefix . $char . ' ' . $label;

        if ($color !== null) {
            return $color->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }

        return $line;
    }

    /**
     * Calculate the natural dimensions of this checkbox group.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $maxWidth = 0;
        foreach ($this->options as $option) {
            // prefix (>) + char + space + label
            $lineWidth = 1 + Width::string($this->checkedChar) + 1 + Width::string($option['label']);
            if ($lineWidth > $maxWidth) {
                $maxWidth = $lineWidth;
            }
        }

        return [$maxWidth, count($this->options)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the selected/focused option index.
     */
    public function withSelectedIndex(int $index): self
    {
        return new self(
            options: $this->options,
            selectedIndex: max(0, min($index, count($this->options) - 1)),
            multiSelect: $this->multiSelect,
            checkedColor: $this->checkedColor,
            uncheckedColor: $this->uncheckedColor,
            checkedChar: $this->checkedChar,
            uncheckedChar: $this->uncheckedChar,
        );
    }

    /**
     * Toggle an option's checked state.
     *
     * @param int $index The option index to toggle
     * @param bool $checked The new checked state
     */
    public function withOptionChecked(int $index, bool $checked): self
    {
        $options = $this->options;
        if (isset($options[$index])) {
            // In single-select mode, checking an option unchecks all others
            if (!$this->multiSelect && $checked) {
                foreach ($options as $i => &$opt) {
                    $opt['checked'] = ($i === $index);
                }
                unset($opt);
            } else {
                $options[$index]['checked'] = $checked;
            }
        }

        return new self(
            options: $options,
            selectedIndex: $this->selectedIndex,
            multiSelect: $this->multiSelect,
            checkedColor: $this->checkedColor,
            uncheckedColor: $this->uncheckedColor,
            checkedChar: $this->checkedChar,
            uncheckedChar: $this->uncheckedChar,
        );
    }

    /**
     * Set all options' checked state.
     */
    public function withAllChecked(bool $checked): self
    {
        $options = array_map(
            fn(array $opt) => ['label' => $opt['label'], 'checked' => $checked],
            $this->options
        );

        return new self(
            options: $options,
            selectedIndex: $this->selectedIndex,
            multiSelect: $this->multiSelect,
            checkedColor: $this->checkedColor,
            uncheckedColor: $this->uncheckedColor,
            checkedChar: $this->checkedChar,
            uncheckedChar: $this->uncheckedChar,
        );
    }

    /**
     * Set multi-select mode.
     */
    public function withMultiSelect(bool $multiSelect): self
    {
        return new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            multiSelect: $multiSelect,
            checkedColor: $this->checkedColor,
            uncheckedColor: $this->uncheckedColor,
            checkedChar: $this->checkedChar,
            uncheckedChar: $this->uncheckedChar,
        );
    }

    /**
     * Set the color for checked items.
     */
    public function withCheckedColor(?Color $color): self
    {
        return new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            multiSelect: $this->multiSelect,
            checkedColor: $color,
            uncheckedColor: $this->uncheckedColor,
            checkedChar: $this->checkedChar,
            uncheckedChar: $this->uncheckedChar,
        );
    }

    /**
     * Set the color for unchecked items.
     */
    public function withUncheckedColor(?Color $color): self
    {
        return new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            multiSelect: $this->multiSelect,
            checkedColor: $this->checkedColor,
            uncheckedColor: $color,
            checkedChar: $this->checkedChar,
            uncheckedChar: $this->uncheckedChar,
        );
    }

    /**
     * Set custom characters for checked and unchecked states.
     */
    public function withChars(string $checked, string $unchecked): self
    {
        return new self(
            options: $this->options,
            selectedIndex: $this->selectedIndex,
            multiSelect: $this->multiSelect,
            checkedColor: $this->checkedColor,
            uncheckedColor: $this->uncheckedColor,
            checkedChar: $checked,
            uncheckedChar: $unchecked,
        );
    }
}
