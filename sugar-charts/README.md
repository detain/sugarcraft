<img src=".assets/icon.png" alt="sugar-charts" width="160" align="right">

# SugarCharts

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-charts)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-charts)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/heatmap.gif)
```sh
composer require sugarcraft/sugar-charts:dev-master
```

No tagged releases ship yet — Packagist serves the `dev-master` branch only,
so name it explicitly (`:dev-master`, or `@dev` in a `composer.json` require).
On a default-`stable` project a bare `sugarcraft/sugar-charts` request does
not resolve.

sugar-charts — terminal charts for PHP 8.3+. The lib ships the canvas foundation
(Canvas / BrailleGrid / Graph) plus ten chart renderers — Sparkline, BarChart,
LineChart, TimeSeries, Streamline, Waveline, Heatmap, OHLCChart, Scatter and Picture —
and an Aggregation toolbox. The Components table below is the real value map.

```php
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\LineChart\LineChart;

echo Sparkline::new([1, 3, 2, 8, 5, 4, 7, 6], 8)->view() . PHP_EOL;
// " ▂▁█▅▃▇▆"  (the minimum value maps to the blank leading cell)

echo BarChart::new([['cpu', 0.7], ['mem', 0.4], ['disk', 0.9]], 20, 5)->view() . PHP_EOL;
//               ██████
// ██████        ██████
// ██████ ██████ ██████
// ██████ ██████ ██████
// cpu    mem    disk

echo BarChart::new([['cpu', 0.7], ['mem', 0.4], ['disk', 0.9]], 20, 5)
    ->withFractionalHeights()->view() . PHP_EOL;
// ▁▁▁▁▁▁        ██████
// ██████        ██████
// ██████ ▆▆▆▆▆▆ ██████
// ██████ ██████ ██████
// cpu    mem    disk

echo LineChart::new([1, 4, 2, 8, 6, 3, 7], 30, 6)->view() . PHP_EOL;
```

> Every `with*()` fluent setter on the chart classes (and on `Legend`) also has a
> short-form alias, so
> `data` / `size` / `min` / `max` / `point` / `xRange` / `yRange` / `xyRange` / `colors` /
> `palette` / `bars` / `barWidth` / `showLabels` / `showAxis` / `title` / `canvas` /
> `theme` / `dataset` / `markLines` / `unicodeConnectors` / `style` / `protocol` / `items` / `position` … are all available.
> The convention is `withFoo()` → `foo()` (drop `with`, lcfirst). A few names deviate to
> read better: `withLegendPosition()` → `legendPos()`, `withTitlePosition()` → `titlePos()`
> (Position shortens to `Pos`), `withDataLabelFormatter()` → `dataLabelFormat()`,
> `withFractionalHeights()` → `fractional()`, and `withXYRange()` → `xyRange()`. These
> deviations are pinned by `tests/AliasCompletenessTest.php` — and note that
> `Sparkline`'s width setter aliases to `width()`, not `size()`. The
> `with*` long forms always still work; pick the form that reads best at the call site.
>
> Read accessors follow the house bare-name law (`cell()`, `cellStyle()` on `Canvas`,
> `circlePoints()` and friends on `Graph`). Where a bare name was already taken by a
> setter alias — `timeRange()` on `TimeSeries`; `autoValueRange()`, `cellStyle()` and
> `monoRamp()` on `Heatmap`; the animation pair on `Chart` — the `get*` spelling
> remains the accessor and is a documented exception, not drift.

## Components

