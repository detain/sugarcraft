<?php

/**
 * Portuguese (pt) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'excluir {names}? (s/n)',
    'confirm.delete_permanent' => 'excluir {names}? PERMANENTE (s/n)',
    'confirm.copy' => 'copiar {names} para {dest}? (s/n)',
    'confirm.move' => 'mover {names} para {dest}? (s/n)',
    'confirm.rename' => 'renomear \'{name}\' para: ',
    'prompt.mkdir' => 'criar diretório: ',
    'confirm.selected_entries' => '{count} entradas selecionadas',
    'confirm.affirmative' => 's',
    'status.nothing_to_delete' => 'nada para excluir',
    'status.nothing_to_copy' => 'nada para copiar',
    'status.nothing_to_move' => 'nada para mover',
    'status.nothing_to_rename' => 'nada para renomear',
    'status.cancelled' => 'cancelado',
    'status.refreshed' => 'atualizado',
    'status.unreadable' => 'não é possível ler {dir} — a mostrar o conteúdo anterior',
    'status.copy_pending' => 'copiando {count} entradas…',
    'status.quit_armed'     => '{count} cópias ainda em curso — prima novamente em q para sair mesmo assim',
    'status.deleted' => '{count} entradas excluídas',
    'status.deleted_with_errors' => '{count} entradas excluídas com {errors} erros',
    'status.deleted_skipped' => '{count} entradas excluídas ({skipped} ignoradas)',
    'status.deleted_permanent' => '{count} entradas excluídas ({permanent} permanentes)',
    'status.copied' => '{count} entradas copiadas',
    'status.copied_with_errors' => '{count} entradas copiadas com {errors} erros',
    'status.copied_skipped' => '{count} entradas copiadas ({skipped} ignoradas)',
    'status.copied_overwrote' => '{count} entradas copiadas ({overwrote} sobrescritas)',
    'status.moved' => '{count} entradas movidas',
    'status.created' => '{count} pastas criadas',
    'status.mkdir_failed' => 'não é possível criar: {name}',
    'status.moved_with_errors' => '{count} entradas movidas com {errors} erros',
    'status.moved_skipped' => '{count} entradas movidas ({skipped} ignoradas)',
    'status.moved_overwrote'    => '{count} entradas movidas ({overwrote} sobrescritas)',
    'status.renamed' => '{old} renomeado para {new}',
    'status.renamed_overwrote'  => '{old} renomeado para {new} ({overwrote} sobrescritas)',
    'status.rename_failed' => 'falha ao renomear: {name}',
    'status.rename_invalid'    => 'Renomear recusado: nome inválido {name}',
    'status.nothing_to_undo' => 'nada para desfazer',
    'status.undone' => 'desfeito: {description}',
    'status.undo_with_errors' => 'desfazer {description} com {errors} erros',
    'status.undo_target_occupied' => 'desfazer {description} bloqueado — destinos ocupados: {occupied} (originais mantidos na reciclagem)',
    'status.cannot_close_last_tab' => 'Não é possível fechar a última aba',
    'status.redone' => 'refeito: {description}',
    'status.redo_with_errors' => 'refazer {description} com {errors} erros',
    'status.nothing_to_redo' => 'nada para refazer',
    'status.copy_undo_noop' => 'cópia mantida (nada para desfazer)',
    'status.focus' => 'foco: {side}',
    'side.left' => 'esquerda',
    'side.right' => 'direita',
    'undo.delete' => 'excluir {count} item(ns)',
    'undo.create' => 'criar {count} item(ns)',
    'undo.rename' => 'renomear {count} item(ns)',
    'undo.move' => 'mover {count} item(ns)',
    'undo.copy' => 'copiar {count} item(ns)',
    'keyhelp.default' => 'Tab alternar · ↑↓ jk mover · Home/End g/G saltar · Enter abrir · ← h subir · espaço selecionar · s ordenar · . ocultos · c copiar · m mover · R renomear · d excluir · r atualizar · q sair · / pesquisar · Esc limpar · t duplicar aba · ^w fechar aba · ^tab circular · ^PgUp/^PgDn abas · u/^z desfazer · ^y refazer',
    'keyhelp.terse' => 'Tab alternar · ↑↓ jk mover · Enter abrir · ← h subir · espaço selecionar · s ordenar · c copiar · m mover · R renomear · d excluir · q sair · / pesquisar · u/^z desfazer · ^y refazer',
    'keyhelp.minimal' => 'Enter abrir · q sair · / pesquisar',
    'search.no_match' => '(sem correspondências)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[DIR]',
    'search.type_file' => '[ARQUIVO]',
    'pane.hidden_suffix' => '+ocultos',
    'pane.unreadable' => '(ilegível)',
    'pane.more_above' => '{count} mais acima',
    'pane.more_below' => '{count} mais abaixo',
    'status.opening_editor' => 'a editar {name}…',
    'status.opening_pager' => 'a ver {name}…',
    'status.running' => 'a executar {name}…',
    'status.opened' => 'de volta de {name}',
    'status.editor_failed' => '{name} terminou com o código {code}',
    'status.no_editor' => 'nenhum editor encontrado para {name}',
    'status.no_viewer' => 'sem visualizador para {name}',
    'status.not_executable' => '{name} não é executável',

    // CF-16 (closes 025, 056, 060): rendered chrome through Lang.
    'search.prompt' => 'Pesquisar: ',
    'size.dir' => 'DIR',
    'size.link' => 'LINK',
    'sort.name' => 'Nome',
    'sort.mtime' => 'Data',
    'sort.size' => 'Tamanho',
    'cli.usage' => 'candy-files — gestor de ficheiros de dois painéis

Uso:
  candy-files [left] [right]   Abre os painéis nos diretórios dados
                               (predefinição: cwd à esquerda, HOME à direita)
  candy-files --version        Imprime a versão
  candy-files --help           Mostra esta ajuda',
    'cli.dir_missing' => 'diretório ({side}) {reason}: {dir}',
    'cli.reason_missing' => 'não existe',
    'cli.reason_unreadable' => 'não é legível',
    'status.deleted_trash_hint' => 'movido para a trash da sessão — apagado definitivamente ao sair (u restaura até lá)',
];
