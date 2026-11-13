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
    'edit.refuse_readonly' => '只读会话 — 编辑已禁用',
    'edit.refuse_no_table' => '请先打开表预览 — 查询结果无法就地编辑',
    'edit.refuse_no_rows' => '光标下没有行',
    'edit.refuse_no_pk' => '该表没有主键 — 无法安全定位行；请在查询窗格中编写语句',
    'edit.refuse_pk_hidden' => '该行主键为 NULL 或二进制占位符 — 无法定位',
    'edit.refuse_binary' => '二进制或大型单元格无法在此编辑 — 请在查询窗格中使用语句',
    'edit.refuse_schema' => '无法读取 {table} 的结构 — 已拒绝编辑',
    'edit.prompt_cell' => '编辑 {table}.{column} = {value}  (回车预览 · Ctrl+N 设为 NULL · Esc 取消)',
    'edit.prompt_insert' => '{table} 新行 — {column} ({type}) [{n}/{total}] = {value}  (回车下一项 · 留空 = DEFAULT · Ctrl+N 设为 NULL · Esc 取消)',
    'edit.prompt_confirm' => '{sql}  (回车提交 · Esc 取消)',
    'edit.cancelled' => '已取消编辑',
    'edit.unchanged' => '值未改变 — 未写入任何内容',
    'edit.done_update' => '已更新 {table} 中的 1 行',
    'edit.done_insert' => '已向 {table} 插入 1 行',
    'edit.done_delete' => '已从 {table} 删除 1 行',
    'edit.none_changed' => '没有行被更改 — 值已相同或该行已不存在；预览已刷新',
    'edit.failed' => '写入失败 — 已回滚，未做任何更改：{reason}',
    'edit.too_many' => '已拒绝：该语句将影响 {count} 行 — 已回滚，未做任何更改',
];
