<?php

/**
 * French translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'L\'index de couleur ANSI 16 doit être entre 0 et 15, reçu : {index}',
    'color.invalid_hex' => 'couleur hexadécimale invalide : {hex}',
    'colormath.invalid_xyz' => 'Le composant XYZ {key} est manquant ou n’est pas un nombre fini',
    'deltae.invalid_lab' => 'Le tableau Lab {label} est dépourvu du composant numérique « {key} »',
    'deltae.non_finite_lab' => 'Le tableau Lab {label} a un composant « {key} » non fini',
    'distance.euclidean_needs_rgb' => 'la métrique euclidienne compare des octets RGB — utilisez between() au lieu de betweenLab()',
    'nearest.empty_palette' => 'impossible de faire correspondre une couleur à une palette vide',
];
