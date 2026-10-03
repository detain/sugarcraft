<?php

/**
 * Italian translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'L\'indice del colore ANSI 16 deve essere 0–15, ottenuto: {index}',
    'color.invalid_hex' => 'colore esadecimale non valido: {hex}',
    'colormath.invalid_xyz' => 'La componente XYZ {key} è mancante o non è un numero finito',
    'deltae.invalid_lab' => 'La matrice Lab {label} non contiene la componente numerica "{key}"',
    'deltae.non_finite_lab' => 'La matrice Lab {label} ha una componente "{key}" non finita',
    'distance.euclidean_needs_rgb' => 'la metrica euclidea confronta byte RGB — usa between() invece di betweenLab()',
    'nearest.empty_palette' => 'impossibile abbinare un colore a una tavolozza vuota',
];
