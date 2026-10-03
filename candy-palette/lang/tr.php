<?php

/**
 * Turkish translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16 renk dizini 0–15 olmalıdır, alınan: {index}',
    'color.invalid_hex' => 'geçersiz onaltılık renk: {hex}',
    'colormath.invalid_xyz' => 'XYZ bileşeni {key} eksik veya sonlu bir sayı değil',
    'deltae.invalid_lab' => 'Lab dizisi {label}, sayısal bir "{key}" bileşeni içermiyor',
    'deltae.non_finite_lab' => 'Lab dizisi {label} sonlu olmayan bir "{key}" bileşenine sahip',
    'distance.euclidean_needs_rgb' => 'Öklid metriği RGB baytlarını karşılaştırır — betweenLab() yerine between() kullanın',
    'nearest.empty_palette' => 'boş bir paletle renk eşleştirilemiyor',
];
