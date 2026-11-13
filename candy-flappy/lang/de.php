<?php

/**
 * German translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'Punkte: {score}',
    'ui.best'                => ' Best: {best}',
    'ui.new_best'            => ' 🏆 NEUER REKORD: {best}',
    'ui.plain_new_best'      => ' NEUER REKORD: {best}',
    'ui.crash_full'          => '💥 platsch — drücke r für eine neue Runde, q zum Beenden',
    'ui.crash_short'         => '💥 platsch — r neu · q quit',
    'ui.ready_full'          => 'Leertaste/⏎/↑/w drücken zum Starten · r neu · q quit',
    'ui.ready_short'         => 'Leertaste startet · q quit',
    'ui.live_full'           => 'Leertaste/⏎/↑/w Flügelschlag · r neu · q quit',
    'ui.live_short'          => 'Leertaste schlägt · q quit',
    'ui.plain_crash_full'    => 'x platsch - druecke r fuer eine neue Runde, q zum Beenden',
    'ui.plain_crash_short'   => 'x platsch - r neu - q quit',
    'ui.plain_ready_full'    => 'Leertaste/Enter/^/w drueckt zum Starten . r neu . q quit',
    'ui.plain_ready_short'   => 'Leertaste startet . q quit',
    'ui.plain_live_full'     => 'Leertaste/Enter/^/w Fluegelschlag . r neu . q quit',
    'ui.plain_live_short'    => 'Leertaste schlaegt . q quit',
    'notty.line1'            => 'candy-flappy braucht ein interaktives Terminal.',
    'notty.line2'            => 'Keine nutzbare Farbfaehigkeit erkannt (kein TTY auf stdout oder TERM=dumb).',
    'size.notice'            => 'candy-flappy braucht ein {need}-Terminal; dieses hier ist {have}.',
    'size.resume'            => 'Ändere die Fenstergröße – das Spiel setzt genau hier fort.',
];
