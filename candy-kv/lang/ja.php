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
    'cli.usage_set'             => '用法：{bin} set <キー> [値] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => '用法：{bin} get <キー> [--sanitize]',
    'cli.usage_delete'          => '用法：{bin} delete <キー>',
    'cli.usage_import'           => '用法：{bin} import <json|yaml|yml> <パス> [--no-atomic] [--if-exists=MODE]',
    'cli.usage_export'            => '用法：{bin} export <json|yaml|yml> [データベース] [パターン]',
    'cli.deleted_n'             => '{count} 件のエントリを削除しました。',
    'cli.unknown_command'       => '不明なコマンド：{cmd}',
    'cli.deleted_none'           => '削除されませんでした: "{key}" に一致するエントリはありません。',
    'cli.pattern_not_supported'  => 'get には完全なキーが必要です。パターンは使用できません。パターン検索は "{bin} list <pattern>" を使用してください。',
    'cli.db_deleted'             => 'データベース "{name}" を削除しました。',
    'cli.db_not_found'           => 'データベースが見つかりません: {db}',
    'cli.db_did_you_mean'        => 'データベース「{db}」のことですか?',
    'store.did_you_mean'         => 'キーが見つかりません: \'{key}\' のことですか?',
    'database.query_failed'      => 'データベースクエリが失敗しました。',
    'cli.key_not_found'          => 'キーが見つかりません: {key}',
    'cli.deleted_one'            => '1 件を削除しました。',
    'cli.imported_one'           => '1 件をインポートしました。',
    'cli.imported_n'             => '{count} 件をインポートしました。',
    'cli.overwrote_one'          => '1 件を上書きしました。',
    'cli.overwrote_n'            => '{count} 件を上書きしました。',
    'cli.would_delete_one'       => '1 件を削除します。',
    'cli.would_delete_n'         => '{count} 件を削除します。',
    'cli.pruned_n'               => '期限切れの {count} 件を削除しました。',
    'cli.pruned_one'             => '期限切れの 1 件を削除しました。',
    'cli.pruned_none'            => '削除対象の期限切れエントリはありません。',
];
