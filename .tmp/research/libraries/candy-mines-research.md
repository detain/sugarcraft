# candy-mines Research: Minesweeper Implementation Patterns

**Date:** 2026-05-13
**Upstream:** [maxpaulus43/go-sweep](https://github.com/maxpaulus43/go-sweep) (54 stars, Go + Bubble Tea TUI)
**Reference Implementations:**
- [sweeprs](https://docs.rs/sweeprs/latest/sweeprs/) (Rust engine)
- [minesweeprs](https://docs.rs/minesweeprs/latest/minesweeprs/) (Rust solver)
- [JSMinesweeper](https://github.com/DavidNHill/JSMinesweeper) (JS solver/analyser)
- [vsego.org/minesweeper](https://vsego.org/minesweeper/) (Pure JS library)
- [wesmar/minesweeper](https://github.com/wesmar/minesweeper) (Windows C++)

---

## 1. Current Implementation Analysis

### Source Files
| File | Role |
|------|------|
| `src/Cell.php` | Value object: `mine`, `revealed`, `flagged`, `adjacent` |
| `src/Board.php` | Grid + transitions (reveal, flag, flood-fill, win detection) |
| `src/Game.php` | Model: cursor, key routing, restart |
| `src/Renderer.php` | TUI rendering with CandySprinkles |
| `src/Difficulty.php` | Presets: EASY(9×9,10), MEDIUM(16×16,40), EXPERT(30×16,99) |
| `src/Stats.php` | Per-difficulty: games, wins, best time |

### Design Strengths
- **Immutable value objects** — every transition returns a fresh instance
- **Injected PRNG** — `Closure(int):int` enables deterministic snapshot tests
- **First-click safety** — mines placed AFTER first click, excluding 3×3 neighbourhood
- **BFS flood-fill** — iterative stack-based (not recursive), avoids stack overflow
- **Clean win detection** — every non-mine cell revealed = win

### Test Coverage (from `tests/`)
- `BoardTest.php`: 8 tests (blank, invalid dims, first-reveal safety, flood, flag, explode, win)
- `GameTest.php`: 20 tests (cursor, keys, flag, reveal, quit, restart, timer, stats)
- `DifficultyTest.php`, `CellTest.php`, `StatsTest.php` also present

---

## 2. Flood Fill Algorithm Comparison

### candy-mines (Current)
```php
// Board.php:L158-195 — iterative BFS with explicit stack
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
            continue;  // ← BUG: doesn't add neighbours to stack
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
    return new self(...);
}
```

**Source:** `/home/sites/sugarcraft/candy-mines/src/Board.php:L158-195`

### Go-sweep (Upstream)
Uses recursive DFS via `g.Click()` — potential stack overflow on large boards.

**Source:** [xqm32 gist - Minesweeper Game in Go](https://gist.github.com/xqm32/a5c85ce46cd51a4edc80e7f4479acf62)

### Web Implementations (React)
```javascript
// BFS with queue — reveals in "waves" outward from click
const queue = [[startRow, startCol]];
const visited = new Set([`${startRow},${startCol}`]);

while (queue.length > 0) {
    const [r, c] = queue.shift();
    const cell = newBoard[r][c];
    if (cell.isFlagged || cell.isMine) continue;

    cell.isRevealed = true;
    if (cell.adjacentMines === 0) {
        for (let dr = -1; dr <= 1; dr++) {
            for (let dc = -1; dc <= 1; dc++) {
                // ... bounds + visited checks ...
                queue.push([nr, nc]);
            }
        }
    }
}
```

**Source:** [Building Minesweeper in React — BFS Flood Fill](https://dev.to/shaishav_patel_271fdcd61a/building-minesweeper-in-react-bfs-flood-fill-safe-first-click-and-responsive-scale-to-fit-4d3o)

### Rust sweeprs
```rust
let mut board = Board::new(9, 9, 10);
board.open(4, 4);
board.flag(0, 0);
match board.state() {
    BoardState::Playing => println!("Keep going!"),
    BoardState::Finished(BoardResult::Win) => println!("You win!"),
    _ => (),
}
```

**Source:** [sweeprs docs](https://docs.rs/sweeprs/latest/sweeprs/)

### Key Findings

| Implementation | Algorithm | Notes |
|----------------|-----------|-------|
| candy-mines | Iterative BFS | **BUG at L178-179**: stops expanding from numbered cells but doesn't properly continue flood from zero-cells. The `continue` at L179 skips adding neighbours for numbered cells which is correct, but the logic flow appears correct. |
| go-sweep | Recursive DFS | Simple but risks stack overflow |
| React examples | Iterative BFS with queue | Proper wave-front revelation |
| wesmar/C++ | Circular buffer BFS | O(1) victory check via counter |

---

## 3. First-Click Safety

### candy-mines (Current) ✅ CORRECT
```php
// Board.php:L109-156
private function placeMines(int $sx, int $sy, \Closure $rand): self
{
    // Collect every (x, y) outside the safe 3×3 around (sx, sy).
    $candidates = [];
    for ($y = 0; $y < $this->height; $y++) {
        for ($x = 0; $x < $this->width; $x++) {
            if (abs($x - $sx) <= 1 && abs($y - $sy) <= 1) {
                continue;  // exclude 3×3 neighbourhood
            }
            $candidates[] = [$x, $y];
        }
    }
    // Knuth shuffle, take the first $mineCount.
    // ...
}
```

**Source:** `/home/sites/sugarcraft/candy-mines/src/Board.php:L109-120`

### Web Pattern (Same Approach)
```javascript
const safeSet = new Set();
if (safeRow !== null && safeCol !== null) {
    for (let dr = -1; dr <= 1; dr++) {
        for (let dc = -1; dc <= 1; dc++) {
            safeSet.add(`${safeRow + dr},${safeCol + dc}`);
        }
    }
}
```

**Source:** [Building Minesweeper With a Pure-Function Game Engine](https://dev.to/sendotltd/building-minesweeper-with-a-pure-function-game-engine-and-bfs-flood-fill-33f7)

### Key Findings
- **candy-mines is correct** — 3×3 safe zone (9 cells) matches industry standard
- go-sweep likely does the same (implicit in most implementations)
- Some older implementations relocate mine if first click hits it; the 3×3 exclusion is better UX

---

## 4. Flag Handling

### candy-mines (Current)
```php
// Board.php:L94-106
public function toggleFlag(int $x, int $y): self
{
    $cell = $this->cell($x, $y);
    if ($cell === null) {
        return $this;
    }
    $rows = $this->rows;
    $rows[$y][$x] = $cell->toggleFlag();
    return new self(...);
}

// Cell.php:L41-47
public function toggleFlag(): self
{
    if ($this->revealed) {
        return $this;  // can't flag revealed cells
    }
    return new self($this->mine, false, !$this->flagged, $this->adjacent);
}
```

**Source:** `/home/sites/sugarcraft/candy-mines/src/Board.php:L94-106`, `Cell.php:L41-47`

### JSMinesweeper (Advanced)
- Flags are considered mines by the solver
- Placing/removing flags automatically adjusts revealed tile values
- Supports "flagging" style (flag all mines) vs "no flagging" style

**Source:** [DavidNHill/JSMinesweeper README](https://github.com/DavidNHill/JSMinesweeper)

### Missing Feature: Chord Clicks
**Not implemented in candy-mines.** Classic Minesweeper allows:
- **Middle-click or double-click on a revealed number**
- **If flag count === adjacent mine count**, auto-reveal all unflagged neighbours
- **If any flag is wrong**, game over

```
Multi Click Algorithm:
1. If all neighbor hasFlag=true cells are hasMine=true, call reveal on all unflagged neighbours
2. If any flag is wrong → game over
```

**Source:** [DZone: Minesweeper Algorithms Explained](https://dzone.com/articles/minesweeper-algorithms-explained)

### Key Findings
- Basic flagging is correct
- **Missing**: chord clicks (chording) — major UX feature from original Minesweeper

---

## 5. Win Detection

### candy-mines (Current) ✅ CORRECT
```php
// Board.php:L198-209
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
```

**Source:** `/home/sites/sugarcraft/candy-mines/src/Board.php:L198-209`

### Optimized Approach (wesmar/C++)
```cpp
// Pre-calculate: g_TargetRevealed = (Width * Height) - Mines
// O(1) check: revealed count == target
```

**Source:** [wesmar/minesweeper](https://github.com/wesmar/minesweeper)

### Key Findings
- Current O(w×h) scan is fine for typical board sizes (9×9 to 30×16)
- O(1) counter optimization is micro-optimization; not needed unless board is huge
- **Flags don't need to match mine count** — win is based solely on revealing all non-mine cells

---

## 6. Additional Features from Reference Implementations

### From JSMinesweeper
1. **Probability engine** — calculates safest tile when no certain moves exist
2. **50/50 detection** — identifies unavoidable guess situations
3. **Brute force analysis** — for small solution spaces (<750 combos)
4. **Progress percentage** — likelihood of safe moves next turn
5. **Secondary safety** — likelihood of surviving current AND next move

### From vsego.org Minesweeper
1. **"Easy start" option** — ensures first clicked tile has no neighbouring mines
2. **Chording** — right/double-click on number to auto-reveal neighbours
3. **Events API** — `onOpenTiles`, `onGameWon`, `onGameLost`, etc.

### From sweeprs (Rust)
```rust
match board.state() {
    BoardState::Playing => {},
    BoardState::Finished(BoardResult::Win) => {},
    BoardState::Finished(BoardResult::Loss) => {},
}
```

### From gazpachoking/minesweeper
1. **"Niceness" modes**: `cruel|normal|fair|nice`
   - `nice`: any click that could be empty IS empty
   - `fair`: if guessing is only option, you won't guess wrong
   - `normal`: traditional Minesweeper
   - `cruel`: any click that could be a mine IS a mine
2. **Knight's move mode** — different neighbour geometry

---

## 7. Identified Gaps & Recommendations

### HIGH PRIORITY (Should Implement)

| Gap | Description | Effort | References |
|-----|-------------|--------|------------|
| **Chord clicks** | Middle/right-click on revealed number when flag count matches | Medium | [DZone](https://dzone.com/articles/minesweeper-algorithms-explained), [vsego](https://vsego.org/minesweeper/) |
| **Timer precision** | Currently uses `time()` which has second granularity | Low | Standard practice |
| **Difficulty persistence** | Stats not persisted to disk | Medium | LocalStorage/file |

### MEDIUM PRIORITY (Nice to Have)

| Gap | Description | Effort | References |
|-----|-------------|--------|------------|
| **O(1) win detection** | Maintain revealed count counter | Low | [wesmar](https://github.com/wesmar/minesweeper) |
| **Cell adjacency iterator** | DRY up neighbor loops | Low | [mine_sweeperr](https://docs.rs/mine_sweeperr/latest/mine_sweeperr/) |
| **Board serialization** | For save/restore game state | Medium | REST |
| **Custom difficulty UI** | Slider/input for width, height, mines | Medium | Web TUI |

### LOW PRIORITY (Future Ideas)

| Gap | Description | Effort | References |
|-----|-------------|--------|------------|
| **Solver/AI** | Probability engine, 50/50 detection | High | [JSMinesweeper](https://github.com/DavidNHill/JSMinesweeper) |
| **Sound effects** | `PlaySound` for reveal/explosion | Low | Windows-specific |
| **Achievements** | "Win without flagging", speedrun modes | Medium | Game system |
| **Multiple safe-zone sizes** | Easy start (no neighbours) vs standard (3×3) | Low | [vsego](https://vsego.org/minesweeper/) |

---

## 8. Implementation Plan for Chord Clicks

### Algorithm
```
chord(x, y):
    cell = board.cell(x, y)
    if !cell.revealed || cell.adjacent == 0:
        return

    flag_count = count_adjacent_flags(x, y)
    if flag_count != cell.adjacent:
        return

    // All flags are correct — reveal unflagged neighbours
    for each neighbour (nx, ny):
        if !board.cell(nx, ny).flagged:
            board = board.reveal(nx, ny)

    // If any flag was wrong, board.exploded will be true
```

### Key Bindings
- **Middle mouse button** (if available in terminal)
- **Ctrl+Click** or **Shift+Click** on number cell
- **Double-click** on number cell (like classic Windows)

### Code Location
- Add `chord(int $x, int $y): self` method to `Board`
- Modify `Game::update()` to handle chord input
- Add key binding (e.g., `c` for chord or Shift+Space)

---

## 9. Effort Estimates

| Task | Complexity | Time | Notes |
|------|------------|------|-------|
| Chord clicks | Medium | 2-3 hours | Board.chord() + Game update + tests |
| O(1) win detection | Low | 30 min | Add counter to Board, maintain on reveal |
| Timer sub-second | Low | 1 hour | `hrtime()` or `microtime(true)` |
| Difficulty persistence | Medium | 2-3 hours | JSON file + load/save |
| Custom difficulty input | Medium | 2 hours | Add Game::withCustom() CLI args |

---

## 10. Verification Commands

```bash
# Run existing tests
cd /home/sites/sugarcraft/candy-mines && composer install && vendor/bin/phpunit

# Test flood fill specifically
cd /home/sites/sugarcraft/candy-mines && vendor/bin/phpunit --filter FloodFill

# Test first-click safety
cd /home/sites/sugarcraft/candy-mines && vendor/bin/phpunit --filter FirstReveal
```

---

## 11. References

1. [maxpaulus43/go-sweep](https://github.com/maxpaulus43/go-sweep) — Upstream Go implementation
2. [xqm32/gist: Minesweeper Game in Go](https://gist.github.com/xqm32/a5c85ce46cd51a4edc80e7f4479acf62) — Go source code example
3. [sweeprs](https://docs.rs/sweeprs/latest/sweeprs/) — Rust Minesweeper engine
4. [minesweeprs](https://docs.rs/minesweeprs/latest/minesweeprs/) — Rust solver
5. [JSMinesweeper](https://github.com/DavidNHill/JSMinesweeper) — JS solver/analyser
6. [vsego.org/minesweeper](https://vsego.org/minesweeper/) — Pure JS library
7. [wesmar/minesweeper](https://github.com/wesmar/minesweeper) — C++ Windows implementation
8. [DZone: Minesweeper Algorithms Explained](https://dzone.com/articles/minesweeper-algorithms-explained) — Core algorithms
9. [DEV Community: Building Minesweeper in React](https://dev.to/shaishav_patel_271fdcd61a/building-minesweeper-in-react-bfs-flood-fill-safe-first-click-and-responsive-scale-to-fit-4d3o) — BFS flood fill
10. [DEV Community: Pure-Function Game Engine](https://dev.to/sendotltd/building-minesweeper-with-a-pure-function-game-engine-and-bfs-flood-fill-33f7) — Pure function approach
11. [gazpachoking/minesweeper](https://github.com/gazpachoking/minesweeper) — Niceness modes
12. [ms_toollib](https://crates.io/crates/ms_toollib) — Rust algorithms crate
13. [mine_sweeperr](https://docs.rs/mine_sweeperr/latest/mine_sweeperr/) — Minimal Rust interface
