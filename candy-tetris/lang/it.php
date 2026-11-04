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
    'help_card'  => '← →  muovi
↑ x  rotazione oraria
z    rotazione antior.
↓    discesa lenta
spc  discesa rapida
c    riserva
p    pausa
r    ricomincia
q    esci',
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

    // Versus renderer
    'label.player'   => 'GIOCATORE',
    'label.computer' => 'COMPUTER',
    'win.player'     => 'HAI VINTO!',
    'win.computer'   => 'VINCE IL COMPUTER!',
    'over.quit_hint' => 'premi q per uscire',
    'vs_keys'        => 'spc scendi  c riserva  p pausa  r ricomincia  q esci',

    // Launch screen
    'mode.unknown' => 'candy-tetris: modalità sconosciuta "{flag}"',
    'help_text'    => 'candy-tetris - clone di Tetris

Uso: candy-tetris [opzioni]
  (vuoto)    Avvia gioco singolo
  -v, --vs   Modalità contro il computer
  -h, --help Mostra questo aiuto
  --version  Stampa la versione
'
];
