<?php

declare(strict_types=1);

namespace SugarCraft\Mines;

use SugarCraft\Mines\Lang;

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
        public readonly int $revealedCount = 0,
    ) {
        if ($width < 2 || $height < 2) {
            throw new \InvalidArgumentException(Lang::t('board.too_small'));
        }
        if ($mineCount < 1 || $mineCount > $width * $height - 1) {
            throw new \InvalidArgumentException(Lang::t('board.minecount_out_of_range'));
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
            $rows, $this->minesPlaced, $this->exploded, $this->revealedCount,
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
            $rows, true, $this->exploded, $this->revealedCount,
        );
    }

    private function floodReveal(int $sx, int $sy): self
    {
        $rows = $this->rows;
        $exploded = $this->exploded;
        $revealedCount = $this->revealedCount;
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
            $revealedCount++;
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
            $rows, $this->minesPlaced, $exploded, $revealedCount,
        );
    }

    /** True iff every non-mine cell has been revealed. O(1) via revealedCount. */
    public function isWon(): bool
    {
        if ($this->exploded) return false;
        return $this->revealedCount === $this->width * $this->height - $this->mineCount;
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

    /**
     * Serialize the board to a string for save/load mid-game.
     *
     * Format: JSON with version tag for forward compatibility.
     * Each cell is stored as [mine, revealed, flagged, adjacent].
     */
    public function serialize(): string
    {
        $cells = [];
        for ($y = 0; $y < $this->height; $y++) {
            $row = [];
            for ($x = 0; $x < $this->width; $x++) {
                $c = $this->rows[$y][$x];
                $row[] = [$c->mine, $c->revealed, $c->flagged, $c->adjacent];
            }
            $cells[] = $row;
        }
        $payload = [
            'v' => 1,
            'w' => $this->width,
            'h' => $this->height,
            'm' => $this->mineCount,
            'p' => $this->minesPlaced,
            'e' => $this->exploded,
            'r' => $this->revealedCount,
            'c' => $cells,
        ];
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Reconstruct a Board from a string produced by {@see serialize()}.
     *
     * @throws \InvalidArgumentException if the payload is malformed
     */
    public static function unserialize(string $data): self
    {
        try {
            $p = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Invalid board serialization', 0, $e);
        }
        if (!is_array($p)) {
            throw new \InvalidArgumentException('Invalid board serialization');
        }
        $w = $p['w'] ?? null;
        $h = $p['h'] ?? null;
        $m = $p['m'] ?? null;
        $p2 = $p['p'] ?? null;
        $e = $p['e'] ?? null;
        $r = $p['r'] ?? 0;
        $cells = $p['c'] ?? null;
        if ($w === null || $h === null || $m === null || $p2 === null || $e === null || $cells === null) {
            throw new \InvalidArgumentException('Invalid board serialization');
        }
        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $cellData = $cells[$y][$x] ?? null;
                if (!is_array($cellData) || count($cellData) < 4) {
                    throw new \InvalidArgumentException('Invalid board serialization');
                }
                $row[] = new Cell(
                    (bool) $cellData[0],
                    (bool) $cellData[1],
                    (bool) $cellData[2],
                    (int) $cellData[3],
                );
            }
            $rows[] = $row;
        }
        return new self(
            (int) $w, (int) $h, (int) $m,
            $rows, (bool) $p2, (bool) $e, (int) $r,
        );
    }

    /**
     * Chord click — reveal all unflagged neighbors of a "satisfied" number.
     *
     * A cell is satisfied when its adjacent count equals the number of
     * flagged neighbors. Chord-clicking it is safe because the player
     * has correctly identified all mines around that cell.
     *
     * Mirrors standard minesweeper left+right / middle-click chord.
     */
    public function chord(int $x, int $y): self
    {
        $cell = $this->cell($x, $y);
        if ($cell === null || !$cell->revealed || $cell->adjacent === 0) {
            return $this;
        }

        // Count flagged neighbors.
        $flagCount = 0;
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if ($dx === 0 && $dy === 0) {
                    continue;
                }
                $nx = $x + $dx;
                $ny = $y + $dy;
                $n = $this->cell($nx, $ny);
                if ($n !== null && $n->flagged) {
                    $flagCount++;
                }
            }
        }

        // Not satisfied — player hasn't flagged the right number yet.
        if ($flagCount !== $cell->adjacent) {
            return $this;
        }

        // Reveal all unflagged, unrevealed neighbors, cascading through
        // floodReveal so any zero-adjacent pocket adjacent to a chorded
        // neighbour is fully cleared (standard minesweeper chord behaviour).
        $b = $this;
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if ($dx === 0 && $dy === 0) {
                    continue;
                }
                $nx = $x + $dx;
                $ny = $y + $dy;
                $n = $b->cell($nx, $ny);
                if ($n === null || $n->revealed || $n->flagged) {
                    continue;
                }
                // floodReveal skips already-revealed/flagged cells, handles
                // adj==0 cascade, increments revealedCount, and sets exploded
                // on a mine — so it fully subsumes the previous hand-rolled
                // single-cell reveal logic.
                $b = $b->floodReveal($nx, $ny);
            }
        }

        return $b;
    }
}
