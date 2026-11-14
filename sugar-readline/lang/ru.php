<?php

/**
 * 'ru' translations for sugar-readline.
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
    'confirm.yes' => 'Да',
    'confirm.no' => 'Нет',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[фильтр] ',
    'selection.match_count' => ' (%d совпадений)',
    'selection.no_matches' => '(нет совпадений)',
    'selection.page' => 'Стр. %d/%d',
    'multiselect.selected' => '[мультивыбор] выбрано: %d',
    'multiselect.min' => ' (мин %d)',
    'multiselect.max' => ' (макс %d)',
    'text.invalid_input' => 'Некорректный ввод',
    'text.search_reverse' => 'обратный-i-поиск',
    'text.search_forward' => 'i-поиск',
    'text.search_failed' => 'сбой ',
];
