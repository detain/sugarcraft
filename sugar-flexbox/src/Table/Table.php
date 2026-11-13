<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Table;

use SugarCraft\Flexbox\Support\Sgr;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Sortable, filterable data table renderer.
 *
 * Renders a list of rows (each row is a list of string values) into a
 * formatted table with column headers, alignment, sorting, and filtering.
 *
 * Design antecedent: 76creates/stickers Table (PHP).
 *
 * @see https://github.com/76creates/stickers
 *
 * NOTE: Diff-based emission has moved to TableRenderer. Call TableRenderer::render()
 * for bandwidth-efficient delta output. Table::render() always returns the full frame.
 */
final class Table
{
    /** @var list<Column> */
    private array $columns = [];

    /**
     * Canonical, unfiltered, original-insertion-order rows.
     * Source of truth — only mutated by addRow().
     *
     * @var list<list<string>>
     */
    private array $allRows = [];

    /**
     * Visible row view: $allRows with the current sort + filter applied.
     * Rebuilt by rebuildView() whenever sort or filter state changes.
     *
     * @var list<list<string>>
     */
    private array $rows = [];

    private int $sortColIndex = -1;
    private bool $sortAscending = true;
    private string $filterText = '';
    private int $cursorRow = 0;

    /**
     * Content of the row the cursor sat on when the last sort/filter
     * writer fired. rebuildView() resolves it to the matching index in
     * the new settled view — the selection travels with its row through
     * a re-sort or refilter (first occurrence when equal rows exist:
     * the view cannot tell them apart any more than the screen can).
     * Null means "nothing to follow": the cursor addressed no visible
     * row at capture time and simply keeps its index.
     */
    private ?array $cursorAnchor = null;

    /** Style for cursor row (ANSI string). */
    private string $cursorStyle = '';

    /** Non-colour focus marker prepended to the cursor row's first cell. */
    private string $cursorMarker = '';

    /** Style for header row (ANSI string). */
    private string $headerStyle = '';

    /** Separator string between cells. */
    private string $separator = ' │ ';

    /**
     * The visible $rows view is derived state; these two fields make it
     * LAZY so N appends do not cost N sorts (m5). Reads go through
     * ensureView().
     */
    private bool $viewDirty = false;

    /**
     * Paint window: view rows below $visibleFrom or at/above $visibleTo
     * are never emitted (header and ruler always paint). Data semantics
     * are untouched — rowCount(), currentRow() and the cursor index all
     * address the FULL settled view. Default (0, null) renders everything.
     */
    private int $visibleFrom = 0;
    private ?int $visibleTo = null;

    /**
     * Per-render row-line memo: view index => [(version key), (line)].
     * A cursor move re-renders ONLY the two cursor rows; every other row
     * is served from here. Rows carrying the cursor are never stored —
     * their marker and style are cursor-dependent, so the cache could
     * poison the frame after the cursor moves on. Keys carry both
     * versions: $rowsVersion moves whenever rebuildView() settles new
     * content (append/sort/filter), $layoutVersion whenever column or
     * separator changes alter every row's shape.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $rowMemo = [];

    private int $rowsVersion = 0;
    private int $layoutVersion = 0;

    public static function new(): self
    {
        return new self();
    }

    /**
     * Returns a new Table with $col appended; receiver unchanged.
     */
    public function withColumn(Column $col): self
    {
        $clone = clone $this;
        $clone->columns[] = $col;
        // Every row gains a cell: the line memo is about the old shape.
        $clone->layoutVersion++;
        return $clone;
    }

