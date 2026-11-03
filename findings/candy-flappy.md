# Audit: candy-flappy

**Library:** SugarCraft/candy-flappy  
**Date:** 2026-06-30  
**PHP Version Target:** 8.3+

---

## HIGH Severity

### 1. Collision and Scoring Frame Mismatch (Off-by-One in Visual Feedback)

**Location:** `src/Game.php:273` (scoring), `src/Pipe.php:33` (collision)

Scoring fires at pipe x=7 when bird is at x=8 (`($p->x + 1) > 7 && $p->x <= 7` evaluates true at x=7). Collision fires at pipe x=8. Score increments one frame before the pipe visually appears to touch the bird.

**Recommendation:** Change scoring condition to fire at x=8 to align with collision:
```php
if ($p->x === self::BIRD_COL - 1) { $score++; }
```

---

### 2. Type Hint on `$rand` Property Too Broad

**Location:** `src/Game.php:38`

```php
private \Closure $rand;
```

Typed as `\Closure` but docblock specifies `\Closure(int): int`. Allows injection of closures with any signature.

**Recommendation:** Add `@var` annotation: `@var \Closure(int): int private \Closure $rand;`

---

## MEDIUM Severity

### 3. Style Object Allocation in Hot Render Loop

**Location:** `src/Renderer.php:28-31`, `src/Renderer.php:66-79`

`cellGlyph()` creates 4-5 new `Style` objects per cell. For 60×18 playfield = 1,080 cells × ~4 Style objects = ~4,320 allocations per frame at 30fps ≈ 129,600 allocations/second.

**Recommendation:** Pre-create reusable `Style` objects at class level:
```php
private static Style $birdStyle;
public static function init(): void {
    self::$birdStyle = Style::new()->foreground(Color::hex('#fde68a'))->bold();
}
```

---

### 4. High Score Persistence Not Atomic

**Location:** `src/Game.php:165-176`

`file_put_contents()` writes directly. If process crashes mid-write, scores file can be corrupted.

**Recommendation:** Write to temp file first, then atomically rename:
```php
$tempPath = $this->highScoreFilePath . '.tmp';
file_put_contents($tempPath, $json);
rename($tempPath, $this->highScoreFilePath);
```

---

### 5. `tickN()` Test Helper Bypasses Crash Gate

**Location:** `src/Game.php:315-322`

`tickN()` calls `advance()` directly, which continues updating bird position even after crash. Bird can fall to negative rows.

**Recommendation:** Add optional `$respectCrashGate` parameter.

---

## LOW Severity

### 6. Config Directory Path With Trailing Slash Creates Double Slash

**Location:** `src/Game.php:58`

```php
($configDir ?? $this->getDefaultConfigDir()) . '/' . self::HIGH_SCORE_FILE
```

If `$configDir` has trailing slash, result has `//`.

**Recommendation:** `rtrim($dir, '/') . '/' . self::HIGH_SCORE_FILE`

---

### 7. `rand()` Accessor Exposes Closure Reference

**Location:** `src/Game.php:119-122`

Returns `$this->rand` directly. Callers can invoke with arbitrary args.

**Recommendation:** Return callable wrapper: `return fn(int $max): int => ($this->rand)($max);`

---

### 8. `json_decode()` Without Error Checking

**Location:** `src/Game.php:87`

No `JSON_THROW_ON_ERROR`. Specific JSON error messages lost.

**Recommendation:**
```php
$decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
```

---

## PHP 8.3/8.4 Compatibility

### 9. First-Class Callable Syntax Not Used

**Location:** `src/Game.php:179-181`, `src/Game.php:230-233`

Using `static fn() => new TickMsg()` instead of PHP 8.3's `TickMsg::new`.

**Recommendation:** Use `TickMsg::new` where applicable.

---

### 10. `readonly` Classes Not Used

**Location:** All source files

Immutable classes (`Bird`, `Pipe`, `TickMsg`) not declared `readonly`. PHP 8.3's `readonly class` enforces immutability at engine level.

**Recommendation:** Declare immutable classes `readonly`: `readonly final class Bird { ... }`

---

## Async/ReactPHP Improvements

### 11. Synchronous High Score Persistence on Tick Path

**Location:** `src/Game.php:165-176`, `src/Game.php:216-228`

`mkdir()` and `file_put_contents()` are blocking operations inside tick closure.

**Recommendation:** Defer to next tick or use async file I/O. LOW priority (high score writes are rare).

---

## Security

### 12. Rand Closure Exception Not Caught

**Location:** `src/Game.php:262`

If injected `$rand` closure throws, game crashes with no recovery.

**Recommendation:** Wrap in try/catch with deterministic fallback.

---

## Summary

| Severity | Category | Issue | Location |
|----------|----------|-------|----------|
| HIGH | Bug | Collision/scoring frame mismatch | Game.php:273, Pipe.php:33 |
| HIGH | Type Safety | `\Closure` property type too broad | Game.php:38 |
| MEDIUM | Performance | Style allocations ~4K/frame | Renderer.php:28-31, 66-79 |
| MEDIUM | Data Loss | Non-atomic high score writes | Game.php:165-176 |
| MEDIUM | Design | `tickN()` bypasses crash gate | Game.php:315-322 |
| LOW | Code Quality | Config path trailing slash | Game.php:58 |
| LOW | Code Quality | `rand()` exposes closure ref | Game.php:119-122 |
| LOW | Code Quality | JSON error not detailed | Game.php:87 |
| PHP 8.3+ | Compat | First-class callable not used | Game.php:179-181 |
| PHP 8.3+ | Compat | `readonly` classes not used | All source |
| Async | Architecture | Sync I/O in tick path | Game.php:165-176 |
| Security | Robustness | Rand closure exception not caught | Game.php:262 |

