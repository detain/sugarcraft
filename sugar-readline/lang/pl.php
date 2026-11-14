<?php

/**
 * 'pl' translations for sugar-readline.
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
    'confirm.yes' => 'Tak',
    'confirm.no' => 'Nie',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[filtr] ',
    'selection.match_count' => ' (%d dopasowań)',
    'selection.no_matches' => '(brak dopasowań)',
    'selection.page' => 'Strona %d/%d',
    'multiselect.selected' => '[wybór wielokrotny] zaznaczono: %d',
    'multiselect.min' => ' (min %d)',
    'multiselect.max' => ' (max %d)',
    'text.invalid_input' => 'Nieprawidłowe dane wejściowe',
    'text.search_reverse' => 'wyszukiwanie-i-wsteczne',
    'text.search_forward' => 'i-wyszukiwanie',
    'text.search_failed' => 'nieudane ',
];
