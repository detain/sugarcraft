<?php

/**
 * Dutch translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'score: {score}',
    'ui.best'                => ' beste: {best}',
    'ui.new_best'            => ' 🏆 NIEUW RECORD: {best}',
    'ui.plain_new_best'      => ' NIEUW RECORD: {best}',
    'ui.crash_full'          => '💥 splat — druk op r voor nog een ronde, q om te stoppen',
    'ui.crash_short'         => '💥 splat — r opnieuw · q stop',
    'ui.crash_floor'         => 'r opnieuw · q stop',
    'ui.ready_full'          => 'spatie/⏎/↑/w om te starten · r opnieuw · q stop',
    'ui.ready_short'         => 'spatie start · q stop',
    'ui.live_full'           => 'spatie/⏎/↑/w slag · r opnieuw · q stop',
    'ui.live_short'          => 'spatie slaat · q stop',
    'ui.plain_crash_full'    => 'x splat - druk op r voor nog een ronde, q om te stoppen',
    'ui.plain_crash_short'   => 'x splat - r opnieuw - q stop',
    'ui.plain_crash_floor'   => 'r opnieuw - q stop',
    'ui.plain_ready_full'    => 'spatie/Enter/^/w om te starten . r opnieuw . q stop',
    'ui.plain_ready_short'   => 'spatie start . q stop',
    'ui.plain_live_full'     => 'spatie/Enter/^/w slag . r opnieuw . q stop',
    'ui.plain_live_short'    => 'spatie slaat . q stop',
    'ui.quit_hint' => 'druk op q om te stoppen.',
    'notty.line1'            => 'candy-flappy heeft een interactieve terminal nodig.',
    'notty.line2'            => 'Geen bruikbare kleurcapabiliteit gevonden (geen-TTY stdout of TERM=dumb).',
    'size.notice'            => 'candy-flappy heeft een {need}-terminal nodig; deze is {have}.',
    'size.resume'            => 'Maak het venster groter en het spel gaat precies hier verder.',
];
