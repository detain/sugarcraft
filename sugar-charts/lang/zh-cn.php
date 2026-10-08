<?php

/**
 * Simplified Chinese translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'canvas.dim_nonneg'        => '画布宽/高必须 >= 0',
    'braillegrid.dim_positive' => 'BrailleGrid 列/行必须 > 0',
    'sparkline.width_nonneg'   => '迷你图宽度必须 >= 0',
    'barchart.dim_nonneg'      => '条形图宽/高必须 >= 0',
    'barchart.bar_width_min'   => 'barWidth 必须 >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap 必须 >= 0',
    'barchart.color_return'   => '柱状图颜色解析器必须返回 {expected} 或 null，实际为 {given}',
    'heatmap.dim_nonneg'       => '热力图宽/高必须 >= 0',
    'heatmap.coords_nonneg'    => '热点坐标必须 >= 0',
    'heatmap.palette_min'      => '调色板至少需要 2 种颜色（或留空以禁用）',
    'linechart.dim_nonneg'     => '折线图宽/高必须 >= 0',
    'linechart.color_return'  => '折线图系列颜色解析器必须返回 {expected} 或 null，实际为 {given}',
    'waveline.dim_nonneg'      => '波形线宽/高必须 >= 0',
    'ohlc.dim_nonneg'          => 'OHLC 图宽/高必须 >= 0',
    'scatter.dim_nonneg'       => '散点图宽/高必须 >= 0',
];
