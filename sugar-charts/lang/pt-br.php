<?php

/**
 * Brazilian Portuguese translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'canvas.dim_nonneg'        => 'a largura/altura do canvas deve ser >= 0',
    'braillegrid.dim_positive' => 'as colunas/linhas do BrailleGrid devem ser > 0',
    'sparkline.width_nonneg'   => 'a largura do sparkline deve ser >= 0',
    'barchart.dim_nonneg'      => 'a largura/altura do gráfico de barras deve ser >= 0',
    'barchart.bar_width_min'   => 'barWidth deve ser >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap deve ser >= 0',
    'barchart.color_return'   => 'o resolvedor de cor do gráfico de barras deve retornar {expected} ou null, recebido {given}',
    'heatmap.dim_nonneg'       => 'a largura/altura do heatmap deve ser >= 0',
    'heatmap.coords_nonneg'    => 'as coordenadas do ponto de calor devem ser >= 0',
    'heatmap.palette_min'      => 'a paleta precisa de pelo menos 2 cores (ou vazia para desativar)',
    'linechart.dim_nonneg'     => 'a largura/altura do gráfico de linhas deve ser >= 0',
    'linechart.color_return'  => 'o resolvedor de cor de série do gráfico de linhas deve retornar {expected} ou null, recebido {given}',
    'waveline.dim_nonneg'      => 'a largura/altura da waveline deve ser >= 0',
    'ohlc.dim_nonneg'          => 'a largura/altura do gráfico OHLC deve ser >= 0',
    'scatter.dim_nonneg'       => 'a largura/altura do gráfico de dispersão deve ser >= 0',
    'chart.dim_nonneg'         => 'a largura/altura do gráfico deve ser >= 0',
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
    'timeseries.time_format' => 'TimeSeries rejeita formatos de hora que o runtime não consegue renderizar: {fmt}',
];
