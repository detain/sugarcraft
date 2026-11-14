<?php

/**
 * 'zh-tw' translations for sugar-readline.
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
  'multiselect.hint' => '[多選] 已選：{count}',
  'multiselect.hint_max' => '[多選] 已選：{count}（最多 {max}）',
  'multiselect.hint_min' => '[多選] 已選：{count}（最少 {min}）',
  'multiselect.hint_min_max' => '[多選] 已選：{count}（最少 {min}）（最多 {max}）',
  'selection.filter_prefix' => '[篩選] ',
  'selection.matches_one' => ' ({count} 筆匹配)',
  'selection.matches_other' => ' ({count} 筆匹配)',
  'selection.no_matches' => '（無匹配項）',
  'selection.page' => '第 {page}/{of} 頁',
  'text.invalid_input' => '無效輸入',
  'text.search_line_forward' => '{label}(i-搜尋)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(失敗 i-搜尋)`{query}\': ',
  'text.search_line_reverse' => '{label}(反向-i-搜尋)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(失敗 反向-i-搜尋)`{query}\': ',
);
