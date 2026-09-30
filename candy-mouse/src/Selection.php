<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * The press → drag → release state machine of a mouse text-selection
 * gesture, clamped to a selectable region of the painted frame.
 *
 * Ported upstream from sugar-crush's `Tui\TextSelection` (scout item S12).
 * With SGR mouse tracking on, the terminal hands every press/drag/release to
 * the application instead of running its own copy-on-select, so an app that
 * wants the gesture every terminal user expects has to own it — the way tmux
 * copy-mode, crush and opencode do. sugar-crush grew this as an immutable
 * value object rebuilt per event; upstreamed, it is one small mutable
 * machine — the same deliberate exception to the fluent convention
 * {@see ZoneClickTracker} already makes in this lib: a gesture IS live
 * sequential state. Every read ({@see range()}) still returns a fresh
 * immutable snapshot, so a caller can hold the frozen geometry past the next
 * event.
 *
 * Coordinates are 1-based terminal cells — the space {@see Zone} and
 * {@see MouseEvent} speak, which is the space SGR reports arrive in. Feed the
 * reported cells straight in; unlike the downstream original there is no
 * rebasing to 0 and the region is just four viewport numbers, never a pane's
 * geometry.
 *
 * The release itself is NOT modelled here: copying, toasts and dismissal
 * policy belong to the app. It reads {@see range()} (or
 * {@see SelectionRange::extract()}) when the release arrives and calls
 * {@see clear()} when its own dismiss rules fire.
 */
final class Selection
{
    private ?int $anchorRow = null;
    private ?int $anchorCol = null;
    private ?int $headRow = null;
    private ?int $headCol = null;

    /**
     * A selection machine over one selectable region.
     *
     * The region is the text column a drag may cover — the caller measures
     * it (a pane's borders and padding are chrome, and a whole-row selection
     * that ran through chrome would copy `│  ` before every line). An
     * inverted pair ($rowTo < $rowFrom or $colTo < $colFrom) is an EMPTY
     * region — the viewport has no text rows yet — and every gesture attempt
     * declines. Empty is never an error, so nothing is swapped or thrown:
     * inventing selectable cells out of a degenerate viewport would be the
     * lie.
     *
     * @param int $rowFrom region's first row    (1-based, inclusive)
     * @param int $rowTo   region's last row     (1-based, inclusive)
     * @param int $colFrom region's left column  (1-based, inclusive)
     * @param int $colTo   region's right column (1-based, inclusive)
     */
    public static function new(int $rowFrom, int $rowTo, int $colFrom, int $colTo): self
    {
        // Floor into terminal space the way Scan::parse()'s viewport clamp
        // does; below-row-1 cells cannot exist, and clamping the region
        // rather than the query keeps every later comparison trusted.
        return new self(max(1, $rowFrom), max(1, $rowTo), max(1, $colFrom), max(1, $colTo));
    }

    private function __construct(
        private readonly int $rowFrom,
        private readonly int $rowTo,
        private readonly int $colFrom,
        private readonly int $colTo,
    ) {}

    /**
     * Anchor a gesture at a pressed cell.
     *
     * A press outside the region selects nothing — and, plain, also drops a
     * previous selection, the same collapse sugar-crush performs when a
     * press lands off the transcript while a copied highlight is up. With
     * $shiftExtend the previous selection survives instead: a shift-press on
     * chrome is declined, not acted on.
     *
     * @param bool $shiftExtend keep the existing anchor and move the head
     *                          (extend); with no anchor the press starts a
     *                          fresh selection like plain — extend-from-
     *                          nothing has nowhere to extend from
     */
    public function begin(int $row, int $col, bool $shiftExtend = false): bool
    {
        if (! $this->covers($row, $col)) {
            if ($shiftExtend === false) {
                $this->clear();
            }

            return false;
        }

        if ($shiftExtend === true && $this->anchorRow !== null) {
            $this->moveHead($row, $col);

            return true;
        }

        $this->anchorRow = $row;
        $this->anchorCol = $col;
        $this->moveHead($row, $col);

        return true;
    }

