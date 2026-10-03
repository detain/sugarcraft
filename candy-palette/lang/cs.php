<?php

/**
 * Czech translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16 index barev musí být 0–15, obdrženo: {index}',
    'color.invalid_hex' => 'neplatná barva v hex zápisu: {hex}',
    'colormath.invalid_xyz' => 'Složka XYZ {key} chybí nebo to není konečné číslo',
    'deltae.invalid_lab' => 'Pole Lab {label} postrádá číselnou složku "{key}"',
    'deltae.non_finite_lab' => 'Pole Lab {label} má složku "{key}", která není konečné číslo',
    'distance.euclidean_needs_rgb' => 'evklidovská metrika porovnává bajty RGB — použijte between() místo betweenLab()',
    'nearest.empty_palette' => 'barvu nelze přiřadit k prázdné paletě',
];
