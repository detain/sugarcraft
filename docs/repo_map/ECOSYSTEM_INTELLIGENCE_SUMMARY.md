# SugarCraft Ecosystem Intelligence Summary

> Aggregated from 50 second-stage ecosystem intelligence reports across all mapped TUI/CLI repositories.
> Generated: 2026-05-27

---

## Executive Summary

This document synthesizes the most important findings from second-stage analysis of 50 upstream repositories. It aggregates recurring patterns, strategic opportunities, and defensive lessons across the entire TUI ecosystem.

**The most important insight:** The TUI ecosystem is fragmented but converging. Charmbracelet's Bubble Tea ecosystem dominates Go, Ratatui leads Rust, Textual leads Python, and php-tui exists as the PHP answer. The key opportunities for SugarCraft are:

1. **Fill gaps where Go/Rust solutions don't apply to PHP** — async integration, ReactPHP event loop, PHP-native patterns
2. **Learn from sunset projects** — mods, crush, charm, mods were all sunset in March 2026, revealing AI CLI volatility
3. **Address recurring pain points upstream won't fix** — Unicode width, escape sequence termination, terminal capability detection
4. **Build composable primitives** — upstream libs are too coupled; SugarCraft can win with better composition

---

## Most Recurring Pain Points Across Ecosystems

### 1. Unicode Width Calculation (🔴 CRITICAL - Appears in 8+ repos)
- **Repos affected:** lipgloss, bubbles, ultraviolet, php-tui, phpschool/cli-menu, sequin, go-termimg, pterm
- **Problem:** Using `mb_strlen` instead of `mb_strwidth` causes CJK/emoji to corrupt layouts
- **SugarCraft risk:** HIGH — directly affects `candy-shine`, `candy-sprinkles`, `sugar-bits`
- **Fix required:** Use `mb_strwidth()` or grapheme cluster counting everywhere

### 2. Escape Sequence Termination (🔴 CRITICAL - Appears in 6+ repos)
- **Repos affected:** ultraviolet, sequin, go-termimg, bubblezone, phpschool/cli-menu
- **Problem:** Missing OSC/DCS terminator (`\x1b\\`) causes sequence corruption on some terminals
- **SugarCraft risk:** HIGH — affects `candy-core` input parsing, `candy-shine` rendering
- **Fix required:** Always terminate OSC/DCS sequences with `\x1b\\` or `\x9c`

### 3. Terminal Capability Query Races (🔴 CRITICAL - Appears in 7+ repos)
- **Repos affected:** bubbletea, ultraviolet, glow, glamour, colorprofile, whisp, go-termimg
- **Problem:** Async capability queries return after process exit, corrupting state
- **SugarCraft risk:** HIGH — affects `candy-core` initialization
- **Fix required:** Synchronous fallback or query-with-timeout + ignore-if-stale

### 4. Event Loop Auto-Start Conflicts (🟡 MODERATE - Appears in 4+ repos)
- **Repos affected:** alecrabbit/php-console-spinner, bubbletea, whisp, php-tui
- **Problem:** Auto-starting event loops conflicts with error handlers and nested composition
- **SugarCraft risk:** MEDIUM — affects `candy-core` ReactPHP integration
- **Fix required:** Make event loop initialization explicit

### 5. Upstream Dependency Fragility (🟡 MODERATE - Appears in 5+ repos)
- **Repos affected:** promptkit, fang, bubbletea, bubblezone, mods, phpschool/cli-menu
- **Problem:** Upstream changes break downstream without warning (bubbletea v2 broke many components)
- **SugarCraft risk:** MEDIUM — affects all leaf libs depending on `candy-core`
- **Fix required:** Abstraction layers over TTY framework, version pinning, regression tests

### 6. Multi-Width Character Optimization Collision (🟡 MODERATE - Appears in 4+ repos)
- **Repos affected:** ultraviolet, bubblezone, go-termimg, pterm
- **Problem:** Column-optimization (ECH/REP/ICH/DCH) assumes 1 cell = 1 column; wide chars break
- **SugarCraft risk:** MEDIUM — affects `candy-core` renderer
- **Fix required:** Bypass all column-optimizations when line contains wide characters

