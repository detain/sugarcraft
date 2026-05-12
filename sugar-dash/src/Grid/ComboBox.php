<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A combobox component with search filtering.
 *
 * Features:
 * - Search input with filtered dropdown results
 * - Case-insensitive filtering
 * - Match highlighting option
 * - Configurable result limit
 *
 * Mirrors combobox UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class ComboBox implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{label: string, value?: string}> $options
     */
    public function __construct(
        private readonly string $placeholder = 'Search...',
        private readonly array $options = [],
        private readonly string $query = '',
        private readonly int $selectedIndex = 0,
        private readonly ?Color $inputColor = null,
        private readonly ?Color $matchColor = null,
        private readonly ?Color $selectedColor = null,
    ) {}

    /**
     * Create a new combobox with default styling.
     */
    public static function new(string $placeholder = 'Search...', array $options = []): self
    {
        return new self(
            placeholder: $placeholder,
            options: $options,
            query: '',
            selectedIndex: 0,
            inputColor: Color::hex('#3B82F6'),
            matchColor: Color::hex('#F59E0B'),
            selectedColor: Color::hex('#874BFD'),
        );
    }

    /**
     * Set the allocated dimensions for this combobox.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the combobox as a string.
     */
    public function render(): string
    {
        $result = $this->renderInput();

        $filtered = $this->getFilteredOptions();
        // Always show results when there are options (with or without query)
        if (!empty($filtered)) {
            $result .= "\n" . $this->renderResults($filtered);
        }

        return $result;
    }

    /**
     * Render the search input line.
     */
    private function renderInput(): string
    {
        $queryDisplay = !empty($this->query) ? $this->query : $this->placeholder;
        $prefix = '🔍 ';
        $content = $prefix . $queryDisplay;

        if (!empty($this->query) && $this->inputColor !== null) {
            return $this->inputColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        if (empty($this->query) && $this->inputColor !== null) {
            // Muted color for placeholder
            return Color::hex('#9CA3AF')->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * Render the filtered results.
     *
     * @param array<int, array{label: string, value?: string}> $filtered
     */
    private function renderResults(array $filtered): string
    {
        $lines = [];
        $safeIndex = max(0, min($this->selectedIndex, count($filtered) - 1));

        foreach ($filtered as $index => $option) {
            $lines[] = $this->renderResultItem($option, $index === $safeIndex);
        }

        return implode("\n", $lines);
    }

    /**
     * Render a single result item.
     *
     * @param array{label: string, value?: string} $option
     */
    private function renderResultItem(array $option, bool $isSelected): string
    {
        $label = $option['label'];
        $prefix = $isSelected ? '▶' : ' ';

        // Highlight matching portion of label if query exists
        if (!empty($this->query)) {
            $label = $this->highlightMatch($label);
        }

        $content = $prefix . ' ' . $label;

        if ($isSelected && $this->selectedColor !== null) {
            return $this->selectedColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * Highlight the matching portion of a label.
     */
    private function highlightMatch(string $label): string
    {
        if ($this->matchColor === null) {
            return $label;
        }

        $query = $this->query;
        // If the label contains the query (case-insensitive), highlight the whole label
        // to avoid breaking the string with ANSI codes
        if (stripos($label, $query) !== false) {
            return $this->matchColor->toFg(ColorProfile::TrueColor) . $label . Ansi::reset();
        }

        return $label;
    }

    /**
     * Get options filtered by the current query.
     *
     * @return array<int, array{label: string, value?: string}>
     */
    private function getFilteredOptions(): array
    {
        if (empty($this->query)) {
            return $this->options;
        }

        $queryLower = strtolower($this->query);
        $filtered = [];

        foreach ($this->options as $option) {
            $label = strtolower($option['label']);
            // Match items that start with the query (case-insensitive)
            if (str_starts_with($label, $queryLower)) {
                $filtered[] = $option;
            }
        }

        return $filtered;
    }

    /**
     * Calculate the natural dimensions of this combobox.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $inputWidth = Width::string($this->placeholder) + 3; // 🔍  + space

        $maxResultWidth = 0;
        $filtered = $this->getFilteredOptions();
        foreach ($filtered as $option) {
            // prefix + space + label
            $itemWidth = 1 + 1 + Width::string($option['label']);
            if ($itemWidth > $maxResultWidth) {
                $maxResultWidth = $itemWidth;
            }
        }

        $width = max($inputWidth, $maxResultWidth);
        $height = 1 + count($filtered);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the placeholder text.
     */
    public function withPlaceholder(string $placeholder): self
    {
        return new self(
            placeholder: $placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        );
    }

    /**
     * Set the options.
     *
     * @param array<int, array{label: string, value?: string}> $options
     */
    public function withOptions(array $options): self
    {
        return new self(
            placeholder: $this->placeholder,
            options: $options,
            query: $this->query,
            selectedIndex: min($this->selectedIndex, count($options) - 1),
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        );
    }

    /**
     * Set the search query.
     */
    public function withQuery(string $query): self
    {
        return new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $query,
            selectedIndex: 0, // Reset selection when query changes
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        );
    }

    /**
     * Set the selected result index.
     */
    public function withSelectedIndex(int $index): self
    {
        $filtered = $this->getFilteredOptions();
        return new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: max(0, min($index, count($filtered) - 1)),
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        );
    }

    /**
     * Set the input color.
     */
    public function withInputColor(?Color $color): self
    {
        return new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            inputColor: $color,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        );
    }

    /**
     * Set the match highlight color.
     */
    public function withMatchColor(?Color $color): self
    {
        return new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            inputColor: $this->inputColor,
            matchColor: $color,
            selectedColor: $this->selectedColor,
        );
    }

    /**
     * Set the selected item color.
     */
    public function withSelectedColor(?Color $color): self
    {
        return new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $color,
        );
    }
}
