<?php

/**
 * Polish translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'wynik: {score}',
    'ui.best'                => ' najlepszy: {best}',
    'ui.new_best'            => ' 🏆 NOWY REKORD: {best}',
    'ui.plain_new_best'      => ' NOWY REKORD: {best}',
    'ui.crash_full'          => '💥 plusk — naciśnij r, aby zagrać ponownie, q aby wyjść',
    'ui.crash_short'         => '💥 plusk — r ponów · q wyjdź',
    'ui.crash_floor'         => 'r ponów · q wyjdź',
    'ui.ready_full'          => 'spacja/⏎/↑/w aby zacząć · r ponów · q wyjdź',
    'ui.ready_short'         => 'spacja zaczyna · q wyjdź',
    'ui.live_full'           => 'spacja/⏎/↑/w macha · r ponów · q wyjdź',
    'ui.live_short'          => 'spacja macha · q wyjdź',
    'ui.plain_crash_full'    => 'x plusk - nacisnij r aby zagrac ponownie, q aby wyjsc',
    'ui.plain_crash_short'   => 'x plusk - r ponow - q wyjdz',
    'ui.plain_crash_floor'   => 'r ponow - q wyjdz',
    'ui.plain_ready_full'    => 'spacja/Enter/^/w aby zaczac . r ponow . q wyjdz',
    'ui.plain_ready_short'   => 'spacja zaczyna . q wyjdz',
    'ui.plain_live_full'     => 'spacja/Enter/^/w macha . r ponow . q wyjdz',
    'ui.plain_live_short'    => 'spacja macha . q wyjdz',
    'ui.quit_hint' => 'naciśnij q, aby zakończyć.',
    'notty.line1'            => 'candy-flappy potrzebuje interaktywnego terminala.',
    'notty.line2'            => 'Nie wykryto użytecznych możliwości kolorów (stdout bez TTY albo TERM=dumb).',
    'size.notice'            => 'candy-flappy potrzebuje terminala {need}; ten ma {have}.',
    'size.resume'            => 'Zmień rozmiar okna, a gra wznowi dokładnie tutaj.',
];
