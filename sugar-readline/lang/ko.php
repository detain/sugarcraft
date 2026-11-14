<?php

/**
 * 'ko' translations for sugar-readline.
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
  'confirm.no' => '아니요',
  'confirm.yes' => '예',
  'multiselect.hint' => '[다중 선택] 선택됨: {count}',
  'multiselect.hint_max' => '[다중 선택] 선택됨: {count} (최대 {max})',
  'multiselect.hint_min' => '[다중 선택] 선택됨: {count} (최소 {min})',
  'multiselect.hint_min_max' => '[다중 선택] 선택됨: {count} (최소 {min}) (최대 {max})',
  'selection.filter_prefix' => '[필터] ',
  'selection.matches_one' => ' ({count} 개의 일치)',
  'selection.matches_other' => ' ({count} 개의 일치)',
  'selection.no_matches' => '(일치 없음)',
  'selection.page' => '페이지 {page}/{of}',
  'text.invalid_input' => '잘못된 입력',
  'text.search_line_forward' => '{label}(i-검색)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(실패 i-검색)`{query}\': ',
  'text.search_line_reverse' => '{label}(역방향-i-검색)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(실패 역방향-i-검색)`{query}\': ',
);
