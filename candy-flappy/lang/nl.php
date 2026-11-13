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
    'ui.best'                => ' record: {best}',
    'ui.new_best'            => ' 🏆 NIEUW RECORD: {best}',
    'ui.plain_new_best'      => ' NIEUW RECORD: {best}',
    'ui.crash_full'          => '💥 {why} — druk op r om opnieuw te starten · q om te stoppen',
    'ui.crash_short'         => '💥 {why} — r opnieuw · q stop',
    'ui.crash_floor'         => 'r opnieuw · q stop',
    'ui.ready_full'          => 'spatie/⏎/↑/w om te starten · q stop',
    'ui.ready_short'         => 'spatie start · q stop',
    'ui.live_full'           => 'spatie/⏎/↑/w slag · r opnieuw · q stop',
    'ui.live_short'          => 'spatie slaat · q stop',
    'ui.plain_crash_full'    => 'x {why} - druk op r om opnieuw te starten - q om te stoppen',
    'ui.plain_crash_short'   => 'x {why} - r opnieuw - q stop',
    'ui.plain_crash_floor'   => 'r opnieuw - q stop',
    'ui.why_splat'            => 'splat',
    'ui.why_ceiling'          => 'plafond geraakt',
    'ui.why_ground'           => 'grond geraakt',
    'ui.why_pipe'             => 'pijp geraakt',
    'ui.plain_why_splat'      => 'splat',
    'ui.plain_why_ceiling'    => 'plafond geraakt',
    'ui.plain_why_ground'     => 'grond geraakt',
    'ui.plain_why_pipe'       => 'pijp geraakt',
    'ui.plain_ready_full'    => 'spatie/Enter/up/w om te starten . q stop',
    'ui.plain_ready_short'   => 'spatie start . q stop',
    'ui.plain_live_full'     => 'spatie/Enter/up/w slag . r opnieuw . q stop',
    'ui.plain_live_short'    => 'spatie slaat . q stop',
    'ui.quit_hint' => 'druk op q om te stoppen.',
    'ui.quarantine'           => '⚠ Vorige scorelijst was onleesbaar — gearchiveerd als {file}',
    'ui.plain_quarantine'     => 'x Vorige scorelijst was onleesbaar - gearchiveerd als {file}',
    'notty.line1'            => 'candy-flappy heeft een terminal nodig die de uitvoer kan weergeven.',
    'notty.line2'            => 'stdout is geen interactieve terminal of TERM=dumb is gedetecteerd.',
    'size.notice'            => 'candy-flappy heeft een {need}-terminal nodig; deze is {have}.',
    'size.resume'            => 'Maak het venster groter en het spel gaat precies hier verder.',
    'config.no_dir'          => 'kan de locatie van uw configuratie niet vinden — stel $HOME of $XDG_CONFIG_HOME in op de map met uw configuratie.',
];
