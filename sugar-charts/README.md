# SugarCharts

![demo](.vhs/heatmap.gif)

PHP port of [NimbleMarkets/ntcharts](https://github.com/NimbleMarkets/ntcharts) —
terminal charts for CandyCore. v0 ships the canvas foundation plus three
self-contained chart types.

```php
use CandyCore\Charts\Sparkline\Sparkline;
use CandyCore\Charts\BarChart\BarChart;
use CandyCore\Charts\LineChart\LineChart;

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

## Components

- **`Charts\Canvas\Canvas`** — fixed-size cell grid. Each cell holds a rune
  + optional Sprinkles `Style`. The renderer walks the grid line by line
  and emits a ready-to-print frame.
- **`Charts\Sparkline\Sparkline`** — single-row series renderer using the
  8 Unicode bar glyphs.
- **`Charts\BarChart\BarChart`** — labeled vertical bars. Auto-scales to a
  configurable min / max.
- **`Charts\LineChart\LineChart`** — single-series ASCII plot drawn onto a
  Canvas with configurable axes.

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

- v0 ships canvas + sparkline + bar + line.
- Heatmap, OHLC, scatter, streamline, time series, picture (Sixel/Kitty)
  remain post-v0.

## Test

```sh
cd sugar-charts && composer install && vendor/bin/phpunit
```
