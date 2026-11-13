<?php

/**
 * German translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16-Farben-Index muss 0–15 sein, erhalten: {index}',
    'standard.ansi256_out_of_range' => 'ANSI 256-Farben-Index muss 0–255 sein, erhalten: {index}',
    'color.invalid_hex' => 'ungültige Hex-Farbe: {hex}',
    'colormath.invalid_xyz' => 'Die XYZ-Komponente {key} fehlt oder ist keine endliche Zahl',
    'deltae.invalid_lab' => 'Dem Lab-Feld {label} fehlt die numerische Komponente "{key}"',
    'deltae.non_finite_lab' => 'Das Lab-Feld {label} hat eine nicht endliche Komponente "{key}"',
    'distance.euclidean_needs_rgb' => 'Die euklidische Metrik vergleicht RGB-Bytes — verwenden Sie between() statt betweenLab()',
    'nearest.empty_palette' => 'Eine Farbe kann nicht mit einer leeren Palette abgeglichen werden',
    'probe.unknown_capability' => 'unbekannte Capability-Schlüssel: {keys}',
    'nearest.error_shape' => 'withError() erwartet eine 3-Elemente-Liste [r, g, b]; {count} Element(e) erhalten',
    'nearest.error_channel' => 'withError()-Akkumulatorkanäle müssen int oder float sein; erhalten: {type}',
    'palette_index.metric' => 'PaletteIndex benötigt eine distanzerhaltende Metrik (Euclidean oder Cie76); {metric} hat keine feste PCA-Basis - verwenden Sie stattdessen NearestColor::closest()',
    'stream.invalid_resource' => 'Der {label}-Stream muss eine gültige Ressource sein',
];
