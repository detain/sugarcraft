<?php

/**
 * Russian translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'пустой цвет',
    'style.unrecognised_color' => 'нераспознанный цвет: {value} — allowed: {allowed}',
    'style.hex_digit_count' => "hex-цвет должен содержать 3 или 6 шестнадцатеричных цифр (например, '#f80' или '#ff8800'); получено '{value}'",
    'style.padding_token_int' => "токен padding/margin должен быть целым числом; получено: '{token}'",
    'style.padding_count'     => 'padding/margin требует 1, 2 или 4 целых чисел; получено: {count}',
    'style.bad_entry'         => "--style записи должны быть 'ключ=значение' или 'элемент.свойство=значение'; получено: '{raw}'",
    'style.unknown_prop'      => "неизвестное свойство стиля: '{prop}'",
    'process.spawn_failed'    => 'не удалось запустить дочерний процесс',
    'border.unknown'          => 'неизвестный стиль рамки: {name}',
    'log.unknown_level'       => 'неизвестный уровень логирования: {name}',
    'log.encode_failed' => 'не удалось кодировать запись журнала в json',
    'log.bad_format' => 'недопустимая строка --format: {format}',
    'spinner.unknown_style'   => 'неизвестный стиль спиннера: {name}',
    'spinner.windows_unsupported' => 'spin не может запускать команды в нативном Windows: процессный бэкенд там не поддерживается — используйте WSL.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'неизвестная тема: {name}',
    'io.read_failed' => 'не удалось прочитать файл: {path}',
    'io.write_failed' => 'не удалось записать в файл: {path}',
    'log.unknown_formatter' => 'неизвестный --formatter: {name}',
    'completion.unknown_shell' => 'неподдерживаемая оболочка: {shell}',
    'confirm.unknown_default' => 'неизвестное значение --default: {value}',
    'cursor_mode.unknown' => 'неизвестный режим курсора: {mode}',
    'runtime.no_terminal' => 'команде {command} нужен интерактивный терминал; управляющий терминал недоступен.',
    'runtime.bad_timeout' => 'Недопустимое значение --timeout «{value}» — ожидается неотрицательное число секунд (0 отключает дедлайн).',
    'runtime.bad_number' => 'Недопустимое значение {flag}: \'{value}\' — ожидалось целое неотрицательное число.',
    'runtime.pcntl_missing' => 'ext-pcntl не загружен: SIGINT/SIGTERM завершат эту программу без отображения кодов выхода 130/143.',
    'runtime.unknown_choice' => 'Недопустимое значение для {option}: «{value}». Допустимо: {allowed}.',
    'hints.choose' => '↑/↓ перемещение · пробел переключает (многовыб.) · enter выбор · esc отмена',
    'hints.filter' => 'печатайте для фильтра · ↑/↓ перемещение · tab переключает (многовыб.) · enter выбор · esc отмена',
    'hints.pager' => 'space/PgDn вниз · PgUp/b вверх · g начало · G конец · q выход',
    'format.template_unresolved' => 'шаблон: {count} подстановочных знаков раскрылись в пустоту: {names} — переменная должна быть задана и разрешена через --allow-env.',
    'runtime.empty_input' => 'Во входе не было строк; ничего не выполнено.',
    'runtime.conflicting_flags' => 'Флаги {first} и {second} нельзя комбинировать с разными значениями.',
];
