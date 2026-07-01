---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: honey-flap Audit Fixes

## Goal
Address all 12 findings from the honey-flap audit, covering bug fixes, performance improvements, PHP 8.3+ compatibility, and security enhancements.

## Context & Decisions
| Decision | Rationale | Source |
|----------|-----------|--------|
| Scoring fires at x=7, collision at x=8 | Off-by-one bug causes score increment one frame before visual collision | `honey-flap.md:1-19` |
| Pre-create Style objects as statics | 4,320 allocations/frame at 60×18 playfield is wasteful | `honey-flap.md:38-49` |
| Use atomic rename for high score writes | `file_put_contents` direct write risks corruption on crash | `honey-flap.md:53-63` |
| Add `@var` annotation for Closure type | Docblock specifies `\Closure(int): int` but type hint is just `\Closure` | `honey-flap.md:23-32` |
| PHP 8.3 `readonly` classes for immutable types | Engine-level enforcement of immutability | `honey-flap.md:123-128` |
| First-class callable `TickMsg::new` | PHP 8.3 syntax, cleaner than `static fn()` | `honey-flap.md:114-119` |

## Phase 1: HIGH Severity Fixes [PENDING]

- [ ] **1.1 Collision and Scoring Frame Mismatch** ← CURRENT
  - **File:** `src/Game.php:273`
  - **Current Code:**
    ```php
    if (($p->x + 1) > self::BIRD_COL - 1 && $p->x <= self::BIRD_COL - 1) {
        $score++;
    }
    ```
  - **Expected Change:** Change to fire at x=8 (BIRD_COL-1 = 7) to align with collision:
    ```php
    if ($p->x === self::BIRD_COL - 1) { $score++; }
    ```
  - **Why:** Scoring currently fires when pipe x=7, but collision detection in `Pipe.php:33` only triggers when pipe x equals bird column (8). This creates a one-frame visual mismatch where score increments before the pipe appears to touch the bird.
  - **Severity:** HIGH - User-facing visual bug
  - **Conditions for Success:** Add test verifying score increments when pipe x becomes 8 (BIRD_COL), not 7
  - **Investigation Notes:**
    - `Game.php:273` - scoring condition uses `(x+1) > 7 && x <= 7` which fires when x=7
    - `Pipe.php:33` - collision `if ($col !== $this->x)` only triggers when pipe x equals bird x (8)
    - `Game.php:32` - `BIRD_COL = 8`
    - Comment at lines 270-272 explains the logic but the math is slightly off

- [ ] 1.2 Type Hint on `$rand` Property Too Broad
  - **File:** `src/Game.php:37-38`
  - **Current Code:**
    ```php
    /** @var \Closure(int): int */
    private \Closure $rand;
    ```
  - **Expected Change:** Move `@var` to be a proper PHPDoc annotation directly above the property declaration at line 38
  - **Why:** The docblock specifies `\Closure(int): int` but PHP only sees `\Closure`. This allows closures with any signature to be injected, violating the contract.
  - **Severity:** HIGH - Type safety violation
  - **Conditions for Success:** PHPStan level 5+ passes with no closure signature warnings
  - **Investigation Notes:**
    - Line 37-38 has docblock with `@var \Closure(int): int` but property declaration doesn't use proper annotation style
    - Constructor at line 57 accepts `?\Closure` and defaults to `static fn(int $max): int => random_int(0, $max)`
    - Line 262 calls `$this->rand` with `(int $max)` argument

## Phase 2: MEDIUM Severity Fixes [PENDING]

