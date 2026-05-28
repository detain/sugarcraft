# Overview

**candy-freeze** (`sugarcraft/candy-freeze`) is a v1-ready PHP port of `charmbracelet/freeze` that converts code or terminal output (ANSI SGR sequences) into SVG or PNG screenshots. It occupies a unique niche in SugarCraft: an *output-edge* library that produces static image files rather than interactive TUIs, making it complementary to (not overlapping with) the core TUI rendering pipeline.

**Biggest opportunity areas**:
1. Syntax highlighting integration — the single largest capability gap vs upstream
2. Interactive TUI configuration mode — upstream's `--interactive` via huh is entirely absent
3. PNG quality improvements — GD bitmap fonts limit quality; `imagettftext()` path is unexplored
4. WebP output — trivial via `imagewebp()` but entirely absent

**Biggest missing capabilities**:
1. No embedded lexer/syntax highlighter (requires pre-colored ANSI input)
2. No PTY execution (`--execute` equivalent)
3. No user config persistence
4. No interactive TUI configuration
5. No line range cropping in CLI
6. No WebP output format

---

# Internal Capability Summary

## Current Architecture

```
Input text (ANSI SGR codes)
         │
         ▼
AnsiParser::parse() ───────────► list<Segment>
  ├─ CSI SGR (color/style)        ──► fg, bg, bold, italic, underline
  ├─ OSC (title, hyperlinks)     ──► silently skipped
  └─ plain text                   ──► passed through

Segments
  │
  ▼
SvgRenderer / PngRenderer
  ├─ Per-segment <rect> (bg) + <text> (content with fg/bold/italic/underline)
  └─ Window chrome (traffic lights / title bar)
```

**Key design decision**: Unlike `charmbracelet/freeze` which uses **Chroma** Go library to tokenize source code, candy-freeze requires input already carrying ANSI SGR codes. This is a deliberate trade-off — porting a full lexer to PHP would be massive. The `ChromaThemeLoader` and `VsCodeThemeLoader` are present only for window chrome/gutter theming, not for live syntax highlighting.

## Current Features

| Feature | Status |
|---------|--------|
| SVG-first code screenshot rendering | ✅ No ext-gd required |
| PNG rendering via GD | ✅ |
| ANSI SGR parsing (16/256/24-bit fg+bg, bold, italic, underline) | ✅ |
| Chroma theme file loader | ✅ |
| VS Code theme file loader | ✅ |
| 5 built-in themes (dark, light, dracula, tokyoNight, nord) | ✅ |
| 5 window decoration styles (Macos, WindowsTerminal, ITerm2, Hyper, None) | ✅ |
| Drop shadow via SVG `<filter>` | ✅ |
| Rounded corners via `rx`/`ry` | ✅ |
| Line numbers gutter | ✅ |
| Line highlighting range-based | ✅ |
| TTF font embedding via base64 `@font-face` | ✅ |
| Ligature control via `font-variant-ligatures` | ✅ |
| Language detection (shebang, extension, content heuristics) | ✅ |
| Interactive CLI (stdin/stdout, flags) | ✅ |
| i18n support (15 locales) | ✅ |
| Immutable fluent builders | ✅ |

## APIs

**SvgRenderer** (primary):
```php
SvgRenderer::dark() | light() | dracula() | tokyoNight() | nord()
    ->withTheme(Theme $theme)
    ->withLineNumbers(bool $show)
    ->withWindow(WindowStyle $style)
    ->withPadding(int $padding)
    ->withShadow(bool $show)
    ->withBorder(bool $show)
    ->withBorderRadius(int $radius)
    ->withLigatures(bool $enable)
    ->withFont(string $path)
    ->withHighlight(int $start, int $end, ?string $color)
    ->render(string $ansiInput): string  // returns SVG XML
```

**PngRenderer** (secondary):
- Mirrors SvgRenderer API but produces GD bitmap output
- **Critical limitation**: GD bitmap fonts don't support per-segment background — only frame background works

**Theme Loaders**:
```php
ChromaThemeLoader::loadFile(string $path): Theme
VsCodeThemeLoader::loadFile(string $path): Theme
Theme::byName(string $name): Theme  // case-insensitive with normalization
```

**LanguageDetector**:
```php
LanguageDetector::detect(string $code): string  // "php", "bash", "python", ... or "text"
LanguageDetector::detectFromFilename(string $filename): string
```

