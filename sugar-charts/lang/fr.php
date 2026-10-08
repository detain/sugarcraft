<?php

/**
 * French translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Canvas/Canvas.php
    'canvas.dim_nonneg'        => 'la largeur/hauteur du canvas doit être >= 0',

    // Canvas/BrailleGrid.php
    'braillegrid.dim_positive' => 'Les colonnes/lignes de BrailleGrid doivent être > 0',

    // Sparkline/Sparkline.php
    'sparkline.width_nonneg'   => 'la largeur du sparkline doit être >= 0',

    // BarChart/BarChart.php
    'barchart.dim_nonneg'      => 'la largeur/hauteur du graphique à barres doit être >= 0',
    'barchart.bar_width_min'   => 'barWidth doit être >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap doit être >= 0',
    'barchart.color_return'   => 'le résolveur de couleur du graphique en barres doit renvoyer {expected} ou null, {given} reçu',

    // Heatmap/Heatmap.php
    'heatmap.dim_nonneg'       => 'la largeur/hauteur de la heatmap doit être >= 0',
    'heatmap.coords_nonneg'    => 'les coordonnées du point de chaleur doivent être >= 0',
    'heatmap.palette_min'      => 'la palette nécessite au moins 2 couleurs (ou vide pour désactiver)',

    // LineChart/LineChart.php
    'linechart.dim_nonneg'     => 'la largeur/hauteur du graphique linéaire doit être >= 0',
    'linechart.color_return'  => 'le résolveur de couleur de série du graphique linéaire doit renvoyer {expected} ou null, {given} reçu',

    // LineChart/Waveline.php
    'waveline.dim_nonneg'      => 'la largeur/hauteur de la waveline doit être >= 0',

    // OHLC/OHLCChart.php
    'ohlc.dim_nonneg'          => 'la largeur/hauteur du graphique OHLC doit être >= 0',

    // Scatter/Scatter.php
    'scatter.dim_nonneg'       => 'la largeur/hauteur du nuage de points doit être >= 0',
];
