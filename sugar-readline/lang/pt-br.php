<?php

/**
 * 'pt-br' translations for sugar-readline.
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
    'confirm.yes' => 'Sim',
    'confirm.no' => 'Não',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[filtro] ',
    'selection.match_count' => ' (%d correspondência)',
    'selection.no_matches' => '(sem correspondências)',
    'selection.page' => 'Página %d/%d',
    'multiselect.selected' => '[seleção múltipla] selecionados: %d',
    'multiselect.min' => ' (mín %d)',
    'multiselect.max' => ' (máx %d)',
    'text.invalid_input' => 'Entrada inválida',
    'text.search_reverse' => 'pesquisa-i-reversa',
    'text.search_forward' => 'i-pesquisa',
    'text.search_failed' => 'falhou ',
];
