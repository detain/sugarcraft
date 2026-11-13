<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

/**
 * The 10×20 Tetris playfield (the "well"), plus a 4-row hidden
 * buffer above it where new pieces spawn before they cross into
 * the visible area.
 *
 * Cells are stored as a list of rows, each row a list of nullable
 * {@see Tetromino} values indexed by column. `null` = empty; a
 * Tetromino value = locked-in cell painted that piece's colour.
 * This shape is deliberately small and mutation-free: every
 * `place()` / `clearLines()` returns a brand-new Board.
 */
final class Board
{
    public const COLS = 10;
    public const VISIBLE_ROWS = 20;
    public const HIDDEN_ROWS  = 4;
    public const ROWS = self::VISIBLE_ROWS + self::HIDDEN_ROWS;

    /** @var list<list<?Tetromino>> outer index = row, inner = col */
    private array $cells;

    /**
     * @param list<list<?Tetromino>>|null $cells
     */
    public function __construct(?array $cells = null)
    {
        if ($cells !== null) {
            $this->cells = $cells;
            return;
        }
        $row = array_fill(0, self::COLS, null);
        $this->cells = array_fill(0, self::ROWS, $row);
    }

    public function isOccupied(int $x, int $y): bool
    {
        if ($x < 0 || $x >= self::COLS || $y < 0 || $y >= self::ROWS) {
            return true; // walls + floor count as "occupied"
        }
        return $this->cells[$y][$x] !== null;
    }

    public function cellAt(int $x, int $y): ?Tetromino
    {
        if ($x < 0 || $x >= self::COLS || $y < 0 || $y >= self::ROWS) {
            return null;
        }
        return $this->cells[$y][$x];
    }

    /**
     * True iff every cell of `$piece` is in-bounds AND not already
     * occupied. Used both for "can the piece move here" and for
     * "did the new spawn collide on entry" (game-over check).
     */
    public function fits(Piece $piece): bool
    {
        foreach ($piece->cells() as [$x, $y]) {
            if ($this->isOccupied($x, $y)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Lock `$piece` into the board, returning a new Board with
     * those cells filled with the piece's Tetromino kind.
     */
    public function place(Piece $piece): self
    {
        $next = $this->cells;
        foreach ($piece->cells() as [$x, $y]) {
            if ($x < 0 || $x >= self::COLS || $y < 0 || $y >= self::ROWS) {
                continue;
            }
            $next[$y][$x] = $piece->kind;
        }
        return new self($next);
    }

    /**
     * Clear any rows that are completely full. Returns
     * `[newBoard, $clearedCount]`. Cleared rows are dropped and
     * empty rows are inserted at the top (hidden buffer end) so
     * everything above falls down.
     *
     * @return array{0:self,1:int}
     */
    public function clearLines(): array
    {
        $remaining = [];
        $cleared = 0;
        foreach ($this->cells as $row) {
            $full = true;
            foreach ($row as $c) {
                if ($c === null) {
                    $full = false;
                    break;
                }
            }
            if ($full === true) {
                $cleared++;
            } else {
                $remaining[] = $row;
            }
        }
        $emptyRow = array_fill(0, self::COLS, null);
        $padded = array_merge(array_fill(0, $cleared, $emptyRow), $remaining);
        return [new self($padded), $cleared];
    }

    /**
     * Drop `$piece` straight down until it can't fall any further.
     * Returns the resting position (without locking it in).
     */
    public function dropPiece(Piece $piece): Piece
    {
        // candy-tetris-050: the descent is one scan of the four cells, not
        // one Piece allocation per row. A cell's free prefix runs while the
        // square below it is free; the piece lands when the FIRST cell hits
        // something, so the descent is the MINIMUM prefix — exactly where
        // the old move-until-!fits loop stopped (offsets below that bound
        // keep every cell inside its own free run, so the whole-piece fits()
        // check agreed with it step for step, overlaps at rest included).
        $slack = PHP_INT_MAX;
        foreach ($piece->cells() as [$x, $y]) {
            $free = 0;
            while ($this->isOccupied($x, $y + $free + 1) === false) {
                $free++;
            }
            $slack = min($slack, $free);
        }

        return $slack === 0 ? $piece : $piece->moved(0, $slack);
    }

    /**
     * @return list<list<?Tetromino>>
     */
    public function rows(): array
    {
        return $this->cells;
    }

    /**
     * True iff the board is completely empty (all cells null).
     * Used to detect a "perfect clear" — all lines cleared at once.
     */
    public function isPerfectClear(): bool
    {
        foreach ($this->cells as $row) {
            foreach ($row as $cell) {
                if ($cell !== null) {
                    return false;
                }
            }
        }
        return true;
    }
}
