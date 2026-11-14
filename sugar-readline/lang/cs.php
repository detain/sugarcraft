<?php

/**
 * 'cs' translations for sugar-readline.
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
  'confirm.no' => 'Ne',
  'confirm.yes' => 'Ano',
  'history.append_lock_failed' => 'nelze získat append zámek na {path}',
  'history.changed_under_us' => 'cesta historie se pod námi změnila (možná záměna symlinku): {path}',
  'history.chmod_failed' => 'nelze nastavit 0600 na soubor historie {path}',
  'history.clear_temp_failed' => 'nelze vytvořit dočasný soubor pro vyčištění historie v {dir}',
  'history.create_failed' => 'nelze vytvořit soubor historie {path}',
  'history.lock_acquire_failed' => 'nelze získat zámek zápisu historie {path}',
  'history.lock_changed' => 'zámek historie se pod námi změnil (možná záměna symlinku): {path}',
  'history.lock_open_failed' => 'nelze otevřít zámek zápisu historie {path}',
  'history.not_regular' => 'cesta historie není regulární soubor (otevřena přes záměnu?): {path}',
  'history.open_failed' => 'nelze otevřít soubor historie {path}',
  'history.replace_failed' => 'nelze nahradit soubor historie {path}',
  'history.symlink_refused' => 'odmítnut symlink pro {what}: {path}',
  'history.temp_create_failed' => 'nelze vytvořit dočasný soubor pro přepsání historie v {dir}',
  'history.temp_lock_failed' => 'nelze uzamknout dočasný soubor přepisu historie: {path}',
  'history.temp_open_failed' => 'nelze otevřít dočasný soubor přepisu historie: {path}',
  'multiselect.hint' => '[více položek] vybráno: {count}',
  'multiselect.hint_max' => '[více položek] vybráno: {count} (max {max})',
  'multiselect.hint_min' => '[více položek] vybráno: {count} (min {min})',
  'multiselect.hint_min_max' => '[více položek] vybráno: {count} (min {min}) (max {max})',
  'multiselect.notice_unmarked' => 'zrušena značka {item}, aby byl dodržen limit {max}',
  'multiselect.nothing_to_submit' => 'žádná volba neodpovídá filtru',
  'selection.filter_prefix' => '[filtr] ',
  'selection.matches_one' => ' ({count} shoda)',
  'selection.matches_other' => ' ({count} shod)',
  'selection.no_matches' => '(žádné shody)',
  'selection.page' => 'Strana {page}/{of}',
  'textarea.notice_max_lines' => 'nelze přidat řádek: nejvýše {max} dosaženo',
  'text.invalid_input' => 'Neplatný vstup',
  'text.notice_no_history' => 'historie je prázdná',
  'text.search_line_forward' => '{label}(i-hledání)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(neúspěšné i-hledání)`{query}\': ',
  'text.search_line_reverse' => '{label}(zpětné-i-hledání)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(neúspěšné zpětné-i-hledání)`{query}\': ',
);
