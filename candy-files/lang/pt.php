<?php

/**
 * Portuguese (pt) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'excluir {names}? (s/n)',
    'confirm.copy' => 'copiar {names} para {dest}? (s/n)',
    'confirm.move' => 'mover {names} para {dest}? (s/n)',
    'confirm.rename' => 'renomear \'{name}\' para: ',
    'confirm.selected_entries' => '{count} entradas selecionadas',
    'status.nothing_to_delete' => 'nada para excluir',
    'status.nothing_to_copy' => 'nada para copiar',
    'status.nothing_to_move' => 'nada para mover',
    'status.nothing_to_rename' => 'nada para renomear',
    'status.cancelled' => 'cancelado',
    'status.refreshed' => 'atualizado',
    'status.copy_pending' => 'copiando {count} entradas…',
    'status.deleted' => '{count} entradas excluídas',
    'status.deleted_with_errors' => '{count} entradas excluídas com {errors} erros',
    'status.deleted_skipped' => '{count} entradas excluídas ({skipped} ignoradas)',
    'status.copied' => '{count} entradas copiadas',
    'status.copied_with_errors' => '{count} entradas copiadas com {errors} erros',
    'status.copied_skipped' => '{count} entradas copiadas ({skipped} ignoradas)',
    'status.copied_overwrote' => '{count} entradas copiadas ({overwrote} sobrescritas)',
    'status.moved' => '{count} entradas movidas',
    'status.moved_with_errors' => '{count} entradas movidas com {errors} erros',
    'status.moved_skipped' => '{count} entradas movidas ({skipped} ignoradas)',
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
