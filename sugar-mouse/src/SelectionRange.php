<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * A normalized, inclusive range of cells a mouse text selection covers over a
 * painted frame — the frozen product of a {@see Selection} gesture.
 *
 * Ported upstream from candy-code's `Tui\TextSelection`. The mode framing
 * and gesture story are told once in {@see Selection}'s class docblock;
 * this class is that gesture's geometry and nothing else: no model, no
 * clipboard, no painting.
 *
 * STREAM, NOT BLOCK. The first row runs from the start cell to the text
 * column's right edge, middle rows are whole, the last row stops at the end
 * cell — reading order, the shape a drag across wrapped prose means. Both
 * ends are INCLUSIVE, so the cell under the pointer is always part of what a
 * copy takes. {@see spanOnRow()} is the single source of truth for that
 * shape; {@see cellContains()} and {@see cells()} read through it, so the
 * covered set can never disagree with itself.
 *
 * Coordinates are 1-based terminal cells — the same space {@see Zone}
 * bounding boxes and {@see MouseEvent::$x} use, which is the space SGR mouse
 * reports arrive in, so an app wires its pointer straight in with no
 * rebasing. (Downstream candy-code once indexed frame cells from 0; its
 * `Tui\TextSelection` now runs on this class with 1-based cells end to end.)
 */
final class SelectionRange
{
    private function __construct(
        public readonly int $startRow,
        public readonly int $startCol,
        public readonly int $endRow,
        public readonly int $endCol,
        /** Leftmost text column of the selectable region: middle and last rows fill from here. */
        public readonly int $edgeFrom,
        /** Rightmost text column of the selectable region: first and middle rows run to here. */
        public readonly int $edgeTo,
    ) {}

    /**
     * Normalize two cells into a reading-order range.
     *
     * The two endpoints are interchangeable — a backward drag covers exactly
     * what the same cells covered dragged forward — so ordering is derived
     * here once, at the boundary, and every reader below trusts it
     * (Parse, Don't Validate).
     *
     * The edges default to the endpoints' own column extent, which makes a
     * directly constructed range a plain bounding-box band; a range from
     * {@see Selection::range()} arrives with the region's text column
     * instead, so its middle rows sweep whole.
     *
     * @param int      $rowA    row of the first cell (e.g. the gesture's anchor)
     * @param int      $colA    column of the first cell
     * @param int      $rowB    row of the second cell (e.g. the gesture's head)
     * @param int      $colB    column of the second cell
     * @param int|null $edgeFrom left edge of the region's text column; null defaults to the left endpoint,
     *                           and an explicit edge crossing right of the end cell is clamped to it
     * @param int|null $edgeTo   right edge of the region's text column; null defaults to the right endpoint,
     *                           and an explicit edge crossing left of the start cell is clamped to it
     *
     * @throws \InvalidArgumentException when any coordinate is off-terminal (< 1)
     */
    public static function new(int $rowA, int $colA, int $rowB, int $colB, ?int $edgeFrom = null, ?int $edgeTo = null): self
    {
        self::refuseOffTerminalCells($rowA, $colA, $rowB, $colB, $edgeFrom, $edgeTo);

        $headFirst = $rowB < $rowA || ($rowB === $rowA && $colB < $colA);
        [$startRow, $startCol, $endRow, $endCol] = $headFirst
            ? [$rowB, $colB, $rowA, $colA]
            : [$rowA, $colA, $rowB, $colB];

        $low  = min($edgeFrom ?? min($colA, $colB), $edgeTo ?? max($colA, $colB));
        $high = max($edgeFrom ?? min($colA, $colB), $edgeTo ?? max($colA, $colB));

        // The envelope: edges may WIDEN the region (that is what {@see Selection::range()}
        // passes, so its middle rows sweep whole) but no edge may cross inside its own
        // row's endpoint — an $edgeTo left of the start cell would invert the first row's
        // span [startCol, edgeTo] and silently drop the very cell the pointer is on
        // (extract() would slice it to a negative width). Fail-safe: clamp the edge to
        // the endpoint it crossed; a cell the drag touched is always copied.
        return new self($startRow, $startCol, $endRow, $endCol, min($low, $endCol), max($high, $startCol));
    }

