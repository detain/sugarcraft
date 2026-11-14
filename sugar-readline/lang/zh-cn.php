<?php

/**
 * 'zh-cn' translations for sugar-readline.
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
    'selection.filter_prefix' => '[筛选] ',
    'selection.match_count' => '（%d 个匹配）',
    'selection.no_matches' => '（无匹配项）',
    'selection.page' => '第 %d/%d 页',
    'multiselect.selected' => '[多选] 已选：%d',
    'multiselect.min' => '（最少 %d）',
    'multiselect.max' => '（最多 %d）',
    'text.invalid_input' => '无效输入',
    'text.search_reverse' => '反向-i-搜索',
    'text.search_forward' => 'i-搜索',
    'text.search_failed' => '失败 ',
];
