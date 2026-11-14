<?php

/**
 * 'es' translations for sugar-readline.
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
  'confirm.no' => 'No',
  'confirm.yes' => 'Sí',
  'multiselect.hint' => '[selección múltiple] seleccionados: {count}',
  'multiselect.hint_max' => '[selección múltiple] seleccionados: {count} (máx {max})',
  'multiselect.hint_min' => '[selección múltiple] seleccionados: {count} (mín {min})',
  'multiselect.hint_min_max' => '[selección múltiple] seleccionados: {count} (mín {min}) (máx {max})',
  'selection.filter_prefix' => '[filtro] ',
  'selection.matches_one' => ' ({count} coincidencia)',
  'selection.matches_other' => ' ({count} coincidencias)',
  'selection.no_matches' => '(sin coincidencias)',
  'selection.page' => 'Página {page}/{of}',
  'text.invalid_input' => 'Entrada no válida',
  'text.search_line_forward' => '{label}(i-búsqueda)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(fallido i-búsqueda)`{query}\': ',
  'text.search_line_reverse' => '{label}(búsqueda-i-inversa)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(fallido búsqueda-i-inversa)`{query}\': ',
);
