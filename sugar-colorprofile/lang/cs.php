<?php

/**
 * Czech translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16 index barev musí být 0–15, obdrženo: {index}',
    'standard.ansi256_out_of_range' => 'ANSI 256 index barev musí být 0–255, obdrženo: {index}',
    'color.invalid_hex' => 'neplatná barva v hex zápisu: {hex}',
    'colormath.invalid_xyz' => 'Složka XYZ {key} chybí nebo to není konečné číslo',
    'deltae.invalid_lab' => 'Pole Lab {label} postrádá číselnou složku "{key}"',
    'deltae.non_finite_lab' => 'Pole Lab {label} má složku "{key}", která není konečné číslo',
    'distance.euclidean_needs_rgb' => 'evklidovská metrika porovnává bajty RGB — použijte between() místo betweenLab()',
    'nearest.empty_palette' => 'barvu nelze přiřadit k prázdné paletě',
    'probe.unknown_capability' => 'neznámé klíče schopností: {keys}',
    'nearest.error_shape' => 'withError() očekává seznam 3 prvků [r, g, b]; obdrženo {count} prvek(prvků)',
    'nearest.error_channel' => 'kanály akumulátoru withError() musí být int nebo float; obdržen typ {type}',
    'palette_index.metric' => 'PaletteIndex vyžaduje metriku zachovávající vzdálenost (Euclidean nebo Cie76); {metric} nemá pevnou PCA bázi - místo toho použijte NearestColor::closest()',
    'stream.invalid_resource' => 'Proud {label} musí být platný zdroj',
];
