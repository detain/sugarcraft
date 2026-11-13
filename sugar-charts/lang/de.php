<?php

/**
 * German translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Canvas/Canvas.php

    // Canvas/BrailleGrid.php
    'braillegrid.dim_positive' => 'BrailleGrid-Spalten/-Zeilen müssen > 0 sein',

    // Sparkline/Sparkline.php
    'sparkline.width_nonneg'   => 'Sparkline-Breite muss >= 0 sein',

    // BarChart/BarChart.php
    'barchart.dim_nonneg'      => 'Balken.diagramm-Breite/-Höhe muss >= 0 sein',
    'barchart.bar_width_min'   => 'barWidth muss >= 1 sein',
    'barchart.bar_gap_nonneg'  => 'barGap muss >= 0 sein',
    'barchart.value_numeric'   => 'Balkenwert muss numerisch sein, {given} übergeben',
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
    'waveline.point_shape'    => 'Waveline-Punkt an Index {index} muss [numerisches x, numerisches y] sein, {given} übergeben',
    'waveline.point_finite'   => 'Waveline-Punkt an Index {index} muss endliche Koordinaten haben',

    // OHLC/OHLCChart.php
    'ohlc.dim_nonneg'          => 'OHLC-Diagramm-Breite/-Höhe muss >= 0 sein',

    // Scatter/Scatter.php
    'scatter.dim_nonneg'       => 'Streudiagramm-Breite/-Höhe muss >= 0 sein',
    'scatter.point_numeric'    => 'Streudiagrammpunkt muss [numerisches x, numerisches y] sein, {given} übergeben',
    'chart.dim_nonneg'         => 'Diagrammbreite/-höhe muss >= 0 sein',
    'chart.duration_nonneg'     => 'Animationsdauer des Diagramms muss >= 0 Millisekunden sein',
    'resample.row_shape' => 'Resample erwartet eine Liste von [\'ts\'=>..,\'value\'=>..]-Zeilen oder [ts,value]-Tupeln; Zeile #{index} hat eine nicht unterstuetzte Form ({given})',
    'bucket.row_shape' => 'Binning erwartet eine Liste von [\'ts\'=>..,\'value\'=>..]-Zeilen oder [ts,value]-Tupeln; Zeile #{index} hat eine nicht unterstuetzte Form ({given})',
    'legend.item_shape' => 'Legend erwartet Eintraege der Form [\'label\'=>string,\'color\'=>string]; Eintrag #{index} hat eine nicht unterstuetzte Form ({given})',
    'finite.non_finite'        => 'Diagrammwert muss endlich sein, erhalten: {value}',
// Support/Rune.php
    'rune.empty'               => '{name} muss eine einzelne sichtbare Zelle sein, nicht leer',
    'rune.width_one'           => '{name} muss genau eine Zelle belegen, {cells} Zellen angegeben',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'TimeSeries-Punkte müssen [DateTimeImmutable, numerisch]-Tupel sein, {given} angegeben',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'Die OHLC-Balkenliste darf nur Bar-Instanzen enthalten, {given} angegeben',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => '{side}-Titel haben in der waagerechten Komposition keinen Platz; verwende Position::Top oder Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart kann keine Wertelabels drucken; verwende BarChart::withDataLabels() oder gebe die Zahlen aus deinen eigenen Daten aus',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries verwirft Zeitformate, die die Laufzeitumgebung nicht rendern kann: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'Breite und Höhe der Leinwand müssen je >= 1 sein',
];
