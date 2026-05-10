<?php

/**
 * English (default) translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Canvas/Canvas.php
    'canvas.dim_nonneg'        => 'canvas width/height must be >= 0',

    // Canvas/BrailleGrid.php
    'braillegrid.dim_positive' => 'BrailleGrid cols/rows must be > 0',

    // Sparkline/Sparkline.php
    'sparkline.width_nonneg'   => 'sparkline width must be >= 0',

    // BarChart/BarChart.php
    'barchart.dim_nonneg'      => 'bar chart width/height must be >= 0',
    'barchart.bar_width_min'   => 'barWidth must be >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap must be >= 0',

    // Heatmap/Heatmap.php
    'heatmap.dim_nonneg'       => 'heatmap width/height must be >= 0',
    'heatmap.coords_nonneg'    => 'heat point coordinates must be >= 0',
    'heatmap.palette_min'      => 'palette needs at least 2 colours (or empty to disable)',

    // LineChart/LineChart.php
    'linechart.dim_nonneg'     => 'line chart width/height must be >= 0',

    // LineChart/Waveline.php
    'waveline.dim_nonneg'      => 'waveline width/height must be >= 0',

    // OHLC/OHLCChart.php
    'ohlc.dim_nonneg'          => 'OHLC chart width/height must be >= 0',

    // Scatter/Scatter.php
    'scatter.dim_nonneg'       => 'scatter width/height must be >= 0',

    // Chart/Chart.php
    'chart.dim_nonneg'         => 'chart width/height must be >= 0',
];
