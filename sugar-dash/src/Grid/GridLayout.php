<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Width;

/**
 * A CSS Grid-style layout component.
 *
 * Provides:
 * - Configurable rows and columns
 * - Cell-based item placement
 * - Gap support between cells
 * - Spanning support (items can span multiple cells)
 *
 * Mirrors CSS Grid layout concepts adapted to PHP with wither-style immutable setters.
 */
final class GridLayout implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<GridItem> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly int $columns = 1,
        private readonly int $rows = 0,
        private readonly int $columnGap = 0,
        private readonly int $rowGap = 0,
    ) {}

    /**
     * Create a new grid layout with the specified columns.
     *
     * @param list<GridItem> $items
     */
    public static function columns(int $columns, array $items = []): self
    {
        return new self(
            items: $items,
            columns: max(1, $columns),
            rows: 0,
            columnGap: 0,
            rowGap: 0,
        );
    }

    /**
     * Create a new grid layout with fixed rows.
     *
     * @param list<GridItem> $items
     */
    public static function rows(int $rows, array $items = []): self
    {
        return new self(
            items: $items,
            columns: 1,
            rows: max(1, $rows),
            columnGap: 0,
            rowGap: 0,
        );
    }

    /**
     * Set the allocated dimensions for this grid layout.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the grid layout.
     */
    public function render(): string
    {
        if ($this->items === []) {
            return '';
        }

        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w <= 0 || $h <= 0) {
            return $this->renderNatural();
        }

        return $this->renderSized($w, $h);
    }

    /**
     * Render with natural (unsized) dimensions.
     */
    private function renderNatural(): string
    {
        if ($this->items === []) {
            return '';
        }

        // Calculate natural dimensions
        $cols = $this->columns;
        $itemCount = count($this->items);
        $rows = $this->rows > 0 ? $this->rows : (int) ceil($itemCount / $cols);

        // Measure all items
        $itemSizes = $this->measureItems();

        // Calculate max cell width and height
        $cellWidth = 0;
        $cellHeight = 0;
        for ($i = 0; $i < $itemCount; $i++) {
            $col = $i % $cols;
            $row = (int) floor($i / $cols);
            $w = $itemSizes[$i]['width'];
            $h = $itemSizes[$i]['height'];
            $cellWidth = max($cellWidth, $w);
            $cellHeight = max($cellHeight, $h);
        }

        // Render grid
        return $this->renderGrid($cols, $rows, $itemSizes, $cellWidth, $cellHeight, $w ?? 80, $h ?? 20);
    }

    /**
     * Render with specific dimensions.
     */
    private function renderSized(int $totalWidth, int $totalHeight): string
    {
        $cols = $this->columns;
        $itemCount = count($this->items);
        $rows = $this->rows > 0 ? $this->rows : (int) ceil($itemCount / $cols);

        // Calculate cell dimensions
        $totalColumnGap = $this->columnGap * ($cols - 1);
        $totalRowGap = $this->rowGap * ($rows - 1);
        $cellWidth = (int) floor(($totalWidth - $totalColumnGap) / $cols);
        $cellHeight = (int) floor(($totalHeight - $totalRowGap) / $rows);

        $cellWidth = max(1, $cellWidth);
        $cellHeight = max(1, $cellHeight);

        // Measure items
        $itemSizes = $this->measureItems();

        return $this->renderGrid($cols, $rows, $itemSizes, $cellWidth, $cellHeight, $totalWidth, $totalHeight);
    }

    /**
     * Render the grid with calculated dimensions.
     */
    private function renderGrid(
        int $cols,
        int $rows,
        array $itemSizes,
        int $cellWidth,
        int $cellHeight,
        int $totalWidth,
        int $totalHeight
    ): string {
        $lines = array_fill(0, $totalHeight, '');

        for ($i = 0; $i < count($this->items); $i++) {
            $item = $this->items[$i];
            $col = $i % $cols;
            $row = (int) floor($i / $cols);

            $x = $col * ($cellWidth + $this->columnGap);
            $y = $row * ($cellHeight + $this->rowGap);

            // Set size on item
            if ($item instanceof Sizer) {
                $sized = $item->setSize($cellWidth, $cellHeight);
            } else {
                $sized = $item;
            }
            $rendered = $sized->render();

            // Split into lines
            $itemLines = explode("\n", $rendered);

            // Place item content in grid
            for ($dy = 0; $dy < $cellHeight; $dy++) {
                $lineIndex = $y + $dy;
                if ($lineIndex >= $totalHeight) {
                    break;
                }

                $lineContent = $itemLines[$dy] ?? '';
                $lineWidth = Width::string($lineContent);

                if ($lineWidth < $cellWidth) {
                    $lineContent .= str_repeat(' ', $cellWidth - $lineWidth);
                } elseif ($lineWidth > $cellWidth) {
                    $lineContent = $this->truncateToWidth($lineContent, $cellWidth);
                }

                // Add to existing line content at correct position
                $existingLine = $lines[$lineIndex];
                $beforeCell = mb_substr($existingLine, 0, $x, 'UTF-8');
                $afterStart = $x + $cellWidth;

                if (mb_strlen($existingLine, 'UTF-8') < $afterStart) {
                    $existingLine = str_pad($existingLine, $afterStart, ' ', STR_PAD_RIGHT);
                }

                $lines[$lineIndex] = mb_substr($existingLine, 0, $x, 'UTF-8')
                    . $lineContent
                    . mb_substr($existingLine, $afterStart, null, 'UTF-8');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Measure all items to get their natural dimensions.
     *
     * @return list<array{width:int,height:int}>
     */
    private function measureItems(): array
    {
        $sizes = [];
        foreach ($this->items as $item) {
            if ($item instanceof Sizer) {
                [$w, $h] = $item->getInnerSize();
                if ($w === 0 || $h === 0) {
                    $rendered = $item->render();
                    $lines = explode("\n", $rendered);
                    $w = 0;
                    foreach ($lines as $line) {
                        $w = max($w, Width::string($line));
                    }
                    $h = count($lines);
                }
            } else {
                $rendered = $item->render();
                $lines = explode("\n", $rendered);
                $w = 0;
                foreach ($lines as $line) {
                    $w = max($w, Width::string($line));
                }
                $h = count($lines);
            }
            $sizes[] = ['width' => max(1, $w), 'height' => max(1, $h)];
        }
        return $sizes;
    }

    /**
     * Truncate a string to fit within the given width.
     */
    private function truncateToWidth(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (Width::string($s) <= $width) {
            return $s;
        }
        $lo = 0;
        $hi = mb_strlen($s, 'UTF-8');
        while ($lo < $hi) {
            $mid = (int) (($lo + $hi + 1) / 2);
            $candidate = mb_substr($s, 0, $mid, 'UTF-8');
            if (Width::string($candidate) <= $width) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($lo === 0) {
            return '';
        }
        return mb_substr($s, 0, $lo, 'UTF-8');
    }

    /**
     * Calculate the natural dimensions of this layout.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w > 0 && $h > 0) {
            return [$w, $h];
        }

        // Calculate natural size
        $itemSizes = $this->measureItems();
        $itemCount = count($this->items);

        if ($itemCount === 0) {
            return [0, 0];
        }

        $cols = $this->columns;
        $rows = $this->rows > 0 ? $this->rows : (int) ceil($itemCount / $cols);

        // Calculate max cell dimensions
        $cellWidth = 0;
        $cellHeight = 0;
        for ($i = 0; $i < $itemCount; $i++) {
            $cellWidth = max($cellWidth, $itemSizes[$i]['width']);
            $cellHeight = max($cellHeight, $itemSizes[$i]['height']);
        }

        $totalColumnGap = $this->columnGap * ($cols - 1);
        $totalRowGap = $this->rowGap * ($rows - 1);

        return [
            ($cellWidth * $cols) + $totalColumnGap,
            ($cellHeight * $rows) + $totalRowGap,
        ];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Add an item to the grid layout.
     */
    public function withItem(Item $item): self
    {
        return new self(
            items: [...$this->items, $item],
            columns: $this->columns,
            rows: $this->rows,
            columnGap: $this->columnGap,
            rowGap: $this->rowGap,
        );
    }

    /**
     * Set the items in this grid layout.
     *
     * @param list<GridItem> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: $items,
            columns: $this->columns,
            rows: $this->rows,
            columnGap: $this->columnGap,
            rowGap: $this->rowGap,
        );
    }

    /**
     * Set the number of columns.
     */
    public function withColumns(int $columns): self
    {
        return new self(
            items: $this->items,
            columns: max(1, $columns),
            rows: $this->rows,
            columnGap: $this->columnGap,
            rowGap: $this->rowGap,
        );
    }

    /**
     * Set the number of rows.
     */
    public function withRows(int $rows): self
    {
        return new self(
            items: $this->items,
            columns: $this->columns,
            rows: max(0, $rows),
            columnGap: $this->columnGap,
            rowGap: $this->rowGap,
        );
    }

    /**
     * Set the column gap.
     */
    public function withColumnGap(int $gap): self
    {
        return new self(
            items: $this->items,
            columns: $this->columns,
            rows: $this->rows,
            columnGap: max(0, $gap),
            rowGap: $this->rowGap,
        );
    }

    /**
     * Set the row gap.
     */
    public function withRowGap(int $gap): self
    {
        return new self(
            items: $this->items,
            columns: $this->columns,
            rows: $this->rows,
            columnGap: $this->columnGap,
            rowGap: max(0, $gap),
        );
    }

    /**
     * Set both column and row gaps.
     */
    public function withGap(int $gap): self
    {
        return new self(
            items: $this->items,
            columns: $this->columns,
            rows: $this->rows,
            columnGap: max(0, $gap),
            rowGap: max(0, $gap),
        );
    }
}
