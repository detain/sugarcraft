<?php

/**
 * Polish translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'braillegrid.dim_positive' => 'kolumny/wiersze BrailleGrid muszą być > 0',
    'sparkline.width_nonneg'   => 'szerokość wykresu musi być >= 0',
    'barchart.dim_nonneg'      => 'szerokość/wysokość wykresu słupkowego musi być >= 0',
    'barchart.bar_width_min'   => 'barWidth musi być >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap musi być >= 0',
    'barchart.value_numeric'   => 'wartość słupka musi być numeryczna, podano {given}',
    'barchart.color_return'   => 'funkcja kolorów wykresu słupkowego musi zwracać {expected} lub null, otrzymano {given}',
    'heatmap.dim_nonneg'       => 'szerokość/wysokość mapy ciepła musi być >= 0',
    'heatmap.coords_nonneg'    => 'współrzędne punktu ciepła muszą być >= 0',
    'heatmap.palette_min'      => 'paleta wymaga co najmniej 2 kolorów (lub pusta, aby wyłączyć)',
    'linechart.dim_nonneg'     => 'szerokość/wysokość wykresu liniowego musi być >= 0',
    'linechart.color_return'  => 'funkcja kolorów serii wykresu liniowego musi zwracać {expected} lub null, otrzymano {given}',
    'waveline.dim_nonneg'      => 'szerokość/wysokość linii falowej musi być >= 0',
    'waveline.point_shape'    => 'punkt waveline o indeksie {index} musi być [numeryczne x, numeryczne y], podano {given}',
    'waveline.point_finite'   => 'punkt waveline o indeksie {index} musi mieć skończone współrzędne',
    'ohlc.dim_nonneg'          => 'szerokość/wysokość wykresu OHLC musi być >= 0',
    'scatter.dim_nonneg'       => 'szerokość/wysokość wykresu punktowego musi być >= 0',
    'scatter.point_numeric'    => 'punkt wykresu musi być [numeryczne x, numeryczne y], podano {given}',
    'chart.dim_nonneg'         => 'szerokość/wysokość wykresu musi wynosić >= 0',
    'chart.duration_nonneg'     => 'czas animacji wykresu musi wynosić >= 0 milisekund',
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
    'finite.non_finite'        => 'wartość wykresu musi być skończona, otrzymano {value}',
// Support/Rune.php
    'rune.empty'               => '{name} musi być pojedynczą widoczną komórką, nie pustą',
    'rune.width_one'           => '{name} musi zajmować dokładnie jedną komórkę, podano {cells} komórek',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'Punkty TimeSeries muszą być krotkami [DateTimeImmutable, liczbowe], podano {given}',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'Lista słupków OHLC może zawierać wyłącznie instancje Bar, podano {given}',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => 'Tytuły {side} nie mają światła w poziomej kompozycji; użyj Position::Top lub Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart nie może drukować etykiet wartości; użyj BarChart::withDataLabels() lub wyświetl liczby z własnych danych',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries odrzuca formaty czasu, których środowisko nie potrafi wyrenderować: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'szerokość i wysokość płótna muszą wynosić co najmniej 1',
];
