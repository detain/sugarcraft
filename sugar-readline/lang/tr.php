<?php

/**
 * 'tr' translations for sugar-readline.
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
    'confirm.yes' => 'Evet',
    'confirm.no' => 'Hayır',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[süzgeç] ',
    'selection.match_count' => ' (%d eşleşme)',
    'selection.no_matches' => '(eşleşme yok)',
    'selection.page' => 'Sayfa %d/%d',
    'multiselect.selected' => '[çoklu seçim] seçili: %d',
    'multiselect.min' => ' (en az %d)',
    'multiselect.max' => ' (en çok %d)',
    'text.invalid_input' => 'Geçersiz giriş',
    'text.search_reverse' => 'geri-i-arama',
    'text.search_forward' => 'i-arama',
    'text.search_failed' => 'başarısız ',
];
