<?php

/**
 * Portuguese translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'braillegrid.dim_positive' => 'as colunas/linhas do BrailleGrid têm de ser > 0',
    'sparkline.width_nonneg'   => 'a largura do sparkline tem de ser >= 0',
    'barchart.dim_nonneg'      => 'a largura/altura do gráfico de barras tem de ser >= 0',
    'barchart.bar_width_min'   => 'barWidth tem de ser >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap tem de ser >= 0',
    'barchart.value_numeric'   => 'o valor da barra tem de ser numérico, {given} fornecido',
    'barchart.color_return'   => 'o resolvedor de cor do gráfico de barras tem de devolver {expected} ou null, recebido {given}',
    'heatmap.dim_nonneg'       => 'a largura/altura do heatmap tem de ser >= 0',
    'heatmap.coords_nonneg'    => 'as coordenadas do ponto de calor têm de ser >= 0',
    'heatmap.palette_min'      => 'a paleta precisa de pelo menos 2 cores (ou vazia para desativar)',
    'linechart.dim_nonneg'     => 'a largura/altura do gráfico de linhas tem de ser >= 0',
    'linechart.color_return'  => 'o resolvedor de cor de série do gráfico de linhas tem de devolver {expected} ou null, recebido {given}',
    'waveline.dim_nonneg'      => 'a largura/altura da waveline tem de ser >= 0',
    'waveline.point_shape'    => 'o ponto de waveline no índice {index} tem de ser [x numérico, y numérico], {given} fornecido',
    'waveline.point_finite'   => 'o ponto de waveline no índice {index} tem de ter coordenadas finitas',
    'ohlc.dim_nonneg'          => 'a largura/altura do gráfico OHLC tem de ser >= 0',
    'scatter.dim_nonneg'       => 'a largura/altura do gráfico de dispersão tem de ser >= 0',
    'scatter.point_numeric'    => 'o ponto de dispersão tem de ser [x numérico, y numérico], {given} fornecido',
    'chart.dim_nonneg'         => 'a largura/altura do gráfico deve ser >= 0',
    'chart.duration_nonneg'     => 'a duração da animação do gráfico deve ser >= 0 milissegundos',
    'finite.non_finite'        => 'o valor do gráfico deve ser finito, obtido {value}',
// Support/Rune.php
    'rune.empty'               => '{name} deve ser uma única célula visível, não vazia',
    'rune.width_one'           => '{name} deve ocupar exatamente uma célula, {cells} células fornecidas',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'Os pontos do TimeSeries devem ser tuplas [DateTimeImmutable, numérico], {given} fornecido',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'A lista de barras OHLC deve conter apenas instâncias de Bar, {given} fornecido',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => 'Títulos {side} não têm espaço na composição horizontal; use Position::Top ou Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'O LineChart não pode imprimir rótulos de valores; use BarChart::withDataLabels() ou renderize os números dos seus próprios dados',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries rejeita formatos de hora que o tempo de execução não consegue renderizar: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'a largura e a altura da tela devem ser cada uma >= 1',
];
