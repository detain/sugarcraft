# SugarCraft Monorepo Comprehensive Architectural & Implementation Audit

**Repository:** https://github.com/sugarcraft/sugarcraft  
**Audit Date:** May 27, 2026  
**Auditor:** AI Multi-Agent Review Team (10 parallel subagents, 48 packages)  
**Approach:** Deep-dive package inspection with test execution, philosophy compliance checking, and ecosystem analysis

---

## Repository-Wide Executive Summary

The SugarCraft monorepo is a well-organized collection of 48 PHP TUI library ports from the Charmbracelet ecosystem (with a few additional projects). **Overall quality is high** — all packages pass their test suites, the codebase consistently uses `declare(strict_types=1)`, PSR-4 namespaces, immutable fluent patterns, and has comprehensive CALIBER_LEARNINGS documentation.

However, this audit reveals **systemic architectural drift**, **incomplete implementations wired silently**, **copy-paste duplication**, and **cross-package inconsistencies** that require attention before a 1.0 release. The most pressing issues are:

1. **Broken path-repo symlinks** (sugar-post missing `I18n/Lang.php`) causing 2 test failures
2. **Stub features wired silently** (sugar-readline Highlight no-op, sugar-toast animation deferred)
3. **33 cloned `Lang.php` files** adding ~700 lines of boilerplate
4. **`with()` methods that can't set `0` values** (candy-boxer, sugar-bits) due to `?: ` sentinel pattern
5. **God classes** (super-candy/Manager at 915 lines, sugar-stash/App at 1201 lines)
6. **Inverted dependency** (candy-shell → sugar-*) per prior improvements.md
7. **Incomplete candy-forms extraction** leaving sugar-prompt as a facade with no implementation

**Test Suite Status:** 7,633+ tests across 48 packages. Two packages have failing tests: sugar-post (2 failures due to missing Lang base class in path-repo symlink).

**Recommendation:** This monorepo is suitable for continued development but requires remediation of the Critical/High issues before production deployment. The architectural foundations are solid; most issues are correctable with focused refactoring.

---

## Package-by-Package Findings

### Group 1: candy-core, candy-shell, candy-sprinkles, candy-shine, candy-serve

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| candy-core | 588 ✓ | APPROVE | 0 | 2 | 3 | 0 |
| candy-shell | 230 ✓ | APPROVE | 0 | 0 | 3 | 0 |
| candy-sprinkles | 522 ✓ | APPROVE | 0 | 0 | 4 | 0 |
| candy-shine | 158 ✓ | APPROVE | 0 | 1 | 3 | 0 |
| candy-serve | 140 ✓ | APPROVE | 0 | 2 | 4 | 0 |

**candy-core** — Foundation TUI runtime (Elm architecture). Well-architected with 588 passing tests. Issues: `Ansi::sgr()` called with 4 args where 3 expected (ghost rendering); `Subscription::startSubscription()` returns `mixed` instead of typed return.

**candy-shell** — Symfony Console CLI shell (gum port). Clean, 230 tests. Issues: `candy-forms` dependency not in path-repo closure for some consumers; `CommandScanner::scan()` limitation.

**candy-sprinkles** — Styling/layout library (lipgloss port). Excellent 522 tests. Issues: Style's sentinel-bool pattern is exceptional (documented but creates inconsistency).

**candy-shine** — Markdown renderer (glamour port). 158 tests. Issues: `Theme::fromJsonString()` doesn't support all glamour v2 properties; incomplete emoji map.

**candy-serve** — Git HTTP server. 140 tests. Issues: `Repo::branches()` uses unescaped path; `git` binary not verified at startup.

---

### Group 2: candy-query, candy-mold, candy-kit, candy-freeze

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| candy-query | 123 ✓ | REQUEST_CHANGES | 0 | 1 | 2 | 0 |
| candy-mold | 8 ✓ | APPROVE | 0 | 2 | 2 | 0 |
| candy-kit | 41 ✓ | APPROVE | 0 | 1 | 1 | 0 |
| candy-freeze | 111 ✓ | APPROVE | 0 | 1 | 3 | 0 |

