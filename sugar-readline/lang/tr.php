<?php

/**
 * 'tr' translations for sugar-readline.
 *
 * Keys ride lang/en.php verbatim; the {placeholder} tokens are contract
 * (LOCALES.md named-token law — values may reorder them, never rename
 * them). '[y/n]' is key notation and stays untranslated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return array (
  'confirm.hint' => '[y/n]',
  'confirm.no' => 'Hayır',
  'confirm.yes' => 'Evet',
  'multiselect.hint' => '[çoklu seçim] seçili: {count}',
  'multiselect.hint_max' => '[çoklu seçim] seçili: {count} (en çok {max})',
  'multiselect.hint_min' => '[çoklu seçim] seçili: {count} (en az {min})',
  'multiselect.hint_min_max' => '[çoklu seçim] seçili: {count} (en az {min}) (en çok {max})',
  'selection.filter_prefix' => '[süzgeç] ',
  'selection.matches_one' => ' ({count} eşleşme)',
  'selection.matches_other' => ' ({count} eşleşme)',
  'selection.no_matches' => '(eşleşme yok)',
  'selection.page' => 'Sayfa {page}/{of}',
  'text.invalid_input' => 'Geçersiz giriş',
  'text.search_line_forward' => '{label}(i-arama)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(başarısız i-arama)`{query}\': ',
  'text.search_line_reverse' => '{label}(geri-i-arama)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(başarısız geri-i-arama)`{query}\': ',
);
