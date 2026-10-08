<?php

/**
 * Polish translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'canvas.dim_nonneg'        => 'szerokość/wysokość canvas musi być >= 0',
    'braillegrid.dim_positive' => 'kolumny/wiersze BrailleGrid muszą być > 0',
    'sparkline.width_nonneg'   => 'szerokość wykresu musi być >= 0',
    'barchart.dim_nonneg'      => 'szerokość/wysokość wykresu słupkowego musi być >= 0',
    'barchart.bar_width_min'   => 'barWidth musi być >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap musi być >= 0',
    'barchart.color_return'   => 'funkcja kolorów wykresu słupkowego musi zwracać {expected} lub null, otrzymano {given}',
    'heatmap.dim_nonneg'       => 'szerokość/wysokość mapy ciepła musi być >= 0',
    'heatmap.coords_nonneg'    => 'współrzędne punktu ciepła muszą być >= 0',
    'heatmap.palette_min'      => 'paleta wymaga co najmniej 2 kolorów (lub pusta, aby wyłączyć)',
    'linechart.dim_nonneg'     => 'szerokość/wysokość wykresu liniowego musi być >= 0',
    'linechart.color_return'  => 'funkcja kolorów serii wykresu liniowego musi zwracać {expected} lub null, otrzymano {given}',
    'waveline.dim_nonneg'      => 'szerokość/wysokość linii falowej musi być >= 0',
    'ohlc.dim_nonneg'          => 'szerokość/wysokość wykresu OHLC musi być >= 0',
    'scatter.dim_nonneg'       => 'szerokość/wysokość wykresu punktowego musi być >= 0',
];
