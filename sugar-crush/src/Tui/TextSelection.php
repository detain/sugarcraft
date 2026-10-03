<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Selection;
use SugarCraft\Mouse\SelectionRange;
use SugarCraft\Sprinkles\Style;

/**
 * A mouse text selection over one painted frame: press anchors it, motion
 * moves its head, release copies what it covers.
 *
 * With SGR mouse tracking on, the terminal hands every press/drag/release to
 * the application instead of running its own copy-on-select, so an app that
 * wants the gesture every terminal user expects has to own it — the way tmux
 * copy-mode, crush and opencode do.
 *
 * THE GEOMETRY LIVES IN CANDY-MOUSE. This class was upstreamed as
 * {@see Selection} (the press → drag state machine, region clamp included)
 * and {@see SelectionRange} (the reading-order span and the text extraction);
 * it is now the immutable adapter sugar-crush's update loop needs over them:
 * candy-mouse's machine is deliberately mutable, while a Chat rebuilds its
 * gesture state per event, so every step here works on a CLONE of the
 * machine and the instance it came from never changes. What stays local is
 * what candy-mouse leaves to the app: the drift-latched {@see $dragging}
 * flag (candy-mouse's own `dragging()` is geometric — a there-and-back sweep
 * un-drags — while crush latches past its click tolerance), the settled /
 * copied-count record of the release, and the reverse-video repaint.
 *
 * STREAM, NOT BLOCK, SELECTION. The first row runs from the anchor to the
 * region's right edge, middle rows are whole, the last row stops at the head
 * — reading order, the shape a drag across wrapped prose means. Both ends are
 * INCLUSIVE, so the cell under the pointer is always part of what is copied.
 *
 * CLAMPED TO A REGION, not to the frame. The frame a chat paints carries box
 * borders and padding around the transcript text; a whole-row selection that
 * ran to the frame edge would copy `│  ` before every line. The region is the
 * text column itself ({@see \SugarCraft\Crush\Renderer::selectableRegion()}),
 * so a drag that strays past it copies text, never chrome — and the leading
 * spaces INSIDE it (code indentation) survive, because only the chrome is
 * outside the region.
 *
 * Coordinates are 1-based terminal cells, the space candy-mouse's zones and
 * SGR mouse reports speak, in the frame the region was measured on (the
 * hosted chat's own frame; the shell rebases the pointer into it first via
 * the zone origin). Frame line R is `$lines[R - 1]`.
 */
final class TextSelection
{
    private function __construct(
        /** The candy-mouse gesture this snapshot froze; never mutated — every step clones it. */
        private readonly Selection $gesture,
        public readonly int $anchorCol,
        public readonly int $anchorRow,
        public readonly int $headCol,
        public readonly int $headRow,
        /** @var array{0:int,1:int,2:int,3:int} rowFrom, rowTo, colFrom, colTo — all inclusive, 1-based */
        public readonly array $region,
        /** True once the pointer has moved far enough that this is a drag, not a click. */
        public readonly bool $dragging = false,
        /** True once the button was released: the highlight stays, the text was copied. */
        public readonly bool $settled = false,
        /** Characters the release copied (0 until settled). */
        public readonly int $copiedChars = 0,
    ) {
    }

    /**
     * Anchor a selection at a pressed cell, or null when the press is outside
     * the selectable region — a press on chrome selects nothing.
     *
     * @param array{0:int,1:int,2:int,3:int} $region rowFrom, rowTo, colFrom, colTo (inclusive, 1-based)
     */
    public static function at(int $col, int $row, array $region): ?self
    {
        [$rowFrom, $rowTo, $colFrom, $colTo] = $region;

        $gesture = Selection::new($rowFrom, $rowTo, $colFrom, $colTo);
        if (!$gesture->begin($row, $col)) {
            return null;
        }

        return new self($gesture, $col, $row, $col, $row, $region);
    }

    /**
     * Move the head to the pointer, clamped into the region.
     *
     * A pointer above the region pins the head to the region's first cell and
     * one below pins it to the last, so dragging off the top or bottom selects
     * "everything from here to the edge" instead of stopping short
     * ({@see Selection::dragTo()}).
     */
    public function withHead(int $col, int $row): self
    {
        $gesture = clone $this->gesture;
        $gesture->dragTo($row, $col);
        $range = self::rangeOf($gesture);

        // candy-mouse reports the covered cells in reading order, not which
        // end is the head; the anchor never moves, so the head is whichever
        // end the anchor is not.
        $anchorFirst = $range->startRow === $this->anchorRow && $range->startCol === $this->anchorCol;
        [$headRow, $headCol] = $anchorFirst
            ? [$range->endRow, $range->endCol]
            : [$range->startRow, $range->startCol];

        return $this->mutate(gesture: $gesture, headCol: $headCol, headRow: $headRow);
    }

