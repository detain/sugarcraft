<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

use SugarCraft\Core\Util\Width;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Theme;

/**
 * CSI handler for the vcr renderer path.
 *
 * Mutates CellGrid + Cursor directly in response to CSI dispatches.
 *
 * Mirrors charmbracelet/x/vt CSI dispatcher (simplified for renderer use).
 */
final class CsiHandlerImpl implements CsiHandler
{
    private int $scrollTop = 0;
    private int $scrollBottom;

    private int $fg;
    private int $bg;
    private int $attrs = 0;

    public function __construct(
        private CellGrid $grid,
        private Cursor $cursor,
        private Theme $theme,
    ) {
        $this->fg = $theme->defaultFg;
        $this->bg = $theme->defaultBg;
        $this->scrollBottom = $grid->rows - 1;
    }

    public function grid(): CellGrid
    {
        return $this->grid;
    }

    public function cursor(): Cursor
    {
        return $this->cursor;
    }

    public function printable(string $grapheme): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;

        $width = Width::string($grapheme);

        // Combining mark (width 0): attach to the previous cell if present.
        if ($width <= 0) {
            if ($col > 0) {
                $prev = $this->grid->get($row, $col - 1);
                $updated = new Cell(
                    char: $prev->char . $grapheme,
                    fg: $this->fg,
                    bg: $this->bg,
                    attrs: $this->attrs,
                );
                $this->grid->set($row, $col - 1, $updated);
            }
            return;
        }

        // If the character doesn't fit on this row, wrap to the next line.
        if ($col + $width > $this->grid->cols) {
            $col = 0;
            $row++;
            if ($row > $this->scrollBottom) {
                $this->scrollUp(1);
                $row = $this->scrollBottom;
            }
        }

        // Write the character cell.
        $cell = new Cell(
            char: $grapheme,
            fg: $this->fg,
            bg: $this->bg,
            attrs: $this->attrs,
        );
        $this->grid->set($row, $col, $cell);

        // Write continuation cells for wide characters (e.g. CJK, emoji).
        for ($i = 1; $i < $width; $i++) {
            $this->grid->set($row, $col + $i, Cell::empty());
        }

        // Advance cursor by character width, wrapping to next row if needed.
        $nextCol = $col + $width;
        $nextRow = $row;
        if ($nextCol >= $this->grid->cols) {
            $nextCol = 0;
            $nextRow = $row + 1;
            if ($nextRow > $this->scrollBottom) {
                $this->scrollUp(1);
                $nextRow = $this->scrollBottom;
            }
        }

