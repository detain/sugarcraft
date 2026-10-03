<?php

/**
 * German translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16-Farben-Index muss 0–15 sein, erhalten: {index}',
    'color.invalid_hex' => 'ungültige Hex-Farbe: {hex}',
    'colormath.invalid_xyz' => 'Die XYZ-Komponente {key} fehlt oder ist keine endliche Zahl',
    'deltae.invalid_lab' => 'Dem Lab-Feld {label} fehlt die numerische Komponente "{key}"',
    'deltae.non_finite_lab' => 'Das Lab-Feld {label} hat eine nicht endliche Komponente "{key}"',
    'distance.euclidean_needs_rgb' => 'Die euklidische Metrik vergleicht RGB-Bytes — verwenden Sie between() statt betweenLab()',
    'nearest.empty_palette' => 'Eine Farbe kann nicht mit einer leeren Palette abgeglichen werden',
];
