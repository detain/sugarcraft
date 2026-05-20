<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Tetris\Rotation\SrsKickTable;

/**
 * A live (falling) Tetris piece. Immutable: every transform —
 * `withX()`, `rotated()`, `dropped()` — returns a fresh instance,
 * which keeps the {@see Game} update loop pure and trivially
 * testable.
 *
 * Cells are computed lazily by deferring to
 * {@see Tetromino::cells()} and offsetting by `$x` / `$y`.
 */
final class Piece
{
    public function __construct(
        public readonly Tetromino $kind,
        public readonly int       $rotation = 0,
        public readonly int       $x = 3,
        public readonly int       $y = 0,
    ) {}

    public function withX(int $x): self
    {
        return new self($this->kind, $this->rotation, $x, $this->y);
    }

    public function withY(int $y): self
    {
        return new self($this->kind, $this->rotation, $this->x, $y);
    }

    public function rotated(int $delta = 1): self
    {
        return new self($this->kind, ((($this->rotation + $delta) % 4) + 4) % 4, $this->x, $this->y);
    }

    public function moved(int $dx, int $dy): self
    {
        return new self($this->kind, $this->rotation, $this->x + $dx, $this->y + $dy);
    }

    /**
     * All possible piece positions after rotation with SRS wall kicks.
     *
     * Mirrors charmbracelet/bubbletea Tetris — SRS applies a series of
     * (dx, dy) offset candidates to the rotated piece and returns every
     * resulting position. Callers (e.g. Game) can test each candidate
     * for board validity and use the first that fits.
     *
     * @return list<self> first element is the naive rotation; subsequent
     *                    elements are wall-kick offsets in SRS order
     */
    public function rotationsWithKicks(int $delta = 1): array
    {
        $to = ((($this->rotation + $delta) % 4) + 4) % 4;
        $naive = new self($this->kind, $to, $this->x, $this->y);

        $candidates = [$naive];

        foreach (SrsKickTable::kicks($this->kind, $this->rotation, $to) as [$dx, $dy]) {
            $candidates[] = new self($this->kind, $to, $this->x + $dx, $this->y + $dy);
        }

        return $candidates;
    }

    /**
     * Cells of this piece in absolute board coordinates.
     *
     * @return list<array{int,int}>
     */
    public function cells(): array
    {
        $out = [];
        foreach ($this->kind->cells($this->rotation) as [$dx, $dy]) {
            $out[] = [$this->x + $dx, $this->y + $dy];
        }
        return $out;
    }
}
