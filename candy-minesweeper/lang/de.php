<?php

/**
 * German translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'Spielfeld zu klein',
    'board.minecount_out_of_range' => 'mineCount außerhalb des gültigen Bereichs',
    'board.rows_shape_mismatch' => 'Zeilenstruktur passt nicht zu Breite/Höhe',
    'custom.min_rows' => 'Zeilen müssen mindestens 2 sein',
    'custom.min_cols' => 'Spalten müssen mindestens 2 sein',
    'custom.max_rows' => 'Zeilen dürfen höchstens 50 sein',
    'custom.max_cols' => 'Spalten dürfen höchstens 50 sein',
    'custom.min_mines' => 'mindestens 1 Mine erforderlich',
    'custom.max_mines' => 'zu viele Minen — lassen Sie einen sicheren Startbereich',
    'ui.status' => 'Minen: {mines}  ·  Flaggen: {flags}  ·  verbleibend: {remaining}  ·  Zeit: {time}',
    'ui.boom' => '💥 Boom — drücken Sie r für einen Neustart',
    'ui.cleared' => '★ Geschafft — drücken Sie r für eine neue Partie',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  bewegen  ·  Space / Enter  aufdecken',
    'ui.help_board' => 'f  flaggen  ·  c / Mittelklick  chord  ·  Rechtsklick  flaggen',
    'ui.help_session' => 'r  Neustart  ·  S  speichern  ·  L  laden  ·  q / Esc / Ctrl+C  beenden',
];
