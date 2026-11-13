<?php

/**
 * Italian UI strings for candy-tetris.
 *
 * Keys, key order and every {placeholder} / sprintf token mirror
 * lang/en.php, which stays the source of truth. Flag spellings in the
 * usage screen are kept verbatim, so a refusal is never half-translated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card' => 'punti:  {score}
linee:  {lines}
livello: {level}',
    'help_card'  => 'muovi
rotazione oraria
rotazione antior.
ruota 180
discesa lenta
discesa rapida
riserva
pausa
riavvia (fine)
esci',
    'next_label' => 'successivo:',
    'hold_label' => 'riserva:',
    'paused'     => '[ in pausa — premi {keys} per riprendere ]',

    // Game-over banner
    'over.title'                => 'FINE PARTITA',
    'over.final_score'          => 'punteggio finale: {points}',
    'over.restart_hint'         => 'premi {restart} per ricominciare — premi {quit} per uscire',
    'over.reason'               => 'motivo: {reason}',
    'over.reason.spawn_blocked' => 'nessuno spazio per il pezzo successivo',
    'over.reason.top_out'       => 'la pila ha raggiunto il tetto',
    'over.reason.crushed'       => 'schiacciato dalla spazzatura',

    // On-frame cues
    'hold_used'  => '(usato)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B pronto',
    'over.best'    => 'migliore: {points}',
    'over.new_best' => 'nuovo record!',
    'over.games' => 'partite completate: {games}',

    // Versus renderer
    'label.player'   => 'GIOCATORE',
    'label.computer' => 'COMPUTER',
    'win.player'     => 'HAI VINTO!',
    'win.computer'   => 'VINCE IL COMPUTER!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'le righe eliminate inviano righe di rifiuti all\'avversario, ognuna con un buco',
    'vs.record' => 'bilancio: {wins} vinte, {losses} perse',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => 'tu',
    'vs.strip.computer' => 'computer',
    'vs.strip.score' => 'punteggio:',
    'vs.strip.lines' => 'righe:',
    'vs.strip.level' => 'livello:',
    'intro.title' => 'benvenuto in candy-tetris',
    'intro.goal' => 'elimina le righe — non arrivare in cima',
    'intro.press' => 'premi {keys} per giocare',
    // candy-tetris-006/-095 (it): the on-ramp advertises the reachable key reference.
    'intro.keys' => 'premi {keys} in qualsiasi momento per i tasti',
    'intro.vs' => 'modalità versus: candy-tetris -v',
    'key_or' => ' o ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: modalità sconosciuta "{flag}"',
    'help_text'    => 'candy-tetris - clone di Tetris

Uso: candy-tetris [opzioni]
  (vuoto)    Avvia gioco singolo
  -v, --vs   Modalità contro il computer
  -h, --help Mostra questo aiuto
  --version  Stampa la versione
',
    // candy-tetris-006/-095 (it): --help keys section header.
    'help.keys' => 'tasti:',
    // candy-tetris-006/-095 (it): the press that closes the key reference.
    'help.close' => 'premi {keys} di nuovo per chiudere',
    // candy-tetris-006/-095 (it): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => 'la cornice richiede un terminale di almeno {cols}x{rows}',
    // candy-tetris-006/-095 (it): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => 'tasti: {keys}',
    'cli.no_tty' => 'candy-tetris richiede un terminale interattivo: avvialo da un TTY (non da una pipe o un reindirizzamento) con TERM diverso da dumb.',
    'launch.extras' => 'candy-tetris: è accettato un solo argomento, ma {count} di troppo: {list}',
    'score.save.failed' => 'candy-tetris: impossibile salvare i punteggi ({problem})',
    'keys.ignored' => 'candy-tetris: file dei tasti illeggibile ignorato ({problem})',
    'scores.ignored' => 'candy-tetris: file dei punteggi illeggibile ignorato ({problem})',
    'config.problem' => '{detail} in {path}',
    'cli.refusal' => 'candy-tetris richiede un terminale interattivo (un TTY).
Funziona senza colori: imposta NO_COLOR.',
    'cli.too_small' => 'candy-tetris richiede un terminale {size}; questo è {actual}.
Ridimensiona la finestra e la partita riprende da qui.',
    'cli.too_small_vs' => 'candy-tetris VS richiede un terminale {size}; questo è {actual}.
Ridimensiona la finestra e la partita riprende da qui.',
];