| Component | Role | Notable knobs |
|---|---|---|
| `Charts\Canvas\Canvas` | Fixed-size cell grid; each cell holds a rune + optional sugar-style `Style`. | `setCell` / `setLines` / `setString` / `setRunes` / `fill` / `fillLine` / `shiftUp` / `shiftDown` / `shiftLeft` / `shiftRight` / `setCellStyle` / `cellStyle` / `cell` (`getCell` / `getCellStyle` kept as compat aliases) |
| `Charts\Canvas\BrailleGrid` | Sub-cell scratch buffer (2 cols × 4 rows of dots per cell). Paint dots, then copy to a `Canvas`. | `set` / `unset` / `toggle` / `isSet` / `rune` / `paint(Canvas, x0, y0, ?Style)` / `clear` |
| `Charts\Canvas\Graph` | Drawing primitives over a `Canvas`. | `drawHLine` / `drawVLine` / `drawXYAxis` / `drawXYAxisLabel` / `drawString` / `drawLine` / `drawLinePoints` / `fillRect` / `drawColumn` / `drawColumns` / `drawRows` / `drawCandlestick` / `drawBrailleRune` / `drawBraillePatterns` / `drawVerticalLineUp` / `drawVerticalLineDown` / `drawHorizontalLineLeft` / `drawHorizontalLineRight` / `circlePoints` / `circlePointsWithLimit` / `fullCirclePoints` / `fullCirclePointsWithLimit` / `linePointsWithLimit` (`get*` spellings kept as ntcharts-mirror compat aliases) |
| `Charts\Sparkline\Sparkline` | Single-row series renderer using the 8 Unicode bar glyphs. | `push` / `pushAll` / `clear` / `withMin` / `withMax` / `withStyle(?Style)` / `withNoAutoMaxValue(bool)` (parity shim — the flag never changes output; removal would be breaking) / `withWidth` |
| `Charts\BarChart\BarChart` | Labeled bars — vertical or horizontal (`withHorizontal()`, a second render mode); auto-scales to a configurable min / max. | `withBarWidth` / `withBarGap` / `withNoAutoBarWidth` / `withFractionalHeights` (eighth-block caps) / `withBarColor(?Closure)` (per-bar `fn(Bar, int): ?Color`) / `withHorizontal(bool)` / `withShowAxis(bool)` / `withMin(?float)` / `withMax(?float)` / `withDataLabels(bool)` (values as a row, see Value labels) / `withDataLabelFormatter(?Closure)` / `push(Bar\|array)` / `pushAll(iterable)` / `clear` |
| `Charts\LineChart\LineChart` | Multi-series ASCII or braille plot drawn onto a Canvas with configurable axes — `withDataset()` registers a named series and its legend entry (colors cycle a six-name color-blind-safe palette (blue, orange, green, red, purple, cyan), or follow the `withTheme()` palette); the strip itself is drawn only after `withLegend()` — adding datasets never shows it on its own. | `withYRange` / `withXRange` / `withXYRange` / `autoAdjustRange` / `withXLabelFormatter` / `withYLabelFormatter` / `withAxes` / `withXLabels` / `withYLabels` / `withCanvas` / `withTheme` / `withFill` / `withSeriesColorFn(?Closure)` (per-point `fn(string, int, float): ?Color`) |
| `Charts\LineChart\TimeSeries` | LineChart variant accepting `[\DateTimeImmutable, value]` tuples. | `push` / `withPoints` / `withTimeFormat` / `withXLabelCount` / `withTimeRange(?start, ?end)` / `getTimeRange()` |
| `Charts\LineChart\Streamline` | Streaming line windowed to width — renders through a LineChart of configurable height (default 8 rows). | `push` / `pushAll` / `clear` / `isEmpty` / `count` / `withSize` / `withMin` / `withMax` / `withYRange` / `withPoint` |
| `Charts\LineChart\Waveline` | XY scatter / wave plot driven by `(x, y)` pairs. | `push` / `pushAll` / `clear` / `withSize` / `withXRange` / `withYRange` / `withXYRange` / `withPoint` |
| `Charts\Heatmap\Heatmap` | 2D grid colored by value with optional palette / legend. | `withSize` / `withMin` / `withMax` / `withPalette` / `withLegend` / `withColors(cold, hot)` / `withRune` / `withColorProfile` / `withCellStyle(?Style)` / `withAutoValueRange(bool)` / `pushPoint(HeatPoint)` / `pushAll` |
| `Charts\OHLC\OHLCChart` | Candlestick chart for `(open, high, low, close)` rows. | `withBars` / `push` / `withSize` / `withBodyRunes(bull,bear)` / `withWickRune(rune)` / `withMin` / `withMax` / `withColors` / `withTitle` / `withLegend` / `withLegendPosition` |
| `Charts\Scatter\Scatter` | XY scatter plot. | `withPoints` / `withSize` / `withXRange` / `withYRange` / `withRune` / `withTitle` / `withLegend` / `withLegendPosition` / `withLegendItems` |
| `Charts\Aggregation\BucketByTime` | Groups timestamped values into time buckets with configurable aggregation. | `sum` / `mean` / `min` / `max` / `first` / `last` factory methods + `add` / `addMany` / `compute` |
| `Charts\Aggregation\MovingAverage` | Sliding-window aggregator — SMA and EMA. | `simple` / `centered` / `ema` static factories + `add` / `addMany` / `computeSimple` / `values` / `clear` |
| `Charts\Aggregation\Resample` | Upsample or downsample timestamped series to a target cadence. | `last` / `mean` / `linear` / `nearest` factories + `resample` auto-detection |
| `Charts\Picture\Picture` | Inline image renderer — Sixel, Kitty (chunked APC) and iTerm2 (OSC 1337) protocols, with `detect()` routing on `TERM` / `TERM_PROGRAM`. | `fromGrid` / `fromPng` / `withProtocol` / `withPaletteSize` / `detect` / `view` |

