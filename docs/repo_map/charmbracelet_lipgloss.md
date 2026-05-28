# charmbracelet/lipgloss

## Metadata
- **URL:** https://github.com/charmbracelet/lipgloss
- **Language:** Go
- **Stars:** ~3,500+ (mature, widely-adopted TUI styling library from the Charmbracelet ecosystem)
- **License:** MIT
- **Module:** `charm.land/lipgloss/v2`
- **go.mod:** Go 1.25.0
- **Key Dependencies:** `charmbracelet/colorprofile`, `charmbracelet/ultraviolet`, `charmbracelet/x/ansi`, `lucasb-eyer/go-colorful`, `clipperhouse/displaywidth`, `rivo/uniseg`
- **Description:** "Style definitions for nice terminal layouts. Built with TUIs in mind." Lip Gloss takes an expressive, declarative approach to terminal rendering — users familiar with CSS will feel at home.

---

## Feature List

### Core Styling
- **ANSI text formatting:** Bold, Italic, Faint, Blink, Strikethrough, Reverse, Underline
- **Underline styles:** None, Single, Double, Curly, Dotted, Dashed — with optional custom underline color
- **Foreground/Background colors:** ANSI 16-color, ANSI 256-color, TrueColor (24-bit hex), `NoColor` sentinel
- **Hyperlinks:** OSC 8 clickable hyperlinks with graceful degradation in unsupported terminals
- **Margins and Padding:** CSS-like shorthand (1 value = all sides; 2 = vertical/horizontal; 3 = top/horizontal/bottom; 4 = clockwise from top)
- **Custom fill characters:** `PaddingChar('·')`, `MarginChar('░')` for block-fill effects; NBSP (`\u00A0`) for non-breaking padding

### Borders
- **Preset border styles:** `NormalBorder`, `RoundedBorder`, `BlockBorder`, `ThickBorder`, `DoubleBorder`, `OuterHalfBlockBorder`, `InnerHalfBlockBorder`, `HiddenBorder`, `MarkdownBorder`, `ASCIIBorder`
- **Per-side border control:** `BorderTop`, `BorderRight`, `BorderBottom`, `BorderLeft`
- **Per-side colors:** `BorderForeground` / `BorderBackground` with individual side accessors
- **Gradient borders:** `BorderForegroundBlend(...)` with `BorderForegroundBlendOffset` — CIELAB-interpolated colors around all four sides
- **Custom border definitions:** `Border{Top, Bottom, Left, Right, TopLeft, TopRight, BottomLeft, BottomRight, ...}` struct

### Layout & Geometry
- **Width/Height constraints:** `Width(n)`, `Height(n)` — text wraps at computed boundary after subtracting padding and borders
- **Inline mode:** `Inline(true)` forces single-line rendering; `MaxWidth(n)` / `MaxHeight(n)` truncates at render time without affecting layout
- **Horizontal/Vertical alignment:** `Align(lipgloss.Left|Center|Right)`, `AlignHorizontal`, `AlignVertical`
- **Tab handling:** `TabWidth(n)` — converts `\t` to n spaces (default 4); `NoTabConversion` constant disables conversion

### Color System
- **Automatic downsampling:** Detects terminal color profile (ANSI/ANSI256/TrueColor) and strips styles entirely when output is not a TTY
- **Adaptive colors:** `LightDark(hasDarkBackground)` helper returns light/dark variant at runtime
- **Complete colors:** `Complete(profile)` helper picks ANSI/ANSI256/TrueColor triple at runtime
- **Color utilities:** `Darken(c, 0.5)`, `Lighten(c, 0.35)`, `Complementary(c)`, `Alpha(c, 0.2)` — all using CIELAB color space
- **Color blending:** `Blend1D(steps, stops...)` for 1D gradients; `Blend2D(width, height, angle, stops...)` for 2D rotated gradients using CIELAB interpolation
- **Terminal background detection:** `HasDarkBackground(os.Stdin, os.Stdout)` queries terminal via OSC 11; Windows-compatible with `CONIN$/CONOUT$` fallback

### Text Utilities
- **Width/Height measurement:** `Width(str)` and `Height(str)` correctly handle ANSI escape sequences and wide Unicode characters (CJK, emoji)
- **Text wrapping:** `Wrap(s, width, breakpoints)` preserves ANSI styles and hyperlinks across line breaks
- **Text joining:** `JoinHorizontal(pos, blocks...)` and `JoinVertical(pos, blocks...)` with Position type (0–1 range, Top=0, Bottom=1, Center=0.5)
- **Text placement:** `Place(width, height, hPos, vPos, str, opts...)`, `PlaceHorizontal`, `PlaceVertical` — fills whitespace around content; customizable via `WithWhitespaceChars`, `WithWhitespaceStyle`

### Sub-packages
- **`table`:** Full table renderer with `Headers()`, `Rows()`, `Row()`, `StyleFunc(row, col)` for per-cell styling, `Border()`, `MarkdownBorder()`, `ASCIIBorder()`, column `Width()` constraints, resizing, and row/column filtering
- **`list`:** Nested list renderer with enumerators (`Bullet`, `Arabic`, `Alphabet`, `Roman`, `Tree`), custom enumerator function support, `ItemStyle()` and `EnumeratorStyle()`, incremental `Item()` building
- **`tree`:** Tree renderer with `Root()`, `Child()`, custom `Enumerator()` functions (`DefaultEnumerator`, `RoundedEnumerator`), `RootStyle()`, `ItemStyle()`, `EnumeratorStyle()`, incremental building
- **`compat`:** v1 migration helpers: `AdaptiveColor`, `CompleteColor`, `CompleteAdaptiveColor` — globally reads stdin/stdout at init time (not recommended for new code)

### Layer Compositing
- **`Layer`:** Visual layer with `content`, `X/Y/Z` positioning, `ID()` for hit-testing, child layer support via `AddLayers()`
- **`Compositor`:** Flattens layer hierarchy, sorts by z-index, provides `Draw(scr, area)`, `Hit(x, y)` for mouse click resolution, `Bounds()` for overall extent
- **`Canvas`:** Cell-buffer implementing `uv.Screen` and `uv.Drawable`; `Compose(drawable)` layers content; `Render()` outputs styled string

### Output Utilities
- **Writer functions:** `Print`, `Println`, `Printf`, `Fprint`, `Fprintln`, `Fprintf`, `Sprint`, `Sprintln`, `Sprintf` — drop-in fmt replacements that auto-downsample color and strip styles for non-TTY

---

## Key Classes and Methods

### `Style` — central style definition
Fluent, pure-value type. All setters return a new `Style`; assignment creates a true copy.

**Setters (all return `Style`):**
- `Bold(bool)`, `Italic(bool)`, `Faint(bool)`, `Blink(bool)`, `Reverse(bool)`, `Strikethrough(bool)`
- `Underline(bool)` / `UnderlineStyle(Underline)` / `UnderlineColor(color.Color)` / `UnderlineSpaces(bool)` / `StrikethroughSpaces(bool)`
- `Foreground(color.Color)`, `Background(color.Color)`
- `Width(int)`, `Height(int)`, `Align(...Position)`, `AlignHorizontal(Position)`, `AlignVertical(Position)`
- `Padding(...int)`, `PaddingTop(int)`, `PaddingRight(int)`, `PaddingBottom(int)`, `PaddingLeft(int)`, `PaddingChar(rune)`
- `Margin(...int)`, `MarginTop(int)`, `MarginRight(int)`, `MarginBottom(int)`, `MarginLeft(int)`, `MarginBackground(color.Color)`, `MarginChar(rune)`
- `Border(Border, ...bool)`, `BorderStyle(Border)`, `BorderTop(bool)`, `BorderRight(bool)`, `BorderBottom(bool)`, `BorderLeft(bool)`
- `BorderForeground(...color.Color)`, `BorderTopForeground`, `BorderRightForeground`, `BorderBottomForeground`, `BorderLeftForeground`, `BorderForegroundBlend(...color.Color)`, `BorderForegroundBlendOffset(int)`
- `BorderBackground(...color.Color)`, `BorderTopBackground`, `BorderRightBackground`, `BorderBottomBackground`, `BorderLeftBackground`
- `Inline(bool)`, `MaxWidth(int)`, `MaxHeight(int)`, `TabWidth(int)`
- `Transform(func(string) string)`, `Hyperlink(url string, params ...string)`
- `Inherit(Style)` — overlays unset properties from another style (margins/padding excluded)

**Getters (defined in `get.go`):**
- `GetBold()`, `GetItalic()`, `GetUnderline()`, `GetUnderlineStyle()`, `GetUnderlineColor()`
- `GetForeground()`, `GetBackground()`, `GetWidth()`, `GetHeight()`
- `GetAlign()`, `GetAlignHorizontal()`, `GetAlignVertical()`
- `GetPadding()` → `(top, right, bottom, left)`, `GetPaddingTop/Right/Bottom/Left()`, `GetPaddingChar()`, `GetHorizontalPadding()`, `GetVerticalPadding()`
- `GetMargin()` → `(top, right, bottom, left)`, `GetMarginTop/Right/Bottom/Left()`, `GetMarginChar()`, `GetHorizontalMargins()`, `GetVerticalMargins()`
- `GetBorder()` → `(b Border, top, right, bottom, left bool)`, `GetBorderStyle()`, `GetBorderTop/Right/Bottom/Left()`, `GetBorderTop/Right/Bottom/LeftForeground/Background()`, `GetBorderForegroundBlend()`, `GetBorderForegroundBlendOffset()`
- `GetHorizontalBorderSize()`, `GetVerticalBorderSize()`, `GetFrameSize()` → `(h, v)`
- `GetInline()`, `GetMaxWidth()`, `GetMaxHeight()`, `GetTabWidth()`, `GetTransform()`, `GetHyperlink()` → `(link, params)`

**Rendering:**
- `Render(strs ...string) string` — applies all style rules to produce ANSI-encoded string
- `String() string` — implements `fmt.Stringer`; calls `Render()` if `SetString()` was used
- `SetString(strs ...string) Style` — sets the underlying string value for Stringer use
- `Value() string` — returns raw unformatted string
- `Copy()` — deprecated alias for assignment (true copy is free via `s2 := s1`)

**Unsetters (defined in `unset.go`):** `UnsetBold()`, `UnsetItalic()`, `UnsetUnderline()`, `UnsetStrikethrough()`, `UnsetReverse()`, `UnsetBlink()`, `UnsetFaint()`, `UnsetForeground()`, `UnsetBackground()`, `UnsetWidth()`, `UnsetHeight()`, `UnsetAlign()`, `UnsetPaddingMarginsBorder()`, etc. When unset, properties are excluded from inheritance/copy.

### `Border` struct
Contains 13 string fields: `Top`, `Bottom`, `Left`, `Right`, `TopLeft`, `TopRight`, `BottomLeft`, `BottomRight`, `MiddleLeft`, `MiddleRight`, `Middle`, `MiddleTop`, `MiddleBottom`.
Methods: `GetTopSize()`, `GetRightSize()`, `GetBottomSize()`, `GetLeftSize()` — return max rune width across edge components.

### `Layer` struct (layer.go)
- `NewLayer(content string, layers ...*Layer) *Layer`
- `GetContent()`, `Width()`, `Height()`, `GetID()`
- `ID(id string) *Layer`, `X(int) *Layer`, `Y(int) *Layer`, `Z(int) *Layer`
- `GetX()`, `GetY()`, `GetZ()`, `AddLayers(...*Layer)`
- `GetLayer(id string) *Layer` — recursive ID lookup
- `MaxZ() int` — max z-index of self + descendants
- `Draw(scr uv.Screen, area uv.Rectangle)` — implements `uv.Drawable`

### `Compositor` struct (layer.go)
- `NewCompositor(layers ...*Layer) *Compositor`
- `AddLayers(...*Layer) *Compositor`
- `Bounds() image.Rectangle`
- `Draw(scr uv.Screen, area image.Rectangle)`
- `Hit(x, y int) LayerHit` — returns topmost layer at coordinates
- `GetLayer(id string) *Layer`
- `Refresh()` — re-flattens hierarchy after position changes
- `Render() string` — renders to styled string

### `Canvas` struct (canvas.go)
- `NewCanvas(width, height int) *Canvas`
- `Resize(width, height)`, `Clear()`
- `Compose(drawable uv.Drawable) *Canvas` — layers onto canvas
- `Draw(scr uv.Screen, area uv.Rectangle)` — renders canvas to screen
- `Render() string` — outputs styled string

### `Position` type (position.go)
`float64` alias with `math.Min(1, math.Max(0, float64(p)))` clamping. Constants: `Top=0.0`, `Bottom=1.0`, `Center=0.5`, `Left=0.0`, `Right=1.0`.

### `Color(...)` function (color.go)
- Parses `#RGB`, `#RRGGBB` hex strings, or ANSI integer values
- Returns `ansi.BasicColor` (< 16), `ANSIColor` (< 256), or `color.RGBA` (TrueColor)
- Named ANSI constants: `Black`, `Red`, `Green`, `Yellow`, `Blue`, `Magenta`, `Cyan`, `White`, `Bright*` variants

### Color utility functions (color.go)
- `Darken(c color.Color, percent float64)` — reduces luminance
- `Lighten(c color.Color, percent float64)` — increases luminance
- `Complementary(c color.Color)` — rotates hue 180°
- `Alpha(c color.Color, alpha float64)` — adjusts alpha (0–1)
- `LightDark(bool) func(light, dark color.Color) color.Color` — terminal bg-aware color picker
- `Complete(colorprofile.Profile) func(ansi, ansi256, truecolor color.Color) color.Color`

### Blending functions (blending.go)
- `Blend1D(steps int, stops ...color.Color) []color.Color` — 1D CIELAB gradient
- `Blend2D(width, height int, angle float64, stops ...color.Color) []color.Color` — 2D rotated CIELAB gradient

### Layout utilities (position.go, join.go)
- `Place(width, height int, hPos, vPos Position, str string, opts ...WhitespaceOption)` — places text in whitespace
- `PlaceHorizontal(width int, pos Position, str string, opts ...WhitespaceOption)` — horizontal placement
- `PlaceVertical(height int, pos Position, str string, opts ...WhitespaceOption)` — vertical placement
- `JoinHorizontal(pos Position, strs ...string)` — horizontal join along vertical axis
- `JoinVertical(pos Position, strs ...string)` — vertical join along horizontal axis

### Measurement utilities (size.go)
- `Width(str string) int` — cell width (ANSI-aware, wide-char-aware)
- `Height(str string) int` — line count + 1
- `Size(str string) (width, height int)`

### Table sub-package (`charm.land/lipgloss/v2/table`)
- `New() *Table`
- `Headers(...string)`, `Rows(...[]string)`, `Row(...any)`
- `StyleFunc(StyleFunc)` — per-cell styling
- `Border(lipgloss.Border)`, `BorderStyle(lipgloss.Style)`, `BorderTop/Bottom/Left/Right(bool)`
- `Width(constraint)` — column width constraint
- `StyleFunc` type: `func(row, col int) lipgloss.Style`; `HeaderRow` constant = -1

### List sub-package (`charm.land/lipgloss/v2/list`)
- `New(items ...any) *List`
- `Items(...any) *List`, `Item(any) *List`
- `Enumerator(func(Items, int) string)`, `EnumeratorStyle(lipgloss.Style)`, `ItemStyle(lipgloss.Style)`, `ItemStyleFunc(func(Items, int) lipgloss.Style)`
- `Indenter(func(Items, int) string)`
- Predefined enumerators: `Bullet`, `Arabic`, `Alphabet`, `Roman`, `Tree`

### Tree sub-package (`charm.land/lipgloss/v2/tree`)
- `New() *Tree`, `Root(any) *Tree`, `Child(...any) *Tree`
- `Enumerator(func(Node) string)`, `EnumeratorStyle(lipgloss.Style)`, `RootStyle(lipgloss.Style)`, `ItemStyle(lipgloss.Style)`
- Predefined enumerators: `DefaultEnumerator`, `RoundedEnumerator`
- Node interface: `Value() string`, `Children() Children`, `Hidden() bool`, `SetHidden(bool)`, `SetValue(any)`
- `Leaf` struct implements Node

### Writer / output (writer.go — implicit fmt replacements)
- `Print`, `Println`, `Printf`, `Fprint`, `Fprintln`, `Fprintf`, `Sprint`, `Sprintln`, `Sprintf` — all auto-downsample colors

### `Wrap` and `WrapWriter` (wrap.go)
- `Wrap(s string, width int, breakpoints string) string` — wraps text preserving ANSI
- `NewWrapWriter(io.Writer) *WrapWriter` — stateful writer tracking pen style + link state across newlines

### `StyleRunes` (runes.go)
- `StyleRunes(str string, indices []int, matched, unmatched Style) string` — applies different styles to specific rune indices

---

## Notable Algorithms / Named Patterns

### Bitmask Property Storage (`props int64`)
The `Style` struct stores which properties are set using a `props int64` bitmask (defined via `propKey` constants shifting `1 << iota`). This enables compact `props.has(k)`, `props.set(k)`, `props.unset(k)` operations. Bool properties are additionally stored in `attrs int` with `attrs |= int(key)` — `set()` dispatches both. This dual representation (bitmask for presence + attrs for bool values) is a named pattern throughout the codebase.

### CIELAB Color Blending
`Blend1D` and `Blend2D` (in `blending.go`) use the CIE L\*a\*b\* color space (`lucasb-eyer/go-colorful`) rather than RGB for perceptual uniformity. The `ensureNotTransparent()` helper repairs alpha lost in RGB→RGBA conversion. `Blend2D` computes a diagonal 1D gradient, then samples it at rotated (x, y) positions using `math.Cos`/`math.Sin` rotation.

### CSS-like Shorthand Resolution
`whichSidesInt`, `whichSidesBool`, `whichSidesColor` in `set.go` implement CSS shorthand: 1 arg = all sides; 2 args = vertical/horizontal; 3 args = top/horizontal/bottom; 4 args = clockwise from top. The same pattern appears in `Padding()`, `Margin()`, `Border()`, `BorderForeground()`, `BorderBackground()`.

### Immutable/Fluent Style Pattern
All setter methods on `Style` return `Style` (not `*Style`). Calling `s.Bold(true)` does not mutate `s` — it returns a new `Style` with `bold` set. Assignment (`s2 := s1`) creates a true copy because `Style` is a value type. The one exception is `Inline()`, `MaxWidth()`, `MaxHeight()` which explicitly note "this method will not mutate the style and instead return a copy."

### Layer Compositing with Z-Index Sort
`Compositor.flatten()` recursively collects all layers with absolute positions, indexes by `ID`, sorts by `layer.z` ascending, then draws from lowest to highest so later layers appear on top. `Hit()` checks from highest z to lowest (reverse iteration) to return the topmost layer.

### ANSI-Safe Text Wrapping
`WrapWriter` (wrapping.go) parses ANSI sequences via `ansi.Parser`, tracks current `uv.Style` and `uv.Link` state, emits `ansi.ResetStyle` before each newline, then re-applies the tracked style after — ensuring styles and hyperlinks survive line breaks.

### Terminal Background Color Query
`queryBackgroundColor` (terminal.go) writes OSC 11 (`\033]11;?\007`) to the terminal and reads the response using a state machine (`ansi.DecodeSequence`) with a 2-second timeout. Windows uses `CONIN$/CONOUT$` console handles explicitly when stdin/stdout are redirected.

### Adaptive Color via `Color` Interface
`AdaptiveColor`, `CompleteColor`, and `CompleteAdaptiveColor` (in `compat/`) implement Go's `color.Color` interface (`RGBA() (r,g,b,a uint32)`), allowing them to be passed directly to `lipgloss.Color()`. The `RGBA()` method performs runtime detection and returns the appropriate variant, making adaptive colors transparent to `Style.Render()`.

### Unicode Grapheme-Aware Width
`Border.GetTopSize()` etc. use `displaywidth.String()` and `displaywidth.StringGraphemes()` from `clipperhouse/displaywidth` to correctly measure the visual width of Unicode characters (emoji, CJK, combiners) in border rendering.

---

## Strengths

- **Exemplary API design:** The fluent, pure-value `Style` type makes composition trivial and eliminates entire classes of TUI bugs (no mutation surprises). The `s2 := s1` true-copy semantics are elegant.
- **Comprehensive ANSI coverage:** Not just colors — blink, faint, underline styles, underline colors, hyperlinks, reverse. Covers the full terminals can do.
- **Automatic color degradation:** The `colorprofile` integration means developers don't need to think about terminal capabilities — it just works, even stripping styles when output is piped to a file or pager.
- **CSS-like familiarity:** Developers coming from web backgrounds immediately understand `Padding(1, 2, 3, 4)`, `Margin(2)`, `Align(Center)` without learning new mental models.
- **Rich preset borders:** 10 preset border styles (Normal, Rounded, Block, Thick, Double, HalfBlock × 2, Hidden, Markdown, ASCII) cover most use cases while `Border{...}` allows arbitrary customization.
- **Layer compositing:** The `Layer`/`Compositor`/`Canvas` system is a proper (if simple) 2D graphics compositor with z-ordering and mouse hit-testing — enabling overlay UIs and non-rectangular layouts.
- **Sub-packages for complex structures:** Tables, lists, and trees are non-trivial to render correctly; having first-party sub-packages with flexible styling callbacks is valuable.
- **Well-tested:** Comprehensive golden/snapshot tests in `*_test.go` files ensure rendering correctness across the ANSI feature set.
- **Mature and stable:** v2 has an upgrade guide from v1, `compat` package for migration, well-established API.

---

## Weaknesses

- **Go-specific:** Cannot be directly used from other languages. SugarCraft must manually port every feature; no shared core.
- **Complex internal property system:** The `propKey` bitmask + dual `attrs` representation makes the internal implementation harder to follow than it needs to be. The `set()` method's massive switch statement (covering every property key) is ~100 lines.
- **Global state in `compat` package:** `HasDarkBackground` and `Profile` are initialized at package import time by reading stdin/stdout — this is explicitly discouraged in the docs but the `compat` package exists for migration purposes.
- **No built-in syntax highlighting:** Lip Gloss handles ANSI SGR sequences but does not include a syntax highlighter. For that you need Glamour (also a Charmbracelet project, already mapped to `candy-shine`).
- **No built-in markdown rendering:** Lip Gloss styles individual strings; it doesn't parse markdown structure. Glamour (`candy-shine`) handles that.
- **Color blending is 1D around border perimeter only:** `Blend2D` is diagonal-linear, not a full 2D rectangular gradient. True rectangular gradients require custom iteration over `Blend1D` results.
- **Limited widget set:** Lip Gloss provides styling primitives and the table/list/tree sub-packages, but no text input, scrollable viewports, progress bars, spinners, or other interactive components. Those are in `bubbles` (mapped to `sugar-bits`).
- **Go 1.25 dependency:** `go.mod` declares `go 1.25.0` which is very recent (future version), limiting adoption in older codebases.
- **Hard dependency on Charmbracelet infrastructure:** `charmbracelet/x/ansi`, `charmbracelet/colorprofile`, `charmbracelet/ultraviolet` are all internal Charm libraries — porting requires understanding this full stack.

---

## SugarCraft Mapping

| SugarCraft Lib | Namespace | Lipgloss Sub-package/Feature |
|---|---|---|
| **CandySprinkles** (`candy-sprinkles/`) | `SugarCraft\Sprinkles` | **Primary mapping:** The core `Style` type and all its setters (`Bold`, `Italic`, `Foreground`, `Background`, `Padding`, `Margin`, `Border*`, `Align`, etc.), color system (`Color`, `Darken`, `Lighten`, `Complementary`, `Alpha`, `Blend1D`, `Blend2D`, `AdaptiveColor`), border presets, position types, measurement utilities (`Width`, `Height`, `Size`), text wrapping/joining/placement, layer compositing (`Layer`, `Compositor`, `Canvas`), `StyleRunes` |
| **SugarBits** (`sugar-bits/`) | `SugarCraft\Bits` | `table` sub-package → `Table.php` with `Column.php`, `SortState.php`, `Styles.php`; `list` sub-package → potential future `ItemList.php` enhancements |
| **CandyPalette** (`candy-palette/`) | `SugarCraft\Palette` | `charmbracelet/colorprofile` → terminal color detection (`HasDarkBackground`, `BackgroundColor`, `LightDark`, `Complete`) — but note SugarCraft's `candy-palette` maps directly to `colorprofile` itself |
| **CandyShine** (`candy-shine/`) | `SugarCraft\Shine` | Adjacent: Lip Gloss handles ANSI styling; Glamour handles Markdown → these compose together in Charm apps |
| **SugarCharts** (`sugar-charts/`) | `SugarCraft\Charts` | `Blend2D` / color gradient functions could power heatmap cell coloring |
| **SugarPrompt** (`sugar-prompt/`) | `SugarCraft\Prompt` | Adjacent: `huh` forms use Lip Gloss for styling internally; styles compose in the Bubble Tea render loop |

**Key architectural insight:** Lip Gloss is the *foundation* that all other Charm libraries build upon. In SugarCraft, **CandySprinkles** (`candy-sprinkles/`) is the equivalent foundation layer that everything else (`sugar-bits`, `sugar-prompt`, etc.) imports for styling. SugarCraft's `candy-core` (Elm-architecture runtime) pairs with `candy-sprinkles` (styling) in the same way Bubble Tea pairs with Lip Gloss.

---

## Analysis

Lip Gloss is a masterclass in API design for terminal applications. Its core insight — that terminal styling should be as intuitive as CSS, but work within the constraints of ANSI escape sequences — leads to an API where `style.Render("text")` applies bold + purple background + rounded border + 2-cell padding in a single chain. The pure-value `Style` type means developers can safely share style definitions without defensive copying, and the fluent builder pattern makes every style a one-liner.

The library's most sophisticated features are its color system and border rendering. The color system uses CIELAB color space for perceptually uniform blending, automatically detects terminal capabilities (ANSI → ANSI256 → TrueColor), and can strip all styling for non-TTY output — all without the developer thinking about it. The border rendering correctly handles per-side coloring, gradient borders (a visually striking effect), and variable-width Unicode characters using grapheme-aware measurement. The `Border{...}` struct with 13 fields seems complex until you realize it exactly matches what terminal emulators can actually render.

The layer compositing system (`Layer`, `Compositor`, `Canvas`) is the most powerful and least-known feature. It implements a simple 2D graphics compositor: layers have X/Y/Z coordinates, the `Compositor` flattens the hierarchy once into a sorted slice, and `Draw()` renders each layer in order. Combined with `Canvas` (a cell-buffer), this enables overlay UIs, modal dialogs, and non-rectangular layouts — patterns that would otherwise require manual coordinate math. The `Hit()` method provides mouse coordinate → layer lookup, enabling clickable terminal UI regions.

The main limitation is that Lip Gloss is fundamentally a styling library, not a component library. It styles text; it doesn't manage focus, input, or state transitions. The table, list, and tree sub-packages are renderers for structured data, not interactive widgets. For interactive components like text inputs, spinners, or paginated lists, you need `bubbles` (mapped to `sugar-bits`). In the SugarCraft ecosystem, this division of labor is explicit: `CandySprinkles` styles, `SugarBits` provides prebuilt interactive components, and `CandyCore` (Bubble Tea) manages the event loop and state.
