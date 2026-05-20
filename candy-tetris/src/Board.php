<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

/**
 * The 10×20 Tetris playfield (the "well"), plus a 4-row hidden
 * buffer above it where new pieces spawn before they cross into
 * the visible area.
 *
 * Cells are stored as a row-major flat array of nullable
 * {@see Tetromino} values. `null` = empty; a Tetromino value =
 * locked-in cell painted that piece's colour. This shape is
 * deliberately small and mutation-free: every `place()` /
 * `clearLines()` returns a brand-new Board.
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
            if ($full) {
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
        $candidate = $piece;
        while (true) {
            $next = $candidate->moved(0, 1);
            if (!$this->fits($next)) {
                return $candidate;
            }
            $candidate = $next;
        }
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
