<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * Customizable interactive table component.
 *
 * Features: column definitions, row data, styled cells, selection,
 * pagination, sorting, filtering, frozen columns, horizontal scroll,
 * zebra striping, missing data indicators, border styling.
 *
 * Port of Evertras/bubble-table.
 *
 * @see https://github.com/Evertras/bubble-table
 */
use SugarCraft\Table\Lang;

final class Table
{
    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var list<Column> */
    private array $columns = [];

    /** @var list<Row> */
    private array $rows = [];

    /** Index of selected row in the current view (filtered+sorted+paged). */
    private int $selectedIndex = 0;

    /** Base style applied to every cell before column/row/cell overrides. */
    private string $baseStyle = '';

    /** Missing data placeholder. */
    private string $missingIndicator = '-';

    /** Border style. */
    private string $borderStyle = '';

    /** Table-level cursor enabled. */
    private bool $selectable = true;

    // Pagination
    private int $pageSize = 0;   // 0 = no pagination
    private int $page     = 0;

    // Sort state: list of ['key' => string, 'asc' => bool]
    /** @var list<array{key: string, asc: bool}> */
    private array $sortColumns = [];

    // Filter state
    /** @var array<string, string>  colKey => filterText */
    private array $filterText = [];

    // Frozen columns (indices)
    /** @var list<int> */
    private array $frozenCols = [];

    // Horizontal scroll offset (character cells)
    private int $scrollX = 0;

    // Viewport virtualization
    /** Number of visible rows. 0 = no virtualization (render all). */
    private int $viewportHeight = 0;

    /** Vertical scroll offset — first visible row index in the filtered+sorted view. */
    private int $scrollY = 0;

    // Zebra stripes
    private bool $zebraEnabled = false;
    private string $zebraStyleOdd  = '100';  // bright black (dim)
    private string $zebraStyleEven = '';

    // Border chars
    private string $borderTopLeft  = '┌';
    private string $borderTop      = '─';
    private string $borderTopRight = '┐';
    private string $borderBottomLeft  = '└';
    private string $borderBottom      = '─';
    private string $borderBottomRight = '┘';
    private string $borderLeft  = '│';
    private string $borderRight = '│';
    private string $borderCenterH = '─';
    private string $borderCenterV = '│';
    private string $borderCross   = '┼';

    private string $headerStyle   = '1;37';  // bold white
    private string $footerStyle   = '90';    // bright black

    private bool $showHeader = true;
    private bool $showFooter = true;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    private function __construct()
    {
    }

