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
 * this class is that gesture's geometry plus the two frame operations every
 * consumer of it needs — {@see extract()} (the copied text) and
 * {@see highlight()} (the repainted band). No model, no clipboard.
 *
 * stream, not block. The first row runs from the start cell to the text
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
     * directly constructed range a plain bounding-box band. A range from
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
     *                           and an explicit edge crossing left of the start cell is clamped to it.
     *                           Only a pair given in reverse order is swapped; a lone edge never moves the other.
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

        // A swapped edge PAIR describes the same column, so only two explicit
        // edges are reordered. A lone explicit edge never moves the other one
        // off its endpoint default: min/max over a default would let an
        // edgeFrom right of both endpoints become the right edge.
        $low  = $edgeFrom ?? min($colA, $colB);
        $high = $edgeTo ?? max($colA, $colB);
        if ($edgeFrom !== null && $edgeTo !== null && $low > $high) {
            [$low, $high] = [$high, $low];
        }

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
            $cells = $to - $from + 1;
            // A wide glyph straddling the span's LEFT edge is dropped whole by
            // dropAnsi(); its covered half is a cell highlight() paints blank,
            // so it is not copied and its cell comes off the take budget, or
            // the copy would run one cell past the span.
            $rest = Width::dropAnsi($plain, $from - 1);
            $lead = min($cells, max(0, Width::string($plain) - ($from - 1) - Width::string($rest)));
            $rows[] = rtrim(Width::takeAnsi($rest, $cells - $lead), " \t");
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
     * $lines with the covered cells repainted — reverse video unless $paint
     * says otherwise.
     *
     * Ported from candy-code's `Tui\TextSelection::highlight()`. The covered
     * run of each row is repainted from its PLAIN text, so the highlight reads
     * the same over every color the frame uses; the cells either side keep
     * their own styling (a head / patch / tail splice, with a reset on each
     * seam so the head's open SGR cannot bleed into the patch). A row shorter
     * than its span is padded with spaces, so a selection through a blank row
     * still shows as one continuous band. Rows the range covers but $lines
     * lacks are skipped; rows outside the range are returned untouched.
     *
     * @param list<string>                 $lines the painted frame; row R is $lines[R - 1]
     * @param (\Closure(string): string)|null $paint styles one row's plain run;
     *        null paints SGR 7 (reverse) closed by SGR 27. Pass a style's
     *        renderer as a first-class callable (`$style->render(...)`) to use
     *        a theme color instead.
     * @return list<string>
     */
    public function highlight(array $lines, ?\Closure $paint = null): array
    {
        $paint ??= static fn (string $run): string => Ansi::sgr(7) . $run . Ansi::sgr(27);

        for ($row = $this->startRow; $row <= $this->endRow; $row++) {
            $index = $row - 1;
            if (!isset($lines[$index])) {
                continue;
            }

            [$from, $to] = $this->spanOnRow($row) ?? [1, 0];
            $cells = $to - $from + 1;
            if ($cells <= 0) {
                continue;
            }

            // Cells left of the span: a 1-based column $from has $from - 1 before it.
            $before = $from - 1;
            $line = $lines[$index];
            $head = Width::truncateAnsi($line, $before);
            $pad = str_repeat(' ', max(0, $before - Width::string($head)));
            // A wide glyph straddling the span's LEFT edge is dropped whole by
            // both truncateAnsi() (head) and dropAnsi() (run): $pad blanks its
            // uncovered half, and $lead blanks the covered half, so the run
            // starts on its own column and the row keeps its cell count. (On
            // the RIGHT edge takeAnsi() keeps the straddler whole and the tail's
            // dropAnsi() skips it whole, so those two already meet, the same
            // glyph extract() copies; extract() skips the left straddler too.)
            $rest = Width::dropAnsi($line, $before);
            $lead = min($cells, max(0, Width::string($line) - $before - Width::string($rest)));
            $run = str_repeat(' ', $lead) . Ansi::strip(Width::takeAnsi($rest, $cells - $lead));
            $run .= str_repeat(' ', max(0, $cells - Width::string($run)));

            $lines[$index] = $head . Ansi::reset() . $pad . $paint($run) . Ansi::reset()
                . Width::dropAnsi($line, $before + $cells);
        }

        return $lines;
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
