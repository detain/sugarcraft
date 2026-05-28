# SugarCraft/candy-mines

## Metadata
- **URL:** https://github.com/sugarcraft/candy-mines
- **Upstream:** [maxpaulus43/go-sweep](https://github.com/maxpaulus43/go-sweep)
- **Language:** PHP 8.3+
- **Stars:** N/A (monorepo library)
- **License:** MIT
- **Status:** 🟢 v1 ready
- **Composer:** `sugarcraft/candy-mines`
- **Namespace:** `SugarCraft\Mines`

---

## Overview

`candy-mines` is a Minesweeper clone ported from Go to PHP, running on the SugarCraft TUI stack (`candy-core` + `candy-sprinkles`). It faithfully reproduces the upstream `go-sweep` experience while adding PHP-native improvements: immutable value objects, injectable deterministic RNG for testing, O(1) win detection, mid-game serialization, and i18n support.

---

## Feature Inventory

### Core Game Logic
| Feature | Implementation | File |
|---|---|---|
| Grid generation | `Board::blank()` creates empty 2D cell grid | `src/Board.php:50` |
| First-click safety | Mines placed only AFTER first reveal, excluding clicked cell's 3x3 neighborhood | `src/Board.php:110` (`placeMines`) |
| Recursive flood-fill | Stack-based `floodReveal()` — iterative (not recursive) to avoid PHP stack overflow | `src/Board.php:159` |
| Flag toggle | `Board::toggleFlag()` — no-op on revealed cells | `src/Board.php:95` |
| Chord click | `Board::chord()` — reveals unflagged neighbors when adjacent flag count equals cell number | `src/Board.php:305` |
| Win detection | O(1) via `revealedCount === width * height - mineCount` | `src/Board.php:201` |
| Lose detection | `exploded` flag set when a mine is revealed | `src/Board.php:177` |
| Deterministic RNG | `\Closure(int):int` injected into `Game::__construct` — default `random_int()` | `src/Game.php:38` |
| Win/loss gate | After game ends only `r` (restart) and `q` (quit) are accepted | `src/Game.php:98` |

### Difficulty Presets
| Preset | Dimensions | Mines |
|---|---|---|
| `EASY` | 9x9 | 10 |
| `MEDIUM` | 16x16 | 40 |
| `EXPERT` | 30x16 | 99 |
| Custom | 2-50 x 2-50 | 1 to rows×cols-9 |

### Input Handling
- Arrow keys + vim keys (hjkl) for cursor movement
- `Space` / `Enter` to reveal
- `f` to toggle flag
- `c` or middle-click for chord
- `r` to restart
- `q` / `Esc` to quit

### Persistence & Stats
| Feature | Implementation | File |
|---|---|---|
| Mid-game save/load | Versioned JSON serialization (`Board::serialize()` / `Board::unserialize()`) | `src/Board.php:224` |
| Stats tracking | Immutable `Stats` per difficulty | `src/Stats.php` |
| Atomic persistence | `DifficultyStats` using tmp+rename (Homestead pattern) | `src/Stats/DifficultyStats.php:86` |
| Timer | Sub-second via `microtime(true)` | `src/Game.php:140` |

### Rendering
- CandySprinkles `Style` + `Border::rounded()`
- Color-coded adjacent numbers (8 distinct colors)
- Cursor reverse highlighting
- Emoji-free ASCII mode (default) with glyphs: `·` (hidden), `F` (flagged), `*` (mine), `1-8` (numbers)

### i18n
- 16 locales via `Lang::t()` facade: en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar

---

## Architecture

### Class Diagram (Core)

```
Game (implements Model)
├── board: Board
├── cursorX: int
├── cursorY: int
├── rand: Closure(int):int
├── startedAt: ?float
├── elapsedSeconds: ?int
└── stats: Stats

Board (immutable value object)
├── width: int
├── height: int
├── mineCount: int
├── rows: list<list<Cell>>
├── minesPlaced: bool
├── exploded: bool
└── revealedCount: int
    ├── blank()
    ├── reveal(x, y, rand)
    ├── toggleFlag(x, y)
    ├── chord(x, y)
    ├── isWon(): bool  // O(1)
    ├── serialize(): string
    └── unserialize(string): Board

Cell (immutable value object)
├── mine: bool
├── revealed: bool
├── flagged: bool
└── adjacent: int
    ├── withMine()
    ├── withAdjacent()
    ├── reveal()
    └── toggleFlag()

Difficulty (PHP enum)
├── EASY / MEDIUM / EXPERT
├── width(), height(), mines()
└── fromDimensions()
```

---

## Detailed Algorithm Analysis

### First-Click Safety (`Board::placeMines`)

**Problem:** Ensure the first click is never a mine and the player gets a meaningful flood-fill opening.

**Algorithm:**
1. Collect all cells where `abs(x - sx) > 1 || abs(y - sy) > 1` (outside 3x3 around click)
2. Fisher-Yates/Knuth shuffle using injected `rand` closure
3. Take first `$mineCount` cells as mine positions

**Why exclude 3x3:** A standard Minesweeper rule — ensures the clicked cell and all 8 neighbors are safe, guaranteeing at least a 3x3 opening.

**Code reference:** `src/Board.php:110-157`

```php
private function placeMines(int $sx, int $sy, \Closure $rand): self
{
    // Collect every (x, y) outside the safe 3x3 around (sx, sy).
    $candidates = [];
    for ($y = 0; $y < $this->height; $y++) {
        for ($x = 0; $x < $this->width; $x++) {
            if (abs($x - $sx) <= 1 && abs($y - $sy) <= 1) {
                continue;  // ← SAFE ZONE
            }
            $candidates[] = [$x, $y];
        }
    }
    // Knuth shuffle
    $n = count($candidates);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = $rand($i);
        [$candidates[$i], $candidates[$j]] = [$candidates[$j], $candidates[$i]];
    }
    $mines = array_slice($candidates, 0, $this->mineCount);
    // ... set mines and compute adjacent counts
}
```

**Deterministic testing:** The injected `rand` closure allows tests to pin the shuffle:
```php
$rand = static fn(int $max): int => $max;  // no-op shuffle
$b = Board::blank(5, 5, 5)->reveal(2, 2, $rand);
```

---

### Flood-Fill Algorithm (`Board::floodReveal`)

**Problem:** Reveal all connected zero-adjacent cells recursively.

**Approach:** Iterative stack-based traversal (not recursive) to avoid PHP call-stack overflow on large boards.

**Algorithm:**
1. Push starting cell onto stack
2. Pop cell, mark as revealed (increment `revealedCount`)
3. If cell is a mine, set `exploded = true`, continue
4. If cell has `adjacent !== 0`, skip neighbor expansion (boundary of flood)
5. If cell has `adjacent === 0`, push all unvisited unflagged unrevealed neighbors onto stack
6. Repeat until stack empty

**Key invariant:** A cell with `adjacent > 0` stops the flood — only zero-adjacent cells expand further. This is the classic Minesweeper behavior.

**Code reference:** `src/Board.php:159-198`

```php
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
        if (isset($seen[$key])) continue;  // ← skip visited
        $seen[$key] = true;

        $cell = $rows[$y][$x] ?? null;
        if ($cell === null || $cell->revealed || $cell->flagged) {
            continue;
        }

        $rows[$y][$x] = $cell->reveal();
        $revealedCount++;

        if ($cell->mine) {
            $exploded = true;
            continue;  // ← mine hit, don't expand
        }

        if ($cell->adjacent !== 0) {
            continue;  // ← boundary cell, don't expand
        }

        // Adjacent is 0 — expand to all 8 neighbors
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

    return new self($this->width, $this->height, $this->mineCount,
        $rows, $this->minesPlaced, $exploded, $revealedCount);
}
```

**Contrast with upstream:** The upstream `go-sweep` uses recursive calls (`sweep()` calls itself via `forEachSurroundingCellDo`). PHP's stack is more limited, making the iterative approach more robust.

---

### O(1) Win Detection (`Board::isWon`)

**Problem:** Check win condition efficiently without scanning the entire grid.

**Solution:** Track `revealedCount` as a running counter. Win = `revealedCount === width * height - mineCount`.

**Code reference:** `src/Board.php:200-205`

```php
public function isWon(): bool
{
    if ($this->exploded) return false;
    return $this->revealedCount === $this->width * $this->height - $this->mineCount;
}
```

**Upstream comparison:** `go-sweep`'s `checkDidWin()` scans the entire minefield every call:

```go
func checkDidWin(m model) bool {
    for y := range m.minefield {
        for _, mine := range m.minefield[y] {
            if !mine.isMine && !mine.isRevealed {
                return false
            }
        }
    }
    return true
}
```

This is **O(width × height)** per check. The candy-mines approach is **O(1)** — critical when `isWon()` is called after every user input.

**Counter maintenance:** `revealedCount` is incremented in two places:
- `floodReveal()` — every cell revealed during flood fill
- `chord()` — every neighbor revealed during chord

Both always pass through the same code path, ensuring the counter is never out of sync.

---

### Chord Click (`Board::chord`)

**Problem:** Safely reveal neighbors when the player has correctly identified all adjacent mines.

**Algorithm:**
1. Get the revealed cell at (x, y) — must have `adjacent > 0`
2. Count flagged neighbors (`adjFlags`)
3. If `adjFlags === adjacent` (satisfied), reveal all unflagged unrevealed neighbors
4. If any revealed neighbor is a mine, set `exploded = true`

**Code reference:** `src/Board.php:305-360`

```php
public function chord(int $x, int $y): self
{
    $cell = $this->cell($x, $y);
    if ($cell === null || !$cell->revealed || $cell->adjacent === 0) {
        return $this;  // ← must be revealed number cell
    }

    $flagCount = 0;
    // ... count flagged neighbors

    if ($flagCount !== $cell->adjacent) {
        return $this;  // ← not satisfied, no-op
    }

    // Reveal all unflagged, unrevealed neighbors
    // ... loop and reveal
}
```

**Upstream equivalent:** `go-sweep`'s `autoSweep()` and the chord logic in `sweep()`:
```go
func sweep(x, y int, m *model, userInitiatedSweep bool, swept set[point]) {
    // ...
    if cell.isRevealed && userInitiatedSweep {
        adjMines := countAdjacentMines(x, y, *m)
        adjFlags := countAdjacentFlags(x, y, *m)
        if adjFlags >= adjMines {
            autoSweep(x, y, m)  // ← chord
        }
        return
    }
    // ...
}
```

---

## SugarCraft-Specific Innovations

### 1. Immutable Value Objects
Every state transition returns a new instance. `Cell`, `Board`, `Stats`, and `Game` are all immutable. This enables:
- Safe concurrency (none here, but foundationally sound)
- Easy testing without mocks
- Time-travel debugging via `Board::unserialize()`

### 2. Closure-Based RNG Injection
Instead of a static/global RNG, the PRNG is a `Closure(int):int` passed to `Game::__construct`. Tests pin deterministic layouts:

```php
// Always picks index $max → no shuffle → predictable mine placement
$rand = static fn(int $max): int => $max;
$board = Board::blank(5, 5, 3)->reveal(2, 2, $rand);
```

This makes tests fully deterministic without touching global state.

### 3. Mid-Game Serialization
`Board::serialize()` produces a versioned JSON payload:
```json
{"v":1,"w":5,"h":5,"m":3,"p":true,"e":false,"r":17,"c":[[...], ...]}
```
`Board::unserialize()` reconstructs an identical board. This enables save/restore functionality.

### 4. Stats Persistence with Atomic Writes
`DifficultyStats::save()` uses the Homestead pattern:
1. Write JSON to `$dir/.tmp_<random>.json`
2. `rename()` over target (atomic on POSIX)

This prevents corruption if a crash occurs mid-write.

---

## Upstream Differences (go-sweep vs candy-mines)

| Aspect | go-sweep | candy-mines |
|---|---|---|
| Language | Go | PHP 8.3+ |
| Framework | Bubble Tea | candy-core (ReactPHP) |
| First-click safety | TODO comment (unimplemented) | ✅ Implemented |
| Win detection | O(n) grid scan | O(1) via `revealedCount` |
| Mine placement | Shuffle all cells upfront | Deferred until first click, safe-zone exclusion |
| Recursion | Recursive `sweep()` | Iterative `floodReveal()` (stack-based) |
| RNG | `rand.New(rand.NewSource(time.Now().UnixNano()))` | Injectable `Closure(int):int` |
| Serialization | None | Versioned JSON |
| i18n | None | 16 locales |
| Stats persistence | None | `DifficultyStats` with atomic writes |
| Timers | Integer seconds only | Sub-second via `microtime(true)` |
| Board representation | Mutating `[][]cell` | Immutable `list<list<Cell>>` |

**Notable upstream gap:** The upstream `go-sweep` has a TODO comment acknowledging first-click safety isn't implemented:
```go
// TODO instantiate the mines after the first sweep to make sure first
// click never hits a mine
```
The candy-mines port implements this correctly.

---

## Test Coverage

| Test File | Coverage |
|---|---|
| `CellTest.php` | Cell value object transitions, immutability |
| `BoardTest.php` | First-click safety, flood-fill, flag, chord, win/lose detection, serialization |
| `GameTest.php` | Key routing, cursor movement, vim keys, timer, stats, difficulty |
| `DifficultyTest.php` | Enum cases, preset dimensions, `fromDimensions()` |
| `CustomDifficultyTest.php` | Custom dimension validation |
| `DifficultyStatsTest.php` | Atomic persistence, round-trip |

**Deterministic RNG pattern used throughout:**
```php
$rand = static fn(int $max): int => 0;  // pins shuffle order
$board = Board::blank(5, 5, 1)->reveal(2, 2, $rand);
```

---

## File Inventory

```
candy-mines/
├── bin/candy-mines              # CLI entry point
├── composer.json
├── phpunit.xml
├── README.md
├── CALIBER_LEARNINGS.md
├── lang/                        # 16 locales (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar)
├── src/
│   ├── Cell.php                 # Immutable cell value object
│   ├── Board.php                # Core game logic (flood-fill, chord, win, serialization)
│   ├── Game.php                 # TEA Model (cursor, key routing, timer)
│   ├── Difficulty.php           # PHP enum (EASY/MEDIUM/EXPERT)
│   ├── Stats.php                # Immutable stats tracker
│   ├── Lang.php                 # i18n facade
│   ├── Renderer.php              # Pure view function → ANSI string
│   ├── Ui/CustomDifficulty.php  # Validated custom dimensions
│   └── Stats/DifficultyStats.php # Atomic JSON persistence
├── tests/
│   ├── CellTest.php
│   ├── BoardTest.php
│   ├── GameTest.php
│   ├── DifficultyTest.php
│   ├── CustomDifficultyTest.php
│   └── StatsTest.php / DifficultyStatsTest.php
├── examples/
│   └── play.php                 # 12x10 board demo
└── .vhs/
    ├── play.tape                # VHS demo
    └── flagging.tape
```

---

## Strengths

1. **First-click safety fully implemented** — upstream has this as a TODO
2. **O(1) win detection** — upstream scans the entire grid after every move
3. **Deterministic injectable RNG** — enables fully reproducible tests
4. **Mid-game serialization** — save/restore without external storage
5. **Immutable architecture** — no hidden shared state, trivial testability
6. **i18n** — 16 locales, zero runtime overhead for non-i18n apps
7. **Atomic persistence** — stats survive crashes
8. **Iterative flood-fill** — no stack overflow on large boards (vs recursive upstream)

## Weaknesses

1. **Dense 2D array access** — `rows[y][x]` with y=row, x=col — easy to confuse
2. **No undo/redo** — immutable design makes this possible but not yet implemented
3. **Single-threaded** — PHP limitation, but appropriate for TUI
4. **No AI solver** — purely a game, no hint system
5. **Board state in `Game::$board`** — could be decoupled further for replay systems

---

## Conclusion

`candy-mines` is a faithful and improved port of `go-sweep`. It adds significant value over the upstream:
- First-click safety (fully working vs upstream's TODO)
- O(1) win detection (vs O(n) grid scan)
- Deterministic testing via injectable RNG
- Mid-game save/load via JSON serialization
- i18n support
- Atomic stats persistence

The code is clean, thoroughly tested, and follows SugarCraft conventions (immutable value objects, fluent `with*()` setters, `Model` interface for the TEA runtime). The iterative flood-fill avoids PHP's stack limitations, making it robust on Expert-sized boards.
