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

    /**
     * @var array<int, list<string>>|null UTF-8 fold of every cell, keyed
     *      by allRows index; see foldedRow() for the invalidation law.
     */
    private ?array $foldedCells = null;

    /** Style for cursor row (ANSI string). */
    private string $cursorStyle = '';

    /** Non-colour focus marker prepended to the cursor row's first cell. */
    private string $cursorMarker = '';

    /** Opt-in no-match row text (FBX-41); null means the feature is off. */
    private ?string $emptyText = null;

    /** Style for header row (ANSI string). */
    private string $headerStyle = '';

    /** Separator string between cells. */
    private string $separator = ' │ ';

    /** Ruler fill/joint runes (FBX-42) — themable like the separator, default unicode exactly as shipped. */
    private string $rulerFill = '─';
    private string $rulerJointChar = '┬';

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
        // yet the sort/filter settle scans every cell of the row, so it can make
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
        // The fold cache is index-addressed over allRows; appends cannot
        // invalidate an existing index, but the dependency is stated
        // here so a future row REWRITE busts it too.
        $clone->foldedCells = null;
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
     * numbers (2 sorts before 100 — and ONLY then: '9' precedes '10'
     * silently, a mixed numeric/text column sorts as text); otherwise
     * they compare as Unicode case-folded strings (full folding via
     * mbstring, plus accent folding when ext-intl is loaded), so 'a'
     * precedes 'B' and 'Ärger' sorts with the A's — the binary strcmp
     * order ('B' first, every accented word last) would read as a bug
     * to every human looking at a sorted name column in a 12-locale
     * ecosystem.
     *
     * A primary-key tie falls through to the columns authored through
     * Column::sorted(), walked in priority order (1 first; 0 consulted
     * last, in column order), each with its own direction — clicking
     * never rewrites those declarations (FBX-37).
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
     * Filter rows by text (Unicode case-folded substring match:
     * 'ärger' finds 'Ärger' and 'strasse' finds 'Straße'; full accent
     * folding too when ext-intl is loaded).
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
     * Theme the ruler's two runes: the horizontal fill and the column
     * joint (default '─' / '┬', byte-identical to every frame ever
     * rendered). ASCII-themed tables mixed unicode rulers under ASCII
     * separators until the ruler characters rode the same ingress as
     * the separator: sanitized, and STRICTLY one cell — a multi-cell
     * or zero-cell rune would desync the ruler line from the header
     * width arithmetic that FBX-16/FBX-34 welded together, so the door
     * refuses anything but a single-cell grapheme, loudly and by name.
     */
    public function withRulerChars(string $fill, string $joint): self
    {
        $cleanFill   = self::assertRulerRune($fill, 'ruler fill');
        $cleanJoint  = self::assertRulerRune($joint, 'ruler joint');
        $clone = clone $this;
        $clone->rulerFill = $cleanFill;
        $clone->rulerJointChar = $cleanJoint;
        $clone->layoutVersion++;
        return $clone;
    }

    private static function assertRulerRune(string $rune, string $what): string
    {
        $clean = \SugarCraft\Core\Util\Sanitize::untrusted($rune);
        if (\SugarCraft\Core\Util\Width::string($clean) !== 1) {
            $cells = \SugarCraft\Core\Util\Width::string($clean);
            throw new \InvalidArgumentException(
                "The {$what} must measure exactly one terminal cell; received a string measuring {$cells} cells. An off-by-one ruler rune desyncs every frame's width arithmetic."
            );
        }
        return $clean;
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
    /**
     * Paint a spanning row when a filter ate every row (FBX-41).
     *
     * The row appears ONLY in the 'no match' state — data exists
     * (totalRowCount() > 0) but the settled view is empty. The 'no
     * data' case (nothing was ever added) deliberately stays bare: the
     * app owns its own empty canvas, and the library guessing here
     * would move bytes on first paint of an empty table. Pass null to
     * switch the feature off; the string is sanitized at ingress like
     * the cursor marker and may be any Lang::t()-produced copy the app
     * maintains.
     */
    public function withEmptyText(?string $text): self
    {
        $clone = clone $this;
        $clone->emptyText = $text === null
            ? null
            : \SugarCraft\Core\Util\Sanitize::untrusted($text);
        return $clone;
    }

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
     * Rows SUPPLIED, before any filter or window: totalRowCount() > 0
     * with rowCount() === 0 is the 'no match' state; totalRowCount()
     * being 0 is plain 'no data'. The pair is what lets an app paint
     * its own empty-state row with different copy (FBX-41).
     */
    public function totalRowCount(): int
    {
        return \count($this->allRows);
    }

    /** The active filter text ('' when unfiltered). */
    public function filterText(): string
    {
        return $this->filterText;
    }

    /**
     * The column roster, index-addressed exactly as the sort keys and
     * header render see it.
     *
     * @return list<Column>
     */
    public function columns(): array
    {
        return $this->columns;
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

        // A width-0 column is a HIDDEN column: it contributes no cell,
        // no joint, and no ruler cluster (FBX-34) — hiding the middle of
        // A/H/B must read 'A │ B', not 'A │  │ B'. The cursor marker and
        // the sort arrows keep working: 'first column' means first
        // VISIBLE column, and hidden columns are simply not a leg of the
        // frame at all. computeTotalWidth() counts the same joints, so
        // the arithmetic and the bytes never disagree.
        $visibleCols = [];
        foreach ($this->columns as $ci => $col) {
            if ($col->width > 0) {
                $visibleCols[$ci] = $col;
            }
        }

        // Header
        $headerCols = [];
        foreach ($visibleCols as $ci => $col) {
            // The active column's arrow comes from the Table's own sort
            // state (a click owns it, nothing else); every other column's
            // arrow is authored Column::sorted() state — the secondary
            // tiebreak keys, shown honestly (FBX-37).
            $arrow = $ci === $this->sortColIndex
                ? ($this->sortAscending ? 1 : -1)
                : $col->sortDir();
            $title = $arrow !== 0
                ? ($arrow > 0 ? '▲ ' : '▼ ') . $col->title
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
        foreach ($visibleCols as $col) {
            $sepParts[] = \str_repeat($this->rulerFill, $col->width);
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
                $lines[] = $this->buildRowLine($ri, $row, true, $visibleCols);
                continue;
            }
            if (($cached[$ri][0] ?? null) === $key) {
                $line = $cached[$ri][1];
            } else {
                $line = $this->buildRowLine($ri, $row, false, $visibleCols);
            }
            $memo[$ri] = [$key, $line];
            $lines[]   = $line;
        }

        $this->rowMemo = $memo;

        // 'No match' (FBX-41): rows were supplied, the settled view is
        // empty (the filter ate them all), and the app opted into a
        // message. A window scrolled past the end is NOT this state —
        // $this->rows answers about the settled view, not the slice.
        if ($this->emptyText !== null && $this->rows === [] && $this->allRows !== []) {
            $span = $this->computeTotalWidth();
            if ($span > 0) {
                $lines[] = Width::padRight(
                    Width::truncateAnsi($this->emptyText, $span),
                    $span
                );
            }
        }

        return $lines;
    }

    /**
     * Render one data-row line for view index $ri.
     *
     * The index reaches Column::padded() — a formatter may branch on it —
     * and the window never renumbers rows, so a windowed line is the very
     * bytes the full frame paints at the same index.
     */
    /**
     * @param array<int, Column> $visibleCols width-0 columns emit NOTHING —
     *                                       see buildLines() for the hidden-column law
     */
    private function buildRowLine(int $ri, array $row, bool $isCursor, array $visibleCols): string
    {
        $rowCols = [];
        $firstVisible = \array_key_first($visibleCols);
        foreach ($visibleCols as $ci => $col) {
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
            if ($isCursor && $this->cursorMarker !== '' && $ci === $firstVisible) {
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
     * The authored secondary sort keys, in tiebreak order: every column
     * that declares sorted() — other than the active one, which the
     * Table owns outright — walked by explicit priority first (1 = the
     * FIRST tiebreak; priority 0 means 'authored but unprioritised' and
     * sorts after every numbered one, in column order).
     *
     * A sort click never authors and never erases these (FBX-37): the
     * old sync clobbered the whole roster, so sorted(-1, 5) survived
     * exactly zero interactions with sortBy(). Column::sorted() is the
     * only writer; this is the only reader.
     *
     * @return list<array{col: int, dir: int, priority: int}>
     */
    private function secondarySortKeys(): array
    {
        $secs = [];
        foreach ($this->columns as $i => $col) {
            if ($i !== $this->sortColIndex && $col->sortDir() !== 0) {
                $secs[] = ['col' => $i, 'dir' => $col->sortDir(), 'priority' => $col->sortPriority()];
            }
        }
        \usort($secs, static fn (array $a, array $b): int =>
            [$a['priority'] > 0 ? $a['priority'] : \PHP_INT_MAX, $a['col']]
            <=> [$b['priority'] > 0 ? $b['priority'] : \PHP_INT_MAX, $b['col']]
        );
        return $secs;
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

        // Decorate-sort-undecorate, paired by allRows index: the sort
        // compares ONE precomputed key per row instead of re-folding
        // inside every comparison (finding-055: ~250 ms/20k of pure
        // re-normalisation churn), and the index travels through the
        // permutation into the filter, which reads the persistent fold
        // cache instead of lowercasing the world per keystroke
        // (finding-056).
        $items = [];
        $keys  = [];
        $sorting = $this->sortColIndex >= 0 && $this->sortColIndex < \count($this->columns);
        if ($sorting) {
            $colIdx = $this->sortColIndex;
            $secs   = $this->secondarySortKeys();
            $secKeys = [];
            foreach ($this->allRows as $i => $row) {
                $items[]  = [$i, $row];
                $folded   = $this->foldedRow($i);
                $keys[$i] = $this->sortKey($row[$colIdx] ?? '', $folded[$colIdx] ?? '');
                foreach ($secs as $sec) {
                    $secKeys[$sec['col']][$i] =
                        $this->sortKey($row[$sec['col']] ?? '', $folded[$sec['col']] ?? '');
                }
            }
            $asc = $this->sortAscending;
            \usort($items, static function (array $a, array $b) use ($keys, $secKeys, $secs, $asc): int {
                $cmp = self::compareSortKeys($keys[$a[0]], $keys[$b[0]]);
                if ($cmp !== 0) {
                    return $asc ? $cmp : -$cmp;
                }
                // Primary tie: walk the authored secondary keys in
                // priority order, each with its own direction.
                foreach ($secs as $sec) {
                    $tie = self::compareSortKeys($secKeys[$sec['col']][$a[0]], $secKeys[$sec['col']][$b[0]]);
                    if ($tie !== 0) {
                        return $sec['dir'] > 0 ? $tie : -$tie;
                    }
                }
                return 0;
            });
        } else {
            foreach ($this->allRows as $i => $row) {
                $items[] = [$i, $row];
            }
        }

        if ($this->filterText !== '') {
            $needle = self::fold($this->filterText);
            $items = \array_values(\array_filter(
                $items,
                function (array $pair) use ($needle): bool {
                    foreach ($this->foldedRow($pair[0]) as $folded) {
                        if ($folded !== '' && \str_contains($folded, $needle)) {
                            return true;
                        }
                    }
                    return false;
                }
            ));
        }

        $rowsOut = [];
        foreach ($items as [, $row]) {
            $rowsOut[] = $row;
        }
        $this->rows = $rowsOut;

        if ($this->cursorAnchor !== null) {
            $anchor = $this->cursorAnchor;
            $this->cursorAnchor = null;
            $this->cursorRow = $this->resolveCursorAnchor($anchor);
        }
    }

    /**
     * One comparable key for a sort cell, built ONCE per settle per row.
     * The fold is read through the persistent cell cache (shared with
     * the filter); the numeric classification decides the comparison
     * lane in compareSortKeys().
     *
     * @return array{digit: ?string, num: ?float, fold: string}
     */
    private function sortKey(string $value, string $fold): array
    {
        return [
            // Digit-only strings keep their EXACT value as a zero-stripped
            // decimal string: a float cast would silently collapse every
            // pair beyond 2^53 (10^18 and 10^18+1 tie at double
            // precision, and the sort order becomes insertion order).
            'digit' => ($value !== '' && \ctype_digit($value)) ? (ltrim($value, '0') ?: '0') : null,
            'num'   => \is_numeric($value) ? (float) $value : null,
            'fold'  => $fold,
        ];
    }

    /**
     * Lane order: two digit-only cells compare EXACTLY at any magnitude
     * (length first — the values carry no leading zeros — then
     * lexicographic, which for equal-length digits is the numeric
     * order); otherwise two numeric-looking cells compare as floats
     * (2 before 100); everything else compares as folded text.
     *
     * @param array{digit: ?string, num: ?float, fold: string} $ka
     * @param array{digit: ?string, num: ?float, fold: string} $kb
     */
    private static function compareSortKeys(array $ka, array $kb): int
    {
        if ($ka['digit'] !== null && $kb['digit'] !== null) {
            $la = \strlen($ka['digit']);
            $lb = \strlen($kb['digit']);
            return $la !== $lb ? $la <=> $lb : \strcmp($ka['digit'], $kb['digit']);
        }
        if ($ka['num'] !== null && $kb['num'] !== null) {
            return $ka['num'] <=> $kb['num'];
        }
        return \strcmp($ka['fold'], $kb['fold']);
    }

    /**
     * The UTF-8 fold of every cell of canonical row $index, built on
     * first need and kept for the life of this row set. allRows is
     * append-only — no stored cell ever changes once written — so an
     * index-keyed cache cannot go stale by construction; withRow()
     * still drops it as the belt-and-braces statement of the
     * dependency. A filter chain therefore pays the fold cost ONCE
     * (first settle), then every keystroke is a predicate sweep over
     * cached keys instead of ~2×20k renormalisations at 20k rows.
     *
     * @return list<string>
     */
    private function foldedRow(int $index): array
    {
        if ($this->foldedCells === null) {
            $this->foldedCells = [];
        }
        return $this->foldedCells[$index] ??= \array_map(self::fold(...), $this->allRows[$index]);
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
    /**
     * The Unicode fold shared by BOTH matching paths: the filter
     * substring test and the string-sort leg (FBX-27).
     *
     * mbstring's full case fold is the guaranteed floor: 'Ärger' answers
     * to 'ärger', and 'ß' answers to 'ss'. When ext-intl is loaded the
     * text is compatibility-decomposed first and combining marks are
     * dropped, so accents fold too — 'Ä' matches and sorts with plain
     * 'A'. Without intl, accented letters still case-fold but keep
     * their code-point neighbourhood in the ordering.
     */
    private static function fold(string $value): string
    {
        // Short-circuit: with no uppercase byte and no high byte the
        // fold is provably the identity — skip normalizer, preg and the
        // mb conversion over whole numeric/ASCII columns.
        if (\preg_match('/[A-Z\x80-\xFF]/', $value) !== 1) {
            return $value;
        }
        if (\function_exists('normalizer_normalize')) {
            $decomposed = \normalizer_normalize($value, \Normalizer::FORM_KD);
            if (\is_string($decomposed)) {
                $stripped = \preg_replace('/\p{Mn}+/u', '', $decomposed);
                if (\is_string($stripped)) {
                    $value = $stripped;
                }
            }
        }
        return \mb_convert_case($value, \MB_CASE_FOLD, 'UTF-8');
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
        return \str_repeat($this->rulerFill, $left) . $this->rulerJointChar . \str_repeat($this->rulerFill, $right);
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
        // Joints live BETWEEN visible cells only: a hidden (width-0)
        // column takes neither a cell nor a joint (FBX-34).
        $visible   = \count(\array_filter($this->columns, static fn(Column $c): bool => $c->width > 0));
        $joints    = \max(0, $visible - 1);
        return $colWidths + Width::string($this->separator) * $joints;
    }

    private function applyStyle(string $s, string $style): string
    {
        if ($style === '') return $s;
        return Ansi::CSI . $style . 'm' . $s . Ansi::reset();
    }
}
