# Implementation Plan: candy-tetris

**Status:** Not Started  
**Phase:** 1  
**Updated:** 2026-06-30

## Goal

Fix all HIGH and MEDIUM severity bugs in candy-tetris game logic (AI rotation, wall kicks, garbage overflow) and address LOW severity issues (performance, code quality, documentation).

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| AI rotation bug is HIGH severity | Critical correctness issue — AI rotates when no rotation requested | `findings/candy-tetris.md` |
| Wall kick support needed for AI | AI should behave like human player with full SRS support | `findings/candy-tetris.md` |
| Garbage overflow off-by-one is MEDIUM | Could miss locked content that gets pushed into visible area | `findings/candy-tetris.md` |
| Document lock delay on rotation | Deviation from strict SRS worth documenting per AGENTS.md | `findings/candy-tetris.md` |
| Use `rotationsWithKicks()` for AI | Mirrors human player path via `tryRotate()` | `candy-tetris/src/Piece.php:58-70` |

---

## Phase 1: HIGH Priority Fixes [PENDING]

### 1.1 AI Rotation Bug Fix ← CURRENT

**Severity:** HIGH  
**Location:** `candy-tetris/src/Game.php:309`

**What is expected:**
- Fix the for loop in `applyAiMove()` so it does NOT execute when `$rotDelta === 0`
- The audit finding states: when `$rotDelta === 0`, the loop "executes `rotated(1)` once"

**Why this matters:**
- Critical correctness issue — the AI rotates pieces when no rotation was requested
- This causes the computer to make invalid moves and lose competitive matches

**Investigation notes:**
- `Game.php:309`: `for ($i = 0; $i < $rotDelta; $i++)`
- Standard PHP: `for ($i = 0; $i < 0; $i++)` should NOT execute (0 < 0 is false)
- BUT the audit finding explicitly states it executes once — this suggests either:
  1. The finding describes a different condition (`<=` vs `<`), OR
  2. There's a runtime behavior issue
- `Computer.php:71`: `bestMove()` returns `$bestRot = $rotations` where `$rotations` is 0-3
- `VsGame.php:154`: `[$dx, $rotDelta] = $this->computerAI->bestMove(...)`

**Proposed fix:**
```php
// Current (line 309):
for ($i = 0; $i < $rotDelta; $i++) {
    $piece = $piece->rotated(1);
}

// The audit finding suggests this might be the bug (uses <=):
for ($i = 0; $i <= $rotDelta; $i++) {  // ← THIS IS THE BUG
    $piece = $piece->rotated(1);
}

// Should be:
for ($i = 0; $i < $rotDelta; $i++) {  // ← Correct: 0 iterations when rotDelta=0
    $piece = $piece->rotated(1);
}
```

**Conditions for success:**
- [ ] Write test `testApplyAiMoveWithZeroRotationDoesNotRotate` that verifies `applyAiMove(0, dx)` does NOT rotate the piece
- [ ] `ComputerTest.php` continues passing
- [ ] `VsGameTest.php` passes

**Related code:**
- `candy-tetris/src/Game.php:305-321` (`applyAiMove` method)
- `candy-tetris/src/Computer.php:32-76` (`bestMove` method)
- `candy-tetris/src/VsGame.php:146-165` (`advanceComputer` method)

---

### 1.2 AI Wall Kicks Support

**Severity:** MEDIUM  
**Location:** `candy-tetris/src/Game.php:305-321`

**What is expected:**
- Modify `applyAiMove()` to use `rotationsWithKicks()` for proper SRS wall kick support
- This mirrors the human player behavior in `tryRotate()`

**Why this matters:**
- Human players can use wall kicks to escape tight situations near walls
- AI should have the same capability to be a fair opponent
- Without wall kicks, the AI may make suboptimal or invalid moves

**Investigation notes:**
- `Game.php:236-255`: `tryRotate()` properly iterates `rotationsWithKicks()` and returns first that fits
- `Piece.php:58-70`: `rotationsWithKicks()` returns array with [naive rotation, wall kick candidates...]
- `SrsKickTable.php:26-61`: Official SRS wall kick offsets for all piece types
- Current `applyAiMove()` just uses naive `rotated()` without any kick retries

