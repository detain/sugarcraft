<?php

/**
 * Russian translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'не удалось выполнить {bin}',
    'config.not_found'         => 'Конфигурация wishlist не найдена: {path}',
    'config.json_top_level'    => 'wishlist json: значение верхнего уровня должно быть массивом',
    'config.json_entry_object' => 'wishlist json: каждая запись должна быть объектом',
    'config.yaml_continuation' => "wishlist yaml: продолжение перед любым блоком '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: неразбираемая строка: {line}',
    'config.entry_missing_field' => 'запись wishlist с отсутствующим обязательным полем: name или host',
    'config.field_not_scalar'  => 'запись wishlist: поле [{field}] должно быть скаляром, получено {type}',
    'config.unreadable'       => 'не удалось прочитать конфигурацию wishlist: {path}',
    'config.home_unset'       => 'невозможно раскрыть ~ без $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: встроенная потоковая коллекция не поддерживается: {value} (используйте вложенный список)',

    // endpoint security
    'endpoint.option_injection' => 'эндпоинт wishlist: поле [{field}] содержит небезопасное значение с ведущим дефисом [{value}]',
    'endpoint.port_invalid'     => 'эндпоинт wishlist [{host}]: недопустимый порт [{port}] — должно быть целое число от 1 до 65535',

    'cli.usage'         => 'Использование: candy-ssh-launcher [--config <путь>] [--ssh <ssh-бинарник>] [--list] [-h|--help]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: неизвестный аргумент: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: конфигурация не найдена. Передайте --config <путь>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: отменено.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: бинарный файл ssh не найден или не является исполняемым: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} требует значение',
    'cli.no_endpoints'   => 'candy-ssh-launcher: нет endpoints в {path}',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'фильтр: ',
    'picker.no_matches'  => '  (нет совпадений)',
    'picker.help'        => '  ↑/↓ выбрать · Enter подключить · Esc выйти · печатайте для фильтра',
    'picker.count'       => '  ({shown} из {total})',
];
