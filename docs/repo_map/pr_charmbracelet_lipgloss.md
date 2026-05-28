# Second-Stage Ecosystem Intelligence: charmbracelet/lipgloss

## 1. Repository Overview

| Attribute | Value |
|---|---|
| **URL** | https://github.com/charmbracelet/lipgloss |
| **Stars** | ~11,000 |
| **Language** | Go |
| **License** | MIT |
| **Module (v2)** | `charm.land/lipgloss/v2` |
| **Go Version** | Go 1.25.0 (v2.0.0+) |
| **Contributors** | 50+ |
| **Releases** | 32 |
| **Latest Release** | v2.0.3 (2026-04-13) |
| **Default Branch** | main |
| **Open Issues** | ~108 |

**Core Description:** "Style definitions for nice terminal layouts. Built with TUIs in mind." Lip Gloss provides an expressive, declarative API for terminal rendering—CSS-like familiarity for the terminal. The v2 release (Feb 2026) removed the `Renderer` type entirely, making `Style` a pure value type and shifting color downsampling to the output layer.

---

## 2. Existing SugarCraft Mapping

| SugarCraft Lib | Namespace | Lipgloss Sub-package/Feature |
|---|---|---|
| **CandySprinkles** (`candy-sprinkles/`) | `SugarCraft\Sprinkles` | **Primary mapping:** The core `Style` type and all setters (`Bold`, `Italic`, `Foreground`, `Background`, `Padding`, `Margin`, `Border*`, `Align`, etc.), color system (`Color`, `Darken`, `Lighten`, `Complementary`, `Alpha`, `Blend1D`, `Blend2D`, `AdaptiveColor`), border presets, position types, measurement utilities (`Width`, `Height`, `Size`), text wrapping/joining/placement, layer compositing (`Layer`, `Compositor`, `Canvas`), `StyleRunes` |
| **SugarBits** (`sugar-bits/`) | `SugarCraft\Bits` | `table` sub-package → `Table.php` with `Column.php`, `SortState.php`, `Styles.php`; `list` sub-package → potential future `ItemList.php` enhancements |
| **CandyPalette** (`candy-palette/`) | `SugarCraft\Palette` | `charmbracelet/colorprofile` → terminal color detection (`HasDarkBackground`, `BackgroundColor`, `LightDark`, `Complete`) — SugarCraft's `candy-palette` maps directly to `colorprofile` itself |
| **CandyShine** (`candy-shine/`) | `SugarCraft\Shine` | Adjacent: Lip Gloss handles ANSI styling; Glamour handles Markdown → these compose together in Charm apps |
| **SugarCharts** (`sugar-charts/`) | `SugarCraft\Charts` | `Blend2D` / color gradient functions could power heatmap cell coloring |
| **SugarPrompt** (`sugar-prompt/`) | `SugarCraft\Prompt` | Adjacent: `huh` forms use Lip Gloss for styling internally; styles compose in the Bubble Tea render loop |

**Key architectural insight:** Lip Gloss is the *foundation* that all other Charm libraries build upon. In SugarCraft, **CandySprinkles** (`candy-sprinkles/`) is the equivalent foundation layer that everything else (`sugar-bits`, `sugar-prompt`, etc.) imports for styling. SugarCraft's `candy-core` (Elm-architecture runtime) pairs with `candy-sprinkles` (styling) in the same way Bubble Tea pairs with Lip Gloss.

---

## 3. Previously Identified Gaps

From the first-pass `repo_map/charmbracelet_lipgloss.md`, the following gaps were identified:

1. **Go-specific implementation** — Cannot be directly used from other languages; SugarCraft must manually port every feature.
2. **Complex internal property system** — The `propKey` bitmask + dual `attrs` representation makes internals harder to follow.
3. **Global state in `compat` package** — `HasDarkBackground` and `Profile` initialized at package import time by reading stdin/stdout.
4. **No built-in syntax highlighting** — Lip Gloss handles ANSI SGR sequences but not syntax highlighting (Glamour handles that).
5. **No built-in markdown rendering** — Lip Gloss styles individual strings; doesn't parse markdown structure.
6. **Color blending is 1D around border perimeter only** — True rectangular gradients require custom iteration.
7. **Limited widget set** — No text input, scrollable viewports, progress bars, spinners, or other interactive components (those are in `bubbles` → `sugar-bits`).
8. **Hard dependency on Charmbracelet infrastructure** — `charmbracelet/x/ansi`, `charmbracelet/colorprofile`, `charmbracelet/ultraviolet` are all internal Charm libraries.

---

## 4. High-Signal Open Issues