    public static function withColumns(array $columns): self
    {
        $t = new self();
        $t->columns = $columns;
        return $t;
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    public function withRows(array $rows): self
    {
        $clone = clone $this;
        $clone->rows    = $rows;
        $clone->selectedIndex = 0;
        return $clone;
    }

    public function withBaseStyle(string $ansiStyle): self
    {
        $clone = clone $this;
        $clone->baseStyle = $ansiStyle;
        return $clone;
    }

    public function withMissingIndicator(string $s): self
    {
        $clone = clone $this;
        $clone->missingIndicator = $s;
        return $clone;
    }

    public function withBorderStyle(string $ansiStyle): self
    {
        $clone = clone $this;
        $clone->borderStyle = $ansiStyle;
        return $clone;
    }

    public function withSelectable(bool $v = true): self
    {
        $clone = clone $this;
        $clone->selectable = $v;
        return $clone;
    }

    public function withPageSize(int $n): self
    {
        $clone = clone $this;
        $clone->pageSize = $n;
        return $clone;
    }

    public function withPage(int $n): self
    {
        $clone = clone $this;
        $clone->page = $n;
        return $clone;
    }

    public function withFrozenCols(array $indices): self
    {
        $clone = clone $this;
        $clone->frozenCols = $indices;
        return $clone;
    }

    public function withScrollX(int $offset): self
    {
        $clone = clone $this;
        $clone->scrollX = \max(0, $offset);
        return $clone;
    }

    public function withViewportHeight(int $height): self
    {
        $clone = clone $this;
        $clone->viewportHeight = \max(0, $height);
        return $clone;
    }

    public function withScrollY(int $offset): self
    {
        $clone = clone $this;
        $clone->scrollY = \max(0, $offset);
        return $clone;
    }

    public function scrollY(): int
    {
        return $this->scrollY;
    }

    public function withZebra(bool $v = true): self
    {
        $clone = clone $this;
        $clone->zebraEnabled = $v;
        return $clone;
    }

    public function withHeaderStyle(string $s): self
    {
        $clone = clone $this;
        $clone->headerStyle = $s;
        return $clone;
    }

    public function withShowHeader(bool $v): self
    {
        $clone = clone $this;
        $clone->showHeader = $v;
        return $clone;
    }

    public function withShowFooter(bool $v): self
    {
        $clone = clone $this;
        $clone->showFooter = $v;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Row data helpers
    // -------------------------------------------------------------------------

    public function addRow(Row $row): self
    {
        $clone = clone $this;
        $clone->rows[] = $row;
        return $clone;
    }

    public function addRows(array $rows): self
    {
        $clone = clone $this;
        foreach ($rows as $row) {
            $clone->rows[] = $row;
        }
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    public function SelectNext(): self
    {
        $view = $this->filteredSortedRows();
        if ($view === []) return $this;

        $clone = clone $this;
        $clone->selectedIndex = \min(\count($view) - 1, $clone->selectedIndex + 1);
        return $clone;
    }

    public function SelectPrevious(): self
    {
        $clone = clone $this;
        $clone->selectedIndex = \max(0, $clone->selectedIndex - 1);
        return $clone;
    }

    public function SelectPage(int $page): self
    {
        $clone = clone $this;
        $clone->page = $page;
        $clone->selectedIndex = 0;
        return $clone;
    }

    public function NextPage(): self
    {
        $total = $this->TotalPages();
        return $this->SelectPage(\min($total - 1, $this->page + 1));
    }

    public function PreviousPage(): self
    {
        return $this->SelectPage(\max(0, $this->page - 1));
    }

    // -------------------------------------------------------------------------
    // Sorting
    // -------------------------------------------------------------------------

    /**
     * Sort by $colKey. If already sorting by it, toggle asc/desc.
     * If $primary is true, replace existing sort list; otherwise append.
     */
    public function SortBy(string $colKey, bool $ascending = true, bool $primary = true): self
    {
        $clone = clone $this;

        if ($primary) {
            // If already primary-sorting on this same key with the same
            // direction, flip direction (toggle semantics).
            if (\count($clone->sortColumns) === 1
                && $clone->sortColumns[0]['key'] === $colKey
                && $clone->sortColumns[0]['asc'] === $ascending
            ) {
                $ascending = !$ascending;
            }
            $clone->sortColumns = [['key' => $colKey, 'asc' => $ascending]];
        } else {
            $idx = null;
            foreach ($clone->sortColumns as $i => $s) {
                if ($s['key'] === $colKey) { $idx = $i; break; }
            }
            if ($idx !== null) {
                $cur = $clone->sortColumns[$idx];
                $clone->sortColumns[$idx] = ['key' => $colKey, 'asc' => !$cur['asc']];
            } else {
                $clone->sortColumns[] = ['key' => $colKey, 'asc' => $ascending];
            }
        }

        $clone->selectedIndex = 0;
        return $clone;
    }

    public function ClearSort(): self
    {
        $clone = clone $this;
        $clone->sortColumns = [];
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Filtering
    // -------------------------------------------------------------------------

    public function Filter(string $colKey, string $text): self
    {
        $clone = clone $this;
        if ($text === '') {
            unset($clone->filterText[$colKey]);
        } else {
            $clone->filterText[$colKey] = $text;
        }
        $clone->selectedIndex = 0;
        return $clone;
    }

    public function ClearFilter(string $colKey): self
    {
        return $this->Filter($colKey, '');
    }

    public function ClearAllFilters(): self
    {
        $clone = clone $this;
        $clone->filterText = [];
        $clone->selectedIndex = 0;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /** @return list<Column> */
    public function Columns(): array { return $this->columns; }

    /** @return list<Row> */
    public function Rows(): array { return $this->rows; }

    /** @return list<Row> */
    public function filteredSortedRows(): array
    {
        $rows = $this->rows;

        // Filter
        if ($this->filterText !== []) {
            $rows = \array_values(
                \array_filter($rows, function (Row $row) use (&$filters): bool {
                    foreach ($this->filterText as $key => $text) {
                        $val = $row->data->get($key);
                        $str = \is_object($val) && method_exists($val, '__toString') ? (string) $val : (string) ($val ?? '');
                        if (\stripos($str, $text) === false) return false;
                    }
                    return true;
                })
            );
        }

        // Sort
        if ($this->sortColumns !== []) {
            \usort($rows, function (Row $a, Row $b): int {
                foreach ($this->sortColumns as $sort) {
                    $key = $sort['key'];
                    $asc = $sort['asc'];

                    $va = $a->data->get($key);
                    $vb = $b->data->get($key);

                    $sa = \is_object($va) && method_exists($va, '__toString') ? (string) $va : (string) ($va ?? '');
                    $sb = \is_object($vb) && method_exists($vb, '__toString') ? (string) $vb : (string) ($vb ?? '');

                    // Numeric sort
                    if (\is_numeric($sa) && \is_numeric($sb)) {
                        $cmp = (float) $sa <=> (float) $sb;
                    } else {
                        $cmp = \strcasecmp($sa, $sb);
                    }

                    if ($cmp !== 0) {
                        return $asc ? $cmp : -$cmp;
                    }
                }
                return 0;
            });
        }

        return $rows;
    }

    /** @return list<Row> The rows on the current page. */
    public function pagedRows(): array
    {
        $rows = $this->filteredSortedRows();
        if ($this->pageSize <= 0) return $rows;

        $offset = $this->page * $this->pageSize;
        return \array_slice($rows, $offset, $this->pageSize);
    }

    public function CurrentRow(): ?Row
    {
        $paged = $this->pagedRows();
        return $paged[$this->selectedIndex] ?? null;
    }

    public function CurrentRowData(): ?RowData
    {
        return $this->CurrentRow()?->data;
    }

    public function TotalRows(): int
    {
        return \count($this->filteredSortedRows());
    }

    public function TotalPages(): int
    {
        if ($this->pageSize <= 0) return 1;
        return \max(1, (int) \ceil($this->TotalRows() / $this->pageSize));
    }

    public function SelectedIndex(): int  { return $this->selectedIndex; }
    public function CurrentPage(): int     { return $this->page; }
    public function PageSize(): int        { return $this->pageSize; }

    public function PageFooter(): string
    {
        return Lang::t('page_of', ['page' => $this->page + 1, 'total' => $this->TotalPages()]);
    }

    /**
     * Compute actual column widths based on ColumnWidth enum values.
     *
     * @return array<int, int>  colIndex => computed width in chars
     */
    public function computeColumnWidths(int $tableWidth): array
    {
        $widths = [];
        $flexCount = 0;
        $reservedWidth = 0;  // borders between columns

        // First pass: collect Fixed/Percent widths, count flexible columns
        foreach ($this->columns as $col) {
            $cw = $col->columnWidth;
            if ($cw === ColumnWidth::Fixed) {
                $widths[] = $col->width;
            } elseif ($cw === ColumnWidth::Percent) {
                $widths[] = (int) \floor($tableWidth * $col->percentValue / 100);
            } else {
                $widths[] = null;  // Dynamic or Content — placeholder
                $flexCount++;
            }
        }

        // Count borders: one between each column
        $borderCount = \count($this->columns) - 1;
        if ($borderCount > 0) {
            $reservedWidth += $borderCount;
        }

        // Flexible columns get remaining space
        if ($flexCount > 0) {
            $fixedWidth = 0;
            foreach ($widths as $w) {
                if ($w !== null) {
                    $fixedWidth += $w;
                }
            }
            $remaining = $tableWidth - $reservedWidth - $fixedWidth;
            $flexWidth = $remaining > 0 ? (int) \floor($remaining / $flexCount) : 0;

            // Apply Dynamic/Content as content-based or minimum flex
            foreach ($this->columns as $i => $col) {
                if ($widths[$i] === null) {
                    $contentLen = $this->contentWidthForColumn($col);
                    if ($col->columnWidth === ColumnWidth::Dynamic) {
                        // Dynamic: use content length or flex width, whichever is larger
                        $widths[$i] = \max($contentLen, $flexWidth);
                    } else {
                        // Content: use exact content length
                        $widths[$i] = \max(1, $contentLen);
                    }
                }
            }
        }

        // Any nulls left (no flex columns) become their original width
        foreach ($widths as $i => $w) {
            if ($w === null) {
                $widths[$i] = $this->columns[$i]->width;
            }
        }

        return $widths;
    }

    /**
     * Estimate the maximum content width for a column from row data.
     */
    private function contentWidthForColumn(Column $col): int
    {
        $maxLen = \strlen($col->title);

        foreach ($this->rows as $row) {
            $val = $row->data->get($col->key);
            if ($val === null) {
                continue;
            }
            $str = \is_object($val) && method_exists($val, '__toString')
                ? (string) $val
                : (\is_scalar($val) ? (string) $val : '');
            $len = \strlen($str);
            if ($len > $maxLen) {
                $maxLen = $len;
            }
        }

        return $maxLen;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function View(): string
    {
        if ($this->columns === []) return '';

        $lines   = [];
        $colCount = \count($this->columns);
        $totalWidth = $this->computeTotalWidth();
        $rows     = $this->pagedRows();

        // Apply viewport virtualization — scrollY offset into the paged view
        if ($this->viewportHeight > 0) {
            if ($this->scrollY >= \count($rows)) {
                $rows = [];  // No rows visible when scrollY exceeds row count
            } else {
                $rows = \array_slice($rows, $this->scrollY, $this->viewportHeight);
            }
        }

        // Top border
        $lines[] = $this->renderTopBorder($totalWidth);

        // Header
        if ($this->showHeader) {
            $lines[] = $this->renderHeader($totalWidth);
            $lines[] = $this->renderHeaderSeparator($totalWidth);
        }

        // Data rows
        foreach ($rows as $ri => $row) {
            // selectedIndex is relative to the full paged view; adjust for scroll offset
            $isSelected = (($ri + $this->scrollY) === $this->selectedIndex) && $this->selectable;
            $rowLines = $this->renderRowLines($row, $ri, $totalWidth, $isSelected);
            foreach ($rowLines as $rowLine) {
                $lines[] = $rowLine;
            }
        }

        // Footer
        if ($this->showFooter && $this->pageSize > 0) {
            $lines[] = $this->renderFooter($totalWidth);
        }

        // Bottom border
        $lines[] = $this->renderBottomBorder($totalWidth);

        return \implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function computeTotalWidth(): int
    {
        $total = 0;
        $flexSum = 0;

        foreach ($this->columns as $col) {
            $total += $col->width;
            $flexSum += $col->flexibleWidth;
        }

        // Borders: one char between each column
        $total += \count($this->columns) - 1;

        return $total;
    }

    private function renderTopBorder(int $totalWidth): string
    {
        $s = $this->borderTopLeft
           . \str_repeat($this->borderTop, $totalWidth)
           . $this->borderTopRight;
        return $this->applyBorderStyle($s);
    }

    private function renderBottomBorder(int $totalWidth): string
    {
        $s = $this->borderBottomLeft
           . \str_repeat($this->borderBottom, $totalWidth)
           . $this->borderBottomRight;
        return $this->applyBorderStyle($s);
    }

    private function renderHeader(int $totalWidth): string
    {
        $cells = [];
        foreach ($this->columns as $col) {
            $cells[] = $col->renderHeader();
        }

        $line = $this->borderLeft
              . \implode($this->borderCenterV, $cells)
              . $this->borderRight;

        return $this->ansi($line, $this->headerStyle);
    }

    private function renderHeaderSeparator(int $totalWidth): string
    {
        $sep = \str_repeat($this->borderCenterH, $totalWidth);
        $line = '├' . $sep . '┤';
        return $this->applyBorderStyle($line);
    }

    private function renderFooter(int $totalWidth): string
    {
        $label = $this->PageFooter();
        $padLeft  = (int) \floor(($totalWidth - \strlen($label)) / 2);
        $padRight = $totalWidth - $padLeft - \strlen($label);
        $content = \str_repeat(' ', $padLeft) . $label . \str_repeat(' ', $padRight);

        $line = $this->borderLeft . $content . $this->borderRight;
        return $this->ansi($line, $this->footerStyle);
    }

    /**
     * Render a row as one or more lines (for wrapped cells).
     *
     * @return list<string>
     */
    private function renderRowLines(Row $row, int $rowIndex, int $totalWidth, bool $isSelected): array
    {
        $columnLines = [];
        $colWidths = [];

        foreach ($this->columns as $colIndex => $col) {
            $val = $row->data->get($col->key);

            if ($val === null) {
                $val = $this->missingIndicator;
            }

            // Determine style precedence: base < column < row < cell
            $style = $this->baseStyle;
            if ($col->style !== '') $style = $col->style;
            if ($row->style !== '') $style = $row->style;
            if ($val instanceof StyledCell && $val->style !== '') $style = $val->style;

            // Zebra override
            if ($this->zebraEnabled) {
                $zebra = ($rowIndex % 2 === 0) ? $this->zebraStyleEven : $this->zebraStyleOdd;
                if ($zebra !== '') $style = $zebra;
            }

            // Cursor override
            if ($isSelected) $style = '7';  // reverse

            if ($val instanceof StyledCell) {
                $val = $val->value;
            }

            $str = \is_object($val) && method_exists($val, '__toString')
                ? (string) $val
                : (\is_scalar($val) ? (string) $val : '');

            $cellLines = $col->renderCell($str);
            // Apply style to each line
            $styledLines = [];
            foreach ($cellLines as $cellLine) {
                $styledLines[] = $this->ansi($cellLine, $style);
            }
            $columnLines[] = $styledLines;
            $colWidths[] = $col->width;
        }

        // Find max number of lines across all columns
        $maxLines = 0;
        foreach ($columnLines as $colLines) {
            if (\count($colLines) > $maxLines) {
                $maxLines = \count($colLines);
            }
        }

        // Build output lines, one per row
        $result = [];
        for ($i = 0; $i < $maxLines; $i++) {
            $cells = [];
            foreach ($columnLines as $ci => $colLines) {
                if (isset($colLines[$i])) {
                    $cells[] = $colLines[$i];
                } else {
                    // Fill with spaces matching the column width
                    $cells[] = \str_repeat(' ', $colWidths[$ci]);
                }
            }
            $result[] = $this->borderLeft . \implode($this->borderCenterV, $cells) . $this->borderRight;
        }

        return $result;
    }

    private function applyBorderStyle(string $s): string
    {
        return $this->borderStyle !== ''
            ? $this->ansi($s, $this->borderStyle)
            : $s;
    }

    private function ansi(string $text, string $codes): string
    {
        if ($codes === '') return $text;
        return "\x1b[{$codes}m{$text}\x1b[0m";
    }
}
