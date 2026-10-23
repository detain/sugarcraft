<?php

/**
 * Japanese translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'エントリは書き込まれましたが、読み取れなくなりました（期限切れ、削除、または破損）',
    'store.cannot_read'         => 'ファイルを読み取れません：{path}',
    'cli.usage_set'             => '用法：{bin} set <キー> [値] [--ttl SECONDS]',
    'cli.usage_get'             => '用法：{bin} get <キー>',
    'cli.usage_delete'          => '用法：{bin} delete <キー>',
    'cli.usage_import'           => '用法：{bin} import <json|yaml|yml> <パス>',
    'cli.usage_export'            => '用法：{bin} export <json|yaml|yml> [データベース] [パターン]',
    'cli.deleted_n'             => '{count} 件のエントリを削除しました。',
    'cli.unknown_command'       => '不明なコマンド：{cmd}',
    'cli.deleted_none'           => '削除されませんでした: "{key}" に一致するエントリはありません。',
    'cli.pattern_not_supported'  => 'get には完全なキーが必要です。パターンは使用できません。パターン検索は "{bin} list <pattern>" を使用してください。',
    'store.did_you_mean'         => 'キーが見つかりません: \'{key}\' のことですか?',
    'database.query_failed'      => 'データベースクエリが失敗しました。',
];
