# SugarCraft\Dash

sugar-dash — a comprehensive TUI component library for PHP 8.3+. Provides 200+ components organized into 13 namespaces for building rich terminal user interfaces.

## Installation

```bash
composer require sugarcraft/sugar-dash
```

## Namespace Structure (12 Namespaces)

| Namespace | Description |
|-----------|-------------|
| `Foundation\` | Pure interfaces + low-level primitives (Item, Sizer, Style, Theme, Color, Rect, Drawable, Buffer, Cell) |
| `Layout\` | Layout primitives (Stack, VStack, HStack, ZStack, FlexLayout, GridLayout, Frame, Panel, Split, Spacer, Window, etc.) |
| `Components\` | UI components (Modal, Select, Toast, Tabs, StatusBar, Form, Feedback, Nav, Card, Calendar, Tree, Table, etc.) |
| `Plot\` | Charts and plotting (Chart, Sparkline, Gauge, Donut, Heatmap, RadarChart, TreeViz, Graph, etc.) |
| `Module\` | Module interface + base implementations |
| `Registry\` | Registry pattern for modules |
| `Plugin\` | Plugin system with JSON protocol |
| `Modules\` | Built-in modules (Clock, System, Weather, etc.) |
| `Keys\` | Key registry and mappings |
| `Position\` | ANSI-aware geometry helpers |
| `Output\` | Extracted helpers (truncate, render bar) |
| `State\` | State management |

---

## Foundation Namespace

### Interfaces & Contracts

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Item` | Anything that can be rendered as a string | `render(): string` |
| `Sizer` | An Item that knows its own dimensions (extends Item) | `setSize(int $width, int $height): Sizer`, `render(): string` |
| `Drawable` | Universal draw contract with GetRect/SetRect/Draw | `getRect(): Rect`, `setRect(Rect): void`, `draw(Buffer): void` |

### Configuration Classes

| Type | Description | Key Properties |
|------|-------------|----------------|
| `Options` | Grid-level configuration options | `$fitScreen: bool` (default: true) |
| `ItemOptions` | Per-item placement options within StackedGrid | `$column: int` (0-based), `$expandVertical: bool` |
| `ItemWithOptions` | Internal pairing of Item + ItemOptions | `$item: Item`, `$options: ItemOptions` |

### Low-Level Primitives

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Cell` | Single terminal cell (rune + Style) — sugar-dash SSOT, distinct from `\SugarCraft\Vt\Cell\Cell` | |
| `Buffer` | Cell grid buffer for drawing — sugar-dash SSOT, distinct from `\SugarCraft\Vt\Buffer\Buffer` | `getCell(x,y)`, `setCell(x,y,Cell)`, `fill(rect,Cell)` |
| `Rect` | Rectangle geometry (rectmath bounds model: minX/minY/maxX/maxY) — distinct from `\SugarCraft\Core\Rect` (offset+size model) | `contains()`, `intersect()`, `dx()`, `dy()` |
| `Style` | Terminal styling (inline foreground/background Color slots) — sugar-dash SSOT, distinct from `\SugarCraft\Sprinkles\Style` (padding/margin/borders) | `fg()`, `bg()`, `bold()`, etc. |
| `StyleParser` | Parses `[text](fg:red,bg:blue)` into Dash Cell arrays — sugar-dash SSOT, NOT drop-in compatible with `\SugarCraft\Sprinkles\StyleParser` | |
| `Color` | Backward-compat alias for `\SugarCraft\Core\Util\Color` (true duplicate, replaced by `class_alias` shim — prefer Core import in new code) | |
| `Theme` | Pre-defined theme palettes (10 colour slots + helpers) — sugar-dash SSOT, distinct from `\SugarCraft\Sprinkles\Theme` (13 slots, readonly only) | `dark()`, `dracula()`, `oneDark()`, `githubDark()`, `light()` |

> **Dual-SSOT note.** Five Foundation primitives (`Style`/`Theme`/`Rect`/`Buffer`/`Cell`) plus `StyleParser` are intentionally distinct from same-named canonical types in `candy-sprinkles`/`candy-core`/`candy-vt`. The two families have distinct design lineages and the API shapes diverge. Only `Color` was a true duplicate and is now a `class_alias` to `\SugarCraft\Core\Util\Color`. See `CALIBER_LEARNINGS.md` entries `[pattern:dual-foundation-ssot]`, `[pattern:dual-style-ssot]`, `[pattern:dual-theme-ssot]`, `[pattern:dual-rect-models]`, `[pattern:dual-buffer-roles]`, `[pattern:dual-cell-shapes]`.

---

## Layout Namespace

### Layout Enums

| Type | Values |
|------|--------|
| `LayoutDirection` | `Horizontal`, `Vertical` |
| `SplitDirection` | `Horizontal`, `Vertical` |
| `AlignItems` | `Start`, `End`, `FlexStart`, `FlexEnd`, `Center`, `Stretch`, `Baseline` |
| `FlexDirection` | `Row`, `Column`, `RowReverse`, `ColumnReverse` |
| `FlexWrap` | `NoWrap`, `Wrap`, `WrapReverse` |
| `HAlign` | `Left`, `Right`, `Center` |
| `VAlign` | `Top`, `Middle`, `Bottom` |
| `JustifyContent` | `Start`, `End`, `FlexStart`, `FlexEnd`, `Center`, `SpaceBetween`, `SpaceAround`, `SpaceEvenly` |

### Layout Containers

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `StackedGrid` | Multi-column stacked grid layout with items in columns | `addItem(Item, ItemOptions)`, `new(Options)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stacked-grid.gif) |
| `GridLayout` | CSS Grid-style layout with rows/columns/gaps | `columns(int, items)`, `rows(int, items)`, `withGap()`, `withItem()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/grid-layout.gif) |
| `FlexLayout` | Flexbox-style layout with direction/wrap/justify | `row()`, `column()`, `withJustify()`, `withAlignItems()`, `withGap()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/flex-layout.gif) |
| `VStack` | Vertical stack with alignment and spacing | `new(...items)`, `spaced(int, ...items)`, `centered(...items)`, `right(...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/vstack.gif) |
| `HStack` | Horizontal stack with spacing and alignment | `new(...items)`, `spaced(int, ...items)`, `centered(...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/hstack.gif) |
| `ZStack` | Layered stack (items on top of each other) | `new(...items)`, `left(...items)`, `right(...items)`, `top(...items)`, `bottom(...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/zstack.gif) |
| `Stack` | Basic vertical stack | `new(...items)`, `spaced(int, ...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stack.gif) |
| `Split` | Split view with two panes | `new(...items)`, `horizontal()`, `vertical()` | |

