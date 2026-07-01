# Audit Findings: candy-tetris

**Date:** Tue Jun 30 2026  
**Auditor:** Code Audit  
**Files Reviewed:** 27 PHP files (12 source, 15 tests)

---

## Severity Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| **HIGH** | 1 | AI rotation bug when `rotDelta=0` |
| **MEDIUM** | 2 | AI doesn't use wall kicks; garbage overflow check off-by-one |
| **LOW** | 5 | Various minor issues (lock delay on rotation, performance, code quality) |
| **NONE** | 3 | Memory leaks, security, PHP compatibility all clean |

---

## 1. BUGS & EDGE CASES

### Issue 1: AI Rotation Bug (HIGH)
**Location:** `Game.php:309`  
**Severity:** HIGH  
**Description:** In `applyAiMove()`, when `$rotDelta === 0` (no rotation needed), the loop `for ($i = 0; $i < $rotDelta; $i++)` executes `rotated(1)` once, incorrectly rotating the piece by 90° clockwise even when no rotation was requested.

```php
for ($i = 0; $i < $rotDelta; $i++) {  // $rotDelta=0 → loop runs once due to bug
    $piece = $piece->rotated(1);      // ← rotates when it shouldn't
}
```

**Recommendation:** The loop condition should be `for ($i = 0; $i < $rotDelta; $i++)`. If `$rotDelta` is 0, the loop should not execute at all. Verify that `bestMove()` returns 0-3 for rotation deltas and that the calling code passes the correct value.

---

### Issue 2: AI Doesn't Apply Wall Kicks (MEDIUM)
**Location:** `Game.php:305-321` (`applyAiMove`)  
**Severity:** MEDIUM  
**Description:** `applyAiMove()` uses naive `rotated()` for rotation, then applies x-shift. It never tries SRS wall kick offsets. The human player path via `tryRotate()` properly tests all wall kicks via `rotationsWithKicks()`. If the naive rotation fails at the AI's chosen x-position, the piece is used as-is without trying wall kicks that might make it valid.

```php
// Naive rotation - no wall kicks
$piece = $piece->rotated(1);

// Apply horizontal shift if it fits
$shifted = $piece->moved($dx, 0);
if ($this->board->fits($shifted)) {
    $piece = $shifted;
}
// No wall kick retry if fits() fails!
```

Compare to `tryRotate()` at `Game.php:241` which iterates through `rotationsWithKicks()`.

**Recommendation:** Modify `applyAiMove()` to use `rotationsWithKicks()` and iterate through candidates, applying the x-shift to each candidate and finding the first that fits. This mirrors the human player behavior.

---

### Issue 3: Garbage Overflow Check Off-By-One (MEDIUM)
**Location:** `Game.php:482-495` (`addGarbageRows`)  
**Severity:** MEDIUM  
**Description:** The top-out detection loop only checks rows `[0, count-1]` but adding `count` rows displaces content from row `count` as well. Row `count` could contain locked content that gets pushed into the visible area but is never checked.

```php
for ($r = 0; $r < $count; $r++) {           // Only checks 0 to count-1
    foreach ($rows[$r] as $cell) {
        if ($cell !== null) { /* game over */ }
    }
}
// Content in row $count (displaced upward) is never checked!
```

For example, `addGarbageRows(2)` checks rows 0 and 1, but the content originally in row 2 gets displaced to row 2 (not row 0 or 1), and that displaced content is never validated.

**Recommendation:** Change the loop to `for ($r = 0; $r <= $count; $r++)` to catch content in the row that gets displaced to position `count`.

---

### Issue 4: Computer Decision Tracking Not Reset on Lock (LOW)
**Location:** `VsGame.php:146-165` (`advanceComputer`)  
**Severity:** LOW  
**Description:** `computerDecisionFor` is updated to `$currentKind` (the NEW piece just spawned) at line 158, but this happens inside `advanceComputer()` which is called after `lockAndSpawn()` already produced a new game state with a new piece. This causes:

1. On the gravity tick when a piece locks, `advanceComputer()` is called
2. The new piece's kind is NOT equal to `computerDecisionFor`  
3. A NEW `bestMove()` is immediately computed for the piece that just spawned

This causes wasted computation (computing a move for a piece, then immediately computing another move for its replacement). The behavior is functionally correct but inefficient.

**Recommendation:** Update `computerDecisionFor` to the new piece's kind before calling `advanceComputer()`, or defer the `bestMove()` computation to the next tick after the piece has actually moved.

---

### Issue 5: Lock Delay Re-arm on Rotation (LOW)
**Location:** `Game.php:248-250`  
**Severity:** LOW  
**Description:** Standard SRS only re-arms lock delay on successful translation (left/right/down). This implementation also re-arms on successful rotation. This is an intentional deviation from SRS, but worth documenting. The behavior may surprise players familiar with standard Tetris.

**Recommendation:** Document this as an intentional deviation in `CALIBER_LEARNINGS.md` or align with strict SRS behavior.

---

## 2. PERFORMANCE PROBLEMS

### Issue 6: Redundant `dropPiece()` Call in Renderer (LOW)
**Location:** `Renderer.php:78` and `Renderer.php:74-78`  
**Severity:** LOW  
**Description:** In `renderBoard()`, `dropPiece()` is called to compute the ghost position. This is computed fresh on every render call. While not incorrect, it could be cached in the Game state if render performance becomes a concern.

