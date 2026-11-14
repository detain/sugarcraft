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
  'history.append_lock_failed' => '追記ロックを取得できません: {path}',
  'history.changed_under_us' => '履歴パスが処理中に変わりました（シンボリックリンクの差し替えの可能性があります）: {path}',
  'history.chmod_failed' => '履歴ファイル {path} に 0600 を設定できません',
  'history.clear_temp_failed' => '履歴を消去するための一時ファイルを {dir} に作成できません',
  'history.create_failed' => '履歴ファイル {path} を作成できません',
  'history.lock_acquire_failed' => '履歴の書き込みロック {path} を取得できません',
  'history.lock_changed' => '履歴ロックが処理中に変わりました（シンボリックリンクの差し替えの可能性があります）: {path}',
  'history.lock_open_failed' => '履歴の書き込みロック {path} を開けません',
  'history.not_regular' => '履歴パスは通常ファイルではありません（差し替え経由で開かれた可能性）: {path}',
  'history.open_failed' => '履歴ファイル {path} を開けません',
  'history.replace_failed' => '履歴ファイル {path} を置き換えられません',
  'history.symlink_refused' => '{what} がシンボリックリンクのため拒否しました: {path}',
  'history.temp_create_failed' => '履歴書き換え用の一時ファイルを {dir} に作成できません',
  'history.temp_lock_failed' => '履歴書き換え用の一時ファイルをロックできません: {path}',
  'history.temp_open_failed' => '履歴書き換え用の一時ファイルを開けません: {path}',
  'multiselect.hint' => '[複数選択] 選択数: {count}',
  'multiselect.hint_max' => '[複数選択] 選択数: {count} (最大 {max})',
  'multiselect.hint_min' => '[複数選択] 選択数: {count} (最小 {min})',
  'multiselect.hint_min_max' => '[複数選択] 選択数: {count} (最小 {min}) (最大 {max})',
  'multiselect.notice_unmarked' => '上限 {max} に収めるため {item} の選択を解除しました',
  'selection.filter_prefix' => '[フィルター] ',
  'selection.matches_one' => ' ({count} 件の一致)',
  'selection.matches_other' => ' ({count} 件の一致)',
  'selection.no_matches' => '(一致なし)',
  'selection.page' => 'ページ {page}/{of}',
  'textarea.notice_max_lines' => '行を追加できません: 最大 {max} 行',
  'text.invalid_input' => '無効な入力',
  'text.notice_no_history' => '履歴が空です',
  'text.search_line_forward' => '{label}(i-検索)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(失敗 i-検索)`{query}\': ',
  'text.search_line_reverse' => '{label}(逆方向-i-検索)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(失敗 逆方向-i-検索)`{query}\': ',
);
