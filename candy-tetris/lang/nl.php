<?php

/**
 * Dutch UI strings for candy-tetris.
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
    'score_card' => 'score:  {score}
lijnen: {lines}
niveau: {level}',
    'help_card'  => 'verplaats
met klok mee
tegen klok in
180° draaien
langzaam laten
snel laten
opslag
pauze
herstart (end)
afsluiten',
    'next_label' => 'volgende:',
    'hold_label' => 'opslag:',
    'paused'     => '[ gepauzeerd — druk {keys} om verder te gaan ]',

    // Game-over banner
    'over.title'                => 'GAME OVER',
    'over.final_score'          => 'eindscore: {points}',
    'over.restart_hint'         => 'druk {restart} voor een nieuw spel — {quit} om af te sluiten',
    'over.reason'               => 'reden: {reason}',
    'over.reason.spawn_blocked' => 'geen ruimte voor het volgende stuk',
    'over.reason.top_out'       => 'de stapel raakte het plafond',
    'over.reason.crushed'       => 'verpletterd door afval',

    // On-frame cues
    'hold_used'  => '(gebruikt)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B klaar',
    'over.best'    => 'beste: {points}',
    'over.new_best' => 'nieuw record!',
    'over.games' => 'voltooide spellen: {games}',

    // Versus renderer
    'label.player'   => 'SPELER',
    'label.computer' => 'COMPUTER',
    'win.player'     => 'JIJ WINT!',
    'win.computer'   => 'COMPUTER WINT!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'gewiste regels sturen de tegenstander rommel — één gat per regel',
    'vs.record' => 'balans: {wins} gewonnen, {losses} verloren',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => 'jij',
    'vs.strip.computer' => 'computer',
    'vs.strip.score' => 'score:',
    'vs.strip.lines' => 'regels:',
    'vs.strip.level' => 'niveau:',
    'intro.title' => 'welkom bij candy-tetris',
    'intro.goal' => 'verwijder regels — bereik de top niet',
    'intro.press' => 'druk op {keys} om te spelen',
    // candy-tetris-006/-095 (nl): the on-ramp advertises the reachable key reference.
    'intro.keys' => 'druk op {keys} voor de toetsen',
    'intro.vs' => 'versusmodus: candy-tetris -v',
    'key_or' => ' of ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: onbekende modus "{flag}"',
    'help_text'    => 'candy-tetris - Tetris-kloon

Gebruik: candy-tetris [opties]
  (geen)     Eenpersoonsspel starten
  -v, --vs   Modus tegen de computer
  -h, --help Toon deze hulp
  --version  Toon de versie
',
    // candy-tetris-006/-095 (nl): --help keys section header.
    'help.keys' => 'toetsen:',
    // candy-tetris-006/-095 (nl): the press that closes the key reference.
    'help.close' => 'druk opnieuw op {keys} om te sluiten',
    // candy-tetris-006/-095 (nl): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => 'het kader heeft een terminal van minimaal {cols}x{rows} nodig',
    // candy-tetris-006/-095 (nl): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => 'toetsen: {keys}',
    // candy-tetris-054/-083 (nl): the confirm question for an armed
    // destructive press, naming the live binding.
    'confirm.quit' => 'druk opnieuw op {keys} om te stoppen',
    // candy-tetris-054/-083 (nl): the confirm question for an armed
    // destructive press, naming the live binding.
    'confirm.restart' => 'druk opnieuw op {keys} om opnieuw te starten',
    // candy-tetris-092/-025 (nl): game-feel moments and the on-frame slot copy.
    'moment.lines.one' => '1 rij!',
    'moment.lines.many' => '{count} rijen!',
    'moment.level' => 'niveau {level}!',
    'moment.garbage' => 'vuilnis komt eraan!',
    'cli.no_tty' => 'candy-tetris heeft een interactieve terminal nodig: start het vanuit een TTY (geen pipe of omleiding) met een TERM anders dan dumb.',
    'launch.extras' => 'candy-tetris: slechts één argument toegestaan, maar {count} te veel: {list}',
    'score.save.failed' => 'candy-tetris: kon scores niet opslaan ({problem})',
    'keys.ignored' => 'candy-tetris: niet-leesbaar toetsenbestand genegeerd ({problem})',
    'scores.ignored' => 'candy-tetris: niet-leesbaar scorebestand genegeerd ({problem})',
    'config.problem' => '{detail} in {path}',
    'cli.refusal' => 'candy-tetris heeft een interactieve terminal nodig (een TTY).
Speelt zonder kleur: stel NO_COLOR in.',
    'cli.too_small' => 'candy-tetris heeft een {size}-terminal nodig; deze is {actual}.
Verander het vensterformaat en het spel gaat hier verder.',
    'cli.too_small_vs' => 'candy-tetris VS heeft een {size}-terminal nodig; deze is {actual}.
Verander het vensterformaat en de wedstrijd gaat hier verder.',
];