        $this->cursor = $this->cursor->at($nextRow, $nextCol);
    }

    public function cuu(int $count = 1): void
    {
        $newRow = max($this->scrollTop, $this->cursor->row - $count);
        $this->cursor = $this->cursor->at($newRow, $this->cursor->col);
    }

    public function cud(int $count = 1): void
    {
        $newRow = min($this->scrollBottom, $this->cursor->row + $count);
        $this->cursor = $this->cursor->at($newRow, $this->cursor->col);
    }

    public function cuf(int $count = 1): void
    {
        $newCol = min($this->grid->cols - 1, $this->cursor->col + $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    public function cub(int $count = 1): void
    {
        $newCol = max(0, $this->cursor->col - $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    public function cup(int $row, int $col): void
    {
        $row = $row < 1 ? 1 : $row;
        $col = $col < 1 ? 1 : $col;

        $top = $this->scrollTop;
        $bottom = $this->scrollBottom;

        $absRow = $row - 1;
        $absCol = $col - 1;

        if ($absRow < $top) {
            $absRow = $top;
        }
        if ($absRow > $bottom) {
            $absRow = $bottom;
        }

        $absCol = max(0, min($this->grid->cols - 1, $absCol));

        $this->cursor = $this->cursor->at($absRow, $absCol);
    }

    public function hvp(int $row, int $col): void
    {
        $this->cup($row, $col);
    }

    public function sgr(array $params): void
    {
        if (empty($params)) {
            $params = [0];
        }

        $i = 0;
        while ($i < count($params)) {
            $p = (int) $params[$i];
            if ($p === -1) {
                $p = 0;
            }

            [$this->fg, $this->bg, $this->attrs, $i] = $this->applySgrParam($p, array_values(array_map('intval', $params)), $i);
        }
    }

    /**
     * @param list<int> $params
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function applySgrParam(int $p, array $params, int $i): array
    {
        return match (true) {
            $p === 0 => [$this->theme->defaultFg, $this->theme->defaultBg, 0, $i + 1],
            $p === 1 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_BOLD, $i + 1],
            $p === 3 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_ITALIC, $i + 1],
            $p === 4 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_UNDERLINE, $i + 1],
            $p === 7 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_INVERSE, $i + 1],
            $p === 9 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_STRIKETHROUGH, $i + 1],
            $p === 22 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_BOLD & ~0x20000, $i + 1],
            $p === 23 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_ITALIC, $i + 1],
            $p === 24 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_UNDERLINE, $i + 1],
            $p === 27 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_INVERSE, $i + 1],

            $p >= 30 && $p <= 37 => [$p - 30, $this->bg, $this->attrs, $i + 1],
            $p >= 40 && $p <= 47 => [$this->fg, $p - 40, $this->attrs, $i + 1],
            $p === 39 => [$this->theme->defaultFg, $this->bg, $this->attrs, $i + 1],
            $p === 49 => [$this->fg, $this->theme->defaultBg, $this->attrs, $i + 1],

            $p === 38 => $this->sgrExtended($params, $i, fg: true),
            $p === 48 => $this->sgrExtended($params, $i, fg: false),

            default => [$this->fg, $this->bg, $this->attrs, $i + 1],
        };
    }

    /**
     * Handle 38;5;n (256-color fg) or 48;5;n (256-color bg).
     *
     * @param list<int> $params
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function sgrExtended(array $params, int $i, bool $fg): array
    {
        $kind = $params[$i + 1] ?? -1;
        if ($kind === 5) {
            $index = $params[$i + 2] ?? 0;
            if ($fg) {
                return [$index, $this->bg, $this->attrs, $i + 3];
            } else {
                return [$this->fg, $index, $this->attrs, $i + 3];
            }
        }
        return [$this->fg, $this->bg, $this->attrs, $i + 1];
    }

    public function gridRows(): int
    {
        return $this->grid->rows;
    }

    public function gridCols(): int
    {
        return $this->grid->cols;
    }

    public function ed(int $mode = 0): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;

        if ($mode === 0) {
            for ($r = $row; $r < $this->grid->rows; $r++) {
                for ($c = ($r === $row ? $col : 0); $c < $this->grid->cols; $c++) {
                    $this->grid->set($r, $c, Cell::empty());
                }
            }
        } elseif ($mode === 1) {
            for ($r = 0; $r <= $row; $r++) {
                $endCol = $r === $row ? $col + 1 : $this->grid->cols;
                for ($c = 0; $c < $endCol; $c++) {
                    $this->grid->set($r, $c, Cell::empty());
                }
            }
        } elseif ($mode === 2) {
            $this->grid = $this->grid->clear();
        }
    }

    public function el(int $mode = 0): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;

        if ($mode === 0) {
            for ($c = $col; $c < $this->grid->cols; $c++) {
                $this->grid->set($row, $c, Cell::empty());
            }
        } elseif ($mode === 1) {
            for ($c = 0; $c <= $col; $c++) {
                $this->grid->set($row, $c, Cell::empty());
            }
        } elseif ($mode === 2) {
            for ($c = 0; $c < $this->grid->cols; $c++) {
                $this->grid->set($row, $c, Cell::empty());
            }
        }
    }

    public function decset(int $mode, int $prefix = 0): void
    {
        if ($mode === 25) {
            $this->cursor = $this->cursor->hidden();
        }
    }

    public function decrst(int $mode, int $prefix = 0): void
    {
        if ($mode === 25) {
            $this->cursor = $this->cursor->shown();
        }
    }

    public function decstbm(int $top, int $bottom): void
    {
        if ($top < 1) {
            $top = 1;
        }
        if ($bottom > $this->grid->rows) {
            $bottom = $this->grid->rows;
        }
        if ($top > $bottom) {
            return;
        }

        $this->scrollTop = $top - 1;
        $this->scrollBottom = $bottom - 1;

        if ($this->cursor->row < $this->scrollTop) {
            $this->cursor = $this->cursor->at($this->scrollTop, $this->cursor->col);
        }
        if ($this->cursor->row > $this->scrollBottom) {
            $this->cursor = $this->cursor->at($this->scrollBottom, $this->cursor->col);
        }
    }

    public function tbc(int $mode = 0): void
    {
    }

    public function cbt(int $count = 1): void
    {
        $newCol = max(0, $this->cursor->col - $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    public function cht(int $count = 1): void
    {
        $newCol = min($this->grid->cols - 1, $this->cursor->col + $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    /**
     * CR — carriage return: move cursor to column 0 (row unchanged).
     */
    public function cr(): void
    {
        $this->cursor = $this->cursor->at($this->cursor->row, 0);
    }

    /**
     * LF — line feed: advance cursor down one row, scrolling the
     * scroll region if the cursor is at scrollBottom.
     * Handles VT (0x0B) and FF (0x0C) the same way.
     */
    public function lf(): void
    {
        if ($this->cursor->row >= $this->scrollBottom) {
            $this->scrollUp(1);
        } else {
            $this->cursor = $this->cursor->at($this->cursor->row + 1, $this->cursor->col);
        }
    }

    private function scrollUp(int $count): void
    {
        $height = $this->scrollBottom - $this->scrollTop + 1;
        $count = min($count, $height);
        if ($count <= 0) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $this->scrollUpOne();
        }
    }

    private function scrollUpOne(): void
    {
        $top = $this->scrollTop;
        $bottom = $this->scrollBottom;
        $cols = $this->grid->cols;

        for ($r = $top; $r < $bottom; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $next = $this->grid->get($r + 1, $c);
                $this->grid->set($r, $c, $next);
            }
        }

        for ($c = 0; $c < $cols; $c++) {
            $this->grid->set($bottom, $c, Cell::empty());
        }
    }
}
