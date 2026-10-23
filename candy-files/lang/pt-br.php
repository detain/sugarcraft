<?php

/**
 * Brazilian Portuguese (pt-br) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'excluir {names}? (s/n)',
    'confirm.copy' => 'copiar {names} para {dest}? (s/n)',
    'confirm.move' => 'mover {names} para {dest}? (s/n)',
    'confirm.rename' => 'renomear \'{name}\' para: ',
    'confirm.selected_entries' => '{count} itens selecionados',
    'status.nothing_to_delete' => 'nada para excluir',
    'status.nothing_to_copy' => 'nada para copiar',
    'status.nothing_to_move' => 'nada para mover',
    'status.nothing_to_rename' => 'nada para renomear',
    'status.cancelled' => 'cancelado',
    'status.refreshed' => 'atualizado',
    'status.copy_pending' => 'copiando {count} itens…',
    'status.deleted' => '{count} itens excluídos',
    'status.deleted_with_errors' => '{count} itens excluídos com {errors} erros',
    'status.deleted_skipped' => '{count} itens excluídos ({skipped} pulados)',
    'status.copied' => '{count} itens copiados',
    'status.copied_with_errors' => '{count} itens copiados com {errors} erros',
    'status.copied_skipped' => '{count} itens copiados ({skipped} pulados)',
    'status.copied_overwrote' => '{count} itens copiados ({overwrote} sobrescritos)',
    'status.moved' => '{count} itens movidos',
    'status.moved_with_errors' => '{count} itens movidos com {errors} erros',
    'status.moved_skipped' => '{count} itens movidos ({skipped} pulados)',
    'status.renamed' => '{old} renomeado para {new}',
    'status.rename_failed' => 'falha ao renomear: {name}',
    'status.nothing_to_undo' => 'nada para desfazer',
    'status.undone' => 'desfeito: {description}',
    'status.undo_with_errors' => 'desfazer {description} com {errors} erro(s)',
    'status.cannot_close_last_tab' => 'Não é possível fechar a última aba',
    'status.redone' => 'refeito: {description}',
    'status.redo_with_errors' => 'refazer {description} com {errors} erro(s)',
    'status.nothing_to_redo' => 'nada para refazer',
    'status.copy_undo_noop' => 'cópia mantida (nada para desfazer)',
    'undo.delete' => 'excluir {count} item(ns)',
    'undo.rename' => 'renomear {count} item(ns)',
    'undo.move' => 'mover {count} item(ns)',
    'undo.copy' => 'copiar {count} item(ns)',
    'keyhelp.default' => 'Tab alternar · ↑↓ jk mover · Enter abrir · ← h subir · espaço selecionar · s ordenar · . ocultos · c copiar · m mover · R renomear · d excluir · r atualizar · q sair · / pesquisar · t nova aba · ^w fechar aba · ^tab circular',
    'search.no_match' => '(sem correspondências)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[DIR]',
    'search.type_file' => '[ARQUIVO]',
    'pane.hidden_suffix' => '+ocultos',
];