## Rendering Systems

**SvgRenderer** (`src/SvgRenderer.php`, 413 lines):
1. `Ansi::strip($line)` computes visible character count (no TTF metrics needed)
2. Cell size = `fontSize * 0.6` wide, `fontSize * lineHeight` tall
3. SVG dimensions computed from cols/lines
4. Emits `<defs>`: shadow filter, optional embedded font
5. Emits background `<rect>` with `rx` and `stroke`
6. Emits window chrome (traffic lights/title bar)
7. Per-line: line-highlight `<rect>`, gutter `<text>`, per-segment `<rect>` (bg) + `<text>`

**PngRenderer** (`src/PngRenderer.php`, 373 lines):
- Uses GD bitmap fonts (`imagestring`, font 5 = 8×16)
- Shadow via semi-transparent filled rectangle offset
- Traffic lights via `imagefilledellipse()`
- **Per-segment background not supported** — GD bitmap font rendering limitation

## Extension Systems

None currently. Theme loading could be formalized as a `ThemeLoaderInterface` for third-party extensions.

## Strengths

1. **SVG-only requires no extensions** — primary path is pure PHP string concatenation, no GD/ImageMagick/FFI needed
2. **More window styles than upstream** — 5 styles vs freeze's implicit macOS-only
3. **Theme loading from standard formats** — Chroma and VS Code themes already supported
4. **Line highlighting** — `withHighlight()` for tutorial/contribution screenshots
5. **Immutable fluent builders** — safe for concurrent use
6. **i18n coverage** — 15 locales
7. **CLI usability** — stdin/file input with all options via flags
8. **No WASM/FFI dependencies** — avoids the instability that plague freeze's resvg-go path

## Weaknesses

1. **No embedded lexer** — requires pre-colored ANSI input, fundamentally limits use cases
2. **No WebP output** — only SVG + PNG
3. **No interactive TUI** — CLI-only, no `--interactive` equivalent
4. **GD loses per-segment background** — bitmap font limitation in PNG path
5. **No PTY execution** — no `--execute` equivalent
6. **No user config persistence** — stateless per invocation
7. **CLI only exposes SVG** — PNG only via PHP library API
8. **No line range CLI flag** — `withHighlight()` exists but not `--lines` in CLI
9. **Fixed 0.6 font aspect ratio** — `cellW = fontSize * 0.6` approximation; true monospace varies by font
10. **Fixed 0.6 font aspect ratio** — approximate; varies by font

---

# Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|---------------------------|----------|
| `charmbracelet/freeze` (upstream) | Critical | Chroma lexer, PTY execution, interactive TUI, XDG config, WebP, `--execute` shell parsing | P0 |
| `charmbracelet/vhs` | High | `.tape` DSL format for scripting terminal demos, theme fuzzy matching, window bars | P1 |
| `charmbracelet/bubbletea` | High | Elm architecture for interactive TUI if implementing `--interactive` mode | P1 |
| `charmbracelet/glamour` | Medium | Cascading style system, block stack for margin/indent handling | P2 |
| `charmbracelet/lipgloss` | Medium | Style system patterns, CSS-like shorthand, color utilities | P2 |
| `ratatui/ratatui` | Medium | Cassowary layout constraint solver, buffer diffing, widget patterns | P2 |
| `charmbracelet/x/ansi` | Medium | ECMA-48 ANSI parser state machine, SGR handling | P2 |
| `charmbracelet/x/vt` | Low | Virtual terminal emulator, cell-based buffer with damage tracking | P3 |
| `php-tui/php-tui` | Medium | PHP-native TUI framework with extension architecture | P2 |
| `charmbracelet/pop` | Low | Form state machine pattern (State enum driving navigation) | P3 |

---

# Feature Gap Analysis

## Critical

