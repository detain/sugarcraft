<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Calendar;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Layout\HAlign;

/**
 * A scrollable list component with item selection.
 *
 * Features:
 * - Collection of string items
 * - Single selection with cursor
 * - Scroll handling when selection goes out of view
 * - Optional filtering/predicate
 * - Configurable list styling (bullet, number, arrow)
 *
 * Mirrors list functionality from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class ListComponent implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const Bullet = 'bullet';
    public const Number = 'number';
    public const Arrow = 'arrow';
    public const Plain = 'plain';

    public function __construct(
        private readonly array $items,
        private readonly int $selected = 0,
        private readonly ?\Closure $filter = null,
        private readonly bool $showCursor = true,
        private readonly string $style = self::Bullet,
        private readonly HAlign $itemAlign = HAlign::Left,
    ) {}

    /**
     * Create a new list with the given items.
     *
     * @param list<string> $items
     */
    public static function new(array $items): self
    {
        return new self(
            items: $items,
            selected: 0,
            filter: null,
            showCursor: true,
            style: self::Bullet,
            itemAlign: HAlign::Left,
        );
    }

    /**
     * Set the allocated dimensions for this list.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the list with selection cursor.
     */
    public function render(): string
    {
        $filteredItems = $this->getFilteredItems();

        if ($filteredItems === []) {
            return '';
        }

        $h = $this->height ?? count($filteredItems);
        $w = $this->width ?? $this->calculateMaxWidth($filteredItems);

        // Clamp selected index to valid range
        $selected = max(0, min($this->selected, count($filteredItems) - 1));

        // Calculate scroll offset to keep selected item in view
        $scrollTop = $this->calculateScrollTop($selected, $h, count($filteredItems));

        // Get visible slice
        $visibleItems = array_slice($filteredItems, $scrollTop, $h);

        $result = [];
        foreach ($visibleItems as $index => $item) {
            $globalIndex = $scrollTop + $index;
            $prefix = $this->getPrefix($globalIndex, $selected, count($filteredItems));
            $line = $prefix . ' ' . $item;

            // Apply item alignment within the given width
            $lineWidth = Width::string($line);
            if ($lineWidth < $w) {
                $padding = $w - $lineWidth;
                $line = match ($this->itemAlign) {
                    HAlign::Left => $line . str_repeat(' ', $padding),
                    HAlign::Right => str_repeat(' ', $padding) . $line,
                    HAlign::Center => $this->centerAlign($line, $lineWidth, $w),
                };
            } elseif ($lineWidth > $w) {
                $line = $this->truncateToWidth($line, $w);
            }

            $result[] = $line;
        }

        // Pad with empty lines if needed
        while (count($result) < $h) {
            $result[] = str_repeat(' ', $w);
        }

        return implode("\n", array_slice($result, 0, $h));
    }

    /**
     * Calculate the natural dimensions of this list when rendered.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $filteredItems = $this->getFilteredItems();
        $h = count($filteredItems);
        $w = $this->calculateMaxWidth($filteredItems);

        if ($this->height !== null && $this->height > 0) {
            $h = $this->height;
        }

        if ($this->width !== null && $this->width > 0) {
            $w = $this->width;
        }

        return [$w, $h];
    }

    /**
     * Get items filtered by the predicate if set.
     *
     * @return list<string>
     */
    private function getFilteredItems(): array
    {
        if ($this->filter === null) {
            return $this->items;
        }

        return array_values(array_filter($this->items, $this->filter));
    }

    /**
     * Calculate the maximum width among all filtered items.
     */
    private function calculateMaxWidth(array $items): int
    {
        $maxWidth = 0;
        foreach ($items as $index => $item) {
            $prefix = $this->getStaticPrefix($index);
            $lineWidth = Width::string($prefix . ' ' . $item);
            if ($lineWidth > $maxWidth) {
                $maxWidth = $lineWidth;
            }
        }
        return $maxWidth;
    }

    /**
     * Get the prefix for a list item based on style.
     */
    private function getStaticPrefix(int $index): string
    {
        return match ($this->style) {
            self::Bullet => '•',
            self::Number => (string) ($index + 1) . '.',
            self::Arrow => '>',
            self::Plain => '',
            default => '•',
        };
    }

    /**
     * Get the prefix for a list item, including cursor state.
     */
    private function getPrefix(int $index, int $selected, int $total): string
    {
        if (!$this->showCursor) {
            return $this->getStaticPrefix($index);
        }

        $isSelected = $index === $selected;

        return match ($this->style) {
            self::Bullet => $isSelected ? '●' : '○',
            self::Number => $isSelected ? "[$index] " : ($index < 9 ? ' ' : '') . ($index + 1) . '.',
            self::Arrow => $isSelected ? '►' : ' ',
            self::Plain => $isSelected ? '>' : ' ',
            default => $isSelected ? '●' : '○',
        };
    }

    /**
     * Calculate the scroll offset to keep the selected item visible.
     */
    private function calculateScrollTop(int $selected, int $height, int $totalItems): int
    {
        if ($height >= $totalItems) {
            return 0;
        }

        // Keep selected item centered when possible
        $idealTop = (int) floor($selected - ($height / 2));

        // Clamp to valid range
        return max(0, min($idealTop, $totalItems - $height));
    }

    /**
     * Center-align a line within the given width.
     */
    private function centerAlign(string $line, int $lineWidth, int $width): string
    {
        $padding = $width - $lineWidth;
        $left = (int) floor($padding / 2);
        $right = $padding - $left;

        return str_repeat(' ', $left) . $line . str_repeat(' ', $right);
    }

    /**
     * Truncate a string to fit within the given width.
     * Budgets 1 character for the ellipsis.
     */
    private function truncateToWidth(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        // Budget 1 column for the ellipsis
        $maxContentWidth = max(1, $width - 1);
        if (Width::string($s) <= $maxContentWidth) {
            return $s . '…';
        }
        $lo = 0;
        $hi = mb_strlen($s, 'UTF-8');
        while ($lo < $hi) {
            $mid = (int) (($lo + $hi + 1) / 2);
            $candidate = mb_substr($s, 0, $mid, 'UTF-8');
            if (Width::string($candidate) <= $maxContentWidth) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($lo === 0) {
            return '';
        }
        $result = mb_substr($s, 0, $lo, 'UTF-8');
        return $result . '…';
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the items for this list.
     *
     * @param list<string> $items
     */
    public function withItems(array $items): self
    {
        $clone = new self(
            items: $items,
            selected: $this->selected,
            filter: $this->filter,
            showCursor: $this->showCursor,
            style: $this->style,
            itemAlign: $this->itemAlign,
        );
        $clone->width = $this->width;
        $clone->height = $this->height;
        return $clone;
    }

    /**
     * Set the selected index.
     */
    public function withSelected(int $selected): self
    {
        $clone = new self(
            items: $this->items,
            selected: max(0, $selected),
            filter: $this->filter,
            showCursor: $this->showCursor,
            style: $this->style,
            itemAlign: $this->itemAlign,
        );
        $clone->width = $this->width;
        $clone->height = $this->height;
        return $clone;
    }

    /**
     * Set a filter predicate for items.
     *
     * @param \Closure|null $filter fn(string $item): bool
     */
    public function withFilter(?\Closure $filter): self
    {
        $clone = new self(
            items: $this->items,
            selected: $this->selected,
            filter: $filter,
            showCursor: $this->showCursor,
            style: $this->style,
            itemAlign: $this->itemAlign,
        );
        $clone->width = $this->width;
        $clone->height = $this->height;
        return $clone;
    }

    /**
     * Show or hide the cursor indicator.
     */
    public function withShowCursor(bool $showCursor): self
    {
        $clone = new self(
            items: $this->items,
            selected: $this->selected,
            filter: $this->filter,
            showCursor: $showCursor,
            style: $this->style,
            itemAlign: $this->itemAlign,
        );
        $clone->width = $this->width;
        $clone->height = $this->height;
        return $clone;
    }

    /**
     * Set the list style (bullet, number, arrow, plain).
     */
    public function withStyle(string $style): self
    {
        $clone = new self(
            items: $this->items,
            selected: $this->selected,
            filter: $this->filter,
            showCursor: $this->showCursor,
            style: $style,
            itemAlign: $this->itemAlign,
        );
        $clone->width = $this->width;
        $clone->height = $this->height;
        return $clone;
    }

    /**
     * Set the item alignment within the list width.
     */
    public function withItemAlign(HAlign $align): self
    {
        $clone = new self(
            items: $this->items,
            selected: $this->selected,
            filter: $this->filter,
            showCursor: $this->showCursor,
            style: $this->style,
            itemAlign: $align,
        );
        $clone->width = $this->width;
        $clone->height = $this->height;
        return $clone;
    }
}