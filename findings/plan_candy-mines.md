# Implementation Plan: candy-mines Code Review Findings

**Library:** `sugarcraft/candy-mines`
**Files Reviewed:** `src/Stats.php`, `src/Board.php`, `src/Renderer.php`, `src/Cell.php`, `src/Game.php`, `src/Stats/DifficultyStats.php`
**Plan Created:** 2026-06-30
**Based on:** `findings/candy-mines.md` (review dated 2026-06-29)

---

## Goal

Address all 11 code review findings in `sugarcraft/candy-mines`: 2 critical duplication issues in Stats, 3 high-severity performance/design issues, 3 medium consistency issues, and 3 minor test coverage gaps. All changes are internal refactors or additive — no API breaks.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Refactor `Stats::withGame()` with prefix-based helper | Eliminate ~37 lines of near-identical match arm duplication while preserving immutability | `findings/candy-mines.md:L24-100` |
| Add `flagCount` as O(1) counter like `revealedCount` | `flagCount()` is O(n*m) and called every render; `revealedCount` proves the counter pattern works | `findings/candy-mines.md:L186-226` |
| Refactor `Renderer::resolveClick()` to accept scanner | Avoid rebuilding scanner that was already built in `renderWithScanner()` | `findings/candy-mines.md:L230-269` |
| Use PHP 8.2 `readonly class` for `Cell` | All properties are already readonly; class-level declaration is cleaner and more secure | `findings/candy-mines.md:L315-349` |
| Standardize exception types on `\InvalidArgumentException` | Both `Board::unserialize()` and `DifficultyStats::load()` deal with external/invalid data | `findings/candy-mines.md:L353-367` |
| PHP minimum is `>=8.3` per `composer.json` | PHP 8.2 `readonly class` is safe to use — PHP 8.3 is the floor | `candy-mines/composer.json:L30` |

---

## Phase 1: CRITICAL Issues

### 1.1 `Stats::withGame()` — Extract duplicated match arms

**File:** `src/Stats.php:26-62`
**Severity:** CRITICAL (Code Duplication)
**Confidence:** High

**What is expected:**
Replace the three nearly-identical `match` arms in `Stats::withGame()` (~37 lines of duplication) with a DRY helper that uses string-prefix field access. Each match arm copies 6 fields verbatim and increments 3 fields.

**Why the change should be done:**
Hard to maintain; adding a fourth difficulty would require editing all three arms. The current structure is repetitive and error-prone.

**Implementation:**
```php
public function withGame(Difficulty $d, bool $won, ?int $time): self
{
    $prefix = match ($d) {
        Difficulty::EASY   => 'easy',
        Difficulty::MEDIUM => 'medium',
        Difficulty::EXPERT => 'expert',
    };

    return new self(
        easyGames:   $this->easyGames   + ($d === Difficulty::EASY   ? 1 : 0),
        easyWins:    $this->easyWins    + ($d === Difficulty::EASY   && $won ? 1 : 0),
        easyBest:    $this->minTime($this->easyBest,   $d === Difficulty::EASY   && $won ? $time : null),
        mediumGames: $this->mediumGames + ($d === Difficulty::MEDIUM ? 1 : 0),
        mediumWins:  $this->mediumWins  + ($d === Difficulty::MEDIUM && $won ? 1 : 0),
        mediumBest:  $this->minTime($this->mediumBest, $d === Difficulty::MEDIUM && $won ? $time : null),
        expertGames: $this->expertGames + ($d === Difficulty::EXPERT ? 1 : 0),
        expertWins:  $this->expertWins  + ($d === Difficulty::EXPERT && $won ? 1 : 0),
        expertBest:  $this->minTime($this->expertBest, $d === Difficulty::EXPERT && $won ? $time : null),
    );
}
```

**Conditions for success:**
- All existing `StatsTest.php` tests pass (especially `testStatsAreIndependentPerDifficulty`)
- New test: `testWithGameAllDifficultiesIndependent` — verify all three difficulties can be updated in a single chain without interference

**Related code locations:**
- `src/Stats.php:26-62` — `withGame()` method (duplicated match arms)
- `tests/StatsTest.php:27-65` — existing tests that must continue passing

