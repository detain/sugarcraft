<?php

/**
 * Simplified Chinese translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => '条目已写入但不再可读（已过期、已删除或已损坏）',
    'store.cannot_read'         => '无法读取文件：{path}',
    'cli.usage_set'             => '用法：{bin} set <键> [值] [--ttl SECONDS]',
    'cli.usage_get'             => '用法：{bin} get <键>',
    'cli.usage_delete'          => '用法：{bin} delete <键>',
    'cli.usage_import'           => '用法：{bin} import <json|yaml|yml> <路径>',
    'cli.usage_export'            => '用法：{bin} export <json|yaml|yml> [数据库] [模式]',
    'cli.deleted_n'             => '已删除 {count} 个条目。',
    'cli.unknown_command'       => '未知命令：{cmd}',
    'cli.deleted_none'           => '未删除任何内容：没有条目匹配 "{key}"。',
    'cli.pattern_not_supported'  => 'get 需要精确的键，而不是模式。使用 "{bin} list <pattern>" 来匹配通配符模式。',
    'store.did_you_mean'         => '未找到键；您是想要 \'{key}\' 吗？',
    'database.query_failed'      => '数据库查询失败。',
];
