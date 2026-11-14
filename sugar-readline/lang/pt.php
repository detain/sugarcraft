<?php

/**
 * 'pt' translations for sugar-readline.
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
  'history.append_lock_failed' => 'não foi possível adquirir a trava de acréscimo em {path}',
  'history.changed_under_us' => 'o caminho do histórico mudou sob nossos pés (possível troca de symlink): {path}',
  'history.chmod_failed' => 'não foi possível definir 0600 no arquivo de histórico {path}',
  'history.clear_temp_failed' => 'não foi possível criar arquivo temporário para limpar o histórico em {dir}',
  'history.create_failed' => 'não foi possível criar o arquivo de histórico {path}',
  'history.lock_acquire_failed' => 'não foi possível adquirir a trava de escrita do histórico {path}',
  'history.lock_changed' => 'a trava do histórico mudou sob nossos pés (possível troca de symlink): {path}',
  'history.lock_open_failed' => 'não foi possível abrir a trava de escrita do histórico {path}',
  'history.not_regular' => 'o caminho do histórico não é um arquivo regular (aberto através de uma troca?): {path}',
  'history.open_failed' => 'não foi possível abrir o arquivo de histórico {path}',
  'history.replace_failed' => 'não foi possível substituir o arquivo de histórico {path}',
  'history.symlink_refused' => 'rejeitando {what} que é um symlink: {path}',
  'history.temp_create_failed' => 'não foi possível criar o arquivo temporário para reescrever o histórico em {dir}',
  'history.temp_lock_failed' => 'não foi possível travar o arquivo temporário de reescrita do histórico: {path}',
  'history.temp_open_failed' => 'não foi possível abrir o arquivo temporário de reescrita do histórico: {path}',
  'multiselect.hint' => '[seleção múltipla] selecionados: {count}',
  'multiselect.hint_max' => '[seleção múltipla] selecionados: {count} (máx {max})',
  'multiselect.hint_min' => '[seleção múltipla] selecionados: {count} (mín {min})',
  'multiselect.hint_min_max' => '[seleção múltipla] selecionados: {count} (mín {min}) (máx {max})',
  'selection.filter_prefix' => '[filtro] ',
  'selection.matches_one' => ' ({count} coincidencia)',
  'selection.matches_other' => ' ({count} coincidencias)',
  'selection.no_matches' => '(sem correspondências)',
  'selection.page' => 'Página {page}/{of}',
  'text.invalid_input' => 'Entrada inválida',
  'text.search_line_forward' => '{label}(i-pesquisa)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(falhado i-pesquisa)`{query}\': ',
  'text.search_line_reverse' => '{label}(pesquisa-i-reversa)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(falhado pesquisa-i-reversa)`{query}\': ',
);
