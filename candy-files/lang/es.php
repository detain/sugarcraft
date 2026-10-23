<?php

/**
 * Spanish (es) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => '¿eliminar {names}? (s/n)',
    'confirm.copy' => '¿copiar {names} a {dest}? (s/n)',
    'confirm.move' => '¿mover {names} a {dest}? (s/n)',
    'confirm.rename' => 'renombrar \'{name}\' a: ',
    'confirm.selected_entries' => '{count} entradas seleccionadas',
    'status.nothing_to_delete' => 'nada que eliminar',
    'status.nothing_to_copy' => 'nada que copiar',
    'status.nothing_to_move' => 'nada que mover',
    'status.nothing_to_rename' => 'nada que renombrar',
    'status.cancelled' => 'cancelado',
    'status.refreshed' => 'actualizado',
    'status.copy_pending' => 'copiando {count} entradas…',
    'status.deleted' => '{count} entradas eliminadas',
    'status.deleted_with_errors' => '{count} entradas eliminadas con {errors} errores',
    'status.deleted_skipped' => '{count} entradas eliminadas ({skipped} omitidas)',
    'status.copied' => '{count} entradas copiadas',
    'status.copied_with_errors' => '{count} entradas copiadas con {errors} errores',
    'status.copied_skipped' => '{count} entradas copiadas ({skipped} omitidas)',
    'status.copied_overwrote' => '{count} entradas copiadas ({overwrote} sobrescritas)',
    'status.moved' => '{count} entradas movidas',
    'status.moved_with_errors' => '{count} entradas movidas con {errors} errores',
    'status.moved_skipped' => '{count} entradas movidas ({skipped} omitidas)',
    'status.renamed' => '{old} renombrado a {new}',
    'status.rename_failed' => 'error al renombrar: {name}',
    'status.nothing_to_undo' => 'nada que deshacer',
    'status.undone' => 'deshecho: {description}',
    'status.undo_with_errors' => 'deshacer {description} con {errors} error(es)',
    'status.cannot_close_last_tab' => 'No se puede cerrar la última pestaña',
    'status.redone' => 'rehecho: {description}',
    'status.redo_with_errors' => 'repetir {description} con {errors} error(es)',
    'status.nothing_to_redo' => 'nada que rehacer',
    'status.copy_undo_noop' => 'copia conservada (nada que deshacer)',
    'undo.delete' => 'eliminar {count} elemento(s)',
    'undo.rename' => 'renombrar {count} elemento(s)',
    'undo.move' => 'mover {count} elemento(s)',
    'undo.copy' => 'copiar {count} elemento(s)',
    'keyhelp.default' => 'Tab intercambiar · ↑↓ jk mover · Enter abrir · ← h subir · espacio seleccionar · s ordenar · . ocultos · c copiar · m mover · R renombrar · d eliminar · r refrescar · q salir · / buscar · t nueva pestaña · ^w cerrar pestaña · ^tab ciclar',
    'search.no_match' => '(sin coincidencias)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[DIR]',
    'search.type_file' => '[ARCHIVO]',
    'pane.hidden_suffix' => '+ocultos',
];
