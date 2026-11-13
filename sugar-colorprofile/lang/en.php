<?php

/**
 * English (default) translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16-color index must be 0–15, got {index}',
    'standard.ansi256_out_of_range' => 'ANSI 256-color index must be 0–255, got {index}',
    'color.invalid_hex' => 'invalid hex color: {hex}',
    'deltae.invalid_lab' => 'Lab array {label} is missing a numeric "{key}" component',
    'deltae.non_finite_lab' => 'Lab array {label} has a non-finite "{key}" component',
    'distance.euclidean_needs_rgb' => 'the Euclidean metric compares RGB bytes — use between() instead of betweenLab()',
    'colormath.invalid_xyz' => 'XYZ component {key} is missing or not a finite number',
    'nearest.empty_palette' => 'cannot match a color against an empty palette',
    'probe.unknown_capability' => 'unknown capability key(s): {keys}',
    'nearest.error_shape' => 'withError() expects a 3-element list [r, g, b]; got {count} element(s)',
    'nearest.error_channel' => 'withError() accumulator channels must be int or float; got {type}',
    'palette_index.metric' => 'PaletteIndex requires a distance-preserving metric (Euclidean or Cie76); {metric} has no fixed PCA basis - use NearestColor::closest() instead',
    'stream.invalid_resource' => 'the {label} stream must be a valid resource',
];
