# Code Review: candy-mines

**Library:** `sugarcraft/candy-mines` (Minesweeper TUI port)
**Review Date:** 2026-06-29
**Reviewer:** Code Review Agent
**Files Reviewed:** `src/Stats.php`, `src/Board.php`, `src/Renderer.php`, `src/Cell.php`, `src/Game.php`, `src/Stats/DifficultyStats.php`, and associated tests

---

## Severity Overview

| Severity | Count | Issues |
|----------|-------|--------|
| CRITICAL | 2 | Code duplication |
| HIGH | 3 | Performance / Design |
| MEDIUM | 3 | Modern PHP / Consistency |
| MINOR | 3 | Test Coverage |
| **Total** | **11** | |

---

## CRITICAL Issues

### 1. `Stats::withGame()` — Three Nearly Identical Match Arms

**File:** `src/Stats.php:26-62`
**Severity:** CRITICAL (Code Duplication)
**Confidence:** High

The `withGame()` method contains three `match` arms that are nearly structurally identical. Each arm is ~10 lines and differs only in which three fields (`*Games`, `*Wins`, `*Best`) are incremented, with the other six fields simply being copied verbatim.

**Duplicated Code (~37 lines):**

```php
public function withGame(Difficulty $d, bool $won, ?int $time): self
{
    return match ($d) {
        Difficulty::EASY   => new self(
            easyGames: $this->easyGames + 1,
            easyWins: $this->easyWins + ($won ? 1 : 0),
            easyBest: $this->minTime($this->easyBest, $won ? $time : null),
            mediumGames: $this->mediumGames,      // ← copied
            mediumWins: $this->mediumWins,        // ← copied
            mediumBest: $this->mediumBest,        // ← copied
            expertGames: $this->expertGames,      // ← copied
            expertWins: $this->expertWins,        // ← copied
            expertBest: $this->expertBest,        // ← copied
        ),
        Difficulty::MEDIUM => new self(
            easyGames: $this->easyGames,           // ← copied
            easyWins: $this->easyWins,             // ← copied
            easyBest: $this->easyBest,             // ← copied
            mediumGames: $this->mediumGames + 1,
            mediumWins: $this->mediumWins + ($won ? 1 : 0),
            mediumBest: $this->minTime($this->mediumBest, $won ? $time : null),
            expertGames: $this->expertGames,       // ← copied
            expertWins: $this->expertWins,         // ← copied
            expertBest: $this->expertBest,         // ← copied
        ),
        Difficulty::EXPERT => new self(
            easyGames: $this->easyGames,           // ← copied
            easyWins: $this->easyWins,             // ← copied
            easyBest: $this->easyBest,             // ← copied
            mediumGames: $this->mediumGames,       // ← copied
            mediumWins: $this->mediumWins,         // ← copied
            mediumBest: $this->mediumBest,         // ← copied
            expertGames: $this->expertGames + 1,
            expertWins: $this->expertWins + ($won ? 1 : 0),
            expertBest: $this->minTime($this->expertBest, $won ? $time : null),
        ),
    };
}
```

**Recommendation:** Extract the per-difficulty field selection into a helper. Consider using an array-based approach or extracting the field naming pattern:

```php
public function withGame(Difficulty $d, bool $won, ?int $time): self
{
    $prefix = match ($d) {
        Difficulty::EASY   => 'easy',
        Difficulty::MEDIUM  => 'medium',
        Difficulty::EXPERT  => 'expert',
    };

    $games  = $prefix . 'Games';
    $wins   = $prefix . 'Wins';
    $best   = $prefix . 'Best';

    return new self(
        easyGames:   $this->easyGames   + ($d === Difficulty::EASY ? 1 : 0),
        easyWins:    $this->easyWins    + ($d === Difficulty::EASY && $won ? 1 : 0),
        easyBest:    $this->easyBest,
        // ... etc
    );
}
```

