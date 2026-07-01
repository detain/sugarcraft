# Code Review: candy-palette

## Overview

candy-palette is a PHP port of charmbracelet/colorprofile — terminal color profile detection and color degradation (TrueColor → ANSI256 → ANSI → ASCII). The library has solid fundamentals but suffers from significant code duplication, architectural inconsistencies, and some potential issues.

---

## Critical Issues

### 1. Duplicate Profile Enums (Major Confusion Risk)

**Files affected:** `src/Profile.php`, `src/ColorProfile.php`

Two near-identical enums exist representing terminal color capabilities:

- **`Profile`** (src/Profile.php:15-102): `TrueColor`, `ANSI256`, `ANSI`, `Ascii`, `NoTTY` — case names use UPPER_SNAKE
- **`ColorProfile`** (src/ColorProfile.php:13-51): `NoTTY`, `Ascii`, `Ansi`, `Ansi256`, `TrueColor` — case names use PascalCase

These have the same underlying string values but different case naming conventions. The relationship is bridged by `Profile::fromColorProfile()` at Profile.php:35-44, but having two enums with the same cases is confusing and error-prone.

**Recommendation:** Consolidate into a single enum. The naming mismatch (ANSI256 vs Ansi256) suggests a lack of consistent naming convention.

---

### 2. Massively Duplicated Detection Logic

**Files affected:** `src/Palette.php:193-295`, `src/Probe.php:31-94`, `src/Probe/TerminalProbe.php:103-185`

Three separate implementations of terminal color profile detection with identical priority orders:

1. `Palette::detectProfile()` — used by Palette for profile detection
2. `Probe::colorProfile()` — static probe utility 
3. `TerminalProbe::checkEnvVars()` — part of TerminalProbe's 4-phase detection

All follow the same 13-step priority order documented in Palette.php:174-192. This violates DRY and creates maintenance burden.

**Specific duplications:**
- TERM pattern matching (`xterm*`, `screen*`, `tmux*`, `-256color`, `xterm-kitty`, `xterm-ghostty`) appears in three places
- TMUX/STY detection logic appears in three places  
- WT_SESSION, GOOGLE_CLOUD_SHELL checks appear in three places
- The same 13-step priority comment appears in multiple places (Probe.php:10-22 and Palette.php:33-47)

**Recommendation:** Extract common detection logic into a shared detection service/class that all three can use.

---

### 3. Static State in StandardColors with Init Outside Class

**Files affected:** `src/StandardColors.php:93-109`

The static Color properties are initialized OUTSIDE the class definition:

```php
StandardColors::$black       = new Color(0x00, 0x00, 0x00);
// ... 15 more lines
```

This is an unusual pattern that:
- Violates typical PSR-4/PSR-12 expectations
- Makes it harder to find where initialization happens
- Could cause issues with some autoloaders or in certain contexts

**Additionally:** The `all()` method at StandardColors.php:46-66 creates a new array on every call by listing all colors explicitly. This could be cached.

---

### 4. Static Mutable State in Probe Class

**Files affected:** `src/Probe.php:229, 231-238`

`Probe::$infocmpPath` is a static property that gets cached after first check:

```php
private static ?string $infocmpPath = null;

private static function infocmpAvailable(): bool
{
    if (self::$infocmpPath !== null) {
        return self::$infocmpPath !== '';
    }
    self::$infocmpPath = is_file('/usr/bin/infocmp') ? '/usr/bin/infocmp'
        : (is_file('/bin/infocmp') ? '/bin/infocmp' : '');
    return self::$infocmpPath !== '';
}
```