### Issue #644: Proposal: Flexbox/Grid Layout Engine (charm-layout)
- **Author:** junhinhow (2026-04-01)
- **Reactions:** Active community interest
- **Summary:** Building complex TUI layouts with lipgloss requires manual calculations. `JoinHorizontal` and `JoinVertical` don't support proportional sizing. No way to say "sidebar 30%, main 70%". Terminal resize requires manual recalculation. No grid system for dashboard-style layouts.
- **Proposed Solution:** `charm-layout` — CSS Flexbox-inspired layout engine built on lipgloss with `Flex` (Row/Column direction, proportional ratios, configurable gap), `Grid` (N columns, auto-row distribution), `Responsive` (SetSize() recalculates everything), `Composable` (flex inside grid, grid inside flex).
- **Direct Risk to SugarCraft:** HIGH — If SugarCraft doesn't implement a proper layout system, users will face the same pain points. The `candy-sprinkles` library needs proportional/grid layout primitives.
- **Strategic Opportunity:** SugarCraft could implement a superior layout system from the start, avoiding the "every Bubble Tea app invents layout logic" problem.

### Issue #85: Formatting Lost on Linewrap
- **Author:** FredrikL (2022-06-03)
- **Labels:** bug
- **Milestone:** v1.2.0 (has been moved between milestones multiple times)
- **Reactions:** 👍 7
- **Age:** ~3+ years old, still open
- **Summary:** When rendering a long string with styles applied in a "box", text formatting is lost when lines wrap. The border resets the previous wrapped line style.
- **Workaround (from community):** Wrap the text _before_ styling it, instead of after.
- **Fix progress:** Maintainers indicate `cellbuf.Wrap` could fix this (refs: charmbracelet/x#350, lipgloss#479).
- **Direct Risk to SugarCraft:** HIGH — This is a core rendering bug that affects any styled text that wraps. `CandySprinkles` will need to implement proper wrapping that preserves style state across line breaks.
- **Strategic Opportunity:** Implementing proper style-preserving text wrap in PHP from the start is a chance to avoid this 3-year-old bug.

### Issue #194: BorderTop(false) Disappears Entire Border
- **Author:** haarts (2023-04-12)
- **Labels:** bug
- **Summary:** Setting `BorderTop(false)` (or any individual side) makes the entire border disappear instead of just that side. Once one side is set to `false`, other borders are treated as `false`.
- **Maintainer acknowledgment:** "Good catch! I'd classify this as a bug."
- **Related PR:** #614 (fix: render unset border sides when only some sides are disabled) — merged Feb 2026.
- **Direct Risk to SugarCraft:** MEDIUM — This was a long-standing bug now fixed upstream; SugarCraft should implement the corrected behavior.

### Issue #562: Emoji/Unicode Width Calculation Causes Layout Misalignment
- **Author:** kolkov (2025-09-04)
- **Summary:** The library incorrectly calculates display width of emoji, Unicode grapheme clusters, CJK characters, and ZWJ sequences, causing misaligned TUI layouts.
- **Maintainer response:** Claims this is a terminal emulator issue (referencing jeffquast.com, mitchellh.com writing on grapheme clusters). However, users have reproduced across multiple terminals (bash, fish, konsole) and claim ghostty (which handles graphemes correctly natively) still shows the issue in TUIOS.
- **Direct Risk to SugarCraft:** HIGH — Unicode width calculation is a fundamental issue. SugarCraft's `candy-sprinkles` uses `Width()` and `Height()` for layout; incorrect width measurement breaks all constrained layouts with international content.
- **Strategic Opportunity:** PHP's `mb_strwidth()` and `grapheme_strlen()` functions may handle this better than Go's `uniseg` — worth investigating.

### Issue #666: V2 Lipgloss.Width Does Not Match Output Width In Some Cases
- **Author:** pjkaufman (2026-04-15)
- **Summary:** `lipgloss.Width()` returns one value for a string, but rendering that many dashes produces different output length. Affects strings with certain Unicode characters (ﾟ Д etc.). This bubbles up from `ansi.StringWidth`, `runewidth-go`, and `uniseg` — all agree on the wrong value.
- **Direct Risk to SugarCraft:** HIGH — `CandySprinkles` uses width measurement for `MaxWidth`, text truncation, and table column sizing.

### Issue #605: Allow Multi-Character Corners on Borders
- **Author:** Grantmartin2002 (2026-02-02)
- **Labels:** enhancement
- **Summary:** `Border` struct truncates corners to 1 rune, but users want multi-character corners for custom border styles.
- **Direct Risk to SugarCraft:** LOW — Border customization is an edge case, but worth supporting in `CandySprinkles` if it doesn't complicate the API significantly.

### Issue #564: Dynamic Layout Not Behaving Like React-Style Flex
- **Author:** vinaycharlie01 (2025-09-05)
- **Summary:** Lip Gloss doesn't behave like React's flexbox for dynamic resizing and positioning. Expected proportional sizing, gap support, etc.
- **Direct Risk to SugarCraft:** HIGH — Same as #644; layout system inadequacy.

---

## 5. Important Closed Issues

### Issue #593: Regression — Markdown Redirection to Files Not Working in v2
- **Author:** adriens (2025-11-24)
- **State:** closed (completed)
- **Summary:** After upgrading to lipgloss v2, redirecting console output to a text file produces ANSI escape sequences instead of clean markdown.
- **Solution:** Use `lipgloss.Print` instead of `fmt.Print`. In v2, `Render()` always emits full-fidelity ANSI; downsampling happens at print layer via `colorprofile.Writer`.
- **Direct Risk to SugarCraft:** HIGH — SugarCraft must handle non-TTY output correctly (CI, pipes, file redirects). The v2 pattern (render always emits full ANSI; print layer downsamples) is the correct approach and SugarCraft should follow this.
- **Key Insight:** The v2 architecture shift is deliberate: separate rendering from output. This is a *feature*, not a regression.

### Issue #635: HasDarkBackground Hangs When No TTY Available
- **Author:** jedevc (2026-03-22)
- **State:** open (fix in PR #636)
- **Summary:** Importing `charm.land/lipgloss/v2/compat` causes program to hang when stdin is a pipe (e.g., `source <(./program)`). Related to OSC 11 background color query.
- **Fix:** PR #636 adds guard to avoid the query when stdin/stdout aren't TTYs.
- **Direct Risk to SugarCraft:** MEDIUM — The same pattern applies to any library that queries terminal capabilities. SugarCraft should guard TTY queries.

### Issue #383: Table Resizing Issue with \r\n in Cells
- **Author:** pachecot (2024-09-28)
- **State:** closed (completed)
- **Summary:** Table resize logic fails on carriage return + line feed (`\r\n`) sequences, adding spaces before newlines.
- **Root Cause:** `x/ansi.Wrap` wasn't trimming `\r` from `"\r\n"`.
- **Direct Risk to SugarCraft:** MEDIUM — SugarCraft's `SugarBits` table implementation should handle `\r\n` correctly.

### Issue #574: Table Width Doesn't Count Column Borders
- **Author:** AdrienHorgnies (2025-10-07)
- **State:** open (fix in PR #575)
- **Summary:** Table doesn't account for border width when computing whether content fits. A 10-character cell in a table with width=10 might overflow due to border characters.
- **Fix:** PR #575 minding borders when computing table width.
- **Direct Risk to SugarCraft:** MEDIUM — Table column sizing must include border widths.

### Issue #287: Weird Behaviour When Using Lipgloss in a Test
- **Author:** LaBatata101 (2024-04-30)
- **State:** closed (completed)
- **Summary:** Test output varies based on whether `go test` is run from the test directory or parent directory. Lipgloss defaults to no color when TTY detection fails.
- **Solution:** Use `lipgloss.SetColorProfile(termenv.TrueColor)` in tests.
- **Direct Risk to SugarCraft:** HIGH — SugarCraft tests need explicit color profile settings, otherwise snapshot tests produce different output depending on context.

### Issue #599: Latest x/ansi Package Breaks Compat with v2.0.0-beta.3
- **Author:** mrusme (2025-12-15)
- **State:** open
- **Summary:** `x/ansi v0.11.3` changed API (e.g., `te.Italic()` now requires `bool` arg), breaking lipgloss v2 beta.
- **Maintainer response:** Users should use `charm.land/lipgloss/v2@v2-exp`.
- **Direct Risk to SugarCraft:** LOW — SugarCraft doesn't depend on `x/ansi` directly; this is an upstream dependency issue.

---

## 6. Recurring Pain Points

1. **Terminal Background Detection Hangs/Timeouts** — `HasDarkBackground` (and `compat` package) queries the terminal via OSC 11, which hangs when stdin/stdout aren't TTYs. Multiple issues: #635, #86, #619 (partial reads fix).
2. **Unicode Width Calculation** — Width calculation for emoji, CJK, grapheme clusters is inconsistent across terminals (#562, #55, #666). The root cause is split between library measurement and terminal rendering.
3. **Text Wrapping Loses Styles** — Issue #85 (3+ years old) — wrapped text loses color/style after the first line. Workaround: wrap before styling.
4. **Global State in v1 Compatibility Layer** — The `compat` package reads stdin/stdout at import time, causing the "Lip Gloss and Bubble Tea fight over stdin/stdout" problem that v2 deliberately fixes.
5. **Table Column Sizing** — Tables don't properly account for borders (#574), wrapping (#383), or ellipsis (#493) in column width calculations.
6. **Per-Side Border Control** — Setting one side to `false` disables all borders (#194). This was fixed in PR #614.
7. **ANSI-in-Border Width Miscalculation** — When borders contain ANSI colors, width is miscalculated (#502, #332).

---

## 7. Frequently Requested Features

1. **Flexbox/Grid Layout System** (#644) — Proportional sizing, responsive layouts, gap support. This is the #1 most-requested feature.
2. **Unified Cross-Library Theming** (#643) — Define colors once, derive styles for lipgloss, glamour, bubbles, huh. 5 built-in themes (Gruvbox, Dracula, Catppuccin, TokyoNight) in POC.
3. **Multi-Character Border Corners** (#605) — Custom border strings longer than 1 rune.
4. **Style Inheritance/Composition** — In v2, styles don't automatically inherit background when you apply bold (#531). Users must use base style and derive.
5. **ANSI-Colored Border Support** (#502) — Borders with embedded color codes.
6. **Table Enhancements** — Explicit `.Wrap(false)` control, ASCII borders for non-TTY, markdown output format.
7. **Style Ranges for Syntax Highlighting** (#531 comment) — Apply different styles to different rune index ranges in a string.
8. **Fast Path for Color-Only Styles** (#81) — Optimize `Render()` when style only contains ANSI sequences (no padding/borders).

---

## 8. Important PRs

| PR | Title | Status | Significance |
|---|---|---|---|
| #636 | fix: Avoid background color query hang | open | Prevents hangs when stdin/stdout aren't TTYs |
| #619 | fix: handle partial reads in queryTerminal | merged | Fixes 80%+ timeout rate on Windows Terminal; affects start time |
| #592 | Improve performance of maxRuneWidth | merged | Switched from `uniseg` to `displaywidth` for better performance |
| #614 | fix: render unset border sides when only some sides are disabled | merged | Fixes #194 — per-side border control |
| #575 | fix(table): Mind borders when computing table width | merged | Fixes #574 — border width in column sizing |
| #507 | v2: remove custom renderer functions | merged | Major v2 architectural change — Renderer removed |
| #479 | (related to #85 fix using cellbuf.Wrap) | merged | Addressing formatting-lost-on-linewrap |
| #390 | fix(getLines): carriage return was not being removed with new lines | merged | Fixes #383 — \r\n handling |
| #496 | fix(table): fix ellipsis (…) not always showing correctly | merged | Fixes #493 |

---

## 9. Architectural Changes

### Lip Gloss v2 Major Architectural Shift (Feb 2026)

**Renderer Removal:** In v1, every `Style` carried a `*Renderer` pointer and the package maintained a global default renderer. Color downsampling happened inside `Style.Render()` via the renderer. In v2, **`Style` is a plain value type**. There is no renderer. Color downsampling is handled at the **output layer**.

**Before (v1):**
```go
// Render() did color downsampling internally
style := lipgloss.NewStyle().Foreground(lipgloss.Color("#FF0000"))
fmt.Println(style.Render("Hello")) // output already downsampled
```

**After (v2):**
```go
// Render() ALWAYS emits full-fidelity ANSI
style := lipgloss.NewStyle().Foreground(lipgloss.Color("#FF0000"))
lipgloss.Println(style.Render("Hello")) // print layer downsamples
// Or use lipgloss.Sprint() to get a downsampled string
```

**Key Implications for SugarCraft:**
- SugarCraft's `Style::render()` should **always emit full ANSI** (no downsampling in render)
- SugarCraft needs **output writer functions** (`Print`, `Sprint`, `Fprint`) that handle TTY detection and color stripping
- The `compat` package pattern (global state at import time) is explicitly discouraged — SugarCraft should make this optional

**Background Detection Change:**
- v1: `HasDarkBackground()` (no args) used global stdin/stdout
- v2: `HasDarkBackground(in, out)` requires explicit file descriptors
- The `compat` package provides the old behavior but maintainers note: "this removes the purity from Lip Gloss... could cause Lip Gloss to compete for resources (like stdin) with other tools"

---

## 10. Performance Discussions

### Issue #81: New API — Fast Colors
- **Author:** antonmedv (2022-04-14)
- **Summary:** CPU profile shows `Render()` is slow when using only colors (no padding/borders). User prerenders every line for a viewbox.
- **Proposed Solution:** Optimize `Render()` to short-circuit when style only contains ANSI sequences.
- **Maintainer Response:** "That's a great idea; we can totally optimize for that. Have some ideas, will look into this some more."
- **Actual Fix:** v2's architecture (separate render from output) partially addresses this. Also: "If you really just need colors, you could of course try and see how you fare with directly using `termenv`."

### Issue #86: Lazy Loading Dark Background Causes Sporadic Performance Issues
- **Author:** orlangure (2022-06-08)
- **Summary:** `HasDarkBackground` sometimes takes up to a minute to complete (~50% of the time). Root cause: both `tea` and `lipgloss` try to query background color from terminal simultaneously.
- **Fix:** Coordinated fix in both Bubble Tea and Lip Gloss v2.

### Issue #592: Improve Performance of maxRuneWidth
- **Author:** clipperhouse
- **Summary:** `maxRuneWidth()` used `uniseg.FirstGraphemeClusterInString()` which was slow. Replaced with `displaywidth.StringGraphemes()` for significant speedup.
- **Diff:** Uses `displaywidth.String()` for single-char case, `displaywidth.StringGraphemes()` for longer strings. Benchmarks show improvement.

**Direct Risk to SugarCraft:** PERFORMANCE OPTIMIZATION OPPORTUNITY — The width calculation is on hot paths for any constrained layout. SugarCraft should:
1. Use a fast grapheme/width library (PHP's `grapheme_strlen` + `mb_strwidth`)
2. Consider caching width measurements for static strings
3. Short-circuit for ASCII-only strings

---

## 11. Extensibility Discussions

### Issue #293: Proposal — Pure NewStyle, Lip Gloss v2
- **Author:** aymanbagabas (2024-05-09) — *one of the maintainers*
- **Summary:** Proposes making `Style` a pure value type by embedding `colorProfile` and `hasLightBackground` in `Style` itself, rather than using a global renderer.
- **Justification:**
  1. Name confusion — `type Renderer` doesn't actually render styles
  2. Difficulty debugging — detection happens auto-magically in background
  3. Code complexity — renderer pattern adds complexity
- **Proposed API:**
  ```go
  type Style struct {
      colorProfile     Profile
      hasLightBackground bool
      // ... other props
  }
  func NewStyle() Style {
      onceStdDefaults.Do(UseStdDefaults)
      return Style{
          profile: ColorProfile,
          hasLightBackground: HasLightBackground,
      }
  }
  ```
- **Outcome:** This was implemented in v2 (Renderer removed, Style is pure value type).

**Direct Risk to SugarCraft:** This v2 design aligns with how SugarCraft's `CandySprinkles` should be designed — pure value types, no hidden global state, explicit color profile passing at output time.

### Issue #502: Border Manual Coloring with ANSI Colors
- **Author:** jcbasso
- **Summary:** When adding a string as a border that contains ANSI colors, width calculation is wrong. Rune-by-rune `ansi.StringWidth` fails; needs to parse string as a whole.
- **Maintainer Note:** "We don't support varying colours for border sides."
- **Direct Risk to SugarCraft:** MEDIUM — SugarCraft should ensure border width calculation is ANSI-aware.

### Issue #531: Rendering Parts of String in Bold Resets Background of Rest
- **Author:** (community member)
- **Summary:** Bold text inside a gray-background box causes the text after the bold portion to lose its background.
- **Workaround:** Make a base style with the background, then derive bold and non-bold styles from it:
  ```go
  baseStyle := lipgloss.NewStyle().Background(lipgloss.Color("#424242"))
  boldStyle := baseStyle.Bold(true)
  ```
- **Maintainer Note:** "The easiest way to do this is to simply make sure all your styles have the same background."
- **Related:** Likely related to #144.
- **Direct Risk to SugarCraft:** HIGH — Style composition in `CandySprinkles` must ensure child styles inherit parent background unless explicitly overridden.

---

## 12. API/UX Complaints

1. **`BorderTop(false)` Semantics Confusing** (#194) — Setting one side to `false` implicitly sets all sides to `false`. Users expect per-side control.
2. **Color Profile Detection in Tests** (#287, #33) — Color output varies depending on whether `go test` is run from the test directory or parent. Confusing for users.
3. **Global State in compat Package** — `compat.HasDarkBackground` is initialized at import time. Many users don't realize this causes issues when stdin/stdout are redirected.
4. **Width Mismatch in Some Cases** (#666) — `lipgloss.Width()` returns one value but rendering that many characters produces different length. Particularly affects CJK and certain Unicode characters.
5. **Excess Whitespace on Every Line** (#577) — `Render()` adds whitespace to every line except empty lines when content exceeds terminal width. User had to switch XML parsers to fix.

---

## 13. Migration Problems

### v1 to v2 Breaking Changes (documented in UPGRADE_GUIDE_V2.md):

| v1 | v2 Replacement |
|---|---|
| `type Renderer` | Removed entirely |
| `DefaultRenderer()` | Not needed |
| `NewRenderer(w, opts...)` | Not needed |
| `SetColorProfile(p)` | Set `lipgloss.Writer.Profile` |
| `HasDarkBackground()` (no args) | `lipgloss.HasDarkBackground(in, out)` |
| `type TerminalColor` | `image/color.Color` |
| `type Color string` | `func Color(string) color.Color` |
| `type AdaptiveColor` | `compat.AdaptiveColor` or `LightDark` |
| `WithWhitespaceForeground/Background` | `WithWhitespaceStyle` |

**Key Migration Pain Points:**
1. **`fmt.Print` → `lipgloss.Print`** — v2 render always emits ANSI; must use lipgloss writer functions to get downsampled output.
2. **Adaptive colors** — Must choose between `compat.AdaptiveColor` (global state) or explicit `LightDark(bool)` helper.
3. **`Renderer` removal** — Any code with `*Renderer` fields must be refactored.

**Direct Risk to SugarCraft:** The v2 API is cleaner and SugarCraft should follow this pattern from the start, avoiding the need for a `compat` package.

---

## 14. Clever Fixes & Workarounds

1. **Style Composition Pattern** (from #531): Always create a base style with the background, then derive child styles from it. This avoids the "bold resets background" issue.
   ```php
   $base = (new Style())->Background(Color('#424242'));
   $bold = $base->Bold(true);
   $normal = $base;
   ```

2. **Wrap Before Styling** (from #85): When text wrapping loses styles, wrap the plain text first, then apply styles to the wrapped result.

3. **Force Color Profile in Tests** (from #287):
   ```go
   lipgloss.SetColorProfile(termenv.TrueColor)
   ```

4. **Use `lipgloss.Writer` for Custom Output** (from #593): When writing to non-stdout destinations, configure the writer:
   ```go
   lipgloss.Writer = colorprofile.NewWriter(os.Stderr, os.Environ())
   ```

5. **Displaywidth over Uniseg** (from #592): Using `displaywidth.StringGraphemes()` instead of manual grapheme iteration via `uniseg` is faster.

---

## 15. Community Workarounds

1. **For Non-TTY Output:** Use `lipgloss.Print` instead of `fmt.Print`. This was the #1 confusion point in v2 migration.
2. **For Background Color Detection Hangs:** Downgrade to x/ansi or set `RUNEWIDTH_EASTASIAN=0` for CJK locale issues (#40 workaround).
3. **For Unicode Width Issues:** Set `RUNEWIDTH_EASTASIAN=0` environment variable.
4. **For Table Wrapping:** Use `.Wrap(false)` to disable wrapping; use ASCII borders for non-TTY output.

---

## 16. Maintainer Guidance Patterns

1. **"Use `lipgloss.Print` for output"** — This is the canonical answer for any color-stripping issue.
2. **"Make sure all your styles have the same background"** — For style composition issues, the answer is always "inherit from a base style."
3. **"This is a terminal issue"** — For Unicode width issues (#562), maintainers frequently deflect to terminal emulator bugs. However, users have pushed back with reproducible examples.
4. **"We don't support varying colours for border sides"** — Clear rejection of per-side multi-color borders.
5. **"Wrap the text before styling it"** — For the wrapping bug (#85), this is the current workaround.
6. **"Set `lipgloss.SetColorProfile(termenv.TrueColor)` in tests"** — For test color issues.

**What Maintainers Repeatedly REFUSE:**
- Per-side multi-color borders (#502)
- Flexbox/grid layout (direct to #644 charm-layout proposal)
- Fast-path color-only API (they suggest using termenv directly)

**What Maintainers RECOMMEND:**
- Use the `compat` package for v1-style adaptive colors (but note it's not recommended for new code)
- Use the writer functions for output
- Use `LightDark` and `Complete` helpers for explicit control

---

## 17. Rejected Ideas Worth Revisiting

1. **Per-Side Multi-Color Borders** — "We don't support varying colours for border sides." However, the `Border` struct already has per-side color methods, so maybe the *rendering* just needs to support it. SugarCraft could support this.
2. **Fast Colors API** (Issue #81) — "If you really just need colors, you could of course try and see how you fare with directly using `termenv`." The optimization wasn't implemented; instead v2 architecture makes it possible to short-circuit.
3. **Flexbox Layout** — This hasn't been merged into lipgloss itself; it's been proposed as a separate `charm-layout` library. SugarCraft could create its own layout system without waiting for Charm.
4. **Builtin Markdown Rendering** — Not in scope; Lip Gloss is for styling, Glamour handles markdown.

---

## 18. Problems Likely Relevant To SugarCraft

1. **Width Measurement for International Content** — PHP's `mb_strwidth()` handles some cases better than Go's `uniseg`. `CandySprinkles` must get this right.
2. **Style Composition with Inheritance** — The "bold resets background" problem (#531) will affect any styling library. Implement style inheritance properly.
3. **TTY Detection at Output Time** — SugarCraft needs writer functions that detect TTY and strip ANSI when needed (CI, pipes, redirect).
4. **Text Wrapping Preserving Style State** — Issue #85 is a 3-year-old bug. SugarCraft must implement wrapping that tracks and restores style state across line breaks.
5. **Background Color Query Hangs** — Any terminal capability query must have timeout and fallback behavior.
6. **Table/Border Width Calculations** — Must account for border characters in width calculations.

---

## 19. Features SugarCraft Should Consider

1. **Flexbox/Grid Layout System** — Implement from scratch. Don't wait for upstream. SugarCraft could solve this better with PHP's richer type system.
2. **Unified Theming** — Create a theme system that derives styles for all SugarCraft libraries from a single color palette.
3. **Style Composition Helpers** — `withBackground()`, `withForeground()` that create new styles inheriting unspecified properties.
4. **ANSI-Aware Width Calculation** — Proper grapheme cluster handling with fallback for CJK.
5. **Writer Functions** — `Print`, `Sprint`, `Fprint` that detect TTY and strip ANSI for non-interactive output.
6. **Table Enhancements** — Explicit column sizing that accounts for borders, proper ellipsis, and wrapping control.
7. **Per-Side Border Colors** — Implement what lipgloss doesn't fully support yet.
8. **Style Ranges** — Apply styles to specific rune index ranges for syntax highlighting.

---

## 20. Architectural Lessons

1. **Separate Render from Output** — v2's key insight: `Render()` should always produce full ANSI; downsampling happens at print layer. This prevents the "rendering to string vs rendering to terminal" inconsistency.

2. **Pure Value Types Eliminate Entire Bug Classes** — When `Style` had a `*Renderer` pointer, it could share state unexpectedly. Pure value types make composition predictable.

3. **Global State at Import Time is Dangerous** — The `compat` package's automatic stdin/stdout detection causes hangs and race conditions. Make this opt-in.

4. **Lazy Detection with Timeout** — Any terminal capability query must have a timeout and fallback. Don't block the main thread.

5. **Grapheme Clusters, Not Code Points** — Width calculation must use grapheme cluster boundaries, not Unicode code points. This is the #1 source of width bugs.

6. **Style State Must Survive Line Wrapping** — When wrapping styled text, the style at the end of one line must be restored at the start of the next.

---

## 21. Defensive Design Lessons

1. **Guard All Terminal Queries** — Check `isatty()` before attempting OSC queries. Never assume stdin/stdout are TTYs.

2. **Timeout All I/O Operations** — Terminal queries (OSC 11 for background color) must have timeouts. Use non-blocking reads with timeout.

3. **Validate Width Calculation** — Never trust width calculated by external libraries without validation. Have fallback width algorithms.

4. **Default to ASCII/Stripped in Unknown Contexts** — If color profile detection fails, default to ASCII (no color) rather than guessing.

5. **Test With Non-TTY Output** — Ensure tests run in both TTY and non-TTY contexts. Set explicit color profile in tests.

6. **Handle Partial Reads** — Terminal responses can come in chunks. Parser must maintain state between reads and only process complete sequences.

---

## 22. Ecosystem Trends

1. **AI Agents Moving to Terminal** — From the v2 announcement: "AI agents moved into the terminal, and suddenly the rest of the industry saw what many already knew: the terminal is the most powerful way to interface with the operating system." Lip Gloss v2 is optimized for AI tool output.

2. **Coordinated Library Versions** — The v2 release coordinated across Bubble Tea, Lip Gloss, and Bubbles simultaneously. SugarCraft should coordinate releases of `candy-sprinkles`, `candy-core`, and `sugar-bits`.

3. **Separation of Rendering and Output** — v2's architecture (render always emits full ANSI; writer handles downsampling) is the correct pattern for composability.

4. **Layout as a Separate Concern** — Flexbox/grid is being proposed as `charm-layout` (separate library). SugarCraft should treat layout as a first-class concern.

5. **Terminal Emulator Diversity** — The #562 issue highlights that terminals handle Unicode width differently. Libraries must be defensive about this.

6. **Performance on SSH** — From v2 announcement: "For applications running over SSH, the changes are monetarily quantifiable." This drives rendering optimization work.

---

## 23. Strategic Opportunities

1. **PHP-First Width Handling** — PHP's `grapheme_strlen()`, `mb_strwidth()`, and `IntlBreakIterator` provide grapheme-aware string handling that may be more accurate than Go's `uniseg`. SugarCraft can leverage this for better Unicode support.

2. **Layout System from Day One** — Rather than adding layout later (as lipgloss is now doing with charm-layout), SugarCraft should implement the layout primitives simultaneously with styling.

3. **Coroutine-Friendly Architecture** — PHP's ReactPHP ecosystem can handle async terminal I/O. SugarCraft's `candy-core` can be designed for non-blocking terminal queries.

4. **Coordinated Multi-Library Releases** — When releasing v2 of `candy-sprinkles`, coordinate with `candy-core` and `sugar-bits` simultaneously.

5. **Superior Theme System** — The upstream `charm-themes` POC exists but hasn't been merged. SugarCraft could create an official theming system that spans all libraries.

6. **Windows-First FFI Support** — PHP 8.4+ has better Windows FFI support. SugarCraft can leverage this for Windows console support that matches or exceeds lipgloss's.

---

## 24. Cross-Ecosystem Pattern Matches

1. **React CSS-in-JS Pattern** — Style composition (base style → derived styles with `Bold(true)`, etc.) mirrors styled-components. SugarCraft's immutable Style pattern is analogous.

2. **CSS Flexbox Mental Model** — Users expect layout systems to behave like CSS flexbox (#564, #644). SugarCraft should implement flex/grid with CSS-like property names where possible.

3. **Terminal UI as a First-Class Platform** — The Charm ecosystem treats the terminal as a serious application platform, not a legacy interface. SugarCraft should embrace this.

4. **Writer Pattern for Output** — Java's `Writer` abstraction (abstract base for character streams) parallels lipgloss's `colorprofile.Writer`. SugarCraft should have an output abstraction that handles encoding/decoding.

5. **Component Libraries Layered on Styling** — `bubbles` (interactive components) builds on `lipgloss` (styling). `sugar-bits` should build on `candy-sprinkles` the same way.

6. **Snapshot Testing for Rendering** — Lipgloss's golden tests assert raw ANSI bytes. SugarCraft should use the same approach for `candy-sprinkles` snapshot tests.

---

## 25. High ROI Recommendations

### Immediate (High Impact, Low Effort)

1. **Implement Writer Functions in CandySprinkles** — `Print()`, `Sprint()`, `Fprint()` that detect TTY and strip ANSI. This addresses the #1 user confusion point from v2 migration.
2. **Guard TTY Queries** — Before any `HasDarkBackground` call, check `isatty()`. Never hang on non-TTY input.
3. **Add Explicit Width Validation** — For CJK/emoji content, add fallback width calculation using PHP's `grapheme_strlen()`.

### Short-Term (High Impact, Moderate Effort)

4. **Proper Style Inheritance** — When deriving a style with `Bold(true)`, inherit unspecified properties (especially background) from the parent. This fixes the #531 issue.
5. **Text Wrap with Style State** — Implement wrapping that tracks and restores style state across line breaks. This is the fix for #85.
6. **Table Column Sizing with Borders** — Ensure table width calculations include border characters. PR #575's fix should be replicated.

### Medium-Term (High Impact, Higher Effort)

7. **Flexbox/Grid Layout System** — Create `candy-layout` or extend `candy-sprinkles` with proportional sizing (`30%`, `70%`), flex direction, gap, and responsive `SetSize()`.
8. **Unified Theming** — Create a `Theme` class that defines semantic colors once and derives `SugarCraft\Sprinkles\Style` objects for each library.
9. **Performance Optimization** — Short-circuit width calculation for ASCII-only strings; cache width for static strings.

### Long-Term (High Strategic Value)

10. **Coordinated Multi-Library Release** — When releasing `candy-sprinkles` v2, coordinate with `candy-core` v2 and `sugar-bits` v2 simultaneously, with matching upgrade guides.
11. **Windows Console FFI** — PHP 8.4+ FFI improvements enable direct Windows console API access. Leverage this for proper Windows support in `candy-sprinkles`.

---

## Summary

Lip Gloss v2 represents a mature, well-tested terminal styling library whose architectural decisions (pure value types, separate render from output, explicit over implicit) provide a clear blueprint for SugarCraft's `candy-sprinkles`. The ecosystem intelligence gathered shows that the most significant pain points are:

1. **Unicode width calculation** (affects international content)
2. **Layout system inadequacy** (flexbox/grid request)
3. **Style state loss on text wrap** (3-year-old bug)
4. **Global state causing hangs** (background detection)
5. **Non-TTY output confusion** (v2 migration issue)

SugarCraft has an opportunity to implement a cleaner solution to all of these problems, leveraging PHP's strengths (better built-in Unicode handling, coroutine-friendly async) while avoiding lipgloss's accumulated baggage. The key is to **implement the v2 architecture from day one** (pure style types, writer functions for output, explicit TTY detection) rather than following v1's design and then migrating.
