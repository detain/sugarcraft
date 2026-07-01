---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-palette Code Review Findings

## Goal
Address all 24 code review findings in candy-palette, consolidating duplicate enums, eliminating detection logic duplication, improving static state management, adding missing tooling configs, and filling architectural gaps.

## Context & Decisions
| Decision | Rationale | Source |
|----------|-----------|--------|
| Keep Profile and ColorProfile as separate enums | Profile is richer (degradedTo, maxColors, description) used by Palette/Color/ProfileWriter. ColorProfile is SSOT for Probe/TerminalProbe, used by consumer libs | ref:exploration |
| Accept ANSI256 vs Ansi256 naming difference | Profile uses UPPER_SNAKE (ANSI256), ColorProfile uses PascalCase (Ansi256) — intentional separation for different consumers. Normalizing would break existing consumer code | ref:exploration |
| Extract shared detection logic to DetectionChain class | DRY violation across Palette, Probe, and TerminalProbe is high priority | ref:exploration |
| Use lazy initialization for StandardColors | Static initialization outside class violates PSR-12 expectations | ref:exploration |
| Consider caching service for Probe static state | Mutable static state persists across requests | ref:exploration |
| Add async support via ReactPHP ChildProcess | Library is entirely synchronous despite ReactPHP ecosystem | ref:exploration |
| Finding #23 (redundant null checks) no action | The `!== ''` checks are defensive and harmless; removing them would be cosmetic with no functional benefit. Deferred | ref:exploration |

## Phase 1: High-Priority Critical Issues [PENDING]

- [ ] 1.1 Add allowsColor() method to Profile enum — Add allowsColor(): bool method returning $this !== self::NoTTY at src/Profile.php after degradedTo(). Medium severity. Verify: Profile::NoTTY->allowsColor() returns false.

- [ ] 1.2 Add degradedTo() method to ColorProfile enum — Add degradation chain method matching Profile::degradedTo() logic at src/ColorProfile.php. Medium severity. Verify: ColorProfile::TrueColor->degradedTo() returns ColorProfile::Ansi256.

- [ ] 1.3 Add maxColors() method to ColorProfile enum — Add maxColors(): int method returning 16_777_216/256/16/2/0 at src/ColorProfile.php. Low severity. Verify: ColorProfile::ANSI256->maxColors() returns 256.

- [ ] 1.4 Add fromProfile() bridge to ColorProfile — Add public static function fromProfile(Profile $p): self at src/ColorProfile.php. Medium severity. Verify: ColorProfile::fromProfile(Profile::ANSI256) returns ColorProfile::Ansi256.

- [ ] 1.5 Create src/DetectionChain.php shared detection class — Extract TERM patterns, priority order constants, and helper methods into a shared class with no dependencies on other candy-palette classes. Critical severity. Verify: Used by Palette, Probe, and TerminalProbe.

- [ ] 1.6 Refactor Palette::detectProfile() to use DetectionChain — Replace inline detection logic with DetectionChain helper calls at src/Palette.php:193-295. Critical severity. Verify: Existing tests pass; detection priority order matches documented 14-step precedence at Palette.php:174-192.

- [ ] 1.7 Refactor Probe::colorProfile() to use DetectionChain — Replace helper methods and inline detection with DetectionChain calls at src/Probe.php:31-94. Critical severity. Verify: Existing tests pass; infocmp phase 2 upgrade still works; priority order matches documented precedence at Probe.php:10-22.

- [ ] 1.8 Refactor TerminalProbe::checkEnvVars() to use DetectionChain — Replace term pattern matchers and env checks with DetectionChain calls at src/Probe/TerminalProbe.php:103-185. Critical severity. Verify: Existing tests pass; TerminalProbe still returns array; priority order matches documented 12-step precedence at TerminalProbe.php:10-22.

- [ ] 1.9 Move StandardColors static initialization inside class — Convert initialization at src/StandardColors.php:93-109 to lazy initialization inside class body. High severity. Verify: StandardColors::$red->r returns 0xcd; all 16 colors accessible.

