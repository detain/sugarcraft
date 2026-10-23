<?php

/**
 * Italian translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'campo troppo piccolo',
    'board.minecount_out_of_range' => 'mineCount fuori dall\'intervallo',
    'board.rows_shape_mismatch' => 'la forma delle righe non corrisponde a larghezza/altezza',
    'custom.min_rows' => 'le righe devono essere almeno 2',
    'custom.min_cols' => 'le colonne devono essere almeno 2',
    'custom.max_rows' => 'le righe devono essere al massimo 50',
    'custom.max_cols' => 'le colonne devono essere al massimo 50',
    'custom.min_mines' => 'è richiesta almeno 1 mina',
    'custom.max_mines' => 'troppe mine — lascia un\'area di partenza sicura',
    'ui.status' => 'mine: {mines}  ·  bandiere: {flags}  ·  rimanenti: {remaining}  ·  tempo: {time}',
    'ui.boom' => '💥 boom — premi r per ricominciare',
    'ui.cleared' => '★ completato — premi r per giocare ancora',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  muovi  ·  spazio / invio  rivela',
    'ui.help_board' => 'f  bandiera  ·  c / clic centrale  accordo  ·  clic destro  bandiera',
    'ui.help_session' => 'r  ricomincia  ·  S  salva  ·  L  carica  ·  q / Esc / Ctrl+C  esci',
];
