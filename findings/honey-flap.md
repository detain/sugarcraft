# Audit: honey-flap

**Library:** SugarCraft/honey-flap  
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
