<?php

/**
 * 'zh-cn' translations for sugar-readline.
 *
 * Keys ride lang/en.php verbatim; the {placeholder} tokens are contract
 * (LOCALES.md named-token law — values may reorder them, never rename
 * them). '[y/n]' is key notation and stays untranslated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return array (
  'confirm.hint' => '[y/n]',
  'confirm.no' => '否',
  'confirm.yes' => '是',
  'history.append_lock_failed' => '无法在 {path} 上获取追加锁',
  'history.changed_under_us' => '历史路径在我们脚下被更改(可能经由符号链接替换): {path}',
  'history.chmod_failed' => '无法对历史文件 {path} 设置 0600',
  'history.clear_temp_failed' => '无法在 {dir} 中创建用于清空历史的临时文件',
  'history.create_failed' => '无法创建历史文件 {path}',
  'history.lock_acquire_failed' => '无法获取历史写锁 {path}',
  'history.lock_changed' => '历史锁在我们脚下被更改(可能经由符号链接替换): {path}',
  'history.lock_open_failed' => '无法打开历史写锁 {path}',
  'history.not_regular' => '历史路径不是常规文件(经由替换打开?): {path}',
  'history.open_failed' => '无法打开历史文件 {path}',
  'history.replace_failed' => '无法替换历史文件 {path}',
  'history.symlink_refused' => '{what} 是符号链接,已拒绝: {path}',
  'history.temp_create_failed' => '无法在 {dir} 中创建用于重写历史的临时文件',
  'history.temp_lock_failed' => '无法锁定历史重写临时文件: {path}',
  'history.temp_open_failed' => '无法打开历史重写临时文件: {path}',
  'multiselect.hint' => '[多选] 已选：{count}',
  'multiselect.hint_max' => '[多选] 已选：{count}（最多 {max}）',
  'multiselect.hint_min' => '[多选] 已选：{count}（最少 {min}）',
  'multiselect.hint_min_max' => '[多选] 已选：{count}（最少 {min}）（最多 {max}）',
  'multiselect.notice_unmarked' => '已取消标记 {item}，以遵守 {max} 项上限',
  'multiselect.nothing_to_submit' => '没有匹配当前筛选的选项',
  'selection.filter_prefix' => '[筛选] ',
  'selection.matches_one' => ' ({count} 个匹配)',
  'selection.matches_other' => ' ({count} 个匹配)',
  'selection.no_matches' => '（无匹配项）',
  'selection.page' => '第 {page}/{of} 页',
  'textarea.notice_max_lines' => '无法添加行：已达上限 {max}',
  'text.invalid_input' => '无效输入',
  'text.notice_no_history' => '历史记录为空',
  'text.search_line_forward' => '{label}(i-搜索)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(失败 i-搜索)`{query}\': ',
  'text.search_line_reverse' => '{label}(反向-i-搜索)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(失败 反向-i-搜索)`{query}\': ',
);
