# SugarCraft/candy-sprinkles

## Metadata
- **Package:** `sugarcraft/candy-sprinkles`
- **Namespace:** `SugarCraft\Sprinkles`
- **Description:** Declarative styling and layout for terminal UIs. PHP port of the Go charmbracelet/lipgloss ecosystem.
- **PHP:** `^8.3`
- **Status:** 🟢 Active

---

## Overview

CandySprinkles is the foundational styling and layout library of the SugarCraft monorepo. It provides an expressive, declarative API for terminal rendering — every property is set via fluent `with*()` methods returning new immutable instances, matching the lipgloss v2 pattern. The library covers the full ANSI SGR spectrum (colors, modifiers, underline styles, hyperlinks), CSS-like margin/padding models, border rendering with per-side color control, multi-layer canvas compositing, constraint-based layout solving, and structured renderers for tables, trees, and lists.

**Primary upstream:** `charmbracelet/lipgloss` (v2)
**Secondary upstream:** `ratatui` (constraint solver), `charmbracelet/bubbles` (table/list/tree), `php-tui/php-tui` (style system)
**Related:** `textualize/textual` (layout engine), `pterm/pterm` (printer patterns), `76creates/stickers` (flexbox layout)

---

## Current Architecture

### Package Structure

```
candy-sprinkles/src/
├── Style.php                    # Core immutable styled-text builder (~1669 lines)
├── Border.php                   # 13-rune border definitions (8 presets + titles)
├── Layout.php                   # Package-level join/place/measure primitives
├── Canvas.php                   # Multi-layer compositor
├── Layer.php                    # Positioned content layer for Canvas
├── Renderer.php                # Per-writer color-profile + dark-bg context
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

`Style` is the central artifact — an immutable value object with ~40 `with*()` setters. Key architectural decisions:

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

---

## Major Systems

### Style System (`Style.php`)

The crown jewel. Key capabilities:

**Color system:**
- `foreground(?Color)` + `foregroundAdaptive(Color $light, Color $dark)` + `foregroundComplete(Color $tc, Color $c256, Color $ansi)`
- `background()` + `backgroundAdaptive()` + `backgroundComplete()` — same triad
- `colorProfile(ColorProfile)` — downsample tier (TrueColor/Ansi256/Ansi/NoTty)
- `resolveAdaptive(bool $isDark)` + `resolveProfile()` — collapse adaptive/complete slots
- `Color::parse()` lookup (38 CSS/ANSI names, case-insensitive)

**Modifiers:**
- Bold, Italic, Underline (with `UnderlineStyle` sub-parameter + `underlineColor()`), Strikethrough, Faint/Dim, Blink, RapidBlink, Reverse, Overline, Invisible
- `underlineStyle(UnderlineStyle::Curly)` emits SGR `4:3` for wavy spell-check underlines

**Geometry:**
- `width(?int)` / `height(?int)` — fixed dimensions
- `maxWidth(?int)` / `maxHeight(?int)` — hard caps without padding expansion
- `padding(...int)` / `margin(...int)` — CSS shorthand (1/2/4 args)
- `paddingChar(string)` / `marginChar(string)` — fill characters
- `align(Align)` / `verticalAlign(VAlign)` — content alignment

**Borders:**
- `border(?Border, bool ...$sides)` — CSS shorthand side toggles
- `borderForeground(?Color)` / `borderBackground(?Color)` — uniform border color
- `borderTopForeground()` / `borderRightForeground()` / `borderBottomForeground()` / `borderLeftForeground()` — per-side
- `borderForegroundBlend(Color $start, Color $end)` — CIELAB-style LERP gradient around perimeter
- `borderTop(bool)` / ... `borderLeft(bool)` — per-side toggles

**Advanced:**
- `hyperlink(string $url, string $id = '')` — OSC 8 wrapper
- `transform(?Closure): string` — last-mile post-border rewrite
- `marginBackground(?Color)` — paint margin area with background color
- `colorWhitespace(bool)` — toggle whether padding/margin inherits SGR
- `tabWidth(int)` — tab expansion (default 4, 0 = literal)
- `inline(bool)` — force single-line, collapse vertical padding/margin
- `inherit(self $parent)` — unset-only merge (explicit child wins)
- `patch(self $other)` — incremental merge (only $other's explicit props apply)

### Layout System

**Package-level primitives** (`Layout.php`):
- `joinHorizontal(float $pos, string ...$blocks)` — side-by-side with vertical anchor
- `joinHorizontalWithSpacing(float $pos, ?int $spacing, string ...$blocks)` — with gap
- `joinVertical(float $pos, string ...$blocks)` — top-to-bottom with horizontal anchor
- `joinVerticalWithSpacing(float $pos, ?int $spacing, string ...$blocks)` — with gap
- `place(int $width, int $height, float $hPos, float $vPos, string $block, string $fill = ' ')` — place in rectangle
- `placeHorizontal(int $width, float $pos, string $block, string $fill = ' ')` — horizontal only
- `placeVertical(int $height, float $pos, string $block, string $fill = ' ')` — vertical only
- `width(string $block): int` — cell width of block
- `height(string $block): int` — line count
- `size(string $block): array` — `[width, height]`

**Constraint-based solver** (`Layout/` sub-namespace):
Mirrors ratatui's Cassowary-lite solver without the full Cassowary algorithm:
- `Layout::horizontal([Constraint ...])→split(Rect): Rect[]` — column split
- `Layout::vertical([Constraint ...])→split(Rect): Rect[]` — row split
- Constraint types: `Length(n)` (fixed), `Min(n)` (floor), `Max(n)` (ceiling), `Percentage(n)` (0-100 of total), `Ratio(n, d)` (proportion), `Fill(weight)` (remaining space)
- One-pass greedy algorithm: Length/Percentage/Ratio → reserved; Min → floor; Fill → proportional remainder; Max → greedy with clamp-and-redistribute

### Border System (`Border.php`)

8 preset factories: `normal()`, `rounded()`, `thick()`, `double()`, `block()`, `ascii()`, `hidden()`, `markdownBorder()`

13-rune model: `top/bottom/left/right`, `topLeft/topRight/bottomLeft/bottomRight`, `middleLeft/middleRight/middle/middleTop/middleBottom` (for table interior separators).

`withTitle(string, ?TitleAnchor)` supports 6 anchor positions (TopLeft, TopCenter, TopRight, BottomLeft, BottomCenter, BottomRight). Multiple titles per anchor concatenated with space separator.

### Table (`Table/Table.php`)

- `headers(string ...)` + `row(string ...)` / `rows(iterable)` / `data(Data)`
- `border(?Border)` + `borderTop/Bottom/Left/Right(bool)` per-side toggles
- `borderHeader(bool)` / `borderRow(bool)` / `borderColumn(bool)` interior separators
- `headerAlign(Align)` / `rowAlign(Align)` per-section alignment
- `styleFunc(Closure(int $row, int $col): Style)` — per-cell styling callback
- `width(?int)` — cap rendered width with truncation
- `offset(int)` — skip first N body rows (pagination)
- `wrap(?Closure)` — cell overflow callback returning `list<string>`
- `clearRows()` — drop body rows, keep headers
- `Table::HEADER_ROW = -1` sentinel for styleFunc

### Tree (`Tree/Tree.php`)

- `root(string)` + `child(Tree|string)` / `children(...$c)`
- `enumerator(Enumerator)` — connector character set (default/rounded/ascii)
- `indenter(Closure(bool $isLast): string)` — continuation prefix override
- `rootStyle(?Style)` / `itemStyle(?Style)` / `enumeratorStyle(?Style)`
- `offset(int $start, int $end)` — half-open range render
- `hide(bool)` — suppress root rendering (for nested composition)
- Inherits enumerator/indenter/style from parent when not explicitly set on child

### List / ItemList (`Listing/ItemList.php`)

- `items(iterable)` / `item(string|ItemList)` — items or nested ItemList
- `enumerator(Closure(int $index, int $total): string)` — marker generator
- `itemStyle(?Style)` / `enumeratorStyle(?Style)` / `itemStyleFunc(Closure(int, string): Style)`
- `indent(string)` — prepended to every line of nested sublists
- Markers right-padded to widest one for text alignment
- Multi-line items preserve indentation on continuation lines

### Canvas / Layer (`Canvas.php` + `Layer.php`)

- `Canvas::new()->addLayer(Layer)->render(): string`
- Layer: `Layer::new(string $content)->withX(int)->withY(int)->withZ(int)`
- Sort by z-index (insertion order breaks ties)
- Compose by splicing each layer's lines into base grid at (x, y)
- ANSI-safe cut via `Width::truncateAnsi()` + `Width::dropAnsi()`
- SGR reset at splice boundaries (`\x1b[0m`) prevents bleed

### Renderer (`Renderer.php`)

Bundles `ColorProfile` + `hasDarkBackground` flag:
- `Renderer::new()` — TrueColor + dark
- `Renderer::fromEnvironment()` — auto-detect profile, default dark
- `withColorProfile(ColorProfile)` / `withHasDarkBackground(bool)`
- `newStyle(): Style` — pre-configured Style instance
- `lightDark(): Closure` — `fn(Color $light, Color $dark): Color`
- `resolveAdaptive(AdaptiveColor): Color`

### Theme (`Theme.php`)

10 named factories: `dark()`, `light()`, `dracula()`, `tokyoNight()`, `oneDark()`, `githubDark()`, `solarizedDark()`, `solarizedLight()`, `ansi()`, `adaptive()`

13 color slots: `foreground`, `background`, `primary` (=`accent`), `secondary` (=`muted`), `error`, `warning`, `success`, `info`, `border`, `separator`, `cursor`.

`Theme::catalog(): list<string>` enumerates factory names. `adaptive()` uses `COLORFGBG` env var for light/dark detection.

---

## Feature Inventory

| Feature | Status | Notes |
|---------|--------|-------|
| Full ANSI SGR (bold/italic/underline/etc.) | ✅ | All SGR codes + underline styles (SGR 4:N) |
| TrueColor / ANSI256 / ANSI / NoTty | ✅ | Per-profile downsample in render() |
| Adaptive colors (light/dark runtime pick) | ✅ | `foregroundAdaptive()` + `resolveAdaptive()` |
| Complete colors (per-tier designer choices) | ✅ | `foregroundComplete()` + `resolveProfile()` |
| CSS-like padding/margin shorthand (1/2/4 args) | ✅ | `expandSides()` helper |
| Per-side border colors | ✅ | `borderTopForeground()` etc. |
| Border gradient blend (perimeter LERP) | ✅ | `BorderGradientBlend::fromColors()` |
| 8 border presets + custom Border{} | ✅ | Plus 6 title anchors |
| OSC 8 hyperlinks | ✅ | `Style::hyperlink()` with id grouping |
| Colored underlines (SGR 58) | ✅ | `underlineColor()` + `underlineStyle()` |
| Transform callbacks | ✅ | `transform(?Closure)` post-border hook |
| Margin background color | ✅ | `marginBackground()` |
| Custom padding/margin fill chars | ✅ | `paddingChar()` / `marginChar()` |
| Tab expansion (configurable width) | ✅ | `tabWidth()` default 4 |
| Inline mode (collapse newlines) | ✅ | `inline()` |
| Width/Height/MaxWidth/MaxHeight | ✅ | Geometry constraints |
| Horizontal/vertical alignment | ✅ | `Align` + `VAlign` enums |
| Table with per-cell styleFunc | ✅ | `Table::HEADER_ROW = -1` sentinel |
| Tree with enumerators + style inheritance | ✅ | Child inherits parent config |
| ItemList with nested sublists | ✅ | Indent string for nested continuation |
| Constraint-based layout solver | ✅ | One-pass greedy (Length/Min/Max/Percentage/Ratio/Fill) |
| Canvas multi-layer compositor | ✅ | Z-index sort + ANSI-safe splicing |
| Rich-style [tag]text[/] markup parser | ✅ | `Markup::parse()` → `list<Cell>` |
| StyleParser [text](fg:red,bold) | ✅ | Distinct from Markup |
| HSL color factory | ✅ | `Hsl::color(200, 80, 50)` + `Hsl::parse()` |
| 10 named theme factories | ✅ | + `Theme::catalog()` enumeration |
| Renderer with color-profile context | ✅ | `Renderer::fromEnvironment()` auto-detect |
| Inherit/patch style merging | ✅ | Explicit vs. incremental merge |
| Style copy (shallow clone) | ✅ | `copy()` |
| Color blending / CIELAB LERP | ✅ | Via `Color::blend()` in sugar-core |
| Light/dark color picking | ✅ | `LightDark::picker()` closure |

---

## Strengths

1. **Faithful lipgloss v2 port** — the API surface is nearly identical to the Go original, reducing cognitive friction for developers familiar with the Charm ecosystem.

2. **Immutable + fluent** — every `with*()` returns a new Style; `inherit()` and `patch()` provide composable merge semantics; `propsSet` tracking enables correct explicit-wins precedence.

3. **Comprehensive ANSI coverage** — not just color, but all SGR attributes including underline sub-styles (SGR 4:N), colored underlines (SGR 58), overline (SGR 53), rapid blink (SGR 6), and OSC 8 hyperlinks.

4. **Graceful color degradation** — the four-tier `ColorProfile` system (TrueColor → ANSI256 → ANSI → NoTty) means the library automatically adapts to the terminal's capabilities without the developer managing it.

5. **CSS-like shorthand** — padding/margin accept 1/2/4 arguments with correct clockwise expansion; border accepts 1/2/4 side toggles.

6. **Constraint solver is lightweight** — the ratatui-inspired one-pass greedy solver (without the full Cassowary algorithm) handles the common 80×24 dashboard use case correctly and is ~300 lines of readable PHP.

7. **Canvas/Layer compositing** — properly handles ANSI escape sequences at splice boundaries with reset sequences, enabling overlay UIs without bleed.

8. **Rich markup parser** — distinct from StyleParser, `Markup::parse()` handles Rich-style nested `[tag]text[/]` with a style stack, returning `list<Cell>` for cell-granularity layout.

9. **Theme catalog** — `Theme::catalog()` enables programmatic discovery of named themes; `adaptive()` auto-detects via `COLORFGBG`.

10. **Well-tested** — snapshot tests in `tests/` verify exact SGR byte output across all major features; constraint solver has comprehensive unit coverage in `tests/Layout/LayoutSolverTest.php`.

---

## Weaknesses

1. **No word-wrap in Style** — lipgloss v2 has `Wrap(s, width, breakpoints)` and `WrapWriter`; candy-sprinkles has `Width::wrap()` in sugar-core but it's not integrated into `Style::render()`. Multi-line content with automatic wrapping is missing.

2. **No 2D gradient blends** — lipgloss has `Blend2D(width, height, angle, stops...)` using CIELAB interpolation at rotated positions; the `BorderGradientBlend` is only 1D (clockwise perimeter). True rectangular gradients require external implementation.

3. **Constraint solver is greedy, not Cassowary** — the one-pass solver can't express constraints like "column A is always at least twice as wide as column B" (ratio constraints only work against total area, not each other). Ratatui's Cassowary algorithm handles these properly.

4. **Table lacks column width constraints** — lipgloss/table has `Width(Constraint)` per column; candy-sprinkles Table auto-fits to widest cell and has no constraint-based column sizing.

5. **No Text/Line/Span hierarchy** — ratatui and php-tui have `Text → Line → Span` for multi-styled inline text. candy-sprinkles has `Markup::parse()` returning `list<Cell>` but no equivalent first-class type.

6. **No built-in syntax highlighting** — Glamour handles markdown/code in the Charm ecosystem; SugarCraft maps `candy-shine` to Glamour but the integration point is unclear.

7. **No StyleRunes equivalent** — lipgloss has `StyleRunes(str, indices, matched, unmatched)` for sub-string styling; not yet ported.

8. **Hyperlink lacks styling preservation** — `hyperlink()` wraps the entire render output; there's no way to set a hyperlink on a specific substring within content.

9. **No border title styling control** — `BorderTitle` stores text + anchor but the style is inferred from `borderForeground`; there's no `BorderTitle::withStyle()` mechanism.

10. **BorderGradientBlend::fromColors() uses LERP, not CIELAB** — lipgloss uses `lucasb-eyer/go-colorful` for CIELAB perceptually-uniform blending; the PHP implementation uses naive linear RGB interpolation.

---

## UX Evaluation

**Ergonomics for simple cases:** Excellent. `Style::new()->bold()->fg('#ff5f87')->on('#1e1e2e')->pad(0, 2)->of('hello')->render()` reads naturally and matches lipgloss chain semantics.

**Ergonomics for complex layouts:** Good. `Layout::joinHorizontal(Position::TOP, ...)` and the constraint solver enable solid dashboard compositions, though nested constraint solves require manual area computation.

**Error handling:** Validates negative dimensions (`width`, `height`, `tabWidth`, `maxWidth`, `maxHeight`, `padding`, `margin`) with `InvalidArgumentException`. Per-side border toggles reject >4 args. Most runtime edge cases (empty content, overflow) produce sensible results rather than throwing.

**Discovery:** `Theme::catalog()` and `Border::catalog()` enable `--list-themes` / `--list-borders` CLI patterns. The sheer number of `with*()` methods (40+) can be overwhelming; the short aliases (`fg`, `bg`, `on`, `pad`, `mg`, `of`) help significantly.

**Debuggability:** Immutable styles print cleanly via `var_export()`. The `propsSet` array can be inspected to understand which properties were explicitly set vs. defaulted. No built-in render tracing.

---

## Developer Experience Evaluation

**Type safety:** Strict typing throughout (`declare(strict_types=1)`), readonly properties, enum-backed constants, comprehensive PHPDoc. PHP 8.3+ only.

**Immutability pattern:** Consistent — `with()` private method with sentinel booleans (`fieldSet: true`) and `propsSet` tracking. The `withUnset()` / `withUnsetProp()` variants handle property reset. `copy()` for explicit shallow clone.

**Composition patterns:**
- `Style::inherit($parent)` — parent props fill unset slots in child
- `Style::patch($other)` — only explicitly-set props in `$other` apply
- `Style::resolveAdaptive(bool)` — collapse adaptive slots at runtime
- `Style::resolveProfile()` — collapse complete-color slots at render time

**Namespace ergonomics:** `SugarCraft\Sprinkles` for main types; `SugarCraft\Sprinkles\Layout` for constraint solver; `SugarCraft\Sprinkles\Border` for border-specific types; `SugarCraft\Sprinkles\Table` / `Tree` / `Listing` for structured renderers.

**Interop:** `SugarCraft\Core\Util\Color` from sugar-core is used throughout; `Width` utility from sugar-core handles ANSI-aware measurement; `Ansi` from sugar-core builds SGR sequences.

**Test patterns:** Snapshot assertions on raw `\x1b[...m` byte sequences (e.g., `assertSame("\x1b[1mhello\x1b[0m", ...)`). Behavior tests for state machines. Coercion tests for boundary inputs.

---

## Performance Notes

**Render cost:** `Style::render()` does multiple array operations (padding expansion, per-line width scan, border line construction, ANSI escape building, implode). For simple styles without borders/padding/alignment, the cost is ~O(content-length). Complex styles with multi-line content, borders, and transforms scale with line count.

**Constraint solver:** `Solver::solve()` is O(n) in constraint count. The one-pass greedy algorithm avoids the O(n²) worst case of naive Cassowary. No caching — every `split()` call recomputes.

**ANSI width measurement:** `Width::string()` is called repeatedly in layout loops (`joinHorizontal`, `place`, `Layout::width`, border line construction). Each call does `mb_strwidth()` after stripping ANSI. For long multi-line content, this is a hotspot. Caching the measured width of rendered blocks between layout passes would help.

**Immutable copying overhead:** `clone $this` in every setter and `new self(...)` for every `with()` call. With deeply nested style chains (e.g., 20 `->fg()->->bold()->->underline()` calls), this creates 20 intermediate objects. Not a concern for typical render-per-frame TUI usage; could matter in tight loops generating many styled strings.

**Canvas composition:** Layers are sorted by z-index via `usort` on each `render()`. The splice operation (`pasteRow`) calls `Width::truncateAnsi()` and `Width::dropAnsi()` per row, which is O(line-width) per layer row.

---

## Extensibility Analysis

**Adding new constraint types:** Extend `Constraint` abstract class with a new `Constraint::myType()` factory, add `instanceof MyType` branch in `Solver::solveHorizontal()`, add clamp redistribution logic if needed.

**Adding new border presets:** Add `public static function myBorder(): self` to `Border.php`, add name to `catalog()`. No other changes required.

**Adding new alignment modes:** Add case to `Align` or `VAlign` enum, update `halign()` or `vfit()` methods in `Style`. Only affects `Align::Center` right now.

**Adding new SGR modifiers:** Add property + getter + `with*()` setter + `buildContentSgr()` code emission + `unset*()` reseter. The `propsSet` tracking is key for correct `inherit()`.

**Custom color types:** Implement the `Color` interface (or extend the existing class) with `toFg(ColorProfile)`, `toBg(ColorProfile)`, `toUnderline(ColorProfile)` methods returning the appropriate SGR string for the profile.

**Custom data sources for Table:** Implement `Table\Data` interface (`rows()`, `columns()`, `at(int $r, int $c)`) and pass to `Table::data()`.

**Custom enumerators for Tree/List:** Implement the expected `Closure` signature (`fn(int $index, int $total): string` for List; `fn(bool $isLast): string` for Tree indenter; `Enumerator` instance for Tree connectors).

---

## Comparison Against Mapped Repositories

### charmbracelet/lipgloss (primary upstream)

| Feature | lipgloss | candy-sprinkles | Gap |
|---------|----------|-----------------|-----|
| Full SGR + underline styles | ✅ | ✅ | — |
| CIELAB blending (Blend1D/Blend2D) | ✅ (go-colorful) | Partial (linear RGB LERP) | BorderGradientBlend only, not full 2D |
| Word wrap integration in render | ✅ (Wrap) | ❌ (Width::wrap exists but not integrated) | `Style::render()` doesn't wrap |
| Text/Line/Span hierarchy | ✅ | ❌ | No multi-styled inline text type |
| StyleRunes | ✅ | ❌ | Sub-string styling not implemented |
| Layer/Compositor/Canvas | ✅ | ✅ | Canvas matches lipgloss v2 |
| Table column width constraints | ✅ | ❌ | Table has no per-column Constraint |
| WrapWriter (stateful ANSI tracking) | ✅ | ❌ | Streaming wrap not implemented |
| Terminal bg detection (OSC 11) | ✅ | ❌ | HasDarkBackground reads COLORFGBG only |
| Border.GetTopSize (grapheme-aware) | ✅ | ❌ | Border measurement assumes 1-cell runes |
| AdaptiveColor as Go color.Color | ✅ | ✅ (implements same role) | — |
| Global unset (props bitmask) | ✅ | ✅ (propsSet array) | Different internal representation |

**Verdict:** ~85% API surface coverage. Main gaps: word-wrap integration, CIELAB blending, StyleRunes, table column constraints, grapheme-aware border measurement.

### ratatui (constraint solver reference)

| Feature | ratatui | candy-sprinkles | Gap |
|---------|---------|-----------------|-----|
| Cassowary constraint solver | ✅ | ❌ (greedy only) | No inter-constraint ratio support |
| Length/Min/Max/Percentage/Ratio/Fill | ✅ | ✅ | Same constraint types |
| Flex alignment (Legacy/Centered/SpaceBetween) | ✅ | ❌ | No `Flex` alignment variants |
| Layout direction + margin | ✅ | ❌ | No margin support on Layout |
| Rect intersection/union helpers | ✅ | ❌ | Only `fromSize()` |

**Verdict:** Constraint solver covers common TUI use cases. Missing: inter-constraint ratio (A always ≥ 2× B), flex alignment modes, layout margins.

### php-tui/php-tui (style system reference)

| Feature | php-tui | candy-sprinkles | Gap |
|---------|---------|-----------------|-----|
| Style patch/merge | ✅ | ✅ | Comparable `patch()` / `inherit()` |
| Color: AnsiColor/RgbColor/LinearGradient | ✅ | ❌ (Color in sugar-core is simpler) | No linear gradients |
| Text/Line/Span hierarchy | ✅ | ❌ | No structured text types |
| Bitmask modifiers | ✅ | ✅ (PHP integer bitmask for propsSet) | Different approach |
| Widget/Visitor rendering | ✅ | ❌ | No widget trait system |
| Double-buffered diff rendering | ✅ | ❌ | Canvas is string-composed, not cell-diffed |

**Verdict:** Style system is more complete in php-tui (gradients, text hierarchy). candy-sprinkles has better API ergonomics (fluent vs. option bags).

### textualize/textual (layout engine reference)

| Feature | textual | candy-sprinkles | Gap |
|---------|----------|-----------------|-----|
| CSS-like layout engine (flex/grid) | ✅ | Partial (constraint solver) | No flexbox/grid |
| Reactive state + watchers | ✅ | ❌ | Not applicable (styling only) |
| CSS-like stylesheet (TCSS) | ✅ | ❌ | No TCSS parser |
| Widget DOM tree + query | ✅ | ❌ | Not a component system |
| Spatial map hit-testing | ✅ | ❌ | Only Canvas z-ordering |
| Animation/easing system | ✅ | ❌ | Not applicable |

**Verdict:** textual's layout engine is far more sophisticated (CSS flexbox/grid, spatial indexing). candy-sprinkles covers the common case with a simpler constraint solver.

### charmbracelet/bubbles (table/list/tree reference)

| Feature | bubbles | candy-sprinkles | Gap |
|---------|---------|-----------------|-----|
| Table with styleFunc | ✅ | ✅ | Same callback pattern |
| Table column navigation (KeyMsg) | ✅ | ❌ | Interactive navigation not included |
| Fuzzy filtering in List | ✅ | ❌ | `sahilm/fuzzy` not ported |
| Tree with inherited enumerator | ✅ | ✅ | Same inheritance pattern |
| List with nested sublists | ✅ | ✅ | Same |
| Viewport with soft-wrap line info | ✅ | ❌ | No per-line cursor tracking |

**Verdict:** Feature parity on table/list/tree renderers. Interactive features (keyboard navigation, fuzzy filtering) belong in sugar-bits, not styling.

### pterm (printer patterns reference)

| Feature | pterm | candy-sprinkles | Gap |
|---------|-------|-----------------|-----|
| TextPrinter interface (Sprint/Printf/...) | ✅ | ❌ | No equivalent interface |
| LivePrinter (cursor-based updates) | ✅ | ❌ | No cursor manipulation |
| RGB.Fade() multi-stop gradients | ✅ | ❌ | No Fade() method |
| AreaPrinter (dynamic region updates) | ✅ | ❌ | No cursor-based live updates |
| BigTextPrinter (ASCII art font map) | ✅ | ❌ | No ASCII art rendering |
| Interactive select with fuzzy | ✅ | ❌ | Interactive belongs to sugar-bits |
| Logger with slog bridge | ✅ | ❌ | Logging separate concern |

**Verdict:** pterm's printer interface pattern is a strong model for sugar-bits. The Fade() gradient and BigTextPrinter are unique to pterm.

### 76creates/stickers (flexbox layout reference)

| Feature | stickers | candy-sprinkles | Gap |
|---------|----------|-----------------|-----|
| Ratio-based responsive grid | ✅ | ❌ | Only fixed/percentage/min/fill |
| minWidth/minHeight constraints | ✅ | ❌ | Layout constraints lack minimum floors |
| ContentGenerator per cell | ✅ | ❌ | No dynamic content adaptation |
| FlexBox → Row → Cell chain | ✅ | ❌ | No row/cell abstraction in Layout |
| Copy accessors (no recalc trigger) | ✅ | ❌ | No dirty-flag lazy recalc |

**Verdict:** stickers' flexbox model with ratio-based sizing and minimum constraints is a significant advance over the current constraint solver. Would enable true responsive dashboard layouts.

---

## Cross-Repo Innovation Opportunities

### From lipgloss: 2D CIELAB gradients + Wrap integration

lipgloss's `Blend2D` uses CIELAB interpolation at rotated positions — the algorithm samples a 1D gradient at `(cos(θ)×x + sin(θ)×y, -sin(θ)×x + cos(θ)×y)` for each cell. This would enable heatmap-style visualizations directly in Style rendering. Additionally, lipgloss's `Wrap()` / `WrapWriter` should be integrated into `Style::render()` for automatic word-wrap.

**File reference:** `candy-sprinkles/src/Style.php` — `render()` method at line 915; color blending at `candy-core/src/Util/Color.php`

### From ratatui: Flex alignment variants

ratatui's `Flex` enum (Legacy/Centered/SpaceBetween/SpaceAround) distributes slack differently:
- `Legacy`: First child absorbs all slack
- `Centered`: Slack split evenly left/right (or top/bottom)
- `SpaceBetween`: First and last child flush to edges, slack distributed evenly between remaining
- `SpaceAround`: Half-slack on each end, rest distributed evenly

**File reference:** `candy-sprinkles/src/Layout/Solver.php` — distribute slack section (lines 102-149)

### From stickers: Ratio-based layout with min constraints

The stickers `calculateRatioWithMinimum()` algorithm handles minimum dimensions as hard floors that take priority over ratio allocation. When a cell's minimum exceeds its ratio share, it's locked and the remaining space is redistributed among non-locked cells recursively. This enables true CSS flexbox-style responsive layouts.

**File reference:** `candy-sprinkles/src/Layout/Solver.php` — `applyMaxClamp()` at line 173 handles Max but not Min-on-ratio.

### From textual: CSS layout engine

Textual's CSS layout parser + flexbox/grid layout algorithms could form the basis of a `candy-sprinkles`-compatible layout engine. The parser handles box model (margin, border, padding, content), flex properties (flex-grow/shrink/basis, flex-direction, flex-wrap, gap), and grid (grid-template-columns/rows, grid-area). This is a long-term undertaking but would make SugarCraft as powerful as Textual for complex layouts.

### From php-tui: Text/Line/Span hierarchy

php-tui's `Text` (collection of Lines) → `Line` (single row) → `Span` (styled substring) pattern provides structured multi-styled inline text. Adding `SugarCraft\Sprinkles\Text` / `Line` / `Span` types would enable paragraph-level rendering with mixed styling inline — currently only `Markup::parse()` provides this at cell granularity.

**File reference:** `candy-sprinkles/src/Markup.php` — returns `list<Cell>` not structured text

### From pterm: RGB.Fade() multi-stop gradients

pterm's `RGB.Fade(min, max float32, end ...RGB)` interpolates through N color stops. This could be added to `Color` in sugar-core and used in `Style` for gradient text/background effects.

**File reference:** `candy-core/src/Util/Color.php` — `blend()` exists but no Fade with multiple stops

---

## Missing Features

1. **Word-wrap integration in Style::render()** — `Width::wrap()` exists in sugar-core but isn't called during render; multi-line content without explicit `\n` placement requires manual wrapping.

2. **CIELAB color blending** — `Color::blend()` uses linear RGB LERP; lipgloss uses `lucasb-eyer/go-colorful` for perceptually uniform CIELAB D65. The `BorderGradientBlend` would benefit from CIELAB for smoother gradients.

3. **2D rectangular gradient fills** — `Blend2D` for heatmap-style cell coloring.

4. **Table column width constraints** — lipgloss table has `Width(Constraint)` per column; candy-sprinkles Table auto-fits only.

5. **Inter-constraint ratio support in layout** — `Constraint::ratio(1, 2)` is ratio-of-total-area, not ratio-of-other-constraint (e.g., "column A is always 2× column B"). Cassowary needed for proper constraint solving.

6. **Text/Line/Span hierarchy** — no structured multi-styled inline text type.

7. **StyleRunes for sub-string styling** — apply different styles to specific rune index ranges in a string.

8. **Terminal background detection via OSC 11** — `HasDarkBackground()` uses `COLORFGBG` env var; doesn't query terminal directly like lipgloss's `queryBackgroundColor()`.

9. **Grapheme-aware border measurement** — `Border::GetTopSize()` assumes 1-cell runes; doesn't handle emoji/wide chars like lipgloss's `displaywidth` integration.

10. **Flex alignment variants** — `Flex::Legacy|Centered|SpaceBetween|SpaceAround` for layout slack distribution.

---

## Smarter Implementations To Adopt

### From lipgloss: `WrapWriter` for ANSI-safe streaming wrap

lipgloss's `WrapWriter` tracks current style + link state across newlines, emitting `ansi.ResetStyle` before each newline and re-applying the tracked style after — ensuring hyperlinks and styles survive line breaks. A PHP equivalent would be valuable for `Viewport` and text-area components in sugar-bits.

**Implementation hint:** State machine tracking current SGR attributes + OSC 8 link state; reset before `\n`, replay after.

### From stickers: Ratio-based layout with minimum constraints

The `calculateRatioWithMinimum()` algorithm recursively handles minimum floors before ratio allocation. This would make the layout solver significantly more powerful for dashboard layouts with sidebar/main/content patterns.

### From textual: Spatial map for Canvas hit-testing

Textual's `_spatial_map.SpatialMap` (R-tree-like) for O(log n) widget lookup by screen position would enable mouse event routing in Canvas. Currently z-only sort, no x/y hit detection.

### From pterm: `TextPrinter` interface pattern

Every pterm printer implements `TextPrinter` with 8 methods (Sprint/Sprintf/Sprintln/Sprintfln × return string vs. write). This consistency enables generic code like `func Print[T TextPrinter](t T) { t.Println("msg") }`. Adopting this in sugar-bits would make the component API uniform.

### From php-tui: `Buffer::diff()` cell-level diffing

php-tui's `Display::diff()` computes minimal cell updates between two buffers. For Canvas, adopting cell-level diffing instead of full string composition would enable efficient partial redraws in animation scenarios.

---

## High-Value Refactors

### 1. Extract `Color` to a shared interface in sugar-core

Currently `Color::hex()`, `Color::ansi()`, `Color::ansi256()`, `Color::hsl()`, `Color::parse()` live in `SugarCraft\Core\Util\Color`. They should implement a `ColorValueObject` interface that `Style` consumes, allowing third-party color types to be injected.

**Affected files:** `candy-sprinkles/src/Style.php` (imports `SugarCraft\Core\Util\Color`), `candy-core/src/Util/Color.php`

### 2. Unify `Markup::parse()` and `StyleParser` into a single text model

`Markup::parse()` returns `list<Cell>`, while `StyleParser` returns styled strings. These should converge on a `SugarCraft\Sprinkles\Text` / `Line` / `Span` hierarchy enabling both cell-granularity and string-level operations on multi-styled text.

### 3. Add layout margin support to `Layout::split()`

ratatui's `Layout` has a `margin` parameter on `split()`. Adding `Margin` as a constraint property (or a separate `Layout::splitWithMargin()`) would enable gutter spacing without manual Space/Fill manipulation.

### 4. Integrate `Width::wrap()` into `Style::render()`

When `Style` has `width()` set and multi-line content, automatic word-wrap should apply. Currently content is only truncated, not wrapped. The `Width::wrap()` function in sugar-core should be called in `render()` when width constraints are active.

### 5. Implement `Border::GetTopSize()` grapheme-aware measurement

`Border` should track the maximum visual width of its edge runes using `mb_strwidth()` (from sugar-core's Width utility). This ensures correct frame size computation when borders contain emoji or wide characters.

---

## Architectural Recommendations

### Short-term (within current architecture)

1. **Add `WrapWriter` equivalent** — Create `SugarCraft\Sprinkles\WrapWriter` that tracks ANSI style state across lines for proper hyperlink/style continuation in wrapped text. Use in `Style::render()` when `width()` is set.

2. **Add CIELAB blending to `Color`** — Extend `Color::blend()` to accept a `ColorSpace` parameter (LinearRGB/CIELAB). Use when building `BorderGradientBlend` from multiple colors.

3. **Add `Table\ColumnWidth` constraint** — Add `widthConstraint(Constraint)` method to `Table` mirroring lipgloss's table width API. Internally use the constraint solver to compute column widths.

4. **Add flex alignment to layout solver** — Implement `Flex::Centered|SpaceBetween|SpaceAround` variants in `Solver`. Small addition, large UX improvement for dashboard layouts.

### Medium-term (new subsystems)

5. **Text/Line/Span text model** — Create structured text types mirroring php-tui's hierarchy:
   ```
   Span: content (string) + style (Style)
   Line: list<Span>
   Text: list<Line>
   ```
   This enables `Style::renderText(Text)` with proper multi-styled inline rendering. `Markup::parse()` becomes a parser producing `Text`.

6. **StyleRunes implementation** — Create `StyleRunes(string, indices, matched, unmatched)` function applying different styles to specific rune index ranges. Uses `Width::string()` to map indices to visual positions.

### Long-term (new capabilities)

7. **Cassowary constraint solver** — Replace the greedy solver with a proper Cassowary algorithm (same algorithm as Apple Auto Layout, ratatui, php-tui/cassowary). Enables expressing "column A is at least twice as wide as column B" constraints. This is a significant algorithmic undertaking (~1000+ lines).

8. **CSS flexbox layout engine** — Following textual's model, implement a CSS flexbox parser + layout algorithm. This would make candy-sprinkles as powerful as textual for complex responsive layouts. High effort, transformative impact.

9. **Canvas cell-level diffing** — Replace string-composition rendering with a cell buffer that tracks foreground color, background color, and modifiers per cell. `Canvas::render()` would diff current vs. previous buffer and only emit changed cells.

---

## Quick Wins

1. **`Flex` alignment in `Solver`** — Add `SpaceBetween` / `SpaceAround` / `Centered` distribution of slack when multiple `Fill` constraints exist. 20 lines of code in `Solver::solveHorizontal()`.

2. **`Color::blend2D()` for heatmaps** — Implement `Blend2D(tl, tr, bl, br, width, height, angle)` using the rotated 1D gradient sampling algorithm from lipgloss. ~50 lines in `Color`.

3. **`BorderGradientBlend` CIELAB mode** — Add optional `useCIELAB: true` flag to `BorderGradientBlend::fromColors()` that uses CIELAB D65 interpolation instead of linear RGB LERP.

4. **`Table::columnWidth(Constraint)`** — Add per-column width constraints using the constraint solver internally. ~30 lines in `Table::render()`.

5. **`Style::marginBackground(string $char)`** — Analogous to `paddingChar()` / `marginChar()`, allows filling margin area with a custom character (e.g., `▓` for a solid bar). Already has the plumbing; just needs the char field.

6. **`Layout::measure(string): array`** — Already exists as `Layout::size()`. Just needs better PHPDoc and promotion in the README — developers often don't know to use it for budgeting.

7. **`Theme::withBorderColor(Color)` / `Theme::withSeparatorColor(Color)`** — Add convenience setters for the 13 named color slots using the existing `with*()` pattern.

---

## Long-Term Opportunities

### Full lipgloss v2 parity

The remaining 15% of lipgloss features (Blend2D, Wrap integration, StyleRunes, terminal OSC 11 detection, table column constraints, grapheme-aware border measurement) would make candy-sprinkles a complete lipgloss v2 port.

### Cassowary solver as separate package

Extract the constraint solver into `sugarcraft/honey-layout` or similar, making it reusable across sugar-charts (for axis layout), sugar-bits (for component internal layout), and any future layout-sensitive library. This would mirror how ratatui's layout is a separate crate.

### Declarative layout via TCSS

Adopting a subset of textual's TCSS syntax (`display: flex; flex-direction: row; gap: 1;`) as a layout description format would enable complex responsive layouts declaratively. Parser + layout engine would be a new sub-namespace.

### Canvas as a proper cell buffer

Replacing the current string-composition model with a `Buffer` class (grid of cells with fg color, bg color, modifiers, and rune) would enable:
- Efficient partial redraw (only changed cells written)
- True alpha blending between layers
- Mouse hit-testing via spatial index
- Eventually, a proper widget rendering system

### Integration with sugar-bits interactive components

The `Renderer::fromEnvironment()` should be used to pre-configure styles in interactive components (TextInput, Viewport, etc.) so they respect terminal color profiles automatically. Currently each component independently calls `ColorProfile::detect()`.

---

## Notable Clever Implementations Found Elsewhere

### lipgloss: `props int64` bitmask for property presence

lipgloss stores property presence in a `propKey` bitmask with named constants (`propKeyForeground = 1 << 0`, etc.). This enables compact `props.has(k)` / `props.set(k)` / `props.unset(k)` operations. While PHP doesn't have integer bitmask constants as a first-class feature, a similar approach using a single integer with bit shifts could reduce the `propsSet` array overhead in `Style`.

**Location:** `lipgloss/props.go`

### lipgloss: `StyleRunes` via byte-position-to-grapheme mapping

`StyleRunes(str, indices, matched, unmatched)` maps byte indices to grapheme cluster positions using `uniseg.NewGraphemes()`. This enables highlighting matched characters in fuzzy search results regardless of whether the match spans multi-byte characters.

**Location:** `lipgloss/runes.go`

### stickers: Copy accessors to avoid recalc cascades

`GetRowCopy()` / `GetCellCopy()` return clones rather than triggering the `recalculateFlag`. This prevents layout recalculation during read-only inspection (e.g., checking cell dimensions for external layout decisions).

**Location:** `76creates/stickers/flexbox/flexbox.go`

### pterm: `MapRangeToRange` for progress visualization

```go
func MapRangeToRange(fromMin, fromMax, toMin, toMax, current float32) int {
    if fromMax-fromMin == 0 { return 0 }
    return int(toMin + ((toMax-toMin)/(fromMax-fromMin))*(current-fromMin))
}
```
Used for: bar chart values → terminal positions, RGB color fades, keyboard navigation offsets. A foundational utility that should be in sugar-core or honey-bounce.

**Location:** `pterm/internal/map_range_to_range.go`

### pterm: `InteractiveSelectPrinter` virtual scrolling

Only `MaxHeight` options are rendered at a time; `displayedOptions` window tracks the visible slice. Combined with `lithammer/fuzzysearch` ranking, this handles thousands of options without performance degradation.

**Location:** `pterm/interactive_select_printer.go`

### textual: Spatial map for O(log n) hit testing

Textual's compositor uses an R-tree-like spatial index for widget lookup by screen position. This enables efficient mouse event routing to the correct widget without O(n) iteration.

**Location:** `textualize/textual/src/layout/compositor.rs`

### textual: Lazy widget loading via `__getattr__`

Widgets are only imported when first referenced via `__getattr__` in `widgets/__init__.py`. This reduces startup time and import footprint significantly for large widget libraries.

**Location:** `textualize/textual/src/widgets/__init__.py`

### ratatui: `Stylize` trait for ergonomic styling

```rust
"hello".red().on_blue().bold() // → Span
Text::from("hello").red().blue() // → Text
Block::bordered().red() // → Block
```
PHP could achieve similar via fluent methods on `Style` (already present) but extending to primitive types (strings, arrays) via `__call` magic or an extension would be powerful.

**Location:** `ratatui/src/style.rs`

---

## Suggested Roadmap

### Phase 1: Polish & Parity (1-2 PRs)

1. Add `Flex::SpaceBetween|SpaceAround` alignment to `Solver` (quick win)
2. Add CIELAB blending mode to `BorderGradientBlend`
3. Add `Table::columnWidth(Constraint)` per-column constraint
4. Add `Style::marginBackground(string $char)` char fill variant
5. Add `Color::blend2D()` for heatmap gradients
6. Fix `Solver` so `Min` takes extra slack proportionally (currently under-explains — see `testMinWithExtraSlack` comment in `LayoutSolverTest.php`)

### Phase 2: Word-Wrap + Text Model (2-3 PRs)

1. Create `WrapWriter` class tracking ANSI state across newlines
2. Integrate `Width::wrap()` into `Style::render()` when `width()` is set
3. Create `Text`/`Line`/`Span` hierarchy
4. Refactor `Markup::parse()` to return `Text` instead of `list<Cell>`
5. Add `StyleRunes` implementation

### Phase 3: Layout Engine (2-3 PRs)

1. Add layout margin support to `Layout::split()`
2. Implement `Border::GetTopSize()` grapheme-aware measurement
3. Add `Layout::gap(int)` for inter-element spacing (sugar-baked gap handling)
4. Consider extracting constraint solver to separate package for reuse

### Phase 4: Canvas Enhancements (1-2 PRs)

1. Add x/y spatial map for hit-testing mouse events on layers
2. Implement `Canvas::diff()` for efficient partial redraws
3. Add `Layer::withContent(string)` for in-place layer content updates

### Phase 5: Advanced Layout (Future)

1. Cassowary constraint solver for inter-constraint ratios
2. CSS flexbox layout parser + engine
3. Full TCSS stylesheet parser

---

## Conclusion

CandySprinkles is a well-executed port of charmbracelet/lipgloss with solid coverage of the core styling and layout API surface. Its immutable + fluent pattern, comprehensive ANSI support, and CSS-like shorthand make it ergonomic for common use cases. The constraint solver handles 80% of dashboard layout scenarios correctly but lacks the inter-constraint ratio support that Cassowary provides. The main gaps relative to lipgloss are word-wrap integration, CIELAB blending, StyleRunes, table column constraints, and grapheme-aware border measurement. The Canvas/Layer system is a strong foundation for overlay UIs. The suggested roadmap prioritizes quick wins (flex alignment, CIELAB blending) over medium-effort high-value features (word-wrap, text model) with a future path toward a full Cassowary solver and CSS flexbox engine.
