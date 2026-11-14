<?php

/**
 * 'de' translations for sugar-readline.
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
    'confirm.no' => 'Nein',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[Filter] ',
    'selection.match_count' => ' (%d Treffer)',
    'selection.no_matches' => '(keine Treffer)',
    'selection.page' => 'Seite %d/%d',
    'multiselect.selected' => '[Mehrfachauswahl] ausgewählt: %d',
    'multiselect.min' => ' (min %d)',
    'multiselect.max' => ' (max %d)',
    'text.invalid_input' => 'Ungültige Eingabe',
    'text.search_reverse' => 'rückwärts-i-Suche',
    'text.search_forward' => 'i-Suche',
    'text.search_failed' => 'fehlgeschlagen ',
];
