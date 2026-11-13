<?php

/**
 * Korean translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'canvas.dim_nonneg'        => '캔버스 너비/높이는 >= 0이어야 합니다',
    'braillegrid.dim_positive' => 'BrailleGrid 열/행은 > 0이어야 합니다',
    'sparkline.width_nonneg'   => '스파클라인 너비는 >= 0이어야 합니다',
    'barchart.dim_nonneg'      => '막대 그래프 너비/높이는 >= 0이어야 합니다',
    'barchart.bar_width_min'   => 'barWidth는 >= 1이어야 합니다',
    'barchart.bar_gap_nonneg'  => 'barGap는 >= 0이어야 합니다',
    'barchart.color_return'   => '막대 차트 색상 리졸버는 {expected} 또는 null을 반환해야 합니다. {given}이(가) 전달됨',
    'heatmap.dim_nonneg'       => '히트맵 너비/높이는 >= 0이어야 합니다',
    'heatmap.coords_nonneg'    => '히트포인트 좌표는 >= 0이어야 합니다',
    'heatmap.palette_min'      => '팔레트에는至少有 2색이 필요합니다 (또는 비활성화하려면 빈 값)',
    'linechart.dim_nonneg'     => '선 그래프 너비/높이는 >= 0이어야 합니다',
    'linechart.color_return'  => '꺾은선 차트 계열 색상 리졸버는 {expected} 또는 null을 반환해야 합니다. {given}이(가) 전달됨',
    'waveline.dim_nonneg'      => '파동선 너비/높이는 >= 0이어야 합니다',
    'ohlc.dim_nonneg'          => 'OHLC 차트 너비/높이는 >= 0이어야 합니다',
    'scatter.dim_nonneg'       => '산점도 너비/높이는 >= 0이어야 합니다',
    'chart.dim_nonneg'         => '차트 너비/높이는 0 이상이어야 합니다',
    'finite.non_finite'        => '차트 값은 유한해야 하며, 다음 값을 받았습니다: {value}',
// Support/Rune.php
    'rune.empty'               => '{name}은(는) 단일 표시 셀이어야 하며 비워 둘 수 없습니다',
    'rune.width_one'           => '{name}은(는) 정확히 한 셀을 차지해야 하며 {cells}셀이 제공되었습니다',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'TimeSeries 점은 [DateTimeImmutable, 숫자] 튜플이어야 하며 {given}이(가) 제공되었습니다',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'OHLC 막대 목록에는 Bar 인스턴스만 포함할 수 있으며 {given}이(가) 제공되었습니다',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => '{side} 제목은 수평 구성에 여백이 없습니다. Position::Top 또는 Position::Bottom을 사용하세요',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart는 값 레이블을 인쇄할 수 없습니다. BarChart::withDataLabels()를 사용하거나 자체 데이터에서 숫자를 렌더링하세요',
];
