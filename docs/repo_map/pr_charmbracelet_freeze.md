# Second-Stage Ecosystem Intelligence Report: charmbracelet/freeze

## Metadata
- **Report Date**: 2026-05-27
- **Upstream**: `charmbracelet/freeze` (Go, ~4.6k stars, MIT)
- **Analysis Stage**: Second-stage (issues, PRs, discussions mining)
- **Sugarcraft Port**: `sugarcraft/candy-freeze` (v1 ready, PHP 8.3+)

---

## 1. Repository Overview

**charmbracelet/freeze** generates publication-ready images of code and terminal output. The core pipeline is: input → Chroma lex/tokenize → SVG render → (optional) PNG/WebP convert. Configuration is layered: built-in JSON presets (`base`, `full`) → user config (`~/.config/freeze/user.json`) → CLI flags.

Key technical components:
- **Chroma** (Go lexer) for syntax highlighting with 100+ themes
- **PTY execution** via `xpty` + `shellwords` for `--execute` flag
- **Dual PNG backend**: `rsvg-convert` (fast, system binary) / `resvg-go` WASM (portable fallback)
- **Interactive TUI** via `huh` (charm's form library)
- **SVG manipulation** via `xml/etree`
- **Window decorations**: macOS traffic lights, rounded corners, drop shadows

**Ecosystem stats**: 74 total issues, 213 PRs (130 merged), 20 contributors, 6 releases. Average issue close: 18 days, PR: 6 days.

---

## 2. Existing Sugarcraft Mapping

The `candy-freeze` port (`sugarcraft/candy-freeze`) is **v1 ready** and covers:

| freeze Component | candy-freeze Class | Status |
|-----------------|-------------------|--------|
| ANSI SGR parsing | `AnsiParser.php` | ✅ Parity |
| SVG rendering | `SvgRenderer.php` | ✅ Parity |
| PNG rendering | `PngRenderer.php` | ✅ Parity (GD-based) |
| Chroma theme loading | `ChromaThemeLoader.php` | ✅ Parity + VS Code |
| Window decorations | `WindowStyle.php` enum | ✅ More styles (5 vs 1) |
| Line numbers | `SvgRenderer::render()` | ✅ Parity |
| Drop shadow | SVG `<filter>` feDropShadow | ✅ Parity |
| Border radius | `rx` attribute | ✅ Parity |
| Language detection | `LanguageDetector.php` | ✅ Heuristic-based |
| Font embedding | `withFont()` TTF base64 | ✅ Parity |
| Ligatures | `font-variant-ligatures` | ✅ Parity |

**Fundamental gap**: freeze uses **Chroma** for live code tokenization; candy-freeze requires **pre-colored ANSI input** (no embedded lexer).

---

## 3. Previously Identified Gaps

From the first-stage `charmbracelet_freeze.md`:
1. **No syntax highlighter** — candy-freeze needs pre-colored input
2. **No WebP output** — only SVG + PNG
3. **No interactive TUI** — CLI-only configuration
4. **GD loses per-segment background** — bitmap fonts limit PNG quality
5. **No PTY execution** — no `--execute` equivalent
6. **No user config persistence** — stateless per invocation
7. **CLI exposes only SVG** — no `--format` flag for PNG
8. **No line range CLI** — no `--lines` flag for cropping
9. **Fixed 0.6 font aspect ratio** — approximate cell width

---

## 4. High-Signal Open Issues

### Issue #203: "SIGSEGV: segmentation violation" On Debian-based Distributions
**Reactions**: 👍 4 (high engagement)
**State**: Open since April 2025

**Problem**: `resvg-go` WASM renderer causes `SIGSEGV` / "runtime: split stack overflow" on Ubuntu 22.04/24.04, PopOS, Fedora 42, NixOS, and WSL2. The crash occurs in `wazero` running Rust WASM for `TinySkiaPixmapNew`.

**Root cause chain**:
```
freeze png.go:L59 → resvg-go.NewPixmap() → TinySkiaPixmapNew() → wazero.Run() → wasm exit with ExitCodeGrowMemory / ExitCodeIntegerOverflow
```

**Workaround**: Install `librsvg2-bin` (`rsvg-convert`) which bypasses the resvg path entirely. Performance improves from **31.4s to 1.3s** with librsvg.

**Implications for candy-freeze**:
- **Direct risk**: LOW — candy-freeze uses GD instead of resvg-go WASM for PNG. This is an architectural advantage.
- **Risk if using FFI/ImageMagick**: HIGH — any external rasterization via WASM or FFI could hit similar issues.
- **Strategic lesson**: Prefer pure-PHP or pure-extension rasterization over WASM or complex FFI chains. GD is boring but stable.

### Issue #250: `--execute` fails on Windows
**State**: Open (March 2026)

**Problem**: `shellwords` parsing breaks on Windows; commands like `ls`, `dir`, `Get-Clipboard` fail with "executable file not found in %PATH%". Also affects `--execute` with pipes/redirects on all platforms (issue #67).

**Related issue #231**: Aliases (`&&`, `|`, `;`, etc.) are not supported by `--execute`. Users must wrap in `bash -c "..."`.

**Root cause**: `shellwords.Parse()` splits by space but doesn't handle shell operators. The upstream `github.com/caarlos0/go-shellwords` is abandoned and was replaced with `github.com/mattn/go-shellwords` in PR #248, but the fundamental issue is that freeze's `--execute` doesn't run through a shell — it parses and executes directly.

**Implications for candy-freeze**:
- **Direct risk**: MEDIUM — candy-freeze has no PTY execution at all (`--execute` is a gap). If added, must handle shell parsing carefully.
- **Strategic opportunity**: A proper PHP PTY wrapper via `candy-pty` could provide better cross-platform shell execution than Go's approach.

### Issue #181: Set specific font style for font family
**State**: Open (March 2025)
**Reactions**: Multiple users confirmed same issue

**Problem**: Cannot specify font weight/style (e.g., "JetBrains Mono SemiBold" vs "JetBrains Mono"). On macOS, font variants with same family name but different style are not selectable.

**Root cause**: Chroma's SVG formatter doesn't expose font-weight/font-style attributes. The fix is `--font.style` and `--font.weight` flags that map to SVG `font-weight` and `font-style` attributes on text elements.

**Implications for candy-freeze**:
- **Direct risk**: LOW — candy-freeze already has `withFont()` that could be extended with `withFontStyle()` and `withFontWeight()`.
- **Strategic opportunity**: SugarCraft can implement this more cleanly by passing font style/weight separately to renderers.

### Issue #93: CJK/Chinese characters garbled in PNG
**State**: Open (May 2024), referenced by PR #242

**Problem**: Chinese text renders correctly in SVG output but becomes garbled in PNG. Users must embed a CJK font via `--font.file` AND `--font.family` together, but the PNG conversion path (`png.go`) hardcodes only JetBrains Mono fonts in `fontdb.LoadFontData()` — custom fonts are ignored in the PNG path.

```go
// png.go - hardcoded, ignores user's --font.file
err = fontdb.LoadFontData(font.JetBrainsMonoTTF)
err = fontdb.LoadFontData(font.JetBrainsMonoNLTTF)
```

**User workaround**: Rebuild freeze with custom fonts embedded at compile time.

**Implications for candy-freeze**:
- **Direct risk**: LOW — candy-freeze's SVG path embeds fonts via base64 `@font-face` (works for all Unicode). PNG path uses GD bitmap fonts (limitation but different mechanism).
- **Defensive lesson**: If implementing font embedding in a rasterization path, the font must be loaded and available before any text rendering — not after initialization.

### Issue #65: Shadow settings produce transparent background
**State**: Open (April 2024)

**Problem**: When using shadow options, the background becomes completely transparent in PNG output (on Windows specifically). The shadow filter in SVG interacts badly with resvg's background handling.

**Workaround**: Using `rsvg-convert` instead of resvg fixes both shadow rendering and speed.

**Implications for candy-freeze**:
- **Direct risk**: LOW — candy-freeze uses GD for PNG and SVG filters for shadow. Different mechanism.
- **Defensive lesson**: SVG shadow filters require the background rect to be painted before the filter is applied; ordering matters in the SVG element tree.

### Issue #90: Make user config the default config
**State**: Open (April 2024)
**Reactions**: 👍 3

**Problem**: Users must pass `-c user` every time to use their saved configuration. The user config at `~/.config/freeze/user.json` should be the default when no config is specified.

**Implications for candy-freeze**:
- **Direct risk**: LOW — candy-freeze already stateless, but if config persistence is added, should default to user config.
- **Strategic opportunity**: An `--interactive` mode (currently missing) would generate and persist user config naturally.

---

## 5. Important Closed Issues

### Issue #73: Windows commands don't work (Closed - Won't Fix direction)
**Problem**: Commands like `ls`, `dir` fail on Windows because `exec.LookPath` doesn't find them in `%PATH%`. Root cause is that `shellwords` parses command strings but the Windows shell doesn't auto-resolve executables without `.exe` extension in some contexts.

**Resolution approach**: Documentation update recommending full paths or `cmd /c` prefix. The fundamental issue is cross-platform shell behavior.

### Issue #76: Show command in execute output (Implemented via PR #81, #101)
**Feature**: Users wanted the executed command visible in the output image (like a real terminal screenshot showing `$ command`).

**Implementation**: `--show-cmd` flag added.

**Implications for candy-freeze**:
- **Direct risk**: N/A — candy-freeze has no `--execute`. If added, should implement similar `--show-cmd` behavior.

### Issue #70: Multi-language highlighting (PR #79 merged)
**Feature**: Support `jinja-sql` style mixed language highlighting (Jinja template + SQL in same file).

**Implementation**: `--language jinja,sql` flag for multiple lexers.

**Implications for candy-freeze**:
- **Direct risk**: HIGH — This requires a lexer, which candy-freeze deliberately doesn't include. This is a fundamental capability gap.
- **Strategic opportunity**: Could integrate with a PHP lexer (Geshi, php-lexer) to enable similar multi-language highlighting.

### Issue #95: Add version flag (PR #99 merged)
**Feature**: `--version` / `-v` flag was missing.

**Resolution**: Added in v0.1.4.

---

## 6. Recurring Pain Points

### Pain Point 1: PNG Conversion Instability
**Frequency**: Mentioned in ~10+ issues
**Severity**: High (crashes, transparency bugs, performance)

The dual-backend PNG system (rsvg-convert fast path vs resvg-go WASM fallback) causes:
- Segfaults on Linux distros (resvg-go/wazero bug)
- Transparent backgrounds with shadows (resvg rendering bug)
- 24x performance difference (31.4s vs 1.3s)
- Missing fonts in PNG output (rsvg uses system fonts, ignores embedded)

**Pattern**: Freeze relies on external system tools or WASM for rasterization — both are less reliable than compiling a clean rasterizer. The maintainers acknowledge this by always recommending `rsvg-convert` as the workaround.

**Implications for candy-freeze**:
- GD is boring and slow but **stable and consistent** across platforms.
- If adding WebP or other formats, prefer pure-PHP encoding (e.g., `imagewebp()`) over external tool invocation.
- The "fast path / slow fallback" pattern is good for DX but requires careful error handling.

### Pain Point 2: Shell Command Parsing Complexity
**Frequency**: 5+ issues (#67, #73, #231, #250)
**Severity**: Medium

`--execute` has fundamental limitations:
- Doesn't expand aliases (`&&`, `|`, `;`, `||`)
- Doesn't handle shell built-ins consistently across platforms
- `shellwords` parsing breaks on complex commands with quotes
- Windows `exec.LookPath` behaves differently from Unix

**Pattern**: When building a tool that captures terminal output, the hardest part is not the rendering — it's reliably executing arbitrary shell commands and capturing their output. This is a notoriously hard problem (see `script` command, `asciinema`, `termcapture`).

**Implications for candy-freeze**:
- If implementing PTY capture, study how `asciinema` handles this — they've solved most of these problems.
- PHP's `proc_open()` with `pty` pseudo-terminal is possible but tricky on Windows.

### Pain Point 3: CJK / Non-ASCII Font Support
**Frequency**: 3+ issues (#93, related to #80, #83)
**Severity**: Medium

Custom font embedding works for SVG but PNG conversion path ignores custom fonts because:
1. The PNG path hardcodes JetBrains Mono font loading
2. System font loading (for librsvg) doesn't know about user-specified fonts
3. CJK fonts are 5-20MB — can't practically embed

**Pattern**: Font handling is split across SVG path (embedded fonts work) and PNG path (system fonts or bust). This is a architectural split that causes user confusion.

**Implications for candy-freeze**:
- candy-freeze embeds fonts via base64 in SVG `@font-face` — works correctly for SVG.
- PNG via GD uses bitmap fonts — no font embedding possible, only GD-built-in fonts.
- For true CJK support in PNG, would need `imagettftext()` with font file loaded — viable but adds complexity.

### Pain Point 4: Interactive Mode Missing Features
**Frequency**: 2 issues (#174, #135)
**Severity**: Low-Medium

Interactive mode (`--interactive`) lacks:
- Background color configuration (issue #174)
- Padding/margin fine-tuning feedback

**Pattern**: The interactive TUI form is powerful but not comprehensive. Users want more live preview and more settings.

**Implications for candy-freeze**:
- **Direct risk**: N/A — candy-freeze has no interactive mode.
- **Strategic opportunity**: Building an interactive TUI config mode would be a differentiator and is a planned future enhancement.

---

## 7. Frequently Requested Features

### Feature: Shell Autocompletion (#134)
**Status**: Open (Sept 2024)
**Reactions**: 👍 1 (but requested for gum, glow too)

**Request**: Add shell tab-completion for freeze flags, themes, languages.

**Status in upstream**: PR #215 exists as WiP.

**Implications for candy-freeze**: Shell completion is a low-effort, high-impact quality-of-life feature. Should implement `--generate-completion` flag.

### Feature: Publish to URL (#28)
**Status**: Open (March 2024)
**Reactions**: 👍 2

**Request**: `--publish` flag to upload image to a hosting service (like `vhs --publish`).

**Note**: This was implemented in `vhs`; freeze users want the same.

**Implications for candy-freeze**: Would require a PHP upload endpoint or integration with an image hosting API. Lower priority for library use.

### Feature: Default User Config (#90)
**Status**: Open (April 2024)
**Reactions**: 👍 3

**Request**: Make `~/.config/freeze/user.json` the default config when no `-c` flag is passed.

**Implications for candy-freeze**: If config persistence is added, should auto-load user config as default.

### Feature: Window Title from Filename (#107)
**Status**: Merged (PR #107)

**Implementation**: Input filename becomes window title bar content.

**Implications for candy-freeze**: Already not implemented — worth adding as `withWindowTitle()` option.

### Feature: Line Highlighting (#164)
**Status**: Open (Dec 2024)

**Request**: `--highlight` flag to highlight specific lines (for tutorial/contribution screenshots).

**Implications for candy-freeze**: Already implemented as `withHighlight($start, $end, $color)` in library API, but not exposed via CLI.

### Feature: Animation (#207)
**Status**: Open (May 2025)

**Request**: Animated GIF output to capture terminal sessions.

**Implications for candy-freeze**: Animation would require a frame sequence capture mechanism — significantly more complex. Not currently planned.

### Feature: Copy to Clipboard (#213)
**Status**: Open (June 2025)

**Request**: `--copy` flag to copy image to clipboard instead of saving to file.

**Implications for candy-freeze**: On Linux, `xclip`/`xcopy`; on macOS, `pbcopy`; on Windows, `clip`. Simple to implement as external command invocation.

---

## 8. Important PRs

### PR #248: Fix broken go-shellwords dependency (Merged Feb 2026)
**Author**: alexpevzner
**Impact**: Critical bugfix

`github.com/caarlos0/go-shellwords` became unavailable when the `caarlos0` account was deleted from GitHub. Replaced with `github.com/mattn/go-shellwords` which is the original upstream.

**Lesson**: When depending on personal GitHub accounts for critical infrastructure, fork and maintain your own copy, or use well-established community libraries with multiple maintainers.

**Implications for candy-freeze**:
- **Direct risk**: LOW — candy-freeze doesn't use shellwords.
- **Defensive lesson**: PHP's `proc_open()` + `shell_exec()` is standard and stable; no single-author dependencies.

### PR #242: Load system fonts for CJK/non-ASCII support (Open)
**Author**: Wangnov
**Status**: Open (Dec 2025)

Attempts to fix the CJK font loading issue by loading system fonts in the PNG path.

**Implications for candy-freeze**: Could study this approach for better non-ASCII font support.

### PR #215: Add tab-completion (WiP)
**Author**: S0AndS0
**Status**: Draft/inactive

**Implications for candy-freeze**: Tab completion is a good CLI feature to add.

### PR #40: Fix properly parse exec (Merged March 2024)
**Author**: caarlos0
**Impact**: Bugfix

Fixed shell command parsing — `gum format "foo bar"` was incorrectly split to `["gum" "format" "\"foo" "bar\""]` instead of `["gum" "format" "foo bar"]`.

**Lesson**: Simple space-splitting of shell commands is wrong; use a proper shellwords parser.

**Implications for candy-freeze**: If implementing `--execute`, must use proper shell parsing. PHP's ` escapeshellcmd()` and `exec()` are insufficient for complex commands.

### PR #83: Fix manually load font file for resvg-go (Not merged)
**Author**: lrh3321
**Status**: Never merged (appreciated but abandoned)

Attempted to fix font loading in the resvg-go PNG path by loading user-specified font files manually. Never merged — the approach was incomplete.

**Implications for candy-freeze**: The font loading architecture in freeze is tightly coupled to the WASM/FFI rendering path. PHP's approach (embedding in SVG, or loading GD fonts) is simpler and more reliable.

### PR #107: Add input file as window title (Draft)
**Author**: AlejandroSuero
**Status**: Draft/never merged

Implements auto-detecting filename and using it as window chrome title.

**Implications for candy-freeze**: Simple to implement; `LanguageDetector` already has the logic.

---

## 9. Architectural Changes

### Architecture Decision: Dual PNG Backend
**Before**: Single resvg-go WASM path
**After**: `rsvg-convert` (fast) → `resvg-go` WASM (fallback)

This is a pragmatic choice — rsvg-convert is fast (1.3s) but requires external dependency; resvg-go is portable (no external deps) but slower (31.4s) and unstable on some platforms.

**Architectural smell**: The fallback path (resvg-go WASM) is the source of most stability issues (segfaults, transparency bugs). A well-designed system would fail fast when the preferred backend is unavailable rather than falling back to an unstable alternative.

**Implications for candy-freeze**:
- GD is the only PNG backend — stable but lower quality.
- If adding more backends (WebP, JPEG), consider: prefer pure-PHP extensions over external tools or WASM.
- Any fallback path must be as stable as the primary path, or should fail clearly.

### Architecture Decision: Shellwords for Command Parsing
**Problem**: `xpty` executes commands but doesn't parse shell syntax.

**Solution**: Use `shellwords` library to split command string into argv array.

**Trade-off**: Works for simple commands but breaks on pipes, redirects, aliases, and shell built-ins.

**Pattern**: PTY execution libraries are low-level; they run a single command with args, not a shell. Adding shell syntax support requires running through `/bin/bash -c "..."` or equivalent, which introduces shell quoting complexity.

**Implications for candy-freeze**:
- If implementing `--execute`, must decide: simple command execution (limited) or full shell execution (complex but powerful).
- Full shell execution via `/bin/bash -c "..."` is the pragmatic choice; handle with `proc_open()` + `pty` on Unix.

### Architecture Decision: Embedded JetBrains Mono Fonts
**Rationale**: Ensures consistent code rendering in SVG output without relying on system fonts.

**Trade-off**: Adds ~200KB to binary size. CJK fonts can't be embedded due to size.

**Design pattern**: Embed one good monospace font, let users override with `--font.file` for special needs.

**Implications for candy-freeze**: Already follows this pattern with TTF base64 embedding in SVG. GD bitmap fonts don't support embedding.

---

## 10. Performance Discussions

### Performance: PNG Encoding is Slow (Issue #90)
**Observation**: Even with `-c full` (not `-c base`), PNG encoding takes 31.4s without rsvg-convert vs 1.3s with rsvg-convert. User config (`-c user`) is faster because it has simpler settings.

**Root cause**: The resvg-go WASM path renders at 192 DPI with high-quality text rendering, which is computationally expensive. rsvg-convert uses system librsvg which is heavily optimized.

**Benchmark from issue #90**:
- `-c full` (macOS-style with window, border, shadow): 31.4s
- `-c user` (minimal): ~same speed as base
- With `rsvg-convert`: 1.3s (all configs)

**Pattern**: Visual complexity (shadows, borders, rounded corners) doesn't slow down SVG generation but dramatically slows PNG rasterization via WASM.

**Implications for candy-freeze**:
- GD rendering is slower than vector SVG but doesn't have the WASM overhead.
- If performance matters, recommend SVG output over PNG.
- For CI/batch rendering, consider async job queue for PNG generation.

### Performance: Go Workspace Resolution
**Observation**: Go 1.8.0 was being resolved even when `resvg-go` specified `wazero v1.7.3` in go.mod. Dependency resolution can silently upgrade.

**Implication**: Reproducible builds require lockfiles or vendoring.

**Implications for candy-freeze**: Composer handles this better with `composer.lock` — always commit lockfiles.

---

## 11. Extensibility Discussions

### Extensibility: Plugin System for Themes
**Current**: Themes are JSON files loaded at runtime.

**Request**: A plugin system for custom themes, lexers, and output formatters.

**Not implemented** — freeze is a focused single-purpose tool. Plugin architecture would increase complexity significantly.

**Implications for candy-freeze**:
- Theme loading from files (Chroma, VS Code) is already extensible.
- For PHP, implementing `ThemeLoaderInterface` allows third-party theme packages.
- Plugin for syntax highlighting lexers is a bigger effort — requires lexer abstraction.

### Extensibility: Custom Output Formats
**Request**: JPEG output, GIF output, PDF output.

**Not implemented** — SVG and PNG only. Maintainers prefer keeping the tool focused.

**Implications for candy-freeze**:
- JPEG via GD (`imagejpeg()`) is trivial to add.
- PDF via TCPDF or Dompdf would be a new renderer.
- Focus on SVG + PNG first; others on request.

---

## 12. API/UX Complaints

### Complaint: No `--version` Flag (Issue #95)
**Severity**: Minor
**State**: Fixed in v0.1.4

Users needed `--version` for health-check scripts and plugin integrations.

**Implications for candy-freeze**: Already has version info; ensure CLI always exposes `--version`.

### Complaint: Unclear Theme List (Issue #74)
**Severity**: Minor

Users couldn't find which themes are available. Documentation points to Chroma but the actual theme list is in Chroma's repo.

**Fix**: PR #104 added link to theme showcase page.

**Implications for candy-freeze**: Already ships 5 built-in themes. Should document which Chroma themes are loadable and link to theme previews.

### Complaint: Error Messages for --execute are Poor (Issue #119)
**Severity**: Medium
**State**: Fixed in v0.1.4

`--execute` failures gave cryptic errors. Improved in v0.1.4.

**Implications for candy-freeze**: If implementing `--execute`, ensure errors are descriptive and actionable.

### Complaint: Default Mono Font is Not Mono-Space (#211)
**Severity**: Minor
**State**: Open

The default "monospace" font may not actually be monospaced on all systems.

**Implication**: Font fallbacks can be unpredictable. Prefer embedded fonts for guaranteed rendering.

**Implications for candy-freeze**:
- Default to a specific embedded font, not system font discovery.
- SVG embedding ensures consistent rendering.

---

## 13. Migration Problems

### Migration: Upgrading resvg-go
**Problem**: Issue #203 mentions upgrading `resvg-go` to latest commit to get newer `wazero` version, but Go resolved to 1.8.0 anyway (semver shenanigans). The upgrade didn't fix the segfault issue.

**Lesson**: WASM dependencies are sensitive to version resolution quirks. Test thoroughly after any WASM library upgrade.

**Implications for candy-freeze**: Avoid WASM dependencies in PHP. GD and native extensions are more predictable.

### Migration: Shellwords to go-shellwords
**Problem**: PR #248 replaced the abandoned `caarlos0/go-shellwords` with `mattn/go-shellwords`. The API is the same but the package path changed.

**Lesson**: For critical parsing libraries, consider forking and maintaining rather than depending on single-maintainer repos.

**Implications for candy-freeze**: PHP's `shell_exec()` and `exec()` are standard library — no external dependency risk.

---

## 14. Clever Fixes & Workarounds

### Workaround: librsvg Installation
**Problem**: resvg-go WASM causes segfaults on many Linux distributions.

**Solution**: Install `librsvg2-bin` (Debian/Ubuntu) or `librsvg` via Homebrew (macOS). The `rsvg-convert` binary is used instead of resvg-go, bypassing the crash.

**Performance gain**: 31.4s → 1.3s

**Why it works**: The code already had `rsvg-convert` as the preferred fast path; it just wasn't documented prominently. Users discover it through issue comments.

**Implications for candy-freeze**: Document that SVG output is recommended for reliability; PNG via GD is available but lower quality.

### Workaround: Rebuild with Custom CJK Font
**Problem**: Chinese characters garbled in PNG output.

**Solution**: User edited `font/font.go` to embed a CJK font:
```go
//go:embed MeowSans.ttf
var MeowSansTTF []byte

// In png.go:
fontdb.LoadFontData(font.MeowSansTTF)
```
Rebuild and replace the binary.

**Implication**: Users who need CJK support must recompile. This is a build-time decision, not runtime.

**Implications for candy-freeze**: For CJK support, could ship with a small CJK font subset embedded, or document how to build a custom version.

### Workaround: Wrap Commands in bash -c
**Problem**: `--execute` doesn't handle pipes/redirects.

**Solution**: `freeze --execute "bash -c 'cat hi.txt | grep foo'"` works because bash parses the command line.

**Pattern**: The workaround is to use bash explicitly. This sidesteps the shellwords parsing issue entirely.

**Implications for candy-freeze**: If implementing PTY execution, always invoke through `/bin/bash -c` to get proper shell semantics.

---

## 15. Community Workarounds

### Docker Wrapper (S0AndS0/dockerized-charmbracelet_freeze)
**Solution**: Containerized freeze for consistent execution across environments.

**Dockerfile approach**: Installs librsvg for fast PNG conversion, provides entrypoint for running freeze in CI.

**Implication**: For CI environments, Docker ensures consistent behavior. Sugarcraft could provide an official Docker image.

### Neovim Plugin (freeze-code.nvim, Issue #95)
**Author**: AlejandroSuero

A Neovim plugin that wraps freeze for taking code screenshots from within the editor.

**Integration pattern**: The plugin checks for `freeze` existence and version via `--version`. Calls freeze as a subprocess with appropriate flags.

**Implication**: CLI tools that integrate with editors need `--version` and `--help` flags, consistent exit codes, and stdin/stdout pipe support.

### mise/tap Integration (Discussion #7650)
**Problem**: `mise install charmbracelet/freeze` fails without `aqua:` prefix because mise treats repo names without prefix as asdf plugins.

**Solution**: Use `mise install aqua:charmbracelet/freeze`.

**Implication**: Tool installation should be documented for major version managers (Homebrew, apt, rpm, aur, aqua, nix).

---

## 16. Maintainer Guidance Patterns

### Pattern: "Happy to Expedite Review"
In response to issue #93 (CJK fonts), maintainer said: "if anyone wants to send a PR we'll be happy to give it an expedited review."

**Interpretation**: Maintainers are receptive to well-scoped bug fixes but have limited bandwidth for new features. Community contributions are welcome for pain points.

**Implication**: For SugarCraft, submitting focused bug fix PRs is more likely to get merged than large feature PRs.

### Pattern: "Can't Embed CJK Font"
Maintainer response to CJK issue: "due to the size of CJK fonts we _probably_ can't embed one that covers CJK."

**Interpretation**: Binary size and bundle size matter. Embedded fonts are a trade-off between functionality and distribution size.

**Implication**: SugarCraft should be careful about embedding large font files. SVG rendering with base64 fonts works but bloats the SVG file (~200KB for JetBrains Mono).

### Pattern: "Will Look Into It"
Maintainer response to shadow transparency bug: "Hey @iamjackg, thanks for flagging this. Will look into this soon!"

**What happened**: The issue is still open (April 2024), indicating that while acknowledged, it hasn't been prioritized.

**Implication**: Some bugs are known but not fixed due to prioritization. SugarCraft should prioritize stability in its rendering paths.

### Pattern: Reference to Testscripts
In PR #40, maintainer noted: "we could use testscripts for these integration tests I think, so we could get coverage of them too" referencing `rogpeppe/go-internal/testscript`.

**Implication**: Integration testing of CLI tools benefits from testscript framework. SugarCraft should use similar approach for CLI testing.

---

## 17. Rejected Ideas Worth Revisiting

### Idea: `--publish` Flag (Issue #28)
**Request**: Publish images to a URL (like vhs has).

**Status**: Not implemented

**Reason**: Would require hosting infrastructure, auth, and maintenance.

**Worth revisiting for SugarCraft**: Could integrate with imgur API or similar free hosting. Lower maintenance than self-hosting.

### Idea: Plugin System
**Request**: Extensible theme/lexer/output system.

**Status**: Not implemented

**Reason**: Adds complexity, maintenance burden, API stability requirements.

**Worth revisiting for SugarCraft**: Theme loading is already plugin-like (ChromaThemeLoader, VsCodeThemeLoader both implement `ThemeLoaderInterface`). Could formalize this as PSR-style interface.

### Idea: Animation/GIF Output (Issue #207)
**Request**: Animated GIF capture of terminal sessions.

**Status**: Not implemented

**Reason**: Complex to implement correctly; requires frame sequence capture and timing metadata.

**Worth revisiting for SugarCraft**: Lower priority than static image capture. Would require PTY capture + frame timing + GIF encoding.

### Idea: Default User Config (Issue #90)
**Request**: Make user config the default when no `-c` flag passed.

**Status**: Not implemented

**Reason**: Unknown — likely backward compatibility concerns or prioritization.

**Worth revisiting for SugarCraft**: Implement this. When adding `--interactive` mode, save config and auto-load it as default.

---

## 18. Problems Likely Relevant To Sugarcraft

### Problem: Font Rendering Consistency
**Upstream finding**: Default "monospace" font isn't always monospaced; CJK fonts don't render correctly in PNG; custom font weights (SemiBold) not selectable.

**Why it matters for SugarCraft**: PHP's GD has very limited font support (5 bitmap fonts). `imagettftext()` handles TTF but requires font file on disk. SVG embedding works but makes files larger.

**Recommendation**: Prioritize SVG output as primary format. Document font embedding limitations. For PNG, use `imagettftext()` with explicit font file path.

### Problem: Shell Command Execution is Hard
**Upstream finding**: `--execute` has had 5+ issues related to command parsing, Windows compatibility, shell operators, and aliases.

**Why it matters for SugarCraft**: If adding PTY capture (`--execute` equivalent), must handle shell parsing correctly.

**Recommendation**: Use PHP's `proc_open()` with `/bin/bash -c` for full shell semantics. On Windows, could use `powershell -Command` or document WSL requirement.

### Problem: Cross-Platform PTY Behavior
**Upstream finding**: Works on Unix, fails on Windows. Same issue affects all PTY tools (asciinema, termcapture, etc.).

**Why it matters for SugarCraft**: PHP's PTY support is limited. `proc_open()` with `pty` type is Unix-only.

**Recommendation**: Document Windows limitations clearly. Could use `candy-pty` (which wraps `php-pty` extension or uses pseudo-TTY via `proc_open()`).

### Problem: Rasterization Quality vs Simplicity
**Upstream finding**: rsvg-convert is fast and high-quality; resvg-go WASM is slow and sometimes broken; GD is ubiquitous but low-quality.

**Why it matters for SugarCraft**: The trade-off between quality, dependencies, and portability affects user experience.

**Recommendation**: SVG-first is correct for SugarCraft. For PNG, GD is universally available but lower quality. Document the trade-off. Could optionally use ImageMagick CLI (`convert`) or Ghostscript for higher quality if available.

---

## 19. Features Sugarcraft Should Consider

### 1. CLI Tab Completion
**Priority**: Medium
**Effort**: Low

`--generate-completion` flag for bash/zsh/fish completions. SugarCraft's CLI utilities should all support this.

### 2. `--show-cmd` Equivalent
**Priority**: Medium
**Effort**: Low (if PTY capture is implemented)

Show the executed command in the output image. Useful for documentation showing "terminal screenshots."

### 3. Window Title from Filename
**Priority**: Low-Medium
**Effort**: Low

Auto-detect input filename and use as window title bar content. Already exists in freeze via PR #107.

### 4. Line Highlighting CLI Exposure
**Priority**: Medium
**Effort**: Low

`withHighlight()` exists in API but not exposed via CLI. Add `--highlight start:end:color` flag.

### 5. Background Color Configuration
**Priority**: Medium
**Effort**: Medium

Add `--background` flag to CLI. Currently only available via Theme.

### 6. Interactive TUI Mode
**Priority**: Low
**Effort**: High

`--interactive` mode for no-code configuration, saving to user config. This is a major feature gap.

### 7. User Config Persistence
**Priority**: Low
**Effort**: Medium

Save last-used settings to `$XDG_CONFIG_HOME/candy-freeze/user.json`, auto-loading on next run. Depends on interactive mode.

### 8. WebP Output
**Priority**: Low
**Effort**: Low

Add `WebpRenderer` using `imagewebp()` (ext-gd). Trivial if GD is available.

### 9. Copy to Clipboard
**Priority**: Low
**Effort**: Low

`--copy` flag using platform-specific clipboard tools (`xclip`, `pbcopy`, `clip`).

### 10. CJK Font Subset Embedding
**Priority**: Low
**Effort**: High

If Chinese/Japanese/Korean support is important, embed a small CJK font subset (~500KB) for common characters. Alternatively, document how to build custom version.

---

## 20. Architectural Lessons

### Lesson 1: Rasterization Backend Matters More Than Expected
Freeze has spent significant engineering time on PNG/WebP rasterization problems (segfaults, transparency, fonts, performance). The SVG generation is stable; the rasterization backend is the source of instability.

**For SugarCraft**: Invest in a reliable PNG rasterization path. GD is boring but stable. External tools (ImageMagick, rsvg-convert) are powerful but introduce dependencies and failure modes. FFI/WASM is powerful but unstable on some platforms.

### Lesson 2: PTY Execution is a Separate Problem
The `--execute` flag's complexity (10+ issues) is not in rendering or styling — it's in reliably capturing terminal output across platforms and shell configurations.

**For SugarCraft**: PTY capture is a distinct concern from image rendering. If implementing `--execute`, isolate it in `candy-pty` and thoroughly test on Windows, macOS, and Linux.

### Lesson 3: Font Handling is Split-Brain
Freeze's SVG path (Chroma embedded fonts) and PNG path (rsvg/system fonts) have different font handling. This causes user confusion and bugs (custom fonts work in SVG but not PNG).

**For SugarCraft**: If implementing multiple output formats, ensure font handling is consistent across them. For PNG via GD, use `imagettftext()` with the same font file specified by `--font.file`.

### Lesson 4: User Config Should Auto-Load
The request to make user config the default (issue #90) indicates that persistent configuration is expected but not intuitive.

**For SugarCraft**: When implementing config persistence, make the saved config the implicit default. Document this behavior.

### Lesson 5: WASM Dependencies Are Fragile
The resvg-go/wazero WASM stack causes segfaults across Linux distributions. This is a known class of problem — WASM runtimes have platform-specific quirks.

**For SugarCraft**: Avoid WASM dependencies. If FFI is needed for graphics, prefer stable, well-tested FFI bindings over WASM.

---

## 21. Defensive Design Lessons

### Defensive Lesson 1: Test on Multiple Platforms
Freeze's issues with Windows (command parsing, font rendering) and Linux distros (WASM segfaults) show that cross-platform testing is essential.

**For SugarCraft**: Test on Ubuntu, macOS, Windows (via WSL or native). Use GitHub Actions matrix for CI across platforms.

### Defensive Lesson 2: Fail Fast on Unavailable Backends
Freeze falls back from rsvg-convert to resvg-go, and resvg-go crashes. The fallback should either work perfectly or fail clearly.

**For SugarCraft**: For each optional backend, detect availability and fail with a clear error message if the backend is both required and unavailable. Don't silently fall back to a broken path.

### Defensive Lesson 3: Document External Dependencies
The README doesn't mention that `librsvg` dramatically improves performance. Users discover this through issue comments.

**For SugarCraft**: Document all external dependencies (GD, ImageMagick, etc.) and their impact on features and performance.

### Defensive Lesson 4: Version Flag for Integration
Plugin authors (Neovim plugin, etc.) need `--version` to check installation and compatibility.

**For SugarCraft**: Every CLI tool should have `--version` / `-v` flag.

### Defensive Lesson 5: Changelog for Breaking Changes
v0.2.0 changed `line_numbers` to `show_line_numbers` (more descriptive but breaking).

**For SugarCraft**: When stable, maintain a changelog. When renaming configuration keys, support both old and new names (deprecation period).

---

## 22. Ecosystem Trends

### Trend 1: Image Generation from Code is Mainstream
Tools like Carbon, Ray.so, Shiki, and freeze indicate strong demand for code-to-image conversion. The use case spans documentation, social media, and education.

**Implication**: SugarCraft's candy-freeze fills a real need. The market is established.

### Trend 2: Terminal Output Capture is Specialized
`--execute` is a niche feature compared to static code screenshots. Most users want code images, not terminal output images.

**Implication**: Prioritize code-to-image (requires lexer) over terminal capture. For terminal capture, PTY execution is complex — consider focusing on ANSI input parsing (already done).

### Trend 3: Theme Ecosystem is Fragmented
Freeze uses Chroma themes (100+), but VS Code themes are more popular. The theme documentation gap (issue #74) shows users struggle to find compatible themes.

**Implication**: Support both Chroma and VS Code theme loading (already done). Document how to use existing editor themes.

### Trend 4: Interactive TUIs for Configuration
The `--interactive` mode in freeze (and similar in gum, glow, vhs) shows that CLI tools with complex configuration benefit from interactive TUI configuration.

**Implication**: Adding interactive mode to candy-freeze would improve DX significantly. Use `candy-sprinkles` form components.

### Trend 5: Embedding Fonts is Standard Practice
Both freeze and candy-freeze embed JetBrains Mono in some form. This ensures consistent rendering.

**Implication**: Continue embedding a default monospace font. SVG embedding via base64 is the right approach for PHP.

---

## 23. Strategic Opportunities

### Opportunity 1: PHP Syntax Highlighting Integration
**Upstream gap**: freeze has Chroma for syntax highlighting. candy-freeze requires pre-colored input.

**Opportunity**: Integrate a PHP lexer (Geshi, highlight.js via FFI, or Python bridge to pygments) to enable live syntax highlighting.

**Approach**: Create a `SyntaxHighlighterInterface` and implement adapters for available highlighters. This would close the "no lexer" gap.

### Opportunity 2: Better Cross-Platform PTY
**Upstream gap**: `--execute` is problematic on Windows and with shell operators.

**Opportunity**: PHP's `proc_open()` with `bash -c` is more portable than Go's direct exec. Could provide reliable PTY capture via `candy-pty`.

### Opportunity 3: Interactive TUI Configuration
**Upstream gap**: freeze's interactive mode uses huh (Go). candy-freeze has no interactive mode.

**Opportunity**: Build `--interactive` using `candy-sprinkles` form components with live SVG preview. This would be a unique feature (upstream's interactive mode doesn't show live preview).

### Opportunity 4: Cloud/Hosting Integration
**Upstream gap**: `--publish` not implemented.

**Opportunity**: For SugarCraft, could integrate with simple hosting (imgur API, GitHub Gist, etc.) for quick sharing. Lower maintenance than freeze's planned approach.

### Opportunity 5: Theme Marketplace
**Upstream gap**: Users can't easily find/share themes.

**Opportunity**: SugarCraft could host a theme gallery (like the VS Code marketplace) where users upload Chroma/VS Code themes tagged for freeze. Even a simple repo of community themes would add value.

---

## 24. Cross-Ecosystem Pattern Matches

### Pattern: asciinema/termcapture Pipeline
**Similar tool**: asciinema records terminal sessions; termcapture captures terminal output.

**Common problem**: Both struggle with cross-platform PTY execution and shell operator parsing.

**Lesson**: The `--execute` problem is solved by asciinema's approach (full terminal emulator, not just command execution). For SugarCraft, consider whether PTY capture needs to record a session or just execute single commands.

### Pattern: Shiki/Geist Code-to-Image
**Similar tool**: Shiki (syntax highlighter that uses TextMate/VS Code themes) and Geist (Vercel's code screenshot tool).

**Common trend**: Code-to-image tools all struggle with font embedding, CJK support, and theme loading.

**Lesson**: Geist uses Playwright for rendering — a browser engine. This ensures consistency but adds complexity. SugarCraft's SVG approach is simpler but less consistent with browser rendering.

### Pattern: Raycast/Alfred Extensions
**Similar tool**: Many macOS tools offer "Screenshot code" actions.

**User expectation**: One-click code screenshot from any context.

**Lesson**: Integration with editors (VS Code, Neovim, JetBrains) drives adoption. SugarCraft's CLI should integrate well with common editor workflows.

---

## 25. High ROI Recommendations

### Recommendation 1: Add `--generate-completion` Flag
**ROI**: High — low effort, high user satisfaction for CLI users.

### Recommendation 2: Implement `--highlight` CLI Flag
**ROI**: High — the library API already supports it; CLI exposure is trivial.

### Recommendation 3: Add `--background` Flag to CLI
**ROI**: Medium — simple addition, improves usability.

### Recommendation 4: Document Extension Dependencies Clearly
**ROI**: High — prevents user confusion about what features require which extensions.

### Recommendation 5: Create SyntaxHighlighter Interface
**ROI**: High (strategic) — enables live syntax highlighting, closing the main capability gap.

### Recommendation 6: Use ImageMagick/FFI for Higher-Quality PNG (Optional)
**ROI**: Medium — improves PNG quality but adds dependency complexity.

### Recommendation 7: Consider Interactive Mode (Long-term)
**ROI**: Medium — high effort but significant DX improvement and feature parity with upstream.

### Recommendation 8: Add `--version` Flag if Missing
**ROI**: High — trivial to implement, required for integration with other tools.

---

## Appendix: Key Upstream Files Analyzed

- `main.go` — CLI entry, config loading, rendering orchestration
- `png.go` — Dual-backend PNG conversion (libsvgConvert, resvgConvert)
- `ansi.go` — SGR dispatcher for ANSI terminal output rendering
- `config.go` — Config struct, XDG config loading
- `pty.go` — PTY execution via xpty + shellwords
- `svg/svg.go` — SVG DOM utilities (shadow, clip, corners, dimensions)
- `font/font.go` — Embedded JetBrains Mono TTF files
- `interactive.go` — huh-based interactive config form
- Issues: #203, #250, #231, #222, #181, #93, #90, #73, #70, #67, #65, #95, #74, #76, #134, #28, #80, #83, #107, #213, #207
- PRs: #248, #242, #215, #83, #40, #79, #81, #101, #99, #104, #107, #113
