<?php

/**
 * Russian translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'не удалось выполнить {bin}',
    'launcher.transport_not_executable' => 'двоичный файл транспорта не найден или не исполняемый: {bin}',
    'launcher.transport_dropped' => 'candy-ssh-launcher: эндпоинт {endpoint}: собственный транспорт игнорирует {legs}',
    'config.not_found'         => 'Конфигурация wishlist не найдена: {path}',
    'config.json_top_level'    => 'wishlist json: значение верхнего уровня должно быть массивом',
    'config.json_entry_object' => 'wishlist json: каждая запись должна быть объектом',
    'config.yaml_continuation' => "wishlist yaml: продолжение перед любым блоком '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: неразбираемая строка {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: блочное отображение под `{field}:` в строке {line}: {found} — это поле должно быть списком элементов `- value`',
    'config.yaml_unterminated_quote' => 'wishlist yaml: незакрытая кавычка в значении: {found}',
    'config.entry_missing_field' => 'запись wishlist #{index}: не хватает обязательного поля: {field}',
    'config.unknown_key' => 'неизвестное поле `{key}` проигнорировано — такого ключа нет в документации',
    'config.unknown_key_nearmiss' => 'неизвестное поле `{key}` проигнорировано — вы имели в виду `{field}`?',
    'config.duplicate_name' => 'повтор имени endpoint `{name}` — все строки загружаются; имена — идентификаторы',
    'config.field_not_scalar'  => 'запись wishlist: поле [{field}] должно быть скаляром, получено {type}',
    'config.too_large'          => 'файл конфигурации вишлиста превышает лимит в {limit} байт: {path}',
    'config.list_too_long'      => 'запись вишлиста: поле [{field}] содержит более {limit} элементов, разрешённых загрузчиком',
    'config.field_not_list'       => 'запись вишлиста: поле [{field}] должно быть списком значений, а не отображением ключ-значение',
    'config.encoding_utf16'       => 'файл {path} имеет кодировку UTF-16 — пересохраните конфигурацию в UTF-8',
    'config.unreadable'       => 'не удалось прочитать конфигурацию wishlist: {path}',
    'config.home_unset'       => 'невозможно раскрыть ~ без $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: встроенная потоковая коллекция не поддерживается: {value} (используйте вложенный список)',

    // endpoint security
    'endpoint.option_injection' => 'эндпоинт wishlist: поле [{field}] содержит небезопасное значение с ведущим дефисом [{value}]',
    'endpoint.host_empty' => 'эндпоинт `{name}` содержит пустой host — выбираемой строке нужно имя хоста',
    'endpoint.name_empty' => 'у endpoint пустое имя — выбираемой строке нужно имя',
    'endpoint.option_empty' => 'эндпоинт `{name}` содержит пустую запись options — пустое значение -o отклоняется',
    'endpoint.port_invalid'     => 'эндпоинт wishlist [{host}]: недопустимый порт [{port}] — должно быть целое число от 1 до 65535',

    'cli.usage'         => "Использование: candy-ssh-launcher [--config <путь>] [--ssh <путь>] [--from-ssh-config] [--list] [-h|--help] [-v|--version] [--] [эндпоинт]\nКлавиши: стрелки перемещают (и j/k при пустом фильтре), набор текста фильтрует, Enter подключает, Ctrl+U очищает строку, Ctrl+W удаляет слово, Ctrl+H удаляет назад, Esc отменяет\nКоды выхода: 0 запущено или выведено, 1 нет конфигурации, битая конфигурация или отказ бинарного файла, 2 неизвестный флаг или аргумент либо нет терминала, 3 эндпоинт отклонён при запуске, 130 отменено\nНет интерактивного терминала? --list печатает все эндпоинты, а точно названный эндпоинт запускается и без терминала.\nОдиночное -- завершает разбор опций; всё после него игнорируется — назовите эндпоинт до него.",
    'cli.unknown_arg'   => 'candy-ssh-launcher: неизвестный аргумент: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: конфигурация не найдена. Создайте ~/.config/wishlist.yml (см. быстрый запуск в README) или передайте --config <путь>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: отменено.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: бинарный файл ssh не найден или не является исполняемым: {bin} — передайте --ssh <путь>, если ssh находится в другом месте',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} требует значение',
    'cli.no_endpoints'   => 'candy-ssh-launcher: нет эндпоинтов в {path}',
    'cli.not_a_terminal' => 'для выбора требуется интерактивный терминал: stdin и stdout должны быть TTY. Используйте --list для машинного вывода.',
    'cli.config_unowned'   => 'candy-ssh-launcher: отклонена конфигурация, принадлежащая другому пользователю: {path} — конфигурации являются доверенными данными; см. раздел Security в README.',
    'cli.config_writable'  => 'candy-ssh-launcher: отклонена конфигурация, доступная для записи группе или всем: {path} — ужесточите режим до 0644 или 0600 либо передайте её явно через --config.',
    'cli.project_local'    => 'candy-ssh-launcher: используется локальная wishlist проекта: {path}',
    'cli.unsafe_option_notice' => 'candy-ssh-launcher: эндпоинт запрашивает -o {option} — {effect}',
    'cli.unsafe_option_effect_strict_host_key_checking' => 'проверка ключей хоста ослаблена — ключ сервера может остаться непроверенным',
    'cli.unsafe_option_effect_user_known_hosts_file' => 'пользовательская база доверия известных хостов перенаправлена',
    'cli.unsafe_option_effect_global_known_hosts_file' => 'системная база доверия перенаправлена',
    'cli.unsafe_option_effect_log_level' => 'предупреждения и уведомления ssh скрыты',
    'cli.unsafe_option_effect_identity_agent' => 'аутентификация делегирована другому сокету агента',
    'cli.unsafe_option_effect_certificate_file' => 'предложен дополнительный сертификат аутентификации',
    'cli.ssh_config_source'  => 'candy-ssh-launcher: импорт эндпоинтов из конфигурации ssh: {path}',
    'cli.ssh_config_missing' => 'candy-ssh-launcher: конфигурация ssh не найдена: {path} — укажите другой файл, передав --config <path> вместе с --from-ssh-config',
    'cli.config_is_ssh_config' => 'candy-ssh-launcher: {path} похож на конфигурацию OpenSSH, а не на файл wishlist — импортируйте его с --from-ssh-config (при необходимости с --config <path>)',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'фильтр: ',
    'picker.no_matches'  => '  (нет совпадений — Backspace или Esc)',
    'picker.help'        => '↑/↓ выбрать · Enter подключить · Esc выйти · печатайте для фильтра',
    'picker.help_keys'   => '⌫ стереть · Ctrl+U/W очистить · PgUp/PgDn страница · Home/End переход',
    'picker.count'       => '  ({shown} из {total})',
];
