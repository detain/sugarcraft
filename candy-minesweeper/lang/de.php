<?php

/**
 * German translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'Spielfeld zu klein',
    'board.minecount_out_of_range' => 'Minenanzahl außerhalb des gültigen Bereichs',
    'board.rows_shape_mismatch' => 'Rasterdaten passen nicht zum {width}×{height}-Spielfeld',
    'custom.min_rows' => 'Zeilen müssen mindestens 2 sein',
    'custom.min_cols' => 'Spalten müssen mindestens 2 sein',
    'custom.max_rows' => 'Zeilen dürfen höchstens 50 sein',
    'custom.max_cols' => 'Spalten dürfen höchstens 50 sein',
    'custom.min_mines' => 'mindestens 1 Mine erforderlich',
    'custom.max_mines' => 'zu viele Minen — lassen Sie einen sicheren Startbereich',
    'ui.status' => 'Minen: {mines}  ·  Flaggen: {flags}  ·  verbleibend: {remaining}  ·  Zeit: {time}',
    'ui.status_stats' => '{preset}  ·  Flaggen: {flags}  ·  verbleibend: {remaining}  ·  Zeit: {time}  ·  Bestzeit: {best}',
    'ui.preset_easy' => 'einfach',
    'ui.preset_standard' => 'standard',
    'ui.preset_medium' => 'mittel',
    'ui.preset_expert' => 'experte',
    'ui.boom' => '💥 Boom — drücken Sie r für einen Neustart',
    'ui.cleared' => '★ Geschafft — drücken Sie r für eine neue Partie',
    'ui.help_rules' => 'eine Zahl zählt die Minen daneben · Flaggen nie mehr als Minen',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  bewegen  ·  Space / Enter  aufdecken',
    'ui.help_board' => 'Linksklick  aufdecken · f / Rechtsklick  flaggen · c / Mittelklick  aufdecken',
    'ui.help_loss' => '{mark} falsche Flagge · * Mine übersehen',
    'ui.help_session' => 'r  Neustart  ·  S  speichern  ·  L  laden  ·  q / Esc / Ctrl+C  beenden',
    'ui.saved' => 'Spiel gespeichert',
    'ui.restarted' => 'Feld neu gestartet',
    'ui.loaded' => 'Spielstand geladen',
    'ui.no_save' => 'Kein Spielstand zum Laden',
    'ui.load_refused' => 'Spielstand abgelehnt (unlesbar oder manipuliert)',
    'ui.save_failed' => 'Speichern fehlgeschlagen',
    'ui.chord_not_ready' => 'Chord braucht Flaggen passend zur Zahl',
    'ui.resume' => 'Ein Spielstand wartet — mit L laden',
    'ui.new_best' => 'NEUER REKORD',
    'ui.beat_best' => '{prev} → NEUER REKORD',
    'ui.fit_notice' => 'candy-minesweeper benötigt einen Terminal mit {need} Zeilen; dieser hat {have}. Vergrößern Sie das Fenster — das Spiel setzt ein, sobald es passt. q beendet.',
];