### Border & Frame Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Frame` | Bordered frame wrapping any Item with title/padding | `new(Item)`, `withBorder()`, `withBorderColor()`, `withPadding()`, `withTitle()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/frame.gif) |
| `Panel` | Panel with header/content/footer sections | `new(Item|string)`, `titled(Item|string, string)`, `withContent()`, `withHeader()`, `withFooter()`, `withStyle()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/panel.gif) |
| `BoxDrawing` | Unicode box-drawing frame generator | `new(?string)`, `titled(string)`, `double()`, `rounded()`, `bold()`, `withStyle()`, `withBorderColor()`, `withBgColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/box-drawing.gif) |
| `BorderText` | Text with ASCII-art border characters | `new(Item|string)`, `withBorders(Item|string)`, `withBorderColor()`, `withTextColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/border-text.gif) |
| `Divider` | Horizontal or vertical divider line | `new(?string)`, `h(?string)`, `v(?string)`, `withStyle()`, `withLabel()`, `withColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/divider.gif) |

### Spacing & Layout Helpers

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Spacer` | Empty space filler with optional fill character | `new(int $width, int $height)`, `dotted(int)`, `dashed(int)`, `vertical(int, int)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/spacer.gif) |
| `LayoutItem` | Item with flex properties for layouts | `flex(Item, int)`, `fixed(Item)` | |
| `Shadow` | Drop shadow effect wrapping any Item | `new(Item)`, `withStyle()`, `withColor()`, `withOffset()`, `withHeavy()`, `withNoShadow()` | |
| `Segment` | 7-segment digital display | `new(string)`, `withDigitWidth()`, `withOnColor()`, `withOffColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/segment.gif) |
| `Window` | Window frame with title bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/window.gif) |
| `Screen` | Terminal screen container | | |
| `Viewport` | Scrollable viewport | | |
| `Sidebar` | Sidebar navigation panel | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sidebar.gif) |
| `Breakpoint` | Static helpers for responsive breakpoints — `narrow()`/`medium()`/`wide()`/`pick()` (default thresholds 90/140, Homedash convention) | `narrow(int $width, int $threshold = 90): bool`, `medium(int $width, int $narrow = 90, int $wide = 140): bool`, `wide(int $width, int $threshold = 140): bool`, `pick(int $width, array $thresholds): string` | |
| `Pad` | Padding wrapper (formerly Boxer) | | |

---

## Plot Namespace (Charts & Visualization)

### Chart Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Plot` | Line/scatter chart with braille plotting | `new(dataPoints, type)`, `withDataPoints()`, `withType()`, `withColor()`, `withGrid()` | |
| `Chart` | Bar/line chart with axes, labels, grid | `new(dataPoints, type)`, `withDataPoints()`, `withType()`, `withColor()`, `withGrid()`, `withShowValues()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/chart.gif) |
| `AreaChart` | Area chart for time series data | `new(series)`, `withShowGrid()`, `withShowLegend()`, `withMaxValue()`, `withStacked()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/area-chart.gif) |
| `Area` | Stacked area chart with gradient fills | `new(dataPoints)`, `sample(int)`, `withDataPoints()`, `withStacked()`, `withShowLegend()` | |
| `AreaPoint` | Area chart data point: `label: string`, `value: float`, `y0: float|null` | | |
| `Bar` | Horizontal status bar with colors | `new(string)`, `withContent()`, `withForeground()`, `withBackground()`, `withAlign()`, `withBorders()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/bar.gif) |
| `CandlestickChart` | Financial candlestick chart | `new()`, `withCandle()`, `addCandle()`, `withShowGrid()`, `withShowVolume()` | |
| `Candlestick` | Single OHLC candlestick: `label`, `open`, `high`, `low`, `close` | `bullish()`, `bearish()`, `isBullish()` | |
| `Donut` | Donut chart with proportional segments | `new(data)`, `mocha(data)`, `withSize()`, `withCenterLabel()`, `withShowPercentage()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/donut.gif) |
| `Gauge` | Horizontal progress bar / gauge | `new(float $ratio)`, `withWidth()`, `withFilledColor()`, `withEmptyColor()`, `withPercentage()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/gauge.gif) |
| `GaugeChart` | Circular gauge chart | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/gauge-chart.gif) |
| `GaugeCircle` | Circular gauge visualization | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/gauge-circle.gif) |
| `HeatMapChart` | 2D heatmap with color gradient | `new(data)`, `sample()`, `withRowLabels()`, `withColumnLabels()`, `withLowColor()`, `withHighColor()` | |
| `Heatmap` | Heat map visualization with legend | `new(data)`, `sample()`, `withLegend()`, `withValues()`, `withLowColor()`, `withHighColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/heatmap.gif) |
| `HeatmapCalendar` | GitHub-style calendar heatmap | `new(data)`, `sample()`, `withLowColor()`, `withHighColor()`, `withEmptyChar()` | |
| `RadarChart` | Radar/spider chart for multi-axis data | `new(labels, series)`, `withSize()`, `withGridLines()`, `withShowLabels()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/radar-chart.gif) |
| `Sparkline` | Inline sparkline chart | `new(data)`, `withData()`, `withWidth()`, `withHeight()`, `withDataPoints()`, `withFill()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sparkline.gif) |
| `SparklineBar` | Bar-style sparkline | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sparkline-bar.gif) |
| `SparklineArea` | Area-style sparkline | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sparkline-area.gif) |
| `SparkArea` | Spark area chart | | |
| `FunnelChart` | Funnel chart visualization | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/funnel-chart.gif) |
| `Funnel` | Funnel visualization component | | |
| `Bullet` | Bullet chart | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/bullet.gif) |
| `Meter` | Meter/gauge component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/meter.gif) |
| `Rating` | Star rating display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/rating.gif) |
| `OHLC` | Open-High-Low-Close data | | |
| `OHLCPoint` | OHLC data point | | |
| `Waterfall` | Waterfall chart | | |
| `WaterfallItem` | Waterfall bar item | | |
| `MetricsGrid` | Grid of metric displays | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/metrics-grid.gif) |

