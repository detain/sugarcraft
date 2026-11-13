<?php

/**
 * Turkish translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'braillegrid.dim_positive' => 'BrailleGrid sütun/satır > 0 olmalıdır',
    'sparkline.width_nonneg'   => 'sparkline genişliği >= 0 olmalıdır',
    'barchart.dim_nonneg'      => 'çubuk grafik genişlik/yüksekliği >= 0 olmalıdır',
    'barchart.bar_width_min'   => 'barWidth >= 1 olmalıdır',
    'barchart.bar_gap_nonneg'  => 'barGap >= 0 olmalıdır',
    'barchart.value_numeric'   => 'çubuk değeri sayısal olmalıdır, {given} verildi',
    'barchart.color_return'   => 'çubuk grafik renk çözücüsü {expected} veya null döndürmelidir, {given} verildi',
    'heatmap.dim_nonneg'       => 'ısı haritası genişlik/yüksekliği >= 0 olmalıdır',
    'heatmap.coords_nonneg'    => 'ısı noktası koordinatları >= 0 olmalıdır',
    'heatmap.palette_min'      => 'palet en az 2 renk gerektirir (veya devre dışı bırakmak için boş)',
    'linechart.dim_nonneg'     => 'çizgi grafik genişlik/yüksekliği >= 0 olmalıdır',
    'linechart.color_return'  => 'çizgi grafik seri renk çözücüsü {expected} veya null döndürmelidir, {given} verildi',
    'waveline.dim_nonneg'      => 'dalga çizgisi genişlik/yüksekliği >= 0 olmalıdır',
    'waveline.point_shape'    => 'waveline noktası {index} indeksinde [sayısal x, sayısal y] olmalıdır, {given} verildi',
    'waveline.point_finite'   => 'waveline noktası {index} indeksinde sonlu koordinatlara sahip olmalıdır',
    'ohlc.dim_nonneg'          => 'OHLC grafiği genişlik/yüksekliği >= 0 olmalıdır',
    'scatter.dim_nonneg'       => 'dağılım grafiği genişlik/yüksekliği >= 0 olmalıdır',
    'scatter.point_numeric'    => 'dağılım noktası [sayısal x, sayısal y] olmalıdır, {given} verildi',
    'chart.dim_nonneg'         => 'grafik genişliği/yüksekliği >= 0 olmalıdır',
    'chart.duration_nonneg'     => 'grafik animasyon süresi >= 0 milisaniye olmalıdır',
    'resample.row_shape' => 'Resample expects a list of [\'ts\'=>..,\'value\'=>..] rows or [ts,value] tuples; row #{index} has unsupported shape ({given})',
        'resample.output_cap' => 'Upsample would emit {points} rows, above the {cap} ceiling; raise it with withMaxPoints() or use a larger interval.',
        'resample.max_points_positive' => 'Max points must be > 0, got {given}',
    'bucket.row_shape' => 'Binning expects a list of [\'ts\'=>..,\'value\'=>..] rows or [ts,value] tuples; row #{index} has unsupported shape ({given})',
    'legend.item_shape' => 'Legend expects items shaped [\'label\'=>string,\'color\'=>string]; item #{index} has unsupported shape ({given})',
    'finite.non_finite'        => 'grafik değeri sonlu olmalıdır, alınan: {value}',
// Support/Rune.php
    'rune.empty'               => '{name} tek bir görünür hücre olmalı, boş olmamalı',
    'rune.width_one'           => '{name} tam olarak bir hücre kapmalı, {cells} hücre verildi',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'TimeSeries noktaları [DateTimeImmutable, sayısal] demetleri olmalı, {given} verildi',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'OHLC çubuk listesi yalnızca Bar örnekleri içermeli, {given} verildi',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => '{side} başlıkların yatay kompozisyonda yeri yoktur; Position::Top veya Position::Bottom kullanın',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart değer etiketleri yazamaz; BarChart::withDataLabels() kullanın veya sayıları kendi verinizden çizdirin',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries, çalışma zamanının oluşturamadığı saat biçimlerini reddeder: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'tuval genişliği ve yüksekliği her biri >= 1 olmalıdır',
];