### 1. No Syntax Highlighter
**Title**: Embed a PHP syntax highlighter for live code coloring
**Description**: Unlike upstream freeze which includes Chroma with 100+ lexers, candy-freeze requires input pre-colored with ANSI SGR codes. This fundamentally limits the tool to terminal output capture and tools like pygments/highlight.
**Why it matters**: The primary use case (code screenshots) is inaccessible without a separate preprocessing step. Users must run code through pygments or similar to generate ANSI-colored input.
**Source repo**: `charmbracelet/freeze` uses `charmbracelet/chroma` for lexing
**Source PR/issue**: freeze issue #70 (multi-language highlighting) + upstream's Chroma integration
**Implementation ideas**:
- Create `SyntaxHighlighterInterface` with adapters for available highlighters (Geshi, highlight.js via FFI, Python bridge to pygments)
- Reuse `candy-shine`'s regex tokeniser pattern (extends `SyntaxHighlighter`) for PHP/JS/HTML/SQL
- Focus on 5-10 most-used languages (PHP, JS, Python, Go, Bash, SQL, JSON)
**Estimated complexity**: High — lexer design is non-trivial
**Expected impact**: Closes the single largest capability gap; enables direct code-to-image without pre-processing

### 2. PNG Background Color Rendering
**Title**: Enable per-segment background in PNG output via `imagettftext()`
**Description**: `PngRenderer` currently uses GD bitmap fonts (`imagestring`, font 5 = 8×16) which cannot render per-segment background colors. Only the frame background is visible.
**Why it matters**: ANSI input with background colors (e.g., diff output, error highlighting) loses all background information in PNG output.
**Source repo**: `charmbracelet/freeze` (PNG path ignores custom fonts in freeze; studied via pr_charmbracelet_freeze.md)
**Implementation ideas**:
- Use `imagettftext()` instead of `imagestring()` for TTF rendering
- Draw per-character background rect before text using filled rectangles
- Requires precise character positioning with `imagettfbbox()`
**Estimated complexity**: Medium — requires reimplementing text rendering pipeline
**Expected impact**: Full ANSI fidelity in PNG output

## High Value

### 3. Interactive TUI Configuration Mode
**Title**: Add `--interactive` TUI mode using candy-sprinkles
**Description**: Upstream freeze has a full interactive configuration via `huh` (Bubble Tea form library). candy-freeze is CLI-only with all options as flags.
**Why it matters**: Interactive configuration with live preview is significantly more user-friendly than memorizing flag combinations.
**Source repo**: `charmbracelet/freeze` (interactive.go using huh)
**Source PR/issue**: freeze issues #174, #135 (interactive mode missing features)
**Implementation ideas**:
- Build form using `candy-sprinkles` TextInput, Select, Confirm components
- Show live SVG preview as settings change
- Persist final config to `$XDG_CONFIG_HOME/candy-freeze/user.json`
**Estimated complexity**: Medium — requires candy-sprinkles integration
**Expected impact**: Major UX improvement; feature parity with upstream

### 4. User Config Persistence
**Title**: Persist last-used settings to `~/.config/candy-freeze/user.json`
**Description**: Upstream freeze saves settings to `~/.config/freeze/user.json` and auto-loads them. candy-freeze is stateless per invocation.
**Why it matters**: Users who customize settings want them remembered across invocations.
**Source repo**: `charmbracelet/freeze` (config.go, XDG config loading)
**Implementation ideas**:
- On `--interactive` save, write `$XDG_CONFIG_HOME/candy-freeze/user.json`
- Auto-load on startup if exists and no explicit flags passed
- Support `--config` flag to override
**Estimated complexity**: Low
**Expected impact**: Improved UX; enables `--interactive` mode persistence