### Graph & Network Visualization

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Sankey` | Sankey diagram for flow visualization | `new()`, `addNode()`, `addFlow()`, `withHorizontal()`, `withShowLabels()` | |
| `SankeyNode` | Node in Sankey diagram: `id`, `label`, `value`, `color` | | |
| `SankeyFlow` | Flow connection: `source`, `target`, `value`, `color` | | |
| `Sunburst` | Sunburst chart visualization | | |
| `Treemap` | Treemap chart visualization | | |
| `TreemapLeaf` | Leaf node in treemap | | |
| `TreeViz` | Tree visualization | | |
| `Network` | Network diagram | | |
| `NetworkNode` | Node in network | | |
| `NetworkShape` | `Circle`, `Square`, `Diamond`, `Hexagon`, `Star` | | |
| `MindMap` | Mind map visualization | | |
| `OrgChart` | Organization chart | | |
| `ClassDiagram` | UML class diagram | | |
| `Flowchart` | Flowchart diagram | | |
| `FlowchartNode` | Node in flowchart | | |
| `FlowchartNodeType` | `Process`, `Decision`, `StartEnd`, `InputOutput`, `Connector`, `Data` | | |
| `Dendrogram` | Dendrogram/tree diagram | | |
| `DendrogramNode` | Node in dendrogram | | |
| `Gantt` | Gantt chart | | |
| `PERT` | PERT chart | | |
| `Timeline` | Timeline display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/timeline.gif) |
| `TimelineViz` | Timeline visualization | | |
| `TimelineNode` | Node in timeline | | |
| `Sequence` | Sequence diagram | | |
| `Graph` | Graph visualization | | |
| `Bubble` | Bubble chart | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/bubble.gif) |
| `BubblePoint` | Bubble chart point | | |
| `Leaderboard` | Leaderboard display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/leaderboard.gif) |
| `WordCloud` | Word cloud visualization | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/word-cloud.gif) |
| `DotMatrix` | Dot matrix display | | |
| `Pictogram` | Pictogram display | | |
| `Partition` | Partition chart | | |
| `PartitionSegment` | Partition segment | | |
| `Diff` | Diff view | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/diff.gif) |
| `Ladder` | Ladder diagram | | |
| `Canvas` | Drawing canvas | | |
| `Scrollbar` | Custom scrollbar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/scrollbar.gif) |

### btop-derived graphs, meters & process rows

Ported from [aristocratos/btop](https://github.com/aristocratos/btop) for
[candy-top](https://github.com/detain/sugarcraft/tree/master/candy-top);
every class is a standalone immutable value object, and the integer
colour/quantization laws are byte-faithful to btop (several are pinned
against oracle fixtures generated from btop's own C++).

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `Plot\Gradient101` | btop `Theme::generateGradients`: expand ≥2 stops into a 101-entry ramp (index = percent) with btop's truncating integer law | `expand(list<Color>): list<Color>` |
| `Plot\Braille\BrailleCanvas` | Dot canvas — now with an optional value→colour ramp | `withGradient(stops, ?scale)`, `withValue()`, `withoutGradient()`, `gradient()`, `value()` |
| `Plot\Braille\DualSampleGraph` | btop `Draw::Graph`: two adjacent samples quantized into one glyph; braille / block / block2 / tty families | `new(width, height, family, invert, noZero, maxValue, offset)`, `push()`, `withData()`, `withGradient()`, `withUnderlay()`, `underlay()`, `render()` |
| `Plot\Chart\Meter` | Analog meter — now with btop's one-row position-coloured `■` bar | `withGradient(stops, positionWise)`, `withGlyph()`, `withInvert()`, `withMeterBg()`, `memoStats()`, `resetMemo()` |
| `Foundation\NetAutoScale` | btop net-graph ceiling hysteresis (grow/shrink only after 5 sustained samples) | `new(sync)`, `offer(down, up)`, `downloadMax()`, `uploadMax()`, `maxFor()`, `counters()`, `forceRescale()` |
| `Foundation\GradientStore` | Named 101-entry ramps — `at('cpu', 73)` is btop `Theme::g("cpu")[73]` | `new()`, `fromStops()`, `ramp()`, `withGradient()`, `at()`, `gradient()`, `has()`, `names()` |
| `Plot\DistanceFade` | btop proc-list distance fade (text dims, metrics blend to grey away from the selection) | `ramp()`, `distance()`, `fadeIndex()`, `fadeColor()`, `metricPosition()`, `metricColor()`, `flatMetricColor()`, `metricValues()` |
| `Plot\ProcRow\ProcRowComposer` | One btop process-list row: pid, name, cmd, threads, user, mem, 5×1 cpu graph on graph_bg, cpu% | `new(width)` (box inner width), `row(ProcRow, row, selected, selectMax, ?graph, followed)`, `withColumns()`, `withPalette()`, `withProcColors()`, `withProcGradient()`, `withFamily()` |
| `Plot\ProcRow\ProcRow` | Process DTO (pid, name, cmd, threads, user, cpu, memPercent, ?memLabel) | `new(...)` |
| `Plot\ProcRow\ProcColumns` | btop column widths | `btop(boxWidth, cpuGraphs)`, `new(prog, cmd, threads, user, cpuGraphs)`, `width()` |
| `Plot\ProcRow\ProcRowPalette` | Theme slots + the `proc` / `proc_color` / `process` ramps | `btop()`, `new(...)`, `withRamps()`, `withSelected()`, `withFollowed()` |
| `Plot\ProcRow\ProcGraphTracker` | Per-pid mini-graph lifecycle (create on cpu > 0, drop after 10 idle samples) | `new(width, family)`, `observe(pid, cpu)`, `retain(pids)`, `graph(pid)`, `sample(cpu)` |

Run `php examples/dual-sample-graph.php` for all three graph families,
an inverted graph, the underlay mini-graph and position-mode meters.

#### Gradient101 + BrailleCanvas gradients

`Gradient101::expand($stops)` is the shared ramp: two stops are one
0..100 leg, three stops split at 50 (btop's start/mid/end), more stops
split evenly. `BrailleCanvas::withGradient($stops, ?Closure $scale = null)`
attaches such a ramp; afterwards every `setPoint()` / `setLine()` with a
`null` colour paints the ramp colour at the canvas' current value
(`withValue()`), while an explicit colour still wins. `$scale` maps a raw
sample onto 0..1 (clamped) — the place for btop's
`(v + offset) * 100 / max_value` law. The ramp, scale and value survive
`setSize()`; `withoutGradient()` detaches it.

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;
use SugarCraft\Dash\Plot\Gradient101;

$stops = [Color::hex('#80d0a3'), Color::hex('#dcd179'), Color::hex('#d45454')]; // btop Default cpu
$ramp  = Gradient101::expand($stops);   // 101 Colors; $ramp[50] is #dcd179

$canvas = BrailleCanvas::new(16, 8)
    ->withGradient($stops, scale: static fn (int|float $v): float => $v / 100);
$x = 0;
foreach ([10, 35, 60, 90, 40, 20, 75, 100] as $v) {
    $h = (int) round($v / 100 * 7);
    $canvas = $canvas->withValue($v)->setLine($x, 7, $x, 7 - $h)->setLine($x + 1, 7, $x + 1, 7 - $h);
    $x += 2;
}
echo $canvas->render();
```

