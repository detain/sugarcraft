<?php

declare(strict_types=1);

/**
 * Committed render-dump oracle for sugar-charts.
 *
 * Captures the deterministic raw render bytes of 32 default chart surfaces
 * (line variants, bar variants, sparklines, heatmap, scatter, OHLC, time
 * series, streamline, waveline, canvas, aggregation kernels) as one
 * `key\tbyte-length\tbase64` line per surface on stdout.
 *
 * Regenerate and hash from the lib root:
 *
 *     php tests/Support/RenderDump.php | md5sum
 *
 * Oracle digest at the docs-fix C weld: md5 8c140e01c4a1ea422130fc0305d030a9
 * (`php tests/Support/RenderDump.php > dump.b64` byte-compares surfaces
 * individually when the digest moves; the docs remediation proved
 * 30/32 surfaces byte-identical across the theme/label code changes, the
 * two themed LineChart frames being the intended difference).
 *
 * Not a PHPUnit test (no *Test suffix — the suite never picks it up): it is
 * the mutation-proof harness reviewers use to tell a deliberate rendering
 * change apart from an accidental byte drift.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Charts\Aggregation\BucketByTime;
use SugarCraft\Charts\Aggregation\MovingAverage;
use SugarCraft\Charts\BarChart\Bar as ValueBar;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\Streamline;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\LineChart\Waveline;
use SugarCraft\Charts\OHLC\Bar as PriceBar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Plot\Braille\BrailleCanvas;
use SugarCraft\Style\Style;
use SugarCraft\Style\Theme;

$out = [];

$line = [1, 4, 2, 8, 6, 3, 7, 5, 9, 2];
$out['line_plain'] = LineChart::new($line, 30, 6)->view();
$out['line_axes'] = LineChart::new($line, 30, 6)->withAxes()->view();
$out['line_fill'] = LineChart::new($line, 30, 6)->withFill()->view();
$out['line_ds'] = LineChart::new($line, 30, 6)->withDataset('b', [3, 1, 4, 1, 5, 9, 2, 6, 5, 3])->view();
$out['line_braille'] = LineChart::new($line, 30, 6)->withCanvas(new BrailleCanvas(80, 24))->view();
$out['line_braille_ds'] = LineChart::new($line, 30, 6)->withCanvas(new BrailleCanvas(80, 24))->withDataset('b', [3, 1, 4, 1, 5, 9, 2, 6, 5, 3])->view();
$out['line_legend'] = LineChart::new($line, 30, 6)->withDataset('b', [3, 1, 4, 1, 5, 9, 2, 6, 5, 3])->withLegend()->view();
$out['line_unicode'] = LineChart::new($line, 30, 6)->withUnicodeConnectors()->view();
$out['line_title'] = LineChart::new($line, 30, 6)->withTitle('T')->withXLabel('x')->withYLabel('y')->view();
$out['line_anim'] = LineChart::new($line, 30, 6)->withAnimationProgress(0.5)->view();
$out['line_marks'] = LineChart::new($line, 30, 6)->withMarkLines([\SugarCraft\Charts\MarkLine::at(5.0, 'avg')])->view();
$out['line_theme_dracula'] = LineChart::new($line, 40, 8)->withTheme(Theme::dracula())->withLegend()->view();
$out['line_theme_dark'] = LineChart::new($line, 40, 8)->withTheme(Theme::dark())->view();

$bars = [new ValueBar('a', 3.0), new ValueBar('b', 7.0), new ValueBar('c', 5.0)];
$out['bar_plain'] = BarChart::new($bars, 30, 8)->view();
$out['bar_frac'] = BarChart::new($bars, 30, 8)->withFractionalHeights()->view();
$out['bar_horiz'] = BarChart::new($bars, 30, 8)->withHorizontal()->view();
$out['bar_legend'] = BarChart::new($bars, 30, 8)->withLegend()->view();
$out['bar_title'] = BarChart::new($bars, 30, 8)->withTitle('BT')->view();
$out['bar_narrow'] = BarChart::new($bars, 30, 8)->withBarWidth(2)->view();

$out['spark'] = Sparkline::new([1, 3, 2, 8, 5, 4, 7, 6], 8)->view();
$out['spark_nolimit'] = Sparkline::new([1, 4, 2, 7, 5])->view();
$out['spark_bars'] = Sparkline::new([1, 3, 2, 8, 5, 4, 7, 6], 8)->withStyle(Style::new()->fg(Color::parse('red')))->view();

$out['heatmap'] = Heatmap::new([[1, 2], [3, 4]], 2, 2)->view();
$scPts = [[1, 2], [3, 4], [5, 6], [2, 7]];
$out['scatter'] = Scatter::new($scPts, 20, 8)->view();
$out['ohlc'] = OHLCChart::new([new PriceBar(1, 4, 0.5, 3), new PriceBar(3, 6, 2, 5), new PriceBar(5, 5.5, 3, 4)], 20, 8)->view();
$d0 = new DateTimeImmutable('2024-01-01 00:00:00');
$out['ts'] = TimeSeries::new([[$d0, 1.0], [$d0->modify('+1 hour'), 4.0], [$d0->modify('+2 hours'), 2.0]], 20, 6)->view();
$out['stream'] = Streamline::new(20, 6)->push(1)->push(2)->push(3)->push(2)->push(1)->view();
$out['wave'] = Waveline::new([[0, 1], [10, 2], [20, 3], [30, 2], [40, 1]], 20, 6)->view();

$cv = new Canvas(10, 3);
$cv->setCell(0, 0, 'X');
$out['canvas'] = $cv->view();

$out['agg_sma'] = json_encode(array_map(static fn($v): float => round($v, 4), MovingAverage::simple(2, [1, 2, 3, 10, 4])));
$out['agg_ema'] = json_encode(array_map(static fn($v): float => round($v, 4), MovingAverage::ema(2, [1, 2, 3, 10, 4])));
$out['agg_bucket'] = json_encode(BucketByTime::sum(3600, [
    ['ts' => 1704085200, 'value' => 10], ['ts' => 1704085210, 'value' => 15],
    ['ts' => 1704088800, 'value' => 20],
]));

$fp = fopen('php://stdout', 'wb');
foreach ($out as $key => $bytes) {
    fwrite($fp, $key . "\t" . strlen($bytes) . "\t" . base64_encode($bytes) . "\n");
}
fclose($fp);
