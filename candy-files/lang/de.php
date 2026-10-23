<?php

/**
 * German (de) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'lösche {names}? (j/n)',
    'confirm.copy' => 'kopiere {names} nach {dest}? (j/n)',
    'confirm.move' => 'verschiebe {names} nach {dest}? (j/n)',
    'confirm.rename' => 'benenne \'{name}\' um in: ',
    'confirm.selected_entries' => '{count} ausgewählte Einträge',
    'status.nothing_to_delete' => 'nichts zu löschen',
    'status.nothing_to_copy' => 'nichts zu kopieren',
    'status.nothing_to_move' => 'nichts zu verschieben',
    'status.nothing_to_rename' => 'nichts umzubenennen',
    'status.cancelled' => 'abgebrochen',
    'status.refreshed' => 'aktualisiert',
    'status.copy_pending' => 'kopiere {count} Einträge…',
    'status.deleted' => '{count} Einträge gelöscht',
    'status.deleted_with_errors' => '{count} Einträge gelöscht, {errors} Fehler',
    'status.deleted_skipped' => '{count} Einträge gelöscht ({skipped} übersprungen)',
    'status.copied' => '{count} Einträge kopiert',
    'status.copied_with_errors' => '{count} Einträge kopiert, {errors} Fehler',
    'status.copied_skipped' => '{count} Einträge kopiert ({skipped} übersprungen)',
    'status.copied_overwrote' => '{count} Einträge kopiert ({overwrote} überschrieben)',
    'status.moved' => '{count} Einträge verschoben',
    'status.moved_with_errors' => '{count} Einträge verschoben, {errors} Fehler',
    'status.moved_skipped' => '{count} Einträge verschoben ({skipped} übersprungen)',
    'status.renamed' => '{old} in {new} umbenannt',
    'status.rename_failed' => 'Umbenennen fehlgeschlagen: {name}',
    'status.nothing_to_undo' => 'nichts rückgängig zu machen',
    'status.undone' => 'rückgängig gemacht: {description}',
    'status.undo_with_errors' => 'Rückgängig ({description}) mit {errors} Fehler(n)',
    'status.cannot_close_last_tab' => 'Letzter Tab kann nicht geschlossen werden',
    'status.redone' => 'wiederhergestellt: {description}',
    'status.redo_with_errors' => 'Wiederholen ({description}) mit {errors} Fehler(n)',
    'status.nothing_to_redo' => 'nichts wiederherzustellen',
    'status.copy_undo_noop' => 'Kopie bleibt bestehen (nichts rückgängig zu machen)',
    'undo.delete' => 'Löschen von {count} Objekt(en)',
    'undo.rename' => 'Umbenennen von {count} Objekt(en)',
    'undo.move' => 'Verschieben von {count} Objekt(en)',
    'undo.copy' => 'Kopieren von {count} Objekt(en)',
    'keyhelp.default' => 'Tab Wechsel · ↑↓ jk bewegen · Enter öffnen · ← h hoch · Space wählen · s sortieren · . versteckte · c kopieren · m verschieben · R umbenennen · d löschen · r neu laden · q beenden · / suchen · t neuer Tab · ^w Tab schließen · ^tab wechseln',
    'search.no_match' => '(keine Treffer)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[VERZ]',
    'search.type_file' => '[DATEI]',
    'pane.hidden_suffix' => '+versteckte',
];
