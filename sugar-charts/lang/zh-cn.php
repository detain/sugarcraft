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
    'chart.dim_nonneg'         => '图表宽度/高度必须 >= 0',
    'finite.non_finite'        => '图表值必须是有限值，实际为 {value}',
// Support/Rune.php
    'rune.empty'               => '{name} 必须是单个可见单元格，不能为空',
    'rune.width_one'           => '{name} 必须恰好占一个单元格，给定 {cells} 个单元格',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'TimeSeries 点必须是 [DateTimeImmutable, 数值] 元组，给定 {given}',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'OHLC 条形列表只能包含 Bar 实例，给定 {given}',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => '{side} 标题在水平布局中没有边栏；请使用 Position::Top 或 Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart 无法打印数值标签；请使用 BarChart::withDataLabels() 或从你自己的数据渲染数值',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries 拒绝运行时无法渲染的时间格式：{fmt}',
];