#### DualSampleGraph

btop's history graph. Each cell pairs the previous and current sample,
each quantized to a band 0..4 per graph row, and looks the pair up in
btop's 5×5 `graph_symbols` table — so braille packs two samples per cell,
and every `push()` scrolls the frame half a cell (a whole cell in `tty`).

- `new(int $width, int $height, string $family = FAMILY_BRAILLE, bool $invert = false, bool $noZero = false, int $maxValue = 0, int $offset = 0)` —
  families `FAMILY_BRAILLE` (`⣿`), `FAMILY_BLOCK` (quadrants; bands 1/2
  share a glyph), `FAMILY_BLOCK2` (2×3 sextants, btop PR #1783 — needs a font
  with Symbols for Legacy Computing; underlay `🬭`) and `FAMILY_TTY` (shades,
  one sample per cell). `DualSampleGraph::FAMILIES` lists all four.
- Values are percents 0..100 unless `$maxValue > 0`, which applies btop's
  `clamp((v + offset) * 100 / maxValue, 0, 100)` (a positive `$offset`
  alone implies `maxValue` 100). `$invert` draws top-down (btop's upload
  graph); `$noZero` keeps the bottom row at band ≥ 1 so idle still shows a
  floor line.
- `push(...$values)` appends, `withData(...$values)` replaces the
  history. History is retained up to the widest width seen (floor
  `HISTORY_CELLS` = 1024), so shrinking and growing back redraws old
  samples.
- `withGradient($stops)` colours through a `Gradient101` ramp: a one-row
  graph colours each cell by `max(prev, cur)`, taller graphs colour each
  row by its vertical position.
- `withUnderlay(Color $inactiveFg)` paints btop's `graph_bg` glyph
  (`⣀` / `▄` / `░`, see `underlayGlyph()`) wherever a one-row graph is
  transparent; static `underlay($family, $cells, $inactiveFg)` returns the
  bare strip for callers that compose it themselves.
- `render(?ColorProfile)` returns `height` lines; it implements `Sizer`,
  so `setSize()` drops it into any layout.

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;

$stops = [Color::hex('#80d0a3'), Color::hex('#dcd179'), Color::hex('#d45454')];

echo DualSampleGraph::new(12, 3)
    ->withGradient($stops)
    ->withData(5, 12, 30, 55, 80, 95, 70, 45, 20, 10, 35, 60)
    ->render(), "\n";

