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
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
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

    /** Index of the selected row in the page-GLOBAL filtered+sorted view. */
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
    // $col is the 0-based column INDEX into Columns() — not the column key
    // string. Hidden columns keep their index (they are skipped, not renumbered).
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

    private string $headerStyle   = '1;37';  // bold white
    private string $footerStyle   = '90';    // bright black

    private bool $showHeader = true;
    private bool $showFooter = true;
    private FooterType $footerType = FooterType::Page;

    /** When set, border characters are sourced from this Border object. */
    private ?Border $border = null;

    /** When true, renderRowLines outputs all wrapped cell lines (multi-line rows). */
    private bool $multilineMode = false;

    /**
     * Row indexes rendered as expanded detail lines: one full-width row
     * carrying every visible column's content, clipped at the table edge.
     * @var list<int>
     */
    private array $expandedRows = [];

    /** Cached computed column widths from the last render pass. @var array<int, int>|null */
    private ?array $computedColumnWidths = null;

    /** Cached result of filteredSortedRows(). Cleared on any filter/sort/row change. @var list<Row>|null */
    private ?array $filteredSortedCache = null;

    /**
     * Cache for computeColumnWidths() results, keyed by tableWidth. Kept as a
     * small LRU (see WIDTH_SOLVE_CACHE_MAX) so a long-lived table fed many
     * distinct widths — e.g. continuous terminal resizing — cannot grow it
     * without bound.
     *
     * @var array<int, array<int, int>>
     */
    private array $widthSolveCache = [];

    /** Max widthSolveCache entries; real UIs bounce between only a few widths. */
    private const WIDTH_SOLVE_CACHE_MAX = 8;

    /**
     * Iteration cap for computeTotalWidth()'s fixed-point solve. Percent
     * columns are a fraction of the total width, but the total is the sum of
     * the resolved widths — a mutual dependency. Convergent configs settle in a
     * handful of passes; an over-subscribed Percent set (total > 100%) has no
     * fixed point, so the loop clamps here rather than spinning forever.
     */
    private const WIDTH_CONVERGE_MAX = 16;

    /** Inner cell padding (characters on each side). Default 0 (flush). */
    private int $cellPadding = 0;

    /** Hidden column indices. Hidden columns are excluded from rendering but still affect data/filters. */
    private array $hiddenCols = [];

    /**
     * Borderless mode: render NO outer box (no top/bottom border rows, no
     * left/right border columns) and use a single space between columns instead
     * of a vertical rule. Lets the table compose inside another bordered shell
     * (e.g. a sugar-boxer content box) without a double border. The header
     * separator rule is still drawn when the header is shown.
     */
    private bool $borderless = false;

    /**
     * Fixed total render width in cells (0 = derive from the columns). When set,
     * Flex columns fill exactly this width and every rendered line is exactly
     * this many cells (borderless) — deterministic for composition.
     */
    private int $targetWidth = 0;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    private function __construct()
    {
    }

    public static function fromColumns(array $columns): self
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
        $clone->resetCursor();
        $clone->invalidateRenderCaches();
        return $clone;
    }

    /**
     * Drop the two render memos — the filtered/sorted view (built from rows
     * and column keys) and the width solve (content-measuring columns react
     * to rows; padding and pinned widths change the solve). Every method that
     * mutates any input to those computations MUST call this on its clone,
     * otherwise a post-render append renders stale, cache-sized data
     * (audit finding #1).
     */
    private function invalidateRenderCaches(): void
    {
        $this->filteredSortedCache = null;
        $this->widthSolveCache = [];
    }

    /**
     * Park the cursor on the first row of the first page (on a clone). Any
     * change that rebuilds the view — rows replaced, sort flipped, filter or
     * search edited — re-anchors here: a selection index chosen against the
     * old view has no meaning in the new one, and a page beyond the shrunken
     * result would render an empty table (audit #5/#9: one cursor domain,
     * always inside the view).
     */
    private function resetCursor(): void
    {
        $this->selectedIndex = 0;
        $this->page = 0;
    }

    /**
     * Replace all columns with a new set.
     *
     * Since column definitions affect both the filtered-view (column keys
     * determine which data fields are searchable/filterable) and the width
     * solve (column widths, percent values, and flex shares), both caches
     * must be invalidated.
     *
     * @param list<Column> $columns
     */
    public function withColumns(array $columns): self
    {
        $clone = clone $this;
        $clone->columns = $columns;
        $clone->invalidateRenderCaches();
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

    /**
     * Navigate to a page, clamped to the valid range, and park the cursor on
     * that page's first row (audit finding #9: an out-of-range page used to
     * leak — a negative page made array_slice count from the END).
     */
    public function withPage(int $n): self
    {
        return $this->gotoPage($n);
    }

    /**
     * Single implementation of page navigation shared by withPage() and
     * SelectPage(). One page means one semantic: the page clamps to
     * [0, TotalPages-1] and the selection snaps to the page's first row in
     * the filtered/sorted view (page-global index = page * pageSize, itself
     * clamped when the last page is short).
     */
    private function gotoPage(int $page): self
    {
        $clamped = \max(0, \min($page, $this->TotalPages() - 1));
        $snapTo  = $this->pageSize > 0 ? $clamped * $this->pageSize : 0;

        $view = $this->filteredSortedRows();
        if ($view !== []) {
            $snapTo = \min($snapTo, \count($view) - 1);
        }

        $clone = clone $this;
        $clone->page          = $clamped;
        $clone->selectedIndex = $snapTo;
        return $clone;
    }

    /**
     * Pin columns by index so they remain visible during horizontal scroll.
     *
     * Only a CONTIGUOUS PREFIX starting at column 0 can be frozen: the scroll
     * window is computed as `count(frozenCols) + scrollX`, so a sparse set
     * (e.g. [2] alone) silently blanks the pinned left edge instead of
     * freezing the intended column (audit finding #3 — fail loud with
     * guidance rather than render the lie).
     *
     * @param list<int> $indices Column indices 0..n-1 to freeze (pin to the
     *                           left edge, in column order)
     * @throws \InvalidArgumentException If the set is not a contiguous prefix
     *                                   of the columns, or overlaps hidden ones
     */
    public function withFrozenCols(array $indices): self
    {
        $sorted = $indices;
        \sort($sorted);
        $sorted = \array_values(\array_unique($sorted));

        if ($sorted !== ($sorted === [] ? [] : \range(0, \count($sorted) - 1))
            || \count($sorted) > \count($this->columns)
        ) {
            throw new \InvalidArgumentException(
                'Frozen columns must be a contiguous prefix starting at index 0; got ['
                . \implode(',', $sorted) . ']. Reorder the columns so the frozen block is leftmost,'
                . ' or hide unwanted columns with withHiddenCols().'
            );
        }

        $overlap = \array_intersect($sorted, $this->hiddenCols);
        if ($overlap !== []) {
            throw new \InvalidArgumentException(
                'Frozen column indices [' . \implode(',', $overlap) . '] cannot be hidden'
            );
        }
        $clone = clone $this;
        $clone->frozenCols = $sorted;
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
     * Set the footer display type.
     *
     * Controls whether the table footer shows "Page N of M", "Showing X to Y of Z rows",
     * or both combined. The footer is only visible when pagination is active
     * (pageSize > 0) and showFooter is true.
     *
     * @param FooterType $type The footer type: Page (default), Rows, or Both
     * @see FooterType::Page  For page-only footer
     * @see FooterType::Rows  For row count footer only
     * @see FooterType::Both  For combined footer
     */
    public function withFooterType(FooterType $type): self
    {
        $clone = clone $this;
        $clone->footerType = $type;
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
     * $col is the 0-based column INDEX into Columns(), not the column key
     * string — match against a key via Columns()[$col]->key. Hidden columns
     * keep their index; the callback simply never fires for them.
     *
     * When not set, existing baseStyle/column style/row style/cell style
     * precedence is used as before.
     *
     * @param callable|null $fn (int $row, int $col, string $value): Style|string — $col is the 0-based column index, not the key
     */
    public function withStyleFunc(?callable $fn): self
    {
        $clone = clone $this;
        $clone->styleFunc = $fn;
        return $clone;
    }

    /**
     * Set inner cell padding.
     *
     * Padding adds whitespace on the left and right sides of each cell's
     * content, creating visual breathing room between content and borders.
     * Padding does not affect column width calculations.
     *
     * @param int $padding Number of spaces on each side (0 = no padding)
     */
    public function withCellPadding(int $padding): self
    {
        $clone = clone $this;
        $clone->cellPadding = \max(0, $padding);
        $clone->invalidateRenderCaches();
        return $clone;
    }

    /**
     * Render without an outer box (no top/bottom border rows, no left/right
     * border columns; a single space separates columns). For composing the table
     * inside another bordered container without a double border. Combine with
     * {@see withWidth()} for a width-exact borderless table.
     */
    public function withBorderless(bool $v = true): self
    {
        $clone = clone $this;
        $clone->borderless = $v;
        return $clone;
    }

    /**
     * Pin the total render width (in cells). Flex columns fill exactly the room
     * left after Fixed/Percent columns and the inter-column gaps, so every line
     * renders to exactly $cols cells. 0 restores the natural (column-derived)
     * width.
     */
    public function withWidth(int $cols): self
    {
        $clone = clone $this;
        $clone->targetWidth = \max(0, $cols);
        $clone->invalidateRenderCaches();
        return $clone;
    }

    /**
     * Hide columns by index without removing them from the table.
     *
     * Hidden columns are excluded from rendering but still participate in
     * data storage, filtering, sorting, and search operations. This is
     * useful for optional columns that can be toggled visible/invisible.
     *
     * @param list<int> $indices Column indices to hide
     */
    public function withHiddenCols(array $indices): self
    {
        $overlap = \array_intersect($indices, $this->frozenCols);
        if ($overlap !== []) {
            throw new \InvalidArgumentException(
                'Hidden column indices [' . \implode(',', $overlap) . '] cannot be frozen'
            );
        }
        $clone = clone $this;
        $clone->hiddenCols = $indices;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Row expansion
    // -------------------------------------------------------------------------

    /**
     * Set which rows are expanded on the current page.
     *
     * An expanded row renders as ONE row-height detail line spanning the
     * table's full visible width: every visible column's text joined into a
     * single run, clipped with '…' at the table edge when even that cannot
     * fit (audit finding #8 — it used to be documented as "full content
     * without truncation" while the renderer silently drew the normal
     * per-column clipped row instead).
     *
     * @param list<int> $indices 0-based row indices relative to the current page
     * @throws \OutOfBoundsException If any index is invalid for the current page
     */
    public function withExpandedRows(array $indices): self
    {
        $clone = clone $this;
        $paged = $clone->pagedRows();
        $clone->expandedRows = [];
        if ($paged === [] && $indices !== []) {
            throw new \OutOfBoundsException("Invalid row index {$indices[0]}: current page has no rows");
        }
        foreach ($indices as $idx) {
            $row = $paged[$idx] ?? null;
            if ($row === null) {
                throw new \OutOfBoundsException("Invalid row index {$idx} for current page");
            }
            $clone->expandedRows[] = $row;
        }
        return $clone;
    }

    /**
     * Toggle the expanded state of a row on the current page.
     *
     * @param int $rowIndex The 0-based row index relative to the current page
     * @throws \OutOfBoundsException If the index is invalid for the current page
     */
    public function toggleExpanded(int $rowIndex): self
    {
        $paged = $this->pagedRows();
        $row = $paged[$rowIndex] ?? null;
        if ($row === null) {
            throw new \OutOfBoundsException("Invalid row index {$rowIndex} for current page");
        }
        $clone = clone $this;
        $idx = \array_search($row, $clone->expandedRows, true);
        if ($idx === false) {
            $clone->expandedRows[] = $row;
        } else {
            \array_splice($clone->expandedRows, $idx, 1);
        }
        return $clone;
    }

    /**
     * Check if a row on the current page is currently expanded.
     *
     * @param int $rowIndex The 0-based row index relative to the current page
     * @throws \OutOfBoundsException If the index is invalid for the current page
     */
    public function isExpanded(int $rowIndex): bool
    {
        $paged = $this->pagedRows();
        $row = $paged[$rowIndex] ?? null;
        if ($row === null) {
            throw new \OutOfBoundsException("Invalid row index {$rowIndex} for current page");
        }
        return \in_array($row, $this->expandedRows, true);
    }

    /**
     * Expand affordance analogue of Evertras/bubble-table's WithRowExpanded
     * family (identity check of a row object against the expanded set;
     * upstream has no per-row predicate method to mirror).
     *
     * @param Row $row The row object to check (identity-based, not index-based)
     * @return bool True if the row is currently expanded
     */
    private function isExpandedByRow(Row $row): bool
    {
        return \in_array($row, $this->expandedRows, true);
    }

    // -------------------------------------------------------------------------
    // Row data helpers
    // -------------------------------------------------------------------------

    public function addRow(Row $row): self
    {
        $clone = clone $this;
        $clone->rows[] = $row;
        $clone->invalidateRenderCaches();
        return $clone;
    }

    public function addRows(array $rows): self
    {
        $clone = clone $this;
        foreach ($rows as $row) {
            $clone->rows[] = $row;
        }
        $clone->invalidateRenderCaches();
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    public function SelectNext(): self
    {
        $view = $this->filteredSortedRows();
        if ($view === []) {
            return $this;
        }

        // Exactly on the last row is a no-op — return $this like the other
        // no-op withers. (An out-of-range index still takes the clamp path so
        // stale selections keep snapping back to the last row.)
        if ($this->selectedIndex === \count($view) - 1) {
            return $this;
        }

        $clone = clone $this;
        $clone->selectedIndex = \min(\count($view) - 1, $clone->selectedIndex + 1);
        $clone->page = $this->pageForIndex($clone->selectedIndex);
        return $clone;
    }

    public function SelectPrevious(): self
    {
        // Already at the top: skip the clone AND the filtered/sorted view —
        // no state can change, so the expensive computation is pure waste.
        if ($this->selectedIndex === 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->selectedIndex = \max(0, $clone->selectedIndex - 1);
        $clone->page = $this->pageForIndex($clone->selectedIndex);
        return $clone;
    }

    /**
     * The page that shows a page-global view index. With pagination off the
     * single page is always 0; with it on, the cursor's page follows the
     * cursor (audit finding #5: the highlight compared a page-local slot
     * against a page-global index, so any cursor past page 0 rendered dead).
     */
    private function pageForIndex(int $index): int
    {
        if ($this->pageSize <= 0) {
            return 0;
        }
        return \max(0, \min(\intdiv($index, $this->pageSize), $this->TotalPages() - 1));
    }

    /**
     * Move the selection directly to a 0-based index in the PAGE-GLOBAL
     * filtered/sorted view, clamped to it, and auto-page so the cursor stays
     * visible (page = intdiv(index, pageSize)) — exactly what a caller
     * tracking its own cursor needs. Mirrors Bubbles' table.SetCursor, which
     * likewise scrolls the viewport to reveal the cursor.
     */
    public function withSelectedIndex(int $index): self
    {
        $view = $this->filteredSortedRows();
        if ($view === []) {
            return $this;
        }
        $clone = clone $this;
        $clone->selectedIndex = \max(0, \min(\count($view) - 1, $index));
        $clone->page = $this->pageForIndex($clone->selectedIndex);
        return $clone;
    }

    /**
     * Jump to a page and park the cursor on its first row. Same semantics as
     * {@see withPage()} — both route through the single page-navigation path.
     */
    public function SelectPage(int $page): self
    {
        return $this->gotoPage($page);
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
    // Keyboard scrolling helpers
    // -------------------------------------------------------------------------

    /**
     * Key constants for scrollYForKey() and handleKey().
     *
     * Use these constants when calling the keyboard navigation helpers to ensure
     * correct key names.  These map to typical terminal arrow-key and navigation
     * key sequences that input libraries (e.g. candy-pty, brick/console) emit.
     */
    public const KEY_ARROW_UP    = 'arrowUp';
    public const KEY_ARROW_DOWN  = 'arrowDown';
    public const KEY_PAGE_UP     = 'pageUp';
    public const KEY_PAGE_DOWN   = 'pageDown';
    public const KEY_HOME        = 'home';
    public const KEY_END         = 'end';

    /**
     * Return the new scrollY value for a given keyboard key.
     *
     * Useful for integrating with an input library — call this on each keypress
     * and then apply the result via withScrollY():
     *
     *   $table = $table->withScrollY($table->scrollYForKey($key));
     *
     * Keys that have no mapping return the current scrollY unchanged (no-op).
     *
     * @param string $key One of the KEY_* constants
     * @return int The new scrollY value (already clamped to valid range)
     */
    public function scrollYForKey(string $key): int
    {
        $maxScroll = $this->maxScrollY();

        return match ($key) {
            self::KEY_ARROW_UP   => \max(0, $this->scrollY - 1),
            self::KEY_ARROW_DOWN => \min($maxScroll, $this->scrollY + 1),
            self::KEY_PAGE_UP    => \max(0, $this->scrollY - $this->viewportHeight),
            self::KEY_PAGE_DOWN  => \min($maxScroll, $this->scrollY + $this->viewportHeight),
            self::KEY_HOME       => 0,
            self::KEY_END        => $maxScroll,
            default              => $this->scrollY,
        };
    }

    /**
     * Return a new Table with scrollY adjusted for the given keyboard key.
     *
     * Convenience wrapper around scrollYForKey() + withScrollY() for easy
     * keyboard navigation integration:
     *
     *   $table = $table->handleKey($key);
     *
     * @param string $key One of the KEY_* constants
     * @return self New Table instance with adjusted scrollY
     *
     * @see scrollYForKey()  For the raw scrollY calculation
     */
    public function handleKey(string $key): self
    {
        return $this->withScrollY($this->scrollYForKey($key));
    }

    /**
     * Compute the maximum allowed scrollY value.
     *
     * When viewportHeight is 0, no scrolling is possible so max is 0.
     * Otherwise max is max(0, totalFilteredRows - viewportHeight).
     */
    private function maxScrollY(): int
    {
        if ($this->viewportHeight <= 0) {
            return 0;
        }
        $total = $this->TotalRows();
        return \max(0, $total - $this->viewportHeight);
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
        if (!$this->columnExists($colKey)) {
            throw new \InvalidArgumentException("SortBy column key '{$colKey}' does not exist");
        }
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
                if ($s['key'] === $colKey) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx !== null) {
                $cur = $clone->sortColumns[$idx];
                $clone->sortColumns[$idx] = ['key' => $colKey, 'asc' => !$cur['asc']];
            } else {
                $clone->sortColumns[] = ['key' => $colKey, 'asc' => $ascending];
            }
        }

        $clone->resetCursor();
        $clone->filteredSortedCache = null;
        return $clone;
    }

    public function ClearSort(): self
    {
        $clone = clone $this;
        $clone->sortColumns = [];
        $clone->filteredSortedCache = null;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Filtering
    // -------------------------------------------------------------------------

    public function Filter(string $colKey, string $text): self
    {
        if (!$this->columnExists($colKey)) {
            throw new \InvalidArgumentException("Filter column key '{$colKey}' does not exist");
        }
        $clone = clone $this;

        // If this column is not filterable (and at least one column IS declared
        // filterable), treat the call as a no-op — return clone with no changes.
        $filterableKeys = $this->filterableKeys();
        $hasExplicitFilterable = $this->hasExplicitFilterable();
        if ($hasExplicitFilterable && !\in_array($colKey, $filterableKeys, true)) {
            return $clone;
        }

        if ($text === '') {
            unset($clone->filterText[$colKey]);
        } else {
            $clone->filterText[$colKey] = $text;
        }
        $clone->resetCursor();
        $clone->filteredSortedCache = null;
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
        $clone->resetCursor();
        $clone->filteredSortedCache = null;
        return $clone;
    }

    /**
     * Search across ALL columns (case-insensitive).
     *
     * Applies a global full-text search across every column in the table.
     * Matching is case-insensitive and uses OR logic: a row is included if
     * ANY column's value contains the search text.
     *
     * Combines with column filters (Filter()) via AND logic — a row must
     * satisfy both the global search and all active column filters.
     *
     * @param string $text The search string. Empty string clears the search.
     * @return self A new Table instance with the search applied.
     *
     * @see Filter()   For per-column filtering with AND logic
     * @see ClearSearch()  For explicitly clearing the global search
     */
    public function search(string $text): self
    {
        $clone = clone $this;
        $clone->searchText = $text;
        $clone->resetCursor();
        $clone->filteredSortedCache = null;
        return $clone;
    }

    /**
     * Clear the global search.
     *
     * Removes the active global search filter, showing all rows (subject to
     * any remaining column filters from Filter()).
     *
     * @return self A new Table instance with global search cleared.
     *
     * @see search()  For setting a global search
     * @see Filter()  For per-column filtering
     */
    public function ClearSearch(): self
    {
        return $this->search('');
    }

    /**
     * Returns true if at least one column has $filterable === true.
     * Used to gate the opt-in filterability model.
     */
    private function hasExplicitFilterable(): bool
    {
        foreach ($this->columns as $col) {
            if ($col->filterable === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a column with the given key exists.
     */
    private function columnExists(string $key): bool
    {
        foreach ($this->columns as $col) {
            if ($col->key === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the list of column keys that participate in filtering.
     *
     * When at least one column has $filterable === true (hasExplicitFilterable):
     * returns only the keys of columns where filterable === true.
     *
     * When NO column has $filterable === true (back-compat default):
     * returns ALL column keys so existing search/filter behaviour is unchanged.
     *
     * @return list<string>
     */
    private function filterableKeys(): array
    {
        $keys = [];
        $anyFilterable = false;
        foreach ($this->columns as $col) {
            if ($col->filterable === true) {
                $anyFilterable = true;
                $keys[] = $col->key;
            }
        }
        // Back-compat: if nothing is explicitly marked filterable, allow all
        return $anyFilterable ? $keys : \array_column($this->columns, 'key');
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /** @return list<Column> */
    public function Columns(): array
    {
        return $this->columns;
    }

    /**
     * Return the column with the given key, or null if not found.
     *
     * @return ?Column
     */
    public function column(string $key): ?Column
    {
        foreach ($this->columns as $col) {
            if ($col->key === $key) {
                return $col;
            }
        }
        return null;
    }

    /** @return list<Row> */
    public function Rows(): array
    {
        return $this->rows;
    }

    /** @return list<Row> */
    public function filteredSortedRows(): array
    {
        return $this->filteredSortedCache ??= $this->buildFilteredSortedRows();
    }

    /** Build (and cache) the filtered+sorted row list. */
    private function buildFilteredSortedRows(): array
    {
        $rows = $this->rows;

        // Per-column filters (AND logic — row must match ALL column filters)
        // Only filterable columns participate; skip filterText entries for
        // non-filterable columns when opt-in gating is active.
        if ($this->filterText !== []) {
            $filterable = $this->filterableKeys();
            $rows = \array_values(
                \array_filter($rows, function (Row $row) use ($filterable): bool {
                    foreach ($this->filterText as $key => $text) {
                        if (!\in_array($key, $filterable, true)) {
                            continue; // column not filterable — skip (no-op filter)
                        }
                        $val = $row->data->get($key);
                        $str = \is_object($val) && method_exists($val, '__toString') ? (string) $val : (string) ($val ?? '');
                        if (\stripos($str, $text) === false) {
                            return false;
                        }
                    }
                    return true;
                })
            );
        }

        // Global search — only scan filterable columns (OR logic)
        if ($this->searchText !== '') {
            $searchLower = \strtolower($this->searchText);
            $filterable = $this->filterableKeys();
            $rows = \array_values(
                \array_filter($rows, function (Row $row) use ($searchLower, $filterable): bool {
                    foreach ($filterable as $key) {
                        $val = $row->data->get($key);
                        if ($val === null) {
                            continue;
                        }
                        $str = \is_object($val) && method_exists($val, '__toString') ? (string) $val : (string) $val;
                        if (\stripos(\strtolower($str), $searchLower) !== false) {
                            return true;
                        }
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
        if ($this->pageSize <= 0) {
            return $rows;
        }

        // Defensive: a negative offset would make array_slice count from the
        // END and leak tail rows (audit #9 — gotoPage clamps now; this guards
        // any future untyped entry point).
        $offset = \max(0, $this->page * $this->pageSize);
        return \array_slice($rows, $offset, $this->pageSize);
    }

    /**
     * The selected row in the PAGE-GLOBAL filtered/sorted view (audit #5:
     * it used to index the page-local slice while withSelectedIndex clamped
     * globally — a cursor past page 0 read null). The page follows the
     * cursor via withSelectedIndex()/gotoPage().
     */
    public function CurrentRow(): ?Row
    {
        return $this->filteredSortedRows()[$this->selectedIndex] ?? null;
    }

    public function CurrentRowData(): ?RowData
    {
        return $this->CurrentRow()?->data;
    }

    /**
     * The currently selected Row from the filtered/sorted view, or null when
     * the view is empty. Naming alias of CurrentRow() that pairs with
     * SelectedIndex(). Mirrors Evertras/bubble-table.Model.HighlightedRow.
     */
    public function SelectedRow(): ?Row
    {
        return $this->CurrentRow();
    }

    public function TotalRows(): int
    {
        return \count($this->filteredSortedRows());
    }

    public function TotalPages(): int
    {
        if ($this->pageSize <= 0) {
            return 1;
        }
        return \max(1, (int) \ceil($this->TotalRows() / $this->pageSize));
    }

    public function SelectedIndex(): int
    {
        return $this->selectedIndex;
    }
    public function CurrentPage(): int
    {
        return $this->page;
    }
    public function PageSize(): int
    {
        return $this->pageSize;
    }

    public function PageFooter(): string
    {
        return Lang::t('page_of', ['page' => $this->page + 1, 'total' => $this->TotalPages()]);
    }

    /**
     * Return a "Showing X to Y of Z rows" footer string.
     *
     * Uses the 'showing_rows' i18n key. Row range is computed from the
     * current page, page size, and total filtered row count.
     *
     * The count reflects filtered+sorted rows (via TotalRows()), not raw row count.
     * When no rows match the filter/search, returns "Showing 0 to 0 of 0 rows".
     *
     * @return string The localized footer string, e.g. "Showing 1 to 25 of 100 rows"
     * @see FooterType::Rows  For displaying this footer in the table
     */
    public function RowsFooter(): string
    {
        $total = $this->TotalRows();
        if ($total === 0) {
            return Lang::t('showing_rows', ['from' => 0, 'to' => 0, 'total' => 0]);
        }

        // When pagination is off (pageSize <= 0), show the full filtered set
        if ($this->pageSize <= 0) {
            $from = 1;
            $to = $total;
        } else {
            $from = ($this->page * $this->pageSize) + 1;
            $to = \min(($this->page + 1) * $this->pageSize, $total);
        }

        return Lang::t('showing_rows', ['from' => $from, 'to' => $to, 'total' => $total]);
    }

    /**
     * Compute actual column widths based on ColumnWidth enum values.
     * Results are memoized per $tableWidth to avoid redundant computation.
     *
     * @return array<int, int>  colIndex => computed width in chars
     */
    public function computeColumnWidths(int $tableWidth): array
    {
        if (isset($this->widthSolveCache[$tableWidth])) {
            // LRU touch: re-append so widths in active use never get evicted
            // (PHP arrays preserve insertion order, so position = recency).
            $widths = $this->widthSolveCache[$tableWidth];
            unset($this->widthSolveCache[$tableWidth]);
            $this->widthSolveCache[$tableWidth] = $widths;
            return $widths;
        }

        if (\count($this->widthSolveCache) >= self::WIDTH_SOLVE_CACHE_MAX) {
            unset($this->widthSolveCache[\array_key_first($this->widthSolveCache)]);
        }

        return $this->widthSolveCache[$tableWidth] = $this->doComputeColumnWidths($tableWidth);
    }

    /**
     * Actual column-width computation (called after cache check).
     *
     * @return array<int, int>  colIndex => computed width in chars
     */
    private function doComputeColumnWidths(int $tableWidth): array
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

            // Apply Dynamic/Content/Flex as content-based or share-based width
            foreach ($this->columns as $i => $col) {
                if ($widths[$i] === null) {
                    $contentLen = $this->contentWidthForColumn($col);
                    if ($col->columnWidth === ColumnWidth::Flex) {
                        // Flex: take the share verbatim, ignoring content (it is
                        // truncated to fit) — the basis for an exact total width.
                        // May shrink to 0 when the width is too tight to spare any.
                        $widths[$i] = \max(0, $flexWidth);
                    } elseif ($col->columnWidth === ColumnWidth::Dynamic) {
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

        // With a pinned width, give the last Flex column the integer-rounding
        // slack so the columns + inter-column gaps sum to EXACTLY $tableWidth
        // (and a selected row's reverse highlight reaches the right edge).
        if ($this->targetWidth > 0) {
            $lastFlex = null;
            foreach ($this->columns as $i => $col) {
                if ($col->columnWidth === ColumnWidth::Flex) {
                    $lastFlex = $i;
                }
            }
            if ($lastFlex !== null) {
                $used = \array_sum($widths) + \max(0, \count($this->columns) - 1);
                $widths[$lastFlex] = \max(0, $widths[$lastFlex] + ($tableWidth - $used));
            }
        }

        // maxWidth is a hard ceiling on every column kind, applied after the
        // slack distribution above: an explicit cap the caller set outranks
        // exact-sum cosmetics (the row simply renders narrower than target,
        // same as an over-wide Content column already does). Audit finding 6
        // — before this the knob was parsed, fluent-carried, and never read.
        foreach ($this->columns as $i => $col) {
            if ($col->maxWidth > 0 && $widths[$i] > $col->maxWidth) {
                $widths[$i] = $col->maxWidth;
            }
        }

        return $widths;
    }

    /**
     * Estimate the maximum content width for a column from row data.
     */
    private function contentWidthForColumn(Column $col): int
    {
        // Sanitize title and measure display width (not byte length)
        $title = Sanitize::value($col->title, false);
        $maxLen = Width::of($title);

        foreach ($this->rows as $row) {
            $val = $row->data->get($col->key);
            if ($val === null) {
                continue;
            }
            $str = \is_object($val) && method_exists($val, '__toString')
                ? (string) $val
                : (\is_scalar($val) ? (string) $val : '');
            // Sanitize before width measurement
            $str = Sanitize::value($str, false);
            $len = Width::of($str);
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
        if ($this->columns === []) {
            return '';
        }

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
        // Height: top border + [header + header sep] + rows + [footer] + bottom border.
        // Borderless mode drops the top/bottom border rows entirely.
        $topBorderRows = $this->borderless ? 0 : 1;
        $headerRows = $this->showHeader ? 2 : 0; // header + separator
        $footerRows = ($this->showFooter && $this->pageSize > 0) ? 1 : 0;
        $bottomBorderRows = $this->borderless ? 0 : 1;
        $rowCount = \count($rows);

        // Pre-compute visible column indices once before any row loops.
        // Avoids O(rows×cols) repeated isColumnVisible() calls.
        $visibleColumnIndices = $this->getVisibleColumnIndices();

        // When multilineMode is enabled, pre-calculate row heights. Expanded
        // rows are a single full-width detail line — one buffer row each —
        // regardless of their wrapped height (audit #8).
        $rowHeights = [];
        if ($this->multilineMode) {
            foreach ($rows as $ri => $row) {
                $rowHeights[$ri] = $this->isExpandedByRow($row)
                    ? 1
                    : $this->calculateRowHeight($row, $this->computedColumnWidths, $visibleColumnIndices);
            }
            $totalRowHeight = \array_sum($rowHeights);
        } else {
            $totalRowHeight = $rowCount;
        }

        // Every row — border, header, data, footer — spans exactly the
        // content width the columns actually render into. Deriving the border
        // from a different width than the data (the old scrollX-only branch)
        // desynced the frame from its gutters (audit #4); hidden and
        // scrolled-away columns are dropped from the layout entirely instead
        // of leaving blank cells, and an over-subscribed fixed set is clamped
        // back to the pinned width so no line can exceed the frame.
        $visibleWidth = $this->contentSpanWidth($this->computedColumnWidths);

        $bufferHeight = $topBorderRows + $headerRows + $totalRowHeight + $footerRows + $bottomBorderRows;
        // Borderless drops the +2 left/right border columns, so the buffer is
        // exactly the content width (every line == $visibleWidth cells).
        $bufferWidth = $visibleWidth + ($this->borderless ? 0 : 2);

        $buffer = Buffer::new($bufferWidth, $bufferHeight);
        $bufferRow = 0;

        // Top border
        if (!$this->borderless) {
            $buffer = $this->fillBorderRow($buffer, $bufferRow, $visibleWidth, 'top');
            $bufferRow++;
        }

        // Header
        if ($this->showHeader) {
            $buffer = $this->fillHeaderRow($buffer, $bufferRow, $visibleWidth, $this->computedColumnWidths);
            $bufferRow++;
            $buffer = $this->fillHeaderSeparatorRow($buffer, $bufferRow, $visibleWidth);
            $bufferRow++;
        }

        // Data rows. The selection index is PAGE-GLOBAL; the render loop
        // walks one page, so the highlight compares against the same domain
        // by offsetting the slot by the page start (audit #5).
        $pageOffset = $this->pageSize > 0 ? $this->page * $this->pageSize : 0;
        if ($rows === [] && !$this->showHeader) {
            // Render no_data message centered in the content area
            $buffer = $this->fillNoDataRow($buffer, $bufferRow, $visibleWidth);
            $bufferRow++;
        } else {
            foreach ($rows as $ri => $row) {
                $isSelected = (($pageOffset + $ri + $this->scrollY) === $this->selectedIndex) && $this->selectable;
                if ($this->isExpandedByRow($row)) {
                    $buffer = $this->fillExpandedRow($buffer, $bufferRow, $row, $ri, $isSelected, $visibleWidth, $visibleColumnIndices);
                    $bufferRow++;
                } elseif ($this->multilineMode) {
                    $buffer = $this->fillDataRowLines($buffer, $bufferRow, $row, $ri, $visibleWidth, $isSelected, $this->computedColumnWidths, $rowHeights[$ri], $visibleColumnIndices);
                    $bufferRow += $rowHeights[$ri];
                } else {
                    $buffer = $this->fillDataRow($buffer, $bufferRow, $row, $ri, $isSelected, $this->computedColumnWidths, $visibleColumnIndices);
                    $bufferRow++;
                }
            }
        }

        // Footer
        if ($this->showFooter && $this->pageSize > 0) {
            $buffer = $this->fillFooterRow($buffer, $bufferRow, $visibleWidth);
            $bufferRow++;
        }

        // Bottom border
        if (!$this->borderless) {
            $buffer = $this->fillBorderRow($buffer, $bufferRow, $visibleWidth, 'bottom');
        }

        return $buffer;
    }

    /**
     * Determine if a column at the given index is visible.
     *
     * Frozen columns are always visible. Non-frozen columns are visible
     * starting at index = count(frozenCols) + scrollX.
     * Hidden columns are never visible regardless of scroll or freeze state.
     */
    private function isColumnVisible(int $colIndex): bool
    {
        // Hidden columns are never visible
        if (\in_array($colIndex, $this->hiddenCols, true)) {
            return false;
        }
        if (\in_array($colIndex, $this->frozenCols, true)) {
            return true;
        }
        $scrollableStartIndex = \count($this->frozenCols) + $this->scrollX;
        return $colIndex >= $scrollableStartIndex;
    }

    /**
     * Return the list of visible column indices, in column order.
     *
     * Pre-computed once per render pass and reused by all row-rendering
     * methods to avoid O(rows×cols) repeated isColumnVisible() calls.
     *
     * @return list<int>
     */
    private function getVisibleColumnIndices(): array
    {
        $indices = [];
        foreach ($this->columns as $ci => $column) {
            if ($this->isColumnVisible($ci)) {
                $indices[] = $ci;
            }
        }
        return $indices;
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

    /**
     * The single content width every row of one render pass must fill: the
     * visible columns laid out contiguously, never exceeding a pinned
     * withWidth() frame. Borders, header rules, data, no-data and footer all
     * take their span from here (audit #4: the border used to be drawn from a
     * different measure than the data row when scrollX was 0, so gutters and
     * frame disagreed).
     */
    private function contentSpanWidth(array $computedWidths): int
    {
        $span = $this->computeVisibleContentWidth($computedWidths);
        if ($this->targetWidth > 0 && $span > $this->targetWidth) {
            return $this->targetWidth;
        }
        return $span;
    }

    /**
     * Write a row's left edge. Bordered: the left border glyph at col 0, content
     * starts at col 1. Borderless: nothing, content starts at col 0.
     *
     * @return array{0: Buffer, 1: int} [buffer, starting content column]
     */
    private function writeLeftEdge(Buffer $buffer, int $row, ?Style $style): array
    {
        if ($this->borderless) {
            return [$buffer, 0];
        }
        return [$buffer->withCellAt(0, $row, new Cell($this->borderLeft(), $style, null, 1)), 1];
    }

    /** Write a row's right edge at $col (no-op in borderless mode). */
    private function writeRightEdge(Buffer $buffer, int $row, int $col, ?Style $style): Buffer
    {
        if ($this->borderless || $col >= $buffer->width()) {
            return $buffer;
        }
        return $buffer->withCellAt($col, $row, new Cell($this->borderRight(), $style, null, 1));
    }

    /**
     * Write the inter-column separator at $col and return the next column.
     * Bordered: a vertical rule in the border style. Borderless: a single space
     * carrying the ROW style so a selected row's reverse highlight stays
     * continuous across the gap.
     *
     * @return array{0: Buffer, 1: int} [buffer, next column]
     */
    private function writeColumnSeparator(Buffer $buffer, int $row, int $col, ?Style $rowStyle, ?Style $borderStyle): array
    {
        if ($col >= $buffer->width()) {
            return [$buffer, $col + 1]; // defensive: too-narrow width, nothing to draw
        }
        $cell = $this->borderless
            ? new Cell(' ', $rowStyle, null, 1)
            : new Cell($this->borderCenterV(), $borderStyle, null, 1);
        return [$buffer->withCellAt($col, $row, $cell), $col + 1];
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

    private function fillHeaderRow(Buffer $buffer, int $row, int $_contentWidth, array $computedWidths): Buffer
    {
        $style = $this->parseAnsiToStyle($this->headerStyle);
        $sepStyle = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;
        [$buffer, $col] = $this->writeLeftEdge($buffer, $row, $style);

        $visibleColumnIndices = $this->getVisibleColumnIndices();
        $lastCi = $visibleColumnIndices === [] ? -1 : \end($visibleColumnIndices);

        // Header cells - visible columns laid out contiguously (hidden and
        // scrolled-away columns take no space, audit #4).
        foreach ($visibleColumnIndices as $ci) {
            $column = $this->columns[$ci];
            $colWidth = $computedWidths[$ci] ?? $column->width;

            // Account for cell padding in header width
            $effectiveWidth = $colWidth - (2 * $this->cellPadding);
            $effectiveWidth = \max(1, $effectiveWidth);
            $headerText = $column->renderHeader($effectiveWidth);
            $buffer = $this->fillCellContent($buffer, $row, $col, $headerText, $colWidth, $style);
            $col += $colWidth;

            if ($ci !== $lastCi) {
                [$buffer, $col] = $this->writeColumnSeparator($buffer, $row, $col, $style, $sepStyle);
            }
        }

        return $this->writeRightEdge($buffer, $row, $col, $sepStyle);
    }

    private function fillHeaderSeparatorRow(Buffer $buffer, int $row, int $contentWidth): Buffer
    {
        $style = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;
        [$buffer, $col] = $this->writeLeftEdge($buffer, $row, $style);

        // Separator rule spanning the content width.
        $buffer = $this->fillCellContent($buffer, $row, $col, \str_repeat($this->borderCenterH(), $contentWidth), $contentWidth, $style);
        $col += $contentWidth;

        return $this->writeRightEdge($buffer, $row, $col, $style);
    }

    private function fillDataRow(Buffer $buffer, int $row, Row $rowData, int $rowIndex, bool $isSelected, array $computedWidths, array $visibleColumnIndices): Buffer
    {
        // Determine row-level style
        $rowStyle = '';
        if ($rowData->style !== '') {
            $rowStyle = $rowData->style;
        }
        if ($this->zebraEnabled) {
            $zebra = ($rowIndex % 2 === 0) ? $this->zebraStyleEven : $this->zebraStyleOdd;
            if ($zebra !== '') {
                $rowStyle = $zebra;
            }
        }
        if ($isSelected) {
            $rowStyle = '7';
        } // reverse

        $style = $rowStyle !== '' ? $this->parseAnsiToStyle($rowStyle) : null;
        $sepStyle = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;
        [$buffer, $col] = $this->writeLeftEdge($buffer, $row, $style);

        // Data cells - visible columns laid out contiguously (hidden and
        // scrolled-away columns take no space, audit #4).
        $lastCi = $visibleColumnIndices === [] ? -1 : \end($visibleColumnIndices);
        foreach ($visibleColumnIndices as $ci) {
            $column = $this->columns[$ci];
            $colWidth = $computedWidths[$ci] ?? $column->width;

            $val = $rowData->data->get($column->key);

            if ($val === null) {
                $val = $this->missingIndicator;
            }

            // Determine cell-level style precedence: base < column < row < cell < selection
            $cellStyle = $this->baseStyle;
            if ($column->style !== '') {
                $cellStyle = $column->style;
            }
            if ($rowStyle !== '') {
                $cellStyle = $rowStyle;
            }
            if ($val instanceof StyledCell && $val->style !== '') {
                $cellStyle = $val->style;
            }

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

            // Sanitize before width padding / renderCell / styleFunc
            $cellStr = Sanitize::value($cellStr, false);

            if ($this->styleFunc !== null) {
                $rawResult = ($this->styleFunc)($rowIndex, $ci, $cellStr);
                $cellStyle = $this->normalizeStyleResult($rawResult, $cellStyle);
            }

            $style = $cellStyle !== '' ? $this->parseAnsiToStyle($cellStyle) : null;

            // Single-line rows clamp to the cell's effective width with an
            // ellipsis (audit #7: WrapMode/ellipsis routing never reached the
            // non-multiline path, so overlong content silently overflowed).
            $effectiveWidth = $colWidth - (2 * $this->cellPadding);
            $effectiveWidth = \max(1, $effectiveWidth); // At least 1 char for content
            $clipped = Column::clipToWidth($cellStr, $effectiveWidth);
            $displayText = $column->alignLeft
                ? Width::padRight($clipped, $effectiveWidth)
                : Width::padLeft($clipped, $effectiveWidth);

            $buffer = $this->fillCellContent($buffer, $row, $col, $displayText, $colWidth, $style);
            $col += $colWidth;

            if ($ci !== $lastCi) {
                [$buffer, $col] = $this->writeColumnSeparator($buffer, $row, $col, $style, $sepStyle);
            }
        }

        return $this->writeRightEdge($buffer, $row, $col, $sepStyle);
    }

    /**
     * Expanded row: ONE full-width detail line spanning the table's visible
     * content (audit #8 — the old branch kept per-cell column budgets, so
     * "expanded" rows rendered identically to collapsed ones). All visible
     * cells' text is joined with a two-space gap and clipped to the table
     * width with '…' when even the full span overflows. Row-level styling
     * (row style, zebra, selection reverse) applies to the whole line; cell
     * styles do not, because the line is no longer cell-structured.
     */
    private function fillExpandedRow(Buffer $buffer, int $row, Row $rowData, int $rowIndex, bool $isSelected, int $contentWidth, array $visibleColumnIndices): Buffer
    {
        $rowStyle = '';
        if ($rowData->style !== '') {
            $rowStyle = $rowData->style;
        }
        if ($this->zebraEnabled) {
            $zebra = ($rowIndex % 2 === 0) ? $this->zebraStyleEven : $this->zebraStyleOdd;
            if ($zebra !== '') {
                $rowStyle = $zebra;
            }
        }
        if ($isSelected) {
            $rowStyle = '7';
        } // reverse

        $style = $rowStyle !== '' ? $this->parseAnsiToStyle($rowStyle) : null;
        $sepStyle = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;

        $parts = [];
        foreach ($visibleColumnIndices as $ci) {
            $column = $this->columns[$ci];
            $val = $rowData->data->get($column->key);
            if ($val === null) {
                $val = $this->missingIndicator;
            }
            $raw = $val instanceof StyledCell ? $val->value : $val;
            $text = \is_object($raw) && method_exists($raw, '__toString')
                ? (string) $raw
                : (\is_scalar($raw) ? (string) $raw : '');
            $parts[] = Sanitize::value($text, false);
        }
        $detail = Column::clipToWidth(\implode('  ', $parts), $contentWidth);

        [$buffer, $col] = $this->writeLeftEdge($buffer, $row, $style);
        $buffer = $this->fillCellContent($buffer, $row, $col, $detail, $contentWidth, $style);

        return $this->writeRightEdge($buffer, $row, $col + $contentWidth, $sepStyle);
    }

    /**
     * Calculate the height of a row in lines based on cell content after wrapping.
     *
     * When multilineMode is enabled, row height equals the maximum number of
     * lines across all visible cells. This accounts for text wrapping in each cell.
     */
    private function calculateRowHeight(Row $row, array $computedWidths, array $visibleColumnIndices): int
    {
        $maxLines = 1;

        foreach ($this->columns as $ci => $column) {
            if (!\in_array($ci, $visibleColumnIndices, true)) {
                continue;
            }

            $colWidth = $computedWidths[$ci] ?? $column->width;
            $effectiveWidth = $colWidth - (2 * $this->cellPadding);
            $effectiveWidth = \max(1, $effectiveWidth);
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
            // Sanitize before renderCell width measurement
            $cellStr = Sanitize::value($cellStr, true);

            $lines = $column->renderCell($cellStr, $effectiveWidth);
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
    private function fillDataRowLines(Buffer $buffer, int $startRow, Row $row, int $rowIndex, int $_contentWidth, bool $isSelected, array $computedWidths, int $rowHeight, array $visibleColumnIndices): Buffer
    {
        // Determine row-level style
        $rowStyle = '';
        if ($row->style !== '') {
            $rowStyle = $row->style;
        }
        if ($this->zebraEnabled) {
            $zebra = ($rowIndex % 2 === 0) ? $this->zebraStyleEven : $this->zebraStyleOdd;
            if ($zebra !== '') {
                $rowStyle = $zebra;
            }
        }
        if ($isSelected) {
            $rowStyle = '7';
        } // reverse

        $style = $rowStyle !== '' ? $this->parseAnsiToStyle($rowStyle) : null;
        $sepStyle = $this->borderStyle !== '' ? $this->parseAnsiToStyle($this->borderStyle) : null;

        // Collect cell lines for each column (only visible columns)
        $cellLines = [];
        $colWidths = [];
        foreach ($visibleColumnIndices as $ci) {
            $column = $this->columns[$ci];

            $colWidth = $computedWidths[$ci] ?? $column->width;
            $colWidths[$ci] = $colWidth;

            $val = $row->data->get($column->key);
            if ($val === null) {
                $val = $this->missingIndicator;
            }

            // Determine cell-level style precedence: base < column < row < cell < selection
            $cellStyle = $this->baseStyle;
            if ($column->style !== '') {
                $cellStyle = $column->style;
            }
            if ($rowStyle !== '') {
                $cellStyle = $rowStyle;
            }
            if ($val instanceof StyledCell && $val->style !== '') {
                $cellStyle = $val->style;
            }

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

            // Sanitize before styleFunc (multiline context: preserve \n for explode)
            $cellStr = Sanitize::value($cellStr, true);

            if ($this->styleFunc !== null) {
                $rawResult = ($this->styleFunc)($rowIndex, $ci, $cellStr);
                $cellStyle = $this->normalizeStyleResult($rawResult, $cellStyle);
            }

            $parsedStyle = $cellStyle !== '' ? $this->parseAnsiToStyle($cellStyle) : null;

            // Account for cell padding in content width. Expanded rows never
            // reach here — renderToBuffer routes them to fillExpandedRow.
            $effectiveWidth = $colWidth - (2 * $this->cellPadding);
            $effectiveWidth = \max(1, $effectiveWidth);
            $lines = $column->renderCell($cellStr, $effectiveWidth);
            $cellLines[$ci] = ['lines' => $lines, 'style' => $parsedStyle, 'width' => $colWidth];
        }

        // Render each line of the row
        for ($lineIdx = 0; $lineIdx < $rowHeight; $lineIdx++) {
            $bufferRow = $startRow + $lineIdx;

            // Left border (full height)
            [$buffer, $col] = $this->writeLeftEdge($buffer, $bufferRow, $style);

            // Render each visible cell, laid out contiguously (audit #4).
            $lastCi = $visibleColumnIndices === [] ? -1 : \end($visibleColumnIndices);
            foreach ($visibleColumnIndices as $ci) {
                $colWidth = $colWidths[$ci];
                $cellData = $cellLines[$ci];
                $lines = $cellData['lines'];
                $cellStyle = $cellData['style'];

                // Get the line to render at this row position
                if ($lineIdx < \count($lines)) {
                    $displayText = $lines[$lineIdx];
                } else {
                    // Pad with empty space for shorter cells (use effective width for padding)
                    $effectiveWidth = $colWidth - (2 * $this->cellPadding);
                    $effectiveWidth = \max(1, $effectiveWidth);
                    $displayText = \str_repeat(' ', $effectiveWidth);
                }

                $buffer = $this->fillCellContent($buffer, $bufferRow, $col, $displayText, $colWidth, $cellStyle);
                $col += $colWidth;

                if ($ci !== $lastCi) {
                    [$buffer, $col] = $this->writeColumnSeparator($buffer, $bufferRow, $col, $style, $sepStyle);
                }
            }

            // Right border (full height)
            $buffer = $this->writeRightEdge($buffer, $bufferRow, $col, $sepStyle);
        }

        return $buffer;
    }

    private function fillFooterRow(Buffer $buffer, int $row, int $contentWidth): Buffer
    {
        $style = $this->parseAnsiToStyle($this->footerStyle);

        $label = match ($this->footerType) {
            FooterType::Page => $this->PageFooter(),
            FooterType::Rows => $this->RowsFooter(),
            FooterType::Both => $this->PageFooter() . '  |  ' . $this->RowsFooter(),
        };

        $content = $this->centeredLabel($label, $contentWidth);

        [$buffer, $col] = $this->writeLeftEdge($buffer, $row, $style);
        $buffer = $this->fillCellContent($buffer, $row, $col, $content, $contentWidth, $style);
        $col += $contentWidth;

        return $this->writeRightEdge($buffer, $row, $col, $style);
    }

    /**
     * Center one label in a display-width budget (floor bias to the left),
     * truncating with candy-core Width first when it overflows. Shared by the
     * footer and the empty-state row — the footer used to center by BYTE count
     * (strlen/substr), which skewed anything non-ASCII out of the middle.
     */
    private function centeredLabel(string $label, int $contentWidth): string
    {
        if ($contentWidth <= 0) {
            return '';
        }
        $labelWidth = Width::of($label);
        if ($labelWidth > $contentWidth) {
            $label = Width::truncate($label, $contentWidth);
            $labelWidth = Width::of($label);
        }
        $padLeft = \intdiv($contentWidth - $labelWidth, 2);
        $padRight = $contentWidth - $labelWidth - $padLeft;

        return \str_repeat(' ', $padLeft) . $label . \str_repeat(' ', $padRight);
    }

    /**
     * Render the "no data" empty state message centered in the content area.
     */
    private function fillNoDataRow(Buffer $buffer, int $row, int $contentWidth): Buffer
    {
        $style = $this->parseAnsiToStyle($this->footerStyle);
        $content = $this->centeredLabel(Lang::t('no_data'), $contentWidth);

        [$buffer, $col] = $this->writeLeftEdge($buffer, $row, $style);
        $buffer = $this->fillCellContent($buffer, $row, $col, $content, $contentWidth, $style);
        $col += $contentWidth;

        return $this->writeRightEdge($buffer, $row, $col, $style);
    }

    /**
     * Fill a region of the buffer with cell content, handling wide characters.
     */
    private function fillCellContent(Buffer $buffer, int $row, int $startCol, string $text, int $cellWidth, ?Style $style): Buffer
    {
        $clusters = $this->graphemeClusters($text);
        $bufWidth = $buffer->width();
        $col = $startCol;
        $lastCol = null;
        $lastCluster = '';
        $lastGw = 1;

        foreach ($clusters as $cluster) {
            if ($col >= $bufWidth) {
                break; // never write past the buffer (defensive: too-narrow width)
            }
            // Candy-core Width is the single EAW oracle for the whole monorepo
            // (audit finding #10 — this file used to carry its own codepoint
            // range tables, which silently drifted from the canonical one).
            // A zero-width cluster (combining mark, ZWJ, variation selector)
            // occupies no slot of its own: it is appended to the cell it
            // follows instead of being inflated to a full cell, which keeps
            // the buffer's cell count in lockstep with the Width::of totals
            // that sized the column.
            $gw = Width::of($cluster);
            if ($gw === 0) {
                if ($lastCol !== null) {
                    $buffer = $buffer->withCellAt(
                        $lastCol,
                        $row,
                        new Cell($lastCluster . $cluster, $style, null, $lastGw)
                    );
                    $lastCluster .= $cluster;
                }
                continue;
            }

            // Clamp to remaining width
            $remaining = $cellWidth - ($col - $startCol);
            if ($gw > $remaining) {
                $gw = $remaining;
            }
            if ($gw <= 0) {
                break;
            }

            $buffer = $buffer->withCellAt($col, $row, new Cell($cluster, $style, null, $gw));
            $lastCol = $col;
            $lastCluster = $cluster;
            $lastGw = $gw;

            // A wide grapheme occupies two slots: the display-width-2 cell at $col
            // plus a continuation marker at $col + 1 (NOT $col + 2 — advancing by
            // $gw already covers both slots).
            if ($gw === 2 && $col + 1 < $bufWidth) {
                $buffer = $buffer->withCellAt($col + 1, $row, Cell::continuation());
            }
            $col += $gw;
        }

        // Fill remaining width with spaces if needed (clamped to the buffer).
        while (($col - $startCol) < $cellWidth && $col < $bufWidth) {
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
        if ($attrs & Style::ATTR_BOLD) {
            $codes[] = '1';
        }
        if ($attrs & Style::ATTR_FAINT) {
            $codes[] = '2';
        }
        if ($attrs & Style::ATTR_ITALIC) {
            $codes[] = '3';
        }
        if ($attrs & Style::ATTR_UNDERLINE) {
            $codes[] = '4';
        }
        if ($attrs & Style::ATTR_BLINK) {
            $codes[] = '5';
        }
        if ($attrs & Style::ATTR_REVERSE) {
            $codes[] = '7';
        }
        if ($attrs & Style::ATTR_STRIKE) {
            $codes[] = '9';
        }
        if ($attrs & Style::ATTR_OVERLINE) {
            $codes[] = '53';
        }

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
                // Standard colors 30-37 (fg) / 40-47 (bg) and bright 90-97 /
                // 100-107 — all sixteen slots resolve through the shared
                // decoder below; the former per-case 256-cube literals
                // (0xcc*/0x808080/#0000ff blues) are deleted.
                case 30: case 31: case 32: case 33: case 34: case 35: case 36: case 37:
                    $fg = $this->ansiColorToRgb($code - 30, false);
                    break;
                case 40: case 41: case 42: case 43: case 44: case 45: case 46: case 47:
                    $bg = $this->ansiColorToRgb($code - 40, false);
                    break;
                case 90: case 91: case 92: case 93: case 94: case 95: case 96: case 97:
                    $fg = $this->ansiColorToRgb($code - 90, true);
                    break;
                case 100: case 101: case 102: case 103: case 104: case 105: case 106: case 107:
                    $bg = $this->ansiColorToRgb($code - 100, true);
                    break;
            }
        }

        return Style::new($fg, $bg, $attrs);
    }

    /**
     * Map an SGR colour index (0-7, bright variant when {@see $bright}) to a
     * packed 24-bit RGB int. The triples come from candy-core's canonical
     * xterm table — slot 4 = `#0000EE` (`main.h DEF_COLOR4 "blue2"`), slot 12
     * = `#5C5CFF` (`DEF_COLOR12 "rgb:5c/5c/ff"`) — indexed from
     * {@see Color::ANSI16_RGB} so this decode can never fork its own blues
     * again. Both callers only ever supply 0-7, so the out-of-range guard is
     * defensive: it lands on the table's white slot (7, or 15 for the bright
     * half). Malformed negative `38;5;-n` input never reaches here — it keeps
     * its own default-to-black in {@see color256ToRgb()}.
     */
    private function ansiColorToRgb(int $idx, bool $bright): int
    {
        $offset = $bright ? 8 : 0;
        [$r, $g, $b] = Color::ANSI16_RGB[$offset + $idx] ?? Color::ANSI16_RGB[$offset + 7];
        return ($r << 16) | ($g << 8) | $b;
    }

    /**
     * Convert a 256-color index to RGB.
     */
    private function color256ToRgb(int $idx, bool $_isFg): int
    {
        if ($idx < 16) {
            // Malformed negative index (e.g. "38;5;-5" off the wire) predates
            // the canon and must keep the black default it always had rather
            // than the decoder's defensive white slot.
            if ($idx < 0) {
                return 0x000000;
            }
            // Standard colors — the same 16-slot canon the SGR 30-37/40-47
            // and 90-97/100-107 codes decode to; the old duplicated cube
            // table here is deleted so `38;5;n` (n<16) can never diverge
            // from its `3n`/`4n` spelling.
            return $this->ansiColorToRgb($idx % 8, $idx >= 8);
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
        // Horizontal header-separator rule: use top border char (─) not middle (┼)
        return $this->border?->top ?? $this->borderCenterH;
    }

    private function borderCenterV(): string
    {
        // Vertical column separator: use left border char (│) not middleLeft (├)
        return $this->border?->left ?? $this->borderCenterV;
    }

    private function computeTotalWidth(): int
    {
        // A pinned width wins: the content span is exactly targetWidth (Flex
        // columns absorb whatever the Fixed/Percent columns and gaps leave).
        if ($this->targetWidth > 0) {
            return $this->targetWidth;
        }

        // Borders: one char between each column.
        $borderCount = \max(0, \count($this->columns) - 1);

        // Seed the solve with the raw declared widths + inter-column gaps.
        $width = $borderCount;
        foreach ($this->columns as $col) {
            $width += $col->width;
        }

        // Percent columns size themselves as a fraction of the TOTAL width, yet
        // the total is the sum of the resolved widths — so a single pass leaves
        // the reported total inconsistent with what the columns actually render
        // (worst on mixed Percent + Dynamic/Content sets). Iterate
        // computeColumnWidths() to a fixed point: once the resolved widths sum
        // back to the width we fed in, both are self-consistent. The loop is
        // bounded (WIDTH_CONVERGE_MAX) so an over-subscribed Percent set with no
        // fixed point still terminates and clamps to the last estimate.
        for ($i = 0; $i < self::WIDTH_CONVERGE_MAX; $i++) {
            $computedWidths = $this->computeColumnWidths($width);
            $next = $borderCount;
            foreach ($computedWidths as $w) {
                $next += $w;
            }
            if ($next === $width) {
                return $width;
            }
            $width = $next;
        }

        return $width;
    }
}
