<?php

/**
 * 'nl' translations for sugar-readline.
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
    'confirm.yes' => 'Ja',
    'confirm.no' => 'Nee',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[filter] ',
    'selection.match_count' => ' (%d overeenkomsten)',
    'selection.no_matches' => '(geen overeenkomsten)',
    'selection.page' => 'Pagina %d/%d',
    'multiselect.selected' => '[multi-select] geselecteerd: %d',
    'multiselect.min' => ' (min %d)',
    'multiselect.max' => ' (max %d)',
    'text.invalid_input' => 'Ongeldige invoer',
    'text.search_reverse' => 'achterwaarts-i-zoeken',
    'text.search_forward' => 'i-zoeken',
    'text.search_failed' => 'mislukt ',
];
