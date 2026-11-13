<?php

/**
 * Russian translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'Пустой цвет.',
    'style.unrecognised_color' => 'Нераспознанный цвет: {value} — Допустимо: {allowed}.',
    'style.hex_digit_count' => "Hex-цвет должен содержать 3 или 6 шестнадцатеричных цифр (например, '#f80' или '#ff8800'); получено '{value}'.",
    'style.padding_token_int' => "Токен padding/margin должен быть целым числом; получено: '{token}'.",
    'style.padding_count'     => 'Padding/margin требует 1, 2 или 4 целых чисел; получено: {count}.',
    'style.bad_entry'         => "--style записи должны быть 'ключ=значение' или 'элемент.свойство=значение'; получено: '{raw}'.",
    'style.unknown_prop'      => "Неизвестное свойство стиля: '{prop}'.",
    'process.spawn_failed'    => 'Не удалось запустить дочерний процесс',
    'border.unknown'          => 'Неизвестный стиль рамки: {name}.',
    'log.unknown_level'       => 'Неизвестный уровень логирования: {name}.',
    'log.encode_failed' => 'Не удалось кодировать запись журнала в json.',
    'log.bad_format' => 'Недопустимая строка --format: {format}.',
    'spinner.unknown_style'   => 'Неизвестный стиль спиннера: {name}.',
    'spinner.windows_unsupported' => 'Spin не может запускать команды в нативном Windows: процессный бэкенд там не поддерживается — используйте WSL.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'Неизвестная тема: {name}.',
    'io.read_failed' => 'Не удалось прочитать файл: {path}.',
    'io.write_failed' => 'Не удалось записать в файл: {path}.',
    'runtime.no_terminal' => 'Команде {command} нужен интерактивный терминал; управляющий терминал недоступен.',
    'runtime.bad_timeout' => 'Недопустимое значение --timeout «{value}» — ожидается неотрицательное число секунд или длительность вида 5s/250ms/1m30s (0 отключает дедлайн).',
    'runtime.bad_number' => "Недопустимое значение {flag}: '{value}' — ожидалось целое неотрицательное число.",
    'runtime.pcntl_missing' => 'ext-pcntl не загружен: SIGINT/SIGTERM завершат эту программу без отображения кодов выхода 130/143.',
    'runtime.unknown_choice' => 'Недопустимое значение для {option}: «{value}». Допустимо: {allowed}.',
    'hints.choose' => '↑/↓ выбор · / фильтр · Enter выбрать · Esc отмена',
    'hints.choose.multi' => '↑/↓ выбор · Пробел отметить · / фильтр · Enter выбрать · Esc отмена',
    'hints.filter' => 'фильтр · ↑/↓ ход · Tab отмечает · Enter · Esc',
    'hints.pager' => '↑/↓ j/k ход · ←/→ h/l сдвиг · PgUp/b PgDn/Пробел стр. · Ctrl+U/D полстр.',
    'hints.pager.more' => 'g/G или Home/End начало/конец · q/Esc выход',
    'format.template_unresolved' => 'Шаблон: {count} подстановочных знаков подставились как пустые: {names} — переменная должна быть задана и разрешена через --allow-env.',
    'runtime.empty_input' => 'Во входе не было строк; ничего не выполнено.',
    'runtime.empty_input_filter' => 'Фильтровать нечего; на вход не поступило ни одной строки.',
    'runtime.empty_input_pager' => 'Нет данных для постраничного просмотра; на вход не поступило ни одной строки.',
    'runtime.empty_items' => 'Элементы не заданы — передайте их аргументами или параметром --item.',
    'runtime.conflicting_flags' => 'Флаги {first} и {second} нельзя комбинировать с разными значениями.',
    'runtime.empty_selection' => 'Элемент не выбран — нажмите Пробел, чтобы отметить хотя бы один элемент перед Enter.',
];
