<?php

/**
 * Italian translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'campo troppo piccolo',
    'board.minecount_out_of_range' => 'numero di mine fuori dall\'intervallo',
    'board.rows_shape_mismatch' => 'i dati della griglia non corrispondono al campo {width}×{height}',
    'custom.min_rows' => 'le righe devono essere almeno 2',
    'custom.min_cols' => 'le colonne devono essere almeno 2',
    'custom.max_rows' => 'le righe devono essere al massimo 50',
    'custom.max_cols' => 'le colonne devono essere al massimo 50',
    'custom.min_mines' => 'è richiesta almeno 1 mina',
    'custom.max_mines' => 'troppe mine — lascia un\'area di partenza sicura',
    'ui.status' => 'mine: {mines}  ·  bandiere: {flags}  ·  rimanenti: {remaining}  ·  tempo: {time}',
    'ui.boom' => '💥 boom — premi r per ricominciare',
    'ui.cleared' => '★ completato — premi r per giocare ancora',
    'ui.help_rules' => 'un numero conta le mine vicine',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  muovi  ·  spazio / invio  rivela',
    'ui.help_board' => 'clic sinistro  apri · f / clic destro  bandiera · c / clic centrale  intorno',
    'ui.help_session' => 'r  ricomincia  ·  S  salva  ·  L  carica  ·  q / Esc / Ctrl+C  esci',
    'ui.saved' => 'Partita salvata',
    'ui.restarted' => 'Griglia riavviata',
    'ui.loaded' => 'Partita caricata',
    'ui.no_save' => 'Nessuna partita da caricare',
    'ui.load_refused' => 'Partita rifiutata (illeggibile o manomessa)',
    'ui.save_failed' => 'Salvataggio non riuscito',
    'ui.chord_not_ready' => 'il chord richiede bandiere pari al numero',
    'ui.resume' => 'Una partita ti aspetta — premi L per caricarla',
    'ui.new_best' => 'NUOVO RECORD',
    'ui.fit_notice' => 'candy-minesweeper richiede un terminale alto {need} righe; questo ne ha {have}. Ingrandisci la finestra: il gioco riprende appena lo spazio basta. q per uscire.',
];
