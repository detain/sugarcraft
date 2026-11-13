<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Table;

use SugarCraft\Widgets\Lang;
use SugarCraft\Widgets\Paginator\Paginator;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Selectable, scrollable data table.
 *
 * Distinct from {@see \SugarCraft\Style\Table\Table}, which is a static
 * styled renderer. This component holds a moving selection cursor,
 * scrolls vertically when the row count exceeds {@see $height}, and
 * draws an underlined header.
 *
 * Column widths are computed from header + cell widths and capped at
 * {@see $width} (when > 0) by truncating individual cells.
 */
final class Table implements Model
{
    /**
     * Row index passed to a {@see styleFunc()} callback when rendering
     * the header row. Mirrors lipgloss / sugar-style `HEADER_ROW`.
     */
    public const HEADER_ROW = -1;

    private function __construct(
        /** @var list<string> */ public readonly array $headers,
        /** @var list<list<string>> */ public readonly array $rows,
        public readonly int $cursor,
        public readonly int $offset,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $focused,
        /** @var list<int> per-column explicit widths (0 = auto). Aligned to $headers index. */
        public readonly array $colWidths = [],
        public readonly ?Styles $styles = null,
        /** @var ?\Closure(int $row, int $col): \SugarCraft\Style\Style */
        public readonly ?\Closure $styleFunc = null,
        public readonly ?SortState $sortState = null,
        public readonly bool $filterable = false,
        public readonly string $filter = '',
        /** @var ?\Closure(list<string> $row): bool */
        public readonly ?\Closure $filterPredicate = null,
        /** @var int Rows per page; 0 means pagination is disabled. */
        public readonly int $pageSize = 0,
        /** @var int Current 0-indexed page when pagination is active. */
        public readonly int $currentPage = 0,
    ) {}

