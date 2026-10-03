<?php

/**
 * Polish translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'Indeks koloru ANSI 16 musi wynosić 0–15, otrzymano: {index}',
    'color.invalid_hex' => 'nieprawidłowy kolor szesnastkowy: {hex}',
    'colormath.invalid_xyz' => 'Składowa XYZ {key} brak lub nie jest liczbą skończoną',
    'deltae.invalid_lab' => 'Tablica Lab {label} nie zawiera numerycznej składowej "{key}"',
    'deltae.non_finite_lab' => 'Tablica Lab {label} ma nieskończoną składową "{key}"',
    'distance.euclidean_needs_rgb' => 'metryka euklidesowa porównuje bajty RGB — użyj between() zamiast betweenLab()',
    'nearest.empty_palette' => 'nie można dopasować koloru do pustej palety',
];
