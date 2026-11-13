<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * Customizable data table component: programmatic row cursor, keyboard-driven
 * scrolling, and per-cell styling. It never handles mouse input itself — wire
 * input events in the host application.
 *
 * Features: column definitions, row data, styled cells, selection,
 * pagination, sorting, filtering, frozen columns, horizontal scroll,
 * zebra striping, missing data indicators, border styling.
 *
 * Cell values are read as plain display text: objects with __toString are
 * stringified, StyledCell contributes its inner value (never its SGR
 * wrapper), and non-scalar values without __toString read as empty text —
 * one stdClass cell costs that cell's matchability, not the pane. The
 * component itself never wires terminal input: it exposes handleKey() and
 * cursor APIs for a host to call from its own keyboard loop, and it has no
 * mouse handling at all.
 *
 * Render caches — the per-column content-width measurements, the width
 * solve, the filter/sort rebuild — live inside this value and ride along
 * with every wither that does not change what is measured. The supported
 * usage is therefore to hold ONE Table in application state and wither it
 * per frame; rebuilding from fromColumns() inside a render loop forfeits
 * that work (audit ST-33 — see README "Keeping a Table across frames").
 *
 * Port of Evertras/bubble-table.
 *
 * @see https://github.com/Evertras/bubble-table
 */
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
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
     * Rows rendered as expanded detail lines: one full-width row carrying
     * every visible column's content, clipped at the table edge.
     *
     * The set is keyed by Row OBJECT IDENTITY, not by index (audit ST-25 —
     * the old index-set annotation was a lie: the writers below push Row
     * instances and every reader compares with strict in_array). The public
     * entry points accept PAGE-RELATIVE indices, resolve them against
     * pagedRows() (throwing OutOfBoundsException on a miss), and store the
     * resolved identities; withRows() prunes identities that leave the data
     * set. Expansion thus follows a Row across page turns while the input
     * numbers address the current page.
     *
     * @var list<Row>
     */
    private array $expandedRows = [];

    /**
     * Opt-in strict sanitization for hosts that compose the rendered frame
     * into a Private-Use-codepoint-marked surface (sugar-mouse zones, the
     * U+E002-and-up image-marker arena): every ingress sweep additionally
     * removes the whole BMP Private Use block so cell data can never forge
     * an arena marker (audit ST-47, finding 56). Default OFF — PUA glyphs
     * are legitimate content for ordinary surfaces.
     */
    private bool $markedFrameSanitization = false;

    /** @var array<string,Style> Parsed-SGR memo, valid for one render pass only. */
    private array $styleMemo = [];

    /** Cached computed column widths from the last render pass. @var array<int, int>|null */
    private ?array $computedColumnWidths = null;

    /**
     * Cached result of filteredSortedRows(). Cleared on any filter/sort/row
     * change. The width solve never reads this view — Content/Dynamic columns
     * measure ALL rows by design (audit ST-66, see contentWidthCache).
     *
     * @var list<Row>|null
     */
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

    /**
     * Per-column cache for contentWidthForColumn(): the content max width
     * depends only on the rows and the column definition, so every seed
     * width in computeTotalWidth()'s fixed-point solve wants the SAME
     * number — yet each of the (up to WIDTH_CONVERGE_MAX) diverging solve
     * iterations used to re-scan the entire row set. Keyed by column index,
     * cleared by invalidateRenderCaches() exactly like widthSolveCache.
     *
     * contentWidthForColumn() scans ALL rows regardless of the rendered
     * view — widths are view-INDEPENDENT by design (audit ST-66, finding
     * 082): filter and search never re-solve columns, so the frame cannot
     * jitter under typing. A "perf win" that solved visible rows only would
     * change both semantics and layout; the behavior pin in
     * TableColumnWidthTest keeps that red on arrival.
     *
     * @var array<int, int>
     */
    private array $contentWidthCache = [];

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
     * (e.g. a sugar-panels content box) without a double border. The header
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
        // Entry-point type gate (audit ST-09): a bad column is named with its
        // index here instead of throwing a raw TypeError from a deep render —
        // the exact failure mode the row factory used to have.
        foreach ($columns as $i => $col) {
            if (!$col instanceof Column) {
                throw new \InvalidArgumentException(
                    'fromColumns()[' . $i . '] must be Column, got ' . (\is_object($col) ? \get_class($col) : \gettype($col))
                );
            }
        }

        $t = new self();
        $t->columns = \array_values($columns);
        return $t;
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Normalize an incoming row list (audit ST-09): Row instances pass
     * through, plain associative arrays are coerced through
     * Row::new(RowData::from(...)) — the array shape README quickstarts and
     * candy-sql call sites already use becomes honored, not a deep TypeError —
     * and anything else is rejected by name and index at the boundary.
     *
     * @param array<array-key, Row|array<array-key, mixed>> $rows
     *
     * @return list<Row>
     */
    private static function normalizeRows(array $rows, string $method): array    {
        $out = [];
        foreach ($rows as $i => $row) {
            if ($row instanceof Row) {
                $out[] = $row;
            } elseif (\is_array($row)) {
                $out[] = Row::new(RowData::from($row));
            } else {
                throw new \InvalidArgumentException(
                    $method . '[' . $i . '] must be Row or array, got ' . (\is_object($row) ? \get_class($row) : \gettype($row))
                );
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, Row|array<array-key, mixed>> $rows Row objects,
     *     or plain data arrays coerced to rows at the boundary.
     */
    public function withRows(array $rows): self
    {
        $rows  = self::normalizeRows($rows, 'withRows()');
        $clone = clone $this;
        $clone->rows    = $rows;
        // Expansion is tracked by Row object identity: rows swapped out from
        // under the table can no longer be expanded, and retaining the stale
        // objects would pin them forever (audit M12: the leak was released
        // only by re-toggling the SAME object, which a refresh makes
        // impossible). Prune to the identities that still exist — ST-64: one
        // spl_object_id key set per call instead of in_array×N. A Row object
        // passed in survives normalizeRows() as the very instance stored at
        // its slot, so the post-normalization ids match the old scan exactly.
        $liveIds = [];
        foreach ($rows as $row) {
            $liveIds[\spl_object_id($row)] = true;
        }
        $clone->expandedRows = \array_values(\array_filter(
            $clone->expandedRows,
            static fn (Row $r): bool => isset($liveIds[\spl_object_id($r)]),
        ));
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
        $this->contentWidthCache = [];
    }

    /**
     * Park the cursor on the first row of the first page (on a clone). Any
     * change that rebuilds the view — rows replaced, sort flipped, filter or
     * search edited — re-anchors here: a selection index chosen against the
     * old view has no meaning in the new one, and a page beyond the shrunken
     * result would render an empty table (audit #5/#9: one cursor domain,
     * always inside the view). Scrolling re-anchors with the cursor: a deep
     * scroll against a narrowed view stranded the frame as a header-only box
     * (audit M9).
     */
    private function resetCursor(): void
    {
        $this->selectedIndex = 0;
        $this->page = 0;
        $this->scrollY = 0;
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
        $count = \count($columns);

        // ST-13: the index sets are column-count contracts, not re-pin hints.
        // A stale frozen index used to silently freeze whatever column landed
        // at that slot (the QA probe: frozen [0,1] shrunk to one column made
        // withScrollX(1) render empty); hidden slots for removed columns are
        // inert, so those intersect away.
        foreach ($this->frozenCols as $index) {
            if ($index >= $count) {
                throw new \InvalidArgumentException(
                    "Frozen column index {$index} does not exist in the new column set ({$count} columns)"
                );
            }
        }

        $clone = clone $this;
        $clone->columns = $columns;
        $clone->hiddenCols = \array_values(\array_filter(
            $this->hiddenCols,
            static fn (int $index): bool => $index < $count,
        ));
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

    /**
     * Change the page size and RE-ANCHOR against the new pagination. The
     * cursor law (audit #5/#9, see resetCursor()) says every view-rebuilding
     * setter keeps the selection inside the view; a page count change is the
     * most literal rebuild there is, and this setter used to miss it — shrink
     * past the last surviving page left the table rendering an empty "Page 3
     * of 1" box. Growth keeps the current page, re-paginated under the new
     * size (gotoPage clamps into [0, TotalPages-1] and parks the cursor on
     * the page's first row).
     */
    public function withPageSize(int $n): self
    {
        $clone = clone $this;
        $clone->pageSize = $n;
        if ($n <= 0) {
            // Pagination off: the whole view is one frame, no page can be
            // stranded — nothing to re-anchor.
            return $clone;
        }

        return $clone->gotoPage($clone->page);
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
     * clamped when the last page is short). The viewport follows the snapped
     * cursor (ST-15 / audit M9): a stale scrollY never strands the highlight.
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
        // ST-15 (audit M9 law): the snapped cursor must be on-screen after the
        // page turn — a stale scrollY used to strand CurrentRow off the frame.
        $clone->followCursor();
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
        // ST-15 default arm: scroll follows the cursor always. The window may
        // move toward the cursor but never past it, so the highlighted row is
        // on-screen after every scroll (UXD's END-key zero-reverse probe).
        // The free-scroll + off-screen-marker alternative stays in OG-3's family.
        $clone->followCursor();
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
     * @param Border $border Any Border from \SugarCraft\Style\Border
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
     * $row is the 0-based slot within the rows rendered in the current
     * frame — page- and scroll-relative, not the absolute index into
     * Rows(). $value arrives post-Sanitize (control and private-use
     * characters already stripped), so match on display-safe text.
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
    /**
     * Route every content/header ingress through the marked-frame policy
     * (strict Private-Use sweep). Residual host duty: values that never
     * pass through a table ingress — styleFunc-returned SGR strings,
     * configured border glyphs and the missing-value indicator — remain
     * host-controlled and are NOT swept by this flag.
     */
    public function withMarkedFrameSanitization(bool $v = true): self
    {
        $clone = clone $this;
        $clone->markedFrameSanitization = $v;
        return $clone;
    }

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
     *
     * A pin is honoured in both directions (audit ST-16): pinning BELOW the
     * declared solve scales the visible columns down proportionally (content
     * then clips with a visible '…' instead of vanishing off-canvas), and
     * pinning ABOVE it with no Flex or Percent column to absorb the surplus
     * throws — a width that cannot be honoured is announced, never erased.
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
     * Hidden columns are excluded from rendering AND from the width solve
     * (audit ST-11: they consume no border, budget, share or slack) but
     * still participate in data storage, filtering, sorting, and search
     * operations. This is useful for optional columns that can be toggled
     * visible/invisible.
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
        $normalized = $this->normalizeHiddenIndexes($indices, \count($this->columns));
        $clone = clone $this;
        $clone->hiddenCols = $normalized;
        // The hidden set is a solve input — swapping it must re-solve, the
        // same way replacing rows does (ST-11 cache-bust leg).
        $clone->invalidateRenderCaches();
        return $clone;
    }

    /**
     * Sort + unique + range-validate a hidden-column index set, symmetric to
     * the withFrozenCols guards (finding 059): the raw set was a divergence
     * seed for any positional iteration of hiddenCols.
     *
     * @param array<array-key, mixed> $indices
     * @return list<int>
     */
    private function normalizeHiddenIndexes(array $indices, int $count): array
    {
        foreach ($indices as $index) {
            if (!\is_int($index)) {
                throw new \InvalidArgumentException(
                    'Hidden column index must be int, got ' . \get_debug_type($index)
                );
            }
        }

        $sorted = $indices;
        \sort($sorted);
        $sorted = \array_values(\array_unique($sorted));

        foreach ($sorted as $index) {
            if ($index < 0 || $index >= $count) {
                throw new \InvalidArgumentException(
                    "Hidden column index {$index} out of range (table has {$count} columns)"
                );
            }
        }

        return $sorted;
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
     * Page-indexed predicate, not a property accessor: there is no bare
     * accessor here for it to duplicate, and `isExpanded` keeps the
     * sentence-reading bool name (ruling 3b era keeper).
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
        $rows  = self::normalizeRows($rows, 'addRows()');
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
        $clone->followCursor();
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
        $clone->followCursor();
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
     * Keep the cursor inside the viewport window on this (clone) instance:
     * scrolling follows the selection the way paging already does. Without
     * it the highlight can walk out of existence under a fixed viewport
     * (audit M9: SelectNext moved the page but never scrollY).
     */
    private function followCursor(): void
    {
        if ($this->viewportHeight <= 0) {
            return;
        }
        $pageOffset = $this->pageSize > 0 ? $this->page * $this->pageSize : 0;
        $slot = $this->selectedIndex - $pageOffset;
        if ($slot < 0) {
            $slot = 0;
        }
        if ($slot < $this->scrollY) {
            $this->scrollY = $slot;
        } elseif ($slot >= $this->scrollY + $this->viewportHeight) {
            $this->scrollY = $slot - $this->viewportHeight + 1;
        }
        $this->scrollY = \max(0, \min($this->scrollY, $this->maxScrollY()));
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
        $clone->followCursor();
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
     * key sequences that input libraries (e.g. sugar-pty, brick/console) emit.
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
     * The applied window is cursor-preserving (ST-15): keys move the viewport
     * toward the highlight, never past it. Use withSelectedIndex()/SelectNext()
     * to travel — cursor travel scrolls the window to reveal itself.
     *
     * @param string $key One of the KEY_* constants
     * @return self New Table instance with adjusted scrollY
     *
     * @see scrollYForKey()  For the raw (uncoupled) scrollY calculation
     */
    public function handleKey(string $key): self
    {
        return $this->withScrollY($this->scrollYForKey($key));
    }

    /**
     * The largest scrollY the current view can show.
     *
     * Page-aware: with pagination on, the scrollable region is the rows of
     * the CURRENT page, not the whole filtered set (audit M9: a page-blind
     * End key parked the viewport past the page and rendered a header-only
     * box with live matches stranded off-screen). Public so hosts can drive
     * paging UI from it instead of re-deriving the geometry (audit M19).
     */
    public function maxScrollY(): int
    {
        if ($this->viewportHeight <= 0) {
            return 0;
        }
        $visible = $this->pageSize > 0 ? \count($this->pagedRows()) : $this->TotalRows();

        return \max(0, $visible - $this->viewportHeight);
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

        // A filter that cannot be honoured is announced, not swallowed (audit
        // ST-26, finding 26): hosts passing filter text across mixed
        // filterable/non-filterable sets used to get a silent no-op that hid
        // the worst case. Now symmetric with SortBy's key-domain throws.
        $filterableKeys = $this->filterableKeys();
        $hasExplicitFilterable = $this->hasExplicitFilterable();
        if ($hasExplicitFilterable && !\in_array($colKey, $filterableKeys, true)) {
            throw new \InvalidArgumentException(
                "Filter column key '{$colKey}' is not filterable (add Column::withFilterable())"
            );
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
     * Global full-text search (case-insensitive).
     *
     * Scans every column while NO column is marked filterable (the
     * back-compat default); once at least one column carries
     * {@see Column::withFilterable()}, the scan is restricted to the
     * filterable-marked columns only. Matching is case-insensitive and
     * uses OR logic: a row is included if ANY scanned column's value
     * contains the search text.
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
                            continue; // defensive: entry validates (ST-26); stale text can only arrive via a post-hoc column swap
                        }
                        $val = $row->data->get($key);
                        if (\stripos(self::plainText($val), $text) === false) {
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
                        // ST-31: no per-haystack strtolower — case folding is
                        // stripos's own job (the Filter path has always done
                        // exactly this; matching its precedent also keeps the
                        // two paths' UTF-8 folding behaviour identical).
                        if (\stripos(self::plainText($val), $searchLower) !== false) {
                            return true;
                        }
                    }
                    return false;
                })
            );
        }

        // Sort (audit ST-30: Schwartzian decoration — the old comparator ran
        // plainText() on both sides of every comparison, i.e. O(rows · log
        // rows · keys) value renders per keystroke on search-sorted hosts
        // like candy-sql; each row's key strings are now derived exactly once
        // per sort key).
        if ($this->sortColumns !== []) {
            $decorated = [];
            foreach ($rows as $row) {
                $sortKeys = [];
                foreach ($this->sortColumns as $sort) {
                    $sortKeys[] = self::plainText($row->data->get($sort['key']));
                }
                $decorated[] = [$sortKeys, $row];
            }

            // PHP's usort is stable: comparing equal decorated pairs keeps
            // insertion order — the documented tie behaviour (multi-key
            // comparators resolve on their own; a full tie is row order).
            $keys = $this->sortColumns;
            \usort($decorated, static function (array $a, array $b) use ($keys): int {
                foreach ($keys as $i => $sort) {
                    $sa = $a[0][$i];
                    $sb = $b[0][$i];

                    // Numeric sort
                    if (\is_numeric($sa) && \is_numeric($sb)) {
                        $cmp = (float) $sa <=> (float) $sb;
                    } else {
                        $cmp = \strcasecmp($sa, $sb);
                    }

                    if ($cmp !== 0) {
                        return $sort['asc'] ? $cmp : -$cmp;
                    }
                }
                return 0;
            });

            $rows = [];
            foreach ($decorated as [, $row]) {
                $rows[] = $row;
            }
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

    /**
     * Active sort stack in priority order: the first entry is the primary
     * key, later entries tie-break it (audit ST-03 — the state SortBy() and
     * ClearSort() mutate was write-only; hosts re-tracked it blind).
     *
     * @return list<array{key: string, asc: bool}>
     */
    public function sortColumns(): array
    {
        return $this->sortColumns; // value copy: callers cannot mutate table state
    }

    /**
     * Active per-column filter texts, keyed by column key (the state
     * Filter()/ClearFilter() mutate; an empty map means unfiltered).
     *
     * @return array<string, string>
     */
    public function activeFilters(): array
    {
        return $this->filterText;
    }

    /** Active global search needle ('' when unset). */
    public function searchText(): string
    {
        return $this->searchText;
    }

    /**
     * Total rendered width of the table in columns (pinned width when
     * set, else the solved content widths plus inter-column borders) —
     * the pre-render budget readout candy-sql hand-computes today
     * (audit finding 075; cross-lib note is report-only, out of group).
     */
    public function totalWidth(): int
    {
        return $this->computeTotalWidth();
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
     * The solve runs against the VISIBLE column set (audit ST-11): hidden
     * columns are neutral placeholders — they consume no border, no fixed
     * budget, no flex share, and no slack basis — because the renderer lays
     * out visible columns only. Planning against all columns solved a hidden
     * third to 1/3 of the width while the frame drew the survivors short of
     * their documented full absorption.
     *
     * @return array<int, int>  colIndex => computed width in chars
     */
    private function doComputeColumnWidths(int $tableWidth): array
    {
        $widths = [];
        $flexCount = 0;
        $reservedWidth = 0;  // borders between columns

        $hiddenLookup = \array_flip($this->hiddenCols);
        $visibleCount = 0;

        // First pass: collect Fixed/Percent widths, count flexible columns
        foreach ($this->columns as $i => $col) {
            if (isset($hiddenLookup[$i])) {
                // Never rendered; carry the declared width as a neutral
                // placeholder so index-based consumers keep their addressing.
                $widths[$i] = $col->width;
                continue;
            }
            $visibleCount++;
            $cw = $col->columnWidth;
            if ($cw === ColumnWidth::Fixed) {
                $widths[$i] = $col->width;
            } elseif ($cw === ColumnWidth::Percent) {
                $widths[$i] = (int) \floor($tableWidth * $col->percentValue / 100);
            } else {
                $widths[$i] = null;  // Dynamic or Content — placeholder
                $flexCount++;
            }
        }

        // Count borders: one between each VISIBLE column
        $borderCount = $visibleCount - 1;
        if ($borderCount > 0) {
            $reservedWidth += $borderCount;
        }

        // Flexible columns get remaining space
        if ($flexCount > 0) {
            $fixedWidth = 0;
            foreach ($this->columns as $i => $col) {
                if ($widths[$i] !== null && !isset($hiddenLookup[$i])) {
                    $fixedWidth += $widths[$i];
                }
            }
            $remaining = $tableWidth - $reservedWidth - $fixedWidth;

            // Weighted flex split (audit MAJOR 15): a Flex column's
            // flexibleWidth is a WEIGHT, not decoration. Every flexible slot
            // holds at least one share — unweighted Flex, Dynamic, and Content
            // columns each count as a single share, so when nothing carries a
            // weight the split below is byte-identical to the old equal split.
            $shares = [];
            $totalShares = 0;
            foreach ($this->columns as $i => $col) {
                if ($widths[$i] !== null) {
                    continue;
                }
                $shares[$i] = $col->columnWidth === ColumnWidth::Flex
                    ? \max(1, $col->flexibleWidth)
                    : 1;
                $totalShares += $shares[$i];
            }
            $flexWidth = $remaining > 0 && $totalShares > 0
                ? (int) \floor($remaining / $totalShares)
                : 0;

            // Apply Dynamic/Content/Flex as content-based or share-based width
            foreach ($this->columns as $i => $col) {
                if ($widths[$i] === null) {
                    $contentLen = $this->contentWidthForColumn($i);
                    if ($col->columnWidth === ColumnWidth::Flex) {
                        // Flex: take its weighted share verbatim, ignoring
                        // content (it is truncated to fit) — the basis for an
                        // exact total width. May shrink to 0 when the width is
                        // too tight to spare any.
                        $widths[$i] = $remaining > 0
                            ? \max(0, (int) \floor($remaining * $shares[$i] / $totalShares))
                            : 0;
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

        // A pinned width must announce in both wrong directions, never erase
        // (audit ST-16): under-pin scales every visible column down into the
        // frame proportionally — content clips through the announcing
        // clipToWidth '…' path instead of being shoved off-canvas into a
        // blank; over-pin hands the remainder to the last Flex column (the
        // integer-rounding slack that makes a selection highlight reach the
        // right edge), or distributes it across visible Percent columns, and
        // when nothing can grow the solve throws instead of quietly
        // rendering narrower than the caller asked for.
        if ($this->targetWidth > 0) {
            $used = $borderCount;  // inter-column gaps BETWEEN VISIBLE COLUMNS
            $lastFlex = null;
            $visiblePercent = [];
            foreach ($this->columns as $i => $col) {
                if (isset($hiddenLookup[$i])) {
                    continue;
                }
                $used += $widths[$i];
                if ($col->columnWidth === ColumnWidth::Flex) {
                    $lastFlex = $i;
                } elseif ($col->columnWidth === ColumnWidth::Percent) {
                    $visiblePercent[] = $i;
                }
            }
            if ($used > $tableWidth) {
                $budget = \max(0, $tableWidth - $borderCount);
                $declared = $used - $borderCount;
                $fractions = [];
                $assigned = 0;
                foreach ($this->columns as $i => $col) {
                    if (isset($hiddenLookup[$i])) {
                        continue;
                    }
                    $exact = $widths[$i] * $budget / $declared;
                    $floor = (int) \floor($exact);
                    $widths[$i] = $floor;
                    $assigned += $floor;
                    $fractions[$i] = $exact - $floor;
                }
                \arsort($fractions);
                $leftover = $budget - $assigned;
                foreach (\array_keys($fractions) as $i) {
                    if ($leftover <= 0) {
                        break;
                    }
                    $widths[$i]++;
                    $leftover--;
                }
            } elseif ($used < $tableWidth) {
                $extra = $tableWidth - $used;
                if ($lastFlex !== null) {
                    $widths[$lastFlex] += $extra;
                } elseif ($visiblePercent !== []) {
                    $n = \count($visiblePercent);
                    $base = \intdiv($extra, $n);
                    $rem = $extra % $n;
                    foreach ($visiblePercent as $k => $i) {
                        $widths[$i] += $base + ($k < $rem ? 1 : 0);
                    }
                } else {
                    throw new \RuntimeException(
                        "Pinned width {$tableWidth} cannot be honoured: no Flex or Percent column can grow"
                        . " (visible columns and gaps total {$used} cells)"
                    );
                }
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
     * Memoized per column index (ST-06): the converge loop used to pay a
     * fresh full row scan per seed width — sixteen cold scans of identical
     * numbers.
     */
    private function contentWidthForColumn(int $colIndex): int
    {
        if (isset($this->contentWidthCache[$colIndex])) {
            return $this->contentWidthCache[$colIndex];
        }

        $col   = $this->columns[$colIndex];
        // Sanitize title and measure display width (not byte length)
        $title = Sanitize::value($col->title, false, $this->markedFrameSanitization);
        $maxLen = Width::of($title);

        foreach ($this->rows as $row) {
            $val = $row->data->get($col->key);
            if ($val === null) {
                continue;
            }
            // StyledCell measures its display value, never its SGR wrapper.
            $str = Sanitize::value(self::plainText($val), false, $this->markedFrameSanitization);
            $len = Width::of($str);
            if ($len > $maxLen) {
                $maxLen = $len;
            }
        }

        return $this->contentWidthCache[$colIndex] = $maxLen;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function View(): string
    {
        if ($this->columns === [] || $this->getVisibleColumnIndices() === []) {
            // Nothing to render: no columns defined, or every column is
            // hidden. Hiding the whole table is a legitimate state and must
            // not ask Buffer for zero width (audit sugar-table CR1 family).
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

        // Viewport virtualization " scrollY offsets into the paged view,
        // clamped to what the view can actually show: a stale deep scroll
        // must never strand the frame into an empty box (audit M9). With no
        // viewport the scroll contributes nothing (maxScrollY is 0).
        $scroll = \min($this->scrollY, $this->maxScrollY());
        if ($this->viewportHeight > 0) {
            $rows = \array_slice($rows, $scroll, $this->viewportHeight);
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
        // regardless of their wrapped height (audit #8). The wrapped lines
        // both passes would compute are memoized for this frame only
        // (audit ST-32): the height pass records each cell's renderCell()
        // output and the paint pass replays it byte-identically, halving the
        // wrap work of every multiline frame.
        $rowHeights = [];
        $cellLineMemo = new CellLineMemo();
        if ($this->multilineMode) {
            foreach ($rows as $ri => $row) {
                $rowHeights[$ri] = $this->isExpandedByRow($row)
                    ? 1
                    : $this->calculateRowHeight($row, $this->computedColumnWidths, $visibleColumnIndices, $cellLineMemo);
            }
            // Close every (column, effective width) group the height pass
            // walked — replay legality starts at complete().
            foreach ($visibleColumnIndices as $ci) {
                $groupColumn = $this->columns[$ci];
                $groupWidth = \max(1, ($this->computedColumnWidths[$ci] ?? $groupColumn->width) - 2 * $this->cellPadding);
                $cellLineMemo->complete($groupColumn, $groupWidth);
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

        // The empty state owns ONE row of its own, and it is budgeted whether
        // or not the header is shown. Previously the no-data line rendered only
        // when the header was hidden AND its row never entered bufferHeight, so
        // the one empty-state path that worked crashed (bordered wrote past a
        // too-short buffer; borderless asked Buffer for height 0) while an
        // empty table with a header rendered a blank box.
        $emptyRows = $rowCount === 0 ? 1 : 0;

        $bufferHeight = $topBorderRows + $headerRows + $totalRowHeight + $emptyRows + $footerRows + $bottomBorderRows;
        // Borderless drops the +2 left/right border columns, so the buffer is
        // exactly the content width (every line == $visibleWidth cells).
        $bufferWidth = $visibleWidth + ($this->borderless ? 0 : 2);

        // Style parses memoize for the duration of this pass only (audit M1:
        // the same SGR strings recur on every cell of every row).
        $this->styleMemo = [];

        $canvas = new RenderCanvas($bufferWidth, $bufferHeight);
        $bufferRow = 0;

        // Top border
        if (!$this->borderless) {
            $canvas = $this->fillBorderRow($canvas, $bufferRow, $visibleWidth, 'top');
            $bufferRow++;
        }

        // Header
        if ($this->showHeader) {
            $canvas = $this->fillHeaderRow($canvas, $bufferRow, $visibleWidth, $this->computedColumnWidths, $visibleColumnIndices);
            $bufferRow++;
            $canvas = $this->fillHeaderSeparatorRow($canvas, $bufferRow, $visibleWidth);
            $bufferRow++;
        }

        // Data rows. The selection index is PAGE-GLOBAL; the render loop
        // walks one page, so the highlight compares against the same domain
        // by offsetting the slot by the page start (audit #5).
        $pageOffset = $this->pageSize > 0 ? $this->page * $this->pageSize : 0;
        if ($rows === []) {
            // Render no_data message centered in the content area (header, when
            // shown, sits above it — the message is the one empty-state row the
            // height budget reserved).
            $canvas = $this->fillNoDataRow($canvas, $bufferRow, $visibleWidth);
            $bufferRow++;
        } else {
            foreach ($rows as $ri => $row) {
                // One index domain for every per-row identity decision:
                // pageOffset + slot + scroll. Selection already compared in
                // it (audit #5); the zebra stripe now rides the SAME number
                // so stripes stay with their data row across scroll and
                // page turns (audit ST-18 — slot-parity inverted the frame
                // on every keypress). styleFunc keeps the legacy page-slot
                // contract untouched.
                $absoluteIndex = $pageOffset + $ri + $scroll;
                $isSelected = ($absoluteIndex === $this->selectedIndex) && $this->selectable;
                if ($this->isExpandedByRow($row)) {
                    $canvas = $this->fillExpandedRow($canvas, $bufferRow, $row, $ri, $isSelected, $visibleWidth, $visibleColumnIndices, $absoluteIndex);
                    $bufferRow++;
                } elseif ($this->multilineMode) {
                    $canvas = $this->fillDataRowLines($canvas, $bufferRow, $row, $ri, $visibleWidth, $isSelected, $this->computedColumnWidths, $rowHeights[$ri], $visibleColumnIndices, $absoluteIndex, $cellLineMemo);
                    $bufferRow += $rowHeights[$ri];
                } else {
                    $canvas = $this->fillDataRow($canvas, $bufferRow, $row, $ri, $isSelected, $this->computedColumnWidths, $visibleColumnIndices, $absoluteIndex);
                    $bufferRow++;
                }
            }
        }

        // Footer
        if ($this->showFooter && $this->pageSize > 0) {
            $canvas = $this->fillFooterRow($canvas, $bufferRow, $visibleWidth);
            $bufferRow++;
        }

        // Bottom border
        if (!$this->borderless) {
            $canvas = $this->fillBorderRow($canvas, $bufferRow, $visibleWidth, 'bottom');
        }

        return $canvas->toBuffer();
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
     * @return array{0: RenderCanvas, 1: int} [canvas, starting content column]
     */
    private function writeLeftEdge(RenderCanvas $canvas, int $row, ?Style $style): array
    {
        if ($this->borderless) {
            return [$canvas, 0];
        }
        $canvas->put(0, $row, new Cell($this->borderLeft(), $style, null, 1));

        return [$canvas, 1];
    }

    /** Write a row's right edge at $col (no-op in borderless mode). */
    private function writeRightEdge(RenderCanvas $canvas, int $row, int $col, ?Style $style): RenderCanvas
    {
        if ($this->borderless || $col >= $canvas->width) {
            return $canvas;
        }
        $canvas->put($col, $row, new Cell($this->borderRight(), $style, null, 1));

        return $canvas;
    }

    /**
     * Write the inter-column separator at $col and return the next column.
     * Bordered: a vertical rule in the border style. Borderless: a single space
     * carrying the ROW style so a selected row's reverse highlight stays
     * continuous across the gap.
     *
     * @return array{0: RenderCanvas, 1: int} [canvas, next column]
     */
    private function writeColumnSeparator(RenderCanvas $canvas, int $row, int $col, ?Style $rowStyle, ?Style $borderStyle): array
    {
        if ($col >= $canvas->width) {
            return [$canvas, $col + 1]; // defensive: too-narrow width, nothing to draw
        }
        $cell = $this->borderless
            ? new Cell(' ', $rowStyle, null, 1)
            : new Cell($this->borderCenterV(), $borderStyle, null, 1);
        $canvas->put($col, $row, $cell);

        return [$canvas, $col + 1];
    }

    private function fillBorderRow(RenderCanvas $canvas, int $row, int $contentWidth, string $type): RenderCanvas
    {
        $style = $this->styleOrNull($this->borderStyle);
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
                $canvas->put($col, $row, new Cell($rune, $cell->style(), $cell->link(), $actualWidth));
                $col++;
            }
        }

        return $canvas;
    }

    private function fillHeaderRow(RenderCanvas $canvas, int $row, int $_contentWidth, array $computedWidths, array $visibleColumnIndices): RenderCanvas
    {
        $style = $this->styleFor($this->headerStyle);
        $sepStyle = $this->styleOrNull($this->borderStyle);
        [$canvas, $col] = $this->writeLeftEdge($canvas, $row, $style);

        $lastCi = $visibleColumnIndices === [] ? -1 : \end($visibleColumnIndices);

        // Header cells - visible columns laid out contiguously (hidden and
        // scrolled-away columns take no space, audit #4).
        foreach ($visibleColumnIndices as $ci) {
            $column = $this->columns[$ci];
            $colWidth = $computedWidths[$ci] ?? $column->width;

            // Account for cell padding in header width
            $effectiveWidth = $colWidth - (2 * $this->cellPadding);
            $effectiveWidth = \max(1, $effectiveWidth);
            $headerText = $column->renderHeader($effectiveWidth, $this->markedFrameSanitization);
            $canvas = $this->fillCellContent($canvas, $row, $col, $headerText, $colWidth, $style);
            $col += $colWidth;

            if ($ci !== $lastCi) {
                [$canvas, $col] = $this->writeColumnSeparator($canvas, $row, $col, $style, $sepStyle);
            }
        }

        return $this->writeRightEdge($canvas, $row, $col, $sepStyle);
    }

    private function fillHeaderSeparatorRow(RenderCanvas $canvas, int $row, int $contentWidth): RenderCanvas
    {
        $style = $this->styleOrNull($this->borderStyle);
        [$canvas, $col] = $this->writeLeftEdge($canvas, $row, $style);

        // Separator rule spanning the content width.
        $canvas = $this->fillCellContent($canvas, $row, $col, \str_repeat($this->borderCenterH(), $contentWidth), $contentWidth, $style);
        $col += $contentWidth;

        return $this->writeRightEdge($canvas, $row, $col, $style);
    }

    private function fillDataRow(RenderCanvas $canvas, int $row, Row $rowData, int $rowIndex, bool $isSelected, array $computedWidths, array $visibleColumnIndices, int $absoluteRowIndex): RenderCanvas
    {
        // Determine row-level style: the row's own style with the zebra
        // stripe COMPOSED underneath it (audit M5: zebra used to clobber the
        // row style wholesale, and Row::withZebra() was never consulted).
        $rowStyle = '';
        if ($rowData->style !== '') {
            $rowStyle = $rowData->style;
        }
        $rowStyle = self::mergeStyle($rowStyle, $this->zebraStyleForRow($rowData, $absoluteRowIndex));

        // Selection is the TOP tier (audit M3: it used to replace rowStyle
        // before the cell chain ran, so the winning cell style threw the
        // reverse video away — the highlight was the first style applied,
        // not the last). The row band (edges/gaps) carries row+zebra+reverse;
        // each cell composes reverse onto ITS winning style below.
        $bandStyle = $isSelected ? self::mergeStyle($rowStyle, '7') : $rowStyle;

        $style = $bandStyle !== '' ? $this->styleFor($bandStyle) : null;
        $sepStyle = $this->styleOrNull($this->borderStyle);
        [$canvas, $col] = $this->writeLeftEdge($canvas, $row, $style);

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

            // Cell-level style precedence: base < column < row < cell
            // < styleFunc, with selection reverse-video composed over the
            // winner at the end of this chain (audit M3 — the old comment
            // promised the same order but the code applied selection as a
            // row-style overwrite BEFORE the cell chain, making it the
            // LOWEST effective tier).
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
            $cellStr = self::plainText($val);

            // Sanitize before width padding / renderCell / styleFunc
            $cellStr = Sanitize::value($cellStr, false, $this->markedFrameSanitization);

            if ($this->styleFunc !== null) {
                $rawResult = ($this->styleFunc)($rowIndex, $ci, $cellStr);
                $cellStyle = $this->normalizeStyleResult($rawResult, $cellStyle);
            }

            // Selection rides on top of the winning cell style (audit M3).
            if ($isSelected) {
                $cellStyle = self::mergeStyle($cellStyle, '7');
            }

            $style = $cellStyle !== '' ? $this->styleFor($cellStyle) : null;

            // Single-line rows clamp to the cell's effective width with an
            // ellipsis (audit #7: WrapMode/ellipsis routing never reached the
            // non-multiline path, so overlong content silently overflowed).
            $effectiveWidth = $colWidth - (2 * $this->cellPadding);
            $effectiveWidth = \max(1, $effectiveWidth); // At least 1 char for content
            $clipped = Column::clipToWidth($cellStr, $effectiveWidth);
            $displayText = $column->alignLeft
                ? Width::padRight($clipped, $effectiveWidth)
                : Width::padLeft($clipped, $effectiveWidth);

            $canvas = $this->fillCellContent($canvas, $row, $col, $displayText, $colWidth, $style);
            $col += $colWidth;

            if ($ci !== $lastCi) {
                [$canvas, $col] = $this->writeColumnSeparator($canvas, $row, $col, $style, $sepStyle);
            }
        }

        return $this->writeRightEdge($canvas, $row, $col, $sepStyle);
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
    private function fillExpandedRow(RenderCanvas $canvas, int $row, Row $rowData, int $rowIndex, bool $isSelected, int $contentWidth, array $visibleColumnIndices, int $absoluteRowIndex): RenderCanvas
    {
        $rowStyle = '';
        if ($rowData->style !== '') {
            $rowStyle = $rowData->style;
        }
        $rowStyle = self::mergeStyle($rowStyle, $this->zebraStyleForRow($rowData, $absoluteRowIndex));
        if ($isSelected) {
            $rowStyle = self::mergeStyle($rowStyle, '7');
        } // reverse composes on top (audit M3)

        $style = $rowStyle !== '' ? $this->styleFor($rowStyle) : null;
        $sepStyle = $this->styleOrNull($this->borderStyle);

        $parts = [];
        foreach ($visibleColumnIndices as $ci) {
            $column = $this->columns[$ci];
            $val = $rowData->data->get($column->key);
            if ($val === null) {
                $val = $this->missingIndicator;
            }
            $text = self::plainText($val);
            $parts[] = Sanitize::value($text, false, $this->markedFrameSanitization);
        }
        $detail = Column::clipToWidth(\implode('  ', $parts), $contentWidth);

        [$canvas, $col] = $this->writeLeftEdge($canvas, $row, $style);
        $canvas = $this->fillCellContent($canvas, $row, $col, $detail, $contentWidth, $style);

        return $this->writeRightEdge($canvas, $row, $col + $contentWidth, $sepStyle);
    }

    /**
     * Calculate the height of a row in lines based on cell content after wrapping.
     *
     * When multilineMode is enabled, row height equals the maximum number of
     * lines across all visible cells. This accounts for text wrapping in each cell.
     */
    private function calculateRowHeight(Row $row, array $computedWidths, array $visibleColumnIndices, CellLineMemo $memo): int
    {
        $maxLines = 1;

        // ST-63: walk the visible list directly — the old columns-scan with a
        // per-column in_array membership test re-checked every hidden column
        // on every row. Same visit order as the paint path, which the
        // ST-32 replay pairing depends on.
        foreach ($visibleColumnIndices as $ci) {
            $column = $this->columns[$ci];
            $colWidth = $computedWidths[$ci] ?? $column->width;
            $effectiveWidth = $colWidth - (2 * $this->cellPadding);
            $effectiveWidth = \max(1, $effectiveWidth);
            $cellStr = self::plainText($row->data->get($column->key));
            // Sanitize before renderCell width measurement
            $cellStr = Sanitize::value($cellStr, true, $this->markedFrameSanitization);

            $lines = $column->renderCell($cellStr, $effectiveWidth, $this->markedFrameSanitization);
            $memo->store($column, $effectiveWidth, $lines);
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
    private function fillDataRowLines(RenderCanvas $canvas, int $startRow, Row $row, int $rowIndex, int $_contentWidth, bool $isSelected, array $computedWidths, int $rowHeight, array $visibleColumnIndices, int $absoluteRowIndex, CellLineMemo $cellLineMemo): RenderCanvas
    {
        // Determine row-level style: compose row + zebra stripe, with the
        // selection reverse video on top of the band (audit M3/M5 — both
        // used to REPLACE the row style instead of composing).
        $rowStyle = '';
        if ($row->style !== '') {
            $rowStyle = $row->style;
        }
        $rowStyle = self::mergeStyle($rowStyle, $this->zebraStyleForRow($row, $absoluteRowIndex));

        $bandStyle = $isSelected ? self::mergeStyle($rowStyle, '7') : $rowStyle;

        $style = $bandStyle !== '' ? $this->styleFor($bandStyle) : null;
        $sepStyle = $this->styleOrNull($this->borderStyle);
        // ST-67: the rightmost visible column is fixed for the whole row —
        // resolve it once instead of re-ending() the index list per line.
        $lastCi = $visibleColumnIndices === [] ? -1 : \end($visibleColumnIndices);

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

            // Cell-level style precedence: base < column < row < cell
            // < styleFunc, selection composed over the winner (audit M3).
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

            $cellStr = self::plainText($val);

            // Sanitize before styleFunc (multiline context: preserve \n for explode)
            $cellStr = Sanitize::value($cellStr, true, $this->markedFrameSanitization);

            if ($this->styleFunc !== null) {
                $rawResult = ($this->styleFunc)($rowIndex, $ci, $cellStr);
                $cellStyle = $this->normalizeStyleResult($rawResult, $cellStyle);
            }

            // Selection rides on top of the winning cell style (audit M3).
            if ($isSelected) {
                $cellStyle = self::mergeStyle($cellStyle, '7');
            }

            $parsedStyle = $cellStyle !== '' ? $this->styleFor($cellStyle) : null;

            // Account for cell padding in content width. Expanded rows never
            // reach here — renderToBuffer routes them to fillExpandedRow.
            $effectiveWidth = $colWidth - (2 * $this->cellPadding);
            $effectiveWidth = \max(1, $effectiveWidth);
            // ST-32: the height pass already wrapped this cell with the same
            // sanitized text and width; replay its lines byte-identically.
            // The fresh renderCell() stays as the structural fallback for a
            // group the height pass did not record (never in a symmetric
            // frame, but the paint must never depend on memo luck).
            $lines = $cellLineMemo->replayNext($column, $effectiveWidth)
                ?? $column->renderCell($cellStr, $effectiveWidth, $this->markedFrameSanitization);
            $cellLines[$ci] = ['lines' => $lines, 'style' => $parsedStyle, 'width' => $colWidth];
        }

        // Render each line of the row
        for ($lineIdx = 0; $lineIdx < $rowHeight; $lineIdx++) {
            $bufferRow = $startRow + $lineIdx;

            // Left border (full height)
            [$canvas, $col] = $this->writeLeftEdge($canvas, $bufferRow, $style);

            // Render each visible cell, laid out contiguously (audit #4).
            // $lastCi is resolved once per row above (ST-67).
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

                $canvas = $this->fillCellContent($canvas, $bufferRow, $col, $displayText, $colWidth, $cellStyle);
                $col += $colWidth;

                if ($ci !== $lastCi) {
                    [$canvas, $col] = $this->writeColumnSeparator($canvas, $bufferRow, $col, $style, $sepStyle);
                }
            }

            // Right border (full height)
            $canvas = $this->writeRightEdge($canvas, $bufferRow, $col, $sepStyle);
        }

        return $canvas;
    }

    private function fillFooterRow(RenderCanvas $canvas, int $row, int $contentWidth): RenderCanvas
    {
        $style = $this->styleFor($this->footerStyle);

        $label = match ($this->footerType) {
            FooterType::Page => $this->PageFooter(),
            FooterType::Rows => $this->RowsFooter(),
            FooterType::Both => $this->PageFooter() . '  |  ' . $this->RowsFooter(),
        };

        $content = $this->centeredLabel($label, $contentWidth);

        [$canvas, $col] = $this->writeLeftEdge($canvas, $row, $style);
        $canvas = $this->fillCellContent($canvas, $row, $col, $content, $contentWidth, $style);
        $col += $contentWidth;

        return $this->writeRightEdge($canvas, $row, $col, $style);
    }

    /**
     * Center one label in a display-width budget (floor bias to the left),
     * truncating with sugar-core Width first when it overflows. Shared by the
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
    private function fillNoDataRow(RenderCanvas $canvas, int $row, int $contentWidth): RenderCanvas
    {
        $style = $this->styleFor($this->footerStyle);
        // Two empty states are one pixel-identical line without this branch:
        // an empty store and a filter/search that narrowed every row away
        // (audit ST-21, finding 021). The filter/search state tells the host
        // the rows exist — the needle is what excludes them.
        $label = ($this->filterText !== [] || $this->searchText !== '')
            ? Lang::t('no_matches')
            : Lang::t('no_data');
        $content = $this->centeredLabel($label, $contentWidth);

        [$canvas, $col] = $this->writeLeftEdge($canvas, $row, $style);
        $canvas = $this->fillCellContent($canvas, $row, $col, $content, $contentWidth, $style);
        $col += $contentWidth;

        return $this->writeRightEdge($canvas, $row, $col, $style);
    }

    /**
     * Fill a region of the buffer with cell content, handling wide characters.
     */
    private function fillCellContent(RenderCanvas $canvas, int $row, int $startCol, string $text, int $cellWidth, ?Style $style): RenderCanvas
    {
        $clusters = $this->graphemeClusters($text);
        $bufWidth = $canvas->width;
        $col = $startCol;
        // Origin-level record of the last placed cell (slot, full rune,
        // span): a zero-width cluster rides this cell — which after ST-07
        // may be a batched run — exactly as it rode the last per-cluster
        // cell before batching.
        $lastOrigin = -1;
        $lastRune = '';
        $lastN = 1;
        // Audit ST-07: contiguous single-width ASCII runs (cell bodies and
        // the padding tail) are batched into ONE multi-column Cell, the
        // fillBorderRow precedent, instead of one Cell + put per cluster.
        // Any wide, zero-width, or non-ASCII cluster flushes the batch first,
        // so the per-cluster path below keeps its exact semantics. Invariant:
        // a batched run never crosses the cell budget or the buffer edge, so
        // flushing always lands; the run's interior slots are written as
        // width-0 empties (the render pass skips those), byte-identical to
        // the per-cluster fill the run replaces.
        $runStr = '';
        $runOrigin = -1;

        $flushRun = function () use (&$runStr, &$runOrigin, $canvas, $row, $style, &$col, &$lastOrigin, &$lastRune, &$lastN): void {
            if ($runOrigin === -1) {
                return;
            }
            $n = \strlen($runStr); // ASCII fast path: bytes == clusters == columns
            $canvas->put($runOrigin, $row, new Cell($runStr, $style, null, $n));
            for ($c = 1; $c < $n; $c++) {
                // Interior slots carry styled width-0 empties — the exact
                // fillBorderRow pattern. (Canvas blanks are NORMAL width-1
                // cells that the render pass emits as a space; only the
                // width-0 skip removes a slot from the output.)
                $canvas->put($runOrigin + $c, $row, new Cell('', $style, null, 0));
            }
            $lastOrigin = $runOrigin;
            $lastRune = $runStr;
            $lastN = $n;
            $col = $runOrigin + $n;
            $runStr = '';
            $runOrigin = -1;
        };

        foreach ($clusters as $cluster) {
            // ASCII fast path: printable single-width bytes batch together.
            // The bounds mirror the per-cluster guards the batched run stands
            // in for: cell budget (startCol + cellWidth) and buffer edge.
            if (\strlen($cluster) === 1 && $cluster >= ' ' && $cluster <= '~'
                && ($col + \strlen($runStr)) < $startCol + $cellWidth
                && ($col + \strlen($runStr)) < $bufWidth
            ) {
                if ($runOrigin === -1) {
                    $runOrigin = $col;
                }
                $runStr .= $cluster;
                continue;
            }

            $flushRun();

            if ($col >= $bufWidth) {
                break; // never write past the buffer (defensive: too-narrow width)
            }
            // Sugar-core Width is the single EAW oracle for the whole monorepo
            // (audit finding #10 — this file used to carry its own codepoint
            // range tables, which silently drifted from the canonical one).
            // A zero-width cluster (combining mark, ZWJ, variation selector)
            // occupies no slot of its own: it is appended to the cell it
            // follows instead of being inflated to a full cell, which keeps
            // the buffer's cell count in lockstep with the Width::of totals
            // that sized the column.
            $gw = Width::of($cluster);
            if ($gw === 0) {
                if ($lastOrigin >= 0) {
                    $lastRune .= $cluster;
                    $canvas->put($lastOrigin, $row, new Cell($lastRune, $style, null, $lastN));
                }
                continue;
            }

            // Audit ST-17: a cluster that cannot fit the remaining cell space
            // used to be clamped to it and placed anyway — the glyph painted
            // half off-canvas (a 10-cell line on a 9-cell frame) and the
            // buffer's cell count desynced from the frame that sized it.
            // The cluster is atomic, so the fit arm is the announcing '…':
            // visible mark when there is a slot for it, then the fill stops
            // and the space-padding below completes the frame.
            // Audit ST-58: the fit budget is the tighter of the cell space
            // and the BUFFER space — a width-2 cluster whose second slot
            // would fall past the right edge used to be stored as a width-2
            // cell with its continuation silently skipped, overrunning the
            // visual line by a column (borderless final-column case; framed
            // paths keep borders inside the buffer, so they cannot reach it).
            $remaining = \min($cellWidth - ($col - $startCol), $bufWidth - $col);
            if ($gw > $remaining || $remaining <= 0) {
                if ($gw > $remaining && $remaining >= 1) {
                    $canvas->put($col, $row, new Cell('…', $style, null, 1));
                    $col++;
                }
                break;
            }

            $canvas->put($col, $row, new Cell($cluster, $style, null, $gw));
            $lastOrigin = $col;
            $lastRune = $cluster;
            $lastN = $gw;

            // A wide grapheme occupies two slots: the display-width-2 cell at $col
            // plus a continuation marker at $col + 1 (NOT $col + 2 — advancing by
            // $gw already covers both slots).
            if ($gw === 2 && $col + 1 < $bufWidth) {
                $canvas->put($col + 1, $row, Cell::continuation());
            }
            $col += $gw;
        }

        // Flush before padding: a run that ends exactly at the cell budget
        // must not absorb the tail — the old code's padding cells always
        // started their own slot run, and per-cell probes read that shape.
        $flushRun();

        // Fill remaining width with spaces if needed (clamped to the buffer),
        // as one batched run Cell (ST-07): the spaces live in the origin
        // cell's own rune string, so the emitted bytes match the per-space
        // fill exactly — the interior slots become width-0 empties.
        $pad = $col < $bufWidth ? \min($startCol + $cellWidth, $bufWidth) - $col : 0;
        if ($pad > 0) {
            $canvas->put($col, $row, new Cell(\str_repeat(' ', $pad), $style, null, $pad));
            for ($c = 1; $c < $pad; $c++) {
                $canvas->put($col + $c, $row, new Cell('', $style, null, 0));
            }
        }

        return $canvas;
    }

    /**
     * Parse an SGR string once per render pass (audit M1: data rows repeat
     * the handful of configured style strings across every cell).
     */
    private function styleFor(string $codes): Style
    {
        return $this->styleMemo[$codes] ??= $this->parseAnsiToStyle($codes);
    }

    /** Memoized style parse where the empty SGR string means 'no style'. */
    private function styleOrNull(string $codes): ?Style
    {
        return $codes === '' ? null : $this->styleFor($codes);
    }

    /**
     * Normalize a styleFunc result to an ANSI SGR string: a CellBuffer Style
     * is converted via styleToAnsi(), a string passes through verbatim, and
     * anything else (including a SugarCraft\Style\Style object, which has no
     * conversion path here) falls back to the inherited style.
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
     * The plain display text of a raw cell value — the same reading rules
     * the render path uses (see the class docblock): a StyledCell matches
     * and sorts by its value, never by its SGR wrapper; anything without a
     * safe string reading contributes empty text instead of throwing.
     */
    private static function plainText(mixed $val): string
    {
        if ($val instanceof StyledCell) {
            $val = $val->value;
        }

        return self::scalarText($val);
    }

    /**
     * Stringify one value with the guarded reading rules; no throwing on
     * non-scalar input.
     */
    private static function scalarText(mixed $val): string
    {
        if (\is_object($val) && \method_exists($val, '__toString')) {
            return (string) $val;
        }

        return \is_scalar($val) ? (string) $val : '';
    }

    /**
     * Compose two SGR strings so both apply, the later code winning at the
     * terminal (SGR codes accumulate left-to-right). Empty sides collapse.
     */
    private static function mergeStyle(string $under, string $over): string
    {
        if ($under === '') {
            return $over;
        }
        if ($over === '') {
            return $under;
        }

        return $under . ';' . $over;
    }

    /**
     * Resolve the zebra stripe for one row (audit M5: {@see Row::withZebra()}
     * used to be stored and never read — rows that opted in with an explicit
     * stripe index now win over positional parity).
     *
     * $rowIndex is the ABSOLUTE data index (page offset + window slot), the
     * same domain the selection highlight compares in (audit ST-18): a
     * scroll step shifts the stripe window by one row instead of flipping
     * every visible row's parity through the frame slot.
     */
    private function zebraStyleForRow(Row $rowData, int $rowIndex): string
    {
        if (!$this->zebraEnabled) {
            return '';
        }
        if ($rowData->zebra) {
            return $rowData->zebraStyleIndex === 1 ? $this->zebraStyleOdd : $this->zebraStyleEven;
        }

        return ($rowIndex % 2 === 0) ? $this->zebraStyleEven : $this->zebraStyleOdd;
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
     * packed 24-bit RGB int. The triples come from sugar-core's canonical
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
        return Column::splitClusters($text);
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

        // Borders: one char between each VISIBLE column (ST-11 basis).
        $hiddenLookup = \array_flip($this->hiddenCols);
        $visibleCount = 0;
        foreach ($this->columns as $i => $col) {
            if (!isset($hiddenLookup[$i])) {
                $visibleCount++;
            }
        }
        $borderCount = \max(0, $visibleCount - 1);

        // Seed the solve with the raw declared widths of the VISIBLE columns,
        // plus inter-column gaps.
        $width = $borderCount;
        foreach ($this->columns as $i => $col) {
            if (!isset($hiddenLookup[$i])) {
                $width += $col->width;
            }
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
