<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Buffer;

use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Sgr\Sgr;
use SugarCraft\Vterm\Hyperlink\Hyperlink;

/**
 * The one 2D cell grid — shared by the emulator ({@see \SugarCraft\Vterm\Handler\ScreenHandler})
 * and the vcr renderer path ({@see \SugarCraft\Vterm\Terminal}).
 *
 * Rows are stored as vec-optimized arrays. Buffer is MUTABLE: `put()` writes
 * in place; `resize()` and `clear()` return fresh instances and leave this
 * one untouched. The immutable frame view is Snapshot / copy-on-write, not
 * this class.
 *
 * Cloning a Buffer is the correct deep copy for frame capture: PHP's default
 * clone copies the `$grid` array by value, and {@see Cell} is readonly —
 * writes to the original can never reach through a clone (or vice versa).
 *
 * Tracks a minimal bounding box of cells written since the instance was
 * built ({@see dirtyRegion()}). `resize()`/`clear()` mint a new instance, so
 * their dirty box starts empty even though content carried over.
 */
final class Buffer
{
    /** @var array<int, array<int, Cell>> */
    private array $grid;

    // Dirty-region sentinels: PHP_INT_MAX/-1 mean "no dirty cells yet".
    // Once any cell is written, minRow/minCol become the true minima
    // and maxRow/maxCol become the true maxima.
    private int $minRow = PHP_INT_MAX;
    private int $maxRow = -1;
    private int $minCol = PHP_INT_MAX;
    private int $maxCol = -1;

    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
    ) {
        if ($cols < 1 || $rows < 1) {
            // A zero/negative grid is not a degenerate-but-usable state:
            // every write silently no-ops and every read answers empty, so
            // a mis-plumbed terminal looks alive while dropping all output.
            // Fail at construction instead.
            throw new \InvalidArgumentException(
                "Buffer dimensions must be >= 1x1, got {$cols}x{$rows}"
            );
        }
        $this->grid = $this->makeGrid($cols, $rows);
    }

    /**
     * Build an empty grid of the given dimensions.
     *
     * Every pristine slot shares the {@see Cell::empty()} singleton: an empty
     * 320x120 grid allocates 38400 array slots but exactly one cell object
     * (~6 MB saved on the hot emulator resize path). Written slots replace
     * the shared reference with their own immutable value.
     */
    private function makeGrid(int $cols, int $rows): array
    {
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                $row[] = Cell::empty();
            }
            $grid[] = $row;
        }
        return $grid;
    }

    /** @return array{minRow:int, maxRow:int, minCol:int, maxCol:int} */
    public function dirtyRegion(): array
    {
        return [
            'minRow' => $this->minRow,
            'maxRow' => $this->maxRow,
            'minCol' => $this->minCol,
            'maxCol' => $this->maxCol,
        ];
    }

    /**
     * Resize the grid, preserving existing content.
     * Columns/rows beyond the new bounds are DISCARDED — dropped rows are
     * not fed to the scrollback ring (the emulator's resizeBuffers keeps the
     * ring separately; a resize is a viewport change, not a scroll). The
     * whole new grid is marked dirty so a delta renderer repaints every cell
     * instead of trusting stale coordinates.
     *
     * Wide-cell invariant: a wide base whose continuation column falls past
     * the width cut is erased at the boundary (it cannot occupy two columns
     * in a one-column space), so no row ever ends with a lone head and
     * {@see \SugarCraft\Sprinkles\Width::of} on a rendered line never
     * exceeds the new width.
     */
    public function resize(int $cols, int $rows): self
    {
        if ($cols < 1 || $rows < 1) {
            throw new \InvalidArgumentException(
                "Buffer dimensions must be >= 1x1, got {$cols}x{$rows}"
            );
        }

        $clone = new self($cols, $rows);

        $maxRows = min($this->rows, $rows);
        $maxCols = min($this->cols, $cols);

        for ($r = 0; $r < $maxRows; $r++) {
            for ($c = 0; $c < $maxCols; $c++) {
                $clone->grid[$r][$c] = $this->grid[$r][$c];
            }
            // Width shrink: if the first dropped column held a wide
            // continuation, its base now sits in the last kept column
            // without a partner — erase the orphaned head.
            if ($cols < $this->cols
                && isset($this->grid[$r][$cols])
                && $this->grid[$r][$cols]->continuation
                && !$this->grid[$r][$cols - 1]->continuation
            ) {
                $clone->grid[$r][$cols - 1] = Cell::empty();
            }
        }

        // Full-repaint signal: coordinates shifted, content was cut or the
        // tail was blanked — an incremental renderer must redraw everything.
        $clone->minRow = 0;
        $clone->maxRow = $rows - 1;
        $clone->minCol = 0;
        $clone->maxCol = $cols - 1;

        return $clone;
    }

    /**
     * Widen the closed column span `[start, end]` on `$row` so it never
     * cuts a wide glyph in half: a span starting on a pair's continuation
     * pulls left over its base; a span ending on a base whose tail sits one
     * column right extends over the tail. This is THE erase-widening rule
     * shared by both engines (vterm-13, ECMA-48/VT510 "double-width
     * characters are treated as one unit" under ED/EL/ECH and the
     * pair-atomic DCH/ICH shifts); the print path's outside-half clearing
     * (ScreenHandler::eraseWidePartners) stays its own loop because it acts
     * per written column rather than on a span.
     *
     * @return array{int, int}
     */
    public function widenPairSpan(int $row, int $start, int $end): array
    {
        if ($start > $end) {
            return [$start, $end];
        }
        if ($start > 0 && $this->grid[$row][$start]->continuation) {
            $start--;
        }
        if (
            $end + 1 < $this->cols
            && !$this->grid[$row][$end]->continuation
            && $this->grid[$row][$end + 1]->continuation
        ) {
            $end++;
        }
        return [$start, $end];
    }

    /** A fresh empty grid of the same dimensions; this instance is untouched. */
    public function clear(): self
    {
        return new self($this->cols, $this->rows);
    }

    public function cell(int $row, int $col): Cell
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return Cell::empty();
        }
        return $this->grid[$row][$col];
    }

    /**
     * Write a cell at the given position and extend the dirty bounding box.
     * Out-of-bounds coordinates are ignored silently.
     */
    public function put(int $row, int $col, Cell $cell): void
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return;
        }
        $this->grid[$row][$col] = $cell;
        $this->minRow = min($this->minRow, $row);
        $this->maxRow = max($this->maxRow, $row);
        $this->minCol = min($this->minCol, $col);
        $this->maxCol = max($this->maxCol, $col);
    }

    /**
     * Insert $count blank rows at row $from, pushing the rows below it DOWN
     * within the region [$top, $bottom]; nothing above $from moves and
     * content overflowing $bottom is dropped (region-internal edit — the
     * callers that model scrollback own ring feeding, not the grid).
     *
     * Faithful extraction of the IL walk that {@see
     * \SugarCraft\Vterm\Handler\ScrollHandler::insertLines()} and the renderer's
     * CsiHandlerImpl::il() each carried inline (extracted so both engines
     * share one walk). Guards kept
     * verbatim from those walks: no-op when $from lies outside the region;
     * $count coerced to at least 1 and clamped to the rows from $from down
     * ($count <= 0 therefore shifts exactly ONE row — public CSI entry
     * points floor explicit 0 earlier, at the dispatch layer). Blank fill
     * is plain {@see Cell::empty()}, no pen.
     *
     * The walk itself is a ROW SPLICE, not a per-cell copy loop: every
     * cell of a shifted region moves in one array operation and the dirty
     * box extends once. Per-cell replay re-extended the box cols times per
     * row and made long scrolls churn (a throughput floor test keeps the
     * splice shape). Grid content is identical either way — the pins are
     * unchanged.
     *
     * Buffer holds no cursor or DECSTBM state, so the region arrives as
     * parameters; the defaults ([0, rows-1] anchored at row 0) make the
     * bare `insertRows($count)` call a full-grid down-scroll.
     *
     * Mirrors charmbracelet/x/vt `Screen.InsertLine` (handlers.go IL).
     *
     * @see ECMA-48 §8.4.15 (IL)
     * @see https://vt100.net/docs/vt510-rm/IL.html
     */
    public function insertRows(int $count, int $from = 0, ?int $top = null, ?int $bottom = null): void
    {
        [$top, $bottom] = $this->clampRegion($top, $bottom);
        if ($from < $top || $from > $bottom) {
            return;
        }
        $height = $bottom - $from + 1;
        $shift = min(max(1, $count), $height);
        $region = array_splice($this->grid, $from, $height);
        // Blanks join the top of the region; the same number of the
        // original rows overflow past $bottom and are dropped.
        array_splice($region, 0, 0, array_fill(0, $shift, $this->blankRow()));
        array_splice($region, $height);
        array_splice($this->grid, $from, 0, $region);
        $this->markRowsDirty($from, $bottom);
    }

    /**
     * Delete $count rows at row $from, pulling the rows below it UP within
     * the region [$top, $bottom] and leaving blanks at the region bottom.
     *
     * Faithful extraction of the DL walk — same guard roster as {@see
     * self::insertRows()} (region test, one-row floor on $count, region
     * clamp), and the same row-splice shape: the shift moves whole rows
     * and the dirty box extends once. Rows above $from are never touched.
     *
     * Mirrors charmbracelet/x/vt `Screen.DeleteLine` (handlers.go DL).
     *
     * @see ECMA-48 §8.4.10 (DL)
     * @see https://vt100.net/docs/vt510-rm/DL.html
     */
    public function deleteRows(int $count, int $from = 0, ?int $top = null, ?int $bottom = null): void
    {
        [$top, $bottom] = $this->clampRegion($top, $bottom);
        if ($from < $top || $from > $bottom) {
            return;
        }
        $height = $bottom - $from + 1;
        $shift = min(max(1, $count), $height);
        $region = array_splice($this->grid, $from, $height);
        // The first $shift rows fall off the top of the region; blanks
        // refill the same number at its bottom.
        array_splice($region, 0, $shift);
        array_splice($region, $height - $shift, 0, array_fill(0, $shift, $this->blankRow()));
        array_splice($this->grid, $from, 0, $region);
        $this->markRowsDirty($from, $bottom);
    }

    /**
     * Snap a requested scroll region into the grid. Callers pass DECSTBM
     * values already inside the viewport; clamping here keeps a stray
     * out-of-range region from splicing past either edge.
     *
     * @return array{0: int, 1: int}
     */
    private function clampRegion(?int $top, ?int $bottom): array
    {
        return [
            max(0, $top ?? 0),
            min($bottom ?? $this->rows - 1, $this->rows - 1),
        ];
    }

    /**
     * A full row of plain empty cells (no pen) — the fill both row shifts
     * land in the freed space.
     *
     * @return list<Cell>
     */
    private function blankRow(): array
    {
        return array_fill(0, $this->cols, Cell::empty());
    }

    /**
     * Extend the dirty box over every column of [$fromRow, $toRow] in one
     * step (the row-level counterpart of put()'s per-cell tracking).
     */
    private function markRowsDirty(int $fromRow, int $toRow): void
    {
        $this->minRow = min($this->minRow, $fromRow);
        $this->maxRow = max($this->maxRow, $toRow);
        $this->minCol = min($this->minCol, 0);
        $this->maxCol = max($this->maxCol, $this->cols - 1);
    }

    /**
     * Iterate all cells in row-major order.
     *
     * @return \Generator<array{row:int, col:int, cell:Cell}>
     */
    public function each(): \Generator
    {
        for ($r = 0; $r < $this->rows; $r++) {
            for ($c = 0; $c < $this->cols; $c++) {
                yield ['row' => $r, 'col' => $c, 'cell' => $this->grid[$r][$c]];
            }
        }
    }

    /**
     * Cell-for-cell equality over the whole grid (dimensions must match).
     */
    public function equals(self $other): bool
    {
        if ($this->cols !== $other->cols || $this->rows !== $other->rows) {
            return false;
        }
        for ($r = 0; $r < $this->rows; $r++) {
            for ($c = 0; $c < $this->cols; $c++) {
                if (!$this->grid[$r][$c]->equals($other->grid[$r][$c])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Copy the entire grid for snapshotting.
     *
     * @return array<int, array<int, Cell>>
     */
    public function copy(): array
    {
        return array_map(fn (array $row) => array_map(fn (Cell $c) => $c, $row), $this->grid);
    }
}
