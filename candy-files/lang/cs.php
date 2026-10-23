<?php

/**
 * Czech (cs) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'smazat {names}? (a/n)',
    'confirm.copy' => 'kopírovat {names} do {dest}? (a/n)',
    'confirm.move' => 'přesunout {names} do {dest}? (a/n)',
    'confirm.rename' => 'přejmenovat \'{name}\' na: ',
    'confirm.selected_entries' => '{count} vybraných položek',
    'status.nothing_to_delete' => 'nic ke smazání',
    'status.nothing_to_copy' => 'nic ke kopírování',
    'status.nothing_to_move' => 'nic k přesunu',
    'status.nothing_to_rename' => 'nic k přejmenování',
    'status.cancelled' => 'zrušeno',
    'status.refreshed' => 'obnoveno',
    'status.copy_pending' => 'kopírování {count} položek…',
    'status.deleted' => 'smazáno {count} položek',
    'status.deleted_with_errors' => 'smazáno {count} položek, chyb {errors}',
    'status.deleted_skipped' => 'smazáno {count} položek ({skipped} přeskočeno)',
    'status.copied' => 'zkopírováno {count} položek',
    'status.copied_with_errors' => 'zkopírováno {count} položek, chyb {errors}',
    'status.copied_skipped' => 'zkopírováno {count} položek ({skipped} přeskočeno)',
    'status.copied_overwrote' => 'zkopírováno {count} položek ({overwrote} přepsáno)',
    'status.moved' => 'přesunuto {count} položek',
    'status.moved_with_errors' => 'přesunuto {count} položek, chyb {errors}',
    'status.moved_skipped' => 'přesunuto {count} položek ({skipped} přeskočeno)',
    'status.renamed' => 'přejmenováno {old} na {new}',
    'status.rename_failed' => 'přejmenování selhalo: {name}',
    'status.nothing_to_undo' => 'nic ke vrácení',
    'status.undone' => 'vráceno: {description}',
    'status.undo_with_errors' => 'vrácení {description} s chybami {errors}',
    'status.cannot_close_last_tab' => 'Poslední kartu nelze zavřít',
    'status.redone' => 'obnoveno: {description}',
    'status.redo_with_errors' => 'opakování {description} s chybami {errors}',
    'status.nothing_to_redo' => 'nic k obnovení',
    'status.copy_undo_noop' => 'kopie ponechána (nic ke vrácení)',
    'undo.delete' => 'smazat {count} položek',
    'undo.rename' => 'přejmenovat {count} položek',
    'undo.move' => 'přesunout {count} položek',
    'undo.copy' => 'kopírovat {count} položek',
    'keyhelp.default' => 'Tab prohodit · ↑↓ jk pohyb · Enter otevřít · ← h nahoru · mezerník vybrat · s řadit · . skryté · c kopírovat · m přesunout · R přejmenovat · d smazat · r obnovit · q quitter · / hledat · t nová karta · ^w zavřít kartu · ^tab přepínat',
    'search.no_match' => '(žádné shody)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[ADRES]',
    'search.type_file' => '[SOUBOR]',
    'pane.hidden_suffix' => '+skryté',
];