// Upload-style: block glyphs, grows downward, raw bytes/s scaled to a 2 KiB ceiling.
echo DualSampleGraph::new(12, 2, DualSampleGraph::FAMILY_BLOCK, invert: true, maxValue: 2048)
    ->push(128, 512, 1024, 2048, 1500, 900)
    ->render(), "\n";

// A btop proc-list mini-graph: 5×1, never fully blank, on the grey underlay.
echo DualSampleGraph::new(5, 1, noZero: true)
    ->withUnderlay(Color::hex('#404040'))
    ->push(0, 40, 90)
    ->render(), "\n";
```

#### Meter position mode

`Meter::withGradient(array $stops, bool $positionWise = false)` attaches a
`Gradient101` ramp. With `positionWise: false` the analog body is coloured
by the meter's value. With `positionWise: true` the meter renders btop's
one-row `Draw::Meter` bar instead: cell `i` (1-based) sits at
`y = round(i * 100 / width)`, is filled while `value >= y` and takes ramp
colour `y` — so a 50% bar on green→red is green-to-yellow, never all
yellow — and the unfilled tail is painted in `meter_bg`.

- `withWidth()` honours widths down to 1 cell in position mode (a layout
  `setSize()` width wins when set).
- `withGlyph(string = '■')`, `withInvert(bool = true)` (leftmost cell takes
  the ramp's high end, e.g. a discharging battery),
  `withMeterBg(?Color)` (null = btop's `#404040`).
- Rendered bars are memoized process-wide per shape (stops, width, invert,
  glyph, meter_bg) × value 0..100, like btop's per-meter
  `std::array<string,101>` cache, bounded to 64 shapes / 4 MiB.
  `Meter::memoStats()` / `Meter::resetMemo()` expose and clear it;
  `Meter::cacheKey(...)` is the shape key.

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\Chart\Meter;

$stops = [Color::hex('#80d0a3'), Color::hex('#dcd179'), Color::hex('#d45454')];

echo Meter::new(0.62)->withWidth(20)->withGradient($stops, positionWise: true)->render(), "\n";
echo Meter::new(0.30)->withWidth(20)->withGradient($stops, positionWise: true)
    ->withInvert()->withGlyph('▮')->withMeterBg(Color::hex('#202020'))->render(), "\n";
```

#### NetAutoScale + GradientStore

`NetAutoScale` is btop's network-graph ceiling, per direction: a speed
above the ceiling bumps a *fast* counter, one under a tenth of it (with
the ceiling above 10 KiB) bumps a *slow* counter, and only a counter
reaching 5 rescales — to `avg × 1.3` (grow) or `avg × 3` (shrink) of the
last 5 samples, floored at `NetAutoScale::FLOOR` (10 KiB/s). The first
`offer()` always rescales. `new(sync: true)` mirrors btop's `net_sync`
(one shared ceiling). `forceRescale(?$downHistory, ?$upHistory)` arms a
rescale on the next offer; the public `$rescaled` flag says whether the
last offer moved a ceiling.

`GradientStore` is the named-ramp registry a themed monitor reads per
cell. `fromStops()` takes btop-style flat keys (`<name>_start` /
`_mid` / `_end`; keys without `_start` are ignored), `withGradient()`
registers or replaces one (start-only fills all 101 entries), and
`at($name, $percent)` clamps the percent and throws
`\OutOfBoundsException` for an unknown name. Expanded ramps are memoized
process-wide (`MEMO_CAP` = 256).

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Foundation\{GradientStore, NetAutoScale};

$scale = NetAutoScale::new()->offer(50_000, 4_000);   // first offer rescales
// $scale->downloadMax() === 65000, $scale->uploadMax() === 10240 (floor)
for ($i = 0; $i < 5; $i++) {
    $scale = $scale->offer(200_000, 4_000);           // 5 sustained samples above → grow
}
// $scale->downloadMax() === 260000

$store = GradientStore::fromStops([
    'cpu_start' => Color::hex('#80d0a3'),
    'cpu_mid'   => Color::hex('#dcd179'),
    'cpu_end'   => Color::hex('#d45454'),
])->withGradient('proc', Color::hex('#cccccc'), end: Color::hex('#404040'));
echo $store->at('cpu', 73)->toHex();                  // #d99868
```

#### Process rows: ProcRowComposer, DistanceFade, ProcGraphTracker

