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

    /** Style for cursor row (ANSI string). */
    private string $cursorStyle = '';

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

    public static function new(): self
    {
        return new self();
    }

    public function addColumn(Column $col): self
    {
        $clone = clone $this;
        $clone->columns[] = $col;
        return $clone;
    }

    /**
     * Add a row (values are matched to columns by index).
     *
     * Values must be strings or scalars convertible to one (null, bool,
     * int, float, or an object with __toString); anything else throws,
     * naming the column index — a silent 'Array' string used to poison
     * cells and trip failOnWarning through strval()'s notice path.
     *
     * Appending does NOT reset the cursor (the view rebuild did): a
     * streaming feed keeps its selection. Sort and filter changes still
     * reset it.
     *
     * @param array<array-key, string|int|float|bool|null|object> $values
     */
    public function addRow(array $values): self
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

        $clone = clone $this;
        $clone->allRows[] = \array_map(\strval(...), $values);
        $clone->viewDirty = true;
        return $clone;
    }

    /**
     * Sort rows by column index.
     *
     * The index must address a real column: silently sorting NOTHING for
     * an out-of-range index used to leave callers believing a sort had
     * happened. Resets cursorRow to 0.
     *
     * Comparison semantics: when BOTH cells are numeric they compare as
     * numbers (2 sorts before 100); otherwise they compare as
     * case-insensitive strings (strcasecmp), so 'a' precedes 'B' — the
     * binary strcmp order ('B' first) would read as a bug to every human
     * looking at a sorted name column.
     */
    public function sortBy(int $colIndex, bool $ascending = true): self
    {
        if ($colIndex < 0 || $colIndex >= \count($this->columns)) {
            $n = \count($this->columns);
            throw new \InvalidArgumentException(
                "Sort column index {$colIndex} is out of range for a table with {$n} column(s)."
            );
        }
        $clone = clone $this;
        $clone->sortColIndex = $colIndex;
        $clone->sortAscending = $ascending;
        $clone->syncSortedColumns();
        $clone->viewDirty = true;
        $clone->cursorRow  = 0;
        return $clone;
    }

    /**
     * Toggle sort direction on column, or start ascending if different column.
     *
     * Delegates to sortBy(), so the range guard and the cursorRow reset
     * apply here too.
     */
    public function sortByNext(int $colIndex): self
    {
        if ($this->sortColIndex === $colIndex) {
            $clone = $this->sortBy($colIndex, !$this->sortAscending);
        } else {
            $clone = $this->sortBy($colIndex, true);
        }
        return $clone;
    }

    /**
     * Filter rows by text (case-insensitive substring match).
     *
     * Resets cursorRow to 0: the visible row set changes identity, so the
     * old index would silently address a different record.
     */
    public function filter(string $text): self
    {
        $clone = clone $this;
        $clone->filterText = $text;
        $clone->viewDirty  = true;
        $clone->cursorRow  = 0;
        return $clone;
    }

    public function clearFilter(): self
    {
        return $this->filter('');
    }

    /**
     * Move the cursor to $rowIndex of the materialized view. The index is
     * stored as given — nothing clamps it — so an out-of-range row simply
     * falls off the view: currentRow() and currentCell() answer null until
     * a later setCursor() lands back inside.
     */
    public function setCursor(int $rowIndex): self
    {
        $clone = clone $this;
        $clone->cursorRow = $rowIndex;
        return $clone;
    }

    public function withCursorStyle(string $ansiStyle): self
    {
        Sgr::assertParameterString($ansiStyle, 'Table cursor style');
        $clone = clone $this;
        $clone->cursorStyle = $ansiStyle;
        return $clone;
    }

    public function withHeaderStyle(string $ansiStyle): self
    {
        Sgr::assertParameterString($ansiStyle, 'Table header style');
        $clone = clone $this;
        $clone->headerStyle = $ansiStyle;
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
     * The cursor row of the MATERIALIZED view: a pending sort or filter
     * is settled first, so this always answers about what render() would
     * show, not about the stale last-settled frame. Null when the cursor
     * falls off the view entirely (empty table, or an index left past
     * the end by a filter).
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

        // Data rows
        foreach ($this->rows as $ri => $row) {
            $isCursor = ($ri === $this->cursorRow);
            $rowCols  = [];

            foreach ($this->columns as $ci => $col) {
                $val = $row[$ci] ?? '';
                $cellStr = $col->padded($val, $ri);

                // Per-column style reaches every data cell — the knob was
                // once stored but never rendered. Cursor style wraps outside
                // it so the selected row stays visually dominant.
                if ($col->ansiStyle !== '') {
                    $cellStr = $this->applyStyle($cellStr, $col->ansiStyle);
                }

                if ($isCursor && $this->cursorStyle !== '') {
                    $cellStr = $this->applyStyle($cellStr, $this->cursorStyle);
                }

                $rowCols[] = $cellStr;
            }

            $lines[] = \implode($this->separator, $rowCols);
        }

        return $lines;
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
     * of N. The cursor is NOT touched here — sort/filter writers reset it
     * deliberately; an append does not.
     */
    private function rebuildView(): void
    {
        $this->viewDirty = false;
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
