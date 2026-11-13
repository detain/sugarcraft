<?php

/**
 * Czech UI strings for candy-tetris.
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
    'score_card' => 'skóre:  {score}
řádky:  {lines}
úroveň: {level}',
    'help_card'  => 'posun
otočit ve směru
otočit proti směru
rotace o 180°
pomalý pád
rychlý pád
hold
pauza
restart (konec)
konec',
    'next_label' => 'další:',
    'hold_label' => 'hold:',
    'paused'     => '[ pauza — {keys} obnoví hru ]',

    // Game-over banner
    'over.title'                => 'KONEC HRY',
    'over.final_score'          => 'konečné skóre: {points}',
    'over.restart_hint'         => '{restart} pro restart — {quit} pro konec',
    'over.reason'               => 'důvod: {reason}',
    'over.reason.spawn_blocked' => 'není místo pro další kus',
    'over.reason.top_out'       => 'stack dosáhl stropu',
    'over.reason.crushed'       => 'drcen odpadem',

    // On-frame cues
    'hold_used'  => '(použito)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B připraveno',
    'over.best'    => 'nejlépe: {points}',
    'over.new_best' => 'nový rekord!',
    'over.games' => 'dokončené hry: {games}',

    // Versus renderer
    'label.player'   => 'HRÁČ',
    'label.computer' => 'POČÍTAČ',
    'win.player'     => 'VYHRÁL JSI!',
    'win.computer'   => 'POČÍTAČ VYHRÁL!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'vyčištěné řádky pošlou soupeři odpad — každý řádek má jednu díru',
    'vs.record' => 'zápas: {wins} vyhráno, {losses} prohráno',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => 'ty',
    'vs.strip.computer' => 'počítač',
    'vs.strip.score' => 'skóre:',
    'vs.strip.lines' => 'řádky:',
    'vs.strip.level' => 'úroveň:',
    'intro.title' => 'vítejte v candy-tetris',
    'intro.goal' => 'maž řádky — nedosáhni na vrchol',
    'intro.press' => 'pro hraní stiskni {keys}',
    // candy-tetris-006/-095 (cs): the on-ramp advertises the reachable key reference.
    'intro.keys' => 'stiskněte {keys} kdykoli pro zobrazení kláves',
    'intro.vs' => 'režim proti sobě: candy-tetris -v',
    'key_or' => ' nebo ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: neznámý režim "{flag}"',
    'help_text'    => 'candy-tetris - Tetris clone

Použití: candy-tetris [volby]
  (bez)      Spustí hru jednoho hráče
  -v, --vs   Režim proti počítači
  -h, --help Zobrazí tuto nápovědu
  --version  Vytiskne verzi
',
    // candy-tetris-006/-095 (cs): --help keys section header.
    'help.keys' => 'klávesy:',
    // candy-tetris-006/-095 (cs): the press that closes the key reference.
    'help.close' => 'pro zavření stiskněte znovu {keys}',
    // candy-tetris-006/-095 (cs): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => 'rám potřebuje terminál alespoň {cols}x{rows}',
    // candy-tetris-006/-095 (cs): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => 'klávesy: {keys}',
    // candy-tetris-092/-025 (cs): game-feel moments and the on-frame slot copy.
    'moment.lines.one' => '1 řada!',
    'moment.lines.many' => '{count} řady!',
    'moment.level' => 'úroveň {level}!',
    'moment.garbage' => 'přicházející odpad!',
    'cli.no_tty' => 'candy-tetris potřebuje interaktivní terminál: spusťte ho z TTY (ne z roury ani přesměrování) a s TERM jiným než dumb.',
    'launch.extras' => 'candy-tetris: je přijímán pouze jeden argument, ale o {count} navíc: {list}',
    'score.save.failed' => 'candy-tetris: nelze uložit skóre ({problem})',
    'keys.ignored' => 'candy-tetris: nečitelný soubor klíčů ignorován ({problem})',
    'scores.ignored' => 'candy-tetris: nečitelný soubor skóre ignorován ({problem})',
    'config.problem' => '{detail} v {path}',
    'cli.refusal' => 'candy-tetris potřebuje interaktivní terminál (TTY).
Hraje bez barev: nastavte NO_COLOR.',
    'cli.too_small' => 'candy-tetris potřebuje terminál {size}; tento má {actual}.
Změňte velikost okna a hra pokračuje právě tady.',
    'cli.too_small_vs' => 'candy-tetris VS potřebuje terminál {size}; tento má {actual}.
Změňte velikost okna a duel pokračuje právě tady.',
];
