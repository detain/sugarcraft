<?php

/**
 * 'ja' translations for sugar-readline.
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
    'confirm.yes' => 'はい',
    'confirm.no' => 'いいえ',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[フィルター] ',
    'selection.match_count' => ' (%d 件一致)',
    'selection.no_matches' => '(一致なし)',
    'selection.page' => 'ページ %d/%d',
    'multiselect.selected' => '[複数選択] 選択数: %d',
    'multiselect.min' => ' (最小 %d)',
    'multiselect.max' => ' (最大 %d)',
    'text.invalid_input' => '無効な入力',
    'text.search_reverse' => '逆方向-i-検索',
    'text.search_forward' => 'i-検索',
    'text.search_failed' => '失敗 ',
];