- [ ] 1.10 Address mutable static state in Probe class — Convert Probe::$infocmpPath (line 229) to use caching service or injectable dependency. High severity. Verify: Existing tests pass; Probe::colorProfile() still caches infocmp path.

- [ ] 1.11 Cache StandardColors::all() result — Add static caching to all() method at src/StandardColors.php:46-66. Medium severity. Verify: StandardColors::all() returns same array instance on repeated calls.

- [ ] 1.12 Create phpstan.neon configuration — Add PHPStan config for candy-palette (level 5+). Medium severity. Verify: vendor/bin/phpstan analyse runs without errors.

- [ ] 1.13 Create .php-cs-fixer.dist.php configuration — Add php-cs-fixer config matching project conventions. Low severity. Verify: vendor/bin/php-cs-fixer fix --dry-run shows no issues.

## Phase 2: Medium-Priority Logic & Quality Issues [PENDING]

- [ ] 2.1 Add FORCE_COLOR support to Probe::colorProfile() — Palette supports FORCE_COLOR at src/Palette.php:203-212 but Probe does not. High severity. Verify: Probe::colorProfile() returns ColorProfile::Ascii when FORCE_COLOR=0.

- [ ] 2.2 Add TERM_PROGRAM check to Probe::colorProfile() — Palette checks TERM_PROGRAM=iTerm.app at line 231-233 but Probe does not. Medium severity. Verify: Probe returns TrueColor when TERM_PROGRAM=iTerm.app.

- [ ] 2.3 Fix inconsistent env access in Palette::detectProfile() — Standardize on env parameter with consistent fallback pattern; mixed usage of $env, $_ENV, and getenv() is confusing. Medium severity. Verify: All env accesses use consistent pattern.

- [ ] 2.4 Extract ANSI256 RGB formula to shared helper — Create Color::ansi256IndexToRgb(int $idx) to eliminate duplicate formulas in toAnsi256() (lines 240-243) and fromAnsi256Index() (lines 214-216). Low severity. Verify: Existing conversion tests pass.

- [ ] 2.5 Make Color::closestAnsi16() palette a class constant — Convert static palette at lines 312-321 to class constant ANSI16_BASIC_PALETTE. Low severity. Verify: Existing color conversion tests pass.

- [ ] 2.6 Optimize Color::perceivedBrightness() — Replace $this->r ** 2 with $this->r * $this->r. Low severity. Verify: Existing brightness-related tests pass.

- [ ] 2.7 Cache Palette object in ProfileWriter::write() — Create Palette once in constructor for non-TrueColor/NonTTY profiles, reuse in write() to avoid repeated instantiation at src/ProfileWriter.php:104-114. Medium severity. Verify: ProfileWriter produces identical output.

- [ ] 2.8 Fix redundant getenv() check in TerminalProbe constructor — Simplify array_merge($_ENV, getenv() ?: [], $env) at src/Probe/TerminalProbe.php:51 to remove redundant ?: []. Low severity. Verify: All tests pass; env override behavior unchanged.

## Phase 3: Missing Features / Architectural Gaps [PENDING]

- [ ] 3.1 Add async terminal probe using ReactPHP ChildProcess — Create AsyncProbe class that uses ReactPHP ChildProcess to run infocmp non-blockingly. Medium severity. Verify: AsyncProbe::colorProfile() returns Promise<ColorProfile>; falls back to sync Probe if loop not available.

- [ ] 3.2 Add caching service for terminal probe results — Create ProbeCache service that caches detection results with optional TTL. Medium severity. Verify: Repeated calls return cached result; cache can be invalidated.

- [ ] 3.3 Add toProfile() method to ProbeReport — Add public function toProfile(): Profile to map detected capabilities to Profile enum. Medium severity. Verify: ProbeReport with Capability::TrueColor maps to Profile::TrueColor.

- [ ] 3.4 Document or implement DA1/DA2 escape query parsing — Either implement actual DA1 query/response parsing in TerminalProbe, or document that checkEscapeQueries() only reads TERM_PROGRAM hints. Low severity. Verify: If implemented, interactive terminals can be queried.

