<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Screen;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;

/**
 * Readonly snapshot of the terminal grid.
 *
 * Constructed from a Buffer copy. Screen instances are always immutable.
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
    ) {}

    /**
     * Build a Screen snapshot from the current Buffer state.
     */
    public static function fromBuffer(Buffer $buf, ?Scrollback $scrollback = null): self
    {
        return new self($buf->copy(), $buf->cols, $buf->rows, $scrollback);
    }

    /**
     * Return the scrollback buffer holding rows that have scrolled off-screen.
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
     * @return \Generator<int, string>
     */
    public function lines(): \Generator
    {
        for ($r = 0; $r < $this->rows; $r++) {
            $line = '';
            for ($c = 0; $c < $this->cols; $c++) {
                $cell = $this->grid[$r][$c] ?? Cell::empty();
                if (!$cell->continuation) {
                    $line .= $cell->grapheme;
                }
            }
            yield $r => $line;
        }
    }
}
