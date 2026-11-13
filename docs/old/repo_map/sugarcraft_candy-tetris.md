# SugarCraft Candy-Tetris: Innovation & Comparison Report

**Date:** 2026-05-27
**Status:** 🟢 v1.0 Ready
**Errata pass:** 2026-11-12 — removed phantom `src/Input/Das.php` claims (DAS/ARR was never implemented here) and brought the SRS wiring status up to date (§3/§10/§13/§15–§17).
**Upstream:** [Broderick-Westrope/tetrigo](https://github.com/Broderick-Westrope/tetrigo) (Go)
**Dependencies:** `sugar-core`, `sugar-style` (candy-scriptui optional for VS mode)

---

## 1. Executive Summary

`candy-tetris` is a complete, well-tested Tetris implementation ported from Go's `tetrigo` ecosystem to PHP 8.3+ using the SugarCraft TUI stack. It implements the full **Super Rotation System (SRS)**, **7-bag randomizer**, **ghost piece**, **NES-style scoring** with all guideline bonuses (T-Spin, B2B, Combo, Perfect Clear), **level-driven gravity**, **lock delay**, **hold piece**, and a **VS Computer mode** with garbage row passing and AI opponent using weighted board-state heuristics. (2026-11-12 correction: **DAS/ARR input is not implemented** — the original summary listed it from a plan that never shipped; see §10.)

The implementation is architecturally excellent: pure-state classes (no mutations), a full test suite with injectable RNG for deterministic testing, and clean separation between game logic (Model), rendering (View), and AI (Computer).

---

## 2. Architecture Overview

```
src/
├── Tetromino.php          Enum — 7 piece types with SRS rotation states + colors
├── Piece.php             VO — Tetromino + rotation + (x,y), immutable transforms
├── Board.php             VO — 10×24 grid (4 hidden spawn rows), fits/place/clearLines/dropPiece
├── Bag.php               7-bag RNG with peek(); injectable \Closure RNG for tests
├── Score.php            Points/lines/level + NES gravity frames-per-row
├── Game.php              Model — TEA orchestrator, key handling, gravity tick loop
├── GravityMsg.php        Marker Msg — triggers one gravity step
├── Renderer.php          Pure view function — board + sidebar + ghost + hold preview
├── Computer.php          AI opponent — weighted heuristic (height/holes/gaps/lines)
├── VsGame.php            Model — two Games + garbage passing + win/lose detection
├── VsRenderer.php        Split-screen view for VS mode
├── Lang.php              i18n lookup wrapper (extends sugar-core Lang)
├── LaunchMode.php        Single-player / VS launch selection
├── LockDelayMsg.php      Marker Msg — drives the real-time lock-delay window
├── Rotation/
│   └── SrsKickTable.php  Official SRS wall-kick offset tables (J/L/S/T/Z + I)
├── Scoring/
│   └── TSpin.php         T-Spin detector (3-corner rule) + T-Spin Mini
└── Support/
    ├── Bindings.php      Shipped key table + help cards derived from it
    ├── KeyMap.php        User JSON key-override file folded onto the table
    ├── Paths.php         Data-directory / score-file path resolution
    ├── Scores.php        High-score persistence
    └── Version.php       Build/version metadata
```

(2026-11-12: the tree previously listed an `Input/Das.php` that never existed; it now mirrors `src/`.)

### Key Design Decisions

| Decision | Rationale |
|---|---|
| Pure-state classes | Every transform returns new instance; Game update loop is `[nextGame, ?Cmd]` — trivially testable |
| SRS kick table separate from Piece | `SrsKickTable` is stateless, separately testable; `Piece::rotationsWithKicks()` queries it |
| Injectable RNG in Bag | Tests use `Bag(static fn(int $max): int => 0)` for deterministic sequences |
| Score::framesPerRow() frame-rate-agnostic | Returns NES frame counts; consumers convert to µs via `* 16_667` (60 fps) |
| 4 hidden rows above board | `Board::HIDDEN_ROWS = 4`; pieces spawn here before sliding into visible 20-row area |
| `preLockRotation` tracking | Game stores rotation before final move for T-Spin 3-corner detection |

---

## 3. SRS Rotation System

### Implementation

**File:** `src/Rotation/SrsKickTable.php`

The official Tetris Association SRS wall-kick tables are fully implemented:

```php
// J/L/S/T/Z kicks (shared by T, S, Z, J, L)
private const array JLSTZ_KICKS = [
    '0→R' => [[0, 0], [-1, 0], [-1, +1], [0, -2], [-1, -2]],
    'R→2' => [[0, 0], [+1, 0], [+1, -1], [0, +2], [+1, +2]],
    '2→L' => [[0, 0], [+1, 0], [+1, +1], [0, -2], [+1, -2]],
    'L→0' => [[0, 0], [-1, 0], [-1, -1], [0, +2], [-1, +2]],
];

// I-piece kicks (larger offsets due to 4-wide bounding box)
private const array I_KICKS = [
    '0→R' => [[0, 0], [-2, 0], [+1, 0], [-2, -1], [+1, +2]],
    'R→2' => [[0, 0], [-1, 0], [+2, 0], [-1, +2], [+2, -1]],
    '2→L' => [[0, 0], [+2, 0], [-1, 0], [+2, +1], [-1, -2]],
    'L→0' => [[0, 0], [+1, 0], [-2, 0], [+1, -2], [-2, +1]],
];
```

**File:** `src/Piece.php:58-70`

```php
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
```

### Test Coverage

**File:** `tests/Rotation/SrsKickTableTest.php`

```php
public static function jlstzKicksProvider(): array
{
    return [
        '0→R' => ['0→R', 0, 1, [[0, 0], [-1, 0], [-1, +1], [0, -2], [-1, -2]]],
        'R→2' => ['R→2', 1, 2, [[0, 0], [+1, 0], [+1, -1], [0, +2], [+1, +2]]],
        '2→L' => ['2→L', 2, 3, [[0, 0], [+1, 0], [+1, +1], [0, -2], [+1, -2]]],
        'L→0' => ['L→0', 3, 0, [[0, 0], [-1, 0], [-1, -1], [0, +2], [-1, +2]]],
    ];
}
```

### Wiring Status

**Shipped (verified 2026-11-12):** `Game::tryRotate()` iterates `Piece::rotationsWithKicks()`, which queries `SrsKickTable::kicks()` per rotation pair — true SRS with vertical offsets is live in the game loop (the board is y-down; the single sign flip lives at the `rotationsWithKicks()` choke). Behaviour is pinned by `tests/Rotation/SrsKickBehaviorTest.php`.

(Historic note: the 2026-05-27 edition of this document recorded `tryRotate()` as a simplified horizontal-only nudge with the table unwired. That gap has since closed; the `docs/research/libraries/candy-tetris-research.md` plan it pointed at was executed.)

---

## 4. 7-Bag Randomizer

### Implementation

**File:** `src/Bag.php`

Standard Fisher-Yates shuffle producing uniform permutations of all 7 tetrominoes:

```php
final class Bag
{
    private array $pending = [];
    private \Closure $rand;

    public function next(): Tetromino
    {
        if ($this->pending === []) {
            $this->pending = self::shuffle(Tetromino::cases(), $this->rand);
        }
        return array_shift($this->pending);
    }

    public function peek(int $n): array  // Previews without consuming
    {
        while (count($this->pending) < $n) {
            $this->pending = array_merge(
                $this->pending,
                self::shuffle(Tetromino::cases(), $this->rand),
            );
        }
        return array_slice($this->pending, 0, $n);
    }

    private static function shuffle(array $items, \Closure $rand): array
    {
        $n = count($items);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = $rand($i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return array_values($items);
    }
}
```

**Guarantee:** In any 14 consecutive pieces, every tetromino appears at least once and at most twice. No I-piece drought.

**Testability:** `Bag(static fn(int $max): int => 0)` gives deterministic first-piece-I sequences for game logic tests.

---

## 5. Ghost Piece

**File:** `src/Board.php:119-133`

```php
public function dropPiece(Piece $piece): Piece
{
    $candidate = $piece;
    while (true) {
        $next = $candidate->moved(0, 1);
        if (!$this->fits($next)) {
            return $candidate;  // Resting position
        }
        $candidate = $next;
    }
}
```

**Rendering** (`src/Renderer.php:74-76`): Ghost rendered UNDER the live piece with dimmed `▒▒` characters. Correct render order: locked cells → ghost → live piece.

**Test Coverage:** `tests/BoardTest.php` — `testDropPiece()`, `testDropPieceCollapses()`.

---

## 6. NES Scoring System

**File:** `src/Score.php`

### Line-Clear Points

| Lines | Base Points | Formula |
|-------|-------------|---------|
| 1 | 40 | × (level + 1) |
| 2 | 100 | × (level + 1) |
| 3 | 300 | × (level + 1) |
| 4 (Tetris) | 1200 | × (level + 1) |

```php
public function withLines(int $cleared): self
{
    $multiplier = match ($cleared) {
        1 => 40, 2 => 100, 3 => 300, default => 1200,
    };
    $points = $this->points + $multiplier * ($this->level + 1);
    $lines  = $this->lines + $cleared;
    $level  = intdiv($lines, 10);
    return new self($points, $lines, $level);
}
```

### T-Spin Scoring

**File:** `src/Scoring/TSpin.php`

Implemented via 3-corner rule:

```php
// T-Spin points (pre-B2B/multiplier)
T_SPIN_MINI_POINTS = 100
T_SPIN_POINTS      = 400
```

- **Full T-Spin:** ≥3 corners filled (including OOB/wall)

- **T-Spin Mini:** Exactly 2 front corners filled (entry side based on rotation)

**Test Coverage:** `tests/Scoring/TSpinTest.php` — 6 tests covering all corner configurations.

### B2B Multiplier

**File:** `src/Game.php:46,302-306`

```php
public const B2B_MULTIPLIER = 1.5;

// Detection: Tetris or full T-Spin (not mini) after prior B2B-eligible clear
$b2bEligible = $count >= 4 || ($tspin->active && !$tspin->mini);
$b2bActive = $this->backToBack && $b2bEligible;
$b2bMultiplier = $b2bActive ? self::B2B_MULTIPLIER : 1.0;
$b2bBonus = (int)(($score->points - $this->score->points) * ($b2bMultiplier - 1.0));
```

### Combo Bonus

**File:** `src/Game.php:317-318`

```php
$newCombo = $count > 0 ? $this->combo + 1 : 0;
$comboBonus = $newCombo > 0 ? $newCombo * 10 : 0;
```

### Perfect Clear Bonus

**File:** `src/Game.php:329-331`

```php
if ($cleared->isPerfectClear()) {
    $bonus += self::PERFECT_CLEAR_BONUS * $levelMultiplier;  // 5000 × (level + 1)
}
```

**File:** `src/Board.php:143-157`

```php
public function isPerfectClear(): bool
{
    foreach ($this->cells as $row) {
        foreach ($row as $cell) {
            if ($cell !== null) return false;
        }
    }
    return true;
}
```

---

## 7. Level-Driven Gravity

**File:** `src/Score.php:54-71`

Mirrors NES Tetris exactly — frame counts at 60 fps:

```php
public function framesPerRow(): int
{
    return match (true) {
        $this->level <= 8  => 48 - 5 * $this->level,  // 48, 43, 38, 33, 28, 23, 18, 13, 8
        $this->level === 9 => 6,
        $this->level <= 12 => 5,
        $this->level <= 15 => 4,
        $this->level <= 18 => 3,
        $this->level <= 28 => 2,
        default            => 1,
    };
}

public function gravityIntervalUs(): int
{
    return $this->framesPerRow() * 16_667;  // 60 fps = 16,667 µs/frame
}
```

### Gravity Curve

| Level | Frames/Row | Fall Time (20 rows) |
|-------|-----------|-------------------|
| 0 | 48 | 16.0s |
| 1 | 43 | 14.3s |
| 5 | 23 | 7.7s |
| 9 | 6 | 2.0s |
| 10 | 5 | 1.7s |
| 15 | 4 | 1.3s |
| 20 | 3 | 1.0s |
| 29+ | 1 | 0.3s |

---

## 8. Lock Delay

**File:** `src/Game.php:76-89`

```php
public static function startWithLockDelay(?Bag $bag = null, int $lockDelayTicks = 15): self
```

Lock delay countdown starts when piece can no longer move down. Resets on successful move/rotation (SRS-style movement reset). Default: 15 ticks.

**Gravity step handling** (`src/Game.php:186-200`):

```php
if ($this->lockDelayTicks > 0) {
    $newLockDelay = $this->lockDelayTicks - 1;
    // Still return tick to continue lock delay countdown
    return [$game, self::scheduleGravity($game->score)];
}
// When lock delay expires → lockAndSpawn()
```

---

## 9. Hold Piece

**File:** `src/Game.php:388-435`

- Press `c` to swap current piece with held piece

- `canHold` flag resets after each lock (allows one hold per piece)

- If held piece can't fit, hold is rejected (no swap occurs)

```php
private function tryHold(): array
{
    if (!$this->canHold) return [$this, null];

    if ($this->hold === null) {
        // First hold: store current, spawn new
        $newPiece = self::spawn($this->bag->next());
        $game = new self(..., hold: $currentKind, canHold: false, ...);
    } else {
        // Swap: held piece becomes current, current piece becomes held
        $swappedPiece = new Piece($this->hold, 0, $this->piece->x, ...);
        if (!$this->board->fits($swappedPiece)) return [$this, null];
        $game = new self(..., hold: $currentKind, canHold: false, ...);
    }
}
```

---

## 10. Input Handling

### DAS/ARR

**Not implemented (2026-11-12 correction).** This section formerly documented a `src/Input/Das.php` class with `DEFAULT_DAS_US`/`DEFAULT_ARR_US` constants; no such file, directory, or constant exists anywhere in the target. Key auto-repeat reaches the game through sugar-core's `KeyRepeatMsg` (terminal-level repeat), so movement repeat works, but there is no app-side DAS/ARR timing tuner. Treat the rest of this section as a design sketch, not shipped documentation; the genuine open item is listed under §15 opportunities.

### Game Controls

**File:** `src/Game.php:135-165`

| Input | Action |
|-------|--------|
| `←/→` | Move left/right |
| `↑/x` | Rotate clockwise |
| `z` | Rotate counter-clockwise |
| `↓` | Soft drop |
| `Space` | Hard drop |
| `c` | Hold |
| `p` | Pause/resume |
| `q` | Quit |

---

## 11. VS Computer Mode

### Architecture

**File:** `src/VsGame.php`

Two independent `Game` instances (player + computer) combined in a single `Model`. Garbage rows passed between players when lines are cleared.

```php
public function update(Msg $msg): array
{
    [$newPlayer, ] = $this->player->update($msg);
    [$newComputer, ] = $this->computer->update($msg);

    // Player cleared lines → add garbage to computer
    if ($linesCleared > 0) {
        $processedComputer = $newComputer->addGarbageRows($linesCleared);
    }

    // Computer cleared lines → add garbage to player
    if ($computerLinesCleared > 0) {
        $finalPlayer = $newPlayer->addGarbageRows($computerLinesCleared);
    }

    // Win/lose detection
    if ($newPlayer->over) return $this->withComputerWinner($finalPlayer);
    if ($newComputer->over) return $this->withPlayerWinner($finalPlayer, $processedComputer);
}
```

### Garbage Rows

**File:** `src/Game.php:454-509`

```php
public function addGarbageRows(int $count, ?\Closure $rand = null): self
```

- Pushes existing rows down by `$count`

- Fills top `$count` rows with garbage (full rows with one random hole)

- If current piece becomes invalid after adding garbage → game over

### Computer AI

**File:** `src/Computer.php`

Weighted board-state heuristic (not brute-force; considers all x positions + rotations):

```php
private const WEIGHT_HEIGHT  = -4.5;  // Penalize tall columns
private const WEIGHT_HOLES   = -7.5;  // Penalize empty cells under blocks
private const WEIGHT_GAPS    = -3.5;  // Penalize horizontal gaps
private const WEIGHT_LINES   = 9.0;  // Reward completed rows
```

**Evaluation functions:**

- `aggregateHeight()` — sum of column heights from bottom

- `countHoles()` — empty cells with blocks above

- `countGaps()` — horizontal gaps in filled rows

- `countCompleteLines()` — rows with all 10 cells filled

---

## 12. Physics Integration (sugar-spring)

**Reference:** `sugar-spring/src/` (Spring + Projectile physics library)

### Contrast: Discrete vs Continuous

candy-tetris uses **discrete, frame-based gravity** (NES-style frames-per-row). sugar-spring provides **continuous physics** (damped springs, projectiles with acceleration).

| sugar-spring | candy-tetris |
|--------------|--------------|
| `Spring::update($pos, $vel, $target)` — returns new position/velocity | Gravity tick moves piece exactly 1 row per interval |
| `Projectile::update()` — Euler integration: `pos += vel·dt; vel += acc·dt` | Gravity tick: piece falls 1 row instantly |
| O(1) per frame regardless of distance | O(distance) drops (while loop in `dropPiece()`) |
| Used for smooth UI animations (confetti, springs) | Used for game-mechanic gravity (lock delay, fall speed) |

### Potential Integration

Currently **no direct integration** — candy-tetris implements its own discrete gravity via `Score::gravityIntervalUs()` and `GravityMsg` tick scheduling. sugar-spring could be used for:
- Smooth piece falling animations (preview interpolation)

- Particle effects on line clear

- Animated ghost piece (spring-easing to landing position)

- Camera shake on Tetris

This is an **innovation opportunity**: spring-based ghost piece animation could provide smoother visual feedback than the current instant-ghost approach.

---

## 13. Comparison with Upstream (tetrigo)

| Feature | tetrigo (Go) | candy-tetris (PHP) | Notes |
|---------|-------------|-------------------|-------|
| Rotation System | Full SRS | ✓ Full SRS | Wired — verified 2026-11-12: `Game::tryRotate()` iterates `Piece::rotationsWithKicks()` → `SrsKickTable` (this doc formerly recorded horizontal-only while unwired) |
| 7-Bag RNG | ✓ | ✓ | candy-tetris has cleaner injectable RNG |
| Ghost Piece | ✓ | ✓ | Identical approach |
| NES Scoring | ✓ | ✓ + T-Spin/B2B/Combo/PerfectClear | candy-tetris more complete |
| Lock Delay | ✓ | ✓ | Identical |
| Hold Piece | ✓ | ✓ | Identical |
| DAS/ARR | Not in tetrigo | — Not implemented | 2026-11-12 correction: `src/Input/Das.php` never existed; repeat relies on terminal-level auto-repeat (see §10) |
| T-Spin | Not in tetrigo | ✓ | 3-corner rule |
| B2B | Not in tetrigo | ✓ | 1.5× multiplier |
| Combo | Not in tetrigo | ✓ | combo × 10 × (level+1) |
| Perfect Clear | Not in tetrigo | ✓ | +5000 × (level+1) |
| VS Mode | Not in tetrigo | ✓ | Full garbage passing + AI |
| Computer AI | Not in tetrigo | ✓ | Weighted heuristic |

**Key finding:** candy-tetris has **surpassed its upstream** in feature completeness — tetrigo is a simpler NES-era implementation while candy-tetris implements the full Guideline scoring system (T-Spin, B2B, Combo, Perfect Clear) plus VS mode.

---

## 14. Test Coverage

**File:** `tests/`

| Test File | Coverage |
|-----------|----------|
| `BagTest.php` | 7-bag shuffle, peek, deterministic RNG |
| `BinaryLaunchTest.php` / `BinaryWiringTest.php` | `bin/` launch contracts + wiring |
| `BoardTest.php` | fits(), place(), clearLines(), dropPiece(), isPerfectClear() |
| `ColourPostureTest.php` | NO_COLOR / colour-capability posture |
| `ComputerTest.php` | AI bestMove behavioural oracle + evaluate() weight pins |
| `EarnedMomentsTest.php` / `IntroGateTest.php` | intro gate, milestone moments |
| `GameTest.php` / `GameGravityChainTest.php` | Key handling, gravity, hard drop, pause, game over, hold; gravity chain scheduling |
| `GoldenRenderTest.php` / `RendererTest.php` / `VsRendererTest.php` / `VsStripTest.php` | Snapshot byte goldens, split-screen rendering |
| `I18nWiringTest.php` / `LangTest.php` / `LocaleParityTest.php` | locale roster + Lang::t wiring |
| `KeyActuationTest.php` | press/repeat/release actuation rules |
| `LaunchModeTest.php` | single-player / VS mode selection |
| `PharBuildScriptTest.php` | phar build + boot contracts |
| `PieceTest.php` | Rotation, movement, cells(), rotationsWithKicks() |
| `Rotation/SrsKickTableTest.php` / `Rotation/SrsKickBehaviorTest.php` | Table transcription vs published SRS; board behaviour (candidate lists, ceiling/floor kicks) |
| `ScoreTest.php` | Points/lines/level, framesPerRow() |
| `Scoring/TSpinTest.php` | 3-corner rule, mini detection, OOB corners |
| `Support/BindingsDerivationTest.php` / `Support/KeyMapTest.php` | Derived help cards; JSON key-override folding |
| `Support/ScoresTest.php` / `Support/ScoresRecordingTest.php` | High-score persistence |
| `TetrominoTest.php` | cells(), color(), all rotations |
| `ViewportTest.php` | sizing, refusal copy |
| `VsGameTest.php` | Two-player sync, garbage passing |

**Total (2026-11-12):** 497 tests, 7401 assertions. (Historic 2026-05-27 figures: 82 tests / 1669 assertions. Rows for `GravityMsgTest.php`, `Input/DasTest.php` removed — the former was deleted as a vacuous smoke in 2026-11 (behaviour lives in `GameTest`), the latter never existed.)

---

## 15. Innovations & Opportunities

### Already Implemented (Strengths)

1. **Full Guideline Scoring** — T-Spin, B2B, Combo, Perfect Clear (supersedes upstream tetrigo)

2. **Full SRS Rotation** — official kick tables wired into the game loop via `Piece::rotationsWithKicks()` (2026-11-12: this replaces the former "DAS/ARR Input" claim, which documented a class that never shipped)

3. **Drop Scoring** — soft drop +1 point/cell, hard drop +2 points/cell (guideline standard; 2026-11-12: shipped, formerly listed as an open opportunity)

4. **VS Computer Mode** — Garbage passing + AI opponent

5. **Pure State Architecture** — Every class immutable, Game update returns `[nextGame, ?Cmd]`

6. **Injectable RNG** — Deterministic testing without mocking global state

7. **Clean Separation** — SrsKickTable, TSpin as independent, testable units

### Opportunities for Enhancement

1. **DAS/ARR input timing** — an app-side delayed-auto-shift/ARR tuner (terminal repeat today; see §10 correction)

2. **sugar-spring Integration** — Spring-animated ghost piece, line-clear particles

3. **Initial Rotation System (IRS)** — Allow rotation during ARE before piece appears

4. **Initial Hold System (IHS)** — Allow hold during ARE

5. **Diminishing Lock Delay** — Lock delay speeds up with each reset (guideline option)

6. **Ghost Piece Toggle** — Allow hiding ghost per player preference

---

## 16. Key Files Reference

| File | Purpose | Lines |
|------|---------|-------|
| `src/Game.php` | TEA Model orchestrator | 1143 |
| `src/Board.php` | Playfield state | 158 |
| `src/Piece.php` | Falling piece VO + kick application site | 89 |
| `src/Tetromino.php` | Piece enum + SRS shapes | 124 |
| `src/Bag.php` | 7-bag RNG | 114 |
| `src/Score.php` | Points/level/gravity | 86 |
| `src/Renderer.php` | Pure view function | 440 |
| `src/Computer.php` | AI opponent | 195 |
| `src/VsGame.php` | VS mode model | 418 |
| `src/VsRenderer.php` | VS mode view | 378 |
| `src/Rotation/SrsKickTable.php` | SRS kick data | 111 |
| `src/Scoring/TSpin.php` | T-Spin detection | 130 |
| `src/GravityMsg.php` | Gravity tick marker | 25 |

(2026-11-12: counts refreshed; the `src/Input/Das.php` row removed — file never existed.)

---

## 17. Conclusion

candy-tetris is a **production-ready, well-architected** Tetris implementation that has surpassed its Go upstream (tetrigo) in feature completeness. Its pure-state architecture, comprehensive test suite, and clean separation of concerns make it an excellent reference implementation of the SugarCraft TEA pattern.

**Rotation status (2026-11-12):** the former "primary opportunity" — wiring `SrsKickTable` into `Game::tryRotate()` — has shipped; the game now plays with true SRS including vertical kicks.

**Remaining opportunities:** DAS/ARR input timing (§10/§15) and sugar-spring physics for smooth UI animations (ghost piece spring-easing, line-clear particles, camera shake on Tetris) to create a more polished visual experience.
