<?php

/**
 * Russian translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'пустой цвет',
    'style.unrecognised_color' => 'нераспознанный цвет: {value}',
    'style.padding_token_int' => "токен padding/margin должен быть целым числом; получено: '{token}'",
    'style.padding_count'     => 'padding/margin требует 1, 2 или 4 целых чисел; получено: {count}',
    'style.bad_entry'         => "--style записи должны быть 'ключ=значение' или 'элемент.свойство=значение'; получено: '{raw}'",
    'style.unknown_prop'      => "неизвестное свойство стиля: '{prop}'",
    'process.spawn_failed'    => 'не удалось запустить дочерний процесс',
    'border.unknown'          => 'неизвестный стиль рамки: {name}',
    'log.unknown_level'       => 'неизвестный уровень логирования: {name}',
    'spinner.unknown_style'   => 'неизвестный стиль спиннера: {name}',
    'format.unknown_type'     => 'неизвестный --type: {type}',
    'format.unknown_theme'    => 'неизвестная тема: {name}',
];