**Proposed fix:**
```php
// Current (lines 305-321):
public function applyAiMove(int $rotDelta, int $dx): self
{
    // Rotate the current piece
    $piece = $this->piece;
    for ($i = 0; $i < $rotDelta; $i++) {
        $piece = $piece->rotated(1);
    }
    // Apply horizontal shift if it fits
    $shifted = $piece->moved($dx, 0);
    if ($this->board->fits($shifted)) {
        $piece = $shifted;
    }
    // Hard-drop: find resting position and lock+spawn
    $resting = $this->board->dropPiece($piece);
    $afterDrop = $this->mutate(['piece' => $resting]);
    return $afterDrop->lockAndSpawn();
}

// Should become:
public function applyAiMove(int $rotDelta, int $dx): self
{
    // Get all rotation candidates (naive + wall kicks)
    $candidates = $this->piece->rotationsWithKicks($rotDelta);
    
    // Find the first candidate that fits after applying x-shift
    $piece = $this->piece;
    foreach ($candidates as $candidate) {
        $shifted = $candidate->moved($dx, 0);
        if ($this->board->fits($shifted)) {
            $piece = $shifted;
            break;
        }
    }
    
    // Hard-drop: find resting position and lock+spawn
    $resting = $this->board->dropPiece($piece);
    $afterDrop = $this->mutate(['piece' => $resting]);
    return $afterDrop->lockAndSpawn();
}
```

**Conditions for success:**
- [ ] Write test `testAiMoveRespectsWallKicks` that verifies AI can place piece using wall kick in tight space
- [ ] Existing `GameTest.php` continues passing
- [ ] `ComputerTest.php` passes

**Related code:**
- `candy-tetris/src/Piece.php:58-70` (`rotationsWithKicks`)
- `candy-tetris/src/Rotation/SrsKickTable.php:26-61` (SRS kick tables)
- `candy-tetris/src/Game.php:236-255` (`tryRotate` for reference)

---

## Phase 2: MEDIUM Priority Fixes [PENDING]

### 2.1 Garbage Overflow Off-By-One ← CURRENT

**Severity:** MEDIUM  
**Location:** `candy-tetris/src/Game.php:482-495`

**What is expected:**
- Change `for ($r = 0; $r < $count; $r++)` to `for ($r = 0; $r <= $count; $r++)`
- This catches content in row `$count` that gets displaced to position `$count` after garbage rows are added

**Why this matters:**
- Without this fix, if content exists in row `count` (e.g., row 2 when adding 2 garbage rows), it gets pushed into the visible area but never checked
- This could allow invalid board states to persist undetected

**Investigation notes:**
- `Board.php:20-23`: `ROWS = 24` (20 visible + 4 hidden buffer rows)
- `Game.php:499-500`: Content shifts from `row - $count` to `row`
- When adding `count` garbage rows:
  - Row 0 content → displaced to row 0 (checked by `r=0`)
  - Row 1 content → displaced to row 1 (checked by `r=1`)
  - Row 2 content → displaced to row 2 (NOT checked! r only goes 0,1)
- The test `testAddGarbageTopsOutWhenStackOverflows` at `GameTest.php:291-310` fills rows 0 and 1, not row 2

**Proposed fix:**
```php
// Current (line 482):
for ($r = 0; $r < $count; $r++) {
    foreach ($rows[$r] as $cell) {
        if ($cell !== null) {
            // Locked content would be displaced — game over
            ...
        }
    }
}

// Should become:
for ($r = 0; $r <= $count; $r++) {  // Note: <= instead of <
    foreach ($rows[$r] as $cell) {
        if ($cell !== null) {
            // Locked content would be displaced — game over
            ...
        }
    }
}
```

**Conditions for success:**
- [ ] Write test `testGarbageOverflowChecksRowCount` that places content in row `count` and verifies game-over triggers
- [ ] Existing `testAddGarbageTopsOutWhenStackOverflows` continues passing
- [ ] `GameTest.php` passes

**Related code:**
- `candy-tetris/src/Game.php:470-526` (`addGarbageRows` method)
- `candy-tetris/tests/GameTest.php:291-310` (existing top-out test)

