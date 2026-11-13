<?php

/**
 * Russian translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'canvas.dim_nonneg'        => 'ширина/высота холста должна быть >= 0',
    'braillegrid.dim_positive' => 'столбцы/строки BrailleGrid должны быть > 0',
    'sparkline.width_nonneg'   => 'ширина спарклайна должна быть >= 0',
    'barchart.dim_nonneg'      => 'ширина/высота столбчатой диаграммы должна быть >= 0',
    'barchart.bar_width_min'   => 'barWidth должен быть >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap должен быть >= 0',
    'barchart.color_return'   => 'функция цвета столбчатой диаграммы должна возвращать {expected} или null, получено {given}',
    'heatmap.dim_nonneg'       => 'ширина/высота тепловой карты должна быть >= 0',
    'heatmap.coords_nonneg'    => 'координаты тепловой точки должны быть >= 0',
    'heatmap.palette_min'      => 'палитра требует минимум 2 цветов (или пусто для отключения)',
    'linechart.dim_nonneg'     => 'ширина/высота линейного графика должна быть >= 0',
    'linechart.color_return'  => 'функция цвета рядов линейного графика должна возвращать {expected} или null, получено {given}',
    'waveline.dim_nonneg'      => 'ширина/высота волновой линии должна быть >= 0',
    'ohlc.dim_nonneg'          => 'ширина/высота OHLC-графика должна быть >= 0',
    'scatter.dim_nonneg'       => 'ширина/высота точечной диаграммы должна быть >= 0',
    'chart.dim_nonneg'         => 'ширина/высота диаграммы должны быть >= 0',
    'finite.non_finite'        => 'значение диаграммы должно быть конечным, получено {value}',
// Support/Rune.php
    'rune.empty'               => '{name} должен быть одной видимой ячейкой, а не пустым',
    'rune.width_one'           => '{name} должен занимать ровно одну ячейку, передано {cells} ячеек',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'Точки TimeSeries должны быть кортежами [DateTimeImmutable, число], передано {given}',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'Список столбцов OHLC должен содержать только экземпляры Bar, передано {given}',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => 'Заголовки {side} не имеют места в горизонтальной композиции; используйте Position::Top или Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart не может печатать подписи значений; используйте BarChart::withDataLabels() или выведите числа из своих данных',
];
