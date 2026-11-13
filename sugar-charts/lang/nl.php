<?php

/**
 * Dutch translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'braillegrid.dim_positive' => 'BrailleGrid kolommen/rijen moeten > 0 zijn',
    'sparkline.width_nonneg'   => 'sparkline-breedte moet >= 0 zijn',
    'barchart.dim_nonneg'      => 'staafdiagram breedte/hoogte moet >= 0 zijn',
    'barchart.bar_width_min'   => 'barWidth moet >= 1 zijn',
    'barchart.bar_gap_nonneg'  => 'barGap moet >= 0 zijn',
    'barchart.value_numeric'   => 'balkwaarde moet numeriek zijn, {given} opgegeven',
    'barchart.color_return'   => 'kleurresolver van staafdiagram moet {expected} of null teruggeven, {given} gekregen',
    'heatmap.dim_nonneg'       => 'heatmap breedte/hoogte moet >= 0 zijn',
    'heatmap.coords_nonneg'    => 'heatpoint-coördinaten moeten >= 0 zijn',
    'heatmap.palette_min'      => 'palet vereist minstens 2 kleuren (of leeg om uit te schakelen)',
    'linechart.dim_nonneg'     => 'lijndiagram breedte/hoogte moet >= 0 zijn',
    'linechart.color_return'  => 'reekskleurresolver van lijndiagram moet {expected} of null teruggeven, {given} gekregen',
    'waveline.dim_nonneg'      => 'golfijn breedte/hoogte moet >= 0 zijn',
    'waveline.point_shape'    => 'waveline-punt op index {index} moet [numeriek x, numeriek y] zijn, {given} opgegeven',
    'waveline.point_finite'   => 'waveline-punt op index {index} moet eindige coördinaten hebben',
    'ohlc.dim_nonneg'          => 'OHLC-diagram breedte/hoogte moet >= 0 zijn',
    'scatter.dim_nonneg'       => 'spreidingsdiagram breedte/hoogte moet >= 0 zijn',
    'scatter.point_numeric'    => 'spreidingsdiagram-punt moet [numeriek x, numeriek y] zijn, {given} opgegeven',
    'chart.dim_nonneg'         => 'breedte/hoogte van de grafiek moet >= 0 zijn',
    'chart.duration_nonneg'     => 'de animatieduur van de grafiek moet >= 0 milliseconden zijn',
    'resample.row_shape' => 'Resample expects a list of [\'ts\'=>..,\'value\'=>..] rows or [ts,value] tuples; row #{index} has unsupported shape ({given})',
        'resample.output_cap' => 'Upsample would emit {points} rows, above the {cap} ceiling; raise it with withMaxPoints() or use a larger interval.',
        'resample.max_points_positive' => 'Max points must be > 0, got {given}',
        'range.min_exceeds_max' => 'Invalid {axis} range: min ({min}) must not exceed max ({max})',
        'range.pinned_min_exceeds_max' => 'Invalid {axis} range: min ({min}) must not exceed max ({max}) — a lone pinned end that excludes the whole data set leaves no drawable domain',
        'ohlc.high_below_low' => 'Invalid OHLC bar: high ({high}) must not be below low ({low})',
        'markline.empty_dataset' => 'Cannot derive MarkLine from empty dataset',
        'markline.invalid_type' => 'Invalid MarkLine type: {type} (expected one of: {allowed})',
        'markline.invalid_style' => 'Invalid MarkLine style: {style} (expected one of: {allowed})',
        'movingaverage.window_positive' => 'Window size must be > 0, got {given}',
        'movingaverage.alpha_range' => 'EMA alpha must be in (0, 1], got {given}',
        'bucketbytime.interval_positive' => 'Bucket interval must be > 0, got {given}',
        'resample.interval_positive' => 'Target interval must be > 0, got {given}',
    'bucket.row_shape' => 'Binning expects a list of [\'ts\'=>..,\'value\'=>..] rows or [ts,value] tuples; row #{index} has unsupported shape ({given})',
    'legend.item_shape' => 'Legend expects items shaped [\'label\'=>string,\'color\'=>string]; item #{index} has unsupported shape ({given})',
    'finite.non_finite'        => 'de grafiekwaarde moet eindig zijn, gekregen {value}',
// Support/Rune.php
    'rune.empty'               => '{name} moet één zichtbare cel zijn, niet leeg',
    'rune.width_one'           => '{name} moet exact één cel innemen, {cells} cellen opgegeven',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'TimeSeries-punten moeten [DateTimeImmutable, numeriek]-tupels zijn, {given} gegeven',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'De OHLC-balklijst mag alleen Candle-instanties bevatten, {given} gegeven',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => '{side}-titels hebben geen goot in de horizontale compositie; gebruik Position::Top of Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart kan geen waardenlabels afdrukken; gebruik BarChart::withDataLabels() of geef de getallen uit je eigen data weer',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries wijst tijdnotaties af die de runtime niet kan weergeven: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'canvasbreedte en -hoogte moeten elk >= 1 zijn',

    // Canvas/Graph.php axis-label overdraw policy (CH-32a/b)
    'graph.xlabel_skipped' => 'X-label "{label}" overgeslagen: overlapt "{kept}"',
    'graph.ylabel_clipped' => 'Y-label "{label}" ingekort tot "{shown}" om de askolom vrij te houden',
];