---

### 2.2 Computer Decision Tracking Inefficiency

**Severity:** LOW  
**Location:** `candy-tetris/src/VsGame.php:146-165`

**What is expected:**
- Fix `advanceComputer()` to avoid redundant `bestMove()` computation
- `computerDecisionFor` should be updated BEFORE calling `applyAiMove()`, not after

**Why this matters:**
- Currently, when a piece locks and a new piece spawns, `bestMove()` is computed for the new piece
- But `computerDecisionFor` is then updated to match the new piece, so on the NEXT gravity tick, `bestMove()` is computed AGAIN
- This wastes computation

**Investigation notes:**
- `VsGame.php:151-161`: Current logic updates `computerDecisionFor` AFTER `applyAiMove()` is called
- The piece kind comparison `$currentKind !== $this->computerDecisionFor` happens at the START of `advanceComputer`
- After `applyAiMove()` calls `lockAndSpawn()`, a new piece is in play
- So the NEXT call to `advanceComputer` sees a new piece kind and computes `bestMove()` again

**Proposed fix:**
The detection logic needs to track which piece `computerDecisionFor` was set for, then compare at the START of `advanceComputer` to determine if we need fresh AI move OR if the current stored move is still valid.

**Conditions for success:**
- [ ] `VsGameTest.php` passes
- [ ] AI makes same quality decisions with fewer `bestMove()` calls

**Related code:**
- `candy-tetris/src/VsGame.php:146-165` (`advanceComputer` method)
- `candy-tetris/src/Computer.php:32-76` (`bestMove` method)

---

## Phase 3: LOW Priority Fixes [PENDING]

### 3.1 Document Lock Delay on Rotation Deviation

**Severity:** LOW  
**Location:** `candy-tetris/src/Game.php:248-250`

**What is expected:**
- Add deviation note to `candy-tetris/CALIBER_LEARNINGS.md`
- This documents that lock delay re-arms on successful rotation (not just translation like strict SRS)

**Why this matters:**
- Prevents future "corrections" that would break this intentional behavior
- Documents the design decision for new contributors

**Investigation notes:**
- `Game.php:248-250`: Re-arms lock delay on successful rotation
- `Game.php:227-230`: Comment explains SRS behavior for movement (only re-arm on grounded pieces)
- No comment explaining rotation behavior

**Proposed fix:**
Add to `candy-tetris/CALIBER_LEARNINGS.md`:
```markdown
### 2026-06-30 — lock-delay-rotation-re-arm
Pattern: Lock delay re-arms on successful rotation (not just translation).
This is an intentional deviation from strict SRS which only re-arms on
left/right/down movement. Documented here to prevent future "correction".
Source: `candy-tetris/src/Game.php:248-250`
```

**Conditions for success:**
- [ ] `CALIBER_LEARNINGS.md` updated with deviation note
- [ ] `testLockDelayReArmsOnMoveWhenGrounded` passes

---

### 3.2 Remove range() Array Allocation

**Severity:** LOW  
**Location:** `candy-tetris/src/Computer.php:39`

**What is expected:**
- Replace `foreach (range(0, 3) as $rotations)` with `for ($rotations = 0; $rotations < 4; $rotations++)`
- Avoids creating a 4-element array just to iterate 0,1,2,3

**Investigation notes:**
- `Computer.php:39`: `foreach (range(0, 3) as $rotations)` creates `[0, 1, 2, 3]`
- This is called once per `bestMove()` invocation

**Proposed fix:**
```php
// Current:
foreach (range(0, 3) as $rotations) {
    $rotated = $piece;
    for ($r = 0; $r < $rotations; $r++) {
        $rotated = $rotated->rotated(1);
    }
    ...
}

// Should become:
for ($rotations = 0; $rotations < 4; $rotations++) {
    $rotated = $piece;
    for ($r = 0; $r < $rotations; $r++) {
        $rotated = $rotated->rotated(1);
    }
    ...
}
```

**Conditions for success:**
- [ ] `ComputerTest.php` passes
- [ ] Refactored code produces identical `bestMove()` results

