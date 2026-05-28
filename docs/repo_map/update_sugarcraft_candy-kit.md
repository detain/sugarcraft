# Overview

CandyKit (`candy-kit`) is a PHP port of `charmbracelet/fang` providing opinionated CLI presentation helpers (StatusLine, Banner, Section, Stage, HelpText, Logo, Theme) that turn ordinary command-line output into branded terminal output. It's library-only, framework-agnostic, and integrates with the SugarCraft ecosystem via `candy-sprinkles` (styling) and `candy-core` (utilities).

**Biggest opportunity areas:**
- Adaptive theme detection matching fang's `ColorSchemeFunc` pattern
- Shell completion generation (fang's most-requested missing feature)
- Wide-character alignment fixes in HelpText
- Command palette / interactive selection system

**Biggest missing capabilities:**
- Light/dark terminal auto-detection
- Shell completion generation for bash/zsh/fish
- Manpage generation
- Banner title anchor positioning (top-left/center/right)
- Section dual-label support (left + right labels)
- Theme derivation helpers (`withSuccess()`, `withError()`, etc.)
- Logo re-theming without re-rendering from ASCII

---

# Internal Capability Summary

## Current Architecture

```
candy-kit/
├── depends on: candy-core (Width utility, Color utilities)
├── depends on: candy-sprinkles (Style, Border)
└── provides: CLI presentation helpers
```

**7 Core Components:**

| Component | Responsibility | Key Methods |
|---|---|---|
| `Theme` | Palette of 7 Style objects (success/error/warn/info/prompt/accent/muted) | 6 named factories (`ansi()`, `plain()`, `charm()`, `dracula()`, `nord()`, `catppuccin()`) |
| `StatusLine` | Single styled status line: glyph + message | `success()`, `error()`, `warn()`, `info()`, `prompt()` |
| `Banner` | Bordered title block with optional subtitle | `title()` with customizable Border |
| `Section` | Section header with fill-to-width ruler | `header()`, `rule()` |
| `Stage` | Numbered step indicators for multi-step processes | `step()`, `subStep()` |
| `HelpText` | Git-style `--help` page with 2-column alignment | `render()`, `renderRows()` |
| `Logo` | ASCII-art logo renderer with color theming | `fromAscii()`, `sugarcraft()`, `withColor()`, `render()` |

**Design decisions:**
- All classes are `final` with static factory methods
- No instance state beyond configuration
- Immutable value objects (Theme constructor sets all fields)
- ANSI-safe width calculation via `Width::string()` in Section
- Unicode-aware length via `mb_strlen()` in HelpText (but has wide-char bug)
- Logo stores rendered string after `withColor()` — not re-themable

## Strengths

1. **Minimal, focused API** — 7 classes, single responsibility, no learning curve
2. **Drop-in library** — No framework requirement; `composer require sugarcraft/candy-kit`
3. **Immutable + final classes** — Safe for concurrent use, easy to test
4. **6 named theme factories** — Covers common branded palettes
5. **ANSI-safe width calculation** — Accurate alignment with styled text
6. **Good test coverage** — Dedicated tests per component
7. **VHS demos** — Visual regression testing + documentation via `.tape` files

## Weaknesses

1. **No auto theme detection** — fang uses `lipgloss.LightDarkFunc`; candy-kit requires explicit theme passing
2. **No shell completion generation** — Fang includes `completion` command; requires CLI framework integration
3. **No manpage generation** — Fang integrates `mango-cobra` for roff generation
4. **HelpText alignment bug** — Uses `mb_strlen()` not `Width::string()`; double-width chars cause misalignment
5. **No Banner title anchor** — Fixed `Border::rounded()` + `padding(0, 2)` only
6. **Logo stores rendered string** — After `withColor()` applied, cannot re-theme without re-creating from ASCII
7. **No Section right-side label** — Only supports left-padded labels

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `charmbracelet/fang` | Primary upstream | ColorSchemeFunc adaptive theme, shell completions, manpage gen, option pattern | **Critical** |
| `charmbracelet/lipgloss` | Styling foundation | CSS-like shorthand, adaptive colors, border system, Layer/Compositor | **High** |
| `charmbracelet/gum` | CLI tool wrapper | Fuzzy search, interactive prompts, style system via lipgloss | **Medium** |
| `charmbracelet/log` | Logging cousin | Sub-logger pattern, context propagation, styled prefixes | **Medium** |
| `charmbracelet/bubbletea` | Elm architecture | Model/Update/View, Commands, Subscriptions | **Low** |
| `pterm/pterm` | Go CLI framework | TextPrinter interface, 25+ printers, Fade gradients, interactive select | **High** |
| `c9s/CLIFramework` | PHP CLI framework | Hierarchical commands, shell completion, event hooks | **Medium** |
| `textualize/textual` | Python TUI | Command palette, reactive state, CSS layout | **Medium** |
| `ratatui/ratatui` | Rust TUI | Cassowary layout, buffer diffing, widget system | **Low** |
| `php-tui/php-tui` | PHP Ratatui port | Widget/WidgetRenderer pattern, buffer cell diffing | **Low** |

---

# Feature Gap Analysis

## Critical Priority

### 1. Light/Dark Auto-Detection for Themes
**Description:** Fang's `ColorSchemeFunc` accepts `lipgloss.LightDarkFunc` for automatic adaptation. candy-kit's Theme factories produce fixed-palette themes.

**Why it matters:** Users on dark and light terminals get the same (dark-optimized) colors by default. Terminal auto-detection is a standard expectation for modern CLI styling.

**Source:** `docs/repo_map/charmbracelet_fang.md` — `ColorSchemeFunc` pattern

**Implementation:**
```php
public static function adaptive(?Closure $isDark = null): self {
    $fn = $isDark ?? fn() => ColorProfile::fromEnvironment()->hasDarkBackground();
    return $fn() ? self::dracula() : self::nord();
}
```

**Estimated complexity:** Low — ~10 lines in Theme.php

**Expected impact:** High — brings feature parity with fang's theme system

---

### 2. Shell Completion Generator
**Description:** Fang includes `completion` command for generating bash/zsh/fish completions. candy-kit has no completion generation.

**Why it matters:** Shell completions are a major ergonomic win for CLI tools. Fang's completion implementation uses Cobra's flag/command structure to generate complete completion scripts.

**Source:** `docs/repo_map/charmbracelet_fang.md` — `WithoutCompletions()` option

**Implementation ideas:**
- Create standalone `sugar-complete` or `candy-complete` library
- Accept flag/command structure from any CLI framework
- Generate completion scripts for bash, zsh, fish
- Support lazy-computed valid values via closures

**Estimated complexity:** High — requires understanding shell completion mechanisms deeply

**Expected impact:** High — major CLI usability improvement

---

### 3. HelpText Wide-Character Alignment Fix
**Description:** `renderRows()` uses `mb_strlen()` for key width calculation, not visual cell width. Double-width characters (emoji, CJK) cause misaligned descriptions.

**Why it matters:** Non-ASCII content breaks the two-column alignment, degrading output quality for international users.

**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — Unicode width handling via `displaywidth` package

**Implementation:**
```php
// Replace mb_strlen($key, 'UTF-8') with Width::string($key)
$maxKey = 0;
foreach (array_keys($rows) as $k) {
    $w = Width::string($k);  // ANSI-aware, wide-char-aware
    if ($w > $maxKey) $maxKey = $w;
}
```

**Estimated complexity:** Low — replace one function call

**Expected impact:** Medium — fixes alignment bug for Unicode users

---

## High Value Priority

### 4. Banner Title Anchor Positioning
**Description:** Banner uses fixed `Border::rounded()` + `padding(0, 2)`. No support for title positioning (top-left, top-center, top-right, etc.) like lipgloss's `BorderTitle`.

**Why it matters:** Users may want different title alignments for different contexts (left for technical tools, centered for branding).

**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — `Border{...}` struct with 13 fields for per-corner/edge control

**Implementation ideas:**
- Add `Banner::withTitlePosition(Position $pos)` where Position = Left|Center|Right
- Use lipgloss-style border title pattern
- Reuse `candy-sprinkles` BorderTitle system

**Estimated complexity:** Medium

**Expected impact:** Medium

---

### 5. Section Dual-Label Support
**Description:** `Section::header()` only supports left-padded labels. No right-side label support (e.g., `── SETUP ──────────────────── 3/5 ──`).

**Why it matters:** Common CLI pattern for showing context on both ends (step number, percentage, status).

**Source:** `docs/repo_map/pterm_pterm.md` — `SectionPrinter` with indent levels

**Implementation:**
```php
public static function headerWithRightLabel(
    string $label,
    string $rightLabel = '',
    ?Theme $theme = null,
    int $leftPad = 2,
    ?int $width = 80,
    string $rune = '─',
): string {
    // Calculate left label width, fill, then right label
}
```

**Estimated complexity:** Low-Medium

**Expected impact:** Medium

---

### 6. Theme Derivation Helpers
**Description:** No `withSuccess()` / `withError()` etc. methods to derive a new theme from an existing one with a single color changed.

**Why it matters:** Users may want to customize one level without redefining all 7. Current approach requires constructing a full new Theme.

**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — Immutable Style with setters returning new instances

**Implementation:**
```php
public function withSuccess(Style $success): self {
    return new self($success, $this->error, $this->warn, $this->info, $this->prompt, $this->accent, $this->muted);
}
```

**Estimated complexity:** Low — ~7 methods, ~5 lines each

**Expected impact:** Medium — improves Theme ergonomics

---

### 7. Logo Re-theming Support
**Description:** After `withColor()` is applied, Logo stores a rendered string. Cannot change color without re-creating from original ASCII art.

**Why it matters:** Users may want to reuse a Logo instance with different themes.

**Source:** `docs/repo_map/charmbracelet_fang.md` — Logo pattern analysis

**Implementation:**
- Store original ASCII art as private property
- `withColor()` re-renders from original, not cached styled content
- OR: Add `Logo::withColorOverride()` that stores raw + applies new color

**Estimated complexity:** Low

**Expected impact:** Low-Medium

---

## Medium Priority

### 8. Manpage Generation
**Description:** Fang integrates `mango-cobra` for roff generation. candy-kit does not implement this.

**Why it matters:** Manpages are expected for serious CLI tools. Not implementing this keeps parity with fang's "not applicable to library" stance, but users may want the feature.

**Source:** `docs/repo_map/charmbracelet_fang.md` — `WithoutManpage()` option

**Estimated complexity:** High

**Expected impact:** Medium

---

### 9. Automatic --version Wiring
**Description:** Fang automatically wires `--version` to build info. candy-kit has no mechanism.

**Why it matters:** Version flag is standard CLI expectation. Requires CLI framework integration point.

**Source:** `docs/repo_map/charmbracelet_fang.md` — `WithVersion()`, `WithCommit()` options

**Estimated complexity:** Medium

**Expected impact:** Medium

---

### 10. Option Pattern for HelpText
**Description:** Fang's help rendering accepts `Styles` customizable via `WithColorSchemeFunc()`. candy-kit's `HelpText::render()` only accepts a Theme.

**Why it matters:** Fine-grained control over help page rendering (custom section ordering, key/value separators).

**Source:** `docs/repo_map/charmbracelet_fang.md` — `ColorSchemeFunc` + option pattern

**Estimated complexity:** Medium

**Expected impact:** Low-Medium

---

### 11. Command Palette System
**Description:** Textual has an extensive command palette with fuzzy search. No equivalent in SugarCraft.

**Why it matters:** Command palettes are increasingly expected in modern CLI tools for power users.

**Source:** `docs/repo_map/textualize_textual.md` — `CommandPalette`, `Provider` system

**Estimated complexity:** High

**Expected impact:** Medium

---

## Low Priority

### 12. Interactive Confirm/Select Prompts
**Description:** pterm has `InteractiveConfirmPrinter`, `InteractiveSelectPrinter` with keyboard navigation and fuzzy search.

**Why it matters:** Interactive CLI components improve user experience for common operations.

**Source:** `docs/repo_map/pterm_pterm.md` — `InteractiveSelectPrinter`, `InteractiveConfirmPrinter`

**Estimated complexity:** High

**Expected impact:** Medium

---

### 13. Context Propagation in Theme
**Description:** `charmbracelet/log` supports sub-loggers with context. Theme could support per-module contextual theming.

**Source:** `docs/repo_map/charmbracelet_log.md` — `logger.With("key", "value")` sub-logger pattern

**Estimated complexity:** Low

**Expected impact:** Low

---

# Algorithm / Performance Opportunities

## Current: mb_strlen() in HelpText Alignment
**Current approach:** Uses `mb_strlen($key, 'UTF-8')` to measure key width.

**Problem:** `mb_strlen()` counts code points, not visual cell width. CJK/emoji characters take 2 cells but count as 1 code point.

**External approach:** `Width::string()` strips ANSI first, then uses `mb_strwidth()` for visual cell width.

**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — `displaywidth.String()` for Unicode width

**Why external is better:** Correct alignment for all Unicode content, not just ASCII.

**Tradeoffs:** Width::string() is already available in candy-core; only needs to be used.

**Applicability:** Fix is trivial — replace one function call in HelpText::renderRows().

---

## Current: Fixed Theme Factories
**Current approach:** Static factory methods returning fixed-palette themes.

**External approach:** fang's `ColorSchemeFunc` accepts closure for dynamic adaptation.

**Source:** `docs/repo_map/charmbracelet_fang.md` — `ColorSchemeFunc` signature

**Why external is better:** Adapts to terminal background automatically without caller managing state.

**Tradeoffs:** Requires `ColorProfile::detect()` which may be unreliable in non-TTY contexts.

**Applicability:** Easy to implement, high value.

---

# Architecture Improvements

## 1. Option Pattern Adoption
Fang uses functional options (`type Option func(*settings)`) for deferred configuration. candy-kit uses nullable parameters with null coalescing defaults.

**Current:**
```php
public static function title(
    string $title,
    string $subtitle = '',
    ?Theme $theme = null,
    ?Border $border = null
): string {
    $theme  ??= Theme::ansi();
    $border ??= Border::rounded();
```

**Improved (optional):**
```php
public static function title(string $title, string $subtitle = '', ?Theme $theme = null, ?Border $border = null, ?Options $opts = null): string;
```

This allows extending without breaking signatures.

## 2. Theme Derivation Pattern
Add `with*()` methods to Theme for single-level customization:

```php
public function withSuccess(Style $success): self;
public function withError(Style $error): self;
// etc.
```

**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — immutable Style setters

## 3. Logo Architecture Fix
Store raw ASCII art, not rendered content:

```php
private function __construct(
    private readonly string $ascii,  // Raw, not styled
    private readonly ?string $styled = null,  // Lazy-computed on render
) {}

public function withColor(string|Color $color): self {
    $c = $color instanceof Color ? $color : Color::fromHex($color);
    $styled = Style::new()->foreground($c)->render($this->ascii);
    return new self($this->ascii, $styled);
}

public function render(): string {
    return $this->styled ?? $this->ascii;
}
```

---

# API / Developer Experience Improvements

## 1. Unified TextPrinter-like Interface
pterm's `TextPrinter` interface with 8 methods (Sprint/Sprintf/Sprintln/Sprintfln × return string vs write output) provides consistent API across all printers.

**candy-kit could add:**
```php
interface TextOutput {
    public function render(): string;
    public function output(): void;
}
```

## 2. Builder Pattern for Complex Components
HelpText::render() currently takes array for sections. Could use a builder:

```php
HelpText::build()
    ->usage('myapp [flags] <command>')
    ->description('A CLI tool.')
    ->section('FLAGS', $flagRows)
    ->section('COMMANDS', $cmdRows)
    ->render();
```

## 3. Theme Enumeration
Allow programmatic theme selection:

```php
Theme::names();  // ['ansi', 'plain', 'charm', 'dracula', 'nord', 'catppuccin']
Theme::fromName('nord');
```

---

# Documentation / Cookbook Opportunities

## 1. CLI Presentation Patterns Guide
Document common patterns:
- Version output with Banner + Logo
- Multi-step progress with Stage
- Help page structure with HelpText
- Error/warning/info output with StatusLine

## 2. Theme Customization Tutorial
Show how to:
- Create custom themes
- Use adaptive theme detection
- Derive themes with with*() methods

## 3. Integration Examples
Show candy-kit integration with:
- `candy-shell` (CLI application framework)
- `candy-log` (logging integration)
- `candy-sprinkles` (advanced styling)

---

# UX / TUI Improvements

## 1. HelpText Markdown Description
Fang's help can render markdown-formatted descriptions. HelpText currently only supports plain text.

**Source:** `docs/repo_map/charmbracelet_gum.md` — `gum format` uses glamour for markdown

**Implementation:** Add `HelpText::renderMarkdown()` that integrates `candy-shine` for markdown descriptions.

## 2. Section Right-Side Labels
Common CLI pattern: `── SETUP ──────────────────── 3/5 ──`

**Source:** `docs/repo_map/pterm_pterm.md` — SectionPrinter

## 3. Banner Title Positioning
Add `Banner::titleWithPosition()` accepting `Position::Left|Center|Right`:

```php
Banner::titleWithPosition(
    'MyApp',
    position: Position::Center,
    subtitle: 'v1.0.0'
);
```

---

# Testing / Reliability Improvements

## 1. Wide-Character Snapshot Tests
Add tests for HelpText with emoji/CJK content:

```php
public function testRenderRowsWithEmoji(): void {
    $rows = ['🔧' => 'tool option', '配置' => 'configuration'];
    $output = HelpText::renderRows($rows, Theme::ansi());
    // Verify alignment is correct
}
```

## 2. Theme Adaptive Detection Tests
Add tests for `Theme::adaptive()` with mocked ColorProfile:

```php
public function testAdaptiveDarkTheme(): void {
    $theme = Theme::adaptive(fn() => true);
    $this->assertSame('dracula', $theme->name());
}

public function testAdaptiveLightTheme(): void {
    $theme = Theme::adaptive(fn() => false);
    $this->assertSame('nord', $theme->name());
}
```

## 3. Banner Long Title Tests
Test border wrapping behavior with very long titles.

## 4. Stage Edge Case Tests
Test with negative or very large step numbers.

---

# Ecosystem / Integration Opportunities

## 1. candy-shell Integration
candy-kit should document integration with `candy-shell` (SugarCraft's CLI application framework):

```php
use SugarCraft\Kit\{Banner, StatusLine, Theme};
use SugarCraft\Shell\Application;

class MyApp extends Application {
    protected function execute(): int {
        echo Banner::title('MyApp', 'v1.0.0'), "\n\n";
        StatusLine::info('Starting...');
        // ...
    }
}
```

## 2. candy-log Integration
candy-kit's StatusLine is visually similar to log levels but lacks log semantics. Document when to use which:

- **StatusLine:** User-facing single messages, one-shot output
- **candy-log:** Time-stamped, leveled, potentially high-volume logging

## 3. sugar-bits Progress Integration
Stage is for visual sequencing; `sugar-bits` progress components are for bars/gauges. Document the distinction.

---

# Notable PRs / Issues / Discussions

## charmbracelet/fang: ColorSchemeFunc Pattern
**Source:** `docs/repo_map/charmbracelet_fang.md`

Fang's `ColorSchemeFunc` signature enables automatic adaptation:
```go
type ColorSchemeFunc = func(lipgloss.LightDarkFunc) ColorScheme
```

**Lesson:** PHP equivalent would be:
```php
public static function adaptive(?Closure $isDark = null): Theme {
    $fn = $isDark ?? fn() => ColorProfile::detect()->hasDarkBackground();
    return $fn() ? self::dracula() : self::nord();
}
```

**Relevance:** Directly applicable to candy-kit Theme improvement.

---

## charmbracelet/lipgloss: CSS Shorthand Resolution
**Source:** `docs/repo_map/charmbracelet_lipgloss.md`

`whichSidesInt` / `whichSidesBool` implement CSS shorthand (1 arg = all sides, 2 = vertical/horizontal, etc.).

**Lesson:** Reuse in candy-kit for Border::rounded() with per-side colors.

**Relevance:** Banner title positioning could use similar pattern.

---

## pterm: TextPrinter Interface
**Source:** `docs/repo_map/pterm_pterm.md`

pterm's `TextPrinter` interface with 8 formatting methods provides API consistency across all printers.

**Lesson:** All candy-kit output classes (StatusLine, Banner, Section, etc.) could implement a common interface.

**Relevance:** API consistency improvement opportunity.

---

## textualize/textual: Command Palette
**Source:** `docs/repo_map/textualize_textual.md`

Textual's command palette uses fuzzy search with custom `Provider` system for extensible commands.

**Lesson:** SugarCraft could add a `CommandPalette` component to sugar-prompt or candy-kit.

**Relevance:** Future interactive CLI enhancement.

---

## c9s/CLIFramework: Shell Completion
**Source:** `docs/repo_map/c9s_CLIFramework.md`

PHP-based completion generation with ZshGenerator/BashGenerator emitting `compdef` and `complete` scripts.

**Lesson:** Directly implementable in PHP for SugarCraft CLI tools.

**Relevance:** High — provides completion for PHP-based CLIs without Go dependency.

---

# Recommended Roadmap

## Immediate Wins (1-2 days)

1. **Theme::adaptive()** — Add adaptive theme factory with closure for dark/light detection
2. **HelpText wide-char fix** — Replace `mb_strlen()` with `Width::string()` in renderRows()
3. **Theme derivation methods** — Add `withSuccess()`, `withError()`, etc.

## Medium-term Improvements (1-2 weeks)

4. **Banner title anchor positioning** — Add Position parameter to Banner::title()
5. **Section right-side label** — Add `Section::headerWithRightLabel()`
6. **Logo re-theming fix** — Store raw ASCII, apply color on render
7. **Theme enumeration** — Add `Theme::names()`, `Theme::fromName()`

## Major Architectural Upgrades (1-2 months)

8. **Shell completion generator** — Create `candy-complete` or `sugar-complete` library
9. **HelpText markdown rendering** — Integrate candy-shine for markdown descriptions
10. **Option pattern for HelpText** — Add Options bag for fine-grained control
11. **Command palette system** — Design and implement fuzzy command palette

## Experimental Ideas (future)

12. **Interactive prompts** — Confirm, Select, Multi-select (requires candy-pty integration)
13. **Manpage generation** — Integrate roff rendering
14. **Auto-version wiring** — CLI framework integration point

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|---|---|---|---|---|
| Theme::adaptive() | High | Low | Low | **Critical** |
| HelpText wide-char fix | Medium | Low | Low | **Critical** |
| Shell completion generator | High | High | Medium | **High** |
| Banner title positioning | Medium | Medium | Low | **High** |
| Section right-side label | Medium | Low-Medium | Low | **High** |
| Theme derivation methods | Medium | Low | Low | **High** |
| Logo re-theming fix | Low-Medium | Low | Low | Medium |
| Theme enumeration | Low-Medium | Low | Low | Medium |
| HelpText markdown rendering | Medium | Medium | Low | Medium |
| Option pattern for HelpText | Low-Medium | Medium | Medium | Medium |
| Command palette system | Medium | High | High | Medium |
| Interactive prompts | Medium | High | Medium | Low |
| Manpage generation | Medium | High | Medium | Low |
| Auto-version wiring | Medium | Medium | Low | Low |

---

# Final Strategic Assessment

CandyKit is a well-scoped, focused library providing the presentation layer of the SugarCraft monorepo. It ports the core visual components of `charmbracelet/fang` (StatusLine, Banner, Section, Stage, HelpText) with solid fidelity to the upstream design while adapting to PHP idioms (static factories, nullable theme parameters, immutable final classes).

**Competitive position:** The library fills a genuine gap in the PHP CLI ecosystem — there is no comparable pure-presentation library that provides styled status lines, section headers, banners, and help text without requiring Symfony Console or another framework.

**Key differentiators vs pterm:** pterm is Go-only and focused on zero-config defaults. candy-kit is PHP-native and framework-agnostic, fitting naturally into the SugarCraft ecosystem.

**Key differentiators vs CLIFramework:** CLIFramework is a full CLI application framework with hierarchical commands, while candy-kit is purely presentation-focused. They can complement each other.

**Main gaps relative to upstream:**
- CLI integration features (shell completions, manpage generation, automatic version wiring) which require a CLI framework opinion that candy-kit correctly declines to have
- Light/dark auto-detection — the most impactful missing feature

**The biggest unlock:** Adding `Theme::adaptive()` for automatic light/dark detection would bring feature parity with fang's theme system and significantly improve out-of-the-box UX for users on different terminal backgrounds.

**Recommendation:** Ready for production use. Prioritize Theme::adaptive() and HelpText wide-char fix as immediate wins, then shell completion generator as the next major investment.