**Total: 12 findings**


---

## FIX LEDGER — 2026-11-03 (lib-reviews-1102 persona campaign, this lane)

The 2026-06-30 findings above were superseded/re-verified during the full
17-persona review (worklist `_COMBINED.md`, 1 CRIT / 27 MAJOR / 34 minor /
19 nit) and its fix pass. All fix commits are on master; SHAs disk-verified.

### June-era rows, status at tip 34f3adeeb

| # | Finding | Status |
|---|---------|--------|
| H1 | scoring/collision off-by-one | NOT A DEFECT (re-verified: score fires at x=7, one frame AFTER the x=8 collision window passes — clearing, not pre-collision; misread in the original audit) |
| H2 | `\Closure` hint too broad | FIXED — property `@var` now spells `\Closure(int $maxInclusive): int` (dab34d0f1; the m12 pass in 34f3adeeb had fixed only the `makePipe` docblock) |
| M3 | Style allocation in render loop | FIXED — memoised cells + per-x wall precompute, goldens byte-identical (59fbe374e) |
| M4 | non-atomic high-score write | FIXED — `AtomicJsonFile` 0600 flock+temp+rename (c5018b217) |
| M5 | `tickN()` bypasses crash gate | DOCUMENTED + PINNED — public test helper by design; world stepping goes through `update(TickMsg)` which is gated (crash, ready, too-small, NoTty) |
| L6 | trailing-slash double slash | FIXED at closeout (dab34d0f1) — `scoreFilePath` now `rtrim`s the dir; reflection pin asserts the exact joined path, mutation-discriminating (revert reddens exactly it). A first ledger draft said already-landed; the disk probe caught the misrecord |
| L7 | `rand()` exposes closure | KEPT — in-process test seam, no secret; type contract now documented |
| L8 | `json_decode` unchecked | FIXED — quarantine-on-corrupt launch path, size cap, clamp (c5018b217) |
| L9 | first-class callables | N/A (closures passed as data) |
| L10 | readonly classes | FIXED — Game props promoted readonly since the posture pass (efdd83108/546eebba3) |
| L11 | sync persist on tick path | KEPT — bounded single write on crash/quit only, `m6` decline reason applies |
| L12 | rand closure exception uncaught | FIXED-ENVELOPE — default rand is `rand()`-backed; injection contract documented; app never wraps user closures in catch (by design, fail loud) |

### 1102 campaign dispositions (headline ids)

FIXED: C1 (150a1c9e0 tick→subscriptions), M1 (0ecfbec60 phantom API table +
real golden recipe docs), M2 (83abebf7a gap parity, FEEL-CHANGE disclosed),
M3/M4 (c5018b217), M5 (83abebf7a hermetic), M6/M7/M8/M26/m30 (efdd83108
terminal posture), M9 lib-half (REDUCE_MOTION honour → SEAM sugar-spring),
M10/M18/M20/M24/m28/m29 (546eebba3), M11/M31 (150a1c9e0), M12→DECLINED
(extension registry = whole-app architecture ask, SEAM), M13/M14/M15/M16/m5
(0ecfbec60 bin+install docs), M17→DECLINED (local single-player scores,
forge-clamp already landed), M19/M20-keys (546eebba3), M21→DECLINED (30 Hz
cadence is the spec; caps sacred), M22→DECLINED feel change (lethal ceiling
documented = spec), M23 (59fbe374e), M25 i18n→DECLINED in-lane (16-locale
wiring is its own lane; minesweeper `Lang` pattern cited), M27/m25 (83abebf7a),
M28-M32 minors (spread across 546eebba3/0ecfbec60/34f3adeeb), m1-m32 sweep
(34f3adeeb + commits above). NOT-REPRODUCED: golden contamination under
polluted HOME (proven hermetic x2, poison-HOME run), Renderer dead code
leg (rewritten with the posture pass). CORRECTED AT CLOSEOUT: `.vhs/play.gif`
IS tracked (907 KB) and candy-flappy is in the vhs.yml matrix — CI re-renders
it, so the ready-card copy refreshes on the next CI run (house convention;
r77 ruling says AGENTS.md's "Don't commit GIFs" line is the stale one). The
`getDefaultConfigDir` fail-closed throw also had NO pin before this lane's
closeout (an interim note wrongly claimed one did);
`testAnUnresolvableConfigDirectoryFailsLoud` now covers it, and
`m1`/`m3`-ctrl key-gating shipped with 150a1c9e0/efdd83108.
DECLINED w/ SEAM: mouse flap, pause/focus (m34), gapY-delta clamp (m33,
caps), `?` help overlay + key-surface unification (m2), alt-gate on keys
(m3b, InputReader C0-rune ambiguity), persist-fail stderr trace (m6,
alt-screen scribble), colour/glyph taste nits.

