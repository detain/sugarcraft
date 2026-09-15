<?php

/**
 * English (default) translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16-color index must be 0–15, got {index}',
    'color.invalid_hex' => 'invalid hex color: {hex}',
    'deltae.invalid_lab' => 'Lab array {label} is missing a numeric "{key}" component',
    'deltae.non_finite_lab' => 'Lab array {label} has a non-finite "{key}" component',
    'distance.euclidean_needs_rgb' => 'the Euclidean metric compares RGB bytes — use between() instead of betweenLab()',
    'colormath.invalid_xyz' => 'XYZ array is missing a finite "{key}" component',
    'nearest.empty_palette' => 'cannot match a color against an empty palette',
];
