<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;

/**
 * A mouse text selection over one painted frame: press anchors it, motion
 * moves its head, release copies what it covers.
 *
 * With SGR mouse tracking on, the terminal hands every press/drag/release to
 * the application instead of running its own copy-on-select, so an app that
 * wants the gesture every terminal user expects has to own it — the way tmux
 * copy-mode, crush and opencode do. This is that gesture's geometry, kept
 * free of any model so it can be tested cell by cell.
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
 * Coordinates are 0-based cells in the coordinate space of the frame the
 * region was measured on (the hosted chat's own frame; the shell rebases the
 * pointer into it first).
 */
final class TextSelection
{
    private function __construct(
        public readonly int $anchorCol,
        public readonly int $anchorRow,
        public readonly int $headCol,
        public readonly int $headRow,
        /** @var array{0:int,1:int,2:int,3:int} rowFrom, rowTo, colFrom, colTo — all inclusive */
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
     * @param array{0:int,1:int,2:int,3:int} $region rowFrom, rowTo, colFrom, colTo (inclusive)
     */
    public static function at(int $col, int $row, array $region): ?self
    {
        [$rowFrom, $rowTo, $colFrom, $colTo] = $region;

        if ($rowTo < $rowFrom || $colTo < $colFrom) {
            return null;
        }

        if ($row < $rowFrom || $row > $rowTo || $col < $colFrom || $col > $colTo) {
            return null;
        }

        return new self($col, $row, $col, $row, $region);
    }

    /**
     * Move the head to the pointer, clamped into the region.
     *
     * A pointer above the region pins the head to the region's first cell and
     * one below pins it to the last, so dragging off the top or bottom selects
     * "everything from here to the edge" instead of stopping short.
     */
    public function withHead(int $col, int $row): self
    {
        [$rowFrom, $rowTo, $colFrom, $colTo] = $this->region;

        if ($row < $rowFrom) {
            [$row, $col] = [$rowFrom, $colFrom];
        } elseif ($row > $rowTo) {
            [$row, $col] = [$rowTo, $colTo];
        }

        return $this->mutate(headCol: max($colFrom, min($colTo, $col)), headRow: $row);
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
     * The inclusive cell range this selection covers on $row, or null when
     * the row is outside it.
     *
     * @return array{0:int,1:int}|null
     */
    public function span(int $row): ?array
    {
        [$startRow, $startCol, $endRow, $endCol] = $this->ordered();

        if ($row < $startRow || $row > $endRow) {
            return null;
        }

        [, , $colFrom, $colTo] = $this->region;

        return [
            $row === $startRow ? $startCol : $colFrom,
            $row === $endRow ? $endCol : $colTo,
        ];
    }

    /**
     * The text under the selection, read from the frame's lines.
     *
     * Escapes are stripped, trailing padding is trimmed from every row, and
     * blank rows at either end are dropped (a drag that started in a
     * transcript's padding row should not copy a leading newline). Rows are
     * joined with `\n`: the frame does not record which breaks were soft
     * wraps, so like every screen-scraping copy (tmux, a terminal's own
     * selection over a TUI) a wrapped paragraph comes back as its rows.
     *
     * @param list<string> $lines
     */
    public function extract(array $lines): string
    {
        [$startRow, , $endRow] = $this->ordered();
        $rows = [];

        for ($row = $startRow; $row <= $endRow; $row++) {
            [$from, $to] = $this->span($row) ?? [0, -1];
            $plain = Ansi::strip($lines[$row] ?? '');
            $rows[] = rtrim(Width::takeAnsi(Width::dropAnsi($plain, $from), $to - $from + 1), " \t");
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
     * $lines with the selected cells repainted in reverse video.
     *
     * The covered run is repainted from its PLAIN text so the highlight reads
     * the same over every colour the transcript uses; the cells either side
     * keep their own styling (the same head/patch/tail splice the shell's
     * drop-target outline uses). A row shorter than its span is padded, so a
     * selection through a blank row still shows as one continuous band.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public function highlight(array $lines, Style $style): array
    {
        [$startRow, , $endRow] = $this->ordered();

        for ($row = $startRow; $row <= $endRow; $row++) {
            if (!isset($lines[$row])) {
                continue;
            }

            [$from, $to] = $this->span($row) ?? [0, -1];
            $cells = $to - $from + 1;
            if ($cells <= 0) {
                continue;
            }

            $line = $lines[$row];
            $head = Width::truncateAnsi($line, $from);
            $pad = str_repeat(' ', max(0, $from - Width::string($head)));
            $run = Ansi::strip(Width::takeAnsi(Width::dropAnsi($line, $from), $cells));
            $run .= str_repeat(' ', max(0, $cells - Width::string($run)));

            $lines[$row] = $head . Ansi::reset() . $pad . $style->render($run) . Ansi::reset() . Width::dropAnsi($line, $from + $cells);
        }

        return $lines;
    }

    /**
     * Anchor and head in reading order.
     *
     * @return array{0:int,1:int,2:int,3:int} startRow, startCol, endRow, endCol
     */
    private function ordered(): array
    {
        $headFirst = $this->headRow < $this->anchorRow
            || ($this->headRow === $this->anchorRow && $this->headCol < $this->anchorCol);

        return $headFirst
            ? [$this->headRow, $this->headCol, $this->anchorRow, $this->anchorCol]
            : [$this->anchorRow, $this->anchorCol, $this->headRow, $this->headCol];
    }

    private function mutate(
        ?int $headCol = null,
        ?int $headRow = null,
        ?bool $dragging = null,
        ?bool $settled = null,
        ?int $copiedChars = null,
    ): self {
        return new self(
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
