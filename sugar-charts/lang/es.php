<?php

/**
 * Spanish translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Canvas/Canvas.php
    'canvas.dim_nonneg'        => 'el ancho/alto del canvas debe ser >= 0',

    // Canvas/BrailleGrid.php
    'braillegrid.dim_positive' => 'las columnas/filas de BrailleGrid deben ser > 0',

    // Sparkline/Sparkline.php
    'sparkline.width_nonneg'   => 'el ancho del sparkline debe ser >= 0',

    // BarChart/BarChart.php
    'barchart.dim_nonneg'      => 'el ancho/alto del gráfico de barras debe ser >= 0',
    'barchart.bar_width_min'   => 'barWidth debe ser >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap debe ser >= 0',
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

    // OHLC/OHLCChart.php
    'ohlc.dim_nonneg'          => 'el ancho/alto del gráfico OHLC debe ser >= 0',

    // Scatter/Scatter.php
    'scatter.dim_nonneg'       => 'el ancho/alto del diagrama de dispersión debe ser >= 0',
];
