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
  'history.append_lock_failed' => '無法在 {path} 上取得追加鎖',
  'history.changed_under_us' => '歷史路徑在我們腳下被變更(可能經由符號連結替換): {path}',
  'history.chmod_failed' => '無法對歷史檔案 {path} 設定 0600',
  'history.clear_temp_failed' => '無法在 {dir} 中建立用於清空歷史的暫存檔案',
  'history.create_failed' => '無法建立歷史檔案 {path}',
  'history.lock_acquire_failed' => '無法取得歷史寫入鎖 {path}',
  'history.lock_changed' => '歷史鎖在我們腳下被變更(可能經由符號連結替換): {path}',
  'history.lock_open_failed' => '無法開啟歷史寫入鎖 {path}',
  'history.not_regular' => '歷史路徑不是常規檔案(經由替換開啟?): {path}',
  'history.open_failed' => '無法開啟歷史檔案 {path}',
  'history.replace_failed' => '無法取代歷史檔案 {path}',
  'history.symlink_refused' => '{what} 是符號連結,已拒絕: {path}',
  'history.temp_create_failed' => '無法在 {dir} 中建立用於重寫歷史的暫存檔案',
  'history.temp_lock_failed' => '無法鎖定歷史重寫暫存檔案: {path}',
  'history.temp_open_failed' => '無法開啟歷史重寫暫存檔案: {path}',
  'multiselect.hint' => '[多選] 已選：{count}',
  'multiselect.hint_max' => '[多選] 已選：{count}（最多 {max}）',
  'multiselect.hint_min' => '[多選] 已選：{count}（最少 {min}）',
  'multiselect.hint_min_max' => '[多選] 已選：{count}（最少 {min}）（最多 {max}）',
  'multiselect.notice_unmarked' => '已取消標記 {item}，以遵守 {max} 項上限',
  'selection.filter_prefix' => '[篩選] ',
  'selection.matches_one' => ' ({count} 筆匹配)',
  'selection.matches_other' => ' ({count} 筆匹配)',
  'selection.no_matches' => '（無匹配項）',
  'selection.page' => '第 {page}/{of} 頁',
  'textarea.notice_max_lines' => '無法新增行：已達上限 {max}',
  'text.invalid_input' => '無效輸入',
  'text.notice_no_history' => '歷史記錄為空',
  'text.search_line_forward' => '{label}(i-搜尋)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(失敗 i-搜尋)`{query}\': ',
  'text.search_line_reverse' => '{label}(反向-i-搜尋)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(失敗 反向-i-搜尋)`{query}\': ',
);
