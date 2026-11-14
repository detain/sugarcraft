<?php

/**
 * 'cs' translations for sugar-readline.
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
    'confirm.yes' => 'Ano',
    'confirm.no' => 'Ne',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[filtr] ',
    'selection.match_count' => ' (%d shod)',
    'selection.no_matches' => '(žádné shody)',
    'selection.page' => 'Strana %d/%d',
    'multiselect.selected' => '[více položek] vybráno: %d',
    'multiselect.min' => ' (min %d)',
    'multiselect.max' => ' (max %d)',
    'text.invalid_input' => 'Neplatný vstup',
    'text.search_reverse' => 'zpětné-i-hledání',
    'text.search_forward' => 'i-hledání',
    'text.search_failed' => 'neúspěšné ',
];