    /**
     * @deprecated Renamed to {@see self::withColumn()} for the fluent house
     *             vocabulary; returns a new Table, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function addColumn(Column $col): self { return $this->withColumn($col); }

    /**
     * Returns a new Table with one more row (values are matched to
     * columns by index).
     *
     * Values must be strings or scalars convertible to one (null, bool,
     * int, float, or an object with __toString); anything else throws,
     * naming the column index — a silent 'Array' string used to poison
     * cells and trip failOnWarning through strval()'s notice path.
     *
     * More values than columns throws, naming both counts — surplus
     * cells never render but still feed sorting and filtering. Fewer
     * values than columns is legal: the trailing cells stay empty.
     *
     * Appending does NOT reset the cursor (the view rebuild did): a
     * streaming feed keeps its selection. Sort and filter changes still
     * reset it.
     *
     * @param array<array-key, string|int|float|bool|null|object> $values
     */
    public function withRow(array $values): self
    {
        $values = \array_values($values);
        foreach ($values as $i => $value) {
            if (\is_array($value)) {
                throw new \InvalidArgumentException(
                    "Row value for column {$i} must be a string or scalar; array given."
                );
            }
            if (\is_object($value) && !\method_exists($value, '__toString')) {
                $type = \get_class($value);
                throw new \InvalidArgumentException(
                    "Row value for column {$i} must be a string or scalar; {$type} without __toString given."
                );
            }
        }

        // A value with no column to wear it is a phantom: it never renders,
        // yet applyFilter/compare scan every cell of the row, so it can make
        // a row match a filter the user cannot explain from the screen.
        // Refuse the shape at the door instead of shipping the illusion.
        $columnCount = \count($this->columns);
        if (\count($values) > $columnCount) {
            $valueCount = \count($values);
            if ($columnCount === 0) {
                throw new \InvalidArgumentException(
                    "withRow() got {$valueCount} value(s) but the table has no columns yet — add the columns before the rows."
                );
            }
            $titles = \implode(', ', \array_map(
                static fn(Column $col): string => $col->title,
                $this->columns
            ));
            throw new \InvalidArgumentException(
                "withRow() got {$valueCount} value(s) but the table has {$columnCount} column(s) ({$titles}): surplus cells never render yet still feed sorting and filtering — trim the row or add the columns first."
            );
        }

        $clone = clone $this;
        $clone->allRows[] = \array_map(\strval(...), $values);
        $clone->viewDirty = true;
        return $clone;
    }

    /**
     * @deprecated Renamed to {@see self::withRow()} for the fluent house
     *             vocabulary; returns a new Table, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function addRow(array $values): self { return $this->withRow($values); }

    /**
     * Sort rows by column index.
     *
     * The index must address a real column: silently sorting NOTHING for
     * an out-of-range index used to leave callers believing a sort had
     * happened. The selection FOLLOWS the row it stood on: the cursor's
     * row is matched by its stored cell values in the re-sorted view, so
     * re-sorting a list keeps the highlighted record highlighted (rows
     * with identical cells resolve to the first survivor).
     *
     * Comparison semantics: when BOTH cells are numeric they compare as
     * numbers (2 sorts before 100); otherwise they compare as
     * case-insensitive strings (strcasecmp), so 'a' precedes 'B' — the
     * binary strcmp order ('B' first) would read as a bug to every human
     * looking at a sorted name column.
     */
    public function withSort(int $colIndex, bool $ascending = true): self
    {
        if ($colIndex < 0 || $colIndex >= \count($this->columns)) {
            $n = \count($this->columns);
            throw new \InvalidArgumentException(
                "Sort column index {$colIndex} is out of range for a table with {$n} column(s)."
            );
        }
        $this->ensureView();
        $clone = clone $this;
        $clone->followSelection();
        $clone->sortColIndex = $colIndex;
        $clone->sortAscending = $ascending;
        $clone->syncSortedColumns();
        $clone->viewDirty = true;
        return $clone;
    }

    /**
     * @deprecated Renamed to {@see self::withSort()} for the fluent house
     *             vocabulary; returns a new Table, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function sortBy(int $colIndex, bool $ascending = true): self { return $this->withSort($colIndex, $ascending); }

    /**
     * Toggle sort direction on column, or start ascending if different column.
     *
     * Delegates to withSort(), so the range guard and the
     * selection-follows-its-row law apply here too.
     */
    public function sortByNext(int $colIndex): self
    {
        if ($this->sortColIndex === $colIndex) {
            $clone = $this->withSort($colIndex, !$this->sortAscending);
        } else {
            $clone = $this->withSort($colIndex, true);
        }
        return $clone;
    }