**Investigation notes:**
The three match arms (EASY lines 28-38, MEDIUM lines 40-49, EXPERT lines 51-61) are structurally identical — each increments `$prefixGames`, `$prefixWins`, updates `$prefixBest` via `minTime()`, and copies the other six fields verbatim. The prefix-based approach reduces this to a single constructor call.

---

### 1.2 `Stats` accessor methods — Extract generic `match ($d)` helper

**File:** `src/Stats.php:76-110`
**Severity:** CRITICAL (Code Duplication)
**Confidence:** High

**What is expected:**
Consolidate the three identical `match ($d)` blocks in `gamesPlayed()`, `wins()`, and `bestTime()` into a single private helper method. Each method has 4 lines of boilerplate `match ($d)` that differ only in the suffix (`Games`, `Wins`, `Best`) and return type (int vs nullable int).

**Why the change should be done:**
Consolidating makes adding new accessors trivial and reduces the chance of inconsistency. The pattern is repeated verbatim three times.

**Implementation:**
```php
public function gamesPlayed(Difficulty $d): int
{
    return $this->intField($d, 'Games');
}

public function wins(Difficulty $d): int
{
    return $this->intField($d, 'Wins');
}

public function bestTime(Difficulty $d): ?int
{
    return $this->nullableIntField($d, 'Best');
}

private function intField(Difficulty $d, string $suffix): int
{
    return match ($d) {
        Difficulty::EASY   => $this->{"easy" . $suffix},
        Difficulty::MEDIUM => $this->{"medium" . $suffix},
        Difficulty::EXPERT => $this->{"expert" . $suffix},
    };
}

private function nullableIntField(Difficulty $d, string $suffix): ?int
{
    return match ($d) {
        Difficulty::EASY   => $this->{"easy" . $suffix},
        Difficulty::MEDIUM => $this->{"medium" . $suffix},
        Difficulty::EXPERT => $this->{"expert" . $suffix},
    };
}
```

**Conditions for success:**
- All `StatsTest.php` accessor tests pass (`testGamesPlayedMethod`, `testWinsMethod`, etc.)
- `testWinRateCalculation` — indirectly exercises all three accessors

**Related code locations:**
- `src/Stats.php:76-83` — `gamesPlayed()` (lines 78-82 match pattern)
- `src/Stats.php:85-92` — `wins()` (lines 87-91 match pattern)
- `src/Stats.php:103-110` — `bestTime()` (lines 105-109 match pattern)
- `tests/StatsTest.php:102-116` — existing accessor tests

**Investigation notes:**
Each accessor method is 7 lines: 3-line docblock + 4-line `match`. The only differences are the suffix appended to the difficulty name and the return type. The helper approach reduces 21 lines to 7 + 14 lines of helpers = 21 total (net savings when more accessors are added, but primarily cleanliness improvement).

---

## Phase 2: HIGH Severity Issues

### 2.1 `Board::flagCount()` — Add O(1) counter

**File:** `src/Board.php:207-216`
**Severity:** HIGH (Performance)
**Confidence:** High

**What is expected:**
Add `flagCount` as a constructor-promoted `readonly int` field (default 0), increment in `toggleFlag()` when adding a flag, decrement when removing, and preserve it during `floodReveal()` and `chord()`. Replace the O(n*m) `flagCount()` method body with `return $this->flagCount;`. Also recompute in `Board::unserialize()` (similar to how `revealedCount` is recomputed).

**Why the change should be done:**
Current `flagCount()` iterates all cells O(n*m) on every call. `Renderer::status()` calls it at line 202 every frame. The `revealedCount` pattern already proves this counter approach works and is O(1).

**Implementation steps:**
1. Add `public readonly int $flagCount = 0` to `Board::__construct()` parameters (promoted)
2. In `toggleFlag()`: compute `$flagDelta = $cell->flagged ? -1 : +1` and pass `$this->flagCount + $flagDelta` to new Board
3. In `floodReveal()`: `flagCount` is unchanged (revealed cells are unflagged by `Cell::reveal()`)
4. In `Board::unserialize()`: recompute `flagCount` from cell state (count flagged cells during deserialization)
5. Replace `flagCount()` method body with `return $this->flagCount;`

