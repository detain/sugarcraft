<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\GridTable;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;

/**
 * A full-featured data grid with sort, filter, pagination, and column freezing.
 *
 * Mirrors tealeaves/teagrid from the Charmbracelet ecosystem adapted to PHP
 * with wither-style immutable setters.
 */
final class GridTable implements Item, Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<Column> */
    private array $columns;

    /** @var list<Row> */
    private array $rows;

    private SortState $sortState;
    private string $filterText = '';
    private Pagination $pagination;
    private BorderConfig $borderConfig;
    private int $frozenColumns = 0;
    private int $scrollTo = 0;
    private ?int $explicitPerPage = null;

    /** @var array<string, true> Column keys already warned about by sort() (D-51, finding 148). */
    private static array $unsortableWarned = [];

    public function __construct(
        array $columns = [],
        array $rows = [],
        ?SortState $sortState = null,
        ?BorderConfig $borderConfig = null,
    ) {
        $this->columns = $columns;
        $this->rows = $rows;
        $this->sortState = $sortState ?? new SortState();
        $this->borderConfig = $borderConfig ?? BorderConfig::new();
        $this->pagination = new Pagination(page: 1, perPage: 20, totalRows: count($rows));
    }

    /**
     * Create a new GridTable with columns and rows.
     *
     * @param list<Column> $columns
     * @param list<Row> $rows
     */
    public static function new(array $columns, array $rows = []): self
    {
        return new self($columns, $rows);
    }

    /**
     * Set the allocated dimensions for this grid.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;

        // Recalculate pagination based on available height. The allocation
        // is the ceiling; an explicit withPerPage() wins below it (D-51,
        // finding 144: this used to stomp any explicit page size). The
        // budget subtracts the chrome THIS config actually emits (D-52,
        // finding 045: height-2 assumed two border lines while the default
        // BorderConfig paints five).
        if ($height > 0) {
            $availableRows = $height - $this->chromeLines();
            if ($availableRows > 0) {
                $perPage = $this->explicitPerPage === null
                    ? $availableRows
                    : min($this->explicitPerPage, $availableRows);
                $clone->pagination = $clone->pagination->withPerPage($perPage);
            }
        }

        return $clone;
    }

    /**
     * Number of non-data lines the configured frame emits around the body:
     * outer borders, the always-present header row, the header and footer
     * separators (the footer separator is budgeted even though an empty
     * result suppresses it — the page size must never depend on the data).
     */
    private function chromeLines(): int
    {
        $c = $this->borderConfig;

        return ($c->showOuter ? 2 : 0)
            + 1
            + ($c->showHeader ? 1 : 0)
            + ($c->showFooter ? 1 : 0);
    }

    /**
     * Get current width.
     */
    public function width(): ?int
    {
        return $this->width;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::width()} instead. REMOVED-IN: 2.0.0.
     */
    public function getWidth(): ?int { return $this->width(); }

    /**
     * Get current height.
     */
    public function height(): ?int
    {
        return $this->height;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::height()} instead. REMOVED-IN: 2.0.0.
     */
    public function getHeight(): ?int { return $this->height(); }

    // ═══════════════════════════════════════════════════════════════
    // Fluent withers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Return a new GridTable with the given columns.
     *
     * @param list<Column> $columns
     */
    public function withColumns(array $columns): self
    {
        $clone = clone $this;
        $clone->columns = $columns;
        return $clone;
    }

    /**
     * Return a new GridTable with the given rows.
     *
     * @param list<Row> $rows
     */
    public function withRows(array $rows): self
    {
        $clone = clone $this;
        $clone->rows = $rows;
        $clone->pagination = $clone->pagination->withTotalRows(count($rows));
        return $clone;
    }

    /**
     * Return a new GridTable showing at most N rows per page.
     *
     * The explicit request survives later setSize() calls as long as the
     * allocation fits it; when the frame is smaller, the allocation caps
     * it (D-51, finding 144).
     */
    public function withPerPage(int $perPage): self
    {
        $clone = clone $this;
        $clone->explicitPerPage = max(1, $perPage);
        $available = $this->height === null ? 0 : $this->height - $this->chromeLines();
        $effective = $available > 0
            ? min($clone->explicitPerPage, $available)
            : $clone->explicitPerPage;
        $clone->pagination = $clone->pagination->withPerPage($effective);
        return $clone;
    }

    /**
     * Return a new GridTable with sort applied to the given column.
     */
    public function sort(Column $column): self
    {
        if (!$column->sortable) {
            $this->warnUnsortable($column->key);
            return $this;
        }

        $clone = clone $this;
        $clone->sortState = $this->sortState->toggle($column->key);
        return $clone;
    }

    /**
     * One-shot notice when sort() is asked for a non-sortable column
     * (D-51, finding 148): the refusal used to be silent, so a forgotten
     * sortable flag looked exactly like a no-op sort. Latched once per
     * key per process; Foundation\Notice is the [OG-3]-gated D-15
     * mechanism this prefigures, not a dependency.
     */
    private function warnUnsortable(string $key): void
    {
        if (isset(self::$unsortableWarned[$key])) {
            return;
        }

        self::$unsortableWarned[$key] = true;
        trigger_error("GridTable: column '{$key}' is not sortable; sort() ignored.", E_USER_NOTICE);
    }

    /**
     * @internal Test seam: clear the one-shot sort-refusal latch.
     */
    public static function resetUnsortableNotices(): void
    {
        self::$unsortableWarned = [];
    }

    /**
     * Return a new GridTable with the given filter applied.
     */
    public function filter(string $filterText): self
    {
        $clone = clone $this;
        $clone->filterText = $filterText;
        return $clone;
    }

    /**
     * Return a new GridTable with the given page set.
     */
    public function page(int $page): self
    {
        $clone = clone $this;
        $clone->pagination = $clone->pagination->withPage($page);
        return $clone;
    }

    /**
     * Return a new GridTable scrolled to the given row index.
     */
    public function scrollTo(int $index): self
    {
        $clone = clone $this;
        $clone->scrollTo = $index;
        return $clone;
    }

    /**
     * Return a new GridTable with N left columns frozen.
     */
    public function freezeColumns(int $count): self
    {
        $clone = clone $this;
        $clone->frozenColumns = max(0, min($count, count($clone->columns)));
        return $clone;
    }

    // ═══════════════════════════════════════════════════════════════
    // Data processing
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get filtered rows based on current filter text.
     *
     * @return list<Row>
     */
    private function getFilteredRows(): array
    {
        if ($this->filterText === '') {
            return $this->rows;
        }

        $filterLower = mb_strtolower($this->filterText);
        $filtered = [];

        foreach ($this->rows as $row) {
            if ($this->rowMatchesFilter($row, $filterLower)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * Check if a row matches the filter text.
     */
    private function rowMatchesFilter(Row $row, string $filterLower): bool
    {
        $checkedAny = false;

        foreach ($this->columns as $column) {
            if (!$column->filterable) {
                continue;
            }

            $checkedAny = true;

            $value = $row->get($column->key);
            if ($value === null) {
                continue;
            }

            $str = is_string($value) ? $value : (string) $value;
            if (mb_stripos($str, $filterLower) !== false) {
                return true;
            }
        }

        // If no filterable columns were checked, show the row
        // If filterable columns were checked but none matched, hide the row
        return !$checkedAny;
    }

    /**
     * Get sorted rows based on current sort state.
     *
     * @param list<Row> $rows
     * @return list<Row>
     */
    private function getSortedRows(array $rows): array
    {
        $sortKey = $this->sortState->key;

        if ($sortKey === null) {
            return $rows;
        }

        $direction = $this->sortState->direction;

        // Find the column
        $column = null;
        foreach ($this->columns as $col) {
            if ($col->key === $sortKey) {
                $column = $col;
                break;
            }
        }

        if ($column === null) {
            return $rows;
        }

        // Sort using usort with stable preservation
        $sorted = $rows;
        usort($sorted, function (Row $a, Row $b) use ($sortKey, $direction) {
            $aVal = $a->get($sortKey);
            $bVal = $b->get($sortKey);

            // Handle nulls
            if ($aVal === null && $bVal === null) {
                return 0;
            }
            if ($aVal === null) {
                return $direction === SortDirection::Asc ? -1 : 1;
            }
            if ($bVal === null) {
                return $direction === SortDirection::Asc ? 1 : -1;
            }

            // Numeric comparison if both are numeric
            if (is_numeric($aVal) && is_numeric($bVal)) {
                $cmp = (float) $aVal <=> (float) $bVal;
                return $direction === SortDirection::Asc ? $cmp : -$cmp;
            }

            // String comparison
            $cmp = (string) $aVal <=> (string) $bVal;
            return $direction === SortDirection::Asc ? $cmp : -$cmp;
        });

        return $sorted;
    }

    /**
     * Get paginated rows for the given window.
     *
     * @param list<Row> $rows
     * @return list<Row>
     */
    private function getPaginatedRows(array $rows, Pagination $pageable): array
    {
        $offset = $pageable->offset();
        $perPage = max(1, $pageable->perPage); // never negative-slice (D-51, finding 144)

        if ($offset >= count($rows)) {
            return [];
        }

        return array_slice($rows, $offset, $perPage);
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════════════════════════

    /**
     * Render the grid as a string.
     */
    public function render(): string
    {
        if ($this->columns === []) {
            return '';
        }

        $width = $this->width ?? 80;
        $filtered = $this->getFilteredRows();
        $sorted = $this->getSortedRows($filtered);

        // Clamp pagination against the FILTERED reality, not the raw row
        // count: a page set before the filter shrank the set must land on
        // a valid page instead of an empty offset (D-51, finding 044 — the
        // dead $totalFiltered assignment this replaces promised exactly
        // that and never delivered it).
        $totalFiltered = count($sorted);
        $pageable = $this->pagination->withTotalRows($totalFiltered)->normalized();
        $paginated = $this->getPaginatedRows($sorted, $pageable);

        // Column widths are computed from the whole filtered set so the
        // geometry is stable across pages (D-51, finding 151: page-derived
        // widths made every border jitter when paging).
        $colWidths = Calc::calculateColumnWidths($sorted, $this->columns, $width);

        $lines = [];

        // Top border
        if ($this->borderConfig->showOuter) {
            $lines[] = $this->renderTopBorder($colWidths);
        }

        // Header row
        $lines[] = $this->renderHeader($colWidths);

        // Header separator
        if ($this->borderConfig->showHeader) {
            $lines[] = $this->renderHeaderSeparator($colWidths);
        }

        // Data rows
        foreach ($paginated as $row) {
            if ($this->frozenColumns > 0 && $this->hasFrozenBand($colWidths)) {
                $lines[] = $this->renderFrozenRow($row, $colWidths);
            } else {
                $lines[] = $this->renderRow($row, $colWidths);
            }
        }

        if ($totalFiltered === 0 && $this->filterText !== '') {
            // D-15 policy route: announce the empty result inside the frame
            // instead of emitting chrome over a silent void (D-51, finding 044).
            $lines[] = $this->renderNoticeRow('no rows match filter', $colWidths);
        }

        // Footer separator
        if ($this->borderConfig->showFooter && $paginated !== []) {
            $lines[] = $this->renderFooterSeparator($colWidths);
        }

        // Bottom border
        if ($this->borderConfig->showOuter) {
            $lines[] = $this->renderBottomBorder($colWidths);
        }

        return implode("\n", $lines);
    }

    /**
     * Does the frozen band occupy any cell at all?
     *
     * The question the row loop actually asks is yes/no: a band with width
     * renders through renderFrozenRow, an empty one through the plain row.
     * The old getFrozenWidth() returned a pixel sum that no consumer ever
     * read past this `> 0` test — renderFrozenRow lays the band out column
     * by column — so the arithmetic now terminates in the bool it always
     * served. The sum itself is kept verbatim: identical widths, identical
     * decision, byte-for-byte.
     *
     * @param list<int> $colWidths
     */
    private function hasFrozenBand(array $colWidths): bool
    {
        $frozen = 0;
        $frozenCount = min($this->frozenColumns, count($colWidths));

        for ($i = 0; $i < $frozenCount; $i++) {
            $frozen += $colWidths[$i];
        }

        // The band carries one divider cell per frozen column when inner
        // borders are on — that alone makes it non-empty.
        if ($frozenCount > 0 && $this->borderConfig->showInner) {
            $frozen += $frozenCount;
        }

        return $frozen > 0;
    }

    /**
     * Render top border line.
     *
     * @param list<int> $colWidths
     */
    private function renderTopBorder(array $colWidths): string
    {
        $c = $this->borderConfig->chars;

        $line = $c->topLeft;

        foreach ($colWidths as $i => $width) {
            $line .= str_repeat($c->top, $width);

            if ($i < count($colWidths) - 1) {
                // No freeze special-case by design: the heavy freeze divider
                // spans the body only, meeting the horizontal rules at the
                // header/footer separators ('╥'/'╨' seats). An outer border
                // row carries plain column junctions at every seat — the
                // former identical-arm branch here was dead by geometry.
                $line .= $c->topMid;
            }
        }

        $line .= $c->topRight;

        return $line;
    }

    /**
     * Render header row.
     *
     * @param list<int> $colWidths
     */
    private function renderHeader(array $colWidths): string
    {
        $c = $this->borderConfig->chars;

        $line = $c->left;

        foreach ($this->columns as $i => $column) {
            $width = $colWidths[$i];
            $label = Calc::truncate($column->label, $width);
            $padded = Width::padRight($label, $width);

            // Add sort indicator if sorted
            if ($this->sortState->isSortedBy($column->key)) {
                $indicator = $this->sortState->direction === SortDirection::Asc ? ' ▲' : ' ▼';
                $indicatorCells = Width::string($indicator);

                if ($indicatorCells < $width) {
                    $inner = $width - $indicatorCells;
                    // Width::truncate strips every escape before it measures, so a label
                    // that already fits must bypass it or its styling is lost (P0-A11).
                    $body = Width::string($label) <= $inner ? $label : Width::truncate($label, $inner);
                    $padded = Width::padRight($body, $inner) . $indicator;
                }
            }

            $line .= $padded;
            $line .= $c->right;

            if ($i < count($this->columns) - 1) {
                if ($this->frozenColumns > 0 && $i === $this->frozenColumns - 1) {
                    $line = rtrim($line, $c->right) . '║'; // Freeze divider
                }
            }
        }

        return $line;
    }

    /**
     * Render header separator line.
     *
     * @param list<int> $colWidths
     */
    private function renderHeaderSeparator(array $colWidths): string
    {
        $c = $this->borderConfig->chars;

        $line = $c->leftJunction;

        foreach ($colWidths as $i => $width) {
            $line .= str_repeat($c->top, $width);

            if ($i < count($colWidths) - 1) {
                if ($this->frozenColumns > 0 && $i === $this->frozenColumns - 1) {
                    $line .= $c->freezeTopJunction; // Freeze top junction
                } else {
                    $line .= $c->topMid;
                }
            }
        }

        $line .= $c->rightJunction;

        return $line;
    }

    /**
     * Render a data row.
     *
     * @param list<int> $colWidths
     */
    private function renderRow(Row $row, array $colWidths): string
    {
        $c = $this->borderConfig->chars;

        $line = $c->left;

        foreach ($this->columns as $i => $column) {
            $width = $colWidths[$i];
            $value = $row->get($column->key);

            // Use renderer if provided
            if ($column->renderer !== null) {
                $str = (string) ($column->renderer)($value);
            } else {
                $str = $value !== null ? (string) $value : '';
            }

            $truncated = Calc::truncate($str, $width);
            $padded = Width::padRight($truncated, $width);

            $line .= $padded;
            $line .= $c->right;

            if ($i < count($this->columns) - 1) {
                if ($this->frozenColumns > 0 && $i === $this->frozenColumns - 1) {
                    $line = rtrim($line, $c->right) . '║'; // Freeze divider
                }
            }
        }

        return $line;
    }

    /**
     * Render a data row with frozen columns.
     *
     * @param list<int> $colWidths
     */
    private function renderFrozenRow(Row $row, array $colWidths): string
    {
        $c = $this->borderConfig->chars;
        $frozenCount = min($this->frozenColumns, count($this->columns));

        $line = '';

        // Render frozen portion
        for ($i = 0; $i < $frozenCount; $i++) {
            $column = $this->columns[$i];
            $width = $colWidths[$i];
            $value = $row->get($column->key);

            if ($column->renderer !== null) {
                $str = (string) ($column->renderer)($value);
            } else {
                $str = $value !== null ? (string) $value : '';
            }

            $truncated = Calc::truncate($str, $width);
            $padded = Width::padRight($truncated, $width);

            if ($i === 0) {
                $line .= $c->left . $padded;
            } else {
                $line .= $c->right . $padded;
            }
        }

        // The divider takes the seat of the wall after the last frozen cell,
        // as renderRow swaps it in, so the row keeps the unfrozen row's cell count
        // (borderless has an empty divider; renderHeader still hard-codes '║' there,
        // a known follow-up pinned in FrozenRowAlignmentTest). With no scrollable
        // column left there is no seat to divide, and the closing wall below ends the row.
        $scrollStart = $frozenCount;
        $columnCount = count($this->columns);
        if ($scrollStart < $columnCount) {
            $line .= $c->freezeDivider;
        }

        // Render scrollable portion
        for ($i = $scrollStart; $i < $columnCount; $i++) {
            $column = $this->columns[$i];
            $width = $colWidths[$i];
            $value = $row->get($column->key);

            if ($column->renderer !== null) {
                $str = (string) ($column->renderer)($value);
            } else {
                $str = $value !== null ? (string) $value : '';
            }

            $truncated = Calc::truncate($str, $width);
            $padded = Width::padRight($truncated, $width);

            if ($i === $scrollStart) {
                $line .= $padded;
            } else {
                $line .= $c->right . $padded;
            }
        }

        $line .= $c->right;

        return $line;
    }

    /**
     * Render footer separator line.
     *
     * @param list<int> $colWidths
     */
    private function renderFooterSeparator(array $colWidths): string
    {
        $c = $this->borderConfig->chars;

        $line = $c->leftJunction;

        foreach ($colWidths as $i => $width) {
            $line .= str_repeat($c->top, $width);

            if ($i < count($colWidths) - 1) {
                if ($this->frozenColumns > 0 && $i === $this->frozenColumns - 1) {
                    $line .= $c->freezeBottomJunction;
                } else {
                    $line .= $c->bottomMid;
                }
            }
        }

        $line .= $c->rightJunction;

        return $line;
    }

    /**
     * Render a full-width announcement row: the message sits in the first
     * cell, the remaining cells blank, walls and separators exactly like a
     * data row so the frame geometry never changes around it.
     *
     * @param list<int> $colWidths
     */
    private function renderNoticeRow(string $message, array $colWidths): string
    {
        $c = $this->borderConfig->chars;

        $line = $c->left;

        foreach ($this->columns as $i => $column) {
            $width = $colWidths[$i];
            $text = $i === 0 ? $message : '';
            $padded = Width::padRight(Calc::truncate($text, $width), $width);
            $line .= $padded . $c->right;
        }

        return $line;
    }

    /**
     * Render bottom border line.
     *
     * @param list<int> $colWidths
     */
    private function renderBottomBorder(array $colWidths): string
    {
        $c = $this->borderConfig->chars;

        $line = $c->bottomLeft;

        foreach ($colWidths as $i => $width) {
            $line .= str_repeat($c->bottom, $width);

            if ($i < count($colWidths) - 1) {
                // See renderTopBorder: the freeze divider never reaches an
                // outer border row, so the seat is a plain bottom junction.
                $line .= $c->bottomMid;
            }
        }

        $line .= $c->bottomRight;

        return $line;
    }

    // ═══════════════════════════════════════════════════════════════
    // State accessors
    // ═══════════════════════════════════════════════════════════════

    public function sortState(): SortState
    {
        return $this->sortState;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::sortState()} instead. REMOVED-IN: 2.0.0.
     */
    public function getSortState(): SortState { return $this->sortState(); }

    public function filterText(): string
    {
        return $this->filterText;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::filterText()} instead. REMOVED-IN: 2.0.0.
     */
    public function getFilterText(): string { return $this->filterText(); }

    public function pagination(): Pagination
    {
        return $this->pagination;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::pagination()} instead. REMOVED-IN: 2.0.0.
     */
    public function getPagination(): Pagination { return $this->pagination(); }

    public function frozenColumns(): int
    {
        return $this->frozenColumns;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::frozenColumns()} instead. REMOVED-IN: 2.0.0.
     */
    public function getFrozenColumns(): int { return $this->frozenColumns(); }
}