---

### 3.3 Extract VsRenderer Card Closure

**Severity:** LOW  
**Location:** `candy-tetris/src/VsRenderer.php:119-123`

**What is expected:**
- Extract `$card` closure to a `private const CARD_FORMATTER` class constant

**Investigation notes:**
- `VsRenderer.php:119-123`: Closure defined inline in `renderSidebar()`
- It uses `static fn()` which is fine for closures, but creating it each call is wasteful

**Proposed fix:**
```php
// In VsRenderer class:
private const CARD_FORMATTER = static fn(string $body): string => Style::new()
    ->border(Border::normal())
    ->padding(0, 1)
    ->width(16)
    ->render($body);

// In renderSidebar():
return self::CARD_FORMATTER($score);
```

**Conditions for success:**
- [ ] `VsRendererTest.php` passes
- [ ] Output unchanged

---

## Phase 4: Testing & Verification [PENDING]

### 4.1 Run Full Test Suite ← CURRENT

**Command:**
```bash
cd candy-tetris && composer install && vendor/bin/phpunit
```

**Conditions for success:**
- [ ] All existing tests pass

---

### 4.2 Write Targeted Tests

| Test Name | Issue | What it verifies |
|-----------|-------|------------------|
| `testApplyAiMoveWithZeroRotationDoesNotRotate` | 1.1 | `applyAiMove(0, dx)` does NOT rotate piece |
| `testAiMoveRespectsWallKicks` | 1.2 | AI can place piece using wall kick in tight space |
| `testGarbageOverflowChecksRowCount` | 2.1 | Content in row `count` triggers game-over |

---

## Summary Table

| # | Issue | Severity | Location | Status |
|---|-------|----------|----------|--------|
| 1 | AI Rotation Bug | **HIGH** | `Game.php:309` | PENDING |
| 2 | AI Missing Wall Kicks | **MEDIUM** | `Game.php:305-321` | PENDING |
| 3 | Garbage Overflow Off-By-One | **MEDIUM** | `Game.php:482-495` | PENDING |
| 4 | Lock Delay on Rotation | LOW | `Game.php:248-250` | PENDING |
| 5 | Redundant dropPiece() | LOW | `Renderer.php:78` | ACKNOWLEDGED |
| 6 | range() Array Allocation | LOW | `Computer.php:39` | PENDING |
| 7 | Closure Per Call | LOW | `VsRenderer.php:119-123` | PENDING |
| 8 | Computer Color Shifting | LOW | `VsRenderer.php:157` | ACKNOWLEDGED |
| 9 | Decision Tracking | LOW | `VsGame.php:146-165` | PENDING |

---

## Files to Modify

| File | Lines | Change |
|------|-------|--------|
| `candy-tetris/src/Game.php` | 305-321 | Fix AI rotation loop, add wall kick support |
| `candy-tetris/src/Game.php` | 482 | Fix off-by-one in garbage overflow check |
| `candy-tetris/src/Game.php` | 248-250 | Add comment documenting lock delay re-arm on rotation |
| `candy-tetris/src/Computer.php` | 39 | Replace `range()` with `for` loop |
| `candy-tetris/src/VsRenderer.php` | 119-123 | Extract closure to constant |
| `candy-tetris/src/VsGame.php` | 146-165 | Fix computer decision tracking |
| `candy-tetris/CALIBER_LEARNINGS.md` | — | Add lock delay deviation note |
| `candy-tetris/tests/` | — | Add targeted tests for bugs |

---

## Notes

- **2026-06-30**: Plan created based on `findings/candy-tetris.md` audit
- **Issue #1 (AI Rotation Bug)** requires runtime verification — the for loop condition `for ($i = 0; $i < $rotDelta; $i++)` with `$rotDelta = 0` should not execute in standard PHP, but the audit finding explicitly states it does. The fix may need `for ($i = 0; $i <= $rotDelta; $i++)` → `for ($i = 0; $i < $rotDelta; $i++)`.
- **Memory leaks**: NONE detected per audit
- **Security**: NONE detected per audit
- **PHP compatibility**: CLEAN (PHP 8.3+) per audit