    /**
     * The inclusive [fromCol, toCol] this range covers on $row, or null when
     * $row lies outside it.
     *
     * @return array{0:int,1:int}|null
     */
    public function spanOnRow(int $row): ?array
    {
        if ($row < $this->startRow || $row > $this->endRow) {
            return null;
        }

        return [
            $row === $this->startRow ? $this->startCol : $this->edgeFrom,
            $row === $this->endRow ? $this->endCol : $this->edgeTo,
        ];
    }

    /**
     * True when $row/$col is covered by the selection — by the STREAM shape,
     * not merely by the bounding rectangle. A cell on the first row left of
     * the start (or on the last row right of the end) sits inside the bbox
     * yet was never swept, and copying or highlighting it would be a lie.
     */
    public function cellContains(int $row, int $col): bool
    {
        $span = $this->spanOnRow($row);

        return $span !== null && $col >= $span[0] && $col <= $span[1];
    }

    /**
     * Every covered cell in reading order.
     *
     * @return \Generator<array{0:int,1:int}> sequence of [row, col] pairs
     */
    public function cells(): \Generator
    {
        for ($row = $this->startRow; $row <= $this->endRow; $row++) {
            [$from, $to] = $this->spanOnRow($row) ?? [1, 0];

            for ($col = $from; $col <= $to; $col++) {
                yield [$row, $col];
            }
        }
    }

    /**
     * The text this range covers, read from the frame's lines.
     *
     * Ported from candy-code's release half: escapes are stripped,
     * trailing padding is trimmed from every row, and blank rows at either
     * end are dropped — a drag that started in a transcript's padding row
     * must not copy a leading newline. Rows join with "\n": the frame does
     * not record which breaks were soft wraps, so like every screen-scraping
     * copy (tmux, a terminal's own selection over a TUI) a wrapped paragraph
     * comes back as its rows. Because every row is cut to the region's text
     * column, a selection copies text, never chrome — while the leading
     * spaces INSIDE the region (code indentation) survive.
     *
     * @param list<string> $lines the painted frame; row R is $lines[R - 1]
     */
    public function extract(array $lines): string
    {
        $rows = [];

        for ($row = $this->startRow; $row <= $this->endRow; $row++) {
            [$from, $to] = $this->spanOnRow($row) ?? [1, 0];
            $plain = Ansi::strip($lines[$row - 1] ?? '');
            $rows[] = rtrim(Width::takeAnsi(Width::dropAnsi($plain, $from - 1), $to - $from + 1), " \t");
        }

        while ($rows !== [] && trim((string) $rows[0]) === '') {
            array_shift($rows);
        }
        while ($rows !== [] && trim((string) end($rows)) === '') {
            array_pop($rows);
        }

        return implode("\n", $rows);
    }

    /**
     * Fail fast on coordinates no terminal can report: SGR cells start at 1,
     * and a 0 here would silently mis-index {@see extract()}'s lines.
     * Names the offending parameter in the message — the variadic spelling
     * this replaced lost the argument identity at the throw site.
     */
    private static function refuseOffTerminalCells(
        ?int $rowA,
        ?int $colA,
        ?int $rowB,
        ?int $colB,
        ?int $edgeFrom,
        ?int $edgeTo,
    ): void {
        foreach (
            ['rowA' => $rowA, 'colA' => $colA, 'rowB' => $rowB, 'colB' => $colB, 'edgeFrom' => $edgeFrom, 'edgeTo' => $edgeTo]
            as $param => $cell
        ) {
            if ($cell !== null && $cell < 1) {
                throw new \InvalidArgumentException(
                    Lang::t('selection.off_terminal', ['cell' => $cell, 'param' => $param])
                );
            }
        }
    }
}
