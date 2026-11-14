<?php

/**
 * 'fr' translations for sugar-readline.
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
  'confirm.no' => 'Non',
  'confirm.yes' => 'Oui',
  'history.append_lock_failed' => 'impossible d’acquérir le verrou d’ajout sur {path}',
  'history.changed_under_us' => 'le chemin d’historique a changé sous nos pieds (possible permutation de lien symbolique) : {path}',
  'history.chmod_failed' => 'impossible de définir 0600 sur le fichier d’historique {path}',
  'history.clear_temp_failed' => 'impossible de créer un fichier temporaire pour vider l’historique dans {dir}',
  'history.create_failed' => 'impossible de créer le fichier d’historique {path}',
  'history.lock_acquire_failed' => 'impossible d’acquérir le verrou d’écriture de l’historique {path}',
  'history.lock_changed' => 'le verrou d’historique a changé sous nos pieds (possible permutation de lien symbolique) : {path}',
  'history.lock_open_failed' => 'impossible d’ouvrir le verrou d’écriture de l’historique {path}',
  'history.not_regular' => 'le chemin d’historique n’est pas un fichier régulier (ouvert via une permutation ?) : {path}',
  'history.open_failed' => 'impossible d’ouvrir le fichier d’historique {path}',
  'history.replace_failed' => 'impossible de remplacer le fichier d’historique {path}',
  'history.symlink_refused' => '{what} est un lien symbolique, refusé : {path}',
  'history.temp_create_failed' => 'impossible de créer un fichier temporaire pour réécrire l’historique dans {dir}',
  'history.temp_lock_failed' => 'impossible de verrouiller le fichier temporaire de réécriture de l’historique : {path}',
  'history.temp_open_failed' => 'impossible d’ouvrir le fichier temporaire de réécriture de l’historique : {path}',
  'multiselect.hint' => '[multi-sélection] sélectionnées : {count}',
  'multiselect.hint_max' => '[multi-sélection] sélectionnées : {count} (max {max})',
  'multiselect.hint_min' => '[multi-sélection] sélectionnées : {count} (min {min})',
  'multiselect.hint_min_max' => '[multi-sélection] sélectionnées : {count} (min {min}) (max {max})',
  'multiselect.notice_unmarked' => '{item} décoché pour respecter la limite de {max}',
  'multiselect.nothing_to_submit' => 'aucune entrée ne correspond au filtre',
  'selection.filter_prefix' => '[filtre] ',
  'selection.matches_one' => ' ({count} correspondance)',
  'selection.matches_other' => ' ({count} correspondances)',
  'selection.no_matches' => '(aucune correspondance)',
  'selection.page' => 'Page {page}/{of}',
  'textarea.notice_max_lines' => 'ligne impossible : {max} lignes au maximum',
  'text.invalid_input' => 'Saisie invalide',
  'text.notice_no_history' => 'l\'historique est vide',
  'text.search_line_forward' => '{label}(i-recherche)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(échec i-recherche)`{query}\': ',
  'text.search_line_reverse' => '{label}(recherche-i-inverse)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(échec recherche-i-inverse)`{query}\': ',
);
