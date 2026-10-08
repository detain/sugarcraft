<?php

/**
 * German translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Canvas/Canvas.php
    'canvas.dim_nonneg'        => 'Canvas-Breite/-Höhe muss >= 0 sein',

    // Canvas/BrailleGrid.php
    'braillegrid.dim_positive' => 'BrailleGrid-Spalten/-Zeilen müssen > 0 sein',

    // Sparkline/Sparkline.php
    'sparkline.width_nonneg'   => 'Sparkline-Breite muss >= 0 sein',

    // BarChart/BarChart.php
    'barchart.dim_nonneg'      => 'Balken.diagramm-Breite/-Höhe muss >= 0 sein',
    'barchart.bar_width_min'   => 'barWidth muss >= 1 sein',
    'barchart.bar_gap_nonneg'  => 'barGap muss >= 0 sein',
    'barchart.color_return'   => 'Farbfunktion des Balkendiagramms muss {expected} oder null zurückgeben, {given} erhalten',

    // Heatmap/Heatmap.php
    'heatmap.dim_nonneg'       => 'Heatmap-Breite/-Höhe muss >= 0 sein',
    'heatmap.coords_nonneg'    => 'Heatpunkt-Koordinaten müssen >= 0 sein',
    'heatmap.palette_min'      => 'Palette benötigt mindestens 2 Farben (oder leer zum Deaktivieren)',

    // LineChart/LineChart.php
    'linechart.dim_nonneg'     => 'Liniendiagramm-Breite/-Höhe muss >= 0 sein',
    'linechart.color_return'  => 'Reihenfarbfunktion des Liniendiagramms muss {expected} oder null zurückgeben, {given} erhalten',

    // LineChart/Waveline.php
    'waveline.dim_nonneg'      => 'Wellenlinien-Breite/-Höhe muss >= 0 sein',

    // OHLC/OHLCChart.php
    'ohlc.dim_nonneg'          => 'OHLC-Diagramm-Breite/-Höhe muss >= 0 sein',

    // Scatter/Scatter.php
    'scatter.dim_nonneg'       => 'Streudiagramm-Breite/-Höhe muss >= 0 sein',
];
