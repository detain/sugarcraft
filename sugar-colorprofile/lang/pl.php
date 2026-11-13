<?php

/**
 * Polish translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'Indeks koloru ANSI 16 musi wynosić 0–15, otrzymano: {index}',
    'standard.ansi256_out_of_range' => 'Indeks koloru ANSI 256 musi wynosić 0–255, otrzymano: {index}',
    'color.invalid_hex' => 'nieprawidłowy kolor szesnastkowy: {hex}',
    'colormath.invalid_xyz' => 'Składowa XYZ {key} brak lub nie jest liczbą skończoną',
    'deltae.invalid_lab' => 'Tablica Lab {label} nie zawiera numerycznej składowej "{key}"',
    'deltae.non_finite_lab' => 'Tablica Lab {label} ma nieskończoną składową "{key}"',
    'distance.euclidean_needs_rgb' => 'metryka euklidesowa porównuje bajty RGB — użyj between() zamiast betweenLab()',
    'nearest.empty_palette' => 'nie można dopasować koloru do pustej palety',
    'probe.unknown_capability' => 'nieznane klucze zdolności: {keys}',
    'nearest.error_shape' => 'withError() oczekuje listy 3-elementowej [r, g, b]; otrzymano {count} element(y/ów)',
    'nearest.error_channel' => 'kanały akumulatora withError() muszą być typu int lub float; otrzymano {type}',
    'palette_index.metric' => 'PaletteIndex wymaga metryki zachowującej odległości (Euclidean lub Cie76); {metric} nie ma stałej bazy PCA - użyj zamiast tego NearestColor::closest()',
    'stream.invalid_resource' => 'strumień {label} musi być prawidłowym zasobem',
];