---

## Most Requested Feature Categories

### 1. Layout System (🔥 HIGHEST DEMAND - 12+ repos requesting)
- **Repos:** lipgloss (#1 request), huh (layout interface), bubbleboxer, bubblelister, stickers, Evertras/bubble-table, textualize, ratatui, php-tui, dashbrew, bubbleup
- **What users want:** Flexbox/grid layout, constraint-based sizing, responsive to terminal resize
- **SugarCraft opportunity:** Implement `candy-shine` Layout with constraint solver (port from ratatui's Cassowary)
- **Priority:** IMMEDIATE

### 2. Dynamic/Async Content (🔥 HIGH DEMAND - 10+ repos)
- **Repos:** bubbles (List dynamic filtering), huh (dynamic reactivity), phpschool/cli-menu (dynamic menus), bubbleup (progress), daltonsw/bubbleup
- **What users want:** Content that updates without full re-render, live data streams, async updates
- **SugarCraft opportunity:** Implement reactive `with*()` + dirty flag rendering pattern
- **Priority:** SHORT-TERM

### 3. Fuzzy Filtering with Highlighting (🟡 MEDIUM DEMAND - 7+ repos)
- **Repos:** bubbles (List), bubble-table, pterm, gum, charmbracelet/huh, sequin
- **What users want:** Real-time filter with matched character highlighting
- **SugarCraft opportunity:** Port `sahilm/fuzzy` equivalent to PHP, integrate into `sugar-bits`
- **Priority:** SHORT-TERM

### 4. Plugin/Extension Architecture (🟡 MEDIUM DEMAND - 6+ repos)
- **Repos:** textualize (most requested), bubbletea (devtools), soft-serve, charm, skate, crush
- **What users want:** Third-party extensibility without modifying core
- **SugarCraft opportunity:** Define `ExtensionInterface` early, before 1.0
- **Priority:** MEDIUM-TERM

### 5. Animations (🟡 MEDIUM DEMAND - 5+ repos)
- **Repos:** harmonica, bubbleup, confettysh, textualize, ratatui
- **What users want:** Fade-in, slide transitions, particle effects
- **SugarCraft opportunity:** `honey-bounce` already exists; add animation driver to `candy-core`
- **Priority:** MEDIUM-TERM

### 6. Word Wrap with Style Preservation (🟡 MEDIUM DEMAND - 4+ repos)
- **Repos:** lipgloss, glamour, bubbles (TextArea), php-tui
- **What users want:** Wrap that preserves ANSI styles across line breaks
- **SugarCraft risk:** `candy-shine` has this gap
- **Priority:** SHORT-TERM

---

## Most Common Architectural Failures

### 1. Silent Failure on Edge Cases
- **Pattern:** Returning 0 items, empty strings, null when error occurred
- **Examples:** bubblezone markers invisible, phpschool/cli-menu backspace crash
- **Defensive lesson:** Fail loudly; never return corrupt state silently

### 2. Assumed Single-Threaded Concurrency
- **Pattern:** Shared mutable state without mutex protection
- **Examples:** pterm live printers race condition, ultraviolet shared buffer, bubbletea renderer state
- **Defensive lesson:** Always protect shared state with mutex or immutable data

### 3. Imperfect API Evolution
- **Pattern:** Breaking changes after v1.0 that migrate users
- **Examples:** lipgloss v2, huh v2, phpschool/cli-menu v5, promptkit v0.21.0 bug
- **Defensive lesson:** Use functional options pattern; never break public API contracts

### 4. Global State Singleton Anti-pattern
- **Pattern:** `GetInstance()` singletons that break testability
- **Examples:** c9s/CLIFramework, alecrabbit/php-console-spinner
- **Defensive lesson:** Use dependency injection; avoid singletons

### 5. Timeout Without Cancellation
- **Pattern:** `addPeriodicTimer` without tracking or cleanup
- **Examples:** bubbletea subscriptions, whisp signal handling
- **Defensive lesson:** Always track timers for cleanup on shutdown

---

## Most Valuable Innovation Opportunities for SugarCraft

### 1. Library-First Architecture (HIGH-VALUE)
- **Observation:** gum refuses to be a library (#224), causing fork proliferation
- **Opportunity:** SugarCraft's composable leaf-lib architecture is a genuine advantage
- **Action:** Lean into being a library; document composability

### 2. First-Class Testing Infrastructure (HIGH-VALUE)
- **Observation:** Bubble Tea lacks testing tools after 6+ years; textualize has `app.run_test()`
- **Opportunity:** SugarCraft could differentiate with VHS-based snapshot testing
- **Action:** Build `SugarCraft\Testing\VHSAssert` for golden file testing

### 3. PHP-Native Async Integration (HIGH-VALUE)
- **Observation:** Ratatui and Bubble Tea have no built-in async; PHP has ReactPHP
- **Opportunity:** SugarCraft can be the async-native TUI framework
- **Action:** Document async patterns; build async-first examples

### 4. Terminal Capability Query Abstraction (MEDIUM-VALUE)
- **Observation:** Every repo re-implements terminal detection; failures are silent
- **Opportunity:** Create `TerminalCapabilities` registry with graceful degradation
- **Action:** Build `candy-palette` as definitive terminal capability detection

### 5. Cell-Based Buffer Rendering (HIGH-VALUE)
- **Observation:** candy-core uses string diffing; ratatui/ultraviolet use true cell buffers
- **Opportunity:** Port `Buffer`/`Cell` model from ratatui for finer-grained repaints
- **Action:** Implement `Buffer` value object + `CellBufferRenderer`

### 6. Layout System (HIGH-VALUE)
- **Observation:** lipgloss, bubbleboxer, ratatui all reinventing layout
- **Opportunity:** SugarCraft could unify with constraint-based Layout
- **Action:** Port ratatui's Cassowary solver (already exists in `honey-bounce`?)

---

## Most Dangerous Implementation Traps

### 1. Escape Sequence Termination
```
BAD:  fwrite($fp, "\x1b[38;5;$colorm");
GOOD: fwrite($fp, "\x1b[38;5;{$color}m\x1b\\");
```
**Why:** Some terminals require explicit DCS termination or will consume subsequent bytes.

### 2. mb_strlen for Visual Width
```
BAD:  $width = mb_strlen($text);
GOOD: $width = mb_strwidth($text);
```
**Why:** CJK characters count as 2 columns visually but `strlen` returns 1.

### 3. Reading stdin Without Non-Blocking
```
BAD:  $bytes = fread(STDIN, 1024);  // blocks forever if no input
GOOD: $bytes = @fread(STDIN, 1024); if ($bytes === false || feof(STDIN)) ...
```
**Why:** STDIN in raw mode can block indefinitely on pipe/pty mismatch.

### 4. Shared Mutable Renderer State
```
BAD:  class Renderer { public array $lines = []; }  // race condition
GOOD: class Renderer { private Mutex $mutex; public function render(...) { $this->mutex->lock(); ... } }
```
**Why:** Input loop and render loop run concurrently; shared state needs protection.

### 5. ANSI Style Loss on Wrap
```
BAD:  substr($line, 0, $width)  // truncates mid-escape
GOOD: // parse tokens, preserve complete sequences during wrap
```
**Why:** Truncating ANSI sequences mid-way produces corrupted output.

---

## Strongest Recurring UX Complaints

### 1. "It doesn't work on my terminal" (12+ repos)
- **Root cause:** Terminal detection fails silently; graceful degradation absent
- **SugarCraft fix:** Probe once at startup, cache capabilities, always have fallback

### 2. "Memory grows without bound" (6+ repos)
- **Root cause:** Subscriber timers, cached data, regex preg_* all leak
- **SugarCraft fix:** Implement `__destruct` cleanup, bounded caches, weakrefs for parent refs

### 3. "Breaking change after upgrade" (8+ repos)
- **Root cause:** No semver enforcement, insufficient deprecation periods
- **SugarCraft fix:** Follow semver strictly; 1 major version for stability

### 4. "Documentation doesn't match reality" (5+ repos)
- **Root cause:** Docs written before code, not updated on change
- **SugarCraft fix:** Docs in code (phpdoc), checked in CI, examples must run

### 5. "Works locally but fails in CI" (7+ repos)
- **Root cause:** CI lacks TTY, color profile detection fails, signal handling different
- **SugarCraft fix:** Test in both TTY and non-TTY modes; mock terminal capability queries

---

## Repeated Extensibility Limitations

### 1. No Plugin Architecture (12+ repos)
- **Examples:** textualize, bubbletea, soft-serve, skate, charm
- **Pattern:** Extensibility exists but is internal-only or requires fork
- **SugarCraft opportunity:** Design `ExtensionInterface` + `ExtensionRegistry` early

### 2. Non-Export of Internal State (8+ repos)
- **Examples:** promptkit validateKeyMap, bubbleboxer layout algorithm, bubblelister cursor tracking
- **Pattern:** Internal APIs are useful but marked private; users fork instead
- **SugarCraft fix:** Export once stable; use `_` prefix for truly private

### 3. Tight Coupling to Bubble Tea (6+ repos)
- **Examples:** bubblezone, bubble-table, promptkit, daltonsw/bubbleup
- **Pattern:** Component depends on `tea.Model` interface; can't use elsewhere
- **SugarCraft fix:** Depend on interfaces, not concrete implementations

### 4. Global Configuration State (4+ repos)
- **Examples:** c9s/CLIFramework singleton, alecrabbit auto-start
- **Pattern:** Global config prevents multiple independent instances
- **SugarCraft fix:** Use DI; pass config as constructor argument

---

## Repeated Scalability Bottlenecks

### 1. O(n) Viewport Rendering (6+ repos)
- **Examples:** bubbles Viewport, bubble-table, Evertras/bubble-table
- **Pattern:** Re-render all items on every frame regardless of visibility
- **SugarCraft fix:** Implement viewport-only rendering; only paint visible rows

### 2. String Concatenation in Hot Path (5+ repos)
- **Examples:** pterm Paginator, lipgloss string building, bubbles progress
- **Pattern:** `$str .= ...` in 60fps loops allocates excessively
- **SugarCraft fix:** Use `StringBuilder` pattern; pre-allocate buffers

### 3. Unbounded Subscription Timers (4+ repos)
- **Examples:** bubbletea tick subscriptions, whisp signal handlers
- **Pattern:** Timers created but never cancelled on shutdown
- **SugarCraft fix:** Track all subscriptions; cancel in `Program::destruct()`

### 4. No Lazy Initialization (4+ repos)
- **Examples:** colorprofile OSC queries, phpschool/cli-menu init
- **Pattern:** Expensive initialization on first use; blocks UI
- **SugarCraft fix:** Init in `Program::run()` before event loop

### 5. Regex Preg_Memory Growth (3+ repos)
- **Examples:** crush global regex, charm URL parsing
- **Pattern:** `preg_*` functions cache indefinitely; no memory bound
- **SugarCraft fix:** Use `WeakMap` for regex cache; periodic flush

---

## Recurring Maintainability Problems

### 1. Massive Single-File Codebases (4+ repos)
- **Examples:** smenu (17,500 lines), php-console-spinner (419 files), c9s/CLIFramework (single command file)
- **Pattern:** Monolithic files resist refactoring; bus factor = 1
- **SugarCraft fix:** Modular structure; max 500 lines per class

### 2. No Test Coverage (3+ repos)
- **Examples:** kojiflowers/php-tui-chart (no tests), c9s/CLIFramework (minimal), phpschool/cli-menu
- **Pattern:** No tests means bugs hidden until production
- **SugarCraft fix:** Require >80% coverage; snapshot tests for rendering

### 3. Pre-PHP 8.x Patterns (2+ repos)
- **Examples:** c9s/CLIFramework (no return types), alecrabbit/php-console-spinner
- **Pattern:** PHP 5 era patterns block modernization
- **SugarCraft fix:** PHP 8.3+ only; strict types everywhere

### 4. Dependency Lock-In (5+ repos)
- **Examples:** charm (caarlos0/sshmarshal dot-import), promptkit (bubbletea dep), fantasy (provider deps)
- **Pattern:** Transitive dependencies block upgrades
- **SugarCraft fix:** Pin essential deps; prefer stdlib; minimal external deps

### 5. Undocumented Edge Cases (6+ repos)
- **Examples:** sequin scope definition, ultraviolet buffer boundaries
- **Pattern:** TODO comments, known limitations undocumented
- **SugarCraft fix:** Document every edge case; `/** @deprecated */` with reason

---

## High-ROI Opportunities for SugarCraft

### Immediate (0-3 months)

| Opportunity | Impact | Effort | Source Repos |
|---|---|---|---|
| Fix Unicode width with `mb_strwidth` | CRITICAL | LOW | lipgloss, bubbles, php-school |
| Add escape sequence termination `\x1b\\` | CRITICAL | LOW | ultraviolet, sequin, go-termimg |
| Terminal capability probe with caching | HIGH | MEDIUM | colorprofile, charm, bubbletea |
| Mutex-protect shared renderer state | HIGH | LOW | pterm, ultraviolet, bubbletea |
| Viewport-only rendering for List/Table | HIGH | MEDIUM | bubbles, bubble-table, Evertras |
| StringBuilder for hot path concatenation | MEDIUM | LOW | pterm, lipgloss, bubbles |

### Short-Term (3-6 months)

| Opportunity | Impact | Effort | Source Repos |
|---|---|---|---|
| Layout system with constraints | HIGH | HIGH | lipgloss, ratatui, huh, bubbleboxer |
| Fuzzy filter with highlighting | HIGH | MEDIUM | bubbles, bubble-table, gum |
| Style-preserving word wrap | HIGH | MEDIUM | glamour, lipgloss, bubbles |
| ProgramOptions builder | MEDIUM | LOW | bubbletea (16-param constructor) |
| WeakRef for parent-child widget refs | MEDIUM | LOW | textualize |
| Error types instead of silent failures | MEDIUM | LOW | bubbleboxer, bubblelister |

### Medium-Term (6-12 months)

| Opportunity | Impact | Effort | Source Repos |
|---|---|---|---|
| Cell-based buffer rendering | HIGH | HIGH | ratatui, ultraviolet, candy-core |
| Plugin/Extension architecture | HIGH | HIGH | textualize, soft-serve, bubbletea |
| Animation driver in core | MEDIUM | MEDIUM | textualize, harmonica, bubbleup |
| VHS-based snapshot testing | HIGH | MEDIUM | bubbletea (no testing tools) |
| Async-first examples + patterns | HIGH | MEDIUM | ratatui (no async), bubbletea |

### Strategic (12+ months)

| Opportunity | Impact | Effort | Source Repos |
|---|---|---|---|
| Distributed TUI (network renderer) | HIGH | HIGH | ratatui, bubbletea (mentioned) |
| CSS-like stylesheet parser | MEDIUM | HIGH | textualize (TCSS) |
| AI/LLM integration patterns | HIGH | HIGH | crush, mods, fantasy |
| WebAssembly rendering target | LOW | VERY HIGH | (emerging niche) |

---

## Strategic Ecosystem Trends

### 1. Charmbracelet Consolidation
The Charm ecosystem has consolidated around Bubble Tea v2 + lipgloss v2 + harmonica. The sunset of mods, crush, and charm (March 2026) signals refocusing on core TUI primitives.

### 2. AI Integration Volatility
Both mods and crush were sunset in March 2026. This shows AI CLI tooling is volatile and depends heavily on provider APIs. SugarCraft should be cautious about deep AI integration.

### 3. Textual's Widget Dominance
With 40+ built-in widgets, Textual sets the user expectation for TUI frameworks. SugarCraft needs comprehensive widget coverage in `sugar-bits`.

### 4. Ratatui's Modular Architecture
The workspace split (ratatui-core, ratatui-widgets, ratatui-backend) provides a model for API stability. SugarCraft should adopt similar modular structure.

### 5. PHP TUI is Nascent
php-tui (~2k stars) and SugarCraft are the main PHP TUI options. There's no established PHP TUI ecosystem yet — SugarCraft can define standards.

### 6. Convergence on Elm Architecture
Both Bubble Tea and Textual use Elm-inspired patterns (Model/Update/View, message passing). This validates SugarCraft's architectural choice.

### 7. Security is Increasingly Important
soft-serve had multiple critical security issues found by community audit. SugarCraft must prioritize security from day one.

### 8. Documentation as Competitive Advantage
Projects with poor docs (aleccrabbit, c9s/CLIFramework) have high pain points. sugar-bits and candy-core should invest heavily in docs.

---

## Cross-Ecosystem Pattern Matches

These patterns appear across Go, Rust, Python, and PHP TUI ecosystems:

| Pattern | Go | Rust | Python | PHP | SugarCraft |
|---|---|---|---|---|---|
| Elm Architecture | bubbletea | — | textual | candy-core | ✅ implemented |
| Cell-based buffer | ultraviolet | ratatui | textual | candy-core (planned) | 🔲 planned |
| Constraint layout | bubbleboxer | ratatui | textual | candy-shine (planned) | 🔲 planned |
| Fuzzy filtering | bubbles | — | textual | sugar-bits (gaps) | 🔲 gaps |
| CSS styling | lipgloss | ratatui | textual (TCSS) | candy-sprinkles | ✅ implemented |
| Widget trait | — | ratatui | textual | — | 🔲 not planned |
| Snapshot testing | — | — | textual | sugar-bits | 🔲 not planned |

---

## Validation of First-Pass Analysis

### Confirmed: Bubble Tea is the Primary Reference
Second-stage analysis confirms `charmbracelet/bubbletea` is the most analyzed repo (23k stars, 60+ examples, massive ecosystem). All its architectural patterns are validated.

### Confirmed: lipgloss v2 Architecture is Correct
lipgloss v2's shift to pure Style type and writer functions is the correct direction for `candy-sprinkles`.

### Confirmed: Textual is Python State-of-the-Art
Textual's reactive state + CSS styling + widget library is more sophisticated than Bubble Tea. SugarCraft should study it but not copy it directly.

### Confirmed: Ratatui's Immediate Mode is Efficient
Ratatui's buffer-diffing approach is more efficient than retained-mode string diffing. SugarCraft should implement cell-based buffer rendering.

### Confirmed: Sunset Projects Signal Volatility
mods, crush, charm all sunset in March 2026. AI integration is volatile. SugarCraft should focus on core TUI, not AI features.

### New: PHP Ecosystem Lacks Mature Options
php-tui is the main PHP competition but is less mature than Go/Rust alternatives. SugarCraft has first-mover advantage in PHP TUI ecosystem.

### New: Documentation Quality Varies Wildly
Charmbracelet has excellent docs; php-tui has none. Documentation is a major differentiator that SugarCraft should invest in.

---

## Appendix: Reports Generated

| Report File | Source Repo | Lines |
|---|---|---|
| pr_charmbracelet_bubbletea.md | charmbracelet/bubbletea | ~700 |
| pr_charmbracelet_bubbles.md | charmbracelet/bubbles | ~919 |
| pr_charmbracelet_lipgloss.md | charmbracelet/lipgloss | ~600 |
| pr_charmbracelet_harmonica.md | charmbracelet/harmonica | ~335 |
| pr_charmbracelet_huh.md | charmbracelet/huh | ~700 |
| pr_charmbracelet_gum.md | charmbracelet/gum | ~800 |
| pr_charmbracelet_glow.md | charmbracelet/glow | ~600 |
| pr_charmbracelet_vhs.md | charmbracelet/vhs | ~800 |
| pr_charmbracelet_freeze.md | charmbracelet/freeze | ~500 |
| pr_charmbracelet_wish.md | charmbracelet/wish | ~700 |
| pr_charmbracelet_soft-serve.md | charmbracelet/soft-serve | ~655 |
| pr_charmbracelet_x.md | charmbracelet/x | ~300 |
| pr_charmbracelet_sequin.md | charmbracelet/sequin | ~400 |
| pr_charmbracelet_glamour.md | charmbracelet/glamour | ~1052 |
| pr_pterm_pterm.md | pterm/pterm | ~690 |
| pr_textualize_textual.md | textualize/textual | ~900 |
| pr_ratatui_ratatui.md | ratatui/ratatui | ~1203 |
| pr_php-tui_php-tui.md | php-tui/php-tui | ~724 |
| pr_lrstanley_bubblezone.md | lrstanley/bubblezone | ~466 |
| pr_charmbracelet_ultraviolet.md | charmbracelet/ultraviolet | ~600 |
| pr_Evertras_bubble-table.md | Evertras/bubble-table | ~500 |
| pr_charmbracelet_fang.md | charmbracelet/fang | ~829 |
| pr_charmbracelet_mods.md | charmbracelet/mods | ~479 |
| pr_charmbracelet_pop.md | charmbracelet/pop | ~435 |
| pr_charmbracelet_skate.md | charmbracelet/skate | ~719 |
| pr_charmbracelet_log.md | charmbracelet/log | ~400 |
| pr_charmbracelet_catwalk.md | charmbracelet/catwalk | ~696 |
| pr_charmbracelet_crush.md | charmbracelet/crush | ~700 |
| pr_charmbracelet_colorprofile.md | charmbracelet/colorprofile | ~834 |
| pr_76creates_stickers.md | 76creates/stickers | ~318 |
| pr_charmbracelet_confettysh.md | charmbracelet/confettysh | ~400 |
| pr_WhispPHP_whisp.md | WhispPHP/whisp | ~400 |
| pr_alecrabbit_php-console-spinner.md | alecrabbit/php-console-spinner | ~448 |
| pr_c9s_CLIFramework.md | c9s/CLIFramework | ~500 |
| pr_php-school_cli-menu.md | php-school/cli-menu | ~600 |
| pr_treilik_bubbleboxer.md | treilik/bubbleboxer | ~400 |
| pr_daltonsw_bubbleup.md | daltonsw/bubbleup | ~400 |
| pr_erikgeiser_promptkit.md | erikgeiser/promptkit | ~670 |
| pr_KevM_bubbleo.md | KevM/bubbleo | ~670 |
| pr_blacktop_go-termimg.md | blacktop/go-termimg | ~612 |
| pr_ratatui_ratatui-image.md | ratatui/ratatui-image | ~600 |
| pr_EthanEFung_bubble-datepicker.md | EthanEFung/bubble-datepicker | ~686 |
| pr_rmhubbert_bubbletea-overlay.md | rmhubbert/bubbletea-overlay | ~400 |
| pr_Genekkion_theHermit.md | Genekkion/theHermit | ~637 |
| pr_p-gen_smenu.md | p-gen/smenu | ~500 |
| pr_treilik_bubblelister.md | treilik/bubblelister | ~400 |
| pr_rasjonell_dashbrew.md | rasjonell/dashbrew | ~500 |
| pr_charmbracelet_charm.md | charmbracelet/charm | ~676 |
| pr_charmbracelet_promwish.md | charmbracelet/promwish | ~514 |
| pr_charmbracelet_fantasy.md | charmbracelet/fantasy | ~647 |
| pr_kojiflowers_php-tui-chart.md | kojiflowers/php-tui-chart | ~495 |

**Total: 50 second-stage intelligence reports, ~30,000 lines of aggregated analysis**

---

## Conclusion

The second-stage ecosystem analysis reveals that SugarCraft's architectural choices are sound — Elm architecture, immutable patterns, and composable leaf libs are validated by upstream successes. However, critical gaps remain:

1. **Unicode width handling** must be fixed immediately
2. **Escape sequence termination** must be correct
3. **Layout system** is the #1 user demand
4. **Terminal capability detection** needs abstraction
5. **Cell-based buffer rendering** is the long-term rendering goal

The ecosystem analysis also reveals opportunities for differentiation:
- Library-first architecture (where gum fails)
- PHP-native async (where ratatui/bubbletea have no answer)
- First-class testing (where bubbletea is weak)
- Documentation quality (where most repos fail)

SugarCraft is well-positioned to become the definitive PHP TUI framework if it addresses the critical path items and capitalizes on upstream's recurring failures.