    /**
     * Move the head to the pointer, clamped into the region.
     *
     * A pointer above the region pins the head to the region's first cell
     * and one below pins it to the last, so dragging off the top or bottom
     * selects "everything from here to the edge" instead of stopping short;
     * the column clamps both-sided within the row. While a gesture is active
     * the anchor holds still — EVERY motion extends it — so $shiftExtend
     * only decides what a motion with no gesture behind it means: a plain
     * dragTo starts one at the pointer (some terminals report the first
     * swept cell as a drag with no preceding press), a shift-drag has
     * nothing to extend and is declined.
     */
    public function dragTo(int $row, int $col, bool $shiftExtend = false): bool
    {
        if (! $this->coversRegion()) {
            return false;
        }

        if ($this->anchorRow === null) {
            if ($shiftExtend === true) {
                return false;
            }

            [$row, $col] = $this->clamp($row, $col);
            $this->anchorRow = $row;
            $this->anchorCol = $col;
            $this->moveHead($row, $col);

            return true;
        }

        $this->moveHead(...$this->clamp($row, $col));

        return true;
    }

    /**
     * Drop the gesture: no anchor, no head. The machine returns to idle and
     * the next plain event starts fresh.
     */
    public function clear(): void
    {
        $this->anchorRow = null;
        $this->anchorCol = null;
        $this->headRow = null;
        $this->headCol = null;
    }

    /**
     * The covered cells in reading order, or null when nothing is selected.
     *
     * The snapshot normalises both drag directions to the same geometry and
     * carries the region's text column, so its middle rows sweep whole.
     */
    public function range(): ?SelectionRange
    {
        if ($this->anchorRow === null || $this->anchorCol === null || $this->headRow === null || $this->headCol === null) {
            return null;
        }

        return SelectionRange::new($this->anchorRow, $this->anchorCol, $this->headRow, $this->headCol, $this->colFrom, $this->colTo);
    }

    /**
     * True while a gesture holds an anchor — from the first accepted press
     * (or plain drag) until {@see clear()}.
     */
    public function active(): bool
    {
        return $this->anchorRow !== null;
    }

    /**
     * True once the head has left the anchor — this is a drag, not a click.
     *
     * Geometric: a drag that returns to its own anchor cell un-dragges, so a
     * there-and-back sweep releases as a click and copies nothing. The
     * downstream original latched the flag past a drift tolerance instead;
     * an app that wants latching tracks its own max-drift (sugar-crush
     * already records press drift separately from the selection) — the model
     * tells the truth about where the pointer is right now.
     */
    public function dragging(): bool
    {
        return $this->active() && ($this->headRow !== $this->anchorRow || $this->headCol !== $this->anchorCol);
    }

    /**
     * Set the head from an already-clamped cell.
     */
    private function moveHead(int $row, int $col): void
    {
        $this->headRow = $row;
        $this->headCol = $col;
    }

    private function covers(int $row, int $col): bool
    {
        return $this->coversRegion()
            && $row >= $this->rowFrom && $row <= $this->rowTo
            && $col >= $this->colFrom && $col <= $this->colTo;
    }

    private function coversRegion(): bool
    {
        return $this->rowTo >= $this->rowFrom && $this->colTo >= $this->colFrom;
    }

    /**
     * Pin a pointer into the region: rows collapse to the first/last cell
     * whole, columns clamp to the region's text column.
     *
     * @return array{0:int,1:int} [row, col]
     */
    private function clamp(int $row, int $col): array
    {
        if ($row < $this->rowFrom) {
            return [$this->rowFrom, $this->colFrom];
        }

        if ($row > $this->rowTo) {
            return [$this->rowTo, $this->colTo];
        }

        // The both-sided clamp shape Scan::parse() settled on: max/min around
        // the live bound, so an out-of-range pointer collapses monotonically
        // onto the edge instead of inverting anything.
        return [$row, max($this->colFrom, min($this->colTo, $col))];
    }
}