- [ ] 2.1 Style Object Allocation in Hot Render Loop
  - **File:** `src/Renderer.php:28-31`, `src/Renderer.php:66-79`
  - **Current Code (lines 66-79):**
    ```php
    if ($x === $birdX && $y === $birdRow) {
        return Style::new()->foreground(Color::hex('#fde68a'))->bold()->render('>');
    }
    if ($p->collides($x, $y)) {
        return Style::new()->foreground(Color::hex('#6ee7b7'))->render('▓');
    }
    if (($x + $g->tickIndex) % 12 === 0 && ($y % 5) === 2) {
        return Style::new()->foreground(Color::hex('#3a2c5a'))->render('·');
    }
    ```
  - **Expected Change:** Pre-create reusable static Style objects:
    ```php
    private static Style $birdStyle;
    private static Style $pipeStyle;
    private static Style $parallaxStyle;

    public static function init(): void {
        self::$birdStyle = Style::new()->foreground(Color::hex('#fde68a'))->bold();
        self::$pipeStyle = Style::new()->foreground(Color::hex('#6ee7b7'));
        self::$parallaxStyle = Style::new()->foreground(Color::hex('#3a2c5a'));
    }
    ```
  - **Why:** 60×18 playfield = 1,080 cells × ~4 Style objects = ~4,320 allocations per frame at 30fps ≈ 129,600 allocations/second. Pre-allocation eliminates this overhead.
  - **Severity:** MEDIUM - Performance
  - **Conditions for Success:** Benchmark showing <10% CPU reduction in render path; golden file tests still pass
  - **Investigation Notes:**
    - `cellGlyph()` is called for every cell in the playfield (lines 28-29)
    - `Style::new()` returns fresh instance each time (`candy-sprinkles/src/Style.php:103-106`)
    - Three styles are reused throughout the render loop: bird, pipe, parallax dots

- [ ] 2.2 High Score Persistence Not Atomic
  - **File:** `src/Game.php:165-176` (`persistHighScores()`) and `src/Game.php:216-228` (closure in update)
  - **Current Code:**
    ```php
    $json = json_encode($this->highScores, JSON_PRETTY_PRINT);
    $written = @file_put_contents($this->highScoreFilePath, $json);
    ```
  - **Expected Change:** Write to temp file first, then atomically rename:
    ```php
    $tempPath = $this->highScoreFilePath . '.tmp';
    $json = json_encode($this->highScores, JSON_PRETTY_PRINT);
    $written = @file_put_contents($tempPath, $json);
    if ($written !== false) {
        rename($tempPath, $this->highScoreFilePath);
    }
    ```
  - **Why:** If process crashes mid-write, the scores file can be corrupted. Atomic rename ensures either complete old or complete new file.
  - **Severity:** MEDIUM - Data loss risk
  - **Conditions for Success:** Crash simulation test: write is interrupted, old scores preserved
  - **Investigation Notes:**
    - `persistHighScores()` at line 165 uses direct `file_put_contents`
    - Closure at lines 216-228 also uses direct write
    - Both need the temp-file pattern

- [ ] 2.3 `tickN()` Test Helper Bypasses Crash Gate
  - **File:** `src/Game.php:315-322`
  - **Current Code:**
    ```php
    public function tickN(int $n): self
    {
        $g = $this;
        for ($i = 0; $i < $n; $i++) {
            $g = $g->advance();
        }
        return $g;
    }
    ```
  - **Expected Change:** Add optional `$respectCrashGate` parameter:
    ```php
    public function tickN(int $n, bool $respectCrashGate = false): self
    {
        $g = $this;
        for ($i = 0; $i < $n; $i++) {
            if ($respectCrashGate && $g->crashed) {
                break;
            }
            $g = $g->advance();
        }
        return $g;
    }
    ```
  - **Why:** `tickN()` calls `advance()` directly which continues updating bird position after crash. Bird can fall to negative rows. Tests rely on the crash gate in `update()` but `tickN()` bypasses it.
  - **Severity:** MEDIUM - Test integrity
  - **Conditions for Success:** New parameter defaults to `false` for backward compatibility; tests pass
  - **Investigation Notes:**
    - `tickN()` at line 315-322 is a test helper
    - Test at `tests/GameTest.php:69-82` documents this behavior explicitly in comments
    - `advance()` method at line 247 doesn't check `crashed` flag (it's checked in `update()` at line 206)

