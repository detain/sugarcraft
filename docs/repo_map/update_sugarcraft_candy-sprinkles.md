# Overview

CandySprinkles (`candy-sprinkles`) is SugarCraft's foundational styling and layout library — a PHP port of `charmbracelet/lipgloss` v2 providing declarative, immutable styling for terminal UIs. The package occupies the critical foundation layer that every other SugarCraft consumer (`sugar-bits`, `sugar-prompt`, `sugar-charts`) imports for styling. It delivers comprehensive ANSI SGR coverage (bold/italic/underline/hyperlinks), CSS-like shorthand (padding/margin 1/2/4-arg), per-side border colors with gradient blends, a constraint-based layout solver, multi-layer canvas compositing, and structured renderers for tables, trees, and lists.

**Biggest opportunity areas:** Word-wrap integration into Style::render() (gap vs. lipgloss), CIELAB perceptually-uniform color blending, Text/Line/Span structured text hierarchy, Cassowary constraint solver for inter-constraint ratio support, and canvas cell-buffer diffing for efficient partial redraws.

**Biggest missing capabilities:** Full 2D gradient fills, grapheme-aware border measurement, WrapWriter for ANSI-safe streaming wrap, terminal OSC 11 background detection, StyleRunes for sub-string styling, and CSS flexbox layout engine.

---

# Internal Capability Summary

## Current Architecture

### Package Structure (37 files across 10 sub-namespaces)

```
candy-sprinkles/src/
├── Style.php                    # Core immutable styled-text builder (~1669 lines)
├── Border.php                   # 13-rune border definitions (8 presets + titles)
├── Layout.php                   # Package-level join/place/measure primitives
├── Canvas.php                    # Multi-layer compositor
├── Layer.php                    # Positioned content layer for Canvas
├── Renderer.php                 # Per-writer color-profile + dark-bg context
├── Output.php                   # Stream-agnostic print/sprint helpers
├── Palette.php                   # ANSI 16 named color constants
├── Theme.php                     # 10 named theme factories + 13 color slots
├── Hsl.php                       # CSS-style HSL color factory
├── Markup.php                    # Rich-style [tag]text[/] parser → Cell[]
├── StyleParser.php              # Alternative [text](fg:red,bold) parser
├── AdaptiveColor.php            # Light/dark runtime picker
├── CompleteColor.php             # Per-tier (TrueColor/ANSI256/ANSI) color picker
├── CompleteAdaptiveColor.php     # Combined adaptive + complete color
├── LightDark.php                 # Closure picker for dark-vs-light schemes
├── UnderlineStyle.php             # Enum: None/Single/Double/Curly/Dotted/Dashed
├── Align.php / VAlign.php        # Horizontal/vertical alignment enums
├── Border/BorderGradientBlend.php # Clockwise perimeter color interpolation
├── Border/BorderTitle.php         # Title text + anchor metadata
├── Border/TitleAnchor.php          # Enum: TopLeft/TopCenter/TopRight/Bottom...
├── Layout/ (constraint solver)
│   ├── Layout.php                # Facade: horizontal()/vertical() → split()
│   ├── Constraint.php            # Abstract base + 6 factories
│   ├── Solver.php                # One-pass greedy solver (no cassowary)
│   ├── Rect.php                  # Axis-aligned rectangle (x, y, width, height)
│   ├── Direction.php             # Horizontal/Vertical enum
│   ├── Length.php / Min.php / Max.php / Fill.php / Percentage.php / Ratio.php
├── Table/
│   ├── Table.php                 # Full table renderer with styleFunc callback
│   ├── Data.php                  # Row-reader interface
│   └── StringData.php             # Default matrix-to-rows Data impl
├── Tree/
│   ├── Tree.php                  # Recursive tree renderer with enumerators
│   └── Enumerator.php            # Connector character sets (default/rounded/ascii)
└── Listing/
    ├── ItemList.php              # Enumerated list renderer
    └── Enumerator.php            # Marker generators (bullet/dash/arabic/roman/etc.)
```

### Style Architecture

`Style` is an immutable value object with ~40 `with*()` setters. Key architectural decisions:

1. **All setters return new instance** — no mutation; `copy()` for explicit shallow clone
2. **`propsSet` array tracks explicitly-set properties** — enables `inherit()` to correctly skip properties the child already set
3. **CSS shorthand expansion** — `padding(1)` → `[1,1,1,1]`, `padding(1,2)` → `[1,2,1,2]`, `padding(1,2,3,4)` → full
4. **Adaptive/Complete color slots** — resolve at render time via `resolveAdaptive()` / `resolveProfile()`
5. **Hyperlink OSC 8 wrapping** — injected at render() final output stage
6. **Transform callback** — applied post-border, pre-margin as a last-mile rewrite hook

