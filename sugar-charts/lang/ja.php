<?php

/**
 * Japanese translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'braillegrid.dim_positive' => 'BrailleGrid の列/行は > 0 である必要があります',
    'sparkline.width_nonneg'   => 'スパークラインの幅は >= 0 である必要があります',
    'barchart.dim_nonneg'      => '棒グラフの幅/高さは >= 0 である必要があります',
    'barchart.bar_width_min'   => 'barWidth は >= 1 である必要があります',
    'barchart.bar_gap_nonneg'  => 'barGap は >= 0 である必要があります',
    'barchart.color_return'   => '棒グラフの色リゾルバーは {expected} または null を返す必要があります（{given} が渡されました）',
    'heatmap.dim_nonneg'       => 'ヒートマップの幅/高さは >= 0 である必要があります',
    'heatmap.coords_nonneg'    => 'ヒートポイント座標は >= 0 である必要があります',
    'heatmap.palette_min'      => 'パレットには少なくとも 2 色が必要です（または無効にするには空）',
    'linechart.dim_nonneg'     => '折れ線グラフの幅/高さは >= 0 である必要があります',
    'linechart.color_return'  => '折れ線グラフの系列色リゾルバーは {expected} または null を返す必要があります（{given} が渡されました）',
    'waveline.dim_nonneg'      => '波線の高さ/幅は >= 0 である必要があります',
    'ohlc.dim_nonneg'          => 'OHLC チャートの幅/高さは >= 0 である必要があります',
    'scatter.dim_nonneg'       => '散布図の幅/高さは >= 0 である必要があります',
    'chart.dim_nonneg'         => 'チャートの幅/高さは >= 0 である必要があります',
    'finite.non_finite'        => 'チャートの値は有限である必要があります。取得値: {value}',
// Support/Rune.php
    'rune.empty'               => '{name} は単一の表示セルである必要があります（空にできません）',
    'rune.width_one'           => '{name} は正確に 1 セルを占める必要があります（{cells} セル指定）',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'TimeSeries の点は [DateTimeImmutable, 数値] タプルである必要があります（指定: {given}）',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'OHLC バーリストには Bar インスタンスのみを含められます（指定: {given}）',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => '{side} タイトルは水平コンポジジョンの余白がないため、Position::Top または Position::Bottom を使用してください',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart は値ラベルを印刷できません。BarChart::withDataLabels() を使うか、自前のデータから数値を描画してください',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries はランタイムで描画できない時刻フォーマットを拒否します: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'キャンバスの幅と高さはそれぞれ 1 以上である必要があります',
];
