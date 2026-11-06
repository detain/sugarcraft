<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Screen;

use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cell;

/**
 * Readonly snapshot of the terminal grid, optionally carrying the
 * scrollback ring that holds the scrolled-off rows.
 *
 * Constructed from a Buffer copy AND a cloned ring — {@see self::fromBuffer()}
 * freezes both surfaces, so later emulator scrolling never retro-mutates a
 * snapshot. Screen instances are always immutable.
 * Mirrors charmbracelet/x/vt Screen.
 */
final readonly class Screen
{
    /** @param array<int, array<int, Cell>> */
    public function __construct(
        private array $grid,
        public readonly int $cols,
        public readonly int $rows,
        private ?Scrollback $scrollback = null,
    ) {
    }

    /**
     * Build a Screen snapshot from the current Buffer state.
     *
     * The ring is CLONED, not stored by reference: a Screen captured at
     * time T must keep showing the scrollback as it was at T, and the
     * emulator keeps pushing into its live ring on every later scroll.
     * PHP clone copies the scalar/readonly fields and detaches the
     * `$rows` array via copy-on-write, freezing head/tail/count — the
     * Cell objects inside ride the same value-equality law the grid copy
     * already relies on (mutate-style APIs hand back new instances).
     */
    public static function fromBuffer(Buffer $buf, ?Scrollback $scrollback = null): self
    {
        return new self($buf->copy(), $buf->cols, $buf->rows, $scrollback === null ? null : clone $scrollback);
    }

    /**
     * Return the scrollback buffer holding rows that have scrolled off-screen.
     * Null when this screen was composed via {@see self::fromBuffer()} without a
     * ring; the full `Terminal\Terminal::screen()` path always wires one.
     */
    public function scrollback(): ?Scrollback
    {
        return $this->scrollback;
    }

    public function cell(int $row, int $col): Cell
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return Cell::empty();
        }
        return $this->grid[$row][$col];
    }

    /**
     * Compare two screens and return only the changed cells.
     *
     * @return array<array{row:int,col:int,prev:Cell,next:Cell}>
     */
    public function diff(self $other): array
    {
        $changes = [];
        $maxRows = max($this->rows, $other->rows);
        $maxCols = max($this->cols, $other->cols);

        for ($r = 0; $r < $maxRows; $r++) {
            for ($c = 0; $c < $maxCols; $c++) {
                $a = $this->cell($r, $c);
                $b = $other->cell($r, $c);
                if (!$a->equals($b)) {
                    $changes[] = ['row' => $r, 'col' => $c, 'prev' => $a, 'next' => $b];
                }
            }
        }

        return $changes;
    }

    /**
     * Iterate rows as grapheme strings (continuation cells skipped).
     *
     * Combining marks the parser attached to a base cell ride the cell's
     * `combining` payload and are re-appended here, so `e` + U+0301
     * round-trips as the two-codepoint string rather than a bare `e`.
     * Marks that land on a wide glyph's continuation tail are dropped at
     * PRINT time (the tail has no base to carry them) — this channel
     * cannot recover what the screen never stored. Rows are padded to the
     * full screen width (blank cells yield their ' '), and text written
     * while concealed (SGR 8) still appears: this is a TEXT channel, not
     * a visibility model.
     *
     * The return value is a Generator — iterate it or
     * `iterator_to_array($screen->lines())`; `count()` and `[0]` on the
     * generator itself are fatal by design.
     *
     * @return \Generator<int, string>
     */
    public function lines(): \Generator
    {
        for ($r = 0; $r < $this->rows; $r++) {
            yield $r => self::lineFromRow($this->grid[$r] ?? [], $this->cols);
        }
    }

    /**
     * Text extraction across the scrollback ring AND the visible screen:
     * rows pushed off the top first (oldest to newest), then every visible
     * line. Keys run 0..n continuously. With no ring attached — or nothing
     * ever scrolled — this yields exactly {@see self::lines()}.
     *
     * @return \Generator<int, string>
     */
    public function linesWithHistory(): \Generator
    {
        $key = 0;
        if ($this->scrollback !== null) {
            foreach ($this->scrollback->all() as $row) {
                yield $key++ => self::lineFromRow($row, $this->cols);
            }
        }
        for ($r = 0; $r < $this->rows; $r++) {
            yield $key++ => self::lineFromRow($this->grid[$r] ?? [], $this->cols);
        }
    }

    /**
     * Shared row → text builder for both extraction channels: skip
     * continuation tails, emit base grapheme + stored combining marks,
     * pad to the screen width.
     *
     * @param array<int, Cell> $row
     */
    private static function lineFromRow(array $row, int $cols): string
    {
        $line = '';
        for ($c = 0; $c < $cols; $c++) {
            $cell = $row[$c] ?? Cell::empty();
            if (!$cell->continuation) {
                $line .= $cell->grapheme . $cell->combining;
            }
        }
        return $line;
    }
}