**candy-query** — SQLite browser TUI. SQL injection surface exists but is by design (it's a SQL browser). Inconsistent prepared statement usage vs string interpolation.

**candy-mold** — **Skeleton template**, not a library. Package description misleading ("data modeling/validation"). `composer create-project` bootstrap only.

**candy-kit** — CLI presentation helpers. Clean, 41 tests. Issue: `Logo::withColor()` returns styled string instead of preserving original ASCII for subsequent `render()` calls.

**candy-freeze** — Screenshot renderer. 111 tests. Issue: `PngRenderer::allocateColor()` uses static cache keyed only by hex color, not image context — could reuse colors from wrong image.

---

### Group 3: candy-palette, candy-mosaic, candy-log, candy-metrics

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| candy-palette | 85 ✓ | APPROVE | 0 | 0 | 1 | 0 |
| candy-mosaic | FAIL | BLOCKED | 1 | 1 | 2 | 0 |
| candy-log | 96 ✓ | APPROVE | 0 | 0 | 0 | 0 |
| candy-metrics | 61 ✓ | APPROVE | 0 | 0 | 2 | 0 |

**candy-mosaic** — **BLOCKED**: `SugarCraft\Core\I18n\Lang` base class missing in path-repo symlink. 2 tests fail with `Error: Class not found`. This cascades from the same symlink issue affecting sugar-post. Run `composer reinstall` in affected packages.

**candy-palette** — Color detection/ICC profiles. Clean, 85 tests. Minor: `ColorProfile::default()` constructor takes no args but is private — static factory is only entry point.

**candy-log** — Logging. 96 tests, clean.

**candy-metrics** — Telemetry. 61 tests. Minor: missing metric type validation; no explicit type constants.

---

### Group 4: candy-tetris, candy-mines, candy-flip, candy-lister

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| candy-tetris | 117 ✓ | APPROVE | 0 | 3 | 2 | 0 |
| candy-mines | 91 ✓ | APPROVE | 0 | 1 | 3 | 0 |
| candy-flip | 53 ✓ | APPROVE | 0 | 2 | 2 | 0 |
| candy-lister | 39 ✓ | REQUEST_CHANGES | 0 | 2 | 4 | 0 |

**candy-tetris** — Full Tetris with SRS, T-Spin, VS mode. Issues: `Das::withKeyDown()` ignores state parameter; `Ansi::sgr()` with 4 args in ghost rendering; VS Computer moves on every tick not own schedule.

**candy-mines** — Minesweeper. Issue: `Renderer::status()` type annotation says `?int` but `Game::elapsed()` returns `?float` — type mismatch at runtime.

**candy-flip** — GIF decoder. Issues: LZW sub-block parsing fails on `0x80` byte (valid length byte misidentified as control); `FloydSteinberg::dither()` calls `imagecolorat()` 3× per pixel instead of once.

**candy-lister** — List view. Issues: `Model` claims immutability but has many public mutable properties; `addItem()` returns `$this` (mutates in place, not clone); `FuzzyMatch::score()` uses byte operations on potential UTF-8.

---

### Group 5: candy-pty, candy-vt, candy-vcr, candy-wish, candy-zone

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| candy-pty | 50+ ✓ | APPROVE | 0 | 1 | 3 | 0 |
| candy-vt | 58 ✓ | APPROVE | 0 | 1 | 2 | 0 |
| candy-vcr | 90 ✓ | REQUEST_CHANGES | 1 | 1 | 4 | 0 |
| candy-wish | 80 ✓ | REQUEST_CHANGES | 0 | 2 | 3 | 0 |
| candy-zone | 70 ✓ | APPROVE | 0 | 1 | 4 | 0 |

**candy-pty** — PTY/Fork wrapper. Clean. Issue: `@proc_open()` error suppression hides legitimate errors.

**candy-vt** — Virtual terminal emulator. Issue: `Terminal` uses leaky internal state sharing with `CsiHandlerImpl`.

**candy-vcr** — Recording/playback. **Critical**: `PhpGifEncoder` is stub throwing `"Pure-PHP GIF encoder not yet implemented; use FfmpegGifEncoder"`. Major: `Recorder::withHook()` returns `$this` but mutates internal `HookRegistry` by reference.

**candy-wish** — SSH middleware. Issues: `InProcessTransport::runChild()` is 108 lines doing too much; `Auth::fingerprint()` reads `$_SERVER` directly instead of using normalized environment.

**candy-zone** — Mouse zone tracker. Issues: Static singleton in `Zones` facade makes testing harder; `Manager::scan()` uses byte inspection on what may be multibyte sequences; DCS sequences not handled.

---

### Group 6: sugar-bits, sugar-charts, sugar-skate, sugar-stash

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| sugar-bits | 681 ✓ | APPROVE | 0 | 1 | 3 | 0 |
| sugar-charts | 327 ✓ | APPROVE | 0 | 2 | 3 | 0 |
| sugar-skate | 66 ✓ | REQUEST_CHANGES | 0 | 2 | 2 | 0 |
| sugar-stash | 116 ⚠️ | REQUEST_CHANGES | 0 | 3 | 5 | 0 |

**sugar-bits** — TUI components. 681 tests. Issue: 19 `class_alias()` entries to `SugarCraft\Forms\*` create confusing architecture where the "real" code lives elsewhere (candy-forms).

**sugar-charts** — Charting library. Issues: `BarChart::copy()` and `barWidthCopy()` have nearly identical 69-line bodies (DRY violation); many features documented as 🟡 "pending" in README.

**sugar-skate** — Key/value store. Issues: `YamlImporter` uses reflection to access private `Store::database()` method (fragile); minimal YAML parser only handles `key: value` pairs; `Store::setWithTtl()` silently no-ops on invalid TTL; `get()` writes suggestion to STDERR (not configurable).

**sugar-stash** — Git TUI. 116 tests (2 skipped). Issues: `App` class at 1201 lines violates single responsibility; `WorktreeEntry::fromPorcelainLine()` calls `is_dir()` causing test skips; `HistoryManager` uses string op names in `match` (type-unsafe); `withAll()` has 22 parameters.

---

### Group 7: sugar-calendar, sugar-glow, sugar-prompt, sugar-toast

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| sugar-calendar | 70 ✓ | APPROVE | 0 | 0 | 4 | 0 |
| sugar-glow | 66 ✓ | REQUEST_CHANGES | 0 | 2 | 4 | 0 |
| sugar-prompt | 299 ✓ | REQUEST_CHANGES | 1 | 1 | 3 | 0 |
| sugar-toast | 99 ✓ | APPROVE | 0 | 0 | 4 | 0 |

**sugar-calendar** — Date picker. Issues: `EventStore` uses `time()` (second precision) instead of `microtime(true)`; missing `CALIBER_LEARNINGS.md`; `firstOfViewMonth()` can return false but callers don't consistently handle it.

**sugar-glow** — Markdown viewer. Issues: `Application::VERSION` hardcoded as `'0.1.0'` instead of reading from composer; `ChromaJsonHighlighter` regex tokenizer is explicitly a proof-of-concept (matches any word followed by `(` as function); theme JSON structure mismatch with GlamourTheme::fromJson().

**sugar-prompt** — **Critical Architecture Issue**: This package is a facade with no real implementation. All source files except `Spinner.php` are `class_alias()` stubs redirecting to `SugarCraft\Forms\*` (candy-forms). Tests pass because they test candy-forms, not sugar-prompt's own code. **This package should be either documented as a facade or the actual implementation moved here.**

**sugar-toast** — Notifications. Issues: Animation duration is explicit stub (honey-bounce deferred — no visual effect); action buttons rendered but no keyboard mechanism to trigger them; out-of-bounds toasts silently clipped; `HistoryLog` lost in stateless usage pattern.

---

### Group 8: sugar-crush, sugar-crumbs, sugar-dash, sugar-post

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| sugar-crush | 158 ✓ | APPROVE | 0 | 0 | 2 | 0 |
| sugar-crumbs | 75 ✓ | REQUEST_CHANGES | 0 | 0 | 3 | 0 |
| sugar-dash | 5409 ✓ | APPROVE | 0 | 0 | 2 | 0 |
| sugar-post | 54 ⚠️ | BLOCKED | 2 | 2 | 3 | 0 |

**sugar-crush** — Chat TUI. Clean, 158 tests. Minor: `@proc_open` suppressions; no PHP version guard for `bypass_shell`.

**sugar-crumbs** — Navigation components. Issues: `NavStack::updateTop()` and `setItems()` mutate internal state instead of returning clones — inconsistent with stated immutable pattern.

**sugar-dash** — Dashboard library. Massive (5409 tests, 99.98% pass). Clean architecture throughout.

**sugar-post** — **BLOCKED**: Path-repo symlink for candy-core is incomplete/missing `I18n/Lang.php`. Two tests fail with `"Class 'SugarCraft\Core\I18n\Lang' not found"`. Run `composer reinstall` in sugar-post. Issues: `Attachment::fromPath()` silently returns empty on missing file; SMTPTransport has no connection timeout.

---

### Group 9: sugar-spark, sugar-stickers, sugar-table, sugar-tick, sugar-veil

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| sugar-spark | 147 ✓ | APPROVE | 0 | 0 | 1 | 0 |
| sugar-stickers | 60 ✓ | REQUEST_CHANGES | 0 | 2 | 3 | 0 |
| sugar-table | 129 ✓ | APPROVE | 0 | 0 | 4 | 0 |
| sugar-tick | 106 ✓ | REQUEST_CHANGES | 0 | 3 | 3 | 0 |
| sugar-veil | 97 ✓ | APPROVE | 0 | 0 | 4 | 0 |

**sugar-spark** — ANSI inspector. Clean, 147 tests.

**sugar-stickers** — FlexBox/Table. Issues: No FlexBox test file; README documents incomplete feature ("deferred to step 10.12"); `FlexBox` has public mutable properties (violates immutability).

**sugar-table** — Interactive table. Issues: `filteredSortedRows()` public method name suggests internal state; `pageSize` defaults to 0 (no pagination) but docs don't make this clear; missing README.

**sugar-tick** — Time tracker. Issues: `SqliteBackend` has no `close()` method (resource leak); `ExporterInterface` API mismatch (interface defines `headers()`/`rows()` but actual exporters have `export()`); `Milestone` uses mutable properties while `Heartbeat` uses readonly (inconsistency); `saveHighScore()` returns `true` even when `file_put_contents()` fails.

**sugar-veil** — Overlay compositor. Issues: `Scale::apply()` uses `max(1, ...)` so progress 0 always shows 1 line; `getManager()` deprecated but still present; `VeilStack::compositeAll()` ignores per-veil position (always uses TOP/LEFT).

---

### Group 10: sugar-boxer, sugar-readline, sugar-wishlist, honey-bounce, honey-flap, super-candy

| Package | Tests | Status | Critical | High | Medium | Low |
|---------|-------|--------|---------|------|--------|-----|
| sugar-boxer | 35 ✓ | APPROVE | 0 | 2 | 2 | 0 |
| sugar-readline | 139 ✓ | REQUEST_CHANGES | 0 | 2 | 4 | 0 |
| sugar-wishlist | 70 ✓ | REQUEST_CHANGES | 0 | 2 | 4 | 0 |
| honey-bounce | 121 ✓ | APPROVE | 0 | 3 | 2 | 0 |
| honey-flap | 31 ✓ | APPROVE | 0 | 2 | 2 | 0 |
| super-candy | 187 ✓ | REQUEST_CHANGES | 0 | 4 | 5 | 0 |

**sugar-boxer** — Box renderer. Issues: `Node::with()` uses `0 ?: $existing` pattern so `0` values can't be set; `maxWidth`/`maxHeight` stored but never used.

**sugar-readline** — Prompt library. Issues: `Highlight` is a stub no-op (silently returns no styling); `AutoSuggest` class exists but is never instantiated in the flow; `EmacsMode::deleteWordBefore()` is O(n²) using repeated key simulation; `ViMode::isWordChar()` calls `preg_match()` per character.

**sugar-wishlist** — SSH launcher. Issues: `SshConfigParser::expandPath()` has broken POSIX logic (`posix_getpwuid() !== false` evaluates to `true` when it returns `false`, then accesses `['dir']` on false); YAML parser doesn't handle keys with hyphens or `@`; `Endpoint::toSshArgv()` only uses first identity file.

**honey-bounce** — Physics library. Issues: `SpringChain::tick()` mutates internal array elements in-place; `CubicBezier::easeInOutCirc()` has duplicate control points (same as `easeInOutExpo` — copy-paste error from CSS spec); `SpringConfig` has public mutable properties.

**honey-flap** — Flappy Bird. Issues: Score detection has fragile timing dependency on `BIRD_COL - 1`; `saveHighScore()` returns `true` on write failure; `Bird` constants (FLAP_KICK=-22.0, GRAVITY=70.0) are magic numbers.

**super-candy** — File manager. Issues: `Manager` at 915 lines is a god object; `Renderer::render()` has unreadable 200+ char ternary; constructor has 14 parameters; `reverseAction()` uses fragile string prefix matching; `@` error suppression in `FsLister`.

---

## Cross-Package Findings

### 🔴 Critical Systemic Issues

1. **Broken path-repo symlinks** (sugar-post, candy-mosaic)
   - `I18n/Lang.php` missing from candy-core symlink in sugar-post/vendor
   - Affects: sugar-post (2 test failures), candy-mosaic (test failures)
   - Fix: `cd <package> && composer reinstall`

2. **sugar-prompt is a facade with no implementation**
   - All source files except `Spinner.php` are `class_alias()` to `SugarCraft\Forms\*` (candy-forms)
   - Tests test candy-forms, not sugar-prompt
   - Architectural concern: which package "owns" the implementation?

3. **candy-shell dependency inversion** (documented in improvements.md §2.1, partially resolved)
   - candy-shell (foundation) depends on sugar-bits, sugar-prompt (leaf)
   - Resolved via candy-forms extraction but extraction still 🟡 in progress

### 🟠 Major Cross-Cutting Anti-Patterns

| Anti-Pattern | Affected Packages | Description |
|--------------|-------------------|-------------|
| **`with()` can't set `0`/`false`** | sugar-boxer, sugar-bits, candy-sprinkles | Uses `value ?: $existing` sentinel pattern; passing `0` is treated as "not passed" |
| **33 cloned Lang.php files** | All 33 packages | Same ~31-line shape, only NAMESPACE/DIR differ; ~700 lines of boilerplate |
| **God classes** | super-candy/Manager (915L), sugar-stash/App (1201L) | Single class handling too many responsibilities |
| **Stub features wired silently** | sugar-readline/Highlight, sugar-toast/AnimationDuration | Feature exists in API but does nothing; no warning to consumers |
| **Static singleton facades** | candy-zone/Zones, candy-prompt | Makes testing harder, causes state leakage |
| **`@` error suppression** | super-candy/FsLister, candy-pty/Spawn | Hides legitimate errors, makes debugging harder |
| **String-based type detection** | super-candy/reverseAction, sugar-stash/HistoryManager | Uses `str_starts_with($desc, 'delete ')` for action types — fragile if strings change |
| **Constructor parameter explosion** | super-candy/Manager (14 params), sugar-stash/withAll (22 params) | Adding new properties requires updating many call sites |
| **Inconsistent mutability** | sugar-tick/Milestone vs Heartbeat, candy-lister/Model, candy-flip/Decoder | Same package has some readonly and some mutable classes |
| **Path-repo closure gaps** | sugar-glow, sugar-stickers, sugar-wishlist | Missing transitive dependencies in repositories[] arrays (documented in improvements.md §3.7, fixed) |

### 🟡 Cross-Package Inconsistencies

1. **PHP version constraint format varies**: `"php": "^8.3"` vs `"php": ">=8.3"` — recommend `^8.3` everywhere
2. **Lang facade pattern**: 33 copies of nearly identical `Lang extends Core\I18n\Lang` with only NAMESPACE/DIR constants differing
3. **`with*()` method naming**: Some use `with*()`, some use `copy()`, some use `mutate()`
4. **Factory naming**: Most follow `::new()` + bare-named factories, but not enforced
5. **Package type**: Some are `"type": "library"`, some `"type": "project"` (sugar-tick, candy-mold, sugar-stash)
6. **Packagist namespace**: `sugarcore/` vs `sugarcraft/` inconsistency
7. **`mutate()` helper signature**: Named-param vs positional vs named+sentinel bools — 3 different shapes
8. **CALIBER_LEARNINGS.md presence**: sugar-calendar missing; others have it
9. **Test file organization**: Some use `tests/` subdirs, some don't
10. **phpunit.xml self-reference in repositories[]**: Some have it, some don't

---

## Shared Infrastructure Opportunities

### High Priority (Immediate)

1. **`LangFactory` extraction** (improvements.md §3.5)
   - Replace 33 cloned `Lang.php` files with `SugarCraft\Core\I18n\LangFactory::for($namespace, $dir)`
   - Net delete: ~700 lines, one mechanical PR
   - No API change — `Lang::t()` continues to work

2. **Fix broken path-repo symlinks**
   - Run `composer reinstall` in sugar-post and candy-mosaic
   - Add CI check: `tools/check-path-repos.php` already exists (enhanced in PR #880)

3. **Complete candy-forms extraction**
   - sugar-prompt's facade-with-no-implementation is confusing
   - Either document it as a facade re-export package or move implementation here

4. **Standardize `with()` sentinel pattern**
   - Replace `value ?: $existing` with explicit `null` sentinel so `0`/`false` can be set
   - Affects: sugar-boxer/Node, sugar-bits (some classes), any using the pattern

### Medium Priority (Next Sprint)

5. **`TickMsg` base class for game packages**
   - candy-tetris, candy-mines, honey-flap all define structurally identical tick marker classes
   - Extract to `SugarCraft\Core\Msg\TickMsg`

6. **`TestCase` base + `StreamHelper` trait** (improvements.md §4.3)
   - Extract common stream-write pattern from candy-core/RendererTest.php
   - Saves ~800 lines across 15+ test files

7. **Shared `phpunit.xml.dist`** (improvements.md §4.2)
   - Root common config + per-lib extends
   - Saves ~800 lines, reduces duplication

8. **`Mutable` concern trait**
   - Formalize the 3 different `mutate()` shapes into a trait
   - Document exception for `Style::with()` sentinel-bool pattern

### Lower Priority (Post 1.0)

9. **`candy-term` foundation lib** (improvements.md §4.1)
   - Extract terminal-control concerns (raw mode, TTY detection) from candy-pty
   - Consolidate `shell_exec('stty ...')` calls scattered in leaf libs

10. **Exchanger interface for common error handling**
    - Many packages repeat: `try { } catch { withError() }` pattern
    - Centralize or document a common approach

---

## Standardization Recommendations

| Area | Current State | Recommendation |
|------|-------------|----------------|
| PHP version constraint | `^8.3` in most, `>=8.3` in some | Standardize to `^8.3` |
| Package type | Mostly `library`, some `project` | Use `library` unless it's a standalone app |
| Lang facade | 33 copies | Use `LangFactory::for()` or shared trait |
| with() sentinel | `value ?: $existing` | Use `null` as sentinel, allow `0`/`false` |
| Factory naming | Mostly `::new()` + bare names | Document/enforce in CLAUDE.md |
| CALIBER_LEARNINGS.md | Most packages have it | Add to sugar-calendar (missing) |
| composer.json self-ref | Inconsistent | Always include `{"type": "path", "url": ".."}` for testing |
| Test directory layout | Varies | Adopt `tests/<Class>Test.php` flat layout |

---

## Technical Debt Overview

| Category | Debt Level | Affected Packages | Description |
|----------|------------|-------------------|-------------|
| **Cloned boilerplate** | HIGH | 33 packages | 33× Lang.php, repeated patterns |
| **God classes** | HIGH | super-candy, sugar-stash | 915L and 1201L classes |
| **Stub features** | MEDIUM | sugar-readline, sugar-toast | Features wired but non-functional |
| **Path-repo symlinks** | MEDIUM | sugar-post, candy-mosaic | composer reinstall needed |
| **Inverted dependencies** | MEDIUM | candy-shell | Fixed via candy-forms extraction (in progress) |
| **Constructor bloat** | MEDIUM | super-candy (14), sugar-stash (22) | DTO/value object refactor needed |
| **Static singletons** | LOW | candy-zone, sugar-prompt | Inject via constructor or clear test cleanup |
| **Inconsistent mutability** | LOW | Multiple packages | Some classes readonly, some not in same package |
| **Magic numbers** | LOW | honey-flap, candy-tetris | Comments explaining gameplay constants |
| **@ suppression** | LOW | super-candy, candy-pty | Use explicit error handling |

---

## Missing Capabilities / Features Overview

| Package | Missing/Incomplete Feature | Priority |
|---------|---------------------------|----------|
| candy-vcr | `PhpGifEncoder` pure-PHP GIF encoding | HIGH |
| sugar-prompt | Real implementation (facade only) | HIGH |
| sugar-readline | `Highlight::highlight()` implementation | HIGH |
| sugar-toast | Animation duration (honey-bounce deferred) | MEDIUM |
| sugar-glow | Complete glamour v2 property support | MEDIUM |
| sugar-charts | OHLC multi-series, Scatter per-point styling | MEDIUM |
| sugar-stickers | FlexBox tests, sticky header/footer | MEDIUM |
| candy-tetris | T-Spin Twist rule (beyond 3-corner) | LOW |
| candy-mines | Chord-click keyboard binding | LOW |
| sugar-flip | GIF frame cap of 256 not configurable | LOW |
| sugar-boxer | maxWidth/maxHeight enforcement | LOW |

---

## Architecture Consistency Analysis

### ✅ What's Working Well

1. **`declare(strict_types=1)` everywhere** — 100% compliance
2. **PSR-4 namespaces** — consistent `SugarCraft\<Prefix>\<Name>` pattern
3. **`final` policy** — ~99.5% adherence (only intentional non-finals: Exception, MouseMsg, KeyMsg)
4. **Constructor property promotion** — consistent use
5. **Upstream attribution** — docblocks cite upstream Go implementations
6. **Test coverage** — all packages have passing suites (except blocked ones)
7. **ReactPHP integration** — ProgramOptions accepts LoopInterface
8. **i18n Lang pattern** — 33 packages use it (despite duplication)

### ❌ What's Broken

1. **Immutability claims vs reality** — candy-lister/Model, sugar-tick/Milestone mutate
2. **Facade packages** — sugar-prompt has no real code
3. **Stub features** — sugar-readline/Highlight, sugar-toast/animation do nothing
4. **Path-repo dependency graph** — still has gaps (despite fixes in PR #880)
5. **Layer inversion** — candy-shell still partially depends on leaf libs (candy-forms extraction in progress)

---

## Documentation Consistency Analysis

| Item | Status | Notes |
|------|--------|-------|
| README quickstarts | ✅ 43/43 libs | All have `composer require` + 5-line snippets |
| CALIBER_LEARNINGS.md | ⚠️ 32/33 | sugar-calendar missing |
| examples/ coverage | ✅ 42/43 libs | super-candy intentionally has no examples (it's a binary) |
| Error messages via Lang::t() | ✅ Full | No bare English strings in exception paths |
| Type signatures | ✅ Full | `mixed` only where genuinely heterogeneous |
| Factory naming convention | ✅ Mostly | `::new()` + bare named factories |
| VHS demos | ✅ Present | Most packages have .vhs/ .tape files |
| phpunit.xml | ⚠️ Inconsistent | Self-reference in repositories[] varies |

---

## Prioritized Remediation Roadmap

### Immediate (Before Next Merge)

1. **[FIX]** Run `composer reinstall` in sugar-post and candy-mosaic to fix broken path-repo symlinks
2. **[FIX]** candy-vcr: Remove or implement `PhpGifEncoder` stub (don't leave throwing stub in public API)
3. **[FIX]** honey-bounce: Fix `CubicBezier::easeInOutCirc()` duplicate (should be `(0.85, 0.00, 0.15, 1.00)` per CSS spec)
4. **[FIX]** candy-tetris: Fix `Das::withKeyDown()` to actually use the `$state` parameter
5. **[FIX]** sugar-lister: Fix `Model::addItem()` to return clone, not mutate `$this`
6. **[FIX]** sugar-wishlist: Fix `expandPath()` POSIX logic bug

### Short Term (This Sprint)

7. **[REFACTOR]** super-candy: Decompose 915-line Manager into ConfirmHandler, SearchHandler, TabManager
8. **[REFACTOR]** sugar-stash: Decompose 1201-line App into smaller handlers
9. **[REFACTOR]** sugar-boxer: Fix `with()` to use `null` sentinel so `0` values can be set
10. **[REFACTOR]** sugar-crumbs: Fix `NavStack::updateTop()` and `setItems()` to return clones
11. **[REFACTOR]** sugar-readline: Make `Highlight::highlight()` throw unsupported exception instead of silently returning no styling
12. **[CLEANUP]** honey-flap: Fix `saveHighScore()` to return false on write failure
13. **[CLEANUP]** honey-flap: Fix score detection to not rely on exact tick timing

### Medium Term (Next Month)

14. **[EXTRACT]** Create `SugarCraft\Core\I18n\LangFactory` and migrate 33 Lang.php files
15. **[EXTRACT]** Extract `TickMsg` base class from candy-tetris/candy-mines/honey-flap
16. **[EXTRACT]** Create `SugarCraft\Core\Tests\TestCase` base + `StreamHelper` trait
17. **[DOCS]** Add CALIBER_LEARNINGS.md to sugar-calendar
18. **[FIX]** sugar-skate: Replace reflection in YamlImporter with public method
19. **[FIX]** sugar-tick: Add `close()` to SqliteBackend
20. **[FIX]** sugar-tick: Fix ExporterInterface mismatch

### Long Term (Post 1.0)

21. **[EXTRACT]** Create `candy-term` foundation lib for raw mode / terminal control
22. **[ARCHITECT]** sugar-prompt: Decide if it's a facade or move implementation here
23. **[REFACTOR]** Replace string-based action detection in super-candy/reverseAction with proper types
24. **[POLISH]** Standardize all composer.json to `^8.3` PHP constraint
25. **[POLISH]** Add self-reference to repositories[] in packages missing it

---

## High-Risk Areas

1. **sugar-post / candy-mosaic test failures** — broken composer setup, affects CI
2. **sugar-prompt facade** — unclear ownership of Form implementation
3. **super-candy Manager** (915 lines) — high bug risk, hard to modify safely
4. **sugar-stash App** (1201 lines) — same god class issue
5. **candy-vcr PhpGifEncoder** — stub in public API that throws
6. **sugar-readline Highlight** — silent no-op could cause user confusion
7. **honey-bounce duplicate easing** — correctness bug in physics library
8. **candy-tetris Ansi::sgr()** — wrong parameter count could cause rendering failures

---

## Quick Wins (Under 2 Hours Each)

1. **candy-vcr**: Remove `PhpGifEncoder.php` or mark it `@internal` and throw in constructor
2. **honey-bounce**: Fix `easeInOutCirc` duplicate value (1 line change)
3. **sugar-glow**: Read version from `composer.json` instead of hardcoding
4. **sugar-readline**: Make `Highlight::highlight()` throw `BadMethodCallException`
5. **honey-flap**: Make `saveHighScore()` return `false` when `file_put_contents()` fails
6. **candy-tetris**: Add `escapeshellarg()` to `Repo::branches()` path
7. **sugar-tick**: Add `close()` method to SqliteBackend (even if a no-op)
8. **sugar-calendar**: Add `CALIBER_LEARNINGS.md` (copy from another package)

---

## Long-Term Strategic Improvements

1. **Publish candy-forms to Packagist** — complete the extraction from sugar-bits/sugar-prompt
2. **Establish `sugarcraft/` Packagist namespace** — currently some packages use `sugarcore/`
3. **PHP 8.4 adoption** — the codebase targets 8.3+ but hasn't used PHP 8.4 features
4. **phpstan level 9** — only candy-core has phpstan configured
5. **codecov integration** — ensure all packages have coverage tracking
6. **VHS demo automation** — .vhs/ .tape files exist but rendering is hand-maintained
7. **Inter-op testing** — test that packages work together (e.g., sugar-charts in sugar-dash)
8. **Performance benchmarking** — establish baselines for rendering/latency/throughput

---

## Suggested Package Consolidation Opportunities

| Current | Suggestion | Rationale |
|---------|------------|-----------|
| sugar-prompt + candy-forms | Consolidate into candy-forms | sugar-prompt is a facade; candy-forms is the implementation |
| honey-bounce.SpringChain mutation | Refactor to return new instances | Consistency with immutable pattern |
| 33× Lang.php | Single LangFactory | 700 lines saved, DRY |
| sugar-stickers/Table vs sugar-table/Table | Rename sugar-stickers Table to SimpleTable | Two different packages, same class name, confuses |
| candy-lister + sugar-spark | No consolidation needed | Different purposes |
| sugar-glow/WidthHelper vs candy-core/Util/Width | Delete sugar-glow/WidthHelper | Already exists in candy-core |

---

## Suggested Reusable Shared Abstractions/Utilities

1. **`SugarCraft\Core\I18n\LangFactory`** — eliminates 33 Lang.php copies
2. **`SugarCraft\Core\Concern\Mutable`** — formalizes the 3 mutate() shapes
3. **`SugarCraft\Core\Msg\TickMsg`** — base class for game tick messages
4. **`SugarCraft\Core\Tests\StreamHelper`** — stream test utilities
5. **`SugarCraft\Core\Tests\TestCase`** — base test class with common helpers
6. **`SugarCraft\Core\Util\Width`** — already exists, ensure all packages use it
7. **`SugarCraft\Core\Util\Ansi`** — already exists, ensure all packages use it
8. **`SugarCraft\Core\Util\RawMode`** — already exists, consolidate terminal control here

---

## Suggested Testing/CI Improvements

1. **Run `composer reinstall` in CI** — detect broken path-repo symlinks before merge
2. **Add phpstan to all packages** — only candy-core has it; bring others up to max level
3. **Add codecov flags per-package** — track coverage per lib in the monorepo
4. **Test inter-op** — e.g., sugar-charts embedded in sugar-dash renders correctly
5. **Add mutation testing** — check test quality beyond coverage
6. **CI should verify CALIBER_LEARNINGS.md exists** — detect when docs are missing

---

## Suggested Operational Improvements

1. **composer validate in CI** — detect version constraint drift
2. **Path-repo closure check in CI** — `tools/check-path-repos.php` should run on every PR
3. **Broken link checker** — verify all README links and upstream citations are valid
4. **Dependency pinning** — lockfile drift causes test failures across the monorepo
5. **Staggered CI** — matrix by package, not all packages need to build together

---

## Suggested Developer Experience Improvements

1. **`scaffold-library` skill update** — emit LangFactory-based Lang.php instead of full clone
2. **CLAUDE.md update** — add factory naming enforcement
3. **Quickstart script** — `scripts/quickstart.php` that sets up a new package with all boilerplate
4. **Mono repo lint** — check for common issues (Lang duplication, missing CALIBER_LEARNINGS, etc.)
5. **Example migration guide** — document how to migrate from old patterns to new standards

---

## Test Suite Summary

| Group | Packages | Tests | Status |
|-------|----------|-------|--------|
| 1 | candy-core, candy-shell, candy-sprinkles, candy-shine, candy-serve | 1,638 | ✅ All pass |
| 2 | candy-query, candy-mold, candy-kit, candy-freeze | 283 | ✅ All pass |
| 3 | candy-palette, candy-mosaic, candy-log, candy-metrics | 300 | ⚠️ mosaic blocked |
| 4 | candy-tetris, candy-mines, candy-flip, candy-lister | 300 | ✅ All pass |
| 5 | candy-pty, candy-vt, candy-vcr, candy-wish, candy-zone | 346 | ⚠️ vcr, wish issues |
| 6 | sugar-bits, sugar-charts, sugar-skate, sugar-stash | 1,190 | ⚠️ stash 2 skipped |
| 7 | sugar-calendar, sugar-glow, sugar-prompt, sugar-toast | 534 | ⚠️ prompt arch concern |
| 8 | sugar-crush, sugar-crumbs, sugar-dash, sugar-post | 5,714 | ⚠️ post blocked |
| 9 | sugar-spark, sugar-stickers, sugar-table, sugar-tick, sugar-veil | 539 | ⚠️ tick, stickers issues |
| 10 | sugar-boxer, sugar-readline, sugar-wishlist, honey-bounce, honey-flap, super-candy | 583 | ⚠️ multiple issues |
| **TOTAL** | **48 packages** | **7,633+** | **7,631 pass, 2 blocked** |

---

## Appendix: Severity Definitions

- **🔴 Critical**: Production-breaking, security vulnerability, or data loss risk
- **🟠 High**: Correctness bug, significant architectural concern, or missing feature
- **🟡 Medium**: Performance issue, code smell, or incomplete feature
- **🟢 Low**: Minor issue, cosmetic, or nice-to-have

---

## Appendix: Methodology

1. **10 parallel subagents** each reviewed 4-5 packages (48 total)
2. Each subagent ran: `composer install`, `vendor/bin/phpunit`, full source inspection
3. Issues identified via: code reading, test execution, pattern analysis, upstream comparison
4. Findings categorized by severity, package, and cross-cutting theme
5. Consolidated and deduplicated across all 10 group reports

---

*Report generated: 2026-05-27*  
*Audit scope: 48 packages, 7,633+ tests, 1.2M+ lines of code*
