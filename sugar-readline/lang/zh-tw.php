<?php

/**
 * 'zh-tw' translations for sugar-readline.
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
    'confirm.yes' => '是',
    'confirm.no' => '否',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[篩選] ',
    'selection.match_count' => '（%d 個匹配）',
    'selection.no_matches' => '（無匹配項）',
    'selection.page' => '第 %d/%d 頁',
    'multiselect.selected' => '[多選] 已選：%d',
    'multiselect.min' => '（最少 %d）',
    'multiselect.max' => '（最多 %d）',
    'text.invalid_input' => '無效輸入',
    'text.search_reverse' => '反向-i-搜尋',
    'text.search_forward' => 'i-搜尋',
    'text.search_failed' => '失敗 ',
];
