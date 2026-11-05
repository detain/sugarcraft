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
    'score_card' => 'skóre:  %d
řádky:  %d
úroveň: %d',
    'help_card'  => 'posun
otočit ve směru
otočit proti směru
pomalý pád
rychlý pád
hold
pauza
restart (konec)
konec',
    'next_label' => 'další:',
    'hold_label' => 'hold:',
    'paused'     => '[ pauza — p obnoví hru ]',

    // Game-over banner
    'over.title'                => 'KONEC HRY',
    'over.final_score'          => 'konečné skóre: {points}',
    'over.restart_hint'         => 'r pro restart — q pro konec',
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

    // Versus renderer
    'label.player'   => 'HRÁČ',
    'label.computer' => 'POČÍTAČ',
    'win.player'     => 'Vyhrál jsi!',
    'win.computer'   => 'Počítač vyhrál!',
    'over.quit_hint' => 'q pro konec',
    'vs_keys'        => 'pád
hold
pauza
restart
konec',
    'vs.record' => 'zápas: {wins} vyhráno, {losses} prohráno',
    'intro.title' => 'vítejte v candy-tetris',
    'intro.goal' => 'maž řádky - nedosáhni na vrchol',
    'intro.press' => 'pro hraní stiskni {keys}',
    'intro.vs' => 'režim proti sobě: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: neznámý režim "{flag}"',
    'help_text'    => 'candy-tetris - Tetris clone

Použití: candy-tetris [volby]
  (bez)      Spustí hru jednoho hráče
  -v, --vs   Režim proti počítači
  -h, --help Zobrazí tuto nápovědu
  --version  Vytiskne verzi
'
];
