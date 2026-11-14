<?php

/**
 * 'ko' translations for sugar-readline.
 *
 * Keys ride lang/en.php verbatim; %d placeholders are contract.
 * '[y/n]' is key notation and stays untranslated; the English match-suffix
 * %s leg is dropped here (extra sprintf arguments are ignored) until S-10
 * replaces the morphology with full-string plural keys.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.yes' => '예',
    'confirm.no' => '아니요',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[필터] ',
    'selection.match_count' => ' (%d개 일치)',
    'selection.no_matches' => '(일치 없음)',
    'selection.page' => '페이지 %d/%d',
    'multiselect.selected' => '[다중 선택] 선택됨: %d',
    'multiselect.min' => ' (최소 %d)',
    'multiselect.max' => ' (최대 %d)',
    'text.invalid_input' => '잘못된 입력',
    'text.search_reverse' => '역방향-i-검색',
    'text.search_forward' => 'i-검색',
    'text.search_failed' => '실패 ',
];
