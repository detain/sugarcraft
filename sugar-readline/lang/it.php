<?php

/**
 * 'it' translations for sugar-readline.
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
  'confirm.yes' => 'Sì',
  'history.append_lock_failed' => 'impossibile acquisire il lock di append su {path}',
  'history.changed_under_us' => 'il percorso della cronologia è cambiato sotto i nostri piedi (possibile scambio di symlink): {path}',
  'history.chmod_failed' => 'impossibile impostare 0600 sul file di cronologia {path}',
  'history.clear_temp_failed' => 'impossibile creare il file temporaneo per svuotare la cronologia in {dir}',
  'history.create_failed' => 'impossibile creare il file di cronologia {path}',
  'history.lock_acquire_failed' => 'impossibile acquisire il lock di scrittura della cronologia {path}',
  'history.lock_changed' => 'il lock della cronologia è cambiato sotto i nostri piedi (possibile scambio di symlink): {path}',
  'history.lock_open_failed' => 'impossibile aprire il lock di scrittura della cronologia {path}',
  'history.not_regular' => 'il percorso della cronologia non è un file regolare (aperto attraverso uno scambio?): {path}',
  'history.open_failed' => 'impossibile aprire il file di cronologia {path}',
  'history.replace_failed' => 'impossibile sostituire il file di cronologia {path}',
  'history.symlink_refused' => 'symlink rifiutato per {what}: {path}',
  'history.temp_create_failed' => 'impossibile creare il file temporaneo per la riscrittura della cronologia in {dir}',
  'history.temp_lock_failed' => 'impossibile bloccare il file temporaneo di riscrittura della cronologia: {path}',
  'history.temp_open_failed' => 'impossibile aprire il file temporaneo di riscrittura della cronologia: {path}',
  'multiselect.hint' => '[selezione multipla] selezionati: {count}',
  'multiselect.hint_max' => '[selezione multipla] selezionati: {count} (max {max})',
  'multiselect.hint_min' => '[selezione multipla] selezionati: {count} (min {min})',
  'multiselect.hint_min_max' => '[selezione multipla] selezionati: {count} (min {min}) (max {max})',
  'multiselect.notice_unmarked' => 'deselezionato {item} per rispettare il limite di {max}',
  'multiselect.nothing_to_submit' => 'nessuna opzione corrisponde al filtro',
  'selection.filter_prefix' => '[filtro] ',
  'selection.matches_one' => ' ({count} corrispondenza)',
  'selection.matches_other' => ' ({count} corrispondenze)',
  'selection.no_matches' => '(nessuna corrispondenza)',
  'selection.page' => 'Pagina {page}/{of}',
  'textarea.notice_max_lines' => 'impossibile aggiungere una riga: massimo {max} raggiunto',
  'text.invalid_input' => 'Input non valido',
  'text.notice_no_history' => 'la cronologia è vuota',
  'text.search_line_forward' => '{label}(i-ricerca)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(fallita i-ricerca)`{query}\': ',
  'text.search_line_reverse' => '{label}(ricerca-i-invertita)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(fallita ricerca-i-invertita)`{query}\': ',
);