    /**
     * Filter rows by text (case-insensitive substring match).
     *
     * Matching runs against the stored raw cell strings, exactly as sort
     * ordering does; the render-time sanitize pass never widens what can
     * match. A value that must only match its cleaned form should be
     * sanitized by the caller before being stored.
     *
     * The selection follows its row: a row that survives the narrowed
     * set keeps the cursor (at its new index), and only a cursor whose
     * row got filtered away falls back to 0 — an index left alone would
     * silently address a different record.
     */
    public function withFilter(string $text): self
    {
        $this->ensureView();
        $clone = clone $this;
        $clone->followSelection();
        $clone->filterText = $text;
        $clone->viewDirty  = true;
        return $clone;
    }

    /**
     * @deprecated Renamed to {@see self::withFilter()} for the fluent house
     *             vocabulary; returns a new Table, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function filter(string $text): self { return $this->withFilter($text); }

    public function clearFilter(): self
    {
        return $this->withFilter('');
    }

    /**
     * Move the cursor to $rowIndex of the materialized view.
     *
     * The view is settled FIRST (a pending sort or filter is applied),
     * then the index must address a visible row: out-of-range refuses
     * with the row count it checked. Silent acceptance used to let a
     * typo'd index park the selection on nothing — currentRow() answered
     * null and every keyboard interaction looked broken with no error
     * to point at. The one legal null-answer state is an empty view
     * holding the default index 0; cursor() names it.
     */
    public function withCursor(int $rowIndex): self
    {
        $this->ensureView();
        $visible = \count($this->rows);
        if ($rowIndex < 0 || $rowIndex >= $visible) {
            throw new \InvalidArgumentException(
                "Cursor row {$rowIndex} is out of range for a settled view of {$visible} row(s); indexes run 0.." . max(0, $visible - 1) . '.'
            );
        }
        $clone = clone $this;
        $clone->cursorRow = $rowIndex;
        return $clone;
    }

    /**
     * @deprecated Renamed to {@see self::withCursor()} for the fluent house
     *             vocabulary; returns a new Table, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setCursor(int $rowIndex): self { return $this->withCursor($rowIndex); }

    public function withCursorStyle(string $ansiStyle): self
    {
        $style = Sgr::normalize($ansiStyle, 'Table cursor style');
        $clone = clone $this;
        $clone->cursorStyle = $style;
        return $clone;
    }

    /**
     * Non-colour focus marker prepended to the cursor row's first cell.
     *
     * Colour-only focus is invisible on monochrome terminals, to colour-blind
     * users and under NO_COLOR hosts (finding-023). The prefix rides INSIDE
     * the first column's allocated width — mirroring the header's ▲/▼ sort
     * arrow precedent — so frame dimensions never move; a value wider than
     * the remaining space truncates on the focused row only. The string is
     * sanitized at ingress like the separator. Default '' renders exactly
     * the bytes it always did.
     */
    public function withCursorMarker(string $prefix): self
    {
        $clone = clone $this;
        $clone->cursorMarker = \SugarCraft\Core\Util\Sanitize::untrusted($prefix);
        return $clone;
    }

    public function withHeaderStyle(string $ansiStyle): self
    {
        $style = Sgr::normalize($ansiStyle, 'Table header style');
        $clone = clone $this;
        $clone->headerStyle = $style;
        return $clone;
    }

    /**
     * Set the cell separator. The string is rendered raw between every
     * pair of cells, so it is sanitized at ingress exactly like data
     * cells: control sequences cannot ride the separator into the
     * terminal (ruler jointing then measures the sanitized display
     * width).
     */
    public function withSeparator(string $s): self
    {
        $clone = clone $this;
        $clone->separator = \SugarCraft\Core\Util\Sanitize::untrusted($s);
        // The joint rides inside every data row: retire the memo's keys.
        $clone->layoutVersion++;
        return $clone;
    }

