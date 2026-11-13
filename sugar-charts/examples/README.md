# SugarCharts examples

Each script is self-contained — `php examples/<name>.php` from the
`sugar-charts/` directory renders the chart to stdout.

| Script | What it shows |
|---|---|
| `bar.php` | `BarChart::new` with labeled bars, auto-scaled to the data range. |
| `heatmap.php` | `Heatmap::new` with a perlin-style 2D grid + the cold→hot palette + `withLegend(true)`. |
| `line.php` | `Streamline` rolling-window rendering over a generated series (the demo drives `Streamline::new(width: 60, height: 12)`). |
| `animated_line.php` | Progressive `LineChart` frames via `withAnimationProgress()` — the caller-driven animation story. |
| `ohlc.php` | `OHLCChart::new` candlestick rendering with body / wick styling. |
| `picture.php` | `Picture::fromGrid` / `Picture::detect` encoding demo (Sixel / Kitty / iTerm2) — prints labeled hex dumps where the terminal can't paint images, so no Sixel capability is required. |
| `scatter.php` | XY scatter plot with auto-ranged axes. |
| `braille.php` | `LineChart` in braille dot-grid mode (`withCanvas` as the mode flag). |
| `sparkline.php` | Single-row `Sparkline::new` over a synthesized series, plus `withStyle` to color the glyphs. |
| `timeseries.php` | `TimeSeries::new` with `[\DateTimeImmutable, value]` tuples + `withTimeFormat('H:i')`. |

## Running an example

```sh
cd sugar-charts
composer install        # one-time setup
php examples/heatmap.php
```

Most examples set their own dimensions; to change the size, edit the
constants at the top of the script.

## Quick sketches

### Sparkline that paints itself blue

```php
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Style\Style;
use SugarCraft\Core\Util\{Color, ColorProfile};

$style = Style::new()
    ->foreground(Color::hex('#4488ff'))
    ->colorProfile(ColorProfile::TrueColor);

echo Sparkline::new([1, 5, 3, 8, 4, 7, 2, 9], 8)
    ->withStyle($style)
    ->view();
```

### Time series pinned to a working-day window

```php
use SugarCraft\Charts\LineChart\TimeSeries;

$start = new \DateTimeImmutable('2026-01-01 09:00:00');
$end   = new \DateTimeImmutable('2026-01-01 17:00:00');

$points = [[$start, 41.5], [new \DateTimeImmutable('2026-01-01 13:00:00'), 43.0], [$end, 42.25]];

echo TimeSeries::new($points, 60, 12)
    ->withTimeFormat('H:i')
    ->withTimeRange($start, $end)
    ->view();
```

### Heatmap with a custom multi-stop palette

```php
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Core\Util\Color;

echo Heatmap::new($grid, 60, 16)
    ->withPalette([
        Color::hex('#0a0a55'),
        Color::hex('#3322aa'),
        Color::hex('#dd66cc'),
        Color::hex('#ffcc44'),
    ])
    ->withLegend(true)
    ->view();
```

### Sub-cell scatter with `BrailleGrid`

```php
use SugarCraft\Charts\Canvas\{Canvas, BrailleGrid, Graph};

$canvas = new Canvas(40, 8);
$grid   = new BrailleGrid(40, 8);
$points = [[2, 5], [3, 7], [8, 2], [12, 9], [16, 4]];
foreach ($points as [$x, $y]) {
    $grid->set($x, $y);
}
$grid->paint($canvas);
echo $canvas->view();
```
