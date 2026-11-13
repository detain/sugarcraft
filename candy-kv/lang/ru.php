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
    'cli.usage_set'             => 'Использование: {bin} set <ключ> [значение] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Использование: {bin} get <ключ> [--sanitize]',
    'cli.usage_delete'          => 'Использование: {bin} delete <ключ>',
    'cli.usage_import'           => 'Использование: {bin} import <json|yaml|yml> <путь> [--no-atomic] [--if-exists=MODE] [--max-bytes=N]',
    'cli.usage_export'            => 'Использование: {bin} export <json|yaml|yml> [база] [шаблон]',
    'cli.deleted_n'             => 'Удалено {count} записей.',
    'cli.unknown_command'       => 'Неизвестная команда: {cmd}',
    'cli.deleted_none'           => 'Ничего не удалено: записи не соответствуют "{key}".',
    'cli.pattern_not_supported'  => 'get требует точный ключ, а не шаблон. Для glob-шаблонов используйте "{bin} list <pattern>".',
    'cli.db_deleted'             => 'База данных "{name}" удалена.',
    'cli.db_not_found'           => 'База данных не найдена: {db}',
    'cli.db_did_you_mean'        => 'Вы имели в виду базу данных \'{db}\'?',
    'store.did_you_mean'         => 'Ключ не найден; вы имели в виду \'{key}\'?',
    'cli.key_not_found'          => 'Ключ не найден: {key}',
    'cli.deleted_one'            => 'Удалена {count} запись.',
    'cli.imported_one'           => 'Импортирована {count} запись.',
    'cli.imported_n'             => 'Импортировано {count} записей.',
    'cli.overwrote_one'          => 'Перезаписана {count} запись.',
    'cli.overwrote_n'            => 'Перезаписано {count} записей.',
    'cli.would_delete_one'       => 'Будет удалена {count} запись.',
    'cli.would_delete_n'         => 'Будет удалено {count} записей.',
    'cli.pruned_n'               => 'Удалено {count} просроченных записей.',
    'cli.pruned_one'             => 'Удалена {count} просроченная запись.',
    'cli.pruned_none'            => 'Нет просроченных записей для удаления.',
    'cli.deleted_few'            => 'Удалено {count} записи.',
    'cli.imported_few'           => 'Импортировано {count} записи.',
    'cli.overwrote_few'          => 'Перезаписано {count} записи.',
    'cli.would_delete_few'       => 'Будет удалено {count} записи.',
    'cli.pruned_few'             => 'Удалено {count} просроченные записи.',
];
