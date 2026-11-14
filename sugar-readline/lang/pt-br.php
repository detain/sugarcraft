<?php

/**
 * 'pt-br' translations for sugar-readline.
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
  'confirm.no' => 'Não',
  'confirm.yes' => 'Sim',
  'multiselect.hint' => '[seleção múltipla] selecionados: {count}',
  'multiselect.hint_max' => '[seleção múltipla] selecionados: {count} (máx {max})',
  'multiselect.hint_min' => '[seleção múltipla] selecionados: {count} (mín {min})',
  'multiselect.hint_min_max' => '[seleção múltipla] selecionados: {count} (mín {min}) (máx {max})',
  'selection.filter_prefix' => '[filtro] ',
  'selection.matches_one' => ' ({count} correspondência)',
  'selection.matches_other' => ' ({count} correspondências)',
  'selection.no_matches' => '(sem correspondências)',
  'selection.page' => 'Página {page}/{of}',
  'text.invalid_input' => 'Entrada inválida',
  'text.search_line_forward' => '{label}(i-pesquisa)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(falhou i-pesquisa)`{query}\': ',
  'text.search_line_reverse' => '{label}(pesquisa-i-reversa)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(falhou pesquisa-i-reversa)`{query}\': ',
);
