<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;

/**
 * Multi-column stacked grid layout.
 *
 * Items are placed into columns (0-based index). Items within the same
 * column are stacked vertically and share the column width. Items in
 * different columns are placed side-by-side with equal column widths
 * (unless FitScreen is disabled).
 *
 * Supports nested grids (a StackedGrid can be added as an item) and any
 * type implementing Sizer for explicit dimension propagation.
 *
 * @implements Sizer
 */
final class StackedGrid implements Sizer
{
    /** @var ItemWithOptions[] */
    private array $items = [];

    private int $width = 0;
    private int $height = 0;

    public function __construct(
        private readonly Options $options = new Options(),
    ) {}

    /**
     * Add an item to the grid with the given placement options.
     */
    public function addItem(Item $item, ItemOptions $options = new ItemOptions()): void
    {
        $this->items[] = new ItemWithOptions($item, $options);
    }

    /**
     * Propagate terminal dimensions to the grid.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $this->width = $width;
        $this->height = $height;
        return $this;
    }

    /**
     * Render the entire grid.
     *
     * Returns "Loading..." until setSize has been called with non-zero
     * dimensions.
     */
    public function render(): string
    {
        if ($this->width === 0 || $this->height === 0) {
            return 'Loading...';
        }

        $columns = $this->groupByColumn();
        $colCount = count($columns);

        if ($colCount === 0) {
            return '';
        }

        if ($colCount === 1) {
            return $this->renderColumn($columns[0], $this->width);
        }

        $colWidth = $this->options->fitScreen
            ? (int) floor($this->width / $colCount)
            : $this->width;

        $renderedColumns = [];
        foreach ($columns as $colItems) {
            $renderedColumns[] = $this->renderColumn($colItems, $colWidth);
        }

        return Layout::joinHorizontal(Position::TOP, ...$renderedColumns);
    }

    /**
     * Group items by their column index.
     *
     * @return array<int, ItemWithOptions[]>
     */
    private function groupByColumn(): array
    {
        $columns = [];
        foreach ($this->items as $itemWithOpts) {
            $col = $itemWithOpts->options->column;
            if (!isset($columns[$col])) {
                $columns[$col] = [];
            }
            $columns[$col][] = $itemWithOpts;
        }

        if ($columns === []) {
            return [];
        }

        ksort($columns);
        return array_values($columns);
    }

    /**
     * Render a single column (a stack of items sharing one width).
     */
    private function renderColumn(array $items, int $colWidth): string
    {
        if ($items === []) {
            return str_repeat("\n", $this->height > 0 ? $this->height : 0);
        }

        // Partition into expanding and non-expanding
        $expanding = [];
        $fixed = [];
        foreach ($items as $itemWithOpts) {
            if ($itemWithOpts->options->expandVertical) {
                $expanding[] = $itemWithOpts;
            } else {
                $fixed[] = $itemWithOpts;
            }
        }

        // Calculate height for non-expanding items
        $fixedHeight = 0;
        foreach ($fixed as $itemWithOpts) {
            $fixedHeight += $this->getItemNaturalHeight($itemWithOpts->item);
        }

        // Remaining space goes to expanding items
        $remainingHeight = max(0, $this->height - $fixedHeight);
        $expandingCount = count($expanding);
        $heightPerExpanding = $expandingCount > 0
            ? (int) floor($remainingHeight / $expandingCount)
            : 0;
        $remainder = $expandingCount > 0
            ? $remainingHeight - ($heightPerExpanding * $expandingCount)
            : 0;

        $renderedItems = [];
        foreach ($items as $i => $itemWithOpts) {
            $item = $itemWithOpts->item;
            $itemHeight = $itemHeight = $item instanceof self
                ? $this->getItemNaturalHeight($item)
                : $this->getItemNaturalHeight($item);

            if ($itemWithOpts->options->expandVertical) {
                $itemHeight = $heightPerExpanding;
                if ($i === count($items) - 1) {
                    $itemHeight += $remainder;
                }
            }

            $rendered = $this->renderItem($item, $colWidth, $itemHeight);
            $renderedItems[] = $rendered;
        }

        return implode("\n", $renderedItems);
    }

    /**
     * Render a single item within a column at the allocated width/height.
     */
    private function renderItem(Item $item, int $width, int $height): string
    {
        // Nested grid
        if ($item instanceof self) {
            $item->setSize($width, $height);
            return $item->render();
        }

        // Sizer (e.g. Frame)
        if ($item instanceof Sizer) {
            $item->setSize($width, $height);
            return $item->render();
        }

        // Plain item — wrap with style
        $rendered = $item->render();
        $lines = explode("\n", $rendered);

        // Trim or pad each line to $width
        $styledLines = [];
        foreach ($lines as $line) {
            $lineWidth = \SugarCraft\Core\Util\Width::string($line);
            if ($lineWidth > $width) {
                // Truncate with ellipsis
                $line = self::truncateToWidth($line, $width);
                $lineWidth = $width;
            }
            if ($lineWidth < $width) {
                $line = $line . str_repeat(' ', $width - $lineWidth);
            }
            $styledLines[] = $line;
        }

        // Pad to $height with blank lines
        while (count($styledLines) < $height) {
            $styledLines[] = str_repeat(' ', $width);
        }

        return implode("\n", $styledLines);
    }

    /**
     * Compute the natural rendered height of an item (number of lines).
     */
    private function getItemNaturalHeight(Item $item): int
    {
        if ($item instanceof self) {
            // For nested grids, use current height or default to 1
            return $this->height > 0 ? $this->height : 1;
        }
        if ($item instanceof Sizer) {
            return 1; // Sizer will receive explicit size from renderItem
        }
        $lines = substr_count($item->render(), "\n") + 1;
        return max(1, $lines);
    }

    /**
     * Truncate a string to fit within $width cell columns, preferring
     * to preserve the end of the string (filename pattern) when
     * truncation is needed.
     */
    private static function truncateToWidth(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if ($width === 1) {
            return mb_substr($s, 0, 1, 'UTF-8');
        }
        // Try keeping full string first
        if (\SugarCraft\Core\Util\Width::string($s) <= $width) {
            return $s;
        }
        // Binary search for the longest prefix that fits
        $lo = 0;
        $hi = mb_strlen($s, 'UTF-8');
        while ($lo < $hi) {
            $mid = (int) (($lo + $hi + 1) / 2);
            $candidate = mb_substr($s, 0, $mid, 'UTF-8');
            if (\SugarCraft\Core\Util\Width::string($candidate) <= $width) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        $result = mb_substr($s, 0, $lo, 'UTF-8');
        // Ensure we show at least something
        if ($result === '') {
            return mb_substr($s, 0, 1, 'UTF-8');
        }
        return $result;
    }
}
