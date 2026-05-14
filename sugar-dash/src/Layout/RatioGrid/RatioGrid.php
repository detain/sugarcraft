<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\RatioGrid;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Width;

/**
 * A grid layout that sizes rows and columns by ratio.
 *
 * Based on the termui grid layout pattern.
 */
final class RatioGrid implements Item, Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<list<GridItem>> $grid
     * @param list<NewColRow> $colDefinitions
     * @param list<NewColRow> $rowDefinitions
     */
    public function __construct(
        private readonly array $grid = [],
        private readonly array $colDefinitions = [],
        private readonly array $rowDefinitions = [],
    ) {}

    public static function create(int $columns, int $rows): self
    {
        $colDefs = [];
        for ($i = 0; $i < $columns; $i++) {
            $colDefs[] = NewColRow::ratio(1.0);
        }

        $rowDefs = [];
        for ($i = 0; $i < $rows; $i++) {
            $rowDefs[] = NewColRow::ratio(1.0);
        }

        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $grid[$r] = [];
            for ($c = 0; $c < $columns; $c++) {
                $grid[$r][$c] = GridItem::full('');
            }
        }

        return new self($grid, $colDefs, $rowDefs);
    }

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    public function render(): string
    {
        if ($this->grid === [] || $this->colDefinitions === [] || $this->rowDefinitions === []) {
            return '';
        }

        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w <= 0 || $h <= 0) {
            return '';
        }

        // Calculate column widths based on ratios
        $colWidths = $this->calculateColWidths($w);
        // Calculate row heights based on ratios
        $rowHeights = $this->calculateRowHeights($h);

        $output = [];
        $rowIndex = 0;

        foreach ($this->grid as $row => $rowItems) {
            if (!isset($rowHeights[$row])) {
                continue;
            }
            $rowHeight = $rowHeights[$row];
            $rowLines = array_fill(0, $rowHeight, '');

            $colIndex = 0;
            foreach ($rowItems as $col => $item) {
                if (!isset($colWidths[$col])) {
                    continue;
                }
                $cellWidth = $colWidths[$col];

                $content = $item->content;
                if ($content instanceof Sizer) {
                    $sized = $content->setSize($cellWidth, $rowHeight);
                    $rendered = $sized->render();
                } elseif ($content instanceof Item) {
                    $rendered = $content->render();
                } else {
                    $rendered = (string) $content;
                }

                $contentLines = explode("\n", $rendered);
                for ($lineIdx = 0; $lineIdx < $rowHeight; $lineIdx++) {
                    $line = $contentLines[$lineIdx] ?? '';
                    $lineWidth = Width::string($line);
                    if ($lineWidth < $cellWidth) {
                        $line .= str_repeat(' ', $cellWidth - $lineWidth);
                    }
                    $rowLines[$lineIdx] .= $line;
                }

                $colIndex++;
            }

            $output = array_merge($output, $rowLines);
            $rowIndex++;
        }

        return implode("\n", $output);
    }

    /**
     * @return list<int>
     */
    private function calculateColWidths(int $totalWidth): array
    {
        $widths = [];
        $fixedTotal = 0;
        $ratioSum = 0.0;
        $flexCols = [];

        foreach ($this->colDefinitions as $idx => $def) {
            if ($def->fixed !== null) {
                $widths[$idx] = $def->fixed;
                $fixedTotal += $def->fixed;
            } else {
                $ratioSum += $def->ratio;
                $flexCols[] = $idx;
            }
        }

        $remaining = max(0, $totalWidth - $fixedTotal);
        $ratioUnit = $ratioSum > 0 ? $remaining / $ratioSum : 0;

        foreach ($flexCols as $idx) {
            $widths[$idx] = (int) ($this->colDefinitions[$idx]->ratio * $ratioUnit);
        }

        return $widths;
    }

    /**
     * @return list<int>
     */
    private function calculateRowHeights(int $totalHeight): array
    {
        $heights = [];
        $fixedTotal = 0;
        $ratioSum = 0.0;
        $flexRows = [];

        foreach ($this->rowDefinitions as $idx => $def) {
            if ($def->fixed !== null) {
                $heights[$idx] = $def->fixed;
                $fixedTotal += $def->fixed;
            } else {
                $ratioSum += $def->ratio;
                $flexRows[] = $idx;
            }
        }

        $remaining = max(0, $totalHeight - $fixedTotal);
        $ratioUnit = $ratioSum > 0 ? $remaining / $ratioSum : 0;

        foreach ($flexRows as $idx) {
            $heights[$idx] = (int) ($this->rowDefinitions[$idx]->ratio * $ratioUnit);
        }

        return $heights;
    }

    public function getInnerSize(): array
    {
        return [$this->width ?? 0, $this->height ?? 0];
    }

    public function withCell(int $row, int $col, GridItem $item): self
    {
        $grid = $this->grid;
        if (!isset($grid[$row])) {
            $grid[$row] = [];
        }
        $grid[$row][$col] = $item;

        return new self(
            grid: $grid,
            colDefinitions: $this->colDefinitions,
            rowDefinitions: $this->rowDefinitions,
        );
    }
}
