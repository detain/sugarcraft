<?php

/**
 * Italian (it) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'eliminare {names}? (s/n)',
    'confirm.copy' => 'copiare {names} in {dest}? (s/n)',
    'confirm.move' => 'spostare {names} in {dest}? (s/n)',
    'confirm.rename' => 'rinomina \'{name}\' in: ',
    'confirm.selected_entries' => '{count} voci selezionate',
    'status.nothing_to_delete' => 'nulla da eliminare',
    'status.nothing_to_copy' => 'nulla da copiare',
    'status.nothing_to_move' => 'nulla da spostare',
    'status.nothing_to_rename' => 'nulla da rinominare',
    'status.cancelled' => 'annullato',
    'status.refreshed' => 'aggiornato',
    'status.copy_pending' => 'copia di {count} voci…',
    'status.deleted' => '{count} voci eliminate',
    'status.deleted_with_errors' => '{count} voci eliminate, {errors} errori',
    'status.deleted_skipped' => '{count} voci eliminate ({skipped} saltate)',
    'status.copied' => '{count} voci copiate',
    'status.copied_with_errors' => '{count} voci copiate, {errors} errori',
    'status.copied_skipped' => '{count} voci copiate ({skipped} saltate)',
    'status.copied_overwrote' => '{count} voci copiate ({overwrote} sovrascritte)',
    'status.moved' => '{count} voci spostate',
    'status.moved_with_errors' => '{count} voci spostate, {errors} errori',
    'status.moved_skipped' => '{count} voci spostate ({skipped} saltate)',
    'status.renamed' => '{old} rinominato in {new}',
    'status.rename_failed' => 'rinomina fallita: {name}',
    'status.nothing_to_undo' => 'nulla da annullare',
    'status.undone' => 'annullato: {description}',
    'status.undo_with_errors' => 'annullamento di {description} con {errors} errori',
    'status.cannot_close_last_tab' => 'Impossibile chiudere l\'ultima scheda',
    'status.redone' => 'ripristinato: {description}',
    'status.redo_with_errors' => 'ripetizione di {description} con {errors} errori',
    'status.nothing_to_redo' => 'nulla da ripristinare',
    'status.copy_undo_noop' => 'copia mantenuta (nulla da annullare)',
    'undo.delete' => 'eliminazione di {count} elemento/i',
    'undo.rename' => 'rinomina di {count} elemento/i',
    'undo.move' => 'spostamento di {count} elemento/i',
    'undo.copy' => 'copia di {count} elemento/i',
    'keyhelp.default' => 'Tab scambia · ↑↓ jk muovi · Invio apri · ← h su · spazio seleziona · s ordina · . nascosti · c copia · m sposta · R rinomina · d elimina · r aggiorna · q esci · / cerca · t nuova scheda · ^w chiudi scheda · ^tab cicla',
    'search.no_match' => '(nessuna corrispondenza)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[DIR]',
    'search.type_file' => '[FILE]',
    'pane.hidden_suffix' => '+nascosti',
];