### 5. Line Range CLI Flag
**Title**: Add `--lines start:end` or `--highlight start:end:color` to CLI
**Description**: `withHighlight()` exists in the library API but `--lines` for cropping input is not exposed in CLI.
**Why it matters**: Users must use the library API to highlight specific lines; CLI users cannot.
**Source repo**: `charmbracelet/freeze` (issue #164, feature request for `--highlight`)
**Implementation ideas**:
- Add `--lines` flag for input cropping: `--lines 10,20`
- Add `--highlight` flag for background highlight: `--highlight 1:5:#ffcc00`
- Parse and apply via `withHighlight()` before render
**Estimated complexity**: Low
**Expected impact**: Closes a CLI/API parity gap

### 6. WebP Output
**Title**: Add `WebpRenderer` using `imagewebp()` (ext-gd)
**Description**: Upstream freeze supports WebP via rsvg-convert. candy-freeze only supports SVG and PNG.
**Why it matters**: WebP offers better compression than PNG with transparency support.
**Source repo**: `charmbracelet/freeze` (issue #207, WebP request)
**Implementation ideas**:
- Create `WebpRenderer` mirroring `PngRenderer` but using `imagewebp()`
- Add `--format svg|png|webp` flag to CLI
- Reuse PNG rendering pipeline, just change output encoding
**Estimated complexity**: Low — trivial if GD is available
**Expected impact**: Modern format support; smaller file sizes

## Medium

### 7. Tab Completion
**Title**: Add `--generate-completion` flag for bash/zsh/fish
**Description**: Freeze has a WiP PR (#215) for shell tab-completion. candy-freeze lacks this.
**Why it matters**: CLI usability for power users.
**Source repo**: `charmbracelet/freeze` (pr #215, issue #134)
**Implementation ideas**:
- Add `--generate-completion bash|zsh|fish` flag
- Generate completion script using `cliche/tab-completion` or custom
- SugarCraft CLI utilities should all support this
**Estimated complexity**: Low
**Expected impact**: Improved CLI DX

### 8. CJK/Non-ASCII Font Support in PNG
**Title**: TTF-based rendering in PNG for proper CJK character support
**Description**: Issue #93 in freeze shows CJK characters garbled in PNG. candy-freeze uses GD bitmap fonts which have no CJK support.
**Why it matters**: International users need CJK rendering.
**Source repo**: `charmbracelet/freeze` (issue #93, #80, #83)
**Implementation ideas**:
- See gap #2 — `imagettftext()` approach also fixes CJK
- Could ship with a small CJK font subset embedded
- Document `--font` flag for custom CJK font
**Estimated complexity**: Medium
**Expected impact**: International adoption

### 9. Window Title from Filename
**Title**: Auto-detect input filename and use as window chrome title
**Description**: PR #107 in freeze implements this; candy-freeze has no equivalent.
**Why it matters**: Makes screenshots more contextual without manual `--title` flag.
**Source repo**: `charmbracelet/freeze` (pr #107)
**Implementation ideas**:
- Add `--window-title` flag or auto-detect from input filename
- Pass through to `withWindowTitle()` if API exists
**Estimated complexity**: Low
**Expected impact**: Minor UX improvement

### 10. Font Style/Weight Selection
**Title**: Add `withFontStyle()` and `withFontWeight()` to renderers
**Description**: Issue #181 in freeze — cannot specify "JetBrains Mono SemiBold" vs regular.
**Why it matters**: Users with specific font variants cannot select them.
**Source repo**: `charmbracelet/freeze` (issue #181)
**Implementation ideas**:
- Add `withFontStyle(string $style)` and `withFontWeight(int $weight)` to renderers
- Map to SVG `font-style` and `font-weight` attributes
- For PNG with imagettftext, pass as parameters
**Estimated complexity**: Low
**Expected impact**: Better font control

---

# Algorithm / Performance Opportunities

## Current Approach vs External

### Rasterization Backend
**Current**: GD (`imagepng()`) for PNG rendering — ubiquitous but low quality for anti-aliased text
**External**: freeze uses rsvg-convert (fast, high-quality) → resvg-go WASM (portable fallback, but unstable)
**Why external is better**: librsvg is heavily optimized for text rendering; GD bitmap fonts look crude by comparison
**Tradeoffs**: GD is available everywhere; rsvg-convert requires system installation
**Applicability**: If adding higher-quality PNG, use ImageMagick CLI (`convert`) or document GD limitations clearly

### Font Aspect Ratio
**Current**: Fixed `cellW = fontSize * 0.6` approximation
**External**: freeze uses actual TTF metrics via `xml/etree` and font measurement
**Why external is better**: True monospace aspect ratios vary by font (JetBrains Mono ~0.6, but others differ)
**Tradeoffs**: Measurement requires loading TTF and computing metrics — significant complexity
**Applicability**: For monospace fonts, 0.6 is a reasonable approximation; could be configurable

### PNG Performance
**Current**: No performance data available
**External**: freeze's resvg-go WASM path takes 31.4s; rsvg-convert takes 1.3s
**Why external is better**: WASM has overhead; rsvg-convert is a compiled binary
**Tradeoffs**: PHP's GD is synchronous but doesn't have WASM startup overhead; hard to compare directly
**Applicability**: SVG output should be recommended for speed; PNG via GD is for compatibility

### Theme Matching
**External (vhs)**: Levenshtein distance for fuzzy theme name matching
**Current**: Exact match with `Theme::byName()` — no fuzzy matching
**Why external is better**: `vhs` handles typos gracefully (e.g., "tokyonight" → "tokyo-night")
**Tradeoffs**: Minimal implementation cost; beneficial for usability
**Applicability**: Should add fuzzy matching for theme names

---

# Architecture Improvements

## 1. Formalize ThemeLoaderInterface
Currently `ChromaThemeLoader` and `VsCodeThemeLoader` are concrete classes. Formalizing as an interface allows third-party theme packages:
```php
interface ThemeLoaderInterface {
    public function loadFile(string $path): Theme;
    public function supports(string $format): bool;
}
```
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — extensibility discussion

## 2. Separate Renderer Interface
`SvgRenderer` and `PngRenderer` share most logic. Extract common `RendererInterface`:
```php
interface RendererInterface {
    public function render(string $input): string;
    public function withTheme(Theme $theme): self;
    // ... fluent options
}
```
**Source**: `docs/repo_map/ratatui_ratatui.md` — `Widget` trait pattern

## 3. Add ImagettftextRenderer (optional PNG path)
Create a TTF-based PNG renderer using `imagettftext()` for:
- Per-segment background colors
- CJK character support
- Proper font variant selection
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — CJK font issue (#93)

## 4. Background/Separation of Concerns
Currently `SvgRenderer` does ANSI parsing + SVG generation. Consider:
- `AnsiRenderer` that produces structured `Segment[]` array
- `SvgSerializer` that takes `Segment[]` and produces SVG
- Allows testing rendering separately from parsing
**Source**: `docs/repo_map/charmbracelet_bubbletea.md` — Elm architecture separation

---

# API / Developer Experience Improvements

## 1. Add `--format` flag for PNG
Currently CLI only outputs SVG; PNG is library-only:
```php
candyfreeze input.php --format png -o out.png
```

## 2. Add `--version` flag
Standard for CLI integration (Neovim plugins, etc. check version)
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — issue #95

## 3. Add `--copy` flag (clipboard)
```php
candyfreeze input.php | xclip -selection clipboard  # current workaround
candyfreeze input.php --copy  # proposed
```
On Linux: `xclip`/`xsel`; macOS: `pbcopy`; Windows: `clip`
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — issue #213

## 4. Add `--show-cmd` for PTY output
If `--execute` is implemented, show the command in the output image
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — pr #76, #81, #101

## 5. Better Error Messages
freeze issue #119 shows `--execute` failures give cryptic errors. Ensure descriptive errors for all failure modes.
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — issue #119

---

# Documentation / Cookbook Opportunities

## 1. Theme Previews Page
freeze issue #74: Users can't find which themes are available. SugarCraft should document:
- Which Chroma themes are loadable
- Link to theme showcase/preview pages
- Examples for VS Code theme conversion

## 2. Syntax Highlighting Guide
Document how to pre-colorize code with:
- pygments: `pygmentize -f terminal256 -O style=monokai input.py`
- highlight: `highlight --out-format=ansi input.py`
- Any PHP-based approach

## 3. Cookbook: Integrating with Editors
- Neovim plugin pattern (like freeze-code.nvim)
- VS Code task for "screenshot this file"
- GitHub Actions for automated screenshots

## 4. FAQ: GD vs SVG Path
Explain when to use SVG (CI, git diffs, no extensions) vs PNG (transparency, wider compatibility)

---

# UX / TUI Improvements

## 1. Interactive TUI with Live Preview
Build `--interactive` using `candy-sprinkles` with live SVG preview as options change.
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — issues #174, #135

## 2. Fuzzy Theme Matching
Add Levenshtein distance for theme names — `tokyonight` → suggests `tokyo-night`
**Source**: `docs/repo_map/charmbracelet_vhs.md` — theme fuzzy matching via `agnivade/levenshtein`

## 3. Terminal Background Detection
Use `candy-palette`'s `HasDarkBackground()` to auto-select dark/light theme
**Source**: `docs/repo_map/charmbracelet_lipgloss.md` — `HasDarkBackground()` pattern

## 4. Progress Feedback
For batch processing multiple files, show progress indicator

---

# Testing / Reliability Improvements

## 1. Golden File Testing for SVG Output
The `WindowStyleTest.php` tests SVG structure but could use golden files for full render comparison.
**Source**: `docs/repo_map/charmbracelet_x.md` — golden package for snapshot testing

## 2. Integration Tests for CLI
Add testscript-style integration tests for the CLI:
```bash
candyfreeze --theme dracula --line-numbers < input.php > output.svg
diff output.svg expected.svg
```
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — "we could use testscripts for these integration tests"

## 3. Cross-Platform Testing
Test on Ubuntu, macOS, Windows (WSL) — freeze issues show platform-specific bugs
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — Windows issues (#73, #250)

## 4. Test on Multiple PHP Versions
Ensure GD behavior is consistent across PHP 8.3+

---

# Ecosystem / Integration Opportunities

## 1. Docker Image
Provide official Docker image for consistent CI execution
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — S0AndS0/dockerized-charmbracelet_freeze

## 2. Editor Integrations
- VS Code extension that calls `candyfreeze` on current file
- Neovim plugin (like freeze-code.nvim)
- JetBrains "Screenshot Code" action

## 3. CI/CD Integration
GitHub Action template for:
- Automatically screenshot code on PR
- Generate API docs screenshots
**Source**: `docs/repo_map/charmbracelet_vhs.md` — vhs-action

## 4. Image Hosting Integration
Add `--publish` to upload to imgur or similar
**Source**: `docs/repo_map/pr_charmbracelet_freeze.md` — issue #28 (rejected but requested)

## 5. sugar-vhs Companion
Port the VHS `.tape` format as a SugarCraft library for scripted terminal demos
**Source**: `docs/repo_map/charmbracelet_vhs.md` — "sugar-vhs" concept

---

# Notable PRs / Issues / Discussions

## freeze Issue #203: SIGSEGV on Debian-based Distributions
**Summary**: resvg-go WASM causes crashes on Ubuntu 22.04/24.04, PopOS, Fedora 42, NixOS, WSL2
**Lesson**: Prefer pure-PHP or pure-extension rasterization over WASM. GD is boring but stable.
**Relevance**: candy-freeze already uses GD — this validates the choice.

## freeze Issue #250: --execute fails on Windows
**Summary**: shellwords parsing breaks on Windows; commands like `ls`, `dir` fail with "executable file not found"
**Lesson**: PTY execution is a notoriously hard problem (see asciinema, termcapture)
**Relevance**: If implementing `--execute`, must handle shell parsing via `/bin/bash -c` on Unix

## freeze Issue #93: CJK/Chinese characters garbled in PNG
**Summary**: PNG path hardcodes JetBrains Mono; custom fonts ignored in PNG path
**Lesson**: Font loading must happen before text rendering, not during initialization
**Relevance**: Same issue affects candy-freeze's GD path

## freeze PR #248: Fix broken shellwords dependency
**Summary**: `caarlos0/go-shellwords` became unavailable when account was deleted
**Lesson**: For critical parsing libraries, fork and maintain or use well-established community libraries
**Relevance**: PHP's `shell_exec()` and `exec()` are standard library — no external dependency risk

## freeze Pain Point: PNG Conversion Instability
**Summary**: 10+ issues related to segfaults, transparency bugs, performance in PNG backend
**Lesson**: External tools (ImageMagick, rsvg-convert) and WASM introduce failure modes
**Relevance**: GD is stable but lower quality; document trade-offs

## vhs Fuzzy Theme Matching
**Summary**: Uses Levenshtein distance for typo-tolerant theme lookup
**Lesson**: SugarCraft should add fuzzy matching for theme names
**Relevance**: Low-effort improvement to CLI usability

## glamour Block Stack Pattern
**Summary**: Maintains stack of block elements during AST traversal for dynamic indent/margin computation
**Lesson**: Could be applied to candy-freeze for more sophisticated window chrome/layout handling
**Relevance**: Reference only — not directly applicable to freeze's rendering model

---

# Recommended Roadmap

## Immediate Wins (1-2 days each)

1. **Add `--format png` flag to CLI** — wire up PngRenderer, trivial effort
2. **Add `--generate-completion` flag** — shell completion for themes/languages
3. **Add `--lines` and `--highlight` CLI flags** — expose existing library functionality
4. **Add fuzzy theme name matching** — Levenshtein distance for typos
5. **Document extension dependencies clearly** — GD vs SVG path trade-offs in README

## Medium-Term Improvements (1-2 weeks each)

6. **Add user config persistence** — `$XDG_CONFIG_HOME/candy-freeze/user.json`
7. **Build interactive TUI** — `--interactive` using candy-sprinkles with live preview
8. **Create `ThemeLoaderInterface`** — formalize theme loading for extensibility
9. **Add `--copy` clipboard flag** — platform-specific clipboard tools
10. **Add `--window-title` or auto-detect from filename**

## Major Architectural Upgrades (weeks)

11. **Build `SyntaxHighlighterInterface` + adapters** — close the lexer gap (largest effort)
12. **Create `ImagettftextRenderer`** — TTF-based PNG for per-segment background + CJK
13. **Add WebpRenderer** — using `imagewebp()`
14. **Build PTY capture via candy-pty** — `--execute` equivalent

## Experimental Ideas

15. **sugar-vhs port** — `.tape` format parser for scripted terminal demos
16. **Image hosting integration** — `--publish` to imgur/GitHub Gist
17. **Animated GIF output** — capture terminal sessions as animation (requires PTY)
18. **Docker image** — official container for CI consistency

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|------------|--------|-------------|------|---------|
| Add `--format png` to CLI | High | Low | Low | P0 |
| Add `--generate-completion` | Medium | Low | Low | P0 |
| Add `--lines`/`--highlight` to CLI | Medium | Low | Low | P0 |
| Fuzzy theme matching | Medium | Low | Low | P1 |
| User config persistence | Medium | Medium | Low | P1 |
| Interactive TUI with live preview | High | High | Medium | P1 |
| ThemeLoaderInterface | Medium | Low | Low | P1 |
| `--copy` clipboard flag | Low | Low | Low | P2 |
| Window title from filename | Low | Low | Low | P2 |
| Font style/weight selection | Low | Low | Low | P2 |
| imagettftext PNG renderer | High | Medium | Medium | P2 |
| SyntaxHighlighterInterface + adapters | Critical | High | High | P2 |
| WebP output | Medium | Low | Low | P2 |
| CJK support via TTF | Medium | Medium | Medium | P3 |
| PTY capture (`--execute`) | High | High | High | P3 |
| Docker image | Low | Low | Low | P3 |
| Image hosting `--publish` | Low | Medium | Medium | P3 |
| Animated GIF output | Medium | High | High | P3 |

---

# Final Strategic Assessment

**candy-freeze** is a well-executed 🟢 v1-ready port that successfully delivers SVG-first code screenshots without requiring any graphics extensions. Its primary strength is stability — by using pure PHP string concatenation for SVG and GD for PNG, it avoids the WASM/FFI instability that plagues upstream freeze's PNG conversion path.

However, the package has a fundamental architectural limitation: **no embedded syntax highlighter**. Unlike freeze which includes Chroma for live code tokenization, candy-freeze requires pre-colored ANSI input. This is a deliberate trade-off documented in the architecture, but it significantly limits the tool's accessibility for the primary use case (code screenshots). Users must run code through pygments or similar as a preprocessing step.

The most impactful improvements would be:

1. **Syntax highlighting integration** — Creating a `SyntaxHighlighterInterface` with adapters for popular PHP highlighters (Geshi, or a Python bridge to pygments) would close the single largest capability gap. This is high effort but transforms the tool from "ANSI-to-image" to "code-to-image".

2. **Interactive TUI mode** — Building `--interactive` using `candy-sprinkles` with live SVG preview would be a major UX differentiator. Upstream's interactive mode (via huh/Bubble Tea) doesn't include live preview — SugarCraft could exceed upstream here.

3. **PNG quality improvements** — The GD bitmap font limitation means PNG loses per-segment background colors. Implementing `imagettftext()`-based rendering would close this gap and also enable CJK support.

4. **WebP output** — Trivial to add via `imagewebp()` but missing entirely. Modern format with better compression + transparency.

The package's position at the "output edge" of the rendering pipeline means it doesn't overlap with the core TUI libraries — it's complementary rather than competing. The i18n support (15 locales), immutable fluent builders, and multiple window decoration styles are polished touches that exceed upstream in some areas.

**Defensive notes from upstream issues**:
- WASM dependencies are fragile — avoid them (candy-freeze already does)
- Shell command parsing is notoriously hard — use `/bin/bash -c` if implementing PTY capture
- PNG rasterization is the source of most instability — GD is boring but stable
- Font handling is split-brain across SVG/PNG paths — ensure consistency if adding more backends
- User config should auto-load as default when added

The package is production-ready for its SVG output use case. The main gaps are around PNG quality, interactive configuration, and live syntax highlighting — all addressable with medium-to-high effort.
