<?php

/**
 * 'es' translations for sugar-readline.
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
    'confirm.yes' => 'Sí',
    'confirm.no' => 'No',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[filtro] ',
    'selection.match_count' => ' (%d coincidencia)',
    'selection.no_matches' => '(sin coincidencias)',
    'selection.page' => 'Página %d/%d',
    'multiselect.selected' => '[selección múltiple] seleccionados: %d',
    'multiselect.min' => ' (mín %d)',
    'multiselect.max' => ' (máx %d)',
    'text.invalid_input' => 'Entrada no válida',
    'text.search_reverse' => 'búsqueda-i-inversa',
    'text.search_forward' => 'i-búsqueda',
    'text.search_failed' => 'fallido ',
];
