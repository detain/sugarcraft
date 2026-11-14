<?php

/**
 * 'it' translations for sugar-readline.
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
    'confirm.yes' => 'Sì',
    'confirm.no' => 'No',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[filtro] ',
    'selection.match_count' => ' (%d corrispondenza)',
    'selection.no_matches' => '(nessuna corrispondenza)',
    'selection.page' => 'Pagina %d/%d',
    'multiselect.selected' => '[selezione multipla] selezionati: %d',
    'multiselect.min' => ' (min %d)',
    'multiselect.max' => ' (max %d)',
    'text.invalid_input' => 'Input non valido',
    'text.search_reverse' => 'ricerca-i-invertita',
    'text.search_forward' => 'i-ricerca',
    'text.search_failed' => 'fallita ',
];