Alternatively, consider using a `difficulty-games` array keyed by `Difficulty` enum to DRY up the field access pattern.

---

### 2. `Stats` — Duplicate `match ($d)` Patterns in Accessor Methods

**File:** `src/Stats.php:76-110`
**Severity:** CRITICAL (Code Duplication)
**Confidence:** High

The methods `gamesPlayed()`, `wins()`, and `bestTime()` all use identical `match ($d)` patterns to access per-difficulty fields. Each is 4 lines of boilerplate:

```php
public function gamesPlayed(Difficulty $d): int
{
    return match ($d) {
        Difficulty::EASY   => $this->easyGames,
        Difficulty::MEDIUM => $this->mediumGames,
        Difficulty::EXPERT => $this->expertGames,
    };
}

public function wins(Difficulty $d): int
{
    return match ($d) {
        Difficulty::EASY   => $this->easyWins,
        Difficulty::MEDIUM => $this->mediumWins,
        Difficulty::EXPERT => $this->expertWins,
    };
}

public function bestTime(Difficulty $d): ?int
{
    return match ($d) {
        Difficulty::EASY   => $this->easyBest,
        Difficulty::MEDIUM => $this->mediumBest,
        Difficulty::EXPERT => $this->expertBest,
    };
}
```

**Recommendation:** Create a single generic accessor that maps difficulty to field name:

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
        Difficulty::EASY   => match ($suffix) {
            'Games' => $this->easyGames,
            'Wins'  => $this->easyWins,
        },
        // ...
    };
}
```

Or better yet, use an internal array-based storage structure that eliminates the need for three separate fields per difficulty:

```php
/** @var array<Difficulty, array{games:int, wins:int, best:?int}> */
private array $perDifficulty;

public function gamesPlayed(Difficulty $d): int
{
    return $this->perDifficulty[$d->name]['games'];
}
```

---

## HIGH Issues

### 3. `Board::flagCount()` — O(n*m) Iteration on Every Render

**File:** `src/Board.php:207-216`
**Severity:** HIGH (Performance)
**Confidence:** High

```php
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
```

This iterates all cells (O(n*m)) on every call. It is called in `Renderer::status()` at line 202, which is invoked on every render via `Renderer::frame()`:

```php
// src/Renderer.php:202
$remaining = max(0, $b->mineCount - $b->flagCount());
```

The class already tracks `revealedCount` as an O(1) counter. `flagCount` should be tracked identically.

**Current counter pattern (good):**
```php
public function __construct(
    // ...
    public readonly int $revealedCount = 0,
) {}