    /**
     * Paint only the view rows in [$from, $to) (open-ended when $to is
     * null). This is the virtualization door for large tables: a 24-line
     * viewport never renders the cells of 20k rows, only its window.
     *
     * Header and ruler always paint; the cursor is unaffected in the data
     * sense — currentRow() and rowCount() still address the FULL view, so
     * a windowed frame is byte-identical to the slice of a full frame at
     * the same indices (pinned in TableTest). Out-of-range $to simply
     * renders fewer rows; $from < 0 or a closed range that does not
     * advance throws at this door (house fail-fast).
     */
    public function withVisibleRange(int $from, ?int $to = null): self
    {
        if ($from < 0) {
            throw new \InvalidArgumentException(
                "Visible range start must be >= 0; got {$from}."
            );
        }
        if ($to !== null && $to <= $from) {
            throw new \InvalidArgumentException(
                "Visible range end {$to} must be greater than its start {$from} (or null for open-ended)."
            );
        }
        $clone = clone $this;
        $clone->visibleFrom = $from;
        $clone->visibleTo = $to;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function rowCount(): int
    {
        $this->ensureView();
        return \count($this->rows);
    }

    public function colCount(): int
    {
        return \count($this->columns);
    }

    /**
     * The cursor's index in the materialized view, settling any pending
     * sort/filter first — the same law as currentRow(). Null means the
     * index addresses no visible row: with FBX-26's refused out-of-range
     * cursors and the follow-its-row re-sort, the only reachable case is
     * an empty view holding the default index 0.
     */
    public function cursor(): ?int
    {
        $this->ensureView();
        return ($this->cursorRow >= 0 && $this->cursorRow < \count($this->rows))
            ? $this->cursorRow
            : null;
    }

    /**
     * The cursor row of the MATERIALIZED view: a pending sort or filter
     * is settled first, so this always answers about what render() would
     * show, not about the stale last-settled frame. Null when the view
     * has no rows at all — an out-of-range cursor cannot exist to fall
     * off (withCursor() refuses it, and re-sorts carry the selection
     * with its row), so the empty view is the one honest null source.
     *
     * The cells are returned RAW — exactly the strings addRow() stored,
     * before the render-time sanitize sweep (which lives inside
     * Column::padded(), reached only through render()) — so echoing them
     * bypasses every guard the table owns. Clean untrusted values first;
     * the README "Trust boundary" section carries the user-facing rule.
     */
    public function currentRow(): ?array
    {
        $this->ensureView();
        $idx = $this->cursorRow;
        if ($idx < 0 || $idx >= \count($this->rows)) {
            return null;
        }
        return $this->rows[$idx];
    }

    /**
     * One cell under the cursor, RAW like {@see self::currentRow()} —
     * the stored string with no sweep applied — or null when the cursor
     * is off the view or the column index is out of range.
     */
    public function currentCell(int $colIndex): ?string
    {
        $row = $this->currentRow();
        return $row[$colIndex] ?? null;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the table to a full string (always full frame, never delta).
     *
     * For bandwidth-efficient delta emission, use TableRenderer::render()
     * which tracks diff state across frames.
     */
    public function render(): string
    {
        return \implode("\n", $this->buildLines());
    }

    /**
     * Build all rendered lines (header, separator, data rows).
     *
     * @return list<string>
     */
    public function buildLines(): array
    {
        if ($this->columns === []) {
            return [];
        }

        $this->ensureView();

        $lines = [];

        // Header
        $headerCols = [];
        foreach ($this->columns as $ci => $col) {
            $title = $col->sortDir() !== 0
                ? ($col->sortDir() > 0 ? '▲ ' : '▼ ') . $col->title
                : $col->title;

            $titleStr = $this->padHeader($title, $col->width, $col->align);
            if ($this->headerStyle !== '') {
                $titleStr = $this->applyStyle($titleStr, $this->headerStyle);
            }
            $headerCols[] = $titleStr;
        }
        $lines[] = \implode($this->separator, $headerCols);

        // Separator line — the joint between column runs is derived from the
        // configured separator's cell width so a custom separator can never
        // desync header from ruler. The default ' │ ' is
        // 3 cells wide, producing '─┬─' exactly as before.
        $sepParts = [];
        foreach ($this->columns as $col) {
            $sepParts[] = \str_repeat('─', $col->width);
        }
        $lines[] = \implode($this->rulerJoint(), $sepParts);

        // Data rows — windowed, and served from the line memo wherever
        // the row is plain and its (view, layout) version still matches.
        $from   = $this->visibleFrom;
        $to     = $this->visibleTo;
        $key    = $this->rowsVersion . ':' . $this->layoutVersion;
        $cached = $this->rowMemo;
        $memo   = [];

        foreach ($this->rows as $ri => $row) {
            if ($ri < $from) {
                continue;
            }
            if ($to !== null && $ri >= $to) {
                break;
            }
            $isCursor = ($ri === $this->cursorRow);
            if ($isCursor) {
                $lines[] = $this->buildRowLine($ri, $row, true);
                continue;
            }
            if (($cached[$ri][0] ?? null) === $key) {
                $line = $cached[$ri][1];
            } else {
                $line = $this->buildRowLine($ri, $row, false);
            }
            $memo[$ri] = [$key, $line];
            $lines[]   = $line;
        }

        $this->rowMemo = $memo;

        return $lines;
    }

    /**
     * Render one data-row line for view index $ri.
     *
     * The index reaches Column::padded() — a formatter may branch on it —
     * and the window never renumbers rows, so a windowed line is the very
     * bytes the full frame paints at the same index.
     */
    private function buildRowLine(int $ri, array $row, bool $isCursor): string
    {
        $rowCols = [];
        foreach ($this->columns as $ci => $col) {
            $val = $row[$ci] ?? '';
            $cellStr = $col->padded($val, $ri);

            // Per-column style reaches every data cell — the knob was
            // once stored but never rendered.
            if ($col->ansiStyle !== '') {
                $cellStr = $this->applyStyle($cellStr, $col->ansiStyle);
            }

            // Non-colour focus marker (finding-023): prepend like the
            // header's sort arrow, then re-clamp so the cell keeps its
            // exact allocated width. Applied AFTER the column style so
            // the prefix always renders un-styled and bare.
            if ($isCursor && $this->cursorMarker !== '' && $ci === 0) {
                $clamped = Width::truncateAnsi(
                    $this->cursorMarker . $cellStr,
                    $col->width
                );
                $cellStr = match ($col->align) {
                    'right'  => Width::padLeft($clamped, $col->width),
                    'center' => Width::padCenter($clamped, $col->width),
                    default  => Width::padRight($clamped, $col->width),
                };
            }

            $rowCols[] = $cellStr;
        }

        $line = \implode($this->separator, $rowCols);

        // Row style wraps the IMPLoded line (finding-019): a per-cell
        // wrap left every ' │ ' joint bare between reset runs, so the
        // focused row rendered striped instead of solid.
        if ($isCursor && $this->cursorStyle !== '') {
            $line = $this->applyStyle($line, $this->cursorStyle);
        }

        return $line;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Sync each Column's sorted() state to the current sort target so the
     * header renders the ▲/▼ arrow on the sorted column and clears it on the
     * others. Column is immutable, so the columns array is rebuilt with the
     * updated Column instances. Sort ordering is untouched — this only mirrors
     * the sort state into the column value objects that the header reads.
     */
    private function syncSortedColumns(): void
    {
        $dir = $this->sortAscending ? 1 : -1;
        foreach ($this->columns as $i => $col) {
            $this->columns[$i] = ($i === $this->sortColIndex)
                ? $col->sorted($dir)
                : $col->unsorted();
        }
    }

    /**
     * Rebuild the visible $rows view from $allRows by applying the current
     * sort and filter state. Always derives from the canonical $allRows
     (never from already-shrunk data), so filter('') restores the full set.
     *
     * The rebuild is LAZY (m5): writers mark $viewDirty and readers settle
     * it through ensureView(), so N addRow() calls cost one sort instead
     * of N. The cursor is carried through here rather than reset: a
     * sort/filter writer parked the content of the selected row in
     * $cursorAnchor (see followSelection()), and the settle re-finds it
     * in the new view — first occurrence when equal rows exist — or
     * falls back to 0 when the row got filtered away. An append neither
     * anchors nor moves the cursor.
     */
    private function rebuildView(): void
    {
        $this->viewDirty = false;
        // New settled content: the line memo keys on this version, so
        // every cached row becomes stale the moment rows change identity.
        $this->rowsVersion++;
        $rows = $this->allRows;

        if ($this->sortColIndex >= 0 && $this->sortColIndex < \count($this->columns)) {
            $colIdx = $this->sortColIndex;
            $asc    = $this->sortAscending;

            \usort($rows, function (array $a, array $b) use ($colIdx, $asc): int {
                $va = $a[$colIdx] ?? '';
                $vb = $b[$colIdx] ?? '';

                $na = \is_numeric($va) ? (float) $va : null;
                $nb = \is_numeric($vb) ? (float) $vb : null;

                if ($na !== null && $nb !== null) {
                    $cmp = $na <=> $nb;
                } else {
                    $cmp = \strcasecmp((string) $va, (string) $vb);
                }

                return $asc ? $cmp : -$cmp;
            });
        }

        $this->rows = $this->applyFilter($rows);

        if ($this->cursorAnchor !== null) {
            $anchor = $this->cursorAnchor;
            $this->cursorAnchor = null;
            $this->cursorRow = $this->resolveCursorAnchor($anchor);
        }
    }

    /**
     * Index of the first row with the same stored cells as $anchor, or 0
     * when the anchored row is gone (filtered away) — the cursor's
     * settle-time remap for the selection-follows-its-row law.
     *
     * @param list<string> $anchor
     */
    private function resolveCursorAnchor(array $anchor): int
    {
        foreach ($this->rows as $i => $row) {
            if ($row === $anchor) {
                return $i;
            }
        }
        return 0;
    }

    /**
     * Called on a freshly built clone BEFORE its sort/filter state moves
     * the rows: parks the content currently under the cursor so the
     * settle can re-find it. Must run after the receiver (and therefore
     * this clone) is settled, or the capture would anchor a stale frame.
     */
    private function followSelection(): void
    {
        $this->cursorAnchor =
            ($this->cursorRow >= 0 && $this->cursorRow < \count($this->rows))
                ? $this->rows[$this->cursorRow]
                : null;
    }

    /**
     * Settle the derived view if any writer marked it stale. Mutates only
     * internal derived state on an already-published instance, mirroring
     * the established clone-then-populate pattern; observable results are
     * unchanged.
     */
    private function ensureView(): void
    {
        if ($this->viewDirty) {
            $this->rebuildView();
        }
    }

    /**
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private function applyFilter(array $rows): array
    {
        if ($this->filterText === '') {
            return $rows;
        }
        $lower = \strtolower($this->filterText);
        return \array_values(
            \array_filter($rows, function (array $row) use ($lower): bool {
                foreach ($row as $cell) {
                    if (\str_contains(\strtolower($cell), $lower)) {
                        return true;
                    }
                }
                return false;
            })
        );
    }

    /**
     * Cell-width ruler segment joining two column runs, honoring the
     * configured separator's display width.
     */
    private function rulerJoint(): string
    {
        $sepWidth = Width::string($this->separator);
        if ($sepWidth <= 0) {
            return '';
        }
        $left  = intdiv($sepWidth - 1, 2);
        $right = $sepWidth - 1 - $left;
        return \str_repeat('─', $left) . '┬' . \str_repeat('─', $right);
    }

    private function padHeader(string $text, int $width, string $align): string
    {
        // Header titles carry '▲ '/'▼ ' prefixes and may hold wide (CJK) glyphs;
        // padding must be display-cell based, not byte based.
        // For plain ASCII this is byte-identical to the old strlen path.
        $truncated = Width::truncateAnsi($text, $width);
        return match ($align) {
            'right'  => Width::padLeft($truncated, $width),
            'center' => Width::padCenter($truncated, $width),
            default  => Width::padRight($truncated, $width),
        };
    }

    /**
     * Total display width of a rendered line, in CELLS: column widths
     * plus the separator's display width at every joint. Byte counting
     * the separator (strlen) over-reported any non-ASCII joint — ' │ '
     * is 5 bytes but 3 cells — and zero columns used to yield a negative
     * total from the (n - 1) joint term.
     */
    public function computeTotalWidth(): int
    {
        $colWidths = \array_sum(\array_map(fn(Column $c) => $c->width, $this->columns));
        $joints    = \max(0, \count($this->columns) - 1);
        return $colWidths + Width::string($this->separator) * $joints;
    }

    private function applyStyle(string $s, string $style): string
    {
        if ($style === '') return $s;
        return Ansi::CSI . $style . 'm' . $s . Ansi::reset();
    }
}
