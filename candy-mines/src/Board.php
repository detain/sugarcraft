<?php

declare(strict_types=1);

namespace CandyCore\Mines;

/**
 * The minesweeper board — pure value object. Immutable; every reveal
 * or flag returns a fresh Board with the changed cells swapped in.
 *
 * The mine layout is deferred until the first reveal so the player
 * never loses on click 1 — the upstream go-sweep behaviour. The PRNG
 * is injected as a `Closure(int $maxInclusive): int` so tests can
 * pin the layout to a known seed without touching the runtime.
 */
final class Board
{
    /** @var list<list<Cell>> $rows[y][x] */
    private array $rows;

    /**
     * @param list<list<Cell>> $rows
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $mineCount,
        array $rows,
        public readonly bool $minesPlaced = false,
        public readonly bool $exploded = false,
    ) {
        if ($width < 2 || $height < 2) {
            throw new \InvalidArgumentException('board too small');
        }
        if ($mineCount < 1 || $mineCount > $width * $height - 1) {
            throw new \InvalidArgumentException('mineCount out of range');
        }
        $this->rows = $rows;
    }

    /**
     * Empty board — all cells unrevealed, no mines. The first reveal
     * triggers mine placement (avoiding the clicked cell + its eight
     * neighbours, so the player gets a non-trivial flood-fill on
     * click 1).
     */
    public static function blank(int $width, int $height, int $mineCount): self
    {
        $rows = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $row[] = new Cell(mine: false);
            }
            $rows[] = $row;
        }
        return new self($width, $height, $mineCount, $rows);
    }

    public function cell(int $x, int $y): ?Cell
    {
        return $this->rows[$y][$x] ?? null;
    }

    /** @return list<list<Cell>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Reveal the cell at (x, y). If it has no adjacent mines, also
     * reveal every neighbour transitively (classic flood fill). If
     * mines aren't yet placed, place them first while excluding the
     * clicked cell's 3×3 neighbourhood.
     *
     * @param \Closure(int):int $rand
     */
    public function reveal(int $x, int $y, \Closure $rand): self
    {
        $cell = $this->cell($x, $y);
        if ($cell === null || $cell->revealed || $cell->flagged) {
            return $this;
        }
        $b = $this;
        if (!$b->minesPlaced) {
            $b = $b->placeMines($x, $y, $rand);
        }
        return $b->floodReveal($x, $y);
    }

    public function toggleFlag(int $x, int $y): self
    {
        $cell = $this->cell($x, $y);
        if ($cell === null) {
            return $this;
        }
        $rows = $this->rows;
        $rows[$y][$x] = $cell->toggleFlag();
        return new self(
            $this->width, $this->height, $this->mineCount,
            $rows, $this->minesPlaced, $this->exploded,
        );
    }

    /** @param \Closure(int):int $rand */
    private function placeMines(int $sx, int $sy, \Closure $rand): self
    {
        // Collect every (x, y) outside the safe 3×3 around (sx, sy).
        $candidates = [];
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if (abs($x - $sx) <= 1 && abs($y - $sy) <= 1) {
                    continue;
                }
                $candidates[] = [$x, $y];
            }
        }
        // Knuth shuffle, take the first $mineCount.
        $n = count($candidates);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = $rand($i);
            [$candidates[$i], $candidates[$j]] = [$candidates[$j], $candidates[$i]];
        }
        $mines = array_slice($candidates, 0, $this->mineCount);

        $rows = $this->rows;
        foreach ($mines as [$mx, $my]) {
            $rows[$my][$mx] = $rows[$my][$mx]->withMine(true);
        }
        // Compute adjacency counts.
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if ($rows[$y][$x]->mine) {
                    continue;
                }
                $n = 0;
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        if ($dx === 0 && $dy === 0) continue;
                        $nx = $x + $dx; $ny = $y + $dy;
                        if (isset($rows[$ny][$nx]) && $rows[$ny][$nx]->mine) {
                            $n++;
                        }
                    }
                }
                $rows[$y][$x] = $rows[$y][$x]->withAdjacent($n);
            }
        }
        return new self(
            $this->width, $this->height, $this->mineCount,
            $rows, true, $this->exploded,
        );
    }

    private function floodReveal(int $sx, int $sy): self
    {
        $rows = $this->rows;
        $exploded = $this->exploded;
        $stack = [[$sx, $sy]];
        $seen = [];
        while ($stack !== []) {
            [$x, $y] = array_pop($stack);
            $key = "$x,$y";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $cell = $rows[$y][$x] ?? null;
            if ($cell === null || $cell->revealed || $cell->flagged) {
                continue;
            }
            $rows[$y][$x] = $cell->reveal();
            if ($cell->mine) {
                $exploded = true;
                continue;
            }
            if ($cell->adjacent !== 0) {
                continue;
            }
            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    if ($dx === 0 && $dy === 0) continue;
                    $nx = $x + $dx; $ny = $y + $dy;
                    if (isset($rows[$ny][$nx])) {
                        $stack[] = [$nx, $ny];
                    }
                }
            }
        }
        return new self(
            $this->width, $this->height, $this->mineCount,
            $rows, $this->minesPlaced, $exploded,
        );
    }

    /** True iff every non-mine cell has been revealed. */
    public function isWon(): bool
    {
        if ($this->exploded) return false;
        foreach ($this->rows as $row) {
            foreach ($row as $c) {
                if (!$c->mine && !$c->revealed) {
                    return false;
                }
            }
        }
        return true;
    }

    public function flagCount(): int
    {
        $n = 0;
        foreach ($this->rows as $row) {
            foreach ($row as $c) {
                if ($c->flagged) $n++;
            }
        }
        return $n;
    }
}