## Phase 4: Testing Improvements [PENDING]

- [ ] 4.1 Add malformed ANSI sequence tests for rewriteAnsi() — Add tests for malformed SGR sequences, incomplete escape sequences, mixed format sequences. Medium severity. Verify: New tests pass or gracefully degrade.

- [ ] 4.2 Fix testConvertToAnsiIsReducedPalette assertion — Make the fragile assertion at tests/ColorTest.php:128-133 more precise. Low severity. Verify: New test is more precise and passes.

- [ ] 4.3 Add tests for Capability-to-Profile bridge — Test ProbeReport::toProfile() once implemented. Medium severity. Verify: New tests pass when toProfile() is implemented.

## Phase 5: Dependency & Integration Improvements [PENDING]

- [ ] 5.1 Inline the single Lang::t() call in StandardColors — Replace StandardColors::fromIndex() error message at line 77 with inline string to remove candy-core dependency. Low severity. Verify: Error message displays correctly without Lang dependency.

- [ ] 5.2 Document CLI integration pattern in README — Add usage examples showing how CLI applications should integrate. Low severity. Verify: README clearly shows integration patterns.

## Notes

- 2026-06-30: Investigation complete — 24 findings across critical (6), logic (4), architectural (3), missing features (4), code quality (4), and minor (3) categories.
- Profile and ColorProfile are intentionally separate enums; bridge methods needed both directions.
- Profile already has methods: `label()`, `description()`, `maxColors()`, `degradedTo()`, `fromColorProfile()`. Phase 1 adds these to ColorProfile plus new `fromProfile()` bridge.
- ANSI256 vs Ansi256 naming difference is intentional (UPPER_SNAKE vs PascalCase) to serve different consumers; do NOT normalize.
- Detection logic triplication is the most urgent refactoring — all three implementations must stay in sync.
- Static state issues (Probe::$infocmpPath, StandardColors initialization) are high-priority fixes.
- No PHPStan or php-cs-fixer config exists.
- Library is entirely synchronous; async support via ReactPHP ChildProcess would benefit the ecosystem.
- Finding #23 (redundant null checks) explicitly deferred — defensive checks are harmless, removal would be cosmetic.

## Finding-to-Task Mapping

| Finding # | Finding Description | Task(s) |
|-----------|---------------------|---------|
| 1 | Duplicate Profile/ColorProfile enums | 1.1, 1.2, 1.3, 1.4 |
| 2 | Massively duplicated detection logic | 1.5, 1.6, 1.7, 1.8 |
| 3 | Static state in StandardColors | 1.9, 1.11 |
| 4 | Static mutable state in Probe | 1.10 |
| 5 | stripAnsi regex complexity | (Low risk, documented) |
| 6 | shell_exec security/async issues | 3.1 |
| 7 | Palette::detectProfile env access inconsistency | 2.3 |
| 8 | Color::closestAnsi16 static palette | 2.5 |
| 9 | Color::toAnsi256 duplicate round-trip logic | 2.4 |
| 10 | TerminalProbe constructor env merging | 2.8 |
| 11 | No PHPStan config | 1.12 |
| 12 | No php-cs-fixer config | 1.13 |
| 13 | Heavy dependency footprint | 5.1 |
| 14 | No async/terminal probe integration | 3.1 |
| 15 | No built-in caching for probe results | 3.2 |
| 16 | No clear CLI tool integration pattern | 5.2 |
| 17 | Escape query implementation incomplete | 3.4 |
| 18 | ProfileWriter creates new Palette on every write | 2.7 |
| 19 | Missing test for rewriteAnsi with invalid sequences | 4.1 |
| 20 | Capability enum has no bridge to Profile | 3.3, 4.3 |
| 21 | Color::perceivedBrightness slow power operation | 2.6 |
| 22 | Lang facade dependency chain | 5.1 |
| 23 | Redundant null checks | Deferred — defensive checks are harmless; removal would be cosmetic with no functional benefit |
| 24 | testConvertToAnsiIsReducedPalette fragile assertion | 4.2 |
| 25 (Async) | Completely synchronous library | 3.1 |
