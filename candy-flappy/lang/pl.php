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
    'ui.best'                => ' rekord: {best}',
    'ui.new_best'            => ' 🏆 NOWY REKORD: {best}',
    'ui.plain_new_best'      => ' NOWY REKORD: {best}',
    'ui.crash_full'          => '💥 plusk — naciśnij r aby zagrać od nowa · q aby wyjść',
    'ui.crash_short'         => '💥 plusk — r ponów · q wyjdź',
    'ui.crash_floor'         => 'r ponów · q wyjdź',
    'ui.ready_full'          => 'spacja/⏎/↑/w aby zacząć · q wyjdź',
    'ui.ready_short'         => 'spacja zaczyna · q wyjdź',
    'ui.live_full'           => 'spacja/⏎/↑/w macha · r ponów · q wyjdź',
    'ui.live_short'          => 'spacja macha · q wyjdź',
    'ui.plain_crash_full'    => 'x plusk - nacisnij r aby grac od nowa - q aby wyjsc',
    'ui.plain_crash_short'   => 'x plusk - r ponow - q wyjdz',
    'ui.plain_crash_floor'   => 'r ponow - q wyjdz',
    'ui.plain_ready_full'    => 'spacja/Enter/up/w aby zaczac . q wyjdz',
    'ui.plain_ready_short'   => 'spacja zaczyna . q wyjdz',
    'ui.plain_live_full'     => 'spacja/Enter/up/w macha . r ponów . q wyjdz',
    'ui.plain_live_short'    => 'spacja macha . q wyjdz',
    'ui.quit_hint' => 'naciśnij q, aby zakończyć.',
    'ui.quarantine'           => '⚠ Poprzednia tabela wyników była nieczytelna — zarchiwizowano jako {file}',
    'ui.plain_quarantine'     => 'x Poprzednia tabela wyników była nieczytelna - zarchiwizowano jako {file}',
    'notty.line1'            => 'candy-flappy potrzebuje terminala, który potrafi wyświetlić jego wyjście.',
    'notty.line2'            => 'stdout nie jest interaktywnym terminalem albo wykryto TERM=dumb.',
    'size.notice'            => 'candy-flappy potrzebuje terminala {need}; ten ma {have}.',
    'size.resume'            => 'Zmień rozmiar okna, a gra wznowi dokładnie tutaj.',
];