Render pipeline (innermost to outermost):
```
content → width constraint + horizontal align → padding (styled) → fixed height (vertical align) → border (styled separately) → margin (unstyled)
```

### Layout System

**Package-level primitives** (`Layout.php`): `joinHorizontal()`, `joinVertical()`, `place()`, `placeHorizontal()`, `placeVertical()`, `width()`, `height()`, `size()`.

**Constraint-based solver** (`Layout/` sub-namespace): Mirrors ratatui's Cassowary-lite without the full Cassowary algorithm. Constraint types: `Length`, `Min`, `Max`, `Percentage`, `Ratio`, `Fill`. One-pass greedy algorithm handles common 80×24 dashboard use cases.

### Canvas/Layer Compositing

`Canvas::new()->addLayer(Layer)->render()` — Layers sorted by z-index, composed by splicing each layer's lines into base grid at (x, y). ANSI-safe via `Width::truncateAnsi()` + `Width::dropAnsi()`. SGR reset at splice boundaries prevents bleed.

---

## Strengths

1. **Faithful lipgloss v2 port** — API surface nearly identical to Go original, reducing cognitive friction
2. **Immutable + fluent** — every `with*()` returns new Style; `inherit()` and `patch()` provide composable merge semantics
3. **Comprehensive ANSI coverage** — all SGR attributes including underline sub-styles (SGR 4:N), colored underlines (SGR 58), overline (SGR 53), rapid blink, OSC 8 hyperlinks
4. **Graceful color degradation** — four-tier `ColorProfile` system (TrueColor → ANSI256 → ANSI → NoTty) auto-adapts
5. **CSS-like shorthand** — padding/margin accept 1/2/4 arguments with correct clockwise expansion
6. **Constraint solver is lightweight** — ~300 lines readable PHP handles common cases
7. **Canvas/Layer properly handles ANSI** — SGR reset sequences at splice boundaries
8. **Rich markup parser** — `Markup::parse()` handles Rich-style nested `[tag]text[/]` returning `list<Cell>`
9. **Theme catalog** — `Theme::catalog()` enables programmatic discovery; `adaptive()` auto-detects via `COLORFGBG`
10. **Well-tested** — snapshot tests verify exact SGR byte output across all major features

---

## Weaknesses

1. **No word-wrap in Style** — `Width::wrap()` exists in sugar-core but not integrated into `Style::render()`
2. **No 2D gradient blends** — lipgloss's `Blend2D` using CIELAB at rotated positions not implemented (only perimeter 1D)
3. **Constraint solver is greedy, not Cassowary** — can't express "column A is at least 2× column B"
4. **Table lacks column width constraints** — lipgloss table has `Width(Constraint)` per column
5. **No Text/Line/Span hierarchy** — `Markup::parse()` returns `list<Cell>` but no first-class structured text type
6. **No built-in syntax highlighting** — glamour integration point unclear
7. **No StyleRunes equivalent** — sub-string styling not ported
8. **Hyperlink lacks styling preservation** — `hyperlink()` wraps entire render; no substring hyperlinks
9. **No border title styling control** — `BorderTitle` infers style from `borderForeground`; no `withStyle()` mechanism
10. **BorderGradientBlend uses LERP not CIELAB** — linear RGB interpolation instead of perceptually-uniform blending

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|-------------------------|----------|
| `charmbracelet/lipgloss` | Primary upstream | Wrap integration, CIELAB blending, StyleRunes, Blend2D, table column constraints, OSC 11 terminal detection, grapheme-aware borders | Critical |
| `ratatui/ratatui` | Constraint solver reference | Cassowary solver, Flex alignment variants, Layout margin support, Text/Line/Span hierarchy | Critical |
| `php-tui/php-tui` | Style system reference | LinearGradient color, Text/Line/Span, Buffer::diff() cell-level diffing, Widget trait system | High |
| `76creates/stickers` | Flexbox layout reference | Ratio-based responsive grid, minWidth/minHeight constraints, ContentGenerator per cell | High |
| `textualize/textual` | Layout engine reference | CSS flexbox/grid layout, spatial map for hit-testing, reactive state | Medium |
| `charmbracelet/bubbles` | Table/list/tree reference | Table column navigation, fuzzy filtering, Viewport soft-wrap line info | Medium |
| `pterm/pterm` | Printer patterns reference | TextPrinter interface, RGB.Fade() multi-stop gradients, LivePrinter cursor updates | Medium |

---

# Feature Gap Analysis

## Critical Priority

