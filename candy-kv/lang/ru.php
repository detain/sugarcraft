<?php

/**
 * Russian translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Запись была сохранена, но больше не читается (истекла, удалена или повреждена)',
    'store.cannot_read'         => 'Не удаётся прочитать файл: {path}',
    'cli.usage_set'             => 'Использование: {bin} set <ключ> [значение] [--ttl SECONDS]',
    'cli.usage_get'             => 'Использование: {bin} get <ключ>',
    'cli.usage_delete'          => 'Использование: {bin} delete <ключ>',
    'cli.usage_import'           => 'Использование: {bin} import <json|yaml|yml> <путь>',
    'cli.usage_export'            => 'Использование: {bin} export <json|yaml|yml> [база] [шаблон]',
    'cli.deleted_n'             => 'Удалено {count} записей.',
    'cli.unknown_command'       => 'Неизвестная команда: {cmd}',
    'cli.deleted_none'           => 'Ничего не удалено: записи не соответствуют "{key}".',
    'cli.pattern_not_supported'  => 'get требует точный ключ, а не шаблон. Для glob-шаблонов используйте "{bin} list <pattern>".',
    'store.did_you_mean'         => 'ключ не найден; вы имели в виду \'{key}\'?',
    'database.query_failed'      => 'Запрос к базе данных выполнен с ошибкой.',
];
