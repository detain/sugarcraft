<?php

/**
 * Czech translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'braillegrid.dim_positive' => 'sloupce/řádky BrailleGrid musí být > 0',
    'sparkline.width_nonneg'   => 'šířka sparkline musí být >= 0',
    'barchart.dim_nonneg'      => 'šířka/výška sloupcového grafu musí být >= 0',
    'barchart.bar_width_min'   => 'barWidth musí být >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap musí být >= 0',
    'barchart.value_numeric'   => 'hodnota sloupce musí být číselná, zadáno {given}',
    'barchart.color_return'   => 'funkce barev sloupcového grafu musí vracet {expected} nebo null, předáno {given}',
    'heatmap.dim_nonneg'       => 'šířka/výška heatmapy musí být >= 0',
    'heatmap.coords_nonneg'    => 'souřadnice heatpointu musí být >= 0',
    'heatmap.palette_min'      => 'paleta vyžaduje alespoň 2 barvy (nebo prázdné pro vypnutí)',
    'linechart.dim_nonneg'     => 'šířka/výška čárového grafu musí být >= 0',
    'linechart.color_return'  => 'funkce barev řad čárového grafu musí vracet {expected} nebo null, předáno {given}',
    'waveline.dim_nonneg'      => 'šířka/výška waveline musí být >= 0',
    'waveline.point_shape'    => 'bod waveline na indexu {index} musí být [číselné x, číselné y], zadáno {given}',
    'waveline.point_finite'   => 'bod waveline na indexu {index} musí mít konečné souřadnice',
    'ohlc.dim_nonneg'          => 'šířka/výška OHLC grafu musí být >= 0',
    'scatter.dim_nonneg'       => 'šířka/výška bodového grafu musí být >= 0',
    'scatter.point_numeric'    => 'bod grafu musí být [číselné x, číselné y], zadáno {given}',
    'chart.dim_nonneg'         => 'šířka/výška grafu musí být >= 0',
    'chart.duration_nonneg'     => 'doba animace grafu musí být >= 0 milisekund',
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
    'finite.non_finite'        => 'hodnota grafu musí být konečná, získáno {value}',
// Support/Rune.php
    'rune.empty'               => '{name} musí být jeden viditelný symbol, ne prázdný',
    'rune.width_one'           => '{name} musí zabírat přesně jeden symbol, zadáno {cells} symbolů',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'Body TimeSeries musí být tuple [DateTimeImmutable, číslo], zadáno {given}',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'Seznam sloupců OHLC musí obsahovat pouze instance Candle, zadáno {given}',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => '{side} titulky nemají v horizontální kompozici místo; použijte Position::Top nebo Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart nemůže tisknout popisky hodnot; použijte BarChart::withDataLabels() nebo vykreslete čísla ze svých dat',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries zamítá formáty času, které prostředí nedokáže vykreslit: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'šířka a výška plátna musí být každá >= 1',

    // Canvas/Graph.php axis-label overdraw policy (CH-32a/b)
    'graph.xlabel_skipped' => 'popisek X "{label}" přeskočen: překrývá "{kept}"',
    'graph.ylabel_clipped' => 'popisek Y "{label}" zkrácen na "{shown}", aby sloupec osy zůstal volný',
];
