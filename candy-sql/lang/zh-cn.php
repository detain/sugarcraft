<?php

/**
 * Simplified Chinese translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'SQLite 文件不存在：{path}',
    'complete.in_literal' => '字符串或注释内无可补全内容',
    'complete.nothing' => '无可补全内容 — 请输入关键字的开头',
    'complete.none_keyword' => '没有可补全 "{word}" 的关键字',
    'complete.none_table' => '没有可补全 "{word}" 的表',
    'complete.none_column' => '没有可补全 "{word}" 的列',
    'complete.none_qualified' => '{qualifier} 中没有可补全 "{word}" 的列',
    'complete.list' => '"{word}" 有 {count} 个补全项：{list}{more}',
    'complete.done' => '已补全 {word} → {insert}',
    'complete.inserted' => '已插入 {insert}',
];
