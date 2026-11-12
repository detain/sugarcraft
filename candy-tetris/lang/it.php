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
    'score_card' => 'punti:  %d
linee:  %d
livello: %d',
    'help_card'  => 'muovi
rotazione oraria
rotazione antior.
discesa lenta
discesa rapida
riserva
pausa
riavvia (fine)
esci',
    'next_label' => 'successivo:',
    'hold_label' => 'riserva:',
    'paused'     => '[ in pausa — premi p per riprendere ]',

    // Game-over banner
    'over.title'                => 'FINE PARTITA',
    'over.final_score'          => 'punteggio finale: {points}',
    'over.restart_hint'         => 'premi r per ricominciare — premi q per uscire',
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

    // Versus renderer
    'label.player'   => 'GIOCATORE',
    'label.computer' => 'COMPUTER',
    'win.player'     => 'HAI VINTO!',
    'win.computer'   => 'VINCE IL COMPUTER!',
    'over.quit_hint' => 'premi q per uscire',
    'vs_keys'        => 'scendi
riserva
pausa
ricomincia
esci',
    'vs.record' => 'bilancio: {wins} vinte, {losses} perse',
    'intro.title' => 'benvenuto in candy-tetris',
    'intro.goal' => 'elimina le righe - non arrivare in cima',
    'intro.press' => 'premi {keys} per giocare',
    'intro.vs' => 'modalita versus: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: modalità sconosciuta "{flag}"',
    'help_text'    => 'candy-tetris - clone di Tetris

Uso: candy-tetris [opzioni]
  (vuoto)    Avvia gioco singolo
  -v, --vs   Modalità contro il computer
  -h, --help Mostra questo aiuto
  --version  Stampa la versione
',
    'cli.no_tty' => 'candy-tetris richiede un terminale interattivo: avvialo da un TTY (non da una pipe o un reindirizzamento) con TERM diverso da dumb.',
];
