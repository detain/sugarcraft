# Candy-Tetris Research: Tetris Implementation Patterns

**Date:** 2026-05-13
**Project:** candy-tetris (SugarCraft monorepo)
**Upstream:** Broderick-Westrope/tetrigo
**Focus:** SRS rotation, scoring, bag randomizer, ghost piece

---

## Executive Summary

The candy-tetris implementation is in **Phase 9+**, with all core mechanics working. However, it uses a **simplified wall-kick system** rather than true SRS. This research compares approaches across Go (tetrigo), Rust (rubtle/tetrs), and classic implementations to identify specific improvements.

**Current Implementation Status:**

| Feature | Status | Notes |
|---------|--------|-------|
| 7-Bag Randomizer | ✅ Complete | Fisher-Yates shuffle, injectable RNG |
| Ghost Piece | ✅ Complete | Rendered via `Board::dropPiece()` |
| NES Scoring | ✅ Complete | 40/100/300/1200 × (level+1) |
| SRS Rotation | ⚠️ Partial | Only ±2 horizontal nudge, not full SRS |
| Lock Delay | ✅ Complete | 15 tick delay, resets on movement |
| Hold Piece | ✅ Complete | Full implementation with `canHold` |
| T-Spin Detection | ❌ Missing | Not in scope for v0 |
| Back-to-Back Bonus | ❌ Missing | Not in scope for v0 |
| Combo System | ❌ Missing | Not in scope for v0 |

---

## 1. SRS Rotation System

### Current Implementation (candy-tetris)

**Source:** `candy-tetris/src/Game.php:L194-213`

```php
private function tryRotate(int $delta): self
{
    $candidate = $this->piece->rotated($delta);
    // Tiny wall-kick: try ±1 / ±2 horizontal nudges if the
    // bare rotation collides. Not full SRS but covers the
    // most common stuck-in-corner cases.
    foreach ([0, -1, 1, -2, 2] as $kick) {
        $kicked = $candidate->moved($kick, 0);
        if ($this->board->fits($kicked)) {
            // SRS-style: successful rotation resets lock delay
            return new self(
                $this->board, $kicked, $this->bag, $this->score,
                hold: $this->hold,
                canHold: $this->canHold,
                lockDelayTicks: $this->lockDelayTicks,
            );
        }
    }
    return $this;
}
```

**Issues:**
- Only horizontal kicks (±1, ±2) - no vertical kicks
- No distinction between piece types (I vs J/L/S/T/Z)
- No distinction between clockwise/counter-clockwise rotations
- Does not use official SRS wall-kick tables

### Upstream Implementation (tetrigo)

**Source:** `Broderick-Westrope/tetrigo/pkg/tetris/tetrimino.go`

```go
// RotationCompasses maps each Tetrimino type to its rotation behavior definition.
// When rotating clockwise (right), the offsets are applied in order.
// When rotating counter-clockwise (left), the offsets are applied in reverse order.
var RotationCompasses = map[byte]RotationCompass{
    'I': {
        { // North
            {X: -1, Y: 1}, {X: 0, Y: 1}, {X: -3, Y: 1}, {X: 0, Y: 3}, {X: -3, Y: 0},
        },
        { // East
            {X: 2, Y: -1}, {X: 0, Y: -1}, {X: 3, Y: -1}, {X: 0, Y: 0}, {X: 3, Y: -3},
        },
        { // South
            {X: -2, Y: 2}, {X: -3, Y: 2}, {X: 0, Y: 2}, {X: -3, Y: 0}, {X: 0, Y: 3},
        },
        { // West
            {X: 1, Y: -2}, {X: 3, Y: -2}, {X: 0, Y: -2}, {X: 3, Y: -3}, {X: 0, Y: 0},
        },
    },
    'O': {
        { // North
            {X: 0, Y: 0},
        },
        // ... all same (0, 0)
    },
    '6': { // T, S, Z, J, L - all same rotation compass
        { // North
            {X: 0, Y: 0}, {X: -1, Y: 0}, {X: -1, Y: 1}, {X: 0, Y: -2}, {X: -1, Y: -2},
        },
        { // East
            {X: 1, Y: 0}, {X: 0, Y: 0}, {X: 0, Y: -1}, {X: 1, Y: 2}, {X: 0, Y: 2},
        },
        { // South
            {X: -1, Y: 1}, {X: 0, Y: 1}, {X: 0, Y: 2}, {X: -1, Y: -1}, {X: 0, Y: -1},
        },
        { // West
            {X: 0, Y: -1}, {X: 1, Y: -1}, {X: 1, Y: -2}, {X: 0, Y: 1}, {X: 1, Y: 1},
        },
    },
}
```

