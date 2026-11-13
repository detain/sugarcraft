<?php

/**
 * Russian translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'неизвестная тема: {name}',
    'render.theme_config_unreadable' => 'конфигурация темы нечитабельна: {path}',
    'render.theme_config_too_large' => 'конфигурация темы превышает {limit} байт: {path}',
    'render.theme_config_unrecognized' => 'конфигурация темы не соответствует ни плоской, ни glamour-схеме: {path}',
    'glamour.json_invalid' => 'конфигурация темы не является допустимым JSON',
    'glamour.json_object' => 'конфигурация темы должна быть объектом JSON',
    'glamour.decoration_unsafe' => 'поле темы {slot} должно содержать только переводы строк, табуляции и пробелы',
    'glamour.value_too_large' => 'ключ темы {key} превышает максимум {limit}',
    'render.no_input_tty' => 'нет ввода: укажите файл или передайте Markdown на стандартный ввод',
    'render.no_input_empty' => 'нет ввода: стандартный ввод пуст',
    'render.pager_needs_tty' => 'пагинатору нужен терминал; выполните рендер в стандартный вывод без -p',
    'render.file_missing' => 'не удаётся открыть {path}: файл не найден',
    'render.file_is_directory' => 'не удаётся открыть {path}: это каталог',
    'render.file_unreadable' => 'не удаётся открыть {path}: файл нечитабелен',
    'render.binary_input' => 'отказ рендеринга {path}: это не текстовый файл',
    'render.input_too_large' => 'вход превышает {limit} байт: {path}',
    'render.width_invalid' => 'недопустимое значение --width: {value} (ожидалось целое число от 0 до 9999; 0 отключает перенос)',
];
