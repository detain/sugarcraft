<?php

/**
 * Spanish translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Canvas/Canvas.php

    // Canvas/BrailleGrid.php
    'braillegrid.dim_positive' => 'las columnas/filas de BrailleGrid deben ser > 0',

    // Sparkline/Sparkline.php
    'sparkline.width_nonneg'   => 'el ancho del sparkline debe ser >= 0',

    // BarChart/BarChart.php
    'barchart.dim_nonneg'      => 'el ancho/alto del gráfico de barras debe ser >= 0',
    'barchart.bar_width_min'   => 'barWidth debe ser >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap debe ser >= 0',
    'barchart.value_numeric'   => 'el valor de la barra debe ser numérico, {given} recibido',
    'barchart.color_return'   => 'el resolvedor de color del gráfico de barras debe devolver {expected} o null, se recibió {given}',

    // Heatmap/Heatmap.php
    'heatmap.dim_nonneg'       => 'el ancho/alto del heatmap debe ser >= 0',
    'heatmap.coords_nonneg'    => 'las coordenadas del punto de calor deben ser >= 0',
    'heatmap.palette_min'      => 'la paleta necesita al menos 2 colores (o vacío para desactivar)',

    // LineChart/LineChart.php
    'linechart.dim_nonneg'     => 'el ancho/alto del gráfico de líneas debe ser >= 0',
    'linechart.color_return'  => 'el resolvedor de color de series del gráfico de líneas debe devolver {expected} o null, se recibió {given}',

    // LineChart/Waveline.php
    'waveline.dim_nonneg'      => 'el ancho/alto de la waveline debe ser >= 0',
    'waveline.point_shape'    => 'el punto de waveline en el índice {index} debe ser [x numérico, y numérico], {given} recibido',
    'waveline.point_finite'   => 'el punto de waveline en el índice {index} debe tener coordenadas finitas',

    // OHLC/OHLCChart.php
    'ohlc.dim_nonneg'          => 'el ancho/alto del gráfico OHLC debe ser >= 0',

    // Scatter/Scatter.php
    'scatter.dim_nonneg'       => 'el ancho/alto del diagrama de dispersión debe ser >= 0',
    'scatter.point_numeric'    => 'el punto de dispersión debe ser [x numérico, y numérico], {given} recibido',
    'chart.dim_nonneg'         => 'el ancho/alto del gráfico debe ser >= 0',
    'finite.non_finite'        => 'el valor del gráfico debe ser finito, se obtuvo {value}',
// Support/Rune.php
    'rune.empty'               => '{name} debe ser una sola celda visible, no vacía',
    'rune.width_one'           => '{name} debe ocupar exactamente una celda, se indicaron {cells} celdas',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'Los puntos de TimeSeries deben ser tuplas [DateTimeImmutable, numérico], se recibió {given}',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'La lista de barras OHLC debe contener solo instancias de Bar, se recibió {given}',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => 'Los títulos {side} no tienen hueco en la composición horizontal; usa Position::Top o Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart no puede imprimir etiquetas de valores; usa BarChart::withDataLabels() o muestra los números de tus propios datos',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries rechaza formatos de hora que el entorno no puede renderizar: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'el ancho y el alto del lienzo deben ser >= 1 cada uno',
];
