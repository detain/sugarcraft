<?php

/**
 * Spanish translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'El índice de color ANSI 16 debe ser 0–15, obtenido: {index}',
    'color.invalid_hex' => 'color hexadecimal no válido: {hex}',
    'colormath.invalid_xyz' => 'El componente XYZ {key} falta o no es un número finito',
    'deltae.invalid_lab' => 'La matriz Lab {label} carece del componente numérico "{key}"',
    'deltae.non_finite_lab' => 'La matriz Lab {label} tiene un componente "{key}" no finito',
    'distance.euclidean_needs_rgb' => 'la métrica euclidiana compara bytes RGB — use between() en lugar de betweenLab()',
    'nearest.empty_palette' => 'no se puede hacer coincidir un color con una paleta vacía',
];
