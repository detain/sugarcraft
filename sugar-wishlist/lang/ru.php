<?php

/**
 * Russian translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec недоступен; требуется ext-pcntl',
    'launcher.exec_failed'     => 'не удалось выполнить {bin}',
    'config.not_found'         => 'Конфигурация wishlist не найдена: {path}',
    'config.json_top_level'    => 'wishlist json: значение верхнего уровня должно быть массивом',
    'config.json_entry_object' => 'wishlist json: каждая запись должна быть объектом',
    'config.yaml_continuation' => "wishlist yaml: продолжение перед любым блоком '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: неразбираемая строка: {line}',
    'config.entry_missing_field' => 'запись wishlist с отсутствующим обязательным полем: name или host',
    'config.field_not_scalar'  => 'запись wishlist: поле [{field}] должно быть скаляром, получено {type}',

    // endpoint security
    'endpoint.option_injection' => 'эндпоинт wishlist: поле [{field}] содержит небезопасное значение с ведущим дефисом [{value}]',
    'endpoint.port_invalid'     => 'эндпоинт wishlist [{host}]: недопустимый порт [{port}] — должно быть целое число от 1 до 65535',

    'cli.usage'         => 'Использование: wishlist [--config <путь>] [--ssh <ssh-бинарник>]',
    'cli.unknown_arg'   => 'wishlist: неизвестный аргумент: {arg}',
    'cli.no_config'     => 'wishlist: конфигурация не найдена. Передайте --config <путь>.',
    'cli.error'         => 'wishlist: {message}',
    'cli.cancelled'     => 'wishlist: отменено.',
    'cli.ssh_not_executable' => 'wishlist: бинарный файл ssh не найден или не является исполняемым: {bin}',
];
