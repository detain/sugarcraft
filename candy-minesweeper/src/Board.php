<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper;

/**
 * The minesweeper board — pure value object. Immutable; every reveal
 * or flag returns a fresh Board with the changed cells swapped in.
 *
 * The mine layout is deferred until the first reveal so the player
 * never loses on click 1 — a guarantee this port adds, not one it
 * ports: upstream go-sweep left first-click safety as an
 * unimplemented TODO (docs/old/repo_map/sugarcraft_candy-minesweeper.md
 * :412, :423-428 record the verbatim `// TODO instantiate the mines
 * after the first sweep...`). The PRNG is injected as a
 * `Closure(int $maxInclusive): int` so tests can pin the layout to a
 * known seed without touching the runtime.
 */
final class Board
{
    /**
     * Byte budget a serialized payload may occupy BEFORE the parse (msw-34,
     * finding -067). The largest HONEST save is a 50×50 board: 2,500 cells
     * at their most verbose wire form ('[true,true,true,8],') plus the
     * envelope ≈ 50.4 KB, so 64 KiB carries every board the game itself can
     * produce with comfortable margin — while a crafted multi-megabyte
     * crawl is refused at the door instead of being json_decode'd first.
     */
    public const MAX_SERIALIZED_BYTES = 65536;

    /** @var list<list<Cell>> $rows[y][x] */
    private array $rows;

