<?php

/**
 * Simplified Chinese (zh-cn) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => '删除 {names}？(y/n)',
    'confirm.copy' => '复制 {names} 到 {dest}？(y/n)',
    'confirm.move' => '移动 {names} 到 {dest}？(y/n)',
    'confirm.rename' => '将 \'{name}\' 重命名为：',
    'confirm.selected_entries' => '已选择 {count} 个条目',
    'status.nothing_to_delete' => '没有可删除的内容',
    'status.nothing_to_copy' => '没有可复制的内容',
    'status.nothing_to_move' => '没有可移动的内容',
    'status.nothing_to_rename' => '没有可重命名的内容',
    'status.cancelled' => '已取消',
    'status.refreshed' => '已刷新',
    'status.copy_pending' => '正在复制 {count} 个条目…',
    'status.deleted' => '已删除 {count} 个条目',
    'status.deleted_with_errors' => '已删除 {count} 个条目，{errors} 个错误',
    'status.deleted_skipped' => '已删除 {count} 个条目（跳过 {skipped} 个）',
    'status.copied' => '已复制 {count} 个条目',
    'status.copied_with_errors' => '已复制 {count} 个条目，{errors} 个错误',
    'status.copied_skipped' => '已复制 {count} 个条目（跳过 {skipped} 个）',
    'status.copied_overwrote' => '已复制 {count} 个条目（覆盖 {overwrote} 个）',
    'status.moved' => '已移动 {count} 个条目',
    'status.moved_with_errors' => '已移动 {count} 个条目，{errors} 个错误',
    'status.moved_skipped' => '已移动 {count} 个条目（跳过 {skipped} 个）',
    'status.renamed' => '已将 {old} 重命名为 {new}',
    'status.rename_failed' => '重命名失败：{name}',
    'status.nothing_to_undo' => '没有可撤销的操作',
    'status.undone' => '已撤销：{description}',
    'status.undo_with_errors' => '撤销 {description} 时发生 {errors} 个错误',
    'status.cannot_close_last_tab' => '无法关闭最后一个标签页',
    'status.redone' => '已重做：{description}',
    'status.redo_with_errors' => '重做 {description} 时发生 {errors} 个错误',
    'status.nothing_to_redo' => '没有可重做的操作',
    'status.copy_undo_noop' => '副本已保留（无需撤销）',
    'undo.delete' => '删除 {count} 个项目',
    'undo.rename' => '重命名 {count} 个项目',
    'undo.move' => '移动 {count} 个项目',
    'undo.copy' => '复制 {count} 个项目',
    'keyhelp.default' => 'Tab 切换 · ↑↓ jk 移动 · Enter 打开 · ← h 上级 · Space 选择 · s 排序 · . 隐藏 · c 复制 · m 移动 · R 重命名 · d 删除 · r 刷新 · q 退出 · / 搜索 · t 新标签页 · ^w 关闭标签页 · ^tab 循环切换',
    'search.no_match' => '(无匹配项)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[目录]',
    'search.type_file' => '[文件]',
    'pane.hidden_suffix' => '+隐藏',
];