`ProcRowComposer::new($width)` (the box's inner width) renders btop's normal-view process
row, exactly `width()` cells wide, with btop's own column sizing
(`ProcColumns::btop()`; pass `withColumns(ProcColumns::new(...))` for a
custom layout). The selected (`$row + 1 === $selected`) or `followed`
row is a bold highlight bar; every other row fades its text by distance
from the selection and colours cpu/name, mem and threads by value
(`withProcGradient()` / `withProcColors()` toggle btop's
`proc_gradient` / `proc_colors`). Control characters in name/cmd/user
become spaces. Colours come from `ProcRowPalette` (`btop()` is btop's
Default theme; `withRamps()` accepts ramps from a `GradientStore`).

`DistanceFade` exposes the underlying law as static helpers — including
btop's deliberate off-by-one (`$selected` is 1-based, `$row` 0-based).
`ProcGraphTracker` owns the per-pid 5×1 `DualSampleGraph`s: `observe()`
once per pid per fresh collection, `retain($livePids)` to sweep dead
pids; `sample()` lifts any cpu in [0.1, 5) to 5 so a barely-busy
process still shows.

```php
use SugarCraft\Dash\Plot\ProcRow\{ProcGraphTracker, ProcRow, ProcRowComposer};

$composer = ProcRowComposer::new(78);   // inner width of an 80-column proc box
$tracker  = ProcGraphTracker::new();
$procs = [
    ProcRow::new(1234, 'php', 'php bin/candy-top', threads: 3, user: 'joe', cpu: 42.5, memPercent: 3.2),
    ProcRow::new(888, 'mysqld', '/usr/sbin/mysqld', threads: 38, user: 'mysql', cpu: 7.1, memPercent: 11.0),
];
foreach ([10.0, 30.0, 42.5] as $cpu) {   // three collection ticks
    $tracker = $tracker->observe(1234, $cpu)->observe(888, 7.1);
}
foreach ($procs as $row => $p) {
    echo $composer->row($p, $row, selected: 1, selectMax: count($procs), graph: $tracker->graph($p->pid)), "\n";
}
```

---

## Components Namespace

### Components\Form (Form & Input)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Input` | Text input field | `new(?string)`, `labeled()`, `password()`, `withValue()`, `withPlaceholder()`, `withLabel()`, `withError()`, `withBorderColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/input.gif) |
| `Textarea` | Multi-line text area | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/textarea.gif) |
| `Checkbox` | Checkbox group with single/multi-select | `new(options)`, `withSelectedIndex()`, `withOptionChecked()`, `withMultiSelect()`, `withCheckedColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/checkbox.gif) |
| `Radio` | Radio button | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/radio.gif) |
| `Toggle` | Toggle switch | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/toggle.gif) |
| `SwitchComponent` | Switch component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/switch-component.gif) |
| `Slider` | Slider control | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/slider.gif) |
| `Select` | Dropdown select component | `new(options)`, `withSelectedIndex()`, `withOptions()`, `withSelectedColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/select.gif) |
| `ComboBox` | Combo box | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/combo-box.gif) |
| `Dropdown` | Dropdown menu | | |
| `DatePicker` | Date picker | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/date-picker.gif) |
| `ColorPicker` | Color picker | | |
| `Label` | Form label | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/label.gif) |
| `Chip` | Chip/tag component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/chip.gif) |
| `ChipGroup` | Group of chips | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/chip-group.gif) |
| `Editor` | Text editor | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/editor.gif) |
| `CommandPalette` | Command palette | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/command-palette.gif) |
| `Cursor` | Terminal cursor | | |

---

### Components\Feedback (Non-Modal Feedback)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Alert` | Alert/message box (info, warning, error, success) | `new(string)`, `info()`, `warning()`, `error()`, `success()`, `withMessage()`, `withTitle()`, `withBorderColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/alert.gif) |
| `Badge` | Badge/tag component | `new(string)`, `success()`, `warning()`, `error()`, `info()`, `withStyle()`, `withSize()`, `withIcon()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/badge.gif) |
| `BadgeGroup` | Group of badges | | |
| `LoadingText` | Animated loading text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/loading-text.gif) |
| `Skeleton` | Loading skeleton | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/skeleton.gif) |
| `Spinner` | Loading spinner | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/spinner.gif) |
| `Toast` | Toast message | `new()`, `info()`, `success()`, `warning()`, `error()`, `fromNotification()`, `fromQueue()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/toast.gif) |
| `NotificationQueue` | Dual-ring queue: active `items[max 20]` + `history[max 50]` | `new()`, `push()`, `dismiss()`, `current()`, `recent()`, `all()`, `history()`, `count()`, `historyCount()` | |
| `Level` | Toast level enum: `Info`, `Warning`, `Error`, `Success` | `icon()`, `isError()`, `isHighlighted()` | |
| `Notification` | Toast notification DTO: `message`, `level`, `title` | `info()`, `warning()`, `error()`, `success()` | |
| `Tooltip` | Tooltip popup | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tooltip.gif) |
| `Popover` | Popover content | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/popover.gif) |
| `NProgress` | npm-style progress bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/nprogress.gif) |
| `Marquee` | Scrolling marquee text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/marquee.gif) |
| `EmptyState` | Empty state placeholder | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/empty-state.gif) |

### Components\Modal (Modal Dialogs)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Modal` | Modal dialog | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/modal.gif) |
| `Notification` | Toast notification | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/notification.gif) |
| `Progress` | Progress indicator | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress.gif) |
| `ProgressBar` | Progress bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress-bar.gif) |
| `ProgressRing` | Circular progress indicator | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress-ring.gif) |
| `Drawer` | Drawer panel | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/drawer.gif) |
| `Wizard` | Multi-step wizard | | |
| `WizardStep` | Wizard step | | |

---

### Components\Nav (Navigation)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Tabs` | Tabbed interface | `new(tabs)`, `withSelectedIndex()`, `withActiveColor()`, `withTabs()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tabs.gif) |
| `TabsVertical` | Vertical tab navigation | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tabs-vertical.gif) |
| `Breadcrumb` | Breadcrumb navigation | `new(items)`, `fromPath()`, `withItems()`, `withSeparator()`, `withActiveIndex()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/breadcrumb.gif) |
| `Pagination` | Pagination controls | | |
| `PaginationSimple` | Simple pagination | | |
| `Stepper` | Step progress indicator | | |
| `Navbar` | Navigation bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/navbar.gif) |
| `Menu` | Menu component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/menu.gif) |
| `Ladder` | Ladder diagram | | |
| `Sequence` | Sequence diagram | | |
| `Scrollbar` | Custom scrollbar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/scrollbar.gif) |

### Components\StatusBar (Status Bar)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `StatusBar` | Status bar with left/right zones | `new()`, `withLeft()`, `withRight()`, `withSeparator()` | |
| `StatusIndicator` | Status indicator dot | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/status-indicator.gif) |

---

### Components\Card (Card & Content Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Card` | Card container component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/card.gif) |
| `Header` | Page header | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/header.gif) |
| `Footer` | Page footer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/footer.gif) |
| `Cover` | Cover layout | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/cover.gif) |
| `Jumbotron` | Jumbotron hero section | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/jumbotron.gif) |
| `CTA` | Call-to-action component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/cta.gif) |
| `Profile` | User profile card | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/profile.gif) |
| `Testimonial` | Testimonial quote | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/testimonial.gif) |
| `Pricing` | Pricing table | | |
| `Features` | Feature grid | | |
| `Accordion` | Accordion/collapsible | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/accordion.gif) |
| `Comment` | Comment component | | |
| `ActivityFeed` | Activity feed | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/activity-feed.gif) |
| `Leaderboard` | Leaderboard display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/leaderboard.gif) |

