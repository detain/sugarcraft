<?php

/**
 * Czech translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'canvas.dim_nonneg'        => 'šířka/výška canvas musí být >= 0',
    'braillegrid.dim_positive' => 'sloupce/řádky BrailleGrid musí být > 0',
    'sparkline.width_nonneg'   => 'šířka sparkline musí být >= 0',
    'barchart.dim_nonneg'      => 'šířka/výška sloupcového grafu musí být >= 0',
    'barchart.bar_width_min'   => 'barWidth musí být >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap musí být >= 0',
    'barchart.color_return'   => 'funkce barev sloupcového grafu musí vracet {expected} nebo null, předáno {given}',
    'heatmap.dim_nonneg'       => 'šířka/výška heatmapy musí být >= 0',
    'heatmap.coords_nonneg'    => 'souřadnice heatpointu musí být >= 0',
    'heatmap.palette_min'      => 'paleta vyžaduje alespoň 2 barvy (nebo prázdné pro vypnutí)',
    'linechart.dim_nonneg'     => 'šířka/výška čárového grafu musí být >= 0',
    'linechart.color_return'  => 'funkce barev řad čárového grafu musí vracet {expected} nebo null, předáno {given}',
    'waveline.dim_nonneg'      => 'šířka/výška waveline musí být >= 0',
    'ohlc.dim_nonneg'          => 'šířka/výška OHLC grafu musí být >= 0',
    'scatter.dim_nonneg'       => 'šířka/výška bodového grafu musí být >= 0',
    'chart.dim_nonneg'         => 'šířka/výška grafu musí být >= 0',
    'finite.non_finite'        => 'hodnota grafu musí být konečná, získáno {value}',
// Support/Rune.php
    'rune.empty'               => '{name} musí být jeden viditelný symbol, ne prázdný',
    'rune.width_one'           => '{name} musí zabírat přesně jeden symbol, zadáno {cells} symbolů',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'Body TimeSeries musí být tuple [DateTimeImmutable, číslo], zadáno {given}',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'Seznam sloupců OHLC musí obsahovat pouze instance Bar, zadáno {given}',
];
