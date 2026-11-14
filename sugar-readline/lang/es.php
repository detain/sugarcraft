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
  'history.append_lock_failed' => 'no se pudo adquirir el bloqueo de adición en {path}',
  'history.changed_under_us' => 'la ruta del historial cambió bajo nuestras narices (posible intercambio de symlink): {path}',
  'history.chmod_failed' => 'no se pudo establecer 0600 en el archivo de historial {path}',
  'history.clear_temp_failed' => 'no se pudo crear el archivo temporal para vaciar el historial en {dir}',
  'history.create_failed' => 'no se pudo crear el archivo de historial {path}',
  'history.lock_acquire_failed' => 'no se pudo adquirir el bloqueo de escritura del historial {path}',
  'history.lock_changed' => 'el bloqueo del historial cambió bajo nuestras narices (posible intercambio de symlink): {path}',
  'history.lock_open_failed' => 'no se pudo abrir el bloqueo de escritura del historial {path}',
  'history.not_regular' => 'la ruta del historial no es un archivo regular (¿abierta a través de un intercambio?): {path}',
  'history.open_failed' => 'no se pudo abrir el archivo de historial {path}',
  'history.replace_failed' => 'no se pudo reemplazar el archivo de historial {path}',
  'history.symlink_refused' => 'se rechaza {what} que es un symlink: {path}',
  'history.temp_create_failed' => 'no se pudo crear el archivo temporal para reescribir el historial en {dir}',
  'history.temp_lock_failed' => 'no se pudo bloquear el archivo temporal de reescritura del historial: {path}',
  'history.temp_open_failed' => 'no se pudo abrir el archivo temporal de reescritura del historial: {path}',
  'multiselect.hint' => '[selección múltiple] seleccionados: {count}',
  'multiselect.hint_max' => '[selección múltiple] seleccionados: {count} (máx {max})',
  'multiselect.hint_min' => '[selección múltiple] seleccionados: {count} (mín {min})',
  'multiselect.hint_min_max' => '[selección múltiple] seleccionados: {count} (mín {min}) (máx {max})',
  'multiselect.notice_unmarked' => 'desmarcado {item} para respetar el límite de {max}',
  'selection.filter_prefix' => '[filtro] ',
  'selection.matches_one' => ' ({count} coincidencia)',
  'selection.matches_other' => ' ({count} coincidencias)',
  'selection.no_matches' => '(sin coincidencias)',
  'selection.page' => 'Página {page}/{of}',
  'textarea.notice_max_lines' => 'no se puede añadir una línea: máximo {max} alcanzado',
  'text.invalid_input' => 'Entrada no válida',
  'text.notice_no_history' => 'el historial está vacío',
  'text.search_line_forward' => '{label}(i-búsqueda)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(fallido i-búsqueda)`{query}\': ',
  'text.search_line_reverse' => '{label}(búsqueda-i-inversa)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(fallido búsqueda-i-inversa)`{query}\': ',
);
