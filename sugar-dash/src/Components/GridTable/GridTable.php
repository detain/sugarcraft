<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;

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

    public function __construct(
        array $columns = [],
        array $rows = [],
        ?SortState $sortState = null,
        ?BorderConfig $borderConfig = null,
    ) {
        $this->columns = $columns;
        $this->rows = $rows;
        $this->sortState = $sortState ?? new SortState();
        $this->borderConfig = $borderConfig ?? BorderConfig::default();
        $this->pagination = new Pagination(page: 1, perPage: 20, totalRows: count($rows));
    }

    /**
     * Create a new GridTable with columns and rows.
     *
     * @param list<Column> $columns
     * @param list<Row> $rows
     */
    public static function create(array $columns, array $rows = []): self
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

        // Recalculate pagination based on available height
        if ($height > 0) {
            $availableRows = $height - 2; // Account for header borders
            if ($availableRows > 0) {
                $clone->pagination = $clone->pagination->withPerPage($availableRows);
            }
        }

        return $clone;
    }

    /**
     * Get current width.
     */
    public function getWidth(): ?int
    {
        return $this->width;
    }

    /**
     * Get current height.
     */
    public function getHeight(): ?int
    {
        return $this->height;
    }

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
     * Return a new GridTable with sort applied to the given column.
     */
    public function sort(Column $column): self
    {
        if (!$column->sortable) {
            return $this;
        }

        $clone = clone $this;
        $clone->sortState = $this->sortState->toggle($column->key);
        return $clone;
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
     * Get paginated rows.
     *
     * @param list<Row> $rows
     * @return list<Row>
     */
    private function getPaginatedRows(array $rows): array
    {
        $offset = $this->pagination->offset();
        $perPage = $this->pagination->perPage;

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

        // Update pagination with filtered count for proper clamping
        $totalFiltered = count($sorted);
        $paginated = $this->getPaginatedRows($sorted);

        // Calculate column widths
        $colWidths = Calc::calculateColumnWidths($paginated, $this->columns, $width);

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
        $frozenWidth = $this->getFrozenWidth($colWidths);

        foreach ($paginated as $row) {
            if ($this->frozenColumns > 0 && $frozenWidth > 0) {
                $lines[] = $this->renderFrozenRow($row, $colWidths, $frozenWidth);
            } else {
                $lines[] = $this->renderRow($row, $colWidths);
            }
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
     * Get total width of frozen columns.
     *
     * @param list<int> $colWidths
     */
    private function getFrozenWidth(array $colWidths): int
    {
        $frozen = 0;
        $frozenCount = min($this->frozenColumns, count($colWidths));

        for ($i = 0; $i < $frozenCount; $i++) {
            $frozen += $colWidths[$i];
        }

        // Add divider width
        if ($frozenCount > 0 && $this->borderConfig->showInner) {
            $frozen += $frozenCount;
        }

        return $frozen;
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
                if ($this->frozenColumns > 0 && $i === $this->frozenColumns - 1) {
                    $line .= $c->topMid; // Freeze divider junction
                } else {
                    $line .= $c->topMid;
                }
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
            $padded = str_pad($label, $width, ' ');

            // Add sort indicator if sorted
            if ($this->sortState->isSortedBy($column->key)) {
                $indicator = $this->sortState->direction === SortDirection::Asc ? ' ▲' : ' ▼';
                $indicatorLen = mb_strlen($indicator);

                if ($indicatorLen < $width) {
                    $padded = mb_substr($padded, 0, $width - $indicatorLen) . $indicator;
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
            $padded = str_pad($truncated, $width, ' ');

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
    private function renderFrozenRow(Row $row, array $colWidths, int $frozenWidth): string
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
            $padded = str_pad($truncated, $width, ' ');

            if ($i === 0) {
                $line .= $c->left . $padded;
            } else {
                $line .= $c->right . $padded;
            }
        }

        // Add freeze divider
        $line .= $c->right . $c->freezeDivider;

        // Render scrollable portion
        $scrollStart = $frozenCount;
        for ($i = $scrollStart; $i < count($this->columns); $i++) {
            $column = $this->columns[$i];
            $width = $colWidths[$i];
            $value = $row->get($column->key);

            if ($column->renderer !== null) {
                $str = (string) ($column->renderer)($value);
            } else {
                $str = $value !== null ? (string) $value : '';
            }

            $truncated = Calc::truncate($str, $width);
            $padded = str_pad($truncated, $width, ' ');

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
                if ($this->frozenColumns > 0 && $i === $this->frozenColumns - 1) {
                    $line .= $c->bottomMid; // Freeze divider junction
                } else {
                    $line .= $c->bottomMid;
                }
            }
        }

        $line .= $c->bottomRight;

        return $line;
    }

    // ═══════════════════════════════════════════════════════════════
    // State accessors
    // ═══════════════════════════════════════════════════════════════

    public function getSortState(): SortState
    {
        return $this->sortState;
    }

    public function getFilterText(): string
    {
        return $this->filterText;
    }

    public function getPagination(): Pagination
    {
        return $this->pagination;
    }

    public function getFrozenColumns(): int
    {
        return $this->frozenColumns;
    }
}
