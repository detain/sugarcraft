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
use SugarCraft\Buffer\{Buffer, Cell, Style};
use SugarCraft\Core\Util\{Ansi, Width};
use SugarCraft\Sprinkles\Border;
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

    // Global search across all columns
    private string $searchText = '';

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

    // Zebra stripes.
    // The stripe MUST carry a foreground as well as a background: a background
    // alone leaves the text at the terminal's default foreground, which has no
    // guaranteed contrast against the stripe (near-white default fg over a light
    // stripe renders as invisible text). Pair the light background with a black
    // foreground so striped rows stay readable on any theme — the same way the
    // selected row stays readable via reverse video.
    //
    // The stripe falls on EVEN row indices (0, 2, 4…) so it begins on the first
    // row. The default cursor sits on row 0, whose reverse-video highlight already
    // reads as a light bar; starting the stripe on row 0 lets the two coincide
    // instead of stacking two light rows (selected row + first stripe) at the top.
    private bool $zebraEnabled = false;
    private string $zebraStyleEven = '30;47';  // even rows: black on light-gray
    private string $zebraStyleOdd  = '';

    // Per-cell style callback: (int $row, int $col, string $value): Style|string
    /** @var callable|null */
    private $styleFunc = null;

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

    /** When set, border characters are sourced from this Border object. */
    private ?Border $border = null;

    /** When true, renderRowLines outputs all wrapped cell lines (multi-line rows). */
    private bool $multilineMode = false;

    /** Cached computed column widths from the last render pass. @var array<int, int>|null */
    private ?array $computedColumnWidths = null;

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

    /** Apply an ANSI SGR style string to the default border (e.g. "1;32" for bold green). */
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

    /**
     * Pin columns by index so they remain visible during horizontal scroll.
     *
     * Frozen columns are always rendered, regardless of scrollX offset.
     * Non-frozen columns become visible starting at index count(frozenCols) + scrollX.
     *
     * @param list<int> $indices Column indices to freeze (pin to the left)
     */
    public function withFrozenCols(array $indices): self
    {
        $clone = clone $this;
        $clone->frozenCols = $indices;
        return $clone;
    }

    /**
     * Set the horizontal scroll offset for non-frozen columns.
     *
     * Only affects non-frozen columns. Frozen columns (set via withFrozenCols)
     * are always visible regardless of scrollX.
     *
     * @param int $offset Number of non-frozen columns to skip in rendering
     */
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

    /**
     * Set the border character family for the table.
     *
     * @param Border $border Any Border from \SugarCraft\Sprinkles\Border
     *                      (normal/rounded/thick/double/block/ascii/hidden/markdownBorder)
     */
    public function withBorder(Border $border): self
    {
        $clone = clone $this;
        $clone->border = $border;
        return $clone;
    }

    /**
     * Toggle multi-line row rendering.
     *
     * When enabled, each row's height equals the maximum number of lines
     * across all its cells after text wrapping. When disabled (the default),
     * cells are clamped to one line for backward compatibility.
     *
     * @param bool $multiline True to enable multi-line rows, false to clamp to single line
     */
    public function withMultilineMode(bool $multiline = true): self
    {
        $clone = clone $this;
        $clone->multilineMode = $multiline;
        return $clone;
    }

    /**
     * Set a per-cell style callback.
     *
     * The callback receives (int $row, int $col, string $value) and may return:
     * - A Style object (new API)
     * - An ANSI SGR string like "1;31" for back-compat
     *
     * When not set, existing baseStyle/column style/row style/cell style
     * precedence is used as before.
     *
     * @param callable|null $fn (int $row, int $col, string $value): Style|string
     */
    public function withStyleFunc(?callable $fn): self
    {
        $clone = clone $this;
        $clone->styleFunc = $fn;
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

    /**
     * Move the selection directly to a 0-based row index, clamped to the
     * filtered/sorted view. Mirrors Bubbles' table.SetCursor — lets a caller
     * that tracks its own cursor (e.g. an external Model) drive the highlight
     * without looping SelectNext/SelectPrevious.
     */
    public function withSelectedIndex(int $index): self
    {
        $view = $this->filteredSortedRows();
        if ($view === []) {
            return $this;
        }
        $clone = clone $this;
        $clone->selectedIndex = \max(0, \min(\count($view) - 1, $index));
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

    /**
     * Search across ALL columns (case-insensitive).
     *
     * When $text is empty, clears the global search.
     * Combines with existing column filters — row must match both.
     */
    public function search(string $text): self
    {
        $clone = clone $this;
        $clone->searchText = $text;
        $clone->selectedIndex = 0;
        return $clone;
    }

    /**
     * Clear the global search.
     */
    public function ClearSearch(): self
    {
        return $this->search('');
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

        // Per-column filters (AND logic — row must match ALL column filters)
        if ($this->filterText !== []) {
            $rows = \array_values(
                \array_filter($rows, function (Row $row): bool {
                    foreach ($this->filterText as $key => $text) {
                        $val = $row->data->get($key);
                        $str = \is_object($val) && method_exists($val, '__toString') ? (string) $val : (string) ($val ?? '');
                        if (\stripos($str, $text) === false) return false;
                    }
                    return true;
                })
            );
        }

        // Global search across all columns (OR logic — row matches if ANY column contains search text)
        if ($this->searchText !== '') {
            $searchLower = \strtolower($this->searchText);
            $rows = \array_values(
                \array_filter($rows, function (Row $row) use ($searchLower): bool {
                    foreach ($row->data as $key => $val) {
                        $str = \is_object($val) && method_exists($val, '__toString') ? (string) $val : (string) ($val ?? '');
                        if (\stripos(\strtolower($str), $searchLower) !== false) return true;
                    }
                    return false;
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

        $buffer = $this->renderToBuffer();
        return $buffer->toAnsi();
    }

    /**
     * Render the entire table into a Buffer.
     *
     * Computes column widths via computeColumnWidths() once at the start of
     * the render pass and uses those widths throughout (header, separators,
     * data cells). Widths are cached in $this->computedColumnWidths for the
     * duration of this render.
     *
     * Frozen columns are always rendered on the left. Non-frozen columns
     * scroll horizontally based on scrollX - when scrollX > 0, the first
     * scrollX non-frozen columns are hidden.
     *
     * When multilineMode is enabled, each row's height equals the maximum
     * number of lines across all its cells after text wrapping. Row borders
     * span the full row height.
     */
    private function renderToBuffer(): Buffer
    {
        $colCount = \count($this->columns);
        $totalWidth = $this->computeTotalWidth();
        $rows = $this->pagedRows();

        // Compute and cache column widths for this render pass
        $this->computedColumnWidths = $this->computeColumnWidths($totalWidth);

        // Apply viewport virtualization — scrollY offset into the paged view
        if ($this->viewportHeight > 0) {
            if ($this->scrollY >= \count($rows)) {
                $rows = [];
            } else {
                $rows = \array_slice($rows, $this->scrollY, $this->viewportHeight);
            }
        }

        // Calculate buffer dimensions
        // Height: top border + [header + header sep] + rows + [footer] + bottom border
        $topBorderRows = 1;
        $headerRows = $this->showHeader ? 2 : 0; // header + separator
        $footerRows = ($this->showFooter && $this->pageSize > 0) ? 1 : 0;
        $bottomBorderRows = 1;
        $rowCount = \count($rows);

        // When multilineMode is enabled, pre-calculate row heights
        $rowHeights = [];
        if ($this->multilineMode) {
            foreach ($rows as $ri => $row) {
                $rowHeights[$ri] = $this->calculateRowHeight($row, $this->computedColumnWidths);
            }
            $totalRowHeight = \array_sum($rowHeights);
        } else {
            $totalRowHeight = $rowCount;
        }

        $bufferHeight = $topBorderRows + $headerRows + $totalRowHeight + $footerRows + $bottomBorderRows;
        $bufferWidth = $totalWidth + 2; // +2 for left/right border chars

        // When scrollX > 0, compute visible width for border rows
        // so borders align with visible content rather than extending beyond it
        if ($this->scrollX > 0) {
            $visibleWidth = $this->computeVisibleContentWidth($this->computedColumnWidths);
        } else {
            $visibleWidth = $totalWidth;
        }

        $buffer = Buffer::new($bufferWidth, $bufferHeight);
        $bufferRow = 0;

        // Top border - use visibleWidth so border spans only visible content when scrolled
        $buffer = $this->fillBorderRow($buffer, $bufferRow, $visibleWidth, 'top');
        $bufferRow++;

        // Header
        if ($this->showHeader) {
            $buffer = $this->fillHeaderRow($buffer, $bufferRow, $totalWidth, $this->computedColumnWidths);
            $bufferRow++;
            $buffer = $this->fillHeaderSeparatorRow($buffer, $bufferRow, $visibleWidth);
            $bufferRow++;
        }

        // Data rows
        foreach ($rows as $ri => $row) {
            $isSelected = (($ri + $this->scrollY) === $this->selectedIndex) && $this->selectable;
            if ($this->multilineMode) {
                $buffer = $this->fillDataRowLines($buffer, $bufferRow, $row, $ri, $totalWidth, $isSelected, $this->computedColumnWidths, $rowHeights[$ri]);
                $bufferRow += $rowHeights[$ri];
            } else {
                $buffer = $this->fillDataRow($buffer, $bufferRow, $row, $ri, $totalWidth, $isSelected, $this->computedColumnWidths);
                $bufferRow++;
            }
        }

        // Footer
        if ($this->showFooter && $this->pageSize > 0) {
            $buffer = $this->fillFooterRow($buffer, $bufferRow, $totalWidth);
            $bufferRow++;
        }

        // Bottom border
        $buffer = $this->fillBorderRow($buffer, $bufferRow, $visibleWidth, 'bottom');

        return $buffer;
    }

    /**
     * Determine if a column at the given index is visible.
     *
     * Frozen columns are always visible. Non-frozen columns are visible
     * starting at index = count(frozenCols) + scrollX.
     */
    private function isColumnVisible(int $colIndex): bool
    {
        if (\in_array($colIndex, $this->frozenCols, true)) {
            return true;
        }
        $scrollableStartIndex = \count($this->frozenCols) + $this->scrollX;
        return $colIndex >= $scrollableStartIndex;
    }

    /**
     * Compute the total width of visible columns plus separators between them.
     *
     * Iterates columns in order, summing widths of visible columns and adding
     * a 1-character separator after each column that has a visible column after it.
     * Uses isColumnVisible() to determine which columns to include.
     */
    private function computeVisibleContentWidth(array $computedWidths): int
    {
        $width = 0;
        $lastVisibleCi = null;
        foreach ($this->columns as $ci => $column) {
            if (!$this->isColumnVisible($ci)) {
                continue;
            }
            $width += $computedWidths[$ci] ?? $column->width;
            // Add separator after this column if there's a visible column after it
            if ($lastVisibleCi !== null) {
                $width++; // separator between consecutive visible columns
            }
            $lastVisibleCi = $ci;
        }
        return $width;
    }

    private function fillBorderRow(Buffer $buffer, int $row, int $contentWidth, string $type): Buffer
    {
        $style = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;
        $borderStyle = $type === 'top'
            ? [$this->borderTopLeft(), $this->borderTop(), $this->borderTopRight()]
            : [$this->borderBottomLeft(), $this->borderBottom(), $this->borderBottomRight()];

        $cells = [];
        $cells[] = new Cell($borderStyle[0], $style, null, 1);
        $fillWidth = $contentWidth;
        $cells[] = new Cell(\str_repeat($borderStyle[1], $fillWidth), $style, null, $fillWidth);
        $cells[] = new Cell($borderStyle[2], $style, null, 1);

        $col = 0;
        foreach ($cells as $cell) {
            $w = $cell->width();
            for ($c = 0; $c < $w; $c++) {
                $actualWidth = ($c === 0) ? $w : 0;
                $rune = ($c === 0) ? $cell->rune() : '';
                $cellToWrite = new Cell($rune, $cell->style(), $cell->link(), $actualWidth);
                $buffer = $buffer->withCellAt($col, $row, $cellToWrite);
                $col++;
            }
        }

        return $buffer;
    }

    private function fillHeaderRow(Buffer $buffer, int $row, int $contentWidth, array $computedWidths): Buffer
    {
        $style = $this->parseAnsiToStyle($this->headerStyle);
        $col = 0;

        // Left border
        $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderLeft(), $style, null, 1));
        $col++;

        // Header cells - render only visible columns
        foreach ($this->columns as $ci => $column) {
            $colWidth = $computedWidths[$ci] ?? $column->width;

            // Skip hidden columns (non-frozen before scrollX offset)
            if (!$this->isColumnVisible($ci)) {
                $col += $colWidth;
                continue;
            }

            $headerText = $column->renderHeader($colWidth);
            $buffer = $this->fillCellContent($buffer, $row, $col, $headerText, $colWidth, $style);
            $col += $colWidth;

            // Column separator - only drawn between actual visible columns.
            // A separator is needed after ci if:
            // - ci is frozen (always marks boundary even when next is hidden), OR
            // - ci is visible AND the next column (ci+1) is also visible
            if ($ci < \count($this->columns) - 1) {
                $ciFrozen = \in_array($ci, $this->frozenCols, true);
                $nextVisible = $this->isColumnVisible($ci + 1);
                if ($ciFrozen || $nextVisible) {
                    $sepStyle = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;
                    $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderCenterV(), $sepStyle, null, 1));
                    $col++;
                }
            }
        }

        // Right border
        $sepStyle = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;
        $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderRight(), $sepStyle, null, 1));

        return $buffer;
    }

    private function fillHeaderSeparatorRow(Buffer $buffer, int $row, int $contentWidth): Buffer
    {
        $style = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;
        $col = 0;

        // Left border
        $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderLeft(), $style, null, 1));
        $col++;

        // Separator
        $buffer = $this->fillCellContent($buffer, $row, $col, \str_repeat($this->borderCenterH(), $contentWidth), $contentWidth, $style);
        $col += $contentWidth;

        // Right border
        $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderRight(), $style, null, 1));

        return $buffer;
    }

    private function fillDataRow(Buffer $buffer, int $row, Row $rowData, int $rowIndex, int $contentWidth, bool $isSelected, array $computedWidths): Buffer
    {
        $col = 0;

        // Determine row-level style
        $rowStyle = '';
        if ($rowData->style !== '') {
            $rowStyle = $rowData->style;
        }
        if ($this->zebraEnabled) {
            $zebra = ($rowIndex % 2 === 0) ? $this->zebraStyleEven : $this->zebraStyleOdd;
            if ($zebra !== '') $rowStyle = $zebra;
        }
        if ($isSelected) $rowStyle = '7'; // reverse

        // Left border
        $style = $rowStyle !== '' ? $this->parseAnsiToStyle($rowStyle) : null;
        $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderLeft(), $style, null, 1));
        $col++;

        // Data cells - render only visible columns
        foreach ($this->columns as $ci => $column) {
            $colWidth = $computedWidths[$ci] ?? $column->width;

            // Skip hidden columns (non-frozen before scrollX offset)
            if (!$this->isColumnVisible($ci)) {
                $col += $colWidth;
                continue;
            }

            $val = $rowData->data->get($column->key);

            if ($val === null) {
                $val = $this->missingIndicator;
            }

            // Determine cell-level style precedence: base < column < row < cell < selection
            $cellStyle = $this->baseStyle;
            if ($column->style !== '') $cellStyle = $column->style;
            if ($rowStyle !== '') $cellStyle = $rowStyle;
            if ($val instanceof StyledCell && $val->style !== '') $cellStyle = $val->style;

            // styleFunc callback
            $cellStr = '';
            if ($val instanceof StyledCell) {
                $cellStr = \is_object($val->value) && method_exists($val->value, '__toString')
                    ? (string) $val->value
                    : (\is_scalar($val->value) ? (string) $val->value : '');
            } else {
                $cellStr = \is_object($val) && method_exists($val, '__toString')
                    ? (string) $val
                    : (\is_scalar($val) ? (string) $val : '');
            }

            if ($this->styleFunc !== null) {
                $rawResult = ($this->styleFunc)($rowIndex, $ci, $cellStr);
                $cellStyle = $this->normalizeStyleResult($rawResult, $cellStyle);
            }

            $style = $cellStyle !== '' ? $this->parseAnsiToStyle($cellStyle) : null;

            $displayText = $column->alignLeft
                ? \SugarCraft\Core\Util\Width::padRight($cellStr, $colWidth)
                : \SugarCraft\Core\Util\Width::padLeft($cellStr, $colWidth);

            $buffer = $this->fillCellContent($buffer, $row, $col, $displayText, $colWidth, $style);
            $col += $colWidth;

            // Column separator - only drawn between actual visible columns.
            // A separator is needed after ci if:
            // - ci is frozen (always marks boundary even when next is hidden), OR
            // - ci is visible AND the next column (ci+1) is also visible
            if ($ci < \count($this->columns) - 1) {
                $ciFrozen = \in_array($ci, $this->frozenCols, true);
                $nextVisible = $this->isColumnVisible($ci + 1);
                if ($ciFrozen || $nextVisible) {
                    $sepStyle = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;
                    $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderCenterV(), $sepStyle, null, 1));
                    $col++;
                }
            }
        }

        // Right border
        $sepStyle = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;
        $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderRight(), $sepStyle, null, 1));

        return $buffer;
    }

    /**
     * Calculate the height of a row in lines based on cell content after wrapping.
     *
     * When multilineMode is enabled, row height equals the maximum number of
     * lines across all visible cells. This accounts for text wrapping in each cell.
     */
    private function calculateRowHeight(Row $row, array $computedWidths): int
    {
        $maxLines = 1;

        foreach ($this->columns as $ci => $column) {
            if (!$this->isColumnVisible($ci)) {
                continue;
            }

            $colWidth = $computedWidths[$ci] ?? $column->width;
            $val = $row->data->get($column->key);
            $cellStr = '';
            if ($val instanceof StyledCell) {
                $cellStr = \is_object($val->value) && method_exists($val->value, '__toString')
                    ? (string) $val->value
                    : (\is_scalar($val->value) ? (string) $val->value : '');
            } else {
                $cellStr = \is_object($val) && method_exists($val, '__toString')
                    ? (string) $val
                    : (\is_scalar($val) ? (string) $val : '');
            }

            $lines = $column->renderCell($cellStr, $colWidth);
            $lineCount = \count($lines);
            if ($lineCount > $maxLines) {
                $maxLines = $lineCount;
            }
        }

        return $maxLines;
    }

    /**
     * Render a data row with multiple lines when multilineMode is enabled.
     *
     * Each cell's content is rendered across the row's height (max lines across
     * all cells). Cells with fewer lines are padded vertically with empty space.
     * Row borders span the full row height.
     */
    private function fillDataRowLines(Buffer $buffer, int $startRow, Row $row, int $rowIndex, int $contentWidth, bool $isSelected, array $computedWidths, int $rowHeight): Buffer
    {
        // Determine row-level style
        $rowStyle = '';
        if ($row->style !== '') {
            $rowStyle = $row->style;
        }
        if ($this->zebraEnabled) {
            $zebra = ($rowIndex % 2 === 0) ? $this->zebraStyleEven : $this->zebraStyleOdd;
            if ($zebra !== '') $rowStyle = $zebra;
        }
        if ($isSelected) $rowStyle = '7'; // reverse

        $style = $rowStyle !== '' ? $this->parseAnsiToStyle($rowStyle) : null;
        $sepStyle = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;

        // Collect cell lines for each column (only visible columns)
        $cellLines = [];
        $colWidths = [];
        foreach ($this->columns as $ci => $column) {
            if (!$this->isColumnVisible($ci)) {
                continue;
            }

            $colWidth = $computedWidths[$ci] ?? $column->width;
            $colWidths[$ci] = $colWidth;

            $val = $row->data->get($column->key);
            if ($val === null) {
                $val = $this->missingIndicator;
            }

            // Determine cell-level style precedence: base < column < row < cell < selection
            $cellStyle = $this->baseStyle;
            if ($column->style !== '') $cellStyle = $column->style;
            if ($rowStyle !== '') $cellStyle = $rowStyle;
            if ($val instanceof StyledCell && $val->style !== '') $cellStyle = $val->style;

            $cellStr = '';
            if ($val instanceof StyledCell) {
                $cellStr = \is_object($val->value) && method_exists($val->value, '__toString')
                    ? (string) $val->value
                    : (\is_scalar($val->value) ? (string) $val->value : '');
            } else {
                $cellStr = \is_object($val) && method_exists($val, '__toString')
                    ? (string) $val
                    : (\is_scalar($val) ? (string) $val : '');
            }

            if ($this->styleFunc !== null) {
                $rawResult = ($this->styleFunc)($rowIndex, $ci, $cellStr);
                $cellStyle = $this->normalizeStyleResult($rawResult, $cellStyle);
            }

            $parsedStyle = $cellStyle !== '' ? $this->parseAnsiToStyle($cellStyle) : null;
            $lines = $column->renderCell($cellStr, $colWidth);
            $cellLines[$ci] = ['lines' => $lines, 'style' => $parsedStyle, 'width' => $colWidth];
        }

        // Render each line of the row
        for ($lineIdx = 0; $lineIdx < $rowHeight; $lineIdx++) {
            $bufferRow = $startRow + $lineIdx;
            $col = 0;

            // Left border (full height)
            $buffer = $buffer->withCellAt($col, $bufferRow, new Cell($this->borderLeft(), $style, null, 1));
            $col++;

            // Render each visible cell
            foreach ($this->columns as $ci => $column) {
                if (!$this->isColumnVisible($ci)) {
                    $col += $computedWidths[$ci] ?? $column->width;
                    continue;
                }

                $colWidth = $colWidths[$ci];
                $cellData = $cellLines[$ci];
                $lines = $cellData['lines'];
                $cellStyle = $cellData['style'];

                // Get the line to render at this row position
                if ($lineIdx < \count($lines)) {
                    $displayText = $lines[$lineIdx];
                } else {
                    // Pad with empty space for shorter cells
                    $displayText = \str_repeat(' ', $colWidth);
                }

                $buffer = $this->fillCellContent($buffer, $bufferRow, $col, $displayText, $colWidth, $cellStyle);
                $col += $colWidth;

                // Column separator - only drawn between actual visible columns.
                // A separator is needed after ci if:
                // - ci is frozen (always marks boundary even when next is hidden), OR
                // - ci is visible AND the next column (ci+1) is also visible
                if ($ci < \count($this->columns) - 1) {
                    $ciFrozen = \in_array($ci, $this->frozenCols, true);
                    $nextVisible = $this->isColumnVisible($ci + 1);
                    if ($ciFrozen || $nextVisible) {
                        $buffer = $buffer->withCellAt($col, $bufferRow, new Cell($this->borderCenterV(), $sepStyle, null, 1));
                        $col++;
                    }
                }
            }

            // Right border (full height)
            $buffer = $buffer->withCellAt($col, $bufferRow, new Cell($this->borderRight(), $sepStyle, null, 1));
        }

        return $buffer;
    }

    private function fillFooterRow(Buffer $buffer, int $row, int $contentWidth): Buffer
    {
        $style = $this->parseAnsiToStyle($this->footerStyle);
        $label = $this->PageFooter();
        $padLeft = (int) \floor(($contentWidth - \strlen($label)) / 2);
        $padRight = $contentWidth - $padLeft - \strlen($label);
        $content = \str_repeat(' ', $padLeft) . $label . \str_repeat(' ', $padRight);

        $col = 0;
        $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderLeft(), $style, null, 1));
        $col++;
        $buffer = $this->fillCellContent($buffer, $row, $col, $content, $contentWidth, $style);
        $col += $contentWidth;
        $buffer = $buffer->withCellAt($col, $row, new Cell($this->borderRight(), $style, null, 1));

        return $buffer;
    }

    /**
     * Fill a region of the buffer with cell content, handling wide characters.
     */
    private function fillCellContent(Buffer $buffer, int $row, int $startCol, string $text, int $cellWidth, ?Style $style): Buffer
    {
        $clusters = $this->graphemeClusters($text);
        $col = $startCol;

        foreach ($clusters as $cluster) {
            $gw = $this->graphemeWidth($cluster);
            $gw = $gw === 0 ? 1 : $gw; // Minimum 1 cell

            // Clamp to remaining width
            $remaining = $cellWidth - ($col - $startCol);
            if ($gw > $remaining) {
                $gw = $remaining;
            }
            if ($gw <= 0) {
                break;
            }

            $cell = new Cell($cluster, $style, null, $gw);
            $buffer = $buffer->withCellAt($col, $row, $cell);
            $col += $gw;

            // Add continuation cell for wide chars
            if ($gw === 2) {
                $buffer = $buffer->withCellAt($col, $row, Cell::continuation());
                $col++;
            }
        }

        // Fill remaining width with spaces if needed
        while (($col - $startCol) < $cellWidth) {
            $buffer = $buffer->withCellAt($col, $row, new Cell(' ', $style, null, 1));
            $col++;
        }

        return $buffer;
    }

    /**
     * Normalize a styleFunc result to an ANSI SGR string.
     * Returns Style object if already Style, or converts string to Style.
     */
    private function normalizeStyleResult(mixed $result, string $fallback): string
    {
        if ($result instanceof Style) {
            return $this->styleToAnsi($result);
        }
        if (\is_string($result)) {
            return $result;
        }
        return $fallback;
    }

    /**
     * Convert a Buffer\Style back to an ANSI SGR string for backward compatibility.
     */
    private function styleToAnsi(Style $style): string
    {
        $codes = [];

        if ($style->fg() !== null) {
            $r = ($style->fg() >> 16) & 0xFF;
            $g = ($style->fg() >> 8) & 0xFF;
            $b = $style->fg() & 0xFF;
            $codes[] = "38;2;{$r};{$g};{$b}";
        }

        if ($style->bg() !== null) {
            $r = ($style->bg() >> 16) & 0xFF;
            $g = ($style->bg() >> 8) & 0xFF;
            $b = $style->bg() & 0xFF;
            $codes[] = "48;2;{$r};{$g};{$b}";
        }

        $attrs = $style->attrs();
        if ($attrs & Style::ATTR_BOLD)       { $codes[] = '1'; }
        if ($attrs & Style::ATTR_FAINT)     { $codes[] = '2'; }
        if ($attrs & Style::ATTR_ITALIC)    { $codes[] = '3'; }
        if ($attrs & Style::ATTR_UNDERLINE) { $codes[] = '4'; }
        if ($attrs & Style::ATTR_BLINK)     { $codes[] = '5'; }
        if ($attrs & Style::ATTR_REVERSE)  { $codes[] = '7'; }
        if ($attrs & Style::ATTR_STRIKE)   { $codes[] = '9'; }
        if ($attrs & Style::ATTR_OVERLINE)  { $codes[] = '53'; }

        return \implode(';', $codes);
    }

    /**
     * Parse an ANSI SGR string (e.g. "1;31" or "38;2;255;0;0") into a Style object.
     */
    private function parseAnsiToStyle(string $codes): Style
    {
        if ($codes === '') {
            return Style::new();
        }

        $fg = null;
        $bg = null;
        $attrs = 0;

        $parts = \explode(';', $codes);
        $i = 0;
        $count = \count($parts);

        while ($i < $count) {
            $code = (int) ($parts[$i] ?? 0);
            $i++;

            switch ($code) {
                case 0:
                    // Reset - ignore, Style::new() is already empty
                    break;
                case 1:
                    $attrs |= Style::ATTR_BOLD;
                    break;
                case 2:
                    $attrs |= Style::ATTR_FAINT;
                    break;
                case 3:
                    $attrs |= Style::ATTR_ITALIC;
                    break;
                case 4:
                    $attrs |= Style::ATTR_UNDERLINE;
                    break;
                case 5:
                    $attrs |= Style::ATTR_BLINK;
                    break;
                case 7:
                    $attrs |= Style::ATTR_REVERSE;
                    break;
                case 9:
                    $attrs |= Style::ATTR_STRIKE;
                    break;
                case 53:
                    $attrs |= Style::ATTR_OVERLINE;
                    break;
                case 38:
                    // Extended foreground color
                    if ($i < $count) {
                        $type = (int) $parts[$i];
                        $i++;
                        if ($type === 2 && $i + 2 < $count) {
                            // 38;2;r;g;b
                            $r = (int) $parts[$i];
                            $g = (int) $parts[$i + 1];
                            $b = (int) $parts[$i + 2];
                            $fg = ($r << 16) | ($g << 8) | $b;
                            $i += 3;
                        } elseif ($type === 5 && $i < $count) {
                            // 38;5;n (256-color)
                            $idx = (int) $parts[$i];
                            $fg = $this->color256ToRgb($idx, true);
                            $i++;
                        }
                    }
                    break;
                case 48:
                    // Extended background color
                    if ($i < $count) {
                        $type = (int) $parts[$i];
                        $i++;
                        if ($type === 2 && $i + 2 < $count) {
                            // 48;2;r;g;b
                            $r = (int) $parts[$i];
                            $g = (int) $parts[$i + 1];
                            $b = (int) $parts[$i + 2];
                            $bg = ($r << 16) | ($g << 8) | $b;
                            $i += 3;
                        } elseif ($type === 5 && $i < $count) {
                            // 48;5;n (256-color)
                            $idx = (int) $parts[$i];
                            $bg = $this->color256ToRgb($idx, false);
                            $i++;
                        }
                    }
                    break;
                // Standard colors 30-37 (fg) and 40-47 (bg)
                case 30: $fg = 0x000000; break;
                case 31: $fg = 0xcc0000; break;
                case 32: $fg = 0x00cc00; break;
                case 33: $fg = 0xcccc00; break;
                case 34: $fg = 0x0000cc; break;
                case 35: $fg = 0xcc00cc; break;
                case 36: $fg = 0x00cccc; break;
                case 37: $fg = 0xcccccc; break;
                case 40: $bg = 0x000000; break;
                case 41: $bg = 0xcc0000; break;
                case 42: $bg = 0x00cc00; break;
                case 43: $bg = 0xcccc00; break;
                case 44: $bg = 0x0000cc; break;
                case 45: $bg = 0xcc00cc; break;
                case 46: $bg = 0x00cccc; break;
                case 47: $bg = 0xcccccc; break;
                // Bright colors 90-97 (fg) and 100-107 (bg)
                case 90: $fg = 0x808080; break;
                case 91: $fg = 0xff0000; break;
                case 92: $fg = 0x00ff00; break;
                case 93: $fg = 0xffff00; break;
                case 94: $fg = 0x0000ff; break;
                case 95: $fg = 0xff00ff; break;
                case 96: $fg = 0x00ffff; break;
                case 97: $fg = 0xffffff; break;
                case 100: $bg = 0x808080; break;
                case 101: $bg = 0xff0000; break;
                case 102: $bg = 0x00ff00; break;
                case 103: $bg = 0xffff00; break;
                case 104: $bg = 0x0000ff; break;
                case 105: $bg = 0xff00ff; break;
                case 106: $bg = 0x00ffff; break;
                case 107: $bg = 0xffffff; break;
            }
        }

        return Style::new($fg, $bg, $attrs);
    }

    /**
     * Convert a 256-color index to RGB.
     */
    private function color256ToRgb(int $idx, bool $isFg): int
    {
        if ($idx < 16) {
            // Standard colors (same as 30-37 / 40-47)
            $colors = [
                0x000000, 0xcc0000, 0x00cc00, 0xcccc00,
                0x0000cc, 0xcc00cc, 0x00cccc, 0xcccccc,
                0x808080, 0xff0000, 0x00ff00, 0xffff00,
                0x0000ff, 0xff00ff, 0x00ffff, 0xffffff,
            ];
            return $colors[$idx] ?? 0x000000;
        }
        if ($idx < 232) {
            // 216-color cube (6x6x6)
            $idx -= 16;
            $r = (int) ($idx / 36);
            $g = (int) (($idx % 36) / 6);
            $b = $idx % 6;
            $r = $r * 51;
            $g = $g * 51;
            $b = $b * 51;
            return ($r << 16) | ($g << 8) | $b;
        }
        // Grayscale
        $gray = (int) (($idx - 232) * 10 + 8);
        return ($gray << 16) | ($gray << 8) | $gray;
    }

    /**
     * Split a string into grapheme clusters.
     *
     * @return list<string>
     */
    private function graphemeClusters(string $text): array
    {
        if ($text === '') {
            return [];
        }
        // grapheme_str_split is PHP 8.4+; cascade to codepoint-level splitters on
        // 8.3 so multi-byte text (box-drawing chars, etc.) renders identically
        // across PHP versions. Mirrors candy-core Util\Width::graphemes().
        if (\function_exists('grapheme_str_split')) {
            $result = @grapheme_str_split($text);
            if (\is_array($result)) {
                return $result;
            }
        }
        if (\function_exists('mb_str_split')) {
            return \mb_str_split($text, 1, 'UTF-8');
        }
        return \preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Get the display width of a single grapheme cluster.
     */
    private function graphemeWidth(string $cluster): int
    {
        if ($cluster === '') {
            return 0;
        }
        $cp = $this->firstCodepoint($cluster);
        if ($cp === 0) {
            return 0;
        }
        if ($this->isZeroWidth($cp)) {
            return 0;
        }
        if ($this->isWide($cp)) {
            return 2;
        }
        return 1;
    }

    private function firstCodepoint(string $g): int
    {
        $b1 = \ord($g[0]);
        if ($b1 < 0x80) {
            return $b1;
        }
        if (($b1 & 0xe0) === 0xc0 && \strlen($g) >= 2) {
            return (($b1 & 0x1f) << 6) | (\ord($g[1]) & 0x3f);
        }
        if (($b1 & 0xf0) === 0xe0 && \strlen($g) >= 3) {
            return (($b1 & 0x0f) << 12) | ((\ord($g[1]) & 0x3f) << 6) | (\ord($g[2]) & 0x3f);
        }
        if (($b1 & 0xf8) === 0xf0 && \strlen($g) >= 4) {
            return (($b1 & 0x07) << 18) | ((\ord($g[1]) & 0x3f) << 12)
                | ((\ord($g[2]) & 0x3f) << 6) | (\ord($g[3]) & 0x3f);
        }
        return 0;
    }

    private function isZeroWidth(int $cp): bool
    {
        if ($cp < 0x20) return true;
        if ($cp >= 0x7f && $cp < 0xa0) return true;
        if ($cp === 0x200b || $cp === 0x200c || $cp === 0x200d || $cp === 0xfeff) return true;
        if ($cp >= 0x0300 && $cp <= 0x036f) return true;
        if ($cp >= 0x1dc0 && $cp <= 0x1dff) return true;
        if ($cp >= 0x20d0 && $cp <= 0x20ff) return true;
        if ($cp >= 0xfe00 && $cp <= 0xfe0f) return true;
        if ($cp >= 0xfe20 && $cp <= 0xfe2f) return true;
        return false;
    }

    private function isWide(int $cp): bool
    {
        if ($cp < 0x1100) return false;
        return ($cp <= 0x115f)
            || ($cp >= 0x2e80 && $cp <= 0x303e)
            || ($cp >= 0x3041 && $cp <= 0x33ff)
            || ($cp >= 0x3400 && $cp <= 0x4dbf)
            || ($cp >= 0x4e00 && $cp <= 0x9fff)
            || ($cp >= 0xa000 && $cp <= 0xa4cf)
            || ($cp >= 0xac00 && $cp <= 0xd7a3)
            || ($cp >= 0xf900 && $cp <= 0xfaff)
            || ($cp >= 0xfe30 && $cp <= 0xfe4f)
            || ($cp >= 0xff00 && $cp <= 0xff60)
            || ($cp >= 0xffe0 && $cp <= 0xffe6)
            || ($cp >= 0x1f300 && $cp <= 0x1f64f)
            || ($cp >= 0x1f680 && $cp <= 0x1f6ff)
            || ($cp >= 0x1f900 && $cp <= 0x1f9ff)
            || ($cp >= 0x20000 && $cp <= 0x2fffd)
            || ($cp >= 0x30000 && $cp <= 0x3fffd);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function borderTopLeft(): string
    {
        return $this->border?->topLeft ?? $this->borderTopLeft;
    }

    private function borderTop(): string
    {
        return $this->border?->top ?? $this->borderTop;
    }

    private function borderTopRight(): string
    {
        return $this->border?->topRight ?? $this->borderTopRight;
    }

    private function borderBottomLeft(): string
    {
        return $this->border?->bottomLeft ?? $this->borderBottomLeft;
    }

    private function borderBottom(): string
    {
        return $this->border?->bottom ?? $this->borderBottom;
    }

    private function borderBottomRight(): string
    {
        return $this->border?->bottomRight ?? $this->borderBottomRight;
    }

    private function borderLeft(): string
    {
        return $this->border?->left ?? $this->borderLeft;
    }

    private function borderRight(): string
    {
        return $this->border?->right ?? $this->borderRight;
    }

    private function borderCenterH(): string
    {
        return $this->border?->middle ?? $this->borderCenterH;
    }

    private function borderCenterV(): string
    {
        return $this->border?->middleLeft ?? $this->borderCenterV;
    }

    private function borderCross(): string
    {
        return $this->border?->middle ?? $this->borderCross;
    }

    private function computeTotalWidth(): int
    {
        // Sum raw column widths as initial estimate
        $total = 0;
        foreach ($this->columns as $col) {
            $total += $col->width;
        }

        // Borders: one char between each column
        $total += \count($this->columns) - 1;

        // Use computeColumnWidths to get accurate widths (accounts for Percent/Dynamic/Content)
        // This ensures consistency between total width and actual column rendering widths
        $computedWidths = $this->computeColumnWidths($total);
        $computedTotal = 0;
        foreach ($computedWidths as $w) {
            $computedTotal += $w;
        }
        // Add border count to match rendering
        $computedTotal += \count($this->columns) - 1;

        return $computedTotal;
    }

}