```php
$ghost = $game->board->dropPiece($piece);  // Computed fresh every render
```

**Recommendation:** Consider caching the ghost piece position in the Game state, or accept as acceptable render cost since `dropPiece()` is O(board_height) = ~24 iterations.

---

### Issue 7: Unnecessary Array Creation in `range()` (LOW)
**Location:** `Computer.php:39`  
**Severity:** LOW  
**Description:** Uses `range(0, 3)` which creates an array `[0, 1, 2, 3]`, when a simple `for` loop would avoid the allocation.

```php
foreach (range(0, 3) as $rotations) {  // Creates [0,1,2,3] array
    $rotated = $piece;
    for ($r = 0; $r < $rotations; $r++) {
        $rotated = $rotated->rotated(1);
    }
```

**Recommendation:** Use `for ($r = 0; $r < 4; $r++)` instead and restructure the inner loop accordingly.

---

## 3. MEMORY LEAKS

**No memory leaks detected.**  
- All classes are properly immutable (Piece returns new instances, Game uses `mutate()`, Board creates new instances)
- Closures capture by value, not reference
- No stream/file handle usage
- No circular references in the Game/Board/Piece graph

---

## 4. SECURITY

**No security issues detected.**  
This is a pure game logic library with no external input, file operations, or network access. Input comes via typed `Msg` objects from the SugarCraft runtime (`KeyMsg`, `GravityMsg`). No user-controlled strings are interpolated into queries, paths, or commands.

---

## 5. COMPLEXITY / CODE QUALITY

### Issue 8: `VsRenderer::renderSidebar()` Creates Card Closure Per Call (LOW)
**Location:** `VsRenderer.php:119-123`  
**Severity:** LOW  
**Description:** The `$card` closure is recreated on every `renderSidebar()` call. While not a correctness issue, creating the closure repeatedly is slightly inefficient.

```php
$card = static fn(string $body): string => Style::new()
    ->border(Border::normal())
    ->padding(0, 1)
    ->width(16)
    ->render($body);
```

**Recommendation:** Make `$card` a static class constant or extract to a reusable helper method.

---

### Issue 9: Computer Color Shifting is Naive (LOW)
**Location:** `VsRenderer.php:157`  
**Severity:** LOW  
**Description:** Uses `($kind->color() + 150) % 256` which is a simple modulo color wheel shift. This doesn't reliably produce a "magenta" hue for all colors and may produce similar colors for adjacent hues. For example, color 51 (I piece cyan) becomes 201, but color 196 (Z piece red) becomes 86.

**Recommendation:** Use a proper hue-shifting algorithm in HSL space, or accept as stylistic choice.

---

## 6. MISSING FEATURES / INCOMPLETE PORTS

**No critical missing features detected.**  
The implementation includes all major Tetris features:
- ✅ 7-bag RNG (`Bag.php`)
- ✅ SRS wall kicks (`SrsKickTable.php`, `Piece::rotationsWithKicks()`)
- ✅ Ghost piece (`Renderer.php:78`, `Game.php` spawns ghost via `board->dropPiece()`)
- ✅ Hold piece (`Game::tryHold()`)
- ✅ Lock delay (`Game.php:57-58, 207-211`)
- ✅ T-Spin detection 3-corner rule (`TSpin.php`)
- ✅ T-Spin Mini detection (`TSpin.php:63-64`)
- ✅ Back-to-Back bonus (`Game.php:334-338, 401`)
- ✅ Combo counter (`Game.php:349-350, 384`)
- ✅ Perfect clear detection (`Board::isPerfectClear()`, `Game.php:366-368`)
- ✅ VS Computer mode with garbage passing (`VsGame.php`, `Game::addGarbageRows()`)
- ✅ NES Tetris gravity curve (`Score::framesPerRow()`)
- ✅ NES Tetris scoring (`Score::withLines()`)

---

## 7. PHP 8.3/8.4 COMPATIBILITY

**No compatibility issues detected.**  
All files properly declare:
- `declare(strict_types=1);` on line 1
- Modern PHP 8.3+ features used: `readonly` properties, constructor property promotion, `match` expressions, first-class `callable` syntax, named arguments

The `composer.json` specifies `"php": ">=8.3"` which is correct for the features used.

---

## 8. ASYNC/REACCPHP IMPROVEMENTS

**No async improvements applicable.**  
This is a synchronous game logic library designed as a SugarCraft `Model`. The `Model` interface from `candy-core` uses a synchronous tick-based loop via `\Closure` return values from `init()` and `update()`. The `VsGame::scheduleTick()` properly returns `\Closure` tick handlers.

If async support is needed (e.g., for network play), the tick scheduling could be converted to use `candy-async` for non-blocking delays, but this would be a new feature rather than a compatibility fix.

---

## Critical Fix Checklist

Before shipping VS Computer mode, fix these items:

1. **[HIGH] `Game.php:309`**: Fix AI rotation bug - `for ($i = 0; $i < $rotDelta; $i++)` should not execute when `$rotDelta = 0`
2. **[MEDIUM] `Game.php:305-321`**: Consider using `rotationsWithKicks()` in `applyAiMove()` for proper SRS wall kick support
3. **[MEDIUM] `Game.php:482`**: Change `for ($r = 0; $r < $count; $r++)` to `for ($r = 0; $r <= $count; $r++)`

---

*End of audit findings.*