### Aggregation

Aggregation classes process raw time-series data before charting:

```php
use SugarCraft\Charts\Aggregation\{BucketByTime, MovingAverage, Resample};

// BucketByTime: group into 1-hour buckets, sum values per bucket.
$buckets = BucketByTime::sum(3600, [
    ['ts' => 1704085800, 'value' => 10],  // 2024-01-01 05:10 UTC
    ['ts' => 1704087900, 'value' => 15],  // 2024-01-01 05:45 UTC
    ['ts' => 1704089100, 'value' => 20],  // 2024-01-01 06:05 UTC
]);
// → [{ts: 1704085200, value: 25}, {ts: 1704088800, value: 20}]
// Note: `ts` is the ABSOLUTE epoch bucket start (floor(ts / 3600) * 3600),
// not a relative offset — render it with gmdate(), not epoch-offset math.

// MovingAverage: 3-point SMA and EMA.
$sma = MovingAverage::simple(3, [1, 4, 2, 8, 6]);
// → [0.0, 0.0, 2.33…, 4.67…, 5.33…]

$ema = MovingAverage::ema(3, [1, 4, 2, 8, 6]);
// → [1.0, 2.5, 2.25, 5.125, 5.5625]

// Resample: downsample to 30-second cadence (last value), or upsample (linear interp).
$timestampedData = [
    ['ts' => 0,  'value' => 1.0],
    ['ts' => 10, 'value' => 2.0],
    ['ts' => 40, 'value' => 3.0],
    ['ts' => 70, 'value' => 4.0],
];
$down = Resample::last(30, $timestampedData);
$up   = Resample::linear(30, $timestampedData);
```

### Braille canvas rendering

`LineChart` supports high-resolution dot-matrix (braille) rendering via
`SugarCraft\Dashboard\Plot\Braille\BrailleCanvas`. Pass a `BrailleCanvas`
instance via `withCanvas()` to switch from character-cell to 2×4
sub-cell resolution. Braille mode needs `composer require
sugarcraft/sugar-dashboard` — an optional display helper, not a runtime
dependency of sugar-charts. `withCanvas()` exists only on the `Chart`
base that `LineChart` extends — `BarChart`, `Scatter` and `OHLCChart`
compose the `ChartExtras` trait and have no braille mode, and `Heatmap`,
`TimeSeries`, `Streamline` and `Waveline` have no such method at all.

The passed canvas works as a **mode flag**: it accepts any non-null
object, its dimensions are ignored and it is never mutated — the chart's
own `width` / `height` still drive the render, so size the *chart* (not
the canvas) below. You do not need the dashboard package installed to
arm the mode; you need it for the `BrailleCanvas` symbol itself and for
embedding the chart's rows in a dashboard layout. Reach for
braille when a pane must carry more samples than it has rows (dense
telemetry, overlapping series); plain cell mode stays the right default
for narrow panes and hosts where glyph widths vary.

```php
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Dashboard\Plot\Braille\BrailleCanvas;

$canvas = new BrailleCanvas(80, 24);
$chart  = LineChart::new([1, 4, 2, 8, 6], 80, 24)
    ->withCanvas($canvas);
echo $chart->view();
```

### Named color themes