**Key differences:**
- Full 5-offset SRS wall-kick tables per rotation state
- I-piece has unique kick data (larger kicks due to size)
- O-piece has no kicks (always at center)
- Clockwise vs counter-clockwise rotation applies offsets differently

### Official SRS Wall-Kick Data

From [Tetris Wiki SRS](https://tetris.wiki/SRS):

**J, L, S, T, Z Tetromino Wall Kick Data:**
| Rotation | Test 1 | Test 2 | Test 3 | Test 4 | Test 5 |
|----------|--------|--------|--------|--------|--------|
| 0→R | (0,0) | (-1,0) | (-1,+1) | (0,-2) | (-1,-2) |
| R→0 | (0,0) | (+1,0) | (+1,-1) | (0,+2) | (+1,+2) |
| R→2 | (0,0) | (+1,0) | (+1,-1) | (0,+2) | (+1,+2) |
| 2→R | (0,0) | (-1,0) | (-1,+1) | (0,-2) | (-1,-2) |
| 2→L | (0,0) | (+1,0) | (+1,+1) | (0,-2) | (+1,-2) |
| L→2 | (0,0) | (-1,0) | (-1,-1) | (0,+2) | (-1,+2) |
| L→0 | (0,0) | (-1,0) | (-1,-1) | (0,+2) | (-1,+2) |
| 0→L | (0,0) | (+1,0) | (+1,+1) | (0,-2) | (+1,-2) |

**I Tetromino Wall Kick Data:**
| Rotation | Test 1 | Test 2 | Test 3 | Test 4 | Test 5 |
|----------|--------|--------|--------|--------|--------|
| 0→R | (0,0) | (-2,0) | (+1,0) | (-2,-1) | (+1,+2) |
| R→0 | (0,0) | (+2,0) | (-1,0) | (+2,+1) | (-1,-2) |
| R→2 | (0,0) | (-1,0) | (+2,0) | (-1,+2) | (+2,-1) |
| 2→R | (0,0) | (+1,0) | (-2,0) | (+1,-2) | (-2,+1) |
| 2→L | (0,0) | (+2,0) | (-1,0) | (+2,+1) | (-1,-2) |
| L→2 | (0,0) | (-2,0) | (+1,0) | (-2,-1) | (+1,+2) |
| L→0 | (0,0) | (+1,0) | (-2,0) | (+1,-2) | (-2,+1) |
| 0→L | (0,0) | (-1,0) | (+2,0) | (-1,+2) | (+2,-1) |

### Recommendation: SRS Full Implementation

**Effort:** Medium (4-6 hours)
**Priority:** High

Create a `RotationSystem` class with SRS wall-kick tables. This requires:

1. Define kick tables as const arrays (I, J/L/S/T/Z, O separately)
2. Add `RotationSystem::getKicks(Tetromino, fromState, toState)` method
3. Modify `tryRotate()` to use vertical kicks in addition to horizontal
4. Add tests for all SRS test cases (see Tetris Wiki SRS tests)

---

## 2. Scoring System

### Current Implementation (candy-tetris)

**Source:** `candy-tetris/src/Score.php:L29-44`

```php
public function withLines(int $cleared): self
{
    if ($cleared <= 0) {
        return $this;
    }
    $multiplier = match ($cleared) {
        1 => 40,
        2 => 100,
        3 => 300,
        default => 1200, // 4+ all credit as Tetris
    };
    $points = $this->points + $multiplier * ($this->level + 1);
    $lines  = $this->lines + $cleared;
    $level  = intdiv($lines, 10);
    return new self($points, $lines, $level);
}
```

**This is NES-style scoring:**
- Single: 40 × (level+1)
- Double: 100 × (level+1)
- Triple: 300 × (level+1)
- Tetris: 1200 × (level+1)

### Guideline Scoring System (Modern Tetris)

From [Tetris Wiki Scoring](https://tetris.wiki/Scoring):

| Action | Points |
|--------|--------|
| Single | 100 × level |
| Double | 300 × level |
| Triple | 500 × level |
| Tetris | 800 × level |
| T-Spin Single | 800 × level |
| T-Spin Double | 1200 × level |
| T-Spin Triple | 1600 × level |
| Back-to-Back Tetris | 1200 × level |
| Combo | (50 × combo_count) × level |
| Soft drop | 1 per cell |
| Hard drop | 2 per cell |

**Key features in modern scoring:**
1. **T-Spin detection** - awards bonus for rotating T into tight spaces
2. **Back-to-Back (B2B)** - 1.5× multiplier for consecutive difficult clears
3. **Combo system** - consecutive line clears earn bonus × combo_count
4. **Perfect clear** - 2000-3200 bonus for clearing entire board

### Recommendation: Enhanced Scoring (Optional)

**Effort:** Medium-High (8-12 hours for T-Spin + B2B + Combo)
**Priority:** Medium

For v0/v1.0, the NES scoring is appropriate as noted in README. If targeting guideline compliance:

1. Add T-Spin detection (requires checking 3 corners of T-piece)
2. Track B2B state for difficult clears
3. Track combo count with decay timer
4. Add soft/hard drop point scoring

---

## 3. Bag Randomizer

### Current Implementation (candy-tetris)

**Source:** `candy-tetris/src/Bag.php`

```php
final class Bag
{
    /** @var list<Tetromino> */
    private array $pending = [];
    /** @var \Closure(int): int */
    private \Closure $rand;

    public function next(): Tetromino
    {
        if ($this->pending === []) {
            $this->pending = self::shuffle(Tetromino::cases(), $this->rand);
        }
        return array_shift($this->pending);
    }

    public function peek(int $n): array
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

**Status:** ✅ **This is correct and complete.**
- Uses proper Fisher-Yates shuffle
- 7-bag system guarantees all 7 pieces appear before any repeats
- Injectable RNG for deterministic testing
- `peek()` correctly handles bag refill for preview

### Comparison with Upstream (tetrigo)

The tetrigo implementation uses the same 7-bag approach. The candy-tetris implementation is actually cleaner with the injectable RNG.

**Recommendation:** No changes needed. This is production-ready.

---

## 4. Ghost Piece

### Current Implementation (candy-tetris)

**Source:** `candy-tetris/src/Renderer.php:L51-86`

```php
private static function renderBoard(Game $game): string
{
    $rows = $game->board->rows();
    $piece = $game->piece;
    $ghost = $game->board->dropPiece($piece);  // Computes ghost position
    $pieceCells = self::cellMap($piece->cells());
    $ghostCells = self::cellMap($ghost->cells());

    // ... rendering with ghost rendered UNDER the live piece
    if (isset($ghostCells[$key])) {
        $line .= self::ghost($piece->kind);  // Faded rendering
        continue;
    }
    // ...
}
```

**Source:** `candy-tetris/src/Board.php:L119-133`

```php
public function dropPiece(Piece $piece): Piece
{
    $candidate = $piece;
    while (true) {
        $next = $candidate->moved(0, 1);
        if (!$this->fits($next)) {
            return $candidate;  // Returns resting position
        }
        $candidate = $next;
    }
}
```

**Status:** ✅ **Correct implementation.**
- Ghost is computed by dropping piece until collision
- Ghost rendered with dimmed style (`ghost()` method)
- Ghost appears under live piece in render order

### Alternative Approaches (from research)

From [Hard Drop Wiki - Ghost Piece](https://harddrop.com/wiki/Ghost_piece):

1. **Standard Ghost** - Shows where piece will land (current approach)
2. **Temporary Landing System (TLS)** - Arika's variant with different visuals
3. **Ghost can be toggled** - Some games allow hiding ghost

The candy-tetris implementation follows the standard guideline approach.

**Recommendation:** No changes needed.

---

## 5. Additional Improvements Identified

### 5.1 DAS (Delayed Auto-Shift)

**Not implemented.** Guideline specifies:
- Initial delay: 150-180ms before repeat starts
- Repeat rate: 50-80ms between shifts

**Effort:** Low (2-3 hours)
**Priority:** Low (nice-to-have for hardcore players)

### 5.2 Initial Rotation System (IRS)

**Not implemented.** Allows rotation during ARE (Appearance Recovery Delay) before piece appears.

**Effort:** Low (1-2 hours)
**Priority:** Low

### 5.3 Initial Hold System (IHS)

**Not implemented.** Can hold during ARE before first piece appears.

**Effort:** Low (1-2 hours)
**Priority:** Low

### 5.4 Lock Delay Modes

**Currently implemented** with movement reset (15 ticks).

Guideline specifies 3 modes:
1. **Lock Delay Only** - locks after N moves/rotations regardless of downward movement
2. **Movement Reset** - resets timer on movement (current implementation)
3. **Diminishing Lock Delay** - timer speeds up with each reset

**Effort:** Already implemented correctly.
**Priority:** N/A

---

## 6. Prioritized Recommendations

### High Priority

| # | Improvement | Effort | Benefit | Notes |
|---|-------------|--------|---------|-------|
| 1 | **Full SRS Rotation** | 4-6h | Authentic feel, enables advanced spins | Use official kick tables from Tetris Wiki |

### Medium Priority

| # | Improvement | Effort | Benefit | Notes |
|---|-------------|--------|---------|-------|
| 2 | T-Spin Detection | 6-8h | Advanced scoring for skilled play | Requires 3-corner detection method |
| 3 | Back-to-Back Bonus | 2-3h | Rewards consistency | Tracks difficult clears |
| 4 | Combo System | 2-3h | Rewards streak play | Combo counter with decay |

### Low Priority (Nice-to-Have)

| # | Improvement | Effort | Benefit | Notes |
|---|-------------|--------|---------|-------|
| 5 | DAS/ARR | 2-3h | Better keyboard feel | For hardcore players |
| 6 | Perfect Clear Detection | 2-3h | Special bonus scoring | Score table available |
| 7 | IRS/IHS | 2-4h | Guideline compliance | Not critical for v1.0 |

### No Changes Needed

| Feature | Status |
|---------|--------|
| 7-Bag Randomizer | ✅ Complete |
| Ghost Piece | ✅ Complete |
| NES Scoring | ✅ Complete (appropriate for v0) |
| Lock Delay | ✅ Complete |
| Hold Piece | ✅ Complete |

---

## 7. Implementation Plan for SRS

### Step 1: Create RotationSystem Class

```php
// src/RotationSystem.php
final class RotationSystem
{
    // SRS wall-kick data for J, L, S, T, Z (5 kicks per state)
    private const KICKS_JLSTZ = [
        // 0->R, R->0, R->2, 2->R, 2->L, L->2, L->0, 0->L
        '0R' => [[0,0], [-1,0], [-1,1], [0,-2], [-1,-2]],
        'R0' => [[0,0], [1,0], [1,-1], [0,2], [1,2]],
        'R2' => [[0,0], [1,0], [1,-1], [0,2], [1,2]],
        '2R' => [[0,0], [-1,0], [-1,1], [0,-2], [-1,-2]],
        '2L' => [[0,0], [1,0], [1,1], [0,-2], [1,-2]],
        'L2' => [[0,0], [-1,0], [-1,-1], [0,2], [-1,2]],
        'L0' => [[0,0], [-1,0], [-1,-1], [0,2], [-1,2]],
        '0L' => [[0,0], [1,0], [1,1], [0,-2], [1,-2]],
    ];

    // SRS wall-kick data for I piece (5 kicks per state)
    private const KICKS_I = [
        '0R' => [[0,0], [-2,0], [1,0], [-2,-1], [1,2]],
        'R0' => [[0,0], [2,0], [-1,0], [2,1], [-1,-2]],
        'R2' => [[0,0], [-1,0], [2,0], [-1,2], [2,-1]],
        '2R' => [[0,0], [1,0], [-2,0], [1,-2], [-2,1]],
        '2L' => [[0,0], [2,0], [-1,0], [2,1], [-1,-2]],
        'L2' => [[0,0], [-2,0], [1,0], [-2,-1], [1,2]],
        'L0' => [[0,0], [1,0], [-2,0], [1,-2], [-2,1]],
        '0L' => [[0,0], [-1,0], [2,0], [-1,2], [2,-1]],
    ];

    // O piece has no kicks
    private const KICKS_O = [
        '0R' => [[0,0]],
        'R0' => [[0,0]],
        'R2' => [[0,0]],
        '2R' => [[0,0]],
        '2L' => [[0,0]],
        'L2' => [[0,0]],
        'L0' => [[0,0]],
        '0L' => [[0,0]],
    ];

    /**
     * Get wall-kick offsets for a rotation.
     *
     * @param Tetromino $kind The tetromino type
     * @param int $fromState Current rotation state (0-3)
     * @param int $toState Target rotation state (0-3)
     * @return array<array{int,int}> Array of [dx, dy] offset pairs to try
     */
    public static function getKicks(Tetromino $kind, int $fromState, int $toState): array
    {
        $key = match(true) {
            $kind === Tetromino::I => 'I',
            $kind === Tetromino::O => 'O',
            default => 'JLSTZ',
        };

        $stateFrom = $fromState % 4;
        $stateTo = $toState % 4;

        // Determine rotation direction
        $isClockwise = ($stateTo === ($stateFrom + 1) % 4);
        $isCounterClockwise = ($stateTo === ($stateFrom + 3) % 4);

        if ($isClockwise) {
            $direction = match("$stateFrom->$stateTo") {
                '0->1' => '0R',
                '1->2' => 'R2',
                '2->3' => '2R',
                '3->0' => 'R0',
                default => '0R',
            };
        } elseif ($isCounterClockwise) {
            $direction = match("$stateFrom->$stateTo") {
                '0->3' => '0L',
                '3->2' => 'L2',
                '2->1' => '2L',
                '1->0' => 'L0',
                default => '0L',
            };
        } else {
            // 180-degree rotation - use different table
            $direction = match("$stateFrom->$stateTo") {
                '0->2', '2->0' => '02',
                '1->3', '3->1' => '13',
                default => '02',
            };
        }

        return match($key) {
            'I' => self::KICKS_I[$direction] ?? [[0,0]],
            'O' => self::KICKS_O[$direction] ?? [[0,0]],
            default => self::KICKS_JLSTZ[$direction] ?? [[0,0]],
        };
    }
}
```

### Step 2: Modify Game::tryRotate()

```php
private function tryRotate(int $delta): self
{
    $fromState = $this->piece->rotation;
    $candidate = $this->piece->rotated($delta);
    $toState = $candidate->rotation;

    // Get SRS wall-kicks
    $kicks = RotationSystem::getKicks($this->piece->kind, $fromState, $toState);

    foreach ($kicks as [$dx, $dy]) {
        $kicked = $candidate->moved($dx, $dy);
        if ($this->board->fits($kicked)) {
            return new self(
                $this->board, $kicked, $this->bag, $this->score,
                hold: $this->hold,
                canHold: $this->canHold,
                lockDelayTicks: $this->lockDelayTicks,
            );
        }
    }
    return $this;
}
```

### Step 3: Add Tests

Create test cases from official SRS test suite (see [Tetris Wiki SRS](https://tetris.wiki/SRS#Tests)).

---

## 8. References

### Documentation
- [Tetris Wiki - SRS](https://tetris.wiki/SRS)
- [Tetris Wiki - Scoring](https://tetris.wiki/Scoring)
- [Tetris Wiki - Guideline](https://tetris.wiki/Guideline)
- [Hard Drop Wiki - Ghost Piece](https://harddrop.com/wiki/Ghost_piece)
- [Tetris Wiki - Wall Kick](https://tetris.wiki/Wall_kick)

### Implementations
- [Broderick-Westrope/tetrigo](https://github.com/Broderick-Westrope/tetrigo) - Go implementation
- [DylanBulfin/tetrs](https://github.com/DylanBulfin/tetrs) - Rust implementation
- [SamillWong/PPTetris](https://github.com/SamillWong/PPTetris) - C++ implementation
- [lyze/Tetris](https://github.com/lyze/Tetris) - Java implementation

### Game Variants
- [Classic Tetris World Championship (CTWC)](http://www.ClassicTetris.com/)
- [BST (Basic Style Tetris)](https://tetris.wiki/BST) - Tournament standard before guideline

---

## 9. Conclusion

The candy-tetris implementation is solid and well-tested at **82 tests, 1669 assertions**. The core mechanics (bag randomizer, ghost piece, lock delay, hold) are correctly implemented.

The main improvement opportunity is **full SRS rotation**, which would bring the implementation to true guideline compliance and enable the advanced "spins" that competitive players expect.

**Recommended next step:** Implement `RotationSystem` class with official SRS kick tables as outlined in Section 7.
