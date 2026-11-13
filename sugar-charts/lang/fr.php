<?php

/**
 * French translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Canvas/Canvas.php

    // Canvas/BrailleGrid.php
    'braillegrid.dim_positive' => 'Les colonnes/lignes de BrailleGrid doivent être > 0',

    // Sparkline/Sparkline.php
    'sparkline.width_nonneg'   => 'la largeur du sparkline doit être >= 0',

    // BarChart/BarChart.php
    'barchart.dim_nonneg'      => 'la largeur/hauteur du graphique à barres doit être >= 0',
    'barchart.bar_width_min'   => 'barWidth doit être >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap doit être >= 0',
    'barchart.value_numeric'   => 'la valeur de la barre doit être numérique, {given} fourni',
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
    'waveline.point_shape'    => 'le point waveline à l\'index {index} doit être [x numérique, y numérique], {given} fourni',
    'waveline.point_finite'   => 'le point waveline à l\'index {index} doit avoir des coordonnées finies',

    // OHLC/OHLCChart.php
    'ohlc.dim_nonneg'          => 'la largeur/hauteur du graphique OHLC doit être >= 0',

    // Scatter/Scatter.php
    'scatter.dim_nonneg'       => 'la largeur/hauteur du nuage de points doit être >= 0',
    'scatter.point_numeric'    => 'le point du nuage de points doit être [x numérique, y numérique], {given} fourni',
    'chart.dim_nonneg'         => 'la largeur/hauteur du graphique doit être >= 0',
    'chart.duration_nonneg'     => 'la durée d\'animation du graphique doit être >= 0 millisecondes',
    'resample.row_shape' => 'Resample attend une liste de lignes [\'ts\'=>..,\'value\'=>..] ou de tuples [ts,valeur] ; la ligne #{index} a une forme non prise en charge ({given})',
        'resample.output_cap' => 'Le reechantillonnage emettrait {points} lignes, au-dessus du plafond {cap}; relevez-le avec withMaxPoints() ou utilisez un intervalle plus grand.',
        'resample.max_points_positive' => 'Le nombre max de points doit etre > 0, recu {given}',
        'range.min_exceeds_max' => 'Plage {axis} invalide : min ({min}) ne doit pas depasser max ({max})',
        'range.pinned_min_exceeds_max' => 'Plage {axis} invalide : min ({min}) ne doit pas depasser max ({max}) — une borne epinglee excluant tout le jeu de donnees ne laisse aucun domaine tracable',
        'ohlc.high_below_low' => 'Barre OHLC invalide : high ({high}) ne doit pas etre inferieur a low ({low})',
        'markline.empty_dataset' => 'Impossible de determiner une MarkLine a partir d\'un jeu de donnees vide',
        'markline.invalid_type' => 'Type MarkLine invalide : {type} (attendu : {allowed})',
        'markline.invalid_style' => 'Style MarkLine invalide : {style} (attendu : {allowed})',
        'movingaverage.window_positive' => 'La taille de fenetre doit etre > 0, recu {given}',
        'movingaverage.alpha_range' => 'Le alpha EMA doit etre dans (0, 1], recu {given}',
        'bucketbytime.interval_positive' => 'L\'intervalle de seau doit etre > 0, recu {given}',
        'resample.interval_positive' => 'L\'intervalle cible doit etre > 0, recu {given}',
    'bucket.row_shape' => 'Binning attend une liste de lignes [\'ts\'=>..,\'value\'=>..] ou de tuples [ts,valeur] ; la ligne #{index} a une forme non prise en charge ({given})',
    'legend.item_shape' => 'Legend attend des entrees de forme [\'label\'=>string,\'color\'=>string] ; l\'entree #{index} a une forme non prise en charge ({given})',
    'finite.non_finite'        => 'la valeur du graphique doit être finie, valeur reçue : {value}',
// Support/Rune.php
    'rune.empty'               => '{name} doit être une seule cellule visible, et non vide',
    'rune.width_one'           => '{name} doit occuper exactement une cellule, {cells} cellules fournies',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'Les points TimeSeries doivent être des tuples [DateTimeImmutable, numérique], {given} fourni',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'La liste de barres OHLC ne doit contenir que des instances Bar, {given} fourni',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => 'Les titres {side} n’ont pas de gouttière dans la composition horizontale ; utilisez Position::Top ou Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'LineChart ne peut pas imprimer d’étiquettes de valeurs ; utilisez BarChart::withDataLabels() ou affichez les nombres depuis vos propres données',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries rejette les formats d’heure que l’exécution ne peut pas rendre : {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'la largeur et la hauteur du canevas doivent être chacune >= 1',
];
