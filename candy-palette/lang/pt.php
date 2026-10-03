<?php

/**
 * Portuguese translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'O índice de cor ANSI 16 tem de ser 0–15, obtido: {index}',
    'color.invalid_hex' => 'cor hexadecimal inválida: {hex}',
    'colormath.invalid_xyz' => 'O componente XYZ {key} está ausente ou não é um número finito',
    'deltae.invalid_lab' => 'A matriz Lab {label} não tem o componente numérico "{key}"',
    'deltae.non_finite_lab' => 'A matriz Lab {label} tem um componente "{key}" não finito',
    'distance.euclidean_needs_rgb' => 'a métrica euclidiana compara bytes RGB — use between() em vez de betweenLab()',
    'nearest.empty_palette' => 'não é possível corresponder uma cor a uma paleta vazia',
];