### 1. Word-wrap integration in Style::render()
**Description:** `Width::wrap()` exists in sugar-core but `Style::render()` only truncates, never wraps multi-line content.
**Why it matters:** Every lipgloss user expects `Width(n).Render("long text")` to wrap at n columns automatically.
**Source:** `charmbracelet_lipgloss.md` — `Wrap(s, width, breakpoints)` in wrap.go
**Implementation:** Integrate `Width::wrap()` call in `Style::render()` when `width()` is set and content contains no explicit newlines. Need `WrapWriter` equivalent for ANSI-safe streaming wrap that tracks style state across newlines.
**Estimated complexity:** Medium — requires creating `WrapWriter` class (~100 lines) + integration into `Style::render()`
**Expected impact:** High — closes major lipgloss feature gap, enables auto-wrapping text areas

### 2. CIELAB Color Blending
**Description:** `Color::blend()` uses linear RGB LERP; lipgloss uses `lucasb-eyer/go-colorful` for perceptually-uniform CIELAB D65 blending.
**Why it matters:** Linear RGB interpolation produces visually jarring gradient transitions, especially for perceptually-similar colors.
**Source:** `charmbracelet_lipgloss.md` — `Blend1D`, `Blend2D` using CIELAB; `sugarcraft_candy-sprinkles.md` — pattern note at line 516
**Implementation:** Add `ColorSpace` parameter to `Color::blend()`. Implement D65 illuminant CIELAB conversion (using `Lab` struct with L*, a*, b*). Apply in `BorderGradientBlend::fromColors()`.
**Estimated complexity:** Medium — requires Lab color space implementation (~80 lines)
**Expected impact:** High — visually smoother gradients, perceptual uniformity matching upstream

### 3. Table column width constraints
**Description:** lipgloss table has `Width(Constraint)` per column; candy-sprinkles Table auto-fits to widest cell.
**Why it matters:** Dashboard tables need fixed-width columns (for IDs) alongside proportional columns.
**Source:** `charmbracelet_lipgloss.md` — `table` sub-package, `Width(constraint)`; `charmbracelet_bubbles.md` — table column navigation
**Implementation:** Add `Table::columnWidth(int $colIndex, Constraint $constraint)` method. Internally use constraint solver to compute column widths before rendering. Needs constraint solver to support inter-column ratio constraints.
**Estimated complexity:** Medium — requires constraint solver enhancement + Table method addition
**Expected impact:** High — enables responsive dashboard tables with mixed fixed/proportional columns

## High Value

### 4. 2D Rectangular Gradient Fills
**Description:** `Blend2D(tl, tr, bl, br, width, height, angle)` for heatmap-style cell coloring.
**Why it matters:** Heatmaps, data visualizations, and color-coded status displays need 2D gradients.
**Source:** `charmbracelet_lipgloss.md` — `Blend2D(width, height, angle, stops...)` using rotated 1D gradient sampling
**Implementation:** Implement `Color::blend2D(Color $tl, Color $tr, Color $bl, Color $br, int $width, int $height, float $angleDegrees): list<list<Color>>`. Algorithm: sample 1D gradient at `(cos(θ)×x + sin(θ)×y, -sin(θ)×x + cos(θ)×y)` for each cell.
**Estimated complexity:** Medium — ~50 lines in Color class
**Expected impact:** Medium — enables heatmap visualizations, color-coded grids

### 5. Cassowary Constraint Solver
**Description:** Replace one-pass greedy solver with proper Cassowary algorithm for inter-constraint ratio support.
**Why it matters:** Cannot express "column A is always at least twice as wide as column B" with current greedy solver.
**Source:** `ratatui_ratatui.md` — Cassowary via `kasuari` crate; `php-tui_php-tui.md` — Cassowary via `php-tui/cassowary`; `76creates_stickers.md` — `calculateRatioWithMinimum` recursive algorithm
**Implementation:** Extract constraint solver to `honey-bounce` package. Implement Cassowary simplex algorithm (~1000+ lines). Use `php-tui/cassowary` as reference implementation.
**Estimated complexity:** High — algorithmic undertaking, ~1000+ lines
**Expected impact:** High — enables truly responsive layouts with constraint relationships

### 6. Text/Line/Span Text Model
**Description:** Structured multi-styled inline text hierarchy (`Text → Line → Span`) like ratatui and php-tui.
**Why it matters:** `Markup::parse()` returns `list<Cell>` but no first-class type for styled substrings within a line.
**Source:** `ratatui_ratatui.md` — `Text`, `Line`, `Span` hierarchy; `php-tui_php-tui.md` — same pattern
**Implementation:** Create `Span(string $content, Style $style)`, `Line(list<Span>)`, `Text(list<Line>)`. Create `Style::renderText(Text): string`. Refactor `Markup::parse()` to return `Text`.
**Estimated complexity:** Medium — new types + refactor existing markup parser
**Expected impact:** High — enables proper multi-styled inline text, richer text rendering

