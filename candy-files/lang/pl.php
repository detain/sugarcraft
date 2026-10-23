<?php

/**
 * Polish (pl) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'usunąć {names}? (t/n)',
    'confirm.copy' => 'kopiować {names} do {dest}? (t/n)',
    'confirm.move' => 'przenieść {names} do {dest}? (t/n)',
    'confirm.rename' => 'zmień nazwę \'{name}\' na: ',
    'confirm.selected_entries' => '{count} zaznaczonych wpisów',
    'status.nothing_to_delete' => 'brak wpisów do usunięcia',
    'status.nothing_to_copy' => 'brak wpisów do skopiowania',
    'status.nothing_to_move' => 'brak wpisów do przeniesienia',
    'status.nothing_to_rename' => 'brak wpisów do zmiany nazwy',
    'status.cancelled' => 'anulowano',
    'status.refreshed' => 'odświeżono',
    'status.copy_pending' => 'kopiowanie {count} wpisów…',
    'status.deleted' => 'usunięto {count} wpisów',
    'status.deleted_with_errors' => 'usunięto {count} wpisów, błędów: {errors}',
    'status.deleted_skipped' => 'usunięto {count} wpisów (pominięto {skipped})',
    'status.copied' => 'skopiowano {count} wpisów',
    'status.copied_with_errors' => 'skopiowano {count} wpisów, błędów: {errors}',
    'status.copied_skipped' => 'skopiowano {count} wpisów (pominięto {skipped})',
    'status.copied_overwrote' => 'skopiowano {count} wpisów (nadpisano {overwrote})',
    'status.moved' => 'przeniesiono {count} wpisów',
    'status.moved_with_errors' => 'przeniesiono {count} wpisów, błędów: {errors}',
    'status.moved_skipped' => 'przeniesiono {count} wpisów (pominięto {skipped})',
    'status.renamed' => 'zmieniono nazwę {old} na {new}',
    'status.rename_failed' => 'zmiana nazwy nieudana: {name}',
    'status.nothing_to_undo' => 'brak operacji do cofnięcia',
    'status.undone' => 'cofnięto: {description}',
    'status.undo_with_errors' => 'cofanie {description} z błędami: {errors}',
    'status.cannot_close_last_tab' => 'Nie można zamknąć ostatniej karty',
    'status.redone' => 'ponowiono: {description}',
    'status.redo_with_errors' => 'ponawianie {description} z błędami: {errors}',
    'status.nothing_to_redo' => 'brak operacji do ponowienia',
    'status.copy_undo_noop' => 'kopia pozostała (nie ma czego cofać)',
    'undo.delete' => 'usunięcie {count} elementów',
    'undo.rename' => 'zmiana nazwy {count} elementów',
    'undo.move' => 'przeniesienie {count} elementów',
    'undo.copy' => 'skopiowanie {count} elementów',
    'keyhelp.default' => 'Tab zamień · ↑↓ jk ruch · Enter otwórz · ← h w górę · spacja zaznacz · s sortuj · . ukryte · c kopiuj · m przenieś · R zmień nazwę · d usuń · r odśwież · q wyjdź · / szukaj · t nowa karta · ^w zamknij kartę · ^tab przełączaj',
    'search.no_match' => '(brak dopasowań)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[KAT]',
    'search.type_file' => '[PLIK]',
    'pane.hidden_suffix' => '+ukryte',
];