### Components\Media (Media Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Image` | Image display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/image.gif) |
| `Picture` | Picture frame | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/picture.gif) |
| `Avatar` | User avatar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/avatar.gif) |
| `AvatarGroup` | Group of avatars | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/avatar-group.gif) |
| `Icon` | Icon display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/icon.gif) |
| `QRCode` | QR code | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/qr-code.gif) |
| `Barcode` | Barcode | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/barcode.gif) |
| `Video` | Video player | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/video.gif) |
| `Audio` | Audio player | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/audio.gif) |
| `FigletText` | ASCII art text (FIGlet style) | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/figlet-text.gif) |
| `ASCIIBanner` | ASCII banner text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/ascii-banner.gif) |
| `Emoji` | Emoji display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/emoji.gif) |
| `Marquee` | Scrolling marquee text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/marquee.gif) |

### Components\Calendar (Calendar & Date Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Calendar` | Calendar view | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/calendar.gif) |
| `ListComponent` | List renderer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/list-component.gif) |

### Components\System (System/Console Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Console` | Terminal console | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/console.gif) |
| `Terminal` | Terminal emulator | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/terminal.gif) |
| `Log` | Log entry | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/log.gif) |
| `LogViewer` | Log file viewer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/log-viewer.gif) |
| `HexDump` | Hex dump viewer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/hex-dump.gif) |
| `Clock` | Digital clock | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/clock.gif) |
| `Timer` | Countdown timer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/timer.gif) |
| `Stopwatch` | Stopwatch | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stopwatch.gif) |

---

### Components\Tree (Tree Structure Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Tree` | Tree structure | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tree.gif) |
| `TreeNode` | Tree node | | |
| `StateMachine` | UML-style state machine diagram | `new()`, `addState()`, `addTransition()`, `addGuard()`, `withInitialState()` | |
| `StateNode` | Node in a state machine (id/label/isInitial/isFinal/entryActions/exitActions) | | |
| `StateTransition` | Transition between states (from/to/label/trigger/action/guard/type) | | |
| `TransitionType` | Enum: `Normal`, `Guard`, `Internal` | | |

### Components\Table (Table Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `TableChart` | Table-based chart | | |
| `TableBordered` | Bordered table | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/table-bordered.gif) |
| `TableZebra` | Zebra-striped table | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/table-zebra.gif) |
| `Stat` | Single stat display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stat.gif) |
| `Stats` | Statistics display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stats.gif) |
| `Metric` | Metric display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/metric.gif) |
| `ProgressList` | List of progress items | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress-list.gif) |

### Components\Text (Text Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Text` | Word-wrapped text content | `new(string)`, `withMaxWidth()`, `withTrim()`, `withHorizontalAlign()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/text.gif) |
| `Paragraph` | Paragraph text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/paragraph.gif) |
| `Code` | Code block with syntax highlighting | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/code.gif) |
| `Kbd` | Keyboard key display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/kbd.gif) |
| `Markdown` | Markdown rendering | | |
| `Highlight` | Syntax highlighted code | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/highlight.gif) |
| `Diff` | Diff view | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/diff.gif) |

---

## Keys Namespace (Key Registry)

| Type | Description | Key Properties |
|------|-------------|----------------|
| `Key` | Key representation |
| `KeyAction` | Key action |
| `KeyMap` | Key mapping |

## State Namespace (State Management)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `State` | Application state diagram | |
| `Persistence` | Atomic tmp+rename state save/load | `save(path, data)`, `load(path): ?array` |

> **Note:** `TransitionType`, `StateNode`, `StateTransition`, and `StateMachine` moved to `Components\Tree\` namespace (PSR-4 one-class-per-file). The `State\*` classes are retained as `@internal` backward-compatibility re-exports via `class_alias`.

## Position Namespace (ANSI-Aware Geometry)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Center` | Calculate centered position |
| `HAlign` | `Left`, `Right`, `Center` |
| `VAlign` | `Top`, `Middle`, `Bottom` |

## Output Namespace (Extracted Helpers)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Truncate` | String truncation with ANSI awareness |
| `RenderBar` | Bar rendering helper |
| `WrapCells` | Cell-aware text wrapping |

## Module Namespace (Module Interface)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Module` | MVC-style Model–Update–View interface aligned with `Core\Model`: `init(): ?Closure`, `update(Msg): array{0:Module,1:?Cmd}`, `view(): string`, plus `name(): string`, `minSize(): array{0:int,1:int}` |
| `BaseModule` | Abstract helper — `withState(array): static` for immutable state, default `update()` returns `[self,null]` |
| `LegacyModule` | Deprecated array-state interface — superseded by `Module` |
| `LegacyModuleAdapter` | `@internal` wrapper that adapts `LegacyModule` to the `Module` contract |
| `ModuleConfig` | Module configuration |
| `ImagePlacer` | Optional interface for image placements |
| `ImagePlacement` | Image placement data |
| `TickEpoch` | Focus-regain epoch counter |

