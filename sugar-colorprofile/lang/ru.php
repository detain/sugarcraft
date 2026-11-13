<?php

/**
 * Russian translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'Индекс цвета ANSI 16 должен быть 0–15, получено: {index}',
    'standard.ansi256_out_of_range' => 'Индекс цвета ANSI 256 должен быть 0–255, получено: {index}',
    'color.invalid_hex' => 'недопустимый шестнадцатеричный цвет: {hex}',
    'colormath.invalid_xyz' => 'Компонент XYZ {key} отсутствует или не является конечным числом',
    'deltae.invalid_lab' => 'В массиве Lab {label} отсутствует числовой компонент "{key}"',
    'deltae.non_finite_lab' => 'В массиве Lab {label} компонент "{key}" не конечен',
    'distance.euclidean_needs_rgb' => 'евклидова метрика сравнивает байты RGB — используйте between() вместо betweenLab()',
    'nearest.empty_palette' => 'невозможно сопоставить цвет с пустой палитрой',
    'probe.unknown_capability' => 'неизвестные ключи возможностей: {keys}',
    'nearest.error_shape' => 'withError() ожидает список из 3 элементов [r, g, b]; получено {count} элемент(ов)',
    'nearest.error_channel' => 'каналы накопителя withError() должны быть int или float; получено {type}',
    'palette_index.metric' => 'PaletteIndex требует метрику, сохраняющую расстояния (Euclidean или Cie76); у {metric} нет фиксированного базиса PCA - используйте вместо этого NearestColor::closest()',
    'stream.invalid_resource' => 'поток {label} должен быть допустимым ресурсом',
];
