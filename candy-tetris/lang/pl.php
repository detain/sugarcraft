<?php

/**
 * Polish UI strings for candy-tetris.
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
    'score_card' => 'wynik: %d
linie: %d
poziom: %d',
    'help_card'  => 'ruch
obrót w prawo
obrót w lewo
powolny zjazd
szybki zjazd
bufor
pauza
restart (po)
wyjście',
    'next_label' => 'następny:',
    'hold_label' => 'bufor:',
    'paused'     => '[ pauza — naciśnij p aby wznowić ]',

    // Game-over banner
    'over.title'                => 'KONIEC GRY',
    'over.final_score'          => 'wynik końcowy: {points}',
    'over.restart_hint'         => 'naciśnij r aby zagrać ponownie — q aby wyjść',
    'over.reason'               => 'powód: {reason}',
    'over.reason.spawn_blocked' => 'brak miejsca na następną figurę',
    'over.reason.top_out'       => 'stos sięgnął sufitu',
    'over.reason.crushed'       => 'zgnieciony przez śmieci',

    // On-frame cues
    'hold_used'  => '(użyty)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B gotowe',
    'over.best'    => 'rekord: {points}',
    'over.new_best' => 'nowy rekord!',

    // Versus renderer
    'label.player'   => 'GRACZ',
    'label.computer' => 'KOMPUTER',
    'win.player'     => 'WYGRAŁEŚ!',
    'win.computer'   => 'KOMPUTER WYGRAŁ!',
    'over.quit_hint' => 'naciśnij q aby wyjść',
    'vs_keys'        => 'zjazd
bufor
pauza
restart
wyjście',
    'vs.record' => 'bilans: {wins} wygrane, {losses} przegrane',
    'intro.title' => 'witamy w candy-tetris',
    'intro.goal' => 'usuwaj linie - nie dotknij góry',
    'intro.press' => 'naciśnij {keys} aby grać',
    'intro.vs' => 'tryb versus: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: nieznany tryb "{flag}"',
    'help_text'    => 'candy-tetris - klon Tetrisa

Użycie: candy-tetris [opcje]
  (bez arg.) Gra pojedyncza
  -v, --vs   Tryb przeciw komputerowi
  -h, --help Pokaż tę pomoc
  --version  Wypisz wersję
'
];