Issues:
- Mutable static state persists across requests in long-running PHP processes
- `_reset()` method is marked `@internal` but is `public` — problematic for API contract
- No thread-safety consideration (though PHP doesn't typically have threading issues)

---

### 5. Regex Potential for ReDoS in stripAnsi

**Files affected:** `src/Palette.php:137-145`

The stripAnsi regex is complex:
```php
'/(?:\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[[0-9;:<>=?]*[A-Za-z]|\x1b[PX^_][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b[OopeHMJKhCBDsu]|\x1b[()*+][@-~])/'
```

While the regex itself is probably safe (the character classes prevent exponential backtracking), the complexity makes it difficult to verify. The `?? $s` null-coalesce on preg_replace is good defensive programming.

---

### 6. shell_exec Usage — Security and Async Issues

**Files affected:** `src/Probe.php:216`, `src/Probe/TerminalProbe.php:204,367`

`shell_exec()` is used for running `infocmp`:
- **Security**: Potential for command injection if input isn't properly escaped (though `escapeshellarg()` is used)
- **Blocking**: `shell_exec()` is synchronous and blocks the process
- **No async**: Cannot be used in ReactPHP event loop contexts

```php
// Probe.php:216
$output = @shell_exec(self::$infocmpPath . ' -1 ' . escapeshellarg($term) . ' 2>/dev/null');

// TerminalProbe.php:204
$output = $this->runCommand('infocmp -1 ' . escapeshellarg($term) . ' 2>/dev/null');
```

---

## Logic Issues

### 7. Palette::detectProfile — Inconsistent $_ENV vs getenv() Access

**File:** `src/Palette.php:193-295`

The detection method inconsistently accesses environment variables:

```php
// Line 196 — explicit getenv fallback
$cliclorForce = $env['CLICOLOR_FORCE'] ?? \getenv('CLICOLOR_FORCE');

// Line 203 — complex nested ternary with $_ENV and getenv
$force = isset($env['FORCE_COLOR']) ? $env['FORCE_COLOR'] : (isset($_ENV['FORCE_COLOR']) ? $_ENV['FORCE_COLOR'] : \getenv('FORCE_COLOR'));

// Line 225 — accesses $_ENV directly
$ct = $env['COLORTERM'] ?? $_ENV['COLORTERM'] ?? \getenv('COLORTERM') ?: null;
```

This is inconsistent. Sometimes `$env` parameter is used first, sometimes `$_ENV`. The `$env` parameter is meant to allow injection for testing, but some places fall back to `$_ENV` directly.

---

### 8. Color::closestAnsi16 — Static Palette Rebuilt Every Call

**File:** `src/Color.php:307-332`

```php
private function closestAnsi16(): int
{
    $minDist = PHP_INT_MAX;
    $closest = 0;

    static $palette = [  // <-- Static but rebuilt on first call per call site
        [0x00, 0x00, 0x00],
        // ...
    ];

    foreach ($palette as $i => [$pr, $pg, $pb]) {
        // ...
    }
    return $closest;
}
```

The static keyword means the palette is only built once per execution, but this function could be a static property or constant for clarity.

---

### 9. Color::toAnsi256 Duplicate Round-Trip Logic

**Files:** `src/Color.php:232-244`, `src/Color.php:208-218`

The ANSI256 conversion and the ANSI256 index decoding have duplicated calculation logic:

```php
// toAnsi256() at line 240-243:
$r = (($idx - 16) / 36) * 255 / 5;
$g = ((($idx - 16) % 36) / 6) * 255 / 5;
$b = (($idx - 16) % 6) * 255 / 5;

// fromAnsi256Index() at line 214-216:
$r = (int) \round((($idx - 16) / 36) * 255 / 5);
$g = (int) \round(((($idx - 16) % 36) / 6) * 255 / 5);
$b = (int) \round((($idx - 16) % 6) * 255 / 5);
```

The difference is int rounding in `fromAnsi256Index` but the formulas are identical. Could be extracted to a shared helper.

---

### 10. TerminalProbe Constructor — env Merging Complexity

**File:** `src/Probe/TerminalProbe.php:49-53`

```php
public function __construct(array $env = [], bool $interactive = true)
{
    $this->env = array_merge($_ENV, getenv() ?: [], $env);
    $this->interactive = $interactive && $this->isInteractive();
}
```

The `getenv() ?: []` is redundant since `getenv()` returns `array|false`, and `array_merge` handles arrays correctly. But more importantly, the order of merging means `$env` parameter overrides `getenv()` which overrides `$_ENV`. This seems intentional but is not well-documented.

---

## Architectural Issues

### 11. No PHPStan/Static Analysis Config

No evidence of PHPStan configuration or any static analysis tooling. For a library that will be used across the monorepo, static analysis is important for catching type errors.

---

### 12. No php-cs-fixer Configuration

No `.php-cs-fixer.dist.php` or similar code style enforcement, despite being mentioned in AGENTS.md as a convention.

---

### 13. Heavy Dependency Footprint for a "Core" Library

**File:** `composer.json:27-30`

```json
"require": {
    "php": "^8.3",
    "sugarcraft/candy-core": "dev-master",
    "sugarcraft/candy-sprinkles": "dev-master"
}
```

For a terminal color detection library, having two internal dependencies (candy-core for i18n Lang facade, candy-sprinkles for what appears to be theming) may be heavier than necessary. A color profile library should ideally have minimal dependencies.

---

## Missing Features / Gaps

### 14. No Async/Terminal Probe Integration

This is a ReactPHP-based ecosystem (`sugarcraft/candy-async` exists in the monorepo), but:
- Terminal probing uses blocking `shell_exec()`
- No async alternative for running infocmp
- No Promise-based or streaming terminal capability detection

**Recommendation:** Consider adding async variants using ReactPHP's ChildProcess component.

---

### 15. No Built-in Caching for Probe Results

Every call to `Probe::colorProfile()` or `TerminalProbe::run()` potentially:
- Re-reads environment variables
- May run `shell_exec` for infocmp

For a long-running CLI tool that repeatedly checks capabilities, this is wasteful.

---

### 16. No Clear CLI Tool Integration Pattern

The library detects terminal capabilities but there's no established pattern for how CLI applications should integrate. Compare to Go's `colorprofile` which is typically imported and used directly.

---

### 17. Escape Query Implementation is Incomplete

**File:** `src/Probe/TerminalProbe.php:232-274`

The comments note that escape queries for terminal capabilities require "actually writing to the terminal and reading the response" but the actual escape query implementation is minimal:

```php
// Note: Escape queries require actually writing to the terminal
// and reading the response. This is typically done via DA1 (Primary
// device attributes) query. For now, we detect known capabilities
// based on TERM_PROGRAM.
```

The `checkEscapeQueries` method only reads `TERM_PROGRAM` hints — actual DA1/DA2 query parsing is not implemented.

---

## Code Quality Issues

### 18. ProfileWriter Write Method Creates New Palette on Every Call

**File:** `src/ProfileWriter.php:95-119`

```php
public function write(string $data): int|false
{
    // ...
    } elseif ($this->profile !== Profile::TrueColor) {
        $palette = (new Palette($this->stream, [
            'NO_COLOR' => '1',
            'CLICOLOR_FORCE' => null,
            // ... more nulls
        ]))->withProfile($this->profile);
        $data = $palette->degrade($data);
    }
    return \fwrite($this->stream, $data);
}
```

A new `Palette` object is created on every `write()` call when the profile is not TrueColor. This is wasteful — the Palette object could be created once and reused.

---

### 19. Missing Test for rewriteAnsi with Invalid Sequences

**Files:** `tests/DegradeTest.php`, `tests/PaletteTest.php`

The regex in `Palette::rewriteAnsi()` assumes well-formed ANSI sequences. There's no test coverage for:
- Malformed SGR sequences
- Incomplete escape sequences
- Mixed format sequences (e.g., 38;2;RGB with extra semicolons)

---

### 20. Capability Enum Has No Bridge to Profile/ColorProfile

**Files:** `src/Probe/Capability.php`, `src/Profile.php`, `src/ColorProfile.php`

There's no conversion method between `Capability` enum (used in TerminalProbe) and the `Profile`/`ColorProfile` enums. A user who runs `TerminalProbe` to get capabilities cannot easily convert that to a `Profile` for use with `Palette::degrade()`.

---

## Minor Issues

### 21. Color::perceivedBrightness Uses Slow Power Operation

**File:** `src/Color.php:297-304`

```php
private function perceivedBrightness(): float
{
    return \sqrt(
        0.299 * ($this->r ** 2) +
        0.587 * ($this->g ** 2) +
        0.114 * ($this->b ** 2)
    );
}
```

Using `** 2` is slower than `$this->r * $this->r`. For a color library called frequently, this micro-optimization might matter.

---

### 22. Lang Facade Dependency Chain

**File:** `src/Lang.php:7`

```php
use SugarCraft\Core\I18n\Lang as BaseLang;
```

`candy-palette` depends on `candy-core` solely for the `Lang` i18n facade. If `Lang::t()` calls are minimal (only one at StandardColors.php:77), could consider using a simpler translation approach or inlining the error message.

---

### 23. Redundant null Checks

In `Palette::detectProfile()`, there are patterns like:

```php
$wtSession = $env['WT_SESSION'] ?? \getenv('WT_SESSION') ?: null;
if ($wtSession !== null && $wtSession !== '') {
```

The `!== ''` check is redundant if the value came from `getenv()` which returns `false` (not `''`) when not set, and the coalescing handles that. However, it doesn't hurt.

---

### 24. testConvertToAnsiIsReducedPalette Assertion is Fragile

**File:** `tests/ColorTest.php:128-133`

```php
public function testConvertToAnsiIsReducedPalette(): void
{
    $c = new Color(255, 80, 80);
    $ansi = $c->convert(Profile::ANSI);
    $this->assertContains($ansi->r, [0xcd, 0xff, 0x00, 0x7f]);
}
```

The assertion that `ANSI red` could be one of 4 values is fragile. A more precise test would verify exact conversion behavior.

---

## Async Patterns Assessment

### 25. Completely Synchronous Library

The library is entirely synchronous:
- All detection uses blocking I/O
- `shell_exec()` blocks for infocmp
- No Promise/Future patterns
- No ReactPHP EventLoop integration

In a ReactPHP ecosystem, a library doing terminal detection should ideally:
- Offer async process execution for infocmp
- Provide a Promise-based API for color profile detection
- Support non-blocking terminal capability queries

**Current state:** Not async-ready at all.

---

## Summary of Recommendations (Priority Order)

1. **High**: Consolidate `Profile` and `ColorProfile` into a single enum
2. **High**: Extract shared terminal detection logic to eliminate duplication across Palette, Probe, and TerminalProbe  
3. **Medium**: Remove mutable static state from Probe (consider caching service instead)
4. **Medium**: Move StandardColors static initialization inside class or use lazy initialization
5. **Medium**: Add async/ReactPHP support for terminal probing
6. **Medium**: Cache Palette object in ProfileWriter::write() to avoid repeated instantiation
7. **Low**: Replace `** 2` with `$r * $r` in Color::perceivedBrightness()
8. **Low**: Add PHPStan configuration for static analysis
9. **Low**: Add php-cs-fixer configuration for code style
10. **Low**: Reduce dependency footprint by inlining the single Lang::t() call

---

## Files Reviewed

**Source (11 files):**
- `src/Color.php` (356 lines) — RGBA value object with conversion methods
- `src/Palette.php` (338 lines) — Main detection and degradation API
- `src/Profile.php` (102 lines) — Terminal profile enum  
- `src/ColorProfile.php` (51 lines) — Duplicate terminal profile enum
- `src/ProfileWriter.php` (128 lines) — Stream wrapper for automatic degradation
- `src/Probe.php` (250 lines) — Static terminal probe utility
- `src/StandardColors.php` (109 lines) — 16-color ANSI palette
- `src/Lang.php` (22 lines) — i18n facade
- `src/Probe/TerminalProbe.php` (404 lines) — Multi-phase capability detection
- `src/Probe/Capability.php` (69 lines) — Terminal capability enum
- `src/Probe/ProbeReport.php` (115 lines) — Capability detection results

**Tests (10 files):**
- `tests/ColorTest.php` — Color value object tests
- `tests/PaletteTest.php` — Palette detection and degradation tests
- `tests/ProfileTest.php` — Profile enum tests
- `tests/ProfileWriterTest.php` — ProfileWriter stream wrapper tests
- `tests/DegradeTest.php` — ANSI sequence degradation tests
- `tests/ProbeTest.php` — Probe utility tests
- `tests/ProbeInfocmpTest.php` — infocmp-based detection tests
- `tests/CoverageBoostTest.php` — Coverage improvement tests
- `tests/Probe/ProbeReportTest.php` — ProbeReport value object tests
- `tests/Probe/TerminalProbeTest.php` — TerminalProbe integration tests

**Dependencies:** `sugarcraft/candy-core`, `sugarcraft/candy-sprinkles` (both dev-master path repos)