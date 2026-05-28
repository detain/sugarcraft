# SugarCraft/sugar-charts

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-charts
- **Language:** PHP 8.3+
- **Status:** 🟡 In Progress
- **Upstream:** NimbleMarkets/ntcharts (primary), with Charmbracelet/bubbletea (canvas rendering patterns), ratatui/ratatui (widget trait patterns), rasjonell/dashbrew (dashboard integration)
- **Dependencies:** `sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`, `sugarcraft/sugar-dash`
- **Description:** PHP terminal charting library providing ASCII/Unicode chart rendering with ANSI SGR color support for SugarCraft TUI ecosystem

## Architecture Overview

sugar-charts is structured around a **layered rendering pipeline** with three distinct levels:

```
User-facing Chart Classes (BarChart, LineChart, Heatmap, OHLC, Sparkline, etc.)
         ↓
   Canvas Primitives (Canvas, Graph, BrailleGrid, Cell)
         ↓
  candy-sprinkles Style / candy-core Color / ColorProfile
```

### Canvas Layer (`SugarCraft\Charts\Canvas`)

The foundation is a **mutable in-place 2D grid** (`Canvas`) of styled `Cell` objects. This is a deliberate design choice — the mutable canvas is "cheaper for hot rendering paths than building a new grid per draw call" (per source comments).

**Key components:**

| Component | File | Responsibility |
|-----------|------|---------------|
| `Cell` | `Canvas/Cell.php` | Single rune + optional `Style` — immutable value object |
| `Canvas` | `Canvas/Canvas.php` | Fixed-size 2D grid, 0-based coords (x=col, y=row), mutates in-place |
| `Graph` | `Canvas/Graph.php` | Drawing primitives: line, axis, labels, candlestick, columns |
| `BrailleGrid` | `Canvas/BrailleGrid.php` | Sub-cell scratch buffer: 2x4 dots per cell for high-res rendering |

**Canvas mutability model** — unlike the immutable fluent `with*()` pattern used throughout SugarCraft, `Canvas` and `BrailleGrid` are mutable. This is intentional for performance in animation loops.

**Graph constants** (`Graph::LINE_THIN`, `LINE_THICK`, `LINE_DOUBLE`) provide pre-defined Unicode box-drawing glyphs matching ASCII art conventions. The `niceNumbers()` algorithm generates readable axis tick intervals.

**Braille encoding** — `BrailleGrid` implements the 2x4 dot matrix per cell using U+2800 Unicode block characters. Each dot has a specific bit position:
```
col 0: row0=0x01, row1=0x02, row2=0x04, row3=0x40
col 1: row0=0x08, row1=0x10, row2=0x20, row3=0x80
```
The `paint()` method transfers the accumulated dot pattern onto a `Canvas`.

### Chart Type Inventory

| Chart Type | Primary File | Key Features |
|------------|--------------|-------------|
| **Sparkline** | `Sparkline/Sparkline.php` | 8-level block glyphs `▁▂▃▄▅▆▇█`, sliding window, auto-max |
| **BarChart** | `BarChart/BarChart.php` | Vertical/horizontal, stacked-compatible via Bar, axis, legend, title, labels |
| **LineChart** | `LineChart/LineChart.php` | Multi-series, axes, connectors (`│ / \`), area fill, animation, BrailleCanvas |
| **TimeSeries** | `LineChart/TimeSeries.php` | DateTimeImmutable input, X-axis time formatting, range filtering |
| **Streamline** | `LineChart/Streamline.php` | Fixed-width sliding window, infinite push() without memory growth |
| **Waveline** | `LineChart/Waveline.php` | Explicit [x,y] point pairs, arbitrary X range, Bresenham connector |
| **Scatter** | `Scatter/Scatter.php` | Unconnected point plotting, auto X/Y range, configurable rune |
| **Heatmap** | `Heatmap/Heatmap.php` | RGB color interpolation, multi-stop palettes, legend strip, ColorProfile |
| **OHLC** | `OHLC/OHLCChart.php` | Candlestick with wick/body glyphs, bullish/bearish colors |
| **Picture** | `Picture/Picture.php` | Protocol auto-detection, Sixel/Kitty/iTerm2 encoding |
| **MarkLine** | `MarkLine.php` | Horizontal reference lines (min/max/avg) |

### Value Objects

| Object | Location | Purpose |
|--------|----------|---------|
| `Bar` | `BarChart/Bar.php` | Single bar with label + value |
| `HeatPoint` | `Heatmap/HeatPoint.php` | x, y, value tuple for streaming heatmap data |
| `Bar` (OHLC) | `OHLC/Bar.php` | open, high, low, close financial data point |
| `Protocol` | `Picture/Protocol.php` | Enum: Sixel, Kitty, ITerm2 |

### Aggregation Layer (`SugarCraft\Charts\Aggregation`)

Three immutable utility classes for time-series data preprocessing:

- **`BucketByTime`** — Groups timestamped values into time buckets
- **`MovingAverage`** — Simple/centered SMA and EMA computation
- **`Resample`** — Upsampling (linear interpolation, nearest) and downsampling (last, mean)

All three follow the **clone-mutate builder pattern** with fluent `add()`/`addMany()` and terminal `compute()` methods.

## Feature Analysis by Chart Type

### Sparkline (`src/Sparkline/Sparkline.php`)

**Implementation:** 8 discrete vertical levels using Unicode block characters `▁▂▃▄▅▆▇█` mapped to normalized values [0,1]. Each data point occupies exactly one cell column.

**Key design decisions:**
- Auto-max mode (`autoMaxValue` flag) — when enabled, rescales to window maximum rather than configured max, so sparklines of live data grow naturally
- Sliding window via `array_slice($data, -$width)` in `view()` — only last `width` points shown
- `push()` returns new instance — immutable append with unbounded input

**Rendering formula:** `idx = round(norm * 8)` where `norm = clamp((v - min) / range, 0, 1)`. When all points equal, renders mid-bar (▄) for visibility.

**Gap from upstream:** Mirrors NimbleMarkets/ntcharts' `sparkline.New()` API closely.

### BarChart (`src/BarChart/BarChart.php`)

**Implementation:** String building with `█` for filled bars. Supports both vertical (bars grow up from baseline) and horizontal (bars grow right from label).

**Key design decisions:**
- Auto bar-width/gap calculation — distributes available width across bars, minimum 1 cell per bar
- Horizontal mode renders one bar per row with label in left gutter
- Value coercion from multiple input shapes: `['label', value]` tuples, `label => value` assoc arrays, or pre-constructed `Bar` objects
- Optional axis line (`┤` vertical, `├` horizontal) at chart edge

**Legend integration:** Uses shared `Legend::new()` with `Position` enum (Top/Bottom/Left/Right). Merge logic pads shorter side.

**Rendering formula:** `height = round(normalized * bodyHeight)` where `normalized = clamp((v - min) / (max - min), 0, 1)` and `bodyHeight = showLabels ? height - 1 : height`.

### LineChart (`src/LineChart/LineChart.php`)

**Implementation:** Plots [column, row] coordinate pairs onto Canvas using configurable point rune (`*` default) with slope-aware connector glyphs (`─ │ ╱ ╲`).

**Key design decisions:**
- Multi-series via `withDataset(name, values)` — auto-assigns cycling colors from `DATASET_COLORS` palette
- Axes and labels are rendered as part of the plot using `Graph::drawXYAxis()` and `drawXYAxisLabel()` with gutter reservation
- Animation support via `animationProgress` (0.0–1.0) — only first `N` points rendered in `renderChart()`
- Area fill mode (`withFill(true)`) fills vertical space from baseline to point row
- BrailleCanvas integration via `withCanvas(BrailleCanvas)` for sub-cell dot-matrix rendering (bridge to sugar-dash)
- Theme integration via `withTheme(Theme)` — passes through to base `Chart` class

**Connector algorithm (`drawConnector`):** For adjacent columns, samples one row per intermediate column using linear interpolation. Vertical connectors use `|` with step direction.

**Multi-dataset behavior:** All series share the same Y-axis range (computed from global min/max of all series) so they're visually comparable.

**Gap from upstream:** `withDataset()` / multi-series is SugarCraft extension beyond ntcharts API.

### TimeSeries (`src/LineChart/TimeSeries.php`)

**Implementation:** Thin wrapper over `LineChart` that accepts `[DateTimeImmutable, value]` tuples and converts timestamps to formatted X-axis labels using PHP `date()` format strings.

**Key design decisions:**
- Explicit range filtering via `withTimeRange(start, end)` — filters points before rendering, rescales X labels to explicit range endpoints rather than data extent
- `withXLabelCount(n)` controls tick count
- Timezone preservation — labels use the timezone of the range start, not UTC

**Limitation:** Does not modify the LineChart's X-axis range — X values are still column-indexed, only labels are timestamp-formatted.

### Streamline (`src/LineChart/Streamline.php`)

**Implementation:** Fixed-capacity sliding window. `push()` appends and trims to `width` with `array_slice($window, -$width)`. Delegates entirely to `LineChart::new()` for rendering.

**Memory guarantee:** Window capped at `width` entries regardless of push count.

### Waveline (`src/LineChart/Waveline.php`)

**Implementation:** Explicit [x, y] point pairs rather than column-indexed values. Projects arbitrary X range onto canvas width.

**Key design decisions:**
- Independent X and Y ranges — `withXYRange(xMin, xMax, yMin, yMax)`
- Projection lambda normalizes both axes independently: `tx = (x - xMin) / (xMax - xMin)`, `ty = (y - yMin) / (yMax - yMin)`
- Bresenham connector algorithm for sub-pixel lines

### Heatmap (`src/Heatmap/Heatmap.php`)

**Implementation:** 2D grid where each cell's foreground color is linearly interpolated between `coldColor` and `hotColor` (or across multi-stop `palette`). Uses `SugarCraft\Core\Util\Color` RGB type.

**Key design decisions:**
- Auto-grows grid via `pushPoint(HeatPoint)` — rows and columns padded with zeros until first write
- Color sampling: when `palette` is set, linearly interpolates between palette stops; otherwise blends cold↔hot
- `ColorProfile` — controls whether colors render as ANSI 256 or TrueColor based on terminal capability
- Legend strip rendered as a single row below the grid, width-aligned

**Color interpolation:** Uses `Color::blend(Color $other, float $t)` where `t` is normalized position. Multi-stop palette: `t * (count-1)` selects segment and `localT` interpolates within segment.

**Palette stops example:** `[#000050, #5fafff, #ffd75f, #ff5f5f]` gives 4 stops for cold→hot gradient.

### OHLC Chart (`src/OHLC/OHLCChart.php`)

**Implementation:** One column per bar. Vertical wick drawn first (high→low), then body cells overlaid on top (open↔close range).

**Key design decisions:**
- Bullish detection: `isBullish()` = `close >= open`
- Wick and body use separate glyphs — body overlays wick so open/close range is visually distinct
- Color assignment: `bullishColor` (default green) for up bars, `bearishColor` (default red) for down
- When count exceeds width, renders last `width` bars (rightmost visible)

**OHLC Bar normalization:** `bodyTop = max(open, close)`, `bodyBottom = min(open, close)` — these are row values, not Y coordinates. Row formula: `row = round((1 - normalized) * (height - 1))`.

### Picture (`src/Picture/Picture.php`)

**Implementation:** Terminal image protocol encoder. Supports three protocols with auto-detection via `$TERM`/`$TERM_PROGRAM` environment variables.

**Protocols:**
- **Sixel** — Pure PHP encoder (no GD/Imagick required). `Sixel::encode()` does palette quantization + 6-row stripe encoding
- **Kitty** — APC `G` sequence with base64-encoded PNG chunks
- **iTerm2** — `ESC ]1337;File=inline=1` sequence with base64 PNG

**Sixel encoder details (`Picture/Sixel.php`):**
1. Builds palette from uniform RGB cube (first 16 = ANSI colors, remainder = axis-sampled cube)
2. Quantizes every pixel via squared-RGB nearest-neighbor
3. Emits palette as `#idx;2;r;g;b` entries (HLS=2 for RGB, 0-100 scale)
4. Walks grid in 6-row stripes — per stripe, emits one color pass per active color with 6-bit mask bytes

**Auto-detection order:** iTerm2 → WezTerm → Kitty → foot/mlterm → Sixel fallback.

**Input modes:** `fromGrid(Color[][])` for programmatic pixel data, `fromPng(string $bytes)` for GD/Imagick output.

### MarkLine (`src/MarkLine.php`)

**Implementation:** Pure value object representing a horizontal reference line. Static factory methods: `at()`, `fromDataset()`, `min()`, `max()`, `average()`.

**Not yet integrated** into any chart class — exists as a standalone annotation concept.

## Rendering Pipeline

### Chart base class (`Chart/Chart.php`)

Abstract base providing common features: legend, title, axis labels, data labels, animation state, BrailleCanvas, Theme.

```
view() → renderChart() → mergeLegend() → addTitle() → addYLabel() → addXLabel()
```

Subclasses implement `protected function renderChart(): string` returning raw chart output.

### Style flow

```
SugarCraft\Sprinkles\Style
    ↓ .foreground(Color)
    ↓ .colorProfile(ColorProfile::TrueColor)
    ↓ .inherit($cellStyle)
Canvas::setCell(x, y, $rune, $style)
    ↓
Canvas::view() → $cell->style->render($rune)  (produces ANSI SGR sequence)
```

### Color profile support

All charts using color (`Heatmap`, `OHLC`) accept `ColorProfile` to control output format:
- `ColorProfile::TrueColor` — `\e[38;2;r;g;bm` format
- `ColorProfile::ANSI256` — `\e[38;5;nm` format
- Not all terminals support TrueColor — fallback is automatic

## Color Scale Design

**Sparkline:** 8-level glyph mapping (no color, just shape variation).

**BarChart:** Single foreground color per bar (no gradient — uses solid `█`). Color assigned via Legend which applies ANSI 16-color foreground.

**LineChart:** Per-series cycling through `['red', 'green', 'yellow', 'blue', 'magenta', 'cyan']`. Each series gets next color in cycle.

**Heatmap:** Multi-stop linear interpolation. Palette stops evenly divide the [min, max] range. Default cold=#000050 (deep blue), hot=#ff4040 (red). Multi-stop example: 4 stops as shown in heatmap example.

**OHLC:** Bullish = ANSI green (#10), Bearish = ANSI red (#9). Solid glyphs for body, single wick glyph.

**Legend:** Uses `Ansi::fg16(31-37)` for 7 named colors. Default foreground (code 39) via `Ansi::sgr(39)` to preserve other attributes.

## Data Formatting

### Axis labels

- **X labels:** `withXLabels([...])` for explicit list; `withXLabelFormatter(fn($v): string, $ticks)` for generated labels based on X range
- **Y labels:** `withYLabels([...])` for explicit list; `withYLabelFormatter(fn($v): string, $ticks)` for generated
- Auto-generated Y labels format floats — `Graph::niceNumbers()` generates "nice" round numbers for tick spacing

### Data coercion

All chart constructors accept loose input shapes:
- Raw arrays: `[val1, val2, val3]` or `[[x1, y1], [x2, y2], ...]`
- Associative: `['label1' => 0.5, 'label2' => 0.8]`
- Pre-constructed value objects: `Bar`, `HeatPoint`, `OHLC\Bar`

### Streaming data

- `Sparkline::push()` — appends without trimming if window not full
- `Streamline::push()` — always trims to width (fixed-window)
- `TimeSeries::push(DateTimeImmutable, value)` — appends time-series tuple
- `Waveline::push(x, y)` — appends XY point
- `Heatmap::pushPoint(HeatPoint)` — auto-grows grid
- `LineChart::push(float)` — slides window and appends

## Performance Considerations

**Canvas mutability:** The Canvas is mutable in-place rather than functional (no copy-on-write). This is intentional — avoids allocation per frame in animation loops.

**BrailleGrid immutability:** Each `set()`/`unset()`/`toggle()` returns a new `BrailleGrid` instance — functional update pattern — but `paint()` transfers to a mutable `Canvas`.

**String concatenation:** All charts build output as PHP strings via concatenation. For 80x24 terminals, this is negligible. Hot path profiling not yet done.

**Sixel encoding:** Pure PHP with no native extensions. Palette quantization is O(n*m) where n=pixels, m=palette. 320x200 image with 16 colors encodes to 30-60KB in ~100ms (estimated).

**Animation:** `animationProgress` controls how many points are rendered — reduces work proportionally when animation is mid-progress. Animation state is stored as float 0.0–1.0 on the Chart object.

**Memory for streaming:** `Streamline` caps window at `width` entries — memory is O(width), not O(total pushes). Same for `Sparkline::push()` with sliding window.

## What Remains Incomplete

Based on source analysis and CALIBER_LEARNINGS.md, the following gaps exist:

### API Completeness
1. **MarkLine not integrated** — `MarkLine` class exists but is not used by any chart. Could be added to LineChart/BarChart for threshold annotations.
2. **No explicit API for horizontal stacked bar charts** — `BarChart::withHorizontal()` renders horizontal bars but stacked behavior requires custom Bar extensions
3. **No grid overlay option** — Charts don't support optional grid lines for reference
4. **No logarithmic scale** — All axes are linear only
5. **No color per data point** — LineChart applies single color per series, not per-point gradients

### Rendering Gaps
6. **No gauge/arc chart** — Present in ratatui/dashbrew but not in sugar-charts
7. **No bubble/scatter with sized points** — `Scatter` uses fixed-size rune; bubble would need size encoding
8. **No radar/spider chart** — Not present in sugar-charts
9. **No 3D projection** — Obviously out of scope for ASCII, but worth noting
10. **No animation duration** — `animationProgress` exists but the duration-based animation (driven by ReactPHP timers) is not wired

### Platform Integration
11. **BrailleCanvas path is experimental** — `LineChart::withCanvas(BrailleCanvas)` integrates with sugar-dash but is marked as bridging pattern in CALIBER_LEARNINGS
12. **No batch/animated rendering** — Individual frame rendering only; no `Animation` helper class
13. **Kitty/iTerm2 need PNG bytes** — `Picture::fromGrid()` works for Sixel but Kitty/iTerm2 require GD/Imagick for PNG encoding (external dependency)

### Testing
14. **Snapshot tests needed** — Many chart classes lack snapshot tests asserting exact SGR byte output
15. **OHLC testing incomplete** — `OHLCChart` has minimal test coverage

### Documentation
16. **Examples missing** — Only 8 of 11 chart types have examples (missing: sparkline streaming, scatter, ohlc)
17. **VHS demos** — Only `.tape` files for bar, heatmap, line, ohlc, picture, scatter, sparkline, timeseries

## Upstream Comparison

### NimbleMarkets/ntcharts (Primary Upstream)

| ntcharts Feature | sugar-charts Status |
|------------------|---------------------|
| Sparkline | ✅ Ported — `Sparkline::new()`, `push()`, sliding window |
| BarChart | ✅ Ported — vertical/horizontal, stacked, axes, legend |
| LineChart | ✅ Ported — multi-series, animation, BrailleCanvas, fill |
| TimeSeries | ✅ Ported — DateTimeImmutable input, time range, labels |
| Streamline | ✅ Ported — fixed-window push model |
| Waveline | ✅ Ported — explicit XY range, Bresenham connector |
| Heatmap | ✅ Ported — multi-stop palette, legend strip, auto-growth |
| OHLC | ✅ Ported — wick/body glyphs, bullish/bearish |
| Picture | ✅ Ported — Sixel pure PHP, Kitty/iTerm2 PNG-based |
| MarkLine | ⚠️ Exists but not integrated |
| BrailleCanvas | ⚠️ Bridge to sugar-dash works but experimental |

**Key difference:** ntcharts is a Go library with first-class terminal backend support (bubbletea). SugarCraft's implementation is self-contained and bridges to sugar-dash's BrailleCanvas separately.

### ratatui/ratatui (Secondary Reference)

| ratatui Widget | sugar-charts Equivalent |
|---------------|------------------------|
| Sparkline | ✅ `Sparkline` — 8-level block glyphs |
| BarChart | ✅ `BarChart` — vertical/horizontal |
| Chart (X/Y axes, legend) | ✅ `LineChart` with `withAxes()` |
| Canvas (Bresenham drawing) | ✅ `Graph` + `Canvas` |
| Gauge | ❌ Not ported |
| Table | ❌ Separate in sugar-bits |
| Paragraph | ❌ In sugar-bits text rendering |

**Key difference:** Ratatui uses immediate-mode rendering with buffer diffing (only changed cells written). SugarCraft's Canvas produces complete frame strings each render.

### rasjonell/dashbrew (Chart Integration Reference)

Dashbrew uses `guptarohit/asciigraph` for ASCII charts. sugar-charts implements equivalent (and beyond) functionality natively.

| Dashbrew Component | sugar-charts Status |
|-------------------|---------------------|
| chart (asciigraph) | ✅ Superior — multi-series, axes, animation |
| histogram | ✅ `BarChart` with label display |
| Color gradient bars | ✅ `Heatmap` + multi-stop palette |

## Innovation Points

1. **Immutable chart objects with fluent API** — Every `with*()` returns a new instance; no internal mutation during rendering

2. **Universal data coercion** — Single constructor accepts arrays, tuples, assoc arrays, or value objects transparently

3. **Sub-cell rendering via BrailleGrid** — 2x4 dot matrix gives higher resolution than character cells for line/scatter plots

4. **Multi-stop palette interpolation** — Color scales are not limited to 2 colors; arbitrary N-stop palettes supported

5. **Protocol auto-detection** — `Picture::detect()` reads `$TERM`/`$TERM_PROGRAM` to select appropriate image encoding

6. **Pure-PHP Sixel encoder** — No external dependencies (GD/Imagick) for Sixel output; works in minimal PHP environments

7. **Animation via progress parameter** — `animationProgress` enables frame-by-frame animation without JS/animation framework

8. **Aggregation utilities** — MovingAverage, Resample, BucketByTime live alongside charts (not in separate utility library)

9. **Theme integration** — `withTheme(Theme)` wires into candy-sprinkles Theme system for consistent palette

10. **Lang/i18n support** — 17 locale files for error messages and chart labels

## Technical Debt and Risks

1. **Many `copy()` variants** — `Chart::copy()`, `BarChart::copy()` + `barWidthCopy()`, `LineChart::lineChartCopy()` — flag parameters (`$minSet`, `$maxSet`) to distinguish "not passed" from "passed as null" indicate the immutability-with-optional-params pattern is strained. Consider PHP 8.4 constructor property hooks.

2. **Canvas is mutable** — Contradicts the immutable fluent pattern used everywhere else. CALIBER_LEARNINGS acknowledges this as intentional for performance, but creates an inconsistent API model.

3. **Sixel palette quantization** — Simple RGB cube sampling is fast but produces visible banding. No dithering or error diffusion. For photographic images, this is a significant quality gap.

4. **Color blending in LAB space** — Dashbrew uses LAB interpolation for perceptually uniform gradients. SugarCraft's `Color::blend()` uses RGB linear interpolation which can produce non-uniform perceived brightness across the gradient.

5. **No test for SGR output correctness** — Canvas/Style integration works but there are no snapshot tests asserting exact escape sequence format across terminals with different capabilities.

6. **`Graph::niceNumbers()` edge cases** — The algorithm can produce surprising tick counts when range is very small or values are very large (scientific notation not handled).

## Dependencies Analysis

```
sugar-charts
├── candy-core (foundation: Style, Ansi, Color, etc.)
├── candy-sprinkles (Theme, input handling)
└── sugar-dash (BrailleCanvas, Plot/Braille for sub-cell rendering)
```

**Circular dependency risk:** sugar-dash has a Plot/Chart module with overlapping types (AreaChart, CandlestickChart, HeatMapChart, Sparkline, etc.). These are separate implementations — sugar-dash's Plot is for dashboard rendering while sugar-charts is for standalone chart objects.

## Test Coverage Analysis

**Well-tested:** Canvas (73 lines, 8 tests), LineChart (348 lines, 20+ tests), Sixel (89 lines, 6 tests), Sparkline, BarChart, Heatmap, OHLC, Legend, Scatter, Aggregation (MovingAverage, Resample, BucketByTime).

**Snapshot tests:** Absent for most chart types. Only Sixel has meaningful byte-level assertions (validates sixel encoding bytes). LineChart has basic structural tests but no snapshot of exact SGR output.

**Behavioral tests:** Present — tests verify that custom points appear, axes render, dataset colors cycle, etc. But no golden-file snapshot tests for the visual output.

## Final Assessment

sugar-charts is a **well-structured, comprehensive terminal charting library** that successfully ports the NimbleMarkets/ntcharts API to PHP with meaningful extensions (BrailleCanvas, Theme integration, multi-dataset support). The architecture cleanly separates concerns: Canvas primitives for low-level drawing, Graph for chart-specific primitives, and individual chart classes for domain logic.

The main gaps are: (1) integration of MarkLine annotation into charts, (2) snapshot testing for SGR output correctness, (3) gauge/arc/radar chart types, (4) animation wiring with ReactPHP, and (5) the Canvas mutability inconsistency with the fluent immutable pattern used throughout.

The library is 🟡 in-progress with solid fundamentals — the core rendering pipeline is complete and tested. Remaining work is extension (more chart types), integration (MarkLine, animation), and hardening (snapshot tests, error handling).