## Registry Namespace (Module Registry)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Registry` | Static register/get/list/reset for modules; auto-wraps `LegacyModule` via `LegacyModuleAdapter` |

## Plugin Namespace (Plugin System)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Request` | Plugin request DTO |
| `Response` | Plugin response DTO |
| `PluginSdk` | Plugin runner loop |
| `ExternalModule` | Wraps binary into Module interface |
| `Discovery` | Plugin discovery from filesystem |

## Modules Namespace (Built-in Modules)

All built-in modules extend `BaseModule` and use `withState()` for immutable state updates.

| Type | Description |
|------|-------------|
| `Clock\ClockModule` | Single-line clock |
| `System\SystemModule` | CPU/mem/disk stats |
| `Uptime\UptimeModule` | System uptime |
| `Greeting\GreetingModule` | Time-of-day greeting |
| `Generic\GenericModule` | Arbitrary shell command runner |
| `Weather\WeatherModule` | Live weather from wttr.in + 30min cache + stale fallback |
| `Weather\WttrInClient` | wttr.in J1 JSON API client implementing `HttpClient` |
| `Weather\HttpClient` | Interface for weather fetch — allows test doubles |
| `Weather\WeatherSnapshot` | Readonly DTO: `tempC`, `condition`, `location`, `fetchedAt` |

---

## Usage Example

```php
use SugarCraft\Dash\Layout\{Frame, VStack, Panel};
use SugarCraft\Dash\Layout\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Components\Card\Text;

// Create a stacked grid layout
$grid = new StackedGrid(new Options(fitScreen: true));

// Add items to columns
$grid->addItem(
    Frame::new(
        VStack::centered(
            Text::new('Welcome to SugarDash'),
            Text::new('Build beautiful TUIs')
        )
    )->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

// Add a panel to the second column
$grid->addItem(
    Panel::titled(
        Text::new('This is a panel'),
        'Dashboard'
    ),
    new ItemOptions(column: 1)
);

// Set size and render
$grid->setSize(80, 24);
echo $grid->render();
```

## Testing

```bash
cd sugar-dash && composer install && vendor/bin/phpunit
```

## GIF Demos

| Demo | Description |
|------|-------------|
| ![Dashboard Live](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-live.gif) | **Interactive dashboard** — Clock/System/Weather panels with keyboard focus rotation (Tab/arrows), q/Ctrl-C quit |
| ![Dashboard Layout](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-layout.gif) | Layout containers demo |
| ![Dashboard Charts](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-charts.gif) | Charts demo |
| ![Dashboard Form](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-form.gif) | Form demo |
| ![Dashboard Nav](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-nav.gif) | Navigation demo |
| ![Dashboard Status](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-status.gif) | Status indicators demo |
| ![Dashboard Text](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-text.gif) | Text components demo |
| ![Dashboard Time](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-time.gif) | Time components demo |
| ![Dashboard UI](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-ui.gif) | UI components demo |
| ![Dashboard Complex](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-complex.gif) | Complex layout demo |
| ![Dashboard Data](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-data.gif) | Data display demo |
| ![Dashboard Devtools](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-devtools.gif) | Devtools demo |
| ![Dashboard Accordion Timeline](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-accordion-timeline.gif) | Accordion and timeline components |
| ![Dashboard Media](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-media.gif) | Media components demo |
| ![Dashboard Metrics](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-metrics.gif) | Metrics display demo |
| ![Boxer](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/boxer.gif) | **Three-panel Boxer layout** — horizontal split with three named leaves and visual focus indicator |
| ![GridTable](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/gridtable.gif) | **Sortable/filterable GridTable** — pagination, column sort, text filter across 25 rows |
| ![Plot Braille](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/plot-braille.gif) | **Plot marker comparison** — side-by-side MarkerDot vs MarkerBraille rendering |

## Example Demos

The `examples/` directory contains standalone demo files that showcase individual components and combinations:

| Demo | Description |
|------|-------------|
| `dashboard-live.php` | **Interactive dashboard** — the headline demo. Program event loop, raw mode, Clock/System/Weather modules, Boxer layout, FocusManager, per-panel tick, keyboard navigation (Tab/arrows), quit (q/Ctrl-C). Run with `php examples/dashboard-live.php` |
| `dashboard-showcase.php` | Multi-component server dashboard with gauges, charts, timeline, breadcrumb, avatar group |
| `dashboard-complex.php` | Full-featured analytics dashboard with charts, stats, funnel, sparkline |
| `dashboard-accordion-timeline.php` | Accordion and timeline components |
| `dashboard-metrics.php` | Key statistics and status indicators |
| `dashboard-status.php` | Spinners, progress bars, gauges, alerts |
| `dashboard-charts.php` | Chart components including area, donut, radar, heatmap |
| `dashboard-form.php` | Form components demo |
| `dashboard-ui.php` | UI components demo |
| `dashboard-nav.php` | Navigation components demo |
| `dashboard-text.php` | Text components demo |
| `dashboard-time.php` | Time components demo |
| `dashboard-media.php` | Media components demo |
| `dashboard-data.php` | Data display components demo |
| `dashboard-devtools.php` | Devtools components demo |
| `dashboard-layout.php` | Layout containers demo |
| `dual-sample-graph.php` | btop-style `DualSampleGraph` in all three families (braille / block / tty), inverted + underlay graphs, and position-coloured `Meter` bars |

## License

MIT License - See LICENSE file for details.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