    /**
     * Memoized visibleRows() projection (sorted → filtered) for this
     * immutable instance — E736/3.3. view(), selectedRow(), moveCursor()
     * rebuild the projection through this cache so one render cycle does the
     * sort+filter once; paginator() still rebuilds it inline (not
     * memo-served - r86 weld docblock truth; routing it would be behavior change). Every state
     * change flows through mutate(), which constructs a fresh instance
     * with a null cache, so the projection can never go stale.
     *
     * @var null|list<list<string>>
     */
    private ?array $visibleRowsCache = null;

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function new(array $headers = [], array $rows = [], int $width = 0, int $height = 10): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('table.dim_nonneg'));
        }
        return new self(
            headers:   array_values($headers),
            rows:      array_values(array_map('array_values', $rows)),
            cursor:    0,
            offset:    0,
            width:     $width,
            height:    $height,
            focused:   false,
            colWidths: [],
            sortState: SortState::empty(),
        );
    }

    /**
     * Replace headers + per-column explicit widths in one call. Each
     * `Column` carries a title and an optional fixed width; passing
     * `width=0` lets the column auto-size. Mirrors Bubbles' `WithColumns`.
     *
     * @param list<Column> $columns
     */
    public function withColumns(array $columns): self
    {
        $titles = [];
        $widths = [];
        foreach ($columns as $col) {
            if (!$col instanceof Column) {
                throw new \InvalidArgumentException(Lang::t('table.set_columns_type'));
            }
            $titles[] = $col->title;
            $widths[] = $col->width;
        }
        return $this->mutate(headers: $titles, colWidths: $widths)
            ->reconcileSort(count($titles))
            ->reclamp();
    }

    /**
     * @deprecated The `set`-prefixed name suggests mutation; this class is
     *     immutable and returns a new instance. Use {@see withColumns()}.
     *     Kept as a BC alias for external callers. Removal at 2.0.
     */
    public function setColumns(array $columns): self
    {
        return $this->withColumns($columns);
    }

    /** @return list<Column> */
    public function columns(): array
    {
        $out = [];
        foreach ($this->headers as $i => $title) {
            $out[] = new Column($title, $this->colWidths[$i] ?? 0);
        }
        return $out;
    }

    /** Bubble-Tea Init — returns the bootstrap Cmd (cursor blink, first tick, etc.) or null. */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!($msg instanceof KeyMsg) || !$this->focused) {
            return [$this, null];
        }
        return match (true) {
            $msg->type === KeyType::Up
                || ($msg->type === KeyType::Char && $msg->rune === 'k')
                => [$this->moveCursor($this->cursor - 1), null],
            $msg->type === KeyType::Down
                || ($msg->type === KeyType::Char && $msg->rune === 'j')
                => [$this->moveCursor($this->cursor + 1), null],
            $msg->type === KeyType::Home
                || ($msg->type === KeyType::Char && $msg->rune === 'g')
                => [$this->moveCursor(0), null],
            $msg->type === KeyType::End
                || ($msg->type === KeyType::Char && $msg->rune === 'G')
                => [$this->moveCursor(PHP_INT_MAX), null],
            $msg->type === KeyType::PageUp
                => [$this->moveCursor($this->cursor - max(1, $this->height)), null],
            $msg->type === KeyType::PageDown
                => [$this->moveCursor($this->cursor + max(1, $this->height)), null],
            default => [$this, null],
        };
    }

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        $cols = $this->columnWidths();
        if ($cols === []) {
            return '';
        }

        $lines = [];
        // C4: with a styleFunc set, every cell closes its own SGR with a
        // reset, so a whole-row wrap applied after renderRow is wiped from
        // the first cell on. The row style is instead composed INTO each
        // cell (renderRow's $rowStyle). Without a styleFunc the old
        // whole-row wrap stays byte-for-byte as before.
        $composed = $this->styleFunc !== null;
        if ($this->headers !== []) {
            // M5: sorted columns carry their direction in the header cell
            // (displayHeaders), measured through the same width budget.
            $headerRow = $this->renderRow($this->displayHeaders(), $cols, self::HEADER_ROW, $composed ? $this->headerStyle() : null);
            $lines[]   = $composed
                ? $headerRow
                : ($this->styles !== null
                    ? $this->styles->header->render($headerRow)
                    : Ansi::sgr(Ansi::UNDERLINE) . $headerRow . Ansi::reset());
        }

        $top    = max(0, $this->offset);
        // E736/3.3: go through the memoized projection instead of
        // re-running sort+filter inline — same result, computed once.
        $filteredRows = $this->visibleRows();
        // When pagination is active, derive the window offset and size from
        // the current page rather than the scroll offset. The PAINTED window
        // never exceeds a positive host height — a pageSize larger than
        // height used to pour rows past the frame the caller allocated
        // (height 0 is the auto-unbounded case, where pageSize rules) —
        // while the page ADVANCE stays pageSize-sized so navigation
        // semantics are unchanged.
        if ($this->pageSize > 0) {
            $top  = $this->currentPage * $this->pageSize;
            $windowSize = $this->height > 0
                ? min($this->pageSize, $this->height)
                : $this->pageSize;
        } else {
            $windowSize = $this->height;
        }
        $window = array_slice($filteredRows, $top, $windowSize);
        foreach ($window as $i => $row) {
            $idx = $top + $i;
            $isSelected = $idx === $this->cursor && $this->focused;
            if ($composed) {
                $rowStyle = $isSelected
                    ? ($this->styles !== null ? $this->styles->selected : Style::new()->reverse())
                    : ($this->styles !== null ? $this->styles->cell : null);
                $lines[]  = $this->renderRow($row, $cols, $idx, $rowStyle);
                continue;
            }
            $line = $this->renderRow($row, $cols, $idx);
            if ($this->styles !== null) {
                $line = $isSelected
                    ? $this->styles->selected->render($line)
                    : $this->styles->cell->render($line);
            } elseif ($isSelected) {
                $line = Ansi::sgr(Ansi::REVERSE) . $line . Ansi::reset();
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /** The row-level style the header line composes under (C4 path). */
    private function headerStyle(): Style
    {
        return $this->styles !== null ? $this->styles->header : Style::new()->underline();
    }

    /**
     * Column index → direction glyph for every sorted column (M5).
     *
     * Upstream Bubbles renders sorted headers through a caller-supplied
     * function; SugarCraft styles are whole-row value objects with no
     * per-column channel, so the indicator rides the header TEXT itself —
     * the same place bubbles-table's default theme puts it. The FIRST
     * criterion for a column wins the glyph.
     *
     * @return array<int, string>
     */
    private function sortMarkers(): array
    {
        if ($this->sortState === null || $this->sortState->isEmpty()) {
            return [];
        }
        $markers = [];
        foreach ($this->sortState->criteria as [$col, $direction]) {
            if ($col < count($this->headers)) {
                $markers[$col] ??= $direction->marker();
            }
        }
        return $markers;
    }

    /**
     * Headers with sort-direction glyphs appended — used for BOTH width
     * measurement and rendering so the indicator never overflows its cell
     * in auto-width tables. With explicit widths from setColumns() the
     * per-cell budget is fixed and a marker can be clipped; the indicator
     * is then the truncated tail, never a layout break.
     *
     * @return list<string>
     */
    private function displayHeaders(): array
    {
        $markers = $this->sortMarkers();
        if ($markers === []) {
            return $this->headers;
        }
        $out = [];
        foreach ($this->headers as $i => $header) {
            $out[] = isset($markers[$i]) ? $header . ' ' . $markers[$i] : $header;
        }
        return $out;
    }

    /** Read-only accessor for the rows. Mirrors Bubbles' `Rows()`. */
    public function rowsList(): array { return $this->rows; }

    /** Read-only accessor for the headers. */
    public function headersList(): array { return $this->headers; }

    /**
     * Cursor position (0-indexed) within the visible (sorted → filtered)
     * row projection. Mirrors Bubbles' `Cursor()`.
     */
    public function cursor(): int { return $this->cursor; }

    /** Move the cursor to a specific row, clamped. */
    public function withCursor(int $row): self { return $this->moveCursor($row); }

    /**
     * @deprecated The `set`-prefixed name suggests mutation; this class is
     *     immutable and returns a new instance. Use {@see withCursor()}.
     *     Kept as a BC alias for external callers. Removal at 2.0.
     */
    public function setCursor(int $row): self { return $this->withCursor($row); }

    /**
     * Apply the supplied {@see Styles} to header / cell / selected
     * rendering. Pass null to fall back to the default reverse-video
     * highlight + underlined header. Mirrors Bubbles' `SetStyles`.
     */
    public function withStyles(?Styles $styles): self
    {
        return $this->mutate(styles: $styles, stylesSet: true);
    }

    public function styles(): ?Styles { return $this->styles; }

    /**
     * Per-cell styling callback. The closure receives `(int $row, int $col)`
     * and returns the {@see \SugarCraft\Style\Style} to apply to that
     * cell. The header row is reported as `$row === Table::HEADER_ROW`.
     *
     * The styleFunc runs *per cell*, before the row-level Styles
     * (header / cell / selected) — so you can do striped rows or
     * conditional highlighting per column without losing the
     * selected-row inversion.
     *
     * Pass null to clear the override.
     *
     * Mirrors charmbracelet/bubbles #246 (long-requested upstream
     * feature) and lipgloss / sugar-style `Table::styleFunc()`.
     *
     * @param ?\Closure(int $row, int $col): \SugarCraft\Style\Style $fn
     */
    public function styleFunc(?\Closure $fn): self
    {
        return $this->mutate(styleFunc: $fn, styleFuncSet: true);
    }

    /**
     * Accessor for the styling callback. The bare name `styleFunc()` is taken
     * by the fluent setter above (AGENTS factory/setter convention), so this
     * getter keeps the `get` prefix to avoid a signature collision.
     */
    public function getStyleFunc(): ?\Closure { return $this->styleFunc; }

    /** @return list<string> */
    public function selectedRow(): array
    {
        return $this->visibleRows()[$this->cursor] ?? [];
    }

    /**
     * Cursor position (0-indexed) within the visible (sorted → filtered)
     * row projection. Matches what `view()` highlights and what
     * `selectedRow()` returns.
     */
    public function index(): int
    {
        return $this->cursor;
    }

    /** Take focus; companion to {@see blur()} — a pure value verb. */
    public function focus(): self
    {
        return $this->mutate(focused: true);
    }

    /** Release focus; companion to {@see focus()}. */
    public function blur(): self
    {
        return $this->mutate(focused: false);
    }

    /** @param list<list<string>> $rows */
    public function withRows(array $rows): self
    {
        return $this->mutate(rows: array_values(array_map('array_values', $rows)))->reclamp();
    }

    /**
     * @deprecated The `set`-prefixed name suggests mutation; this class is
     *     immutable and returns a new instance. Use {@see withRows()}.
     *     Kept as a BC alias for external callers. Removal at 2.0.
     */
    public function setRows(array $rows): self
    {
        return $this->withRows($rows);
    }

    /** @param list<string> $headers */
    public function withHeaders(array $headers): self
    {
        $headers = array_values($headers);
        return $this->mutate(headers: $headers)
            ->reconcileSort(count($headers))
            ->reclamp();
    }

    /**
     * @deprecated The `set`-prefixed name suggests mutation; this class is
     *     immutable and returns a new instance. Use {@see withHeaders()}.
     *     Kept as a BC alias for external callers. Removal at 2.0.
     */
    public function setHeaders(array $headers): self
    {
        return $this->withHeaders($headers);
    }

    /**
     * Host frame in cells. A negative dimension throws; 0 is the
     * per-component sentinel documented in the README dimension table.
     * When {@see withPageSize()} is also active, the painted window
     * clamps to this height (pageSize never overflows the frame).
     */
    public function withSize(int $width, int $height): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('table.dim_nonneg'));
        }
        return $this->mutate(width: $width, height: $height)->reclamp();
    }

    /**
     * @deprecated The `set`-prefixed name suggests mutation; this class is
     *     immutable and returns a new instance. Use {@see withSize()}.
     *     Kept as a BC alias for external callers. Removal at 2.0.
     */
    public function setSize(int $width, int $height): self
    {
        return $this->withSize($width, $height);
    }

    /** Move the cursor to row 0. Mirrors Bubbles' `GotoTop`. */
    public function gotoTop(): self    { return $this->moveCursor(0); }
    /** Move the cursor to the last row. Mirrors `GotoBottom`. */
    public function gotoBottom(): self { return $this->moveCursor(PHP_INT_MAX); }
    /** Move cursor up `$n` rows. Default 1. */
    public function moveUp(int $n = 1): self   { return $this->moveCursor($this->cursor - max(1, $n)); }
    /** Move cursor down `$n` rows. */
    public function moveDown(int $n = 1): self { return $this->moveCursor($this->cursor + max(1, $n)); }

    /**
     * Set the primary sort column and direction. Clears any prior sort chain.
     */
    public function withSort(string $column, SortDirection $dir = SortDirection::Asc): self
    {
        $colIndex = $this->resolveColumnIndex($column);
        $state = SortState::empty()->withCriterion($colIndex, $dir);
        return $this->mutate(sortState: $state, sortStateSet: true);
    }

    /**
     * Add a secondary (or further) sort criterion. The chain is applied
     * in order — first `withSort()` is primary, each `thenSortBy()` is a
     * tiebreaker.
     */
    public function thenSortBy(string $column, SortDirection $dir = SortDirection::Asc): self
    {
        $colIndex = $this->resolveColumnIndex($column);
        $state = $this->sortState ?? SortState::empty();
        return $this->mutate(sortState: $state->withCriterion($colIndex, $dir), sortStateSet: true);
    }

    /** Remove all sort criteria, restoring insertion order. */
    public function clearSort(): self
    {
        return $this->mutate(sortState: SortState::empty(), sortStateSet: true);
    }

    /**
     * Enable or disable filtering. When enabled and {@see $filter} is
     * non-empty, rows that don't match are hidden from the view.
     */
    public function withFilterable(bool $filterable): self
    {
        return $this->mutate(filterable: $filterable, filterableSet: true);
    }

    /** @return bool */
    public function filterable(): bool
    {
        return $this->filterable;
    }

    /**
     * Set the filter query string. A non-empty string enables implicit
     * case-insensitive substring matching across all visible columns.
     * Use {@see withFilterPredicate()} to override with a custom callable.
     */
    public function withFilter(string $query): self
    {
        // The projection shrinks (or grows) — the cursor/page must follow
        // now, not at first render (M1).
        return $this->mutate(filter: $query, filterSet: true)->reclamp();
    }

    /** @return string */
    public function filter(): string
    {
        return $this->filter;
    }

    /**
     * Provide a custom filter predicate. The callable receives a row
     * (list<string>) and returns true to keep the row or false to hide it.
     * Overrides the default substring-match behaviour when set.
     * Pass null to restore the default.
     *
     * @param ?\Closure(list<string> $row): bool $predicate
     */
    public function withFilterPredicate(?\Closure $predicate): self
    {
        // Same projection-shrink reconciliation as withFilter (M1).
        return $this->mutate(filterPredicate: $predicate, filterPredicateSet: true)->reclamp();
    }

    /** @return ?\Closure(list<string> $row): bool */
    public function filterPredicate(): ?\Closure
    {
        return $this->filterPredicate;
    }

    /**
     * Set the number of rows displayed per page. When > 0, the table
     * is paginated and only rows for the current page are visible.
     * When 0 (the default), all rows are shown (no pagination).
     * The painted window never exceeds the height set via
     * {@see withSize()} — with both set, effectively
     * `min(pageSize, height)` rows render (page advance stays
     * pageSize-sized); height 0 means auto/unbounded, so pageSize rules.
     */
    public function withPageSize(int $size): self
    {
        if ($size < 0) {
            // m11: this guards the PAGE SIZE, not a table dimension — the
            // old shared message blamed "table width/height" for a bad
            // pageSize argument.
            throw new \InvalidArgumentException(Lang::t('table.page_size_nonneg'));
        }
        // M1: shrinking the page window can strand the cursor (and the page)
        // beyond the last row — re-run the reconciliation.
        return $this->mutate(pageSize: $size, pageSizeSet: true)->reclamp();
    }

    /** @return int */
    public function pageSize(): int
    {
        return $this->pageSize;
    }

    /** @return int */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Return a {@see Paginator} initialized with the current row state.
     * The Paginator can drive page navigation; call {@see withPage()}
     * to apply a new page to the table.
     */
    public function paginator(): Paginator
    {
        $sortedRows   = $this->sortedRows();
        $filteredRows = $this->filteredRows($sortedRows);
        $total        = count($filteredRows);
        $pageSize     = $this->pageSize > 0 ? $this->pageSize : $total;

        return Paginator::new()
            ->withPerPage($pageSize)
            ->withTotalItems($total)
            ->withPage($this->currentPage);
    }

    /**
     * Return a new Table with a different active page. The page is clamped
     * to the valid range so this method is always safe to call.
     */
    public function withPage(int $page): self
    {
        $paginator = $this->paginator()->withPage($page);
        $newPage   = $paginator->page;
        if ($newPage === $this->currentPage) {
            return $this;
        }
        return $this->mutate(currentPage: $newPage, currentPageSet: true)
            ->reclampCursorForPage($paginator);
    }

    /**
     * Move the cursor to the FIRST row of the visible list (m15: the old
     * body jumped to page 0 while the docblock claimed "first row of the
     * current page" — one of the two had to be wrong; upstream Bubbles
     * `GotoStart` means start-of-list, so the docblock was). With a page
     * window set, the cursor's page follows automatically (M2).
     */
    public function pageFirst(): self
    {
        return $this->moveCursor(0);
    }

    /** Move the cursor to the LAST row of the visible list (`GotoEnd`). */
    public function pageLast(): self
    {
        return $this->moveCursor(PHP_INT_MAX);
    }

    /**
     * Advance to the next page. No-op when already on the last page
     * or when pagination is disabled.
     */
    public function nextPage(): self
    {
        if ($this->pageSize <= 0) {
            return $this;
        }
        return $this->withPage($this->currentPage + 1);
    }

    /**
     * Go to the previous page. No-op when already on the first page
     * or when pagination is disabled.
     */
    public function prevPage(): self
    {
        if ($this->pageSize <= 0) {
            return $this;
        }
        return $this->withPage($this->currentPage - 1);
    }

    /** @return SortState */
    public function sortState(): SortState
    {
        return $this->sortState ?? SortState::empty();
    }

    // ---- internals ---------------------------------------------------

    /**
     * Resolve a column name to its index. Throws if the column is not found.
     */
    private function resolveColumnIndex(string $column): int
    {
        $idx = array_search($column, $this->headers, true);
        if ($idx === false) {
            throw new \InvalidArgumentException(Lang::t('table.sort_unknown_column', ['column' => $column]));
        }
        return (int) $idx;
    }

    /**
     * Return the rows sorted according to the current sortState chain.
     * Returns the original rows array when no sort criteria are set.
     *
     * @return list<list<string>>
     */
    private function sortedRows(): array
    {
        $state = $this->sortState ?? SortState::empty();
        if ($state->isEmpty()) {
            return $this->rows;
        }

        // Single-pass usort: comparator evaluates all criteria in priority order,
        // returning immediately on first non-zero comparison.
        // Work on a mutable copy — $this->rows is readonly.
        $rows = $this->rows;
        $criteria = $state->criteria;
        \usort(
            $rows,
            static function (array $a, array $b) use ($criteria): int {
                foreach ($criteria as [$col, $dir]) {
                    $va = $a[$col] ?? '';
                    $vb = $b[$col] ?? '';
                    $cmp = strnatcasecmp((string) $va, (string) $vb);
                    if ($cmp !== 0) {
                        return $dir === SortDirection::Desc ? -$cmp : $cmp;
                    }
                }
                return 0;
            }
        );
        return $rows;
    }

    /**
     * Return rows filtered according to the current filter settings.
     * When filterable is true and filter (or filterPredicate) is set,
     * applies the predicate to each row.
     *
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private function filteredRows(array $rows): array
    {
        if (!$this->filterable) {
            return $rows;
        }

        $predicate = $this->filterPredicate;
        if ($predicate !== null) {
            return array_values(array_filter($rows, static fn(array $row): bool => $predicate($row)));
        }

        if ($this->filter === '') {
            return $rows;
        }

        // Default: case-insensitive substring match on all columns.
        $query = mb_strtolower($this->filter);
        return array_values(array_filter($rows, static function (array $row) use ($query): bool {
            foreach ($row as $cell) {
                if (mb_stripos((string) $cell, $query) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * The rows that are currently visible: sorted then filtered.
     * This is the single source of truth for cursor/indexing operations —
     * both `view()` and `selectedRow()` operate on this projection.
     *
     * @return list<list<string>>
     */
    private function visibleRows(): array
    {
        return $this->visibleRowsCache ??= $this->filteredRows($this->sortedRows());
    }

    /** @return list<int> */
    private function columnWidths(): array
    {
        // N7: size columns from the PROJECTED rows (filter + sort applied),
        // not the raw list — a filtered-out row with a monster cell used to
        // widen every column of the visible table. visibleRows() is memoized,
        // so this costs nothing extra per view().
        $projection = $this->visibleRows();
        $cols = max(
            count($this->headers),
            ...array_map('count', $projection ?: [[]]),
        );
        if ($cols === 0) {
            return [];
        }
        $widths = array_fill(0, $cols, 0);
        foreach (array_merge([$this->displayHeaders()], $projection) as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], Width::string($cell));
            }
        }
        // Per-column explicit width overrides auto-sizing.
        foreach ($this->colWidths as $i => $w) {
            if ($w > 0) {
                $widths[$i] = $w;
            }
        }
        // If a total width is constrained, shrink columns proportionally to
        // their own widths so every line of output fits the budget.
        // E736/2.3: the former round-robin loop cost O(excess × cols);
        // this single O(cols) pass takes floor(excess × width / total) per
        // column, then hands the (≤ cols−1) indivisible remainder cells out
        // right-to-left — preserving the old loop's right-bias when it
        // degrades the trailing columns first. A column can never be asked
        // to shed more than it has: floor ≤ width always, and the remainder
        // walk skips columns already at zero.
        if ($this->width > 0) {
            $gutter = $cols - 1;
            $budget = max(0, $this->width - $gutter);
            $total  = array_sum($widths);
            if ($total > $budget) {
                $excess   = $total - $budget;
                $assigned = 0;
                foreach ($widths as $i => $w) {
                    $shrink    = (int) floor($excess * $w / $total);
                    $widths[$i] = $w - $shrink;
                    $assigned  += $shrink;
                }
                for ($i = $cols - 1; $assigned < $excess && $i >= 0; $i--) {
                    if ($widths[$i] > 0) {
                        $widths[$i]--;
                        $assigned++;
                    }
                }
            }
        }
        return $widths;
    }

    /**
     * @param list<string> $row
     * @param list<int>    $widths
     * @param ?Style       $rowStyle  Row-level style (header / cell / selected)
     *                                to compose UNDER each styleFunc cell. Only
     *                                supplied when a styleFunc is set; the
     *                                whole-row-wrap path passes null and stays
     *                                byte-identical to the legacy render.
     */
    private function renderRow(array $row, array $widths, int $rowIndex = self::HEADER_ROW, ?Style $rowStyle = null): string
    {
        $cells = [];
        foreach ($widths as $i => $w) {
            $cell = $row[$i] ?? '';
            // C3: controlChars deliberately keeps the U+E000/U+E001 zone
            // sentinels and ANSI/C1 bytes a table cell has no business
            // drawing. This is the marked-frame world (cells feed hosts that
            // call Manager::mark/scan), so run the full marked-frame sweep.
            $cell = Sanitize::untrustedForMarkedFrames(Sanitize::controlChars($cell));
            $cell = Width::truncate($cell, $w);
            $pad  = $w - Width::string($cell);
            $padded = $cell . str_repeat(' ', max(0, $pad));
            // Apply per-cell styleFunc when set. The row-level style is
            // composed as the PARENT (winning) layer so the selected-row
            // inversion survives: each cell's own render ends in `0m`,
            // which would otherwise wipe a whole-row wrap from cell 1 on
            // (C4). The cell style still supplies colours the row style does
            // not set, so striping/conditional highlighting is preserved.
            if ($this->styleFunc !== null) {
                $cellStyle = ($this->styleFunc)($rowIndex, $i);
                $style     = $rowStyle !== null ? $rowStyle->inherit($cellStyle) : $cellStyle;
                $padded    = $style->render($padded);
            }
            $cells[] = $padded;
        }
        return implode(' ', $cells);
    }

    private function moveCursor(int $idx): self
    {
        $count = count($this->visibleRows());
        if ($count === 0) {
            return $this->mutate(cursor: 0, offset: 0, currentPage: 0, currentPageSet: true);
        }
        $cursor = max(0, min($count - 1, $idx));
        if ($this->pageSize > 0) {
            // M2: with pagination the window IS the page (view() slices at
            // currentPage * pageSize), so the cursor must carry its page.
            // Previously the cursor could sit outside the drawn window while
            // selectedRow()/index() reported it as the selection.
            $page = intdiv($cursor, $this->pageSize);
            return $this->mutate(
                cursor: $cursor,
                offset: $page * $this->pageSize,
                currentPage: $page,
                currentPageSet: true,
            );
        }
        $offset = $this->offset;
        if ($cursor < $offset) {
            $offset = $cursor;
        }
        if ($this->height > 0 && $cursor >= $offset + $this->height) {
            $offset = $cursor - $this->height + 1;
        }
        return $this->mutate(cursor: $cursor, offset: max(0, $offset));
    }

    /**
     * M4: SortState resolves column names to indices at set time and stores
     * the indices. setHeaders()/setColumns() replace the header vector, so a
     * carried criterion pointing past the new end would silently re-target
     * nothing (or the wrong column after a later widen). Drop out-of-range
     * criteria instead of laundering them.
     */
    private function reconcileSort(int $columnCount): self
    {
        $state = $this->sortState;
        if ($state === null) {
            return $this;
        }
        $kept = $state->withoutColumnsBeyond($columnCount);
        return $kept === $state ? $this : $this->mutate(sortState: $kept, sortStateSet: true);
    }

    private function reclamp(): self
    {
        return $this->moveCursor($this->cursor);
    }

    private function mutate(
        ?array $headers = null,
        ?array $rows = null,
        ?int $cursor = null,
        ?int $offset = null,
        ?int $width = null,
        ?int $height = null,
        ?bool $focused = null,
        ?array $colWidths = null,
        ?Styles $styles = null,
        bool $stylesSet = false,
        ?\Closure $styleFunc = null,
        bool $styleFuncSet = false,
        ?SortState $sortState = null,
        bool $sortStateSet = false,
        ?bool $filterable = null,
        bool $filterableSet = false,
        ?string $filter = null,
        bool $filterSet = false,
        ?\Closure $filterPredicate = null,
        bool $filterPredicateSet = false,
        ?int $pageSize = null,
        bool $pageSizeSet = false,
        ?int $currentPage = null,
        bool $currentPageSet = false,
    ): self {
        return new self(
            headers:         $headers           ?? $this->headers,
            rows:            $rows              ?? $this->rows,
            cursor:          $cursor            ?? $this->cursor,
            offset:          $offset            ?? $this->offset,
            width:           $width             ?? $this->width,
            height:          $height            ?? $this->height,
            focused:         $focused           ?? $this->focused,
            colWidths:       $colWidths         ?? $this->colWidths,
            styles:          $stylesSet ? $styles : $this->styles,
            styleFunc:       $styleFuncSet ? $styleFunc : $this->styleFunc,
            sortState:       $sortStateSet ? $sortState : $this->sortState,
            filterable:       $filterableSet ? $filterable : $this->filterable,
            filter:           $filterSet ? $filter : $this->filter,
            filterPredicate:  $filterPredicateSet ? $filterPredicate : $this->filterPredicate,
            pageSize:         $pageSizeSet ? $pageSize : $this->pageSize,
            currentPage:      $currentPageSet ? $currentPage : $this->currentPage,
        );
    }

    /**
     * Keep the cursor within the current page boundary after a page change.
     */
    private function reclampCursorForPage(Paginator $paginator): self
    {
        [$start, $end] = $paginator->sliceBounds();
        $count = $end - $start;
        if ($count === 0) {
            return $this->moveCursor($start);
        }
        // Clamp the existing cursor so it doesn't land off the page.
        $cursor = max($start, min($start + $count - 1, $this->cursor));
        return $this->mutate(
            cursor: $cursor,
            offset: $this->currentPage * $this->pageSize,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