    public function withDragging(bool $dragging = true): self
    {
        return $this->mutate(dragging: $dragging);
    }

    public function withSettled(int $copiedChars): self
    {
        return $this->mutate(settled: true, copiedChars: max(0, $copiedChars));
    }

    /**
     * The covered cells as candy-mouse's frozen, reading-order range.
     */
    public function range(): SelectionRange
    {
        return self::rangeOf($this->gesture);
    }

    /**
     * The inclusive cell range this selection covers on $row, or null when
     * the row is outside it.
     *
     * @return array{0:int,1:int}|null
     */
    public function span(int $row): ?array
    {
        return $this->range()->spanOnRow($row);
    }

    /**
     * The text under the selection, read from the frame's lines.
     *
     * Escapes are stripped, trailing padding is trimmed from every row, and
     * blank rows at either end are dropped (a drag that started in a
     * transcript's padding row should not copy a leading newline). Rows are
     * joined with `\n`: the frame does not record which breaks were soft
     * wraps, so like every screen-scraping copy (tmux, a terminal's own
     * selection over a TUI) a wrapped paragraph comes back as its rows
     * ({@see SelectionRange::extract()}).
     *
     * @param list<string> $lines the painted frame; row R is `$lines[R - 1]`
     */
    public function extract(array $lines): string
    {
        return $this->range()->extract($lines);
    }

    /**
     * $lines with the selected cells repainted in reverse video.
     *
     * The covered run is repainted from its PLAIN text so the highlight reads
     * the same over every colour the transcript uses; the cells either side
     * keep their own styling (the same head/patch/tail splice the shell's
     * drop-target outline uses). A row shorter than its span is padded, so a
     * selection through a blank row still shows as one continuous band.
     *
     * @param list<string> $lines the painted frame; row R is `$lines[R - 1]`
     * @return list<string>
     */
    public function highlight(array $lines, Style $style): array
    {
        $range = $this->range();

        for ($row = $range->startRow; $row <= $range->endRow; $row++) {
            $index = $row - 1;
            if (!isset($lines[$index])) {
                continue;
            }

            [$from, $to] = $range->spanOnRow($row) ?? [1, 0];
            $cells = $to - $from + 1;
            if ($cells <= 0) {
                continue;
            }

            // Cells left of the span: a 1-based column $from has $from - 1 before it.
            $before = $from - 1;
            $line = $lines[$index];
            $head = Width::truncateAnsi($line, $before);
            $pad = str_repeat(' ', max(0, $before - Width::string($head)));
            $run = Ansi::strip(Width::takeAnsi(Width::dropAnsi($line, $before), $cells));
            $run .= str_repeat(' ', max(0, $cells - Width::string($run)));

            $lines[$index] = $head . Ansi::reset() . $pad . $style->render($run) . Ansi::reset() . Width::dropAnsi($line, $before + $cells);
        }

        return $lines;
    }

    /**
     * The range of a gesture this class anchored. Every gesture held here
     * went through an accepted {@see Selection::begin()}, so it always has
     * one; a null would mean that invariant broke, and is loud.
     */
    private static function rangeOf(Selection $gesture): SelectionRange
    {
        $range = $gesture->range();
        if ($range === null) {
            throw new \LogicException('TextSelection holds a gesture with no anchor.');
        }

        return $range;
    }

    private function mutate(
        ?Selection $gesture = null,
        ?int $headCol = null,
        ?int $headRow = null,
        ?bool $dragging = null,
        ?bool $settled = null,
        ?int $copiedChars = null,
    ): self {
        return new self(
            $gesture ?? $this->gesture,
            $this->anchorCol,
            $this->anchorRow,
            $headCol ?? $this->headCol,
            $headRow ?? $this->headRow,
            $this->region,
            $dragging ?? $this->dragging,
            $settled ?? $this->settled,
            $copiedChars ?? $this->copiedChars,
        );
    }
}
