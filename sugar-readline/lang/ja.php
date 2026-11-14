<?php

/**
 * 'ja' translations for sugar-readline.
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
  'confirm.no' => 'いいえ',
  'confirm.yes' => 'はい',
  'multiselect.hint' => '[複数選択] 選択数: {count}',
  'multiselect.hint_max' => '[複数選択] 選択数: {count} (最大 {max})',
  'multiselect.hint_min' => '[複数選択] 選択数: {count} (最小 {min})',
  'multiselect.hint_min_max' => '[複数選択] 選択数: {count} (最小 {min}) (最大 {max})',
  'selection.filter_prefix' => '[フィルター] ',
  'selection.matches_one' => ' ({count} 件の一致)',
  'selection.matches_other' => ' ({count} 件の一致)',
  'selection.no_matches' => '(一致なし)',
  'selection.page' => 'ページ {page}/{of}',
  'text.invalid_input' => '無効な入力',
  'text.search_line_forward' => '{label}(i-検索)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(失敗 i-検索)`{query}\': ',
  'text.search_line_reverse' => '{label}(逆方向-i-検索)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(失敗 逆方向-i-検索)`{query}\': ',
);