`LineChart` consumes a [sugar-style](https://github.com/detain/sugarcraft/tree/master/sugar-style)
`Theme` via `withTheme()`: the theme's `primary` / `accent` / `info` /
`success` / `warning` / `secondary` / `error` colors become the series
palette in render order (primary series first, then each `withDataset()`
in turn, cycling), and the legend indicators are recolored to the same
slots. A `withSeriesColorFn()` color still wins per point; `null` from
the resolver falls back to the theme slot. Charts built **without** a
theme render byte-identically to unthemed output, and `withTheme()`
works in both the ASCII and the braille (`withCanvas()`) pass. All ten
`Theme::` factory methods are supported:

```php
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Style\Theme;

$chart = LineChart::new([3, 1, 4, 1, 5, 9, 2, 6], 40, 8)
    ->withTheme(Theme::dracula())
    ->withLegend();
echo $chart->view();   // truecolor 38;2;… runs follow the dracula slots
```

Available themes: `Theme::ansi()` / `Theme::dark()` / `Theme::light()` /
`Theme::dracula()` / `Theme::tokyoNight()` / `Theme::oneDark()` /
`Theme::githubDark()` / `Theme::solarizedDark()` / `Theme::solarizedLight()` / `Theme::adaptive()` (profile-adaptive).

Theme consumption is scoped to `LineChart` today. The other renderers do
not read a theme (`BarChart` / `Scatter` / `OHLCChart` never had
`withTheme()` — they compose `ChartExtras`; per-bar / per-point color
closures are their styling surface). Widening theme slots to those
renderers would be new public API and is left as a seam.

### Value labels and animation

`BarChart` prints each bar's value as a **data-label row** — above the
bars in vertical mode, trailing the bar in horizontal mode (truncated to
whatever room the row has left):

```php
use SugarCraft\Charts\BarChart\BarChart;

echo BarChart::new([['cpu', 7], ['mem', 4], ['disk', 9]], 20, 5)
    ->withDataLabels(true)
    ->view() . PHP_EOL;
//   7      4      9
//               ██████
// ██████        ██████
// ██████ ██████ ██████
// ██████ ██████ ██████
// cpu    mem    disk
```

The default format trims to an integer when the value is whole, otherwise
up to two decimals (`2.25`, `2.5`). `withDataLabelFormatter(fn(Bar $bar,
int $i): string)` — alias `dataLabelFormat()` — replaces it. The base
`Chart::withDataLabels()` / `withDataLabelFormatter()` storage exists for
API shape; `LineChart` deliberately renders no value labels — its
braille/ASCII canvas paints one dot per column with no spare cell — so
the knob is honest storage only there, and `BarChart` is the label
renderer.

Animation is caller-driven and `LineChart`-only: `withAnimationProgress(0.0…1.0)`
clamps how much of the series a render paints, honored in both the cell
and the braille pass; no other renderer exposes the knob (verified
`method_exists` — `BarChart`, `Scatter`, `OHLCChart`, `Heatmap`,
`TimeSeries`, `Streamline`, `Waveline`, `Sparkline`, `Picture`).
`withAnimationDuration(int $ms)` is a stored **pacing hint** for the
caller's own clock — the lib runs no timers. Drive frames by advancing
the progress yourself, e.g. `examples/animated_line.php`.

### Per-point and per-bar colors

One color per series is too coarse for value-heat graphs — btop tints every
graph point and meter by its value through the theme gradient
(btop's C++ drawing code roughly samples a theme color at the point value). Two optional resolvers bring
that to SugarCharts:

- `LineChart::withSeriesColorFn(?Closure)` — `fn(string $dataset, int $x, float $value): ?Color`.
  A returned `Color` paints that sample's dot, its `withFill()` run and the
  connector leaving it; `null` falls through to the theme slot for that
    series (with `withTheme()`), else the normal per-series color
  (legend color in braille mode, unstyled in cell mode). `$dataset` is the
  series name — `LineChart::PRIMARY_SERIES` (`'_primary'`) for the unnamed
  `$data` series — and `$x` is the sample's index in the *full* series, even
  when the chart tail-slices to fit its width. Works in both cell and braille
  (`withCanvas()`) modes.
- `BarChart::withBarColor(?Closure)` — `fn(Bar $bar, int $i): ?Color`, `$i`
  being the bar's index. Colors the `█` body and the eighth-block cap;
  labels, gaps and the axis are never colored.

Both have short-form aliases (`seriesColorFn()` / `barColor()`), passing
`null` restores the default path byte-identically, and a resolver returning
anything other than `Color|null` throws `\UnexpectedValueException` at
render time. Equal colors share one memoized `Style`, so a resolver may
return a fresh `Color` per call.

```php
use SugarCraft\Charts\BarChart\{Bar, BarChart};
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Core\Util\Color;

$heat = static fn (float $v): Color => match (true) {
    $v >= 7 => Color::hex('#ff5555'),
    $v >= 4 => Color::hex('#f1fa8c'),
    default => Color::hex('#50fa7b'),
};

echo LineChart::new([1, 4, 2, 8, 6, 3, 7], 30, 6)
    ->withSeriesColorFn(
        static fn (string $dataset, int $x, float $v): ?Color =>
            $dataset === LineChart::PRIMARY_SERIES ? $heat($v) : null,
    )
    ->view() . PHP_EOL;

echo BarChart::new([['cpu', 0.7], ['mem', 0.4], ['disk', 0.9]], 20, 5)
    ->withFractionalHeights()
    ->withBarColor(static fn (Bar $bar, int $i): ?Color => $heat($bar->value * 10))
    ->view() . PHP_EOL;
```

### Reference lines: MarkLine, Legend, Position

```php
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\MarkLine;

$chart = LineChart::new([3, 1, 4, 1, 5, 9, 2, 6], 40, 8)
    ->withMarkLines([
        MarkLine::at(7.5, 'alarm', MarkLine::STYLE_DASHED),
    ])
    ->withLegend()
    ->withLegendPosition(Position::Bottom);
echo $chart->view();
```

- `MarkLine::at(float $value, string $label = '', string $style = MarkLine::STYLE_DASHED)`
  is the factory (the constructor is private). `STYLE_SOLID` /
  `STYLE_DASHED` / `STYLE_DOTTED` pick the run glyph; the line is drawn
  across the plot at the value's row. Labels pass the untrusted-text guard
  at construction; non-finite values and unknown styles throw at once.
  Data-derived marks: `MarkLine::min($data)`, `MarkLine::max($data)`,
  `MarkLine::average($data)` (each `(array $data, string $style = STYLE_DASHED)`),
  or `MarkLine::fromDataset($data, $type)` with `$type` one of min/max/average.
- `Legend` is the composed strip — `withItems(list<array{label, color}>)`,
  `withPosition(Position)`, `withIndicatorChar(string)`,
  `withShowBorder(bool)`; short aliases `items()` / `position()`. Charts
  build one for you when `withLegend()` is on.
- `Position` (`SugarCraft\Charts\Chart\Position`: `Top` / `Bottom` /
  `Left` / `Right`) is the enum behind both `withLegendPosition()` and
  `withTitlePosition()` — import it; string forms are not accepted.

## Accessibility note

Charts encode series and magnitude through **color** and glyph position.
Color is the channel that fails first — in monochrome output, under a
`NO_COLOR`-style environment, or for readers with color-vision deficiency.
Pair it with the structural channels that survive it.

**What color actually drops out when.** The environment ladder
(`CLICOLOR_FORCE` / `FORCE_COLOR` beat `NO_COLOR`, which beats `CLICOLOR=0`)
is consulted at exactly one site here: `Legend::colorSuppressed()`, which
suppresses the legend's color indicators. Everything else — bar bodies,
the Heatmap's truecolor fill, themed frame rules — keeps emitting SGR
regardless, and there is no automatic non-TTY degradation: piping a chart
to a file yields full color. To force monochrome deliberately, set the
sugar-style profile for the whole process
(`Style::setDefaultProfile(ColorProfile::Ascii())`) and cool the Heatmap
separately with `Heatmap::withColorProfile(ColorProfile::Ascii())`; the
library never installs a profile from the environment on your behalf.

**Structural channels that never carry color.** `withDataLabels()` prints
bar values as text (BarChart renders them; LineChart's flag and formatter
are stored, not drawn — reach for BarChart when you need labels). Cell-mode
LineChart labels its series with six plain-ASCII point runes (`* + o x # .`),
overridable per-series with `withDatasetPoint()`. Legend entries and axis
labels are plain text. `withRune()` (Scatter, Heatmap) and
`withBodyRunes()` (OHLCChart) replace the glyphs themselves. Braille-dot
plots lose per-series identity entirely when printed without their legend,
so keep `withLegend()` on for shared or logged output. When no graphics
protocol is detected, `Picture::view()` degrades to a plain-text
placeholder, and `examples/picture.php` dumps its frames labeled as hex —
both readable without any color channel.

**Glyph widths.** Plain-ASCII defaults hold only for those six point runes.
Bodies and frames default to Unicode: the eight Sparkline block shades,
BarChart's `█`, the Heatmap fill, OHLC's `│ █ ▒`, MarkLine's `─` / `╌`, and
Graph's thin frame rules. Several of these are East-Asian-Ambiguous and
occupy one or two cells depending on terminal configuration, which shifts
fixed-width rows. There is no library-wide ASCII switch; the per-chart rune
levers above are the mitigation.

## Untrusted labels

Every string this library can echo to a terminal passes through
`SugarCraft\Charts\Support\UntrustedText` — the trust boundary for labels
assembled from application data. The sweep deletes escape-introduced
sequences (CSI, OSC-8 hyperlinks, OSC 0 title writes, DCS/SOS/PM/APC, both
spellings), C0 controls except TAB/LF/CR, DEL, 8-bit C1, and forged
U+E000/U+E001 mouse-zone sentinels; repairs malformed UTF-8 to U+FFFD
before the Unicode-aware passes; converts bidi overrides and zero-width
characters into printable markers; and flattens TAB/LF/CR to spaces, so a
single surviving newline can never desynchronise the fixed-width frame.
Ordinary printable input comes back byte-identical, and the guard is
idempotent.

Static strings are swept at their setters: legend items, dataset names and
runes (`withDatasetPoint()`), axis label arrays, `MarkLine` captions, and
chart titles. Closure-produced text is swept at the resolve and render
sites — `withXLabelFormatter()` / `withYLabelFormatter()` results and
BarChart's `withDataLabelFormatter()` return value — so a formatter that
echoes hostile data cannot bypass the boundary either. The Canvas and
Graph internals that compose the frame are trusted layers and stay
unguarded by design.

## Localization

`lang/` ships sixteen locales. Every user-facing constraint message goes
through `Lang::t()`: chart dimension and non-finite value refusals are
localized, while developer-fault throws (reserved dataset name, unknown
`MarkLine` style, bad resolver return type) stay bare English by design.
`tests/LangTest.php` pins locale key parity against `lang/en.php`, so a
new message must be added to every locale to land.

Terminology: a *tick* is a numeric value at an axis position, a *label*
is the text drawn for one of the evenly-spaced *slots* along an axis
(five slots on X, four on Y by default; `withXLabelFormatter` /
`withYLabelFormatter` accept both).

## Performance

Render cost is linear in width × height. Both canvases are sparse —
empty cells store nothing — so mostly-blank charts render cheaply, and
per-sample style lookups are memoized.

Axis tick values are not a nice-number ladder: the live path
(`LineChart::resolveXLabels` / `resolveYLabels`) emits evenly-spaced
**raw** values between the data bounds (a Y axis can read
`8, 5.6667, 3.3333, 1`) and yields no labels at all unless a label
formatter is set — the formatter decides how those values render.
`Graph::niceNumbers` and `NiceScale` are caller-facing 1-2-5×10ⁿ
primitives (ntcharts mirrors) with zero production callers in the
render path; call them yourself when you want human-readable bounds.

## Shared foundations

`Sparkline` (with the `Buffer` / `BufferHelper` row-composition helpers
it uses) builds through [sugar-cellbuffer](https://github.com/detain/sugarcraft/tree/master/sugar-cellbuffer);
the grid-based renderers (`LineChart`, `Waveline`, `Heatmap`, `Scatter`,
`OHLCChart`) compose the lib-local `SugarCraft\Charts\Canvas\Canvas` —
`BarChart` does not (it composes `ChartExtras` text rows) and `Picture`
emits raw protocol bytes.
Do not re-implement string padding or cell-grid assembly — reuse `Buffer`
or `Canvas` as the shape of your output demands. Display widths come from
`SugarCraft\Core\Util\Width`, so bar labels, gutters and truncation are
**cell-correct** for CJK / emoji text (`BarChart`'s horizontal gutter
included).

## Graph primitives at a glance

`Graph` is a static-method utility — every draw call takes a `Canvas`
plus coordinates in canvas (cell) space (top-left origin). Pair with
`BrailleGrid` for sub-cell precision:

```php
use SugarCraft\Charts\Canvas\{Canvas, BrailleGrid, Graph};

$canvas = new Canvas(40, 8);
Graph::drawXYAxis($canvas, 1, 6, 38, 5);
Graph::drawString($canvas, 4, 0, 'Demo');

// Sub-cell line via BrailleGrid.
$grid = new BrailleGrid(38, 6);
foreach (Graph::linePointsWithLimit(0, 0, 75, 23, limit: 200) as [$x, $y]) {
    $grid->set($x, $y);
}
$grid->paint($canvas, 1, 0);
echo $canvas->view();
```

### Palette / color profile

`Heatmap::withPalette([Color, …])` interpolates between an arbitrary
number of stops (≥ 2). `withColors(Color $cold, Color $hot)` is the
two-stop shortcut. `withColorProfile(ColorProfile)` sets the color space
the gradient is emitted in — there is no output autodetection; the default
is TrueColor. `Heatmap::withCellStyle(Style)` overlays additional attributes
(bold / background / etc.) on top of every cell's foreground gradient.

## Demos

Nine of the ten renderers have a runnable demo: `php examples/<name>.php`
from the `sugar-charts/` directory (see [examples/README.md](examples/README.md)).
The eight CI-recorded GIFs cover eight renderers; `line.php` drives
`Streamline`. Waveline's example is pending.

### Bar chart

![bar](.vhs/bar.gif)

### Heatmap

![heatmap](.vhs/heatmap.gif)

### Streamline (line.gif)

![line](.vhs/line.gif)

### OHLC (candlestick)

![ohlc](.vhs/ohlc.gif)

### Picture (Sixel)

![picture](.vhs/picture.gif)

### Scatter

![scatter](.vhs/scatter.gif)

### Sparkline

![sparkline](.vhs/sparkline.gif)

### Time series

![timeseries](.vhs/timeseries.gif)


## Status

| Component | State |
|---|---|
| Canvas, BrailleGrid, Graph primitives | 🟢 v1 — shipped, nothing pending. |
| Sparkline | 🟢 v1 — shipped, nothing pending. |
| BarChart | 🟢 v1 (data labels + horizontal mode ship; multi-bar grouped datasets pending) |
| LineChart | 🟢 v1 (multi-dataset + per-point colors + theming ship; zoom/pan pending) |
| TimeSeries | 🟢 v1 (multi-dataset + update-handler variants pending) |
| Streamline, Waveline | 🟢 v1 — shipped, nothing pending. |
| Heatmap | 🟢 v1 (auto value-range toggle ships: `withAutoValueRange()` / `getAutoValueRange()`) |
| OHLC | 🟡 candlestick rendering only — volume sub-pane + multi-series pending |
| Scatter | 🟡 single-dataset only — per-point rune/style sets pending |
| Picture | 🟢 v1 — Sixel + Kitty + iTerm2 encoders ship; when no protocol is detected, `view()` prints a plain-text placeholder |

## Snapshot tests

Sparkline render output is covered by golden-file snapshot tests —
`tests/GoldenRenderTest.php` compares actual ANSI bytes against
`sparkline-basic.golden`, `sparkline-minmax.golden` and
`sparkline-styled.golden` in `tests/fixtures/` via
`SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()`. The other
renderers are covered by inline byte assertions in their class suites;
that is the extension path for growing the golden family.

The golden writer is create-only: an existing fixture is never
rewritten, and a mismatch against one still fails with the update arm
set. To re-record after an intentional output change, delete first:

```sh
rm tests/fixtures/<name>.golden && SUGAR_TESTING_UPDATE_GOLDENS=1 vendor/bin/phpunit
```

(`UPDATE_GOLDENS=1` is honored as a legacy spelling; the canonical arm
is `SUGAR_TESTING_UPDATE_GOLDENS=1`.)

## Test

```sh
cd sugar-charts && composer install && vendor/bin/phpunit
```

## Related

- [sugar-style](https://github.com/detain/sugarcraft/tree/master/sugar-style) — `Style` / `Color` / `Theme` consumed here
- [sugar-cellbuffer](https://github.com/detain/sugarcraft/tree/master/sugar-cellbuffer) — `Buffer` substrate behind Sparkline composition
- [sugar-dashboard](https://github.com/detain/sugarcraft/tree/master/sugar-dashboard) — provides the `BrailleCanvas` used by braille mode
- [sugar-testing](https://github.com/detain/sugarcraft/tree/master/sugar-testing) — golden-file snapshot assertions used by the suite
- [NimbleMarkets/ntcharts](https://github.com/NimbleMarkets/ntcharts) — design antecedent
- [SugarCraft monorepo](https://github.com/detain/sugarcraft)

## Credits & inspiration

Design antecedent: [NimbleMarkets/ntcharts](https://github.com/NimbleMarkets/ntcharts); SugarCraft is developed as a native PHP project.
