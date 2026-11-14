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
  'history.append_lock_failed' => '추가 잠금을 획득할 수 없습니다: {path}',
  'history.changed_under_us' => '히스토리 경로가 처리 중 변경되었습니다(심볼릭 링크 교체 가능성): {path}',
  'history.chmod_failed' => '히스토리 파일 {path}에 0600을 설정할 수 없습니다',
  'history.clear_temp_failed' => '히스토리를 지우기 위한 임시 파일을 {dir}에 만들 수 없습니다',
  'history.create_failed' => '히스토리 파일 {path}을(를) 만들 수 없습니다',
  'history.lock_acquire_failed' => '히스토리 쓰기 잠금을 획득할 수 없습니다: {path}',
  'history.lock_changed' => '히스토리 잠금이 처리 중 변경되었습니다(심볼릭 링크 교체 가능성): {path}',
  'history.lock_open_failed' => '히스토리 쓰기 잠금을 열 수 없습니다: {path}',
  'history.not_regular' => '히스토리 경로가 일반 파일이 아닙니다(교체를 통해 열림?): {path}',
  'history.open_failed' => '히스토리 파일 {path}을(를) 열 수 없습니다',
  'history.replace_failed' => '히스토리 파일 {path}을(를) 바꿀 수 없습니다',
  'history.symlink_refused' => '{what}이(가) 심볼릭 링크이므로 거부합니다: {path}',
  'history.temp_create_failed' => '히스토리 다시 쓰기를 위한 임시 파일을 {dir}에 만들 수 없습니다',
  'history.temp_lock_failed' => '히스토리 다시 쓰기 임시 파일을 잠글 수 없습니다: {path}',
  'history.temp_open_failed' => '히스토리 다시 쓰기 임시 파일을 열 수 없습니다: {path}',
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
