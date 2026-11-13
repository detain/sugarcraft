<?php

/**
 * English (default) translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Canvas/Canvas.php

    // Canvas/BrailleGrid.php
    'braillegrid.dim_positive' => 'BrailleGrid cols/rows must be > 0',

    // Sparkline/Sparkline.php
    'sparkline.width_nonneg'   => 'sparkline width must be >= 0',

    // BarChart/BarChart.php
    'barchart.dim_nonneg'      => 'bar chart width/height must be >= 0',
    'barchart.bar_width_min'   => 'barWidth must be >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap must be >= 0',
    'barchart.value_numeric'   => 'bar value must be numeric, {given} given',
    'barchart.color_return'   => 'bar chart color resolver must return {expected} or null, {given} given',

    // Heatmap/Heatmap.php
    'heatmap.dim_nonneg'       => 'heatmap width/height must be >= 0',
    'heatmap.coords_nonneg'    => 'heat point coordinates must be >= 0',
    'heatmap.palette_min'      => 'palette needs at least 2 colors (or empty to disable)',

    // LineChart/LineChart.php
    'linechart.dim_nonneg'     => 'line chart width/height must be >= 0',
    'linechart.color_return'  => 'line chart series color resolver must return {expected} or null, {given} given',

    // LineChart/Waveline.php
    'waveline.dim_nonneg'      => 'waveline width/height must be >= 0',
    'waveline.point_shape'    => 'waveline point at index {index} must be [numeric x, numeric y], {given} given',
    'waveline.point_finite'   => 'waveline point at index {index} must have finite coordinates',

    // OHLC/OHLCChart.php
    'ohlc.dim_nonneg'          => 'OHLC chart width/height must be >= 0',

    // Scatter/Scatter.php
    'scatter.dim_nonneg'       => 'scatter width/height must be >= 0',
    'scatter.point_numeric'    => 'scatter point must be [numeric x, numeric y], {given} given',

    // Chart/Chart.php
    'chart.dim_nonneg'         => 'chart width/height must be >= 0',
    'chart.duration_nonneg'     => 'chart animation duration must be >= 0 milliseconds',
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

    // Support/Finite.php
    'finite.non_finite'        => 'chart value must be finite, got {value}',

    // Support/Rune.php
    'rune.empty'               => '{name} must be a single visible cell, not empty',
    'rune.width_one'           => '{name} must occupy exactly one cell, {cells} cells given',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'TimeSeries points must be [DateTimeImmutable, numeric] tuples, {given} given',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'OHLC bar list must contain only Candle instances, {given} given',

    // Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => '{side} titles have no gutter in the horizontal composition; use Position::Top or Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart cannot print value labels; use BarChart::withDataLabels() or render the numbers from your own data',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries rejects time formats the runtime cannot render: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'canvas width and height must each be >= 1',

    // Canvas/Graph.php axis-label overdraw policy (CH-32a/b)
    'graph.xlabel_skipped' => 'x label "{label}" skipped: it overlaps "{kept}"',
    'graph.ylabel_clipped' => 'y label "{label}" clipped to "{shown}" to keep the axis column clear',
];
