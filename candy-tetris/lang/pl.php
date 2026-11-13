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
    'score_card' => 'wynik: {score}
linie: {lines}
poziom: {level}',
    'help_card'  => 'ruch
obrót w prawo
obrót w lewo
powolny zjazd
szybki zjazd
bufor
pauza
restart (po)
wyjście',
    'next_label' => 'następna:',
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
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'usunięte linie wysyłają przeciwnikowi śmieci — każdy rząd z jedną dziurą',
    'vs.record' => 'bilans: {wins} wygrane, {losses} przegrane',
    'intro.title' => 'witamy w candy-tetris',
    'intro.goal' => 'usuwaj linie — nie dotknij góry',
    'intro.press' => 'naciśnij {keys} aby grać',
    'intro.vs' => 'tryb versus: candy-tetris -v',
    'key_or' => ' lub ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: nieznany tryb "{flag}"',
    'help_text'    => 'candy-tetris - klon Tetrisa

Użycie: candy-tetris [opcje]
  (bez arg.) Gra pojedyncza
  -v, --vs   Tryb przeciw komputerowi
  -h, --help Pokaż tę pomoc
  --version  Wypisz wersję
',
    'cli.no_tty' => 'candy-tetris wymaga interaktywnego terminala: uruchom go z TTY (nie przez potok ani przekierowanie), z TERM innym niż dumb.',
    'launch.extras' => 'candy-tetris: akceptowany jest tylko jeden argument, ale o {count} za dużo: {list}',
    'score.save.failed' => 'candy-tetris: nie udało się zapisać wyników ({problem})',
    'keys.ignored' => 'candy-tetris: pomijam nieczytelny plik klawiszy ({problem})',
    'scores.ignored' => 'candy-tetris: pomijam nieczytelny plik wyników ({problem})',
    'config.problem' => '{detail} w {path}',
    'cli.refusal' => 'candy-tetris wymaga terminala interaktywnego (TTY).
Działa bez koloru: ustaw NO_COLOR.',
    'cli.too_small' => 'candy-tetris wymaga terminala {size}; ten ma {actual}.
Zmień rozmiar okna, gra wznowi się tutaj.',
    'cli.too_small_vs' => 'candy-tetris VS wymaga terminala {size}; ten ma {actual}.
Zmień rozmiar okna, pojedynek wznowi się tutaj.',
];