### 7. Flex Alignment Variants in Layout Solver
**Description:** ratatui's `Flex` enum: `Legacy | Centered | SpaceBetween | SpaceAround` for slack distribution.
**Why it matters:** Dashboard layouts need even spacing between elements, not all-slack-to-first-child.
**Source:** `ratatui_ratatui.md` — `Flex` enum; `sugarcraft_candy-sprinkles.md` — gap at line 396
**Implementation:** Add `Flex` enum with 4 variants. Update `Solver::solveHorizontal()` and `solveVertical()` to handle slack distribution per flex mode.
**Estimated complexity:** Low — ~20 lines in Solver
**Expected impact:** High — significantly better dashboard layout ergonomics

### 8. Grapheme-Aware Border Measurement
**Description:** `Border::GetTopSize()` assumes 1-cell runes; doesn't handle emoji/wide chars.
**Why it matters:** Borders containing emoji or wide characters render incorrectly.
**Source:** `charmbracelet_lipgloss.md` — `GetTopSize()` uses `displaywidth.String()`; `sugarcraft_candy-sprinkles.md` — gap at line 384
**Implementation:** Track max visual width of edge runes using `mb_strwidth()` (from sugar-core's Width utility) in Border class.
**Estimated complexity:** Low — ~15 lines, use Width utility
**Expected impact:** Medium — correct rendering with emoji borders

## Medium Priority

### 9. StyleRunes for Sub-String Styling
**Description:** `StyleRunes(str, indices, matched, unmatched)` applies styles to specific rune index ranges.
**Why it matters:** Fuzzy search highlighting requires styling matched character positions regardless of multi-byte characters.
**Source:** `charmbracelet_lipgloss.md` — `StyleRunes` in runes.go using `uniseg.NewGraphemes()`
**Implementation:** Create `StyleRunes(string $str, list<int> $indices, Style $matched, Style $unmatched): string`. Map byte indices to grapheme positions via regex `//u` with `preg_match_all('/./us', $str, $matches)`.
**Estimated complexity:** Medium — requires grapheme-aware index mapping
**Expected impact:** Medium — enables fuzzy search result highlighting

### 10. Terminal Background Detection via OSC 11
**Description:** `HasDarkBackground()` uses `COLORFGBG` only; doesn't query terminal directly like lipgloss.
**Why it matters:** `COLORFGBG` is not set by all terminals; direct OSC 11 query is more reliable.
**Source:** `charmbracelet_lipgloss.md` — `queryBackgroundColor` via OSC 11; `sugarcraft_candy-sprinkles.md` — gap at line 383
**Implementation:** Write OSC 11 query (`\033]11;?\007`) to terminal, read response via state machine. Windows requires `CONIN$/CONOUT$` console handles.
**Estimated complexity:** High — requires async-safe terminal query with timeout state machine
**Expected impact:** Medium — more reliable dark/light detection

### 11. Canvas Cell-Level Diffing
**Description:** Canvas uses string composition; php-tui's `Buffer::diff()` enables efficient partial redraws.
**Why it matters:** Animation and live updates redraw entire canvas unnecessarily.
**Source:** `php-tui_php-tui.md` — `Buffer::diff(Buffer)` for minimal cell updates; `ratatui_ratatui.md` — immediate mode with diffing
**Implementation:** Replace string-composition Canvas with cell buffer (grid of Cell with fg/bg/modifier/rune). `Canvas::render()` diffs current vs. previous buffer, emit only changed cells.
**Estimated complexity:** High — requires new Cell buffer type + diff algorithm
**Expected impact:** High — efficient partial redraws for animations

### 12. Spatial Map for Canvas Hit-Testing
**Description:** Canvas only has z-ordering; textual uses R-tree-like spatial index for O(log n) mouse hit detection.
**Why it matters:** Interactive overlays need mouse event routing to correct layer.
**Source:** `textualize_textual.md` — `_spatial_map.SpatialMap` for widget lookup by screen position
**Implementation:** Add `SpatialMap` class using sorted rectangles by x/y bounds. `Canvas::hitTest(int $x, int $y): ?Layer` returns topmost layer at coordinates.
**Estimated complexity:** Medium — spatial indexing data structure
**Expected impact:** Medium — enables mouse event routing in Canvas overlays

---

# Algorithm / Performance Opportunities

## Current vs. External Approach

### Constraint Solver: Greedy vs. Cassowary

**Current:** One-pass greedy algorithm: Length/Percentage/Ratio → reserved; Min → floor; Fill → proportional remainder; Max → clamp-and-redistribute. Cannot express inter-constraint relationships.

**External (ratatui/php-tui):** Cassowary simplex algorithm — enables "column A is always at least 2× column B" constraints. Same algorithm used by Apple Auto Layout.

**Why external is better:** Ratatui's dashboard demo shows sidebar+main+extra columns where sidebar is fixed 20, main is 60% of remaining, extra absorbs rest — works with greedy. But sidebar at least twice as wide as extra requires Cassowary.

**Tradeoffs:** Cassowary is ~1000+ lines vs. current ~300 lines. Greedy handles 80% of common cases; Cassowary needed only for constraint relationships between columns/rows.

**Applicability:** Medium — would transform layout solver but significant implementation cost. Consider `honey-bounce` extraction first.

### Color Blending: Linear RGB vs. CIELAB

**Current:** `Color::blend()` uses linear RGB LERP: `r = a.r + t * (b.r - a.r)`.

**External (lipgloss):** CIELAB D65 perceptually-uniform color space via `lucasb-eyer/go-colorful`.

**Why external is better:** Human perception is non-linear; CIELAB L* (lightness) is designed to produce visually uniform steps. Linear RGB interpolation produces banding artifacts for perceptually-similar colors.

**Tradeoffs:** CIELAB requires sqrt/pow operations per blend step (vs. simple addition for RGB). RGB blending is 10× faster but produces visible artifacts. For border gradients and data visualizations (where blending matters most), CIELAB quality is worth the cost.

**Applicability:** High — add optional `ColorSpace` parameter to `Color::blend()`. ~80 lines for Lab conversion.

### Canvas Rendering: String Composition vs. Cell Buffer Diffing

**Current:** Canvas composes layers as strings, splices at (x,y) with ANSI-aware truncation. `render()` outputs full composed string every call.

**External (php-tui, ratatui):** `Buffer` class with per-cell fg color, bg color, modifiers, and rune. `Buffer::diff()` computes minimal cell updates between frames.

**Why external is better:** For live-updating UIs (progress bars, spinners, logs), only changed cells need to be written. String composition always outputs full canvas even if 99% unchanged.

**Tradeoffs:** Cell buffers require more memory (one Cell struct per terminal cell). String composition is simpler but less efficient. PHP's weaker numeric performance makes cell-level diffing more costly.

**Applicability:** Medium — would benefit sugar-bits interactive components more than candy-sprinkles styling layer.

### ANSI Width Measurement: Repeated mb_strwidth Calls

**Current:** `Width::string()` called repeatedly in layout loops (`joinHorizontal`, `place`, `Layout::width`, border line construction). Each call strips ANSI and calls `mb_strwidth()`.

**External (lipgloss):** Caches measured widths where possible; uses `displaywidth` package for grapheme-aware measurement.

**Why external is better:** For long multi-line content in tight layout loops, repeated measurement is a hotspot. Caching measured width of rendered blocks between layout passes eliminates redundant work.

**Tradeoffs:** Caching adds complexity (invalidation on style change). For typical render-per-frame TUI usage, not a concern.

**Applicability:** Low — micro-optimization, measurable only in pathological cases.

---

# Architecture Improvements

## Short-term (within current architecture)

1. **Add `WrapWriter` class** — Track ANSI style + OSC 8 link state across newlines. Reset before `\n`, replay after. ~100 lines. Use in `Style::render()` when `width()` is set.

2. **Add CIELAB blending to `Color`** — Extend `Color::blend()` to accept `ColorSpace` parameter (LinearRGB/CIELAB). Add `Lab` struct with L*, a*, b*. ~80 lines. Use in `BorderGradientBlend`.

3. **Add `Table::columnWidth(Constraint)`** — Per-column width constraints using constraint solver internally. ~30 lines in `Table::render()`.

4. **Add flex alignment to layout solver** — `Flex::Centered|SpaceBetween|SpaceAround` in `Solver`. ~20 lines.

## Medium-term (new subsystems)

5. **Text/Line/Span text model** — Create structured text types:
   ```
   Span: content (string) + style (Style)
   Line: list<Span>
   Text: list<Line>
   ```
   Enable `Style::renderText(Text)`. `Markup::parse()` becomes a parser producing `Text`.

6. **StyleRunes implementation** — `StyleRunes(string, indices, matched, unmatched)` for sub-string styling. ~50 lines using grapheme-aware index mapping.

7. **Extract constraint solver to `honey-bounce`** — Reusable across sugar-charts (axis layout), sugar-bits (component layout), future layout-sensitive libs. Mirror ratatui's layout crate separation.

## Long-term (new capabilities)

8. **Cassowary constraint solver** — Full simplex algorithm. ~1000+ lines. Transformational for complex responsive layouts.

9. **CSS flexbox layout engine** — Following textual's model, implement TCSS parser + flexbox algorithm. High effort, transformative impact.

10. **Canvas cell-buffer diffing** — Replace string-composition with Cell grid. `Canvas::render()` diffs current vs. previous buffer. Enable efficient partial redraws.

---

# API / Developer Experience Improvements

1. **`Style::marginBackground(string $char)`** — Analogous to `paddingChar()` / `marginChar()`, fill margin area with custom character. Already has plumbing; just needs char field.

2. **`Layout::measure(string): array`** — Already exists as `Layout::size()`. Better PHPDoc and README promotion — developers don't know to use it for budgeting.

3. **`Theme::withBorderColor(Color)` / `Theme::withSeparatorColor(Color)`** — Convenience setters for 13 named color slots using existing `with*()` pattern.

4. **`Color::blend2D()` documentation** — `Color::hex()` returns RGB; documented `blend2D()` but not `blend()`. Clarify in README that `blend()` is 1D linear interpolation.

5. **`Style::hyperlink(string $url, string $id = '')` documentation** — Clarify that hyperlink wraps entire render output; no substring hyperlinks currently.

6. **`BorderGradientBlend` CIELAB mode** — Add `useCIELAB: true` flag to `BorderGradientBlend::fromColors()`. Currently linear RGB only.

7. **Short aliases promotion** — `fg`, `bg`, `on`, `pad`, `mg`, `of` are documented but lipgloss-mirroring names (`foreground`, `background`, `padding`, `margin`, `setString`) could be more prominent for new users.

---

# Documentation / Cookbook Opportunities

1. **Word-wrap cookbook** — Demonstrate `Width::wrap()` + `WrapWriter` pattern for auto-wrapping text areas.

2. **Dashboard layout cookbook** — Full example showing constraint solver for 3-pane layout with fixed header, proportional body columns.

3. **Gradient border cookbook** — `BorderGradientBlend::fromColors()` + CIELAB mode for smooth perimeter gradients.

4. **Canvas overlay cookbook** — Multi-layer Canvas for modal dialogs, tooltips, status overlays.

5. **Theme customization cookbook** — `Theme::catalog()` for programmatic discovery + `with*()` overrides for custom palettes.

6. **Color blending cookbook** — `Color::blend()` (1D), `Color::blend2D()` (2D heatmap), `BorderGradientBlend` (perimeter), CIELAB vs. RGB comparison.

7. **Text/Line/Span cookbook** — When to use `Markup::parse()` (cell granularity) vs. `Text/Line/Span` (structured styling).

8. **Style inherit/patch cookbook** — `inherit()` for theme propagation, `patch()` for incremental overrides. Explicit-wins rule explained.

9. **Adaptive color cookbook** — `AdaptiveColor` + `resolveAdaptive()` for runtime dark/light switching without re-creating styles.

10. **Table styling cookbook** — `styleFunc` + `HEADER_ROW = -1` sentinel, per-side borders, column constraints.

---

# UX / TUI Improvements

1. **Better error messages for invalid dimensions** — Currently throws `InvalidArgumentException` with minimal context. Could include property name and value.

2. **Debug render trace** — Add `Style::renderTrace(): array` returning the intermediate steps (content width, padding applied, border added, margin added) for debugging complex styles.

3. **Theme live preview** — `Theme::catalog()` + `Theme::adaptive()` documented but no tool to preview all themes side-by-side.

4. **`Border::catalog()` promotion** — Listed in API but not demonstrated visually. `--list-borders` CLI pattern mentioned in mapping.

5. **Interactive border preview** — Similar to `--list-themes` / `--list-borders`, a demo showing all 8 border presets with sample content.

---

# Testing / Reliability Improvements

1. **Expand snapshot test coverage for `inherit()` / `patch()`** — Currently good coverage for SGR output, less for style merging behavior.

2. **Constraint solver edge case tests** — `testMinWithExtraSlack` comment in `LayoutSolverTest.php` indicates known issue. Fix and add regression test.

3. **CIELAB blend accuracy tests** — Add snapshot tests asserting known Lab values for specific hex inputs.

4. **Grapheme-aware width tests** — Test Border with emoji border characters (e.g., `🭽▔🭾`) asserting correct frame size.

5. **WrapWriter ANSI state machine tests** — Test that hyperlink + style survives line wrap with `WrapWriter`.

6. **Adaptive color runtime tests** — Test `AdaptiveColor::resolveAdaptive()` with explicit `isDark=true/false` vs. `COLORFGBG` env var scenarios.

7. **Canvas layer splice boundary ANSI reset tests** — Verify SGR reset on splice boundaries prevents color bleed between layers.

---

# Ecosystem / Integration Opportunities

1. **Integrate `Width::wrap()` into `Style::render()`** — Currently separate utility; should be called automatically when `width()` is set.

2. **`Renderer::fromEnvironment()` usage in sugar-bits** — Each sugar-bits component independently calls `ColorProfile::detect()`. Should use `Renderer::fromEnvironment()` for consistency.

3. **candy-shine (Glamour) integration** — Markdown/code syntax highlighting not yet connected to candy-sprinkles rendering pipeline.

4. **candy-core `Width` utility sharing** — Both candy-sprinkles and sugar-bits import from `SugarCraft\Core\Util\Width`. Ensure API consistency.

5. **sugar-charts heatmap using `Blend2D`** — `Color::blend2D()` would enable heatmap cell coloring in charts.

6. **sugar-bits progress bar using RGB.Fade()** — pterm's multi-stop gradient pattern for progress visualization.

7. **`honey-bounce` extraction** — Constraint solver currently in candy-sprinkles should be extracted for reuse.

---

# Notable PRs / Issues / Discussions

### lipgloss: `StyleRunes` via grapheme mapping
`StyleRunes(str, indices, matched, unmatched)` maps byte indices to grapheme cluster positions using `uniseg.NewGraphemes()`. Enables highlighting matched characters in fuzzy search results regardless of multi-byte characters.
**Reference:** `charmbracelet_lipgloss.md` — runes.go, lines 198-199
**Lesson:** PHP equivalent needs `preg_match_all('/./us', $str, $matches)` for grapheme-aware indexing.

### lipgloss: `WrapWriter` ANSI state machine
Tracks current `uv.Style` and `uv.Link` state across newlines via `ansi.Parser`. Emits `ansi.ResetStyle` before each `\n`, replays tracked style after. Ensures hyperlinks and styles survive line breaks.
**Reference:** `charmbracelet_lipgloss.md` — wrapping.go, lines 219-220
**Lesson:** State machine approach is correct; PHP equivalent needs to track SGR attribute state.

### stickers: `calculateRatioWithMinimum` recursive algorithm
Recursive algorithm: if a cell's minimum exceeds its ratio-allocated share, lock the minimum and redistribute remaining space among non-locked cells recursively. Enables CSS flexbox-style responsive layouts with hard floors.
**Reference:** `76creates_stickers.md` — flexbox/utils.go
**Lesson:** More powerful than current greedy solver for constrained layouts.

### pterm: `MapRangeToRange` linear interpolation
```go
func MapRangeToRange(fromMin, fromMax, toMin, toMax, current float32) int {
    if fromMax-fromMin == 0 { return 0 }
    return int(toMin + ((toMax-toMin)/(fromMax-fromMin))*(current-fromMin))
}
```
Foundational utility for bar chart scaling, progress color fades, keyboard navigation offsets.
**Reference:** `pterm_pterm.md` — internal/map_range_to_range.go
**Lesson:** Should be in sugar-core or honey-bounce as `MapRange::map(float $value, float $fromMin, float $fromMax, float $toMin, float $toMax): float`.

### textual: Spatial map for O(log n) hit testing
Compositor uses `_spatial_map.SpatialMap` (R-tree-like) for widget lookup by screen position. Enables efficient mouse event routing without O(n) iteration.
**Reference:** `textualize_textual.md` — src/layout/compositor.rs, lines 119-123
**Lesson:** For Canvas mouse events, spatial index is essential for performance.

### ratatui: `Stylize` trait for ergonomic styling
```rust
"hello".red().on_blue().bold() // → Span
Text::from("hello").red().blue() // → Text
Block::bordered().red() // → Block
```
PHP could achieve similar via `__call` magic on strings returning `Span` instances, but not a current priority.
**Reference:** `ratatui_ratatui.md` — style.rs, lines 138-145
**Lesson:** Aspirational ergonomics; lower priority than feature parity.

### php-tui: `Buffer::diff()` cell-level diffing
`Buffer::diff(Buffer)` computes minimal updates between two buffers. Only changed cells sent to terminal backend.
**Reference:** `php-tui_php-tui.md` — src/Display/Buffer.php, lines 96-116
**Lesson:** Key for efficient animation; would benefit Canvas.

---

# Recommended Roadmap

## Immediate Wins (1-2 PRs)

1. **Add `Flex::SpaceBetween|SpaceAround` alignment to `Solver`** — 20 lines, large UX improvement for dashboards
2. **Add CIELAB blending mode to `BorderGradientBlend`** — Add `useCIELAB: true` flag
3. **Add `Table::columnWidth(Constraint)`** — Per-column width constraints
4. **Add `Style::marginBackground(string $char)`** — Char fill variant
5. **Add `Color::blend2D()` for heatmap gradients** — ~50 lines
6. **Fix `Solver` `testMinWithExtraSlack` issue** — Known bug in layout solver

## Medium-term Improvements (2-3 PRs)

7. **Create `WrapWriter` class** — ANSI state tracking across newlines
8. **Integrate `Width::wrap()` into `Style::render()`** — Automatic word-wrap when width is set
9. **Create `Text/Line/Span` hierarchy** — Refactor `Markup::parse()` to return `Text`
10. **Add `StyleRunes` implementation** — Sub-string styling via grapheme-aware index mapping
11. **Add layout margin support to `Layout::split()`** — ratatui-style margin parameter
12. **Implement `Border::GetTopSize()` grapheme-aware measurement** — Using `mb_strwidth()`

## Major Architectural Upgrades

13. **Cassowary constraint solver** — Extract to `honey-bounce`, implement full simplex algorithm
14. **CSS flexbox layout engine** — TCSS parser + flexbox algorithm (long-term)
15. **Canvas cell-buffer diffing** — Replace string composition with Cell grid

## Experimental Ideas

16. **Spatial map for Canvas hit-testing** — R-tree-like layer lookup by screen position
17. **Terminal OSC 11 background detection** — Direct terminal query vs. COLORFGBG env var
18. **`honey-bounce` extraction** — Separate constraint solver package for reuse

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|------------|--------|-------------|------|-------------------|
| Flex alignment (SpaceBetween/SpaceAround) | High | Low | Low | Immediate |
| CIELAB blending in BorderGradientBlend | High | Low | Low | Immediate |
| Table column width constraints | High | Medium | Low | Immediate |
| Style::marginBackground(char) | Medium | Low | Low | Immediate |
| Color::blend2D() for heatmaps | Medium | Medium | Low | Immediate |
| Fix testMinWithExtraSlack bug | Medium | Low | Low | Immediate |
| WrapWriter + Width::wrap integration | High | Medium | Medium | Medium-term |
| Text/Line/Span hierarchy | High | Medium | Low | Medium-term |
| StyleRunes implementation | Medium | Medium | Low | Medium-term |
| Layout margin support | Medium | Low | Low | Medium-term |
| Grapheme-aware Border measurement | Medium | Low | Low | Medium-term |
| Cassowary constraint solver | High | High | Medium | Long-term |
| CSS flexbox layout engine | High | High | High | Long-term |
| Canvas cell-buffer diffing | High | High | Medium | Long-term |
| Spatial map for hit-testing | Medium | Medium | Medium | Long-term |
| OSC 11 terminal detection | Medium | High | Medium | Experimental |

---

# Final Strategic Assessment

CandySprinkles is a well-executed, ~85% complete port of charmbracelet/lipgloss v2 that delivers the most critical styling and layout primitives for the SugarCraft monorepo. Its immutable + fluent `Style` architecture, comprehensive ANSI SGR coverage, CSS-like shorthand, and constraint-based layout solver provide a solid foundation that the rest of SugarCraft (`sugar-bits`, `sugar-prompt`, `sugar-charts`) builds upon.

**The main gaps relative to lipgloss are:** word-wrap integration (most impactful user expectation gap), CIELAB blending (visual quality), StyleRunes (sub-string styling), table column constraints (dashboard usability), and grapheme-aware border measurement (correctness with emoji). These are not trivial — they represent real feature surface area — but they are well-understood and documented.

**The most significant architectural limitation** is the greedy constraint solver, which cannot express inter-constraint relationships like "sidebar is always at least twice as wide as the extra column." This requires a full Cassowary implementation, which is a substantial algorithmic undertaking (~1000+ lines). The current greedy solver handles the common 80×24 dashboard case correctly; Cassowary is needed only for complex responsive layouts.

**Canvas architecture** is a strong foundation for overlay UIs but needs cell-buffer diffing for efficient partial redraws in animation scenarios. The spatial map gap means mouse event routing to layers requires O(n) iteration, which will matter for interactive overlays.

**The recommended priority order** is: immediate wins (flex alignment, CIELAB blending, table column constraints) first since they are low-complexity with high impact. Then word-wrap integration and Text/Line/Span hierarchy since they close major functional gaps. Cassowary and CSS flexbox are long-term investments that transform the layout engine but require significant effort.

The package is production-ready for its current scope. The gaps are enhancements rather than blocking issues, and the codebase is well-structured enough to accommodate them incrementally.
