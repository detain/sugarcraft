<img src=".assets/icon.png" alt="sugar-charts" width="160" align="right">

# SugarCharts

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-charts)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-charts)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-charts?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-charts)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/heatmap.gif)
```sh
composer require sugarcraft/sugar-charts
```

PHP port of [NimbleMarkets/ntcharts](https://github.com/NimbleMarkets/ntcharts) —
terminal charts for SugarCraft. v0 ships the canvas foundation plus three
self-contained chart types.

```php
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\LineChart\LineChart;

echo Sparkline::new([1, 3, 2, 8, 5, 4, 7, 6], 8)->view() . PHP_EOL;
// ▁▃▂█▅▄▇▆

echo BarChart::new([['cpu', 0.7], ['mem', 0.4], ['disk', 0.9]], 20, 5)->view() . PHP_EOL;
// 0.9 ┤   ▏  █
// 0.7 ┤█  ▏  █
// 0.4 ┤█  █  █
//     └────────
//      cpu mem disk

echo LineChart::new([1, 4, 2, 8, 6, 3, 7], 30, 6)->view() . PHP_EOL;
```

> Charts also expose short-form aliases on the most-used setters:
> `data` / `size` / `min` / `max` / `point` / `xRange` / `yRange` /
> `colors` / `palette` / `bars` / `barWidth` / `showLabels` / `showAxis`,
> etc. The upstream-mirroring `with*` long forms still work; pick the
> form that reads best at the call site.

## Components

| Component | Role | Notable knobs |
|---|---|---|
| `Charts\Canvas\Canvas` | Fixed-size cell grid; each cell holds a rune + optional Sprinkles `Style`. | `setCell` / `setLines` / `setString` / `setRunes` / `fill` / `fillLine` / `shiftUp` / `shiftDown` / `shiftLeft` / `shiftRight` / `setCellStyle` / `getCellStyle` |
| `Charts\Canvas\BrailleGrid` | Sub-cell scratch buffer (2 cols × 4 rows of dots per cell). Paint dots, then copy to a `Canvas`. | `set` / `unset` / `toggle` / `isSet` / `rune` / `paint(Canvas, x0, y0, ?Style)` / `clear` |
| `Charts\Canvas\Graph` | Drawing primitives over a `Canvas`. | `drawHLine` / `drawVLine` / `drawXYAxis` / `drawXYAxisLabel` / `drawString` / `drawLine` / `drawLinePoints` / `fillRect` / `drawColumn` / `drawColumns` / `drawRows` / `drawCandlestick` / `drawBrailleRune` / `drawBraillePatterns` / `drawVerticalLineUp` / `drawVerticalLineDown` / `drawHorizontalLineLeft` / `drawHorizontalLineRight` / `getCirclePoints` / `getCirclePointsWithLimit` / `getFullCirclePoints` / `getFullCirclePointsWithLimit` / `getLinePointsWithLimit` |
| `Charts\Sparkline\Sparkline` | Single-row series renderer using the 8 Unicode bar glyphs. | `push` / `pushAll` / `clear` / `withMin` / `withMax` / `withStyle(?Style)` / `withNoAutoMaxValue(bool)` / `withWidth` |
| `Charts\BarChart\BarChart` | Labeled vertical bars; auto-scales to a configurable min / max. | `withBarWidth` / `withBarGap` / `withNoAutoBarWidth` / `push(Bar\|array)` / `pushAll(iterable)` / `clear` |
| `Charts\LineChart\LineChart` | Single-series ASCII plot drawn onto a Canvas with configurable axes. | `withYRange` / `withXRange` / `withXYRange` / `autoAdjustRange` / `withXLabelFormatter` / `withYLabelFormatter` / `withAxes` / `withXLabels` / `withYLabels` / `withCanvas` / `withTheme` / `withFill` |
| `Charts\LineChart\TimeSeries` | LineChart variant accepting `[\DateTimeImmutable, value]` tuples. | `push` / `withPoints` / `withTimeFormat` / `withXLabelCount` / `withTimeRange(?start, ?end)` / `getTimeRange()` |
| `Charts\LineChart\Streamline` | Single-row streaming line — auto-windowed to width. | `push` / `pushAll` / `clear` / `withSize` / `withMin` / `withMax` / `withYRange` |
| `Charts\LineChart\Wavelinechart` | XY scatter / wave plot driven by `(x, y)` pairs. | `push` / `pushAll` / `clear` / `withSize` / `withXRange` / `withYRange` / `withXYRange` / `withPoint` |
| `Charts\Heatmap\Heatmap` | 2D grid coloured by value with optional palette / legend. | `withSize` / `withMin` / `withMax` / `withPalette` / `withLegend` / `withColors(cold, hot)` / `withColorProfile` / `withCellStyle(?Style)` / `withAutoValueRange(bool)` / `pushPoint(HeatPoint)` / `pushAll` |
| `Charts\OHLC\OHLC` | Candlestick chart for `(high, open, close, low)` rows. | `withSize` / `withCandleStyle` / `withWickStyle` |
| `Charts\Scatter\Scatter` | XY scatter plot. | `push` / `pushAll` / `withSize` / `withMin` / `withMax` |
| `Charts\Aggregation\BucketByTime` | Groups timestamped values into time buckets with configurable aggregation. | `sum` / `mean` / `min` / `max` / `first` / `last` factory methods + `add` / `addMany` / `compute` |
| `Charts\Aggregation\MovingAverage` | Sliding-window aggregator — SMA and EMA. | `simple` / `centered` / `ema` static factories + `add` / `addMany` / `computeSimple` / `values` / `clear` |
| `Charts\Aggregation\Resample` | Upsample or downsample timestamped series to a target cadence. | `last` / `mean` / `linear` / `nearest` factories + `resample` auto-detection |
| `Charts\Picture\Picture` | Inline image renderer (Sixel today; Kitty / iTerm2 protocols pending). | `withFromFile` / `withDimensions` / `view` |

### Aggregation

Aggregation classes process raw time-series data before charting:

```php
use SugarCraft\Charts\Aggregation\{BucketByTime, MovingAverage, Resample};

// BucketByTime: group into 1-hour buckets, sum values per bucket.
$buckets = BucketByTime::sum(3600, [
    ['ts' => strtotime('2024-01-01 00:10'), 'value' => 10],
    ['ts' => strtotime('2024-01-01 00:45'), 'value' => 15],
    ['ts' => strtotime('2024-01-01 01:05'), 'value' => 20],
]);
// → [{ts: 0, value: 25}, {ts: 3600, value: 20}]

// MovingAverage: 3-point SMA and EMA.
$sma = MovingAverage::simple(3, [1, 4, 2, 8, 6]);
// → [0.0, 0.0, 2.33…, 4.67…, 5.33…]

$ema = MovingAverage::ema(3, [1, 4, 2, 8, 6]);
// → [1.0, 2.5, 2.25, 5.13, 5.56]

// Resample: downsample to 30-second cadence (last value), or upsample (linear interp).
$down = Resample::last(30, $timestampedData);
$up   = Resample::linear(30, $timestampedData);
```

### Braille canvas rendering

Charts support high-resolution dot-matrix (braille) rendering via
`SugarCraft\Dash\Plot\Braille\BrailleCanvas`. Pass a `BrailleCanvas`
instance via `withCanvas()` to switch from character-cell to 2×4
sub-cell resolution:

```php
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;

$canvas = new BrailleCanvas(80, 24);
$chart  = LineChart::new([1, 4, 2, 8, 6], 80, 24)
    ->withCanvas($canvas);
echo $chart->view();
```

### Named color themes

Charts inherit [candy-sprinkles](https://github.com/detain/sugarcraft/tree/master/candy-sprinkles)
`Theme` palette support via `withTheme()`. All `Theme::` factory methods
are supported:

```php
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Sprinkles\Theme;

$chart = LineChart::new([3, 1, 4, 1, 5, 9, 2, 6], 40, 8)
    ->withTheme(Theme::dracula())
    ->withLegend();
echo $chart->view();
```

Available themes: `Theme::ansi()` / `Theme::dark()` / `Theme::light()` /
`Theme::dracula()` / `Theme::tokyoNight()` / `Theme::oneDark()` /
`Theme::githubDark()` / `Theme::solarizedDark()` / `Theme::solarizedLight()`.

## Graph primitives at a glance

`Graph` is a static-method utility — every draw call takes a `Canvas`
plus coordinates in canvas (cell) space (top-left origin). Pair with
`BrailleGrid` for sub-cell precision:

```php
use SugarCraft\Charts\Canvas\{Canvas, BrailleGrid, Graph};

$canvas = new Canvas(40, 8);
Graph::drawXYAxis($canvas, 1, 6, 38);
Graph::drawString($canvas, 4, 0, 'Demo');

// Sub-cell line via BrailleGrid.
$grid = new BrailleGrid(38, 6);
foreach (Graph::getLinePointsWithLimit(0, 0, 75, 23, limit: 200) as [$x, $y]) {
    $grid->set($x, $y);
}
$grid->paint($canvas, 1, 0);
echo $canvas->view();
```

### Palette / colour profile

`Heatmap::withPalette([Color, …])` interpolates between an arbitrary
number of stops (≥ 2). `withColors(Color $cold, Color $hot)` is the
two-stop shortcut. `withColorProfile(ColorProfile)` overrides the
auto-detected output profile when piping to non-tty / Sixel-capable
sinks. `Heatmap::withCellStyle(Style)` overlays additional attributes
(bold / background / etc.) on top of every cell's foreground gradient.

## Demos

### Bar chart

![bar](.vhs/bar.gif)

### Heatmap

![heatmap](.vhs/heatmap.gif)

### Line chart

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
| Canvas, BrailleGrid, Graph primitives | 🟢 v1 |
| Sparkline | 🟢 v1 |
| BarChart | 🟢 v1 (multi-bar grouped datasets pending) |
| LineChart | 🟢 v1 (zoom/pan + dataset styles pending) |
| TimeSeries | 🟢 v1 (multi-dataset + update-handler variants pending) |
| Streamline, Wavelinechart | 🟢 v1 |
| Heatmap | 🟢 v1 (default-color-scale getter/setter pending) |
| OHLC | 🟡 candlestick rendering only — volume sub-pane + multi-series pending |
| Scatter | 🟡 single-dataset only — per-point rune/style sets pending |
| Picture | 🟡 Sixel renderer only — Kitty graphics protocol + iTerm2 inline + half-block fallback pending |

## Test

```sh
cd sugar-charts && composer install && vendor/bin/phpunit
```

## Related

- [SugarCraft monorepo](https://github.com/detain/sugarcraft)
- Upstream: [NimbleMarkets/ntcharts](https://github.com/NimbleMarkets/ntcharts)