    /**
     * Thin construction (msw-10/-015): the shape census lives at the public
     * boundary ({@see fromRows()}, {@see blank()}); internal one-cell and
     * flood clones take this path unchecked. The class is final and $rows is
     * private, so no misshapen grid can reach a clone without first passing
     * a boundary — parse-don't-validate on the hot path.
     *
     * @param list<list<Cell>> $rows
     */
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $mineCount,
        array $rows,
        public readonly bool $minesPlaced = false,
        public readonly bool $exploded = false,
        public readonly int $revealedCount = 0,
        public readonly int $flaggedCount = 0,
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
     * Public direct-construction boundary: the grid census for callers that
     * hand us a full $rows map. Fail fast on a misshapen grid: a 5×5-claim
     * over 2×2 data would silently produce an un-winnable board (win
     * threshold unreachable, reveals outside the real data no-op).
     * unserialize() runs its own stricter wire pass and constructs directly;
     * both honour the same promise.
     *
     * @param list<list<Cell>> $rows
     */
    public static function fromRows(
        int $width,
        int $height,
        int $mineCount,
        array $rows,
        bool $minesPlaced = false,
        bool $exploded = false,
        int $revealedCount = 0,
        int $flaggedCount = 0,
    ): self {
        if (count($rows) !== $height) {
            throw new \InvalidArgumentException(Lang::t('board.rows_shape_mismatch', ['width' => $width, 'height' => $height]));
        }
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== $width) {
                throw new \InvalidArgumentException(Lang::t('board.rows_shape_mismatch', ['width' => $width, 'height' => $height]));
            }
            foreach ($row as $cell) {
                if (!$cell instanceof Cell) {
                    throw new \InvalidArgumentException(Lang::t('board.rows_shape_mismatch', ['width' => $width, 'height' => $height]));
                }
            }
        }

        return new self($width, $height, $mineCount, $rows, $minesPlaced, $exploded, $revealedCount, $flaggedCount);
    }

    /**
     * Empty board — all cells unrevealed, no mines. The first reveal
     * triggers mine placement, avoiding the clicked cell + its eight
     * neighbours whenever the mine count leaves room (so the player
     * gets a non-trivial flood-fill on click 1). A board dense enough
     * that the exclusion would starve the placement falls back to
     * keeping only the clicked cell mine-free — see
     * {@see placeMines()}. That regime is unreachable through the
     * CLI: the launcher caps mines at cells − 9.
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
     * mines aren't yet placed, place them first, excluding the clicked
     * cell's 3×3 neighbourhood — or, on a board too dense for that
     * exclusion to leave enough candidates, the clicked cell alone
     * ({@see placeMines()}).
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
        // Rule-fidelity gate (msw-17/-030): the classic rulebook refuses the
        // next flag once the flags already equal the mines — placement stops
        // at the count, un-flagging stays free. Returning the board identity
        // makes Game's changed-guard treat the refused press exactly like a
        // dud: no state move, notice untouched.
        if ($this->flaggedCount >= $this->mineCount && !$cell->flagged) {
            return $this;
        }
        $rows = $this->rows;
        $rows[$y][$x] = $cell->toggleFlag();
        // Cell::toggleFlag no-ops on a revealed cell (and returns the same
        // object when it does); answer the same identity back so callers can
        // tell a real flag change from a dud press — Game's notice rule and
        // the over-flag gate both key on it.
        if ($rows[$y][$x] === $cell) {
            return $this;
        }
        // Track the flag count incrementally so flagCount() stays O(1) and the
        // renderer never scans the grid per frame. A revealed cell can't flag
        // (Cell::toggleFlag() no-ops), so the delta is derived from the cell's
        // prior flagged state.
        $flaggedDelta = $cell->revealed ? 0 : ($cell->flagged ? -1 : 1);
        return new self(
            $this->width, $this->height, $this->mineCount,
            $rows, $this->minesPlaced, $this->exploded, $this->revealedCount,
            $this->flaggedCount + $flaggedDelta,
        );
    }

    /**
     * @param \Closure(int):int $rand  The shuffle's sole source of randomness:
     *        called once per Knuth step with the current index as the INCLUSIVE
     *        upper bound (n-1 down to 1) and expected to answer an int in
     *        [0, $max]. A deterministic closure therefore yields a
     *        deterministic layout — tests rely on exactly that contract, and
     *        the swap below now ENFORCES it (msw-34, finding -066): an
     *        out-of-range or non-int answer throws here, naming the contract,
     *        instead of detonating opaquely at the destructure with a
     *        TypeError far from its cause.
     */
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
        // On a dense board the full 3×3 safe zone can leave fewer cells than
        // mines; the old array_slice() then silently placed FEWER mines than
        // requested, permanently breaking win detection (revealedCount can
        // never reach width*height - mineCount). Fall back to excluding only
        // the clicked cell — the first click is still never a mine and every
        // requested mine gets placed. The constructor bound (mineCount <=
        // w*h-1) guarantees this fallback always has enough candidates.
        if (count($candidates) < $this->mineCount) {
            $candidates = [];
            for ($y = 0; $y < $this->height; $y++) {
                for ($x = 0; $x < $this->width; $x++) {
                    if ($x === $sx && $y === $sy) {
                        continue;
                    }
                    $candidates[] = [$x, $y];
                }
            }
        }
        // Knuth shuffle, take the first $mineCount.
        $n = count($candidates);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = $rand($i);
            if (!\is_int($j) || $j < 0 || $j > $i) {
                throw new \InvalidArgumentException(
                    'Mine placement random() must answer an int in [0, ' . $i . ']; got '
                        . (\is_int($j) ? (string) $j : \get_debug_type($j))
                );
            }
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
            $rows, true, $this->exploded, $this->revealedCount, $this->flaggedCount,
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
            $rows, $this->minesPlaced, $exploded, $revealedCount, $this->flaggedCount,
        );
    }

    /** True iff every non-mine cell has been revealed. O(1) via revealedCount. */
    public function isWon(): bool
    {
        if ($this->exploded) return false;
        return $this->revealedCount === $this->width * $this->height - $this->mineCount;
    }

    /** Number of flagged cells. O(1) via the incrementally-tracked counter. */
    public function flagCount(): int
    {
        return $this->flaggedCount;
    }

    /**
     * Serialize the board to a string for save/load mid-game.
     *
     * Format: JSON with a version tag (`v`) that {@see unserialize()}
     * enforces — payloads tagged with any other version are rejected with a
     * clear error rather than half-parsed. Each cell is stored as
     * [mine, revealed, flagged, adjacent].
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
     * The savegame is user-editable data (it lives on disk at 0600), so the
     * loader treats it as hostile input and enforces that the payload
     * describes a state this game could actually have produced:
     *
     *  - a payload small enough to parse at all — the byte budget is checked
     *    BEFORE {@see \json_decode()} ever touches it (finding -067);
     *  - strict wire types (no silent (int)/"yes"-to-bool coercion of junk);
     *  - dimensions within the game's 2..50 ladder (CustomDifficulty law);
     *  - planted mine bits exactly equal the claimed mineCount;
     *  - pre-placement boards carry zero mines and zero adjacency
     *    (placeMines() is the only source of either), killing the
     *    "mines already sitting on a fresh board" first-click detonation;
     *  - adjacency equals a full recompute from the planted bits (mine
     *    cells 0, non-mine cells the neighbour-mine count), so numbers
     *    cannot lie about the layout;
     *  - exploded ⇔ a revealed mine exists (explosions reveal their mine;
     *    a revealed mine sets exploded), and exploded implies placement;
     *  - a cell is never simultaneously revealed and flagged (Cell::reveal
     *    no-ops on flagged; toggleFlag no-ops on revealed).
     *
     * revealed/flagged counts are recomputed from cells, never trusted
     * from disk. Coherence is the guarantee: a hand-crafted but fully
     * consistent board (e.g. a won layout) still loads — that is the
     * player cheating themselves in their own save slot, not a loader bug,
     * and the 0600 mode plus this docblock are the trust boundary.
     *
     * @throws \InvalidArgumentException if the payload is malformed or
     *                                  describes an unreachable state
     */
    public static function unserialize(string $data): self
    {
        // The cheap door runs FIRST (finding -067): the 2..50 ladder below
        // only fires after json_decode has already eaten the whole payload,
        // so the size defense needs to precede the parse it protects.
        if (\strlen($data) > self::MAX_SERIALIZED_BYTES) {
            throw new \InvalidArgumentException(
                'Invalid board serialization: payload exceeds the byte budget'
            );
        }
        try {
            $p = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Invalid board serialization', 0, $e);
        }
        if (!is_array($p)) {
            throw new \InvalidArgumentException('Invalid board serialization');
        }
        $v = $p['v'] ?? null;
        if ($v !== 1) {
            throw new \InvalidArgumentException(
                $v === null
                    ? 'Invalid board serialization'
                    : "Unsupported board serialization version: " . json_encode($v)
            );
        }
        $w  = $p['w'] ?? null;
        $h  = $p['h'] ?? null;
        $m  = $p['m'] ?? null;
        $placed  = $p['p'] ?? null;
        $exploded = $p['e'] ?? null;
        $cells = $p['c'] ?? null;
        if (
            !is_int($w) || !is_int($h) || !is_int($m)
            || !is_bool($placed) || !is_bool($exploded)
            || !is_array($cells)
        ) {
            throw new \InvalidArgumentException('Invalid board serialization');
        }
        // Same ladder the interactive custom-difficulty form enforces; the
        // byte budget above already bounded the parse cost, so a crafted
        // 5000x5000 payload cannot reach this line as a crawl.
        if ($w < 2 || $w > 50 || $h < 2 || $h > 50) {
            throw new \InvalidArgumentException('Invalid board serialization');
        }
        if (count($cells) !== $h) {
            throw new \InvalidArgumentException('Invalid board serialization');
        }
        $rows = [];
        $revealedCount = 0;
        $flaggedCount = 0;
        $plantedBits = 0;
        $revealedMines = 0;
        /** @var list<list<int>> $adjGiven */
        $adjGiven = [];
        /** @var list<list<bool>> $mineGrid */
        $mineGrid = [];
        for ($y = 0; $y < $h; $y++) {
            if (!is_array($cells[$y]) || count($cells[$y]) !== $w) {
                throw new \InvalidArgumentException('Invalid board serialization');
            }
            $row = [];
            $adjRow = [];
            $mineRow = [];
            for ($x = 0; $x < $w; $x++) {
                $cellData = $cells[$y][$x] ?? null;
                if (
                    !is_array($cellData) || count($cellData) !== 4
                    || !is_bool($cellData[0]) || !is_bool($cellData[1])
                    || !is_bool($cellData[2]) || !is_int($cellData[3])
                ) {
                    throw new \InvalidArgumentException('Invalid board serialization');
                }
                $mine     = $cellData[0];
                $revealed = $cellData[1];
                $flagged  = $cellData[2];
                $adjacent = $cellData[3];
                // Cell-level state machine: reveal clears flags, flagging a
                // revealed cell no-ops — both-true is unreachable.
                if ($revealed && $flagged) {
                    throw new \InvalidArgumentException('Invalid board serialization');
                }
                if ($revealed) {
                    $revealedCount++;
                    if ($mine) {
                        $revealedMines++;
                    }
                }
                if ($flagged) {
                    $flaggedCount++;
                }
                if ($mine) {
                    $plantedBits++;
                }
                $adjRow[] = $cellData[3];
                $mineRow[] = $mine;
                $row[] = new Cell(mine: $mine, revealed: $revealed, flagged: $flagged, adjacent: 0);
            }
            $rows[] = $row;
            $adjGiven[] = $adjRow;
            $mineGrid[] = $mineRow;
        }
        // On a PLACED board the claimed mine count must equal the planted
        // bits — the win threshold (revealedCount === w*h - m) is only
        // meaningful if m cannot be under-declared to fake a win or
        // over-declared to make the board un-winnable. An UNPLACED board
        // carries m as a promise only: placeMines() honours it exactly when
        // the first reveal runs, and its cells must be mine-free until then
        // (checked in that branch below).
        if ($placed && $plantedBits !== $m) {
            throw new \InvalidArgumentException('Invalid board serialization');
        }
        // exploded ⇔ a revealed mine: an explosion is exactly the frame a
        // flood reveals a mine, and no other path reveals mines.
        if ($exploded && !$placed) {
            throw new \InvalidArgumentException('Invalid board serialization');
        }
        if ($exploded !== ($revealedMines > 0)) {
            throw new \InvalidArgumentException('Invalid board serialization');
        }
        // Drop the persisted 'r' value entirely — recompute from live cell state
        // so a tampered payload cannot inflate revealedCount to force a false win
        // or deflate it to make a finished board un-winnable. flaggedCount is
        // likewise derived from the reconstructed cells, never trusted from disk.
        if (!$placed) {
            // placeMines() is the only writer of mine bits and adjacency;
            // an unplaced board must carry neither. The first reveal always
            // places, so an unplaced board cannot legitimately hold any
            // revealed cell either — otherwise w*h - m revealed empty cells
            // would fake a win on a board whose mines were never placed.
            if ($plantedBits > 0 || $revealedCount > 0) {
                throw new \InvalidArgumentException('Invalid board serialization');
            }
            foreach ($adjGiven as $adjRow) {
                foreach ($adjRow as $adjacent) {
                    if ($adjacent !== 0) {
                        throw new \InvalidArgumentException('Invalid board serialization');
                    }
                }
            }
        } else {
            // Full adjacency recompute: every claimed number must equal the
            // planted-neighbour count (mine cells themselves stay 0), so a
            // tampered "8" over an empty corner or a 2-char glyph value
            // cannot survive.
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $expected = 0;
                    if (!$mineGrid[$y][$x]) {
                        for ($dy = -1; $dy <= 1; $dy++) {
                            for ($dx = -1; $dx <= 1; $dx++) {
                                if ($dx === 0 && $dy === 0) {
                                    continue;
                                }
                                $nx = $x + $dx;
                                $ny = $y + $dy;
                                if (isset($mineGrid[$ny][$nx]) && $mineGrid[$ny][$nx]) {
                                    $expected++;
                                }
                            }
                        }
                    }
                    if ($adjGiven[$y][$x] !== $expected) {
                        throw new \InvalidArgumentException('Invalid board serialization');
                    }
                    $rows[$y][$x] = $rows[$y][$x]->withAdjacent($expected);
                }
            }
        }
        return new self(
            $w, $h, $m,
            $rows, $placed, $exploded, $revealedCount, $flaggedCount,
        );
    }

    /**
     * Chord click — reveal all unflagged neighbors of a "satisfied" number.
     *
     * A cell is satisfied when its (positive) adjacent count equals the number
     * of flagged neighbours — EQUALS, not go-sweep's transcribed `adjFlags >=
     * adjMines`: upstream chorded an over-flagged number, here it is refused. The chord then reveals every unflagged neighbour
     * — safe ONLY while those flags are correct: a wrong flag leaves its mine
     * unflagged, and the reveal flood detonates on it.
     *
     * A zero-adjacent cell can satisfy the letter of that definition vacuously
     * (0 flagged neighbours around a 0), but it is deliberately NOT chordable:
     * a revealed 0 has already flood-filled every neighbour it owns, so a
     * chord there could never reveal anything new, and treating it as
     * "satisfied" would let a stray click on empty ground look like a move
     * that did something. Chording an unrevealed cell likewise does nothing.
     *
     * Mirrors the chord press of maxpaulus43/go-sweep (`autoSweep()`,
     * transcribed at docs/old/repo_map/sugarcraft_candy-minesweeper.md
     * :346-354); the divergences from it — `===` strictness and the
     * unchordable revealed zero above — are port tightenings, listed
     * under "Differences from the antecedent" in the README.
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
        // neighbour is fully cleared.
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
