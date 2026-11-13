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
langzaam laten
snel laten
opslag
pauze
herstart (end)
afsluiten',
    'next_label' => 'volgende:',
    'hold_label' => 'opslag:',
    'paused'     => '[ gepauzeerd — druk p om verder te gaan ]',

    // Game-over banner
    'over.title'                => 'GAME OVER',
    'over.final_score'          => 'eindscore: {points}',
    'over.restart_hint'         => 'druk r voor een nieuw spel — q om af te sluiten',
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

    // Versus renderer
    'label.player'   => 'SPELER',
    'label.computer' => 'COMPUTER',
    'win.player'     => 'JIJ WINT!',
    'win.computer'   => 'COMPUTER WINT!',
    'vs_keys'        => 'laten
opslag
pauze
opnieuw
afsluiten',
    'vs.record' => 'balans: {wins} gewonnen, {losses} verloren',
    'intro.title' => 'welkom bij candy-tetris',
    'intro.goal' => 'verwijder regels — bereik de top niet',
    'intro.press' => 'druk op {keys} om te spelen',
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
    'cli.no_tty' => 'candy-tetris heeft een interactieve terminal nodig: start het vanuit een TTY (geen pipe of omleiding) met een TERM anders dan dumb.',
    'launch.extras' => 'candy-tetris: slechts één argument toegestaan, maar {count} te veel: {list}',
    'score.save.failed' => 'candy-tetris: kon scores niet opslaan ({problem})',
    'keys.ignored' => 'candy-tetris: niet-leesbaar toetsenbestand genegeerd ({problem})',
    'scores.ignored' => 'candy-tetris: niet-leesbaar scorebestand genegeerd ({problem})',
    'config.problem' => 'candy-tetris: {detail} in {path}',
    'cli.refusal' => 'candy-tetris heeft een interactieve terminal nodig (een TTY).
Speelt zonder kleur: stel NO_COLOR in.',
    'cli.too_small' => 'candy-tetris heeft een {size}-terminal nodig; deze is {actual}.
Verander het vensterformaat en het spel gaat hier verder.',
    'cli.too_small_vs' => 'candy-tetris VS heeft een {size}-terminal nodig; deze is {actual}.
Verander het vensterformaat en de wedstrijd gaat hier verder.',
];
