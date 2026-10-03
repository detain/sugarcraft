<?php

/**
 * Dutch translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16-kleurenindex moet 0–15 zijn, gekregen: {index}',
    'color.invalid_hex' => 'ongeldige hex-kleur: {hex}',
    'colormath.invalid_xyz' => 'XYZ-component {key} ontbreekt of is geen eindig getal',
    'deltae.invalid_lab' => 'De Lab-matrix {label} mist de numerieke component "{key}"',
    'deltae.non_finite_lab' => 'De Lab-matrix {label} heeft een niet-eindige component "{key}"',
    'distance.euclidean_needs_rgb' => 'de Euclidische metriek vergelijkt RGB-bytes — gebruik between() in plaats van betweenLab()',
    'nearest.empty_palette' => 'kan een kleur niet laten overeenkomen met een lege palet',
];
