<?php

/**
 * Spanish translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'El índice de color ANSI 16 debe ser 0–15, obtenido: {index}',
    'standard.ansi256_out_of_range' => 'El índice de color ANSI 256 debe ser 0–255, obtenido: {index}',
    'color.invalid_hex' => 'color hexadecimal no válido: {hex}',
    'colormath.invalid_xyz' => 'El componente XYZ {key} falta o no es un número finito',
    'deltae.invalid_lab' => 'La matriz Lab {label} carece del componente numérico "{key}"',
    'deltae.non_finite_lab' => 'La matriz Lab {label} tiene un componente "{key}" no finito',
    'distance.euclidean_needs_rgb' => 'la métrica euclidiana compara bytes RGB — use between() en lugar de betweenLab()',
    'nearest.empty_palette' => 'no se puede hacer coincidir un color con una paleta vacía',
    'probe.unknown_capability' => 'clave(s) de capacidad desconocida(s): {keys}',
    'nearest.error_shape' => 'withError() espera una lista de 3 elementos [r, g, b]; se recibieron {count} elemento(s)',
    'nearest.error_channel' => 'los canales del acumulador de withError() deben ser int o float; se recibió {type}',
    'palette_index.metric' => 'PaletteIndex requiere una métrica que preserve la distancia (Euclidean o Cie76); {metric} no tiene una base PCA fija - use NearestColor::closest() en su lugar',
    'stream.invalid_resource' => 'el flujo {label} debe ser un recurso válido',
];
