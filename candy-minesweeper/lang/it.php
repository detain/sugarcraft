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
    'custom.min_area'            => 'una griglia personalizzata richiede almeno 10 celle (righe × colonne)',
    'custom.label' => '{width}×{height} · {mines} mine',
    'ui.status' => 'mine: {mines}  ·  bandiere: {flags}  ·  rimanenti: {remaining}  ·  tempo: {time}',
    'ui.status_stats' => '{preset}  ·  bandiere: {flags}  ·  rimanenti: {remaining}  ·  tempo: {time}  ·  record: {best}',
    'ui.preset_easy' => 'facile',
    'ui.preset_standard' => 'standard',
    'ui.preset_medium' => 'medio',
    'ui.preset_expert' => 'esperto',
    'ui.boom' => '💥 boom — premi r per ricominciare · n per un altro campo',
    'ui.cleared' => '★ completato — premi r per giocare ancora · n per un altro campo',
    'ui.help_rules' => 'un numero = mine vicine · segnala le sospette · apri tutto per vincere',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  muovi  ·  spazio / invio  rivela',
    'ui.help_board' => 'clic sinistro  apri · f / clic destro  bandiera · c / clic centrale  intorno',
    'ui.help_loss' => '{mark} bandiera errata · * mina persa',
    'ui.help_session' => 'r  ricomincia  ·  S  salva  ·  L  carica  ·  q / Esc / Ctrl+C  esci',
    'ui.saved' => 'Partita salvata',
    'ui.restarted' => 'Griglia riavviata',
    'ui.loaded' => 'Partita caricata',
    'ui.no_save' => 'Nessuna partita da caricare',
    'ui.load_refused' => 'Partita rifiutata (illeggibile o manomessa)',
    'ui.save_failed' => 'Salvataggio non riuscito',
    'ui.chord_not_ready' => 'il chord richiede bandiere pari al numero',
    'ui.confirm_quit' => 'premi di nuovo q per uscire — la partita non è salvata',
    'ui.resume' => 'Una partita ti aspetta — premi L per caricarla',
    'ui.new_best' => 'NUOVO RECORD',
    'ui.beat_best' => '{prev} → NUOVO RECORD',
    'ui.picker_title' => 'scegli un campo',
    'ui.picker_custom' => 'personalizzato',
    'ui.picker_hint' => '1-4 inizia · c campo personalizzato · q / Esc indietro',
    'ui.form_width' => 'larghezza',
    'ui.form_height' => 'altezza',
    'ui.form_mines' => 'mine',
    'ui.form_hint' => 'digita cifre · invio al prossimo · invio su mine inizia · esc indietro',
    'ui.fit_notice' => 'candy-minesweeper richiede un terminale alto {need} righe; questo ne ha {have}. Ingrandisci la finestra: il gioco riprende appena lo spazio basta. q per uscire.',
];