## Phase 3: LOW Severity Fixes [PENDING]

- [ ] 3.1 Config Directory Path With Trailing Slash
  - **File:** `src/Game.php:58`
  - **Current Code:**
    ```php
    $this->highScoreFilePath = ($configDir ?? $this->getDefaultConfigDir()) . '/' . self::HIGH_SCORE_FILE;
    ```
  - **Expected Change:**
    ```php
    $dir = rtrim($configDir ?? $this->getDefaultConfigDir(), '/');
    $this->highScoreFilePath = $dir . '/' . self::HIGH_SCORE_FILE;
    ```
  - **Why:** If `$configDir` has trailing slash, result has `//` which can cause issues on some filesystems.
  - **Severity:** LOW - Code quality
  - **Conditions for Success:** Path doesn't contain `//` when configDir has trailing slash

- [ ] 3.2 `rand()` Accessor Exposes Closure Reference
  - **File:** `src/Game.php:119-122`
  - **Current Code:**
    ```php
    public function rand(): \Closure
    {
        return $this->rand;
    }
    ```
  - **Expected Change:** Return callable wrapper:
    ```php
    public function rand(): callable
    {
        return fn(int $max): int => ($this->rand)($max);
    }
    ```
  - **Why:** Returns `$this->rand` directly. Callers can invoke with arbitrary args. A typed wrapper enforces the signature.
  - **Severity:** LOW - API design
  - **Conditions for Success:** Type changes from `\Closure` to `callable`; invocation is validated

- [ ] 3.3 `json_decode()` Without Error Checking
  - **File:** `src/Game.php:87`
  - **Current Code:**
    ```php
    $decoded = json_decode($contents, true);
    ```
  - **Expected Change:**
    ```php
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    ```
  - **Why:** No `JSON_THROW_ON_ERROR`. Specific JSON error messages lost on failure.
  - **Severity:** LOW - Debuggability
  - **Conditions for Success:** Invalid JSON causes specific exception with message

## Phase 4: PHP 8.3/8.4 Compatibility [PENDING]

- [ ] 4.1 First-Class Callable Syntax Not Used
  - **File:** `src/Game.php:179-181`, `src/Game.php:230-233`
  - **Current Code (line 180):**
    ```php
    return Cmd::tick(0.033, static fn() => new TickMsg());
    ```
  - **Expected Change:**
    ```php
    return Cmd::tick(0.033, TickMsg::new);
    ```
  - **Current Code (line 231):**
    ```php
    Cmd::tick(0.033, static fn() => new TickMsg())
    ```
  - **Expected Change:**
    ```php
    Cmd::tick(0.033, TickMsg::new)
    ```
  - **Why:** PHP 8.3's first-class callable syntax is more readable and has slightly better performance.
  - **Severity:** PHP 8.3+ - Modernization
  - **Conditions for Success:** Code compiles and runs; `TickMsg::new` is a valid first-class callable
  - **Investigation Notes:**
    - `TickMsg` has no constructor arguments (see `src/TickMsg.php:15-17`)
    - `TickMsg` is `final class` with no factory method - direct instantiation works

- [ ] 4.2 `readonly` Classes Not Used
  - **File:** `src/Bird.php:22`, `src/Pipe.php:12`, `src/TickMsg.php:15`
  - **Current Code (Bird.php):**
    ```php
    final class Bird { ... }
    ```
  - **Expected Change:**
    ```php
    readonly final class Bird { ... }
    ```
  - **Why:** PHP 8.3's `readonly` class enforces immutability at engine level. These classes represent immutable game entities.
  - **Severity:** PHP 8.3+ - Modernization
  - **Conditions for Success:** PHP 8.3+ pass; all properties already readonly via constructor promotion
  - **Investigation Notes:**
    - `Bird.php:32-35` - all properties use `readonly` constructor promotion
    - `Pipe.php:14-18` - all properties use `readonly` constructor promotion
    - `TickMsg.php:15-17` - no properties, empty class
    - These classes already have immutable semantics; `readonly` adds engine enforcement

