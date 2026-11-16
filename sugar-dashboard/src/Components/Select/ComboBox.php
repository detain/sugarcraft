<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Select;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;
use SugarCraft\Dashboard\Support\ListWindow;
use SugarCraft\Dashboard\Components\GridTable\Calc;

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
final class ComboBox implements \SugarCraft\Dashboard\Foundation\SizedItem
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
     * Carry the current allocation onto a wither result.
     *
     * The withers rebuild via new self(...); without this the setSize()
     * width/height evaporates on the first tweak after sizing (the
     * Popover/Dropdown allocation-drop class). The ::new() factory is
     * deliberately not routed here: a fresh build has no allocation.
     */
    private function carryAllocation(self $next): self
    {
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the allocated dimensions for this combobox.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
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
        if (!empty($filtered) && $this->visibleResultCount($filtered) > 0) {
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
        $queryDisplay = $this->clipToAllocation($queryDisplay, Width::string($prefix));
        $content = $prefix . $queryDisplay;

        if (!empty($this->query) && $this->inputColor !== null) {
            return $this->inputColor->toFg(ColorMode::profile()) . $content . Ansi::reset();
        }

        if (empty($this->query) && $this->inputColor !== null) {
            // Muted color for placeholder
            return Color::hex('#9CA3AF')->toFg(ColorMode::profile()) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * How many result rows the current allocation allows.
     *
     * Null/zero height means natural (every match). An allocated height
     * reserves the input row first; a height of one leaves no budget for
     * results at all.
     *
     * @param array<int, mixed> $filtered
     */
    private function visibleResultCount(array $filtered): int
    {
        if ($this->height === null || $this->height <= 0) {
            return count($filtered);
        }

        return min(count($filtered), max(0, $this->height - 1));
    }

    /**
     * Render the filtered results.
     *
     * With an allocation the results are a scroll window around the
     * selected row (height minus the input line; same ListWindow rule as
     * the calendar list). Without one every match paints (finding
     * sugar-dashboard-051: the height used to be stored and ignored).
     *
     * @param array<int, array{label: string, value?: string}> $filtered
     */
    private function renderResults(array $filtered): string
    {
        $lines = [];
        $total = count($filtered);
        $safeIndex = max(0, min($this->selectedIndex, $total - 1));

        $visible = $this->visibleResultCount($filtered);
        $top = ListWindow::scrollTop($safeIndex, $visible, $total);

        for ($index = $top; $index < $top + $visible; $index++) {
            $lines[] = $this->renderResultItem($filtered[$index], $index === $safeIndex);
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
        $label = $this->clipToAllocation($option['label'], 2);
        $prefix = $isSelected ? '▶' : ' ';

        // Highlight matching portion of label if query exists
        if (!empty($this->query)) {
            $label = $this->highlightMatch($label);
        }

        $content = $prefix . ' ' . $label;

        if ($isSelected && $this->selectedColor !== null) {
            return $this->selectedColor->toFg(ColorMode::profile()) . $content . Ansi::reset();
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
            return $this->matchColor->toFg(ColorMode::profile()) . $label . Ansi::reset();
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
    public function innerSize(): array
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
        $height = 1 + $this->visibleResultCount($filtered);

        return [$width, $height];
    }

    /**
     * Clip plain text to the horizontal allocation, if there is one.
     *
     * Unallocated the combobox keeps its natural width (Boxer asks innerSize
     * for it); once setSize speaks, render stays inside the budget and long
     * content ellipsises instead of overflowing the box (finding
     * sugar-dashboard-046, the no-compress cliff — the same fail-toward-FIT
     * rule GridTable columns get through Calc).
     *
     * Behaviour change (P0-A11): the placeholder and the query are clipped by
     * Calc::truncate, which measures display cells, not code points. A CJK or
     * emoji placeholder/query therefore fills its allocation in cells and no
     * longer overruns it. A clipped text also drops any ANSI sequence it
     * carried (the old mb_substr cut could leave one open mid-escape). Text
     * that already fits is returned untouched, SGR included.
     */
    private function clipToAllocation(string $text, int $chrome): string
    {
        if ($this->width === null || $this->width <= 0) {
            return $text;
        }

        return Calc::truncate($text, max(1, $this->width - $chrome));
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the placeholder text.
     */
    public function withPlaceholder(string $placeholder): self
    {
        return $this->carryAllocation(new self(
            placeholder: $placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        ));
    }

    /**
     * Set the options.
     *
     * @param array<int, array{label: string, value?: string}> $options
     */
    public function withOptions(array $options): self
    {
        return $this->carryAllocation(new self(
            placeholder: $this->placeholder,
            options: $options,
            query: $this->query,
            selectedIndex: min($this->selectedIndex, count($options) - 1),
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        ));
    }

    /**
     * Set the search query.
     */
    public function withQuery(string $query): self
    {
        return $this->carryAllocation(new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $query,
            selectedIndex: 0, // Reset selection when query changes
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        ));
    }

    /**
     * Set the selected result index.
     */
    public function withSelectedIndex(int $index): self
    {
        $filtered = $this->getFilteredOptions();
        return $this->carryAllocation(new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: max(0, min($index, count($filtered) - 1)),
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        ));
    }

    /**
     * Set the input color.
     */
    public function withInputColor(?Color $color): self
    {
        return $this->carryAllocation(new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            inputColor: $color,
            matchColor: $this->matchColor,
            selectedColor: $this->selectedColor,
        ));
    }

    /**
     * Set the match highlight color.
     */
    public function withMatchColor(?Color $color): self
    {
        return $this->carryAllocation(new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            inputColor: $this->inputColor,
            matchColor: $color,
            selectedColor: $this->selectedColor,
        ));
    }

    /**
     * Set the selected item color.
     */
    public function withSelectedColor(?Color $color): self
    {
        return $this->carryAllocation(new self(
            placeholder: $this->placeholder,
            options: $this->options,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            inputColor: $this->inputColor,
            matchColor: $this->matchColor,
            selectedColor: $color,
        ));
    }
}