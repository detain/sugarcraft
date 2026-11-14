<?php

/**
 * 'fr' translations for sugar-readline.
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
    'confirm.yes' => 'Oui',
    'confirm.no' => 'Non',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[filtre] ',
    'selection.match_count' => ' (%d correspondance)',
    'selection.no_matches' => '(aucune correspondance)',
    'selection.page' => 'Page %d/%d',
    'multiselect.selected' => '[multi-sélection] sélectionnées : %d',
    'multiselect.min' => ' (min %d)',
    'multiselect.max' => ' (max %d)',
    'text.invalid_input' => 'Saisie invalide',
    'text.search_reverse' => 'recherche-i-inverse',
    'text.search_forward' => 'i-recherche',
    'text.search_failed' => 'échec ',
];
