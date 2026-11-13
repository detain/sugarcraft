<?php

/**
 * Turkish translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16 renk dizini 0–15 olmalıdır, alınan: {index}',
    'standard.ansi256_out_of_range' => 'ANSI 256 renk dizini 0–255 olmalıdır, alınan: {index}',
    'color.invalid_hex' => 'geçersiz onaltılık renk: {hex}',
    'colormath.invalid_xyz' => 'XYZ bileşeni {key} eksik veya sonlu bir sayı değil',
    'deltae.invalid_lab' => 'Lab dizisi {label}, sayısal bir "{key}" bileşeni içermiyor',
    'deltae.non_finite_lab' => 'Lab dizisi {label} sonlu olmayan bir "{key}" bileşenine sahip',
    'distance.euclidean_needs_rgb' => 'Öklid metriği RGB baytlarını karşılaştırır — betweenLab() yerine between() kullanın',
    'nearest.empty_palette' => 'boş bir paletle renk eşleştirilemiyor',
    'probe.unknown_capability' => 'bilinmeyen yetenek anahtarı(ları): {keys}',
    'nearest.error_shape' => 'withError() 3 öğeli bir liste [r, g, b] bekler; {count} öğe alındı',
    'nearest.error_channel' => 'withError() toplayıcı kanalları int veya float olmalıdır; alınan: {type}',
    'palette_index.metric' => 'PaletteIndex mesafe koruyan bir ölçüt gerektirir (Euclidean veya Cie76); {metric} sabit bir PCA tabanına sahip değil - bunun yerine NearestColor::closest() kullanın',
];
