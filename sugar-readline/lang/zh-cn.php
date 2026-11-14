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
  'multiselect.hint' => '[多选] 已选：{count}',
  'multiselect.hint_max' => '[多选] 已选：{count}（最多 {max}）',
  'multiselect.hint_min' => '[多选] 已选：{count}（最少 {min}）',
  'multiselect.hint_min_max' => '[多选] 已选：{count}（最少 {min}）（最多 {max}）',
  'selection.filter_prefix' => '[筛选] ',
  'selection.matches_one' => ' ({count} 个匹配)',
  'selection.matches_other' => ' ({count} 个匹配)',
  'selection.no_matches' => '（无匹配项）',
  'selection.page' => '第 {page}/{of} 页',
  'text.invalid_input' => '无效输入',
  'text.search_line_forward' => '{label}(i-搜索)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(失败 i-搜索)`{query}\': ',
  'text.search_line_reverse' => '{label}(反向-i-搜索)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(失败 反向-i-搜索)`{query}\': ',
);
