<?php

/**
 * Russian translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'Индекс цвета ANSI 16 должен быть 0–15, получено: {index}',
    'color.invalid_hex' => 'недопустимый шестнадцатеричный цвет: {hex}',
    'colormath.invalid_xyz' => 'Компонент XYZ {key} отсутствует или не является конечным числом',
    'deltae.invalid_lab' => 'В массиве Lab {label} отсутствует числовой компонент "{key}"',
    'deltae.non_finite_lab' => 'В массиве Lab {label} компонент "{key}" не конечен',
    'distance.euclidean_needs_rgb' => 'евклидова метрика сравнивает байты RGB — используйте between() вместо betweenLab()',
    'nearest.empty_palette' => 'невозможно сопоставить цвет с пустой палитрой',
];