## Phase 5: Async/ReactPHP & Security [PENDING]

- [ ] 5.1 Synchronous High Score Persistence on Tick Path
  - **File:** `src/Game.php:165-176`, `src/Game.php:216-228`
  - **Issue:** `mkdir()` and `file_put_contents()` are blocking operations inside tick closure
  - **Expected Change:** Consider deferring to async file I/O or next tick. Low priority since high score writes are rare.
  - **Why:** Blocking I/O in the tick path can cause frame drops
  - **Severity:** LOW - Architecture (async)
  - **Investigation Notes:**
    - The closure at 216-228 already handles errors gracefully with try/catch
    - This is marked LOW priority in the findings - deferred consideration

- [ ] 5.2 Rand Closure Exception Not Caught
  - **File:** `src/Game.php:262`
  - **Current Code:**
    ```php
    $pipes[] = PipeGenerator::makePipe($this->score, $this->rand);
    ```
  - **Expected Change:** Wrap in try/catch with deterministic fallback:
    ```php
    try {
        $pipes[] = PipeGenerator::makePipe($this->score, $this->rand);
    } catch (\Throwable) {
        // Fallback to deterministic pipe on rand failure
        $pipes[] = PipeGenerator::makePipe($this->score, static fn(int $max): int => 0);
    }
    ```
  - **Why:** If injected `$rand` closure throws, game crashes with no recovery.
  - **Severity:** Security - Robustness
  - **Conditions for Success:** Bad rand closure doesn't crash game; falls back to safe deterministic behavior

## Phase 6: Testing & Verification [PENDING]

- [ ] 6.1 Run Existing Test Suite
  - **Command:** `cd honey-flap && composer install && vendor/bin/phpunit`
  - **Conditions for Success:** All tests pass before and after changes

- [ ] 6.2 Add New Tests for Bug Fixes
  - **Score increment timing test:** Verify score increments when pipe x equals BIRD_COL (8), not BIRD_COL-1 (7)
  - **Crash gate test:** Verify `tickN()` with `$respectCrashGate=true` stops at crash boundary
  - **Atomic write test:** Verify temp file rename semantics

---

## Summary Table

| # | Severity | Issue | Location | Phase |
|---|----------|-------|----------|-------|
| 1 | HIGH | Collision/scoring frame mismatch | Game.php:273, Pipe.php:33 | 1.1 |
| 2 | HIGH | `\Closure` property type too broad | Game.php:38 | 1.2 |
| 3 | MEDIUM | Style allocations ~4K/frame | Renderer.php:28-31, 66-79 | 2.1 |
| 4 | MEDIUM | Non-atomic high score writes | Game.php:165-176 | 2.2 |
| 5 | MEDIUM | `tickN()` bypasses crash gate | Game.php:315-322 | 2.3 |
| 6 | LOW | Config path trailing slash | Game.php:58 | 3.1 |
| 7 | LOW | `rand()` exposes closure ref | Game.php:119-122 | 3.2 |
| 8 | LOW | JSON error not detailed | Game.php:87 | 3.3 |
| 9 | PHP 8.3+ | First-class callable not used | Game.php:179-181, 230-233 | 4.1 |
| 10 | PHP 8.3+ | `readonly` classes not used | Bird.php, Pipe.php, TickMsg.php | 4.2 |
| 11 | Async | Sync I/O in tick path | Game.php:165-176 | 5.1 |
| 12 | Security | Rand closure exception not caught | Game.php:262 | 5.2 |

## Notes
- 2026-06-30: Initial plan created from audit findings `honey-flap.md`
- All source locations verified against actual code during investigation
- Tests at `tests/GameTest.php` provide existing coverage patterns to follow
