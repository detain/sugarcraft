<?php

/**
 * German UI strings for candy-tetris.
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
    'score_card' => 'Punkte: %d
Zeilen: %d
Level:  %d',
    'help_card'  => 'bewegen
im Uhrzeigersinn
gegen den Uhrz.
langsam fallen
schnell fallen
Lager
Pause
Neustart (Ende)
beenden',
    'next_label' => 'nächste:',
    'hold_label' => 'Lager:',
    'paused'     => '[ Pause — p zum Fortsetzen ]',

    // Game-over banner
    'over.title'                => 'SPIEL VORBEI',
    'over.final_score'          => 'Endpunktzahl: {points}',
    'over.restart_hint'         => 'r für Neustart — q zum Beenden',
    'over.reason'               => 'Grund: {reason}',
    'over.reason.spawn_blocked' => 'kein Platz für die nächste Figur',
    'over.reason.top_out'       => 'Der Stapel hat die Decke erreicht',
    'over.reason.crushed'       => 'vom Müll zerdrückt',

    // On-frame cues
    'hold_used'  => '(verbraucht)',
    'combo_line' => 'Combo x%d',
    'b2b_line'   => 'B2B bereit',
    'over.best'    => 'Bestwert: {points}',
    'over.new_best' => 'neuer Bestwert!',

    // Versus renderer
    'label.player'   => 'SPIELER',
    'label.computer' => 'COMPUTER',
    'win.player'     => 'DU GEWINNST!',
    'win.computer'   => 'COMPUTER GEWINNT!',
    'over.quit_hint' => 'q zum Beenden',
    'vs_keys'        => 'fallen
Lager
Pause
Neustart
beenden',
    'vs.record' => 'Bilanz: {wins} gewonnen, {losses} verloren',
    'intro.title' => 'willkommen bei candy-tetris',
    'intro.goal' => 'Linien löschen - nicht oben ankommen',
    'intro.press' => 'drücke {keys} zum Spielen',
    'intro.vs' => 'Versus-Modus: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: unbekannter Modus "{flag}"',
    'help_text'    => 'candy-tetris - Tetris-Klon

Aufruf: candy-tetris [Optionen]
  (ohne)     Einzelspieler starten
  -v, --vs   Gegen den Computer
  -h, --help Diese Hilfe anzeigen
  --version  Version ausgeben
'
];