// Updated in floodReveal:
$rows[$y][$x] = $cell->reveal();
$revealedCount++;
```

**Recommendation:** Add `flagCount` as a constructor-promoted field and increment/decrement it in `toggleFlag()` and `floodReveal()` (when cells are revealed, flags are cleared). This mirrors the `revealedCount` pattern and provides O(1) access.

---

### 4. `Renderer::resolveClick()` — Duplicated Scanner Work

**File:** `src/Renderer.php:177`
**Severity:** HIGH (Performance / Design)
**Confidence:** High

```php
public static function resolveClick(Game $g, int $col, int $row): ?array
{
    $scanner = Scanner::new()->scan(self::interior($g, mark: true));  // ← NEW Scanner
    $zone = $scanner->hit($col, $row);
    // ...
}
```

`renderWithScanner()` at line 159-165 already creates a `Scanner` via the same call:

```php
public static function renderWithScanner(Game $g): array
{
    $scanner = Scanner::new()->scan(self::interior($g, mark: true));  // ← SAME
    $full = self::frame($g, self::interior($g, mark: false));
    return [$full, $scanner];
}
```

If `resolveClick()` is called after (or instead of) `renderWithScanner()`, the scanner is rebuilt unnecessarily. This is especially wasteful since `Scanner::scan()` parses the ANSI-escaped string.

**Recommendation:** Refactor so `renderWithScanner()` populates a shared scanner state that `resolveClick()` can reuse. Alternatively, have callers use `renderWithScanner()` and pass the returned scanner to `resolveClick()`:

```php
public static function resolveClickWithScanner(Game $g, int $col, int $row, Scanner $scanner): ?array
{
    $zone = $scanner->hit($col, $row);
    // ...
}
```

If backward compatibility is needed, keep `resolveClick()` as a convenience that delegates to `renderWithScanner()` and discards the frame.

---

### 5. `Board` — Inconsistent Constructor Promotion

**File:** `src/Board.php:26-41`
**Severity:** HIGH (Consistency)
**Confidence:** High

```php
public function __construct(
    public readonly int $width,
    public readonly int $height,
    public readonly int $mineCount,
    array $rows,                       // ← NOT promoted
    public readonly bool $minesPlaced = false,
    public readonly bool $exploded = false,
    public readonly int $revealedCount = 0,
) {
    // ...
    $this->rows = $rows;              // ← manual assignment
}
```

All other properties use constructor promotion, but `$rows` does not. This is inconsistent with the rest of the class and the broader codebase conventions.

**Recommendation:** Use constructor promotion for consistency:

```php
public function __construct(
    public readonly int $width,
    public readonly int $height,
    public readonly int $mineCount,
    public readonly array $rows,      // ← promoted
    public readonly bool $minesPlaced = false,
    public readonly bool $exploded = false,
    public readonly int $revealedCount = 0,
) {
    // Remove $this->rows = $rows;
}
```

---

## MEDIUM Issues

### 6. `Cell` — Not Declared `readonly class`

**File:** `src/Cell.php:13`
**Severity:** MEDIUM (Modern PHP)
**Confidence:** High

```php
final class Cell
{
    public function __construct(
        public readonly bool $mine,
        public readonly bool $revealed = false,
        public readonly bool $flagged = false,
        public readonly int $adjacent = 0,
    ) {}
}
```

The class is `final` with all `readonly` properties, but PHP 8.2+ supports `readonly class` declarations which provide stronger compile-time guarantees. With individual `readonly` properties, it's still possible (though unlikely) to create a non-readonly instance via a mutable constructor argument.

**Recommendation:** Upgrade to PHP 8.2+ `readonly class`:

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

Note: This requires PHP 8.2+. Verify the library's `composer.json` `php` requirement before applying.

---

### 7. Exception Type Inconsistency

**File:** `Board::unserialize()` vs `DifficultyStats::load()`
**Severity:** MEDIUM (Consistency)
**Confidence:** High

| Method | Throws | File:Line |
|--------|--------|-----------|
| `Board::unserialize()` | `\InvalidArgumentException` | `Board.php:258,261,270,273,279,285` |
| `DifficultyStats::load()` | `\RuntimeException` | `DifficultyStats.php:47,54,58,89,105` |

Both are deserialization methods that fail on invalid data, yet they throw different exception types. `\InvalidArgumentException` is more semantically appropriate for "the provided data is invalid," while `\RuntimeException` suggests "something went wrong at runtime that wasn't the caller's fault."

**Recommendation:** Standardize on `\InvalidArgumentException` for both, as the invalid data is provided by an external source (serialized string, JSON file) and thus is argument-like.

---

### 8. `Board::floodReveal()` — Non-Idiomatic Empty Check

**File:** `src/Board.php:166`
**Severity:** MEDIUM (Style)
**Confidence:** High

```php
while ($stack !== []) {
```

Uses `!== []` comparison rather than the more idiomatic PHP `!empty($stack)`.

**Recommendation:** Use `!empty($stack)` for idiomatic PHP and minor clarity improvement:

```php
while (!empty($stack)) {
```

---

## MINOR Issues

### 9. `DifficultyStats::load()` — Silent Default on Missing Fields

**File:** `src/Stats/DifficultyStats.php:64-72`
**Severity:** MINOR (Robustness)
**Confidence:** High

```php
$stats = new Stats(
    easyGames:   self::expectInt($data['easyGames']   ?? null, 0, $path),   // ← defaults to 0
    easyWins:    self::expectInt($data['easyWins']    ?? null, 0, $path),
    // ...
);
```

Missing JSON fields default to `0` rather than throwing validation errors. If a stats file is truncated or missing fields (e.g., from an older version that added new fields), the new fields silently become `0` instead of failing fast.

**Current behavior:** Missing `easyBest` → silently becomes `null`
**More strict behavior:** Missing field → throw `\RuntimeException`

**Recommendation:** Consider failing fast on missing fields rather than silently defaulting, especially for stats that represent meaningful user data:

```php
easyGames:   self::expectInt($data['easyGames']   ?? null, $path),  // no default — must be present
```

If backward compatibility is desired, this could be a documented behavior but should be explicitly noted in the docblock.

---

### 10. `Game::elapsed()` — Frozen Time Not Tested

**File:** `src/Game.php:269-278`
**Severity:** MINOR (Test Coverage)
**Confidence:** High

```php
public function elapsed(): ?float
{
    if ($this->startedAt === null) {
        return null;
    }
    if ($this->board->exploded || $this->board->isWon()) {
        return $this->elapsedSeconds !== null ? (float) $this->elapsedSeconds : null;  // ← frozen
    }
    return microtime(true) - $this->startedAt;  // ← live
}
```

The frozen time behavior (line 274-275) is not tested. Existing test `testTimerUsesMicrotimePrecision()` only verifies that elapsed increases during active play. There is no test that:
1. Reveals a cell causing an explosion
2. Calls `elapsed()` and verifies it returns the frozen `elapsedSeconds` value
3. Verifies subsequent calls to `elapsed()` continue to return the same frozen value

**Recommendation:** Add test:

```php
public function testElapsedIsFrozenAfterExplosion(): void
{
    $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);
    [$g, ] = $g->update(self::key(KeyType::Space));

    // Wait a bit
    usleep(50000);

    // Force explosion by finding a mine cell
    // (Test uses deterministic RNG, so mine positions are predictable)

    $frozenElapsed = $g->elapsed();
    usleep(50000);
    $this->assertSame($frozenElapsed, $g->elapsed());
}
```

---

### 11. `Board::flagCount()` — No Dedicated Unit Tests

**File:** `src/Board.php:207-216`
**Severity:** MINOR (Test Coverage)
**Confidence:** High

`flagCount()` has no dedicated unit tests. It is only exercised indirectly through:
- Render tests in `GoldenRenderTest.php` (which check the rendered output includes correct flag counts)
- Implicitly through `Renderer::status()` output

There is no isolated test verifying:
- Correct count after placing a flag
- Correct count after removing a flag
- Correct count after multiple toggles
- Correct count after reveal (flagged cell revealed, flag removed)

**Recommendation:** Add isolated unit tests in `BoardTest.php`:

```php
public function testFlagCountAfterToggle(): void
{
    $b = Board::blank(3, 3, 1);
    $this->assertSame(0, $b->flagCount());
    $b = $b->toggleFlag(0, 0);
    $this->assertSame(1, $b->flagCount());
    $b = $b->toggleFlag(1, 1);
    $this->assertSame(2, $b->flagCount());
    $b = $b->toggleFlag(0, 0);
    $this->assertSame(1, $b->flagCount());
}
```

---

## POSITIVE OBSERVATIONS

The codebase demonstrates several strong patterns worth highlighting:

### Immutable Value Object Pattern ✅
`Cell`, `Board`, `Stats`, and `Game` all correctly implement immutable value objects. Every state transition returns a new instance rather than mutating in place. The `with*()` pattern (e.g., `Stats::withGame()`) is consistently applied.

### TEA Model Pattern ✅
`Game` correctly implements the SugarCraft `Model` interface with proper `init()`, `update()`, `view()`, and `subscriptions()` methods. The TEA (Term, Effect, Action) pattern is correctly implemented — `update()` returns `[$model, ?Cmd]` tuples.

### Deterministic PRNG for Testing ✅
```php
public function __construct(
    // ...
    ?\Closure $rand = null,
) {
    $this->rand = $rand ?? static fn(int $max): int => random_int(0, $max);
}
```
This is excellent for fixture testing — tests can pin mine layouts without touching global state. The pattern is used consistently throughout the test suite.

### O(1) Win Detection ✅
```php
public function isWon(): bool
{
    if ($this->exploded) return false;
    return $this->revealedCount === $this->width * $this->height - $this->mineCount;
}
```
The `revealedCount` counter is a good pattern — avoids O(n*m) iteration on every win check. The same approach should be applied to `flagCount` (see Issue #3).

### Atomic Tmp+Rename Persistence ✅
`DifficultyStats::save()` correctly uses the atomic tmp+rename pattern:
```php
$tmp = $dir . '/.tmp_' . basename($path) . '.' . bin2hex(random_bytes(8));
if (file_put_contents($tmp, $payload, LOCK_EX) === false) { ... }
if (!rename($tmp, $path)) { ... }
```
This ensures the target file is never in a partial-write state, even on crash.

### Golden Render Tests ✅
Comprehensive snapshot tests in `GoldenRenderTest.php` assert against raw ANSI output. This is the correct approach for TUI rendering — testing the actual output bytes rather than intermediate state.

### Test Structure ✅
Tests are well-organized with:
- Clear `setUp()`/`tearDown()` lifecycle
- Descriptive test method names (`testFirstRevealOnEmptyAreaFloodsRecursively`)
- Deterministic RNG for reproducible layouts
- Proper isolation (each test gets a fresh `Game::start()`)

### Board Unserialize — Recomputes `revealedCount` ✅
`Board::unserialize()` (line 298-300) correctly recomputes `revealedCount` from live cell state rather than trusting the persisted value:
```php
// Drop the persisted 'r' value entirely — recompute from live cell state
// so a tampered payload cannot inflate revealedCount to force a false win
// or deflate it to make a finished board un-winnable.
```
This prevents save-game tampering attacks.

### Flood Fill Implementation ✅
`floodReveal()` correctly implements the classic minesweeper flood-fill with:
- Stack-based iteration (no recursion risk)
- `$seen` map to prevent re-processing cells
- Proper adjacency count check to stop propagation at numbered cells

---

## ASYNC PATTERNS NOTE

**Not applicable.** This is a synchronous TUI game that correctly uses the synchronous TEA pattern. No ReactPHP integration is needed or would be appropriate for this use case.

---

## Summary

| Category | Count | Fix Complexity |
|----------|-------|----------------|
| CRITICAL (duplication) | 2 | Medium |
| HIGH (perf/design) | 3 | Medium-High |
| MEDIUM (modern PHP/consistency) | 3 | Low |
| MINOR (tests) | 3 | Low |
| **Total** | **11** | |

**Recommended Priority:**

1. **Issue #3 (flagCount O(n*m))** — High impact on render performance. Straightforward fix (add counter like `revealedCount`).
2. **Issues #1 & #2 (Stats duplication)** — Medium effort, high long-term maintainability improvement.
3. **Issue #5 (Board $rows promotion)** — Trivial fix, improves consistency.
4. **Issue #6 (readonly class)** — Requires PHP 8.2+ compatibility check. Low effort if requirements allow.
5. **Issues #4, #7, #8, #9, #10, #11** — Lower priority but worthwhile improvements.

Overall the codebase is well-structured with good architectural decisions. The issues identified are technical debt rather than fundamental flaws.