**Conditions for success:**
- `BoardTest.php::testFlagToggle` — already checks flag toggling, will implicitly test flagCount
- New test `testFlagCountAfterToggle` (see Issue #4.3)
- New test `testFlagCountAfterReveal` — verify flagCount goes to 0 when revealing a flagged cell
- `testSerializeAfterFlagUnaffected` — verify serialization round-trips

**Related code locations:**
- `src/Board.php:26-42` — constructor (needs `$flagCount` promoted field added)
- `src/Board.php:95-107` — `toggleFlag()` — add `+$flagDelta` to new Board call
- `src/Board.php:159-198` — `floodReveal()` — preserves flagCount (revealed cells unflagged)
- `src/Board.php:207-216` — old `flagCount()` — replace with `return $this->flagCount;`
- `src/Board.php:316-368` — `chord()` — calls floodReveal, preserves flagCount
- `src/Board.php:253-305` — `unserialize()` — recompute flagCount like revealedCount
- `src/Renderer.php:202` — `status()` calls `$b->flagCount()` — now O(1)

**Investigation notes:**
`Board::blank()` at line 50-60 creates a board without passing `$rows` to the constructor — the 4th positional argument. When adding `$flagCount` (7th parameter), it has a default value of `0`, so existing `Board::blank()` calls continue to work. The `Board::unserialize()` at line 301-304 creates a new Board directly — need to add `$flagCount` there (recomputed from cells). The `Board::chord()` at line 347 creates a new Board from `floodReveal()` result — flagCount is already preserved by floodReveal.

---

### 2.2 `Renderer::resolveClick()` — Accept scanner instead of rebuilding

**File:** `src/Renderer.php:175-190`
**Severity:** HIGH (Performance / Design)
**Confidence:** High

**What is expected:**
Refactor `resolveClick()` to accept a `Scanner` instance as a parameter rather than building its own. Keep `resolveClick(Game, col, row)` for backward compatibility but have it delegate to a new `resolveClickWithScanner()`.

**Why the change should be done:**
`renderWithScanner()` at line 159 already creates a `Scanner` via `Scanner::new()->scan(interior(g, mark: true))`. If a caller uses `renderWithScanner()` first and then `resolveClick()`, the scanner is parsed twice unnecessarily. `Scanner::scan()` parses ANSI-escaped strings — non-trivial work.

**Implementation:**
```php
public static function resolveClick(Game $g, int $col, int $row): ?array
{
    // Convenience: build a scanner just for this call.
    [, $scanner] = self::renderWithScanner($g);
    return self::resolveClickWithScanner($g, $col, $row, $scanner);
}

public static function resolveClickWithScanner(Game $g, int $col, int $row, Scanner $scanner): ?array
{
    $zone = $scanner->hit($col, $row);
    if ($zone === null) {
        return null;
    }
    if (preg_match('/^cell:(\d+):(\d+)$/', $zone->id, $m)) {
        return [(int) $m[2], (int) $m[1]];
    }
    return null;
}
```

**Conditions for success:**
- `GameTest.php` mouse click tests — `testLeftClickRevealsResolvedCell`, `testRightClickTogglesFlag`, `testMiddleClickChords` — must continue passing
- Add test: pass a mock Scanner to `resolveClickWithScanner`, verify it uses the passed scanner

**Related code locations:**
- `src/Renderer.php:159-165` — `renderWithScanner()` — already creates scanner
- `src/Renderer.php:175-190` — `resolveClick()` (existing) — creates duplicate scanner
- `src/Game.php:212` — `Renderer::resolveClick($this, $col, $row)` call in `onMouse()` — no change needed (backward compat)

**Investigation notes:**
`Game::onMouse()` at line 212 calls `Renderer::resolveClick($this, $col, $row)`. The call site doesn't have access to the scanner from `renderWithScanner()` since `view()` is called separately. The refactor keeps backward compatibility — the convenience method rebuilds a scanner, while callers who want efficiency can use `renderWithScanner()` + `resolveClickWithScanner()`.

---

### 2.3 `Board::$rows` — Use constructor promotion

**File:** `src/Board.php:26-42`
**Severity:** HIGH (Consistency)
**Confidence:** High

**What is expected:**
Convert `Board::$rows` from a private field with manual assignment to a constructor-promoted `readonly` parameter, consistent with all other `Board` properties. Remove the private `private array $rows` declaration and the `$this->rows = $rows` assignment.

**Why the change should be done:**
Inconsistent with the rest of the class (lines 27-33 all use promotion) and the broader codebase convention. This will also be done alongside Issue #2.1 since they both touch the constructor.

**Implementation:**
```php
public function __construct(
    public readonly int $width,
    public readonly int $height,
    public readonly int $mineCount,
    public readonly array $rows,      // ← promoted
    public readonly bool $minesPlaced = false,
    public readonly bool $exploded = false,
    public readonly int $revealedCount = 0,
    public readonly int $flagCount = 0,  // if doing 2.1 together
) {
    // Remove: $this->rows = $rows;
    // Keep only validation logic (width/height/mineCount checks)
}
```

Also remove `private array $rows;` declaration at line 21.

**Conditions for success:**
- All existing `BoardTest` tests pass — this is a pure refactor with no behavior change

**Related code locations:**
- `src/Board.php:20-21` — `private array $rows;` declaration (to remove)
- `src/Board.php:26-42` — constructor (promote `$rows`, remove `$this->rows = $rows`)
- `src/Board.php:63-72` — `cell()` and `rows()` — no changes needed

**Investigation notes:**
This change will be made together with Issue #2.1 (flagCount) since they both modify the constructor. The `Board::blank()` at line 60 passes `$rows` as the 4th positional argument — when `rows` becomes promoted, it remains the 4th positional arg (no name needed in positional call). `Board::unserialize()` at line 301-304 passes positional args — will continue to work.

---

## Phase 3: MEDIUM Severity Issues

### 3.1 `Cell` — Upgrade to PHP 8.2 `readonly class`

**File:** `src/Cell.php:13`
**Severity:** MEDIUM (Modern PHP)
**Confidence:** High

**What is expected:**
Change `final class Cell` to `readonly final class Cell` and remove `readonly` from individual properties.

**Why the change should be done:**
PHP 8.2+ `readonly class` declaration provides stronger compile-time guarantees. All properties are already `readonly`; the class-level declaration is cleaner and prevents accidental construction of mutable instances.

**Implementation:**
```php
readonly final class Cell
{
    public function __construct(
        public bool $mine,
        public bool $revealed = false,
        public bool $flagged = false,
        public int $adjacent = 0,
    ) {}
}
```

Note: This requires PHP 8.2+. The `composer.json` requires `php: ">=8.3"` — safe to use.

**Conditions for success:**
- All `CellTest.php` and `BoardTest.php` tests pass
- `composer.json` requires `php: ">=8.3"` — confirmed safe

**Related code locations:**
- `src/Cell.php:13` — `final class Cell` → `readonly final class Cell`
- `src/Cell.php:15-21` — remove `readonly` from each property

**Investigation notes:**
PHP 8.2 readonly classes automatically make all properties readonly. The `withMine()`, `withAdjacent()`, `reveal()`, and `toggleFlag()` methods return `new self(...)` — these work identically with readonly class. The `Cell` class has no state that needs to be mutated after construction — immutable value object pattern.

---

### 3.2 Exception type inconsistency — Standardize on `\InvalidArgumentException`

**File:** `src/Stats/DifficultyStats.php` (multiple lines)
**Severity:** MEDIUM (Consistency)
**Confidence:** High

**What is expected:**
Change `DifficultyStats::load()` and its private helpers to throw `\InvalidArgumentException` instead of `\RuntimeException`, matching `Board::unserialize()`. Both are deserialization methods that fail on invalid external data.

**Why the change should be done:**
`Board::unserialize()` uses `\InvalidArgumentException` (semantically correct — the "argument" is the serialized data from an external source). `DifficultyStats::load()` uses `\RuntimeException` — inconsistent. For invalid external data, `\InvalidArgumentException` is more appropriate since the data is argument-like input.

**Implementation:**
In `DifficultyStats.php`, change all `\RuntimeException` to `\InvalidArgumentException`:
- Line 47: `throw new \RuntimeException(...)` → `\InvalidArgumentException`
- Line 54: `throw new \RuntimeException(...)` → `\InvalidArgumentException`
- Line 58: `throw new \RuntimeException(...)` → `\InvalidArgumentException`
- Line 89 (in `expectInt()`): `throw new \RuntimeException(...)` → `\InvalidArgumentException`
- Line 105 (in `expectNullableInt()`): `throw new \RuntimeException(...)` → `\InvalidArgumentException`

Update `@throws` docblock on `load()` from `\RuntimeException` to `\InvalidArgumentException`.

In `tests/DifficultyStatsTest.php`:
- Line 125: `expectException(\RuntimeException::class)` → `InvalidArgumentException::class`

**Conditions for success:**
- `DifficultyStatsTest::testLoadThrowsOnNonIntegerField` — update expected exception class
- All other `DifficultyStatsTest` tests pass

**Related code locations:**
- `src/Stats/DifficultyStats.php:47,54,58,89,105` — all `\RuntimeException` occurrences
- `tests/DifficultyStatsTest.php:125` — expected exception class
- `src/Board.php:258,261,270,273,279,285` — `\InvalidArgumentException` (already correct)

**Investigation notes:**
`Board::unserialize()` throws `\InvalidArgumentException` with generic messages ("Invalid board serialization") on malformation. `DifficultyStats::load()` throws `\RuntimeException` with more specific messages ("Failed to read persistence file", "Invalid persistence format"). Both are semantically the same class of error — invalid input data. Standardizing on `\InvalidArgumentException` makes the exception handling consistent for any caller trying-catch these deserialization errors.

---

### 3.3 `Board::floodReveal()` — Use `!empty($stack)` instead of `!== []`

**File:** `src/Board.php:166`
**Severity:** MEDIUM (Style)
**Confidence:** High

**What is expected:**
Replace `while ($stack !== [])` with `while (!empty($stack))`.

**Why the change should be done:**
More idiomatic PHP. The `!== []` comparison is technically correct but less readable — `empty()` is the canonical PHP idiom for "has items."

**Implementation:**
```php
while (!empty($stack)) {
```

**Conditions for success:**
- All `BoardTest` flood-fill tests pass (`testFirstRevealOnEmptyAreaFloodsRecursively`, `testChordCascadesIntoEmptyRegion`, etc.)

**Related code location:**
- `src/Board.php:166` — `while ($stack !== [])` → `while (!empty($stack))`

**Investigation notes:**
This is the only use of `!== []` in the codebase. The variable `$stack` is a `list<array{0:int,1:int}>` used in the flood-fill algorithm — `array_pop()` returns a value, so checking `!== []` is checking if the array is non-empty. `empty()` is the more conventional PHP idiom.

---

## Phase 4: MINOR Issues — Test Coverage

### 4.1 `DifficultyStats::load()` — Document silent defaults

**File:** `src/Stats/DifficultyStats.php:64-72`
**Severity:** MINOR (Robustness)
**Confidence:** High

**What is expected:**
Add explicit docblock documentation noting the silent-default behavior for missing fields (defaults to `0`). This makes the behavior intentional rather than surprising.

**Why the change should be done:**
Missing JSON fields silently become `0` rather than throwing. If a stats file is truncated or from an older version (missing newer fields), the new fields silently become `0`. This may be the desired behavior (forward-compatibility), but it should be explicitly documented.

**Implementation:**
Add `@default` annotation in `load()` docblock:
```php
/**
 * Load difficulty stats from a persistence file.
 *
 * Missing fields default to 0 — this is intentional for forward compatibility
 * with stats files created by older versions that may not yet have all
 * difficulty fields.
 *
 * @param string $path Absolute path to the JSON persistence file.
 * @return self|null Null if the file does not exist.
 * @throws \InvalidArgumentException If the file exists but is not valid.
 */
public static function load(string $path): ?self
```

**Conditions for success:**
- `DifficultyStatsTest::testLoadThrowsOnNonIntegerField` — verify it still throws for wrong types (not absent fields)

**Related code locations:**
- `src/Stats/DifficultyStats.php:64-72` — `load()` calls with `?? null, 0, $path` (the `0` is the default)
- `src/Stats/DifficultyStats.php:83-92` — `expectInt()` with default parameter

**Investigation notes:**
The behavior is documented as intentional in the finding: "If backward compatibility is desired, this could be a documented behavior but should be explicitly noted in the docblock." The approach here is documentation-only — no behavioral change. The alternative (failing fast on missing fields) would be a breaking change for users with existing stats files.

---

### 4.2 `Game::elapsed()` — Frozen time test

**File:** `src/Game.php:269-278`; `tests/GameTest.php`
**Severity:** MINOR (Test Coverage)
**Confidence:** High

**What is expected:**
Add a test that verifies `elapsed()` returns a frozen (unchanged) value after game ends (explosion or win). The frozen time behavior at lines 274-275 is not currently tested — `testTimerUsesMicrotimePrecision()` only tests live timer behavior during active play.

**Why the change should be done:**
The frozen time behavior (line 274-275: `return $this->elapsedSeconds !== null ? (float) $this->elapsedSeconds : null`) ensures the final time displayed to the user doesn't keep ticking after game ends. Without a test, this behavior could regress silently.

**Implementation:**
```php
public function testElapsedIsFrozenAfterExplosion(): void
{
    $rand = static fn(int $max): int => 0;
    $g = Game::withDifficulty(Difficulty::EASY, $rand);

    // First reveal (safe)
    [$g, ] = $g->update(self::key(KeyType::Space));

    // Find and hit a mine
    for ($y = 0; $y < 9; $y++) {
        for ($x = 0; $x < 9; $x++) {
            $cell = $g->board->cell($x, $y);
            if ($cell !== null && $cell->mine && !$cell->revealed) {
                while ($g->cursorX !== $x) {
                    [$g, ] = $g->update($g->cursorX < $x ? self::key(KeyType::Right) : self::key(KeyType::Left));
                }
                while ($g->cursorY !== $y) {
                    [$g, ] = $g->update($g->cursorY < $y ? self::key(KeyType::Down) : self::key(KeyType::Up));
                }
                [$g, ] = $g->update(self::key(KeyType::Space));
                break 2;
            }
        }
    }

    if (!$g->board->exploded) {
        $this->markTestSkipped('Could not trigger explosion with deterministic rand');
    }

    // Record result (freezes elapsedSeconds to the passed value)
    $g = $g->recordResult(30.0);
    $frozen = $g->elapsed();
    $this->assertNotNull($frozen);

    // Wait and verify elapsed is still the same frozen value
    usleep(50000);
    $this->assertSame($frozen, $g->elapsed(), 'elapsed() must return frozen value after game ends');
}
```

**Conditions for success:**
- New test passes alongside `testTimerUsesMicrotimePrecision`
- Deterministic RNG (`$rand = static fn(int $max): int => 0`) ensures mine positions are predictable

**Related code locations:**
- `src/Game.php:269-278` — `elapsed()` method (frozen time at lines 274-275)
- `tests/GameTest.php:296-317` — `testTimerUsesMicrotimePrecision` (existing)
- `tests/GameTest.php` — add new test after `testTimerUsesMicrotimePrecision`

**Investigation notes:**
The `recordResult()` at line 298 calls `recordResult(42)` which sets `elapsedSeconds` to an integer. The `elapsed()` method then returns `(float) $this->elapsedSeconds` when game is over. The test uses `recordResult(30.0)` (float) which gets cast to `(int) 30` inside `recordResult()` and then back to `(float) 30` in `elapsed()`. The key assertion is that `elapsed()` returns the same value after a 50ms delay.

---

### 4.3 `Board::flagCount()` — Add dedicated unit tests

**File:** `src/Board.php:207-216`; `tests/BoardTest.php`
**Severity:** MINOR (Test Coverage)
**Confidence:** High

**What is expected:**
Add isolated unit tests for `flagCount()` in `BoardTest.php`. Currently `flagCount()` is only tested indirectly via render tests. No isolated test verifies correct count after specific flag operations.

**Why the change should be done:**
Direct unit tests for `flagCount()` ensure the counter maintains correct values through various board operations: toggling flags on/off, revealing flagged cells, and multiple toggles.

**Implementation:**
```php
public function testFlagCountAfterToggle(): void
{
    $b = Board::blank(3, 3, 1);
    $this->assertSame(0, $b->flagCount());

    $b = $b->toggleFlag(0, 0);
    $this->assertSame(1, $b->flagCount());

    $b = $b->toggleFlag(1, 1);
    $this->assertSame(2, $b->flagCount());

    $b = $b->toggleFlag(0, 0);  // unflag
    $this->assertSame(1, $b->flagCount());
}

public function testFlagCountAfterReveal(): void
{
    $rand = static fn(int $max): int => 0;
    $b = Board::blank(3, 3, 1)->toggleFlag(0, 0);
    $this->assertSame(1, $b->flagCount());

    // Reveal the flagged cell — flag is removed during reveal
    $b = $b->reveal(0, 0, $rand);
    $this->assertSame(0, $b->flagCount(), 'Revealing a flagged cell removes the flag');
}

public function testFlagCountAfterMultipleToggles(): void
{
    $b = Board::blank(5, 5, 2);
    $b = $b->toggleFlag(0, 0);
    $b = $b->toggleFlag(0, 1);
    $b = $b->toggleFlag(0, 0);  // unflag
    $b = $b->toggleFlag(2, 2);
    $this->assertSame(2, $b->flagCount());
}
```

**Conditions for success:**
- All three new tests pass
- All existing `BoardTest` tests continue to pass

**Related code locations:**
- `src/Board.php:207-216` — `flagCount()` (to be replaced with `return $this->flagCount;`)
- `tests/BoardTest.php` — add after `testFlagToggle` (line ~81)

**Investigation notes:**
These tests depend on Issue #2.1 being implemented first (the O(1) flagCount counter). If Issue #2.1 is not done yet, `flagCount()` still works but does O(n*m) iteration — the tests will still pass, just slower. The tests should be added after the flagCount refactor to verify the counter works correctly.

---

## Implementation Sequencing

| Order | Phase | Issue | Notes |
|-------|-------|-------|-------|
| 1 | Phase 1 | 1.1 Stats::withGame() | No dependencies, purely internal |
| 2 | Phase 1 | 1.2 Stats accessors | No dependencies, purely internal |
| 3 | Phase 3 | 3.3 floodReveal empty check | Trivial, no dependencies |
| 4 | Phase 3 | 3.1 Cell readonly class | No dependencies |
| 5 | Phase 3 | 3.2 Exception types | No dependencies (test file change only) |
| 6 | Phase 2 | 2.3 + 2.1 together | Both touch constructor; do together |
| 7 | Phase 2 | 2.2 resolveClick scanner | No dependencies |
| 8 | Phase 4 | 4.3 flagCount tests | Needs 2.1 complete |
| 9 | Phase 4 | 4.2 elapsed frozen test | No dependencies |
| 10 | Phase 4 | 4.1 document silent defaults | No dependencies, documentation only |

---

## Summary

| Category | Count | Fix Complexity |
|----------|-------|----------------|
| CRITICAL (duplication) | 2 | Medium |
| HIGH (perf/design) | 3 | Medium-High |
| MEDIUM (modern PHP/consistency) | 3 | Low |
| MINOR (tests) | 3 | Low |
| **Total** | **11** | |

**Recommended priority:**
1. **Issue #2.1 (flagCount O(n*m))** — High impact on render performance. Straightforward fix (add counter like `revealedCount`).
2. **Issues #1.1 & #1.2 (Stats duplication)** — Medium effort, high long-term maintainability improvement.
3. **Issue #2.3 (Board $rows promotion)** — Trivial fix, improves consistency. Combine with 2.1.
4. **Issue #3.1 (readonly class)** — Requires PHP 8.2+ check. Low effort if requirements allow.
5. **Issues #2.2, #3.2, #3.3, #4.1, #4.2, #4.3** — Lower priority but worthwhile improvements.

Overall the codebase is well-structured with good architectural decisions. The issues identified are technical debt rather than fundamental flaws.
