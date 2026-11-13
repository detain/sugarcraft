<?php

/**
 * Italian translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'braillegrid.dim_positive' => 'le colonne/righe di BrailleGrid devono essere > 0',
    'sparkline.width_nonneg'   => 'la larghezza dello sparkline deve essere >= 0',
    'barchart.dim_nonneg'      => 'la larghezza/altezza del grafico a barre deve essere >= 0',
    'barchart.bar_width_min'   => 'barWidth deve essere >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap deve essere >= 0',
    'barchart.color_return'   => 'il resolver dei colori del grafico a barre deve restituire {expected} o null, ricevuto {given}',
    'heatmap.dim_nonneg'       => 'la larghezza/altezza della mappa di calore deve essere >= 0',
    'heatmap.coords_nonneg'    => 'le coordinate del punto di calore devono essere >= 0',
    'heatmap.palette_min'      => 'la palette richiede almeno 2 colori (o vuota per disabilitare)',
    'linechart.dim_nonneg'     => 'la larghezza/altezza del grafico lineare deve essere >= 0',
    'linechart.color_return'  => 'il resolver dei colori delle serie del grafico a linee deve restituire {expected} o null, ricevuto {given}',
    'waveline.dim_nonneg'      => 'la larghezza/altezza della waveline deve essere >= 0',
    'ohlc.dim_nonneg'          => 'la larghezza/altezza del grafico OHLC deve essere >= 0',
    'scatter.dim_nonneg'       => 'la larghezza/altezza del grafico a dispersione deve essere >= 0',
    'chart.dim_nonneg'         => 'larghezza/altezza del grafico devono essere >= 0',
    'finite.non_finite'        => 'il valore del grafico deve essere finito, ottenuto {value}',
// Support/Rune.php
    'rune.empty'               => '{name} deve essere una sola cella visibile, non vuota',
    'rune.width_one'           => '{name} deve occupare esattamente una cella, fornite {cells} celle',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'I punti TimeSeries devono essere tuple [DateTimeImmutable, numerico], fornito {given}',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'La lista di barre OHLC deve contenere solo istanze Bar, fornito {given}',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => 'I titoli {side} non hanno spazio nella composizione orizzontale; usa Position::Top o Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart non può stampare etichette dei valori; usa BarChart::withDataLabels() o mostra i numeri dai tuoi dati',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries rifiuta i formati ora che il runtime non può renderizzare: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'larghezza e altezza della tela devono essere ciascuna >= 1',
];
