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
    'score_card' => 'Punkte: {score}
Zeilen: {lines}
Level:  {level}',
    'help_card'  => 'bewegen
im Uhrzeigersinn
gegen den Uhrz.
180° drehen
langsam fallen
schnell fallen
Lager
Pause
Neustart (Ende)
beenden',
    'next_label' => 'nächste:',
    'hold_label' => 'Lager:',
    'paused'     => '[ Pause — {keys} zum Fortsetzen ]',

    // Game-over banner
    'over.title'                => 'SPIEL VORBEI',
    'over.final_score'          => 'Endpunktzahl: {points}',
    'over.restart_hint'         => '{restart} für Neustart — {quit} zum Beenden',
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
    'over.games' => 'Beendete Spiele: {games}',

    // Versus renderer
    'label.player'   => 'SPIELER',
    'label.computer' => 'COMPUTER',
    'win.player'     => 'DU GEWINNST!',
    'win.computer'   => 'COMPUTER GEWINNT!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'gelöschte Linien senden dem Gegner Reihen mit je einem Loch',
    'vs.record' => 'Bilanz: {wins} gewonnen, {losses} verloren',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => 'du',
    'vs.strip.computer' => 'Computer',
    'vs.strip.score' => 'Punkte:',
    'vs.strip.lines' => 'Reihen:',
    'vs.strip.level' => 'Stufe:',
    'intro.title' => 'willkommen bei candy-tetris',
    'intro.goal' => 'Linien löschen — nicht oben ankommen',
    'intro.press' => 'drücke {keys} zum Spielen',
    // candy-tetris-006/-095 (de): the on-ramp advertises the reachable key reference.
    'intro.keys' => 'drücke jederzeit {keys} für die Tasten',
    'intro.vs' => 'Versus-Modus: candy-tetris -v',
    'key_or' => ' oder ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: unbekannter Modus "{flag}"',
    'help_text'    => 'candy-tetris - Tetris-Klon

Aufruf: candy-tetris [Optionen]
  (ohne)     Einzelspieler starten
  -v, --vs   Gegen den Computer
  -h, --help Diese Hilfe anzeigen
  --version  Version ausgeben
',
    // candy-tetris-006/-095 (de): --help keys section header.
    'help.keys' => 'Tasten:',
    // candy-tetris-006/-095 (de): the press that closes the key reference.
    'help.close' => 'zum Schließen erneut {keys} drücken',
    // candy-tetris-006/-095 (de): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => 'der Rahmen braucht einen Terminal mit mindestens {cols}x{rows}',
    // candy-tetris-006/-095 (de): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => 'Tasten: {keys} drücken',
    'cli.no_tty' => 'candy-tetris braucht ein interaktives Terminal: Starte es in einem TTY (nicht über eine Pipe oder Umleitung) mit einem TERM ungleich dumb.',
    'launch.extras' => 'candy-tetris: nur ein Argument erlaubt, aber {count} zu viel: {list}',
    'score.save.failed' => 'candy-tetris: Punkte konnten nicht gespeichert werden ({problem})',
    'keys.ignored' => 'candy-tetris: nicht lesbare Tastaturdatei ignoriert ({problem})',
    'scores.ignored' => 'candy-tetris: nicht lesbare Punktedatei ignoriert ({problem})',
    'config.problem' => '{detail} in {path}',
    'cli.refusal' => 'candy-tetris braucht ein interaktives Terminal (ein TTY).
Es spielt ohne Farbe: NO_COLOR setzen.',
    'cli.too_small' => 'candy-tetris benötigt ein {size}-Terminal; dieses hier ist {actual}.
Ändere die Fenstergröße, das Spiel setzt genau hier fort.',
    'cli.too_small_vs' => 'candy-tetris VS benötigt ein {size}-Terminal; dieses hier ist {actual}.
Ändere die Fenstergröße, die Partie setzt genau hier fort.',
];
