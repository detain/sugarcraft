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
    'ui.best'                => ' Rekord: {best}',
    'ui.new_best'            => ' 🏆 NEUER REKORD: {best}',
    'ui.plain_new_best'      => ' NEUER REKORD: {best}',
    'ui.crash_full'          => '💥 {why} — drücke r zum Neustart · q quit',
    'ui.crash_short'         => '💥 {why} — r neu · q quit',
    'ui.crash_floor'         => 'r neu · q quit',
    'ui.ready_full'          => 'Leertaste/⏎/↑/w drücken zum Starten · q quit',
    'ui.ready_short'         => 'Leertaste startet · q quit',
    'ui.live_full'           => 'Leertaste/⏎/↑/w Flügelschlag · r neu · q quit',
    'ui.live_short'          => 'Leertaste schlägt · q quit',
    'ui.plain_crash_full'    => 'x {why} - druecke r zum Neustart - q quit',
    'ui.plain_crash_short'   => 'x {why} - r neu - q quit',
    'ui.plain_crash_floor'   => 'r neu - q quit',
    'ui.why_splat'            => 'platsch',
    'ui.why_ceiling'          => 'Decke getroffen',
    'ui.why_ground'           => 'Boden getroffen',
    'ui.why_pipe'             => 'Rohr getroffen',
    'ui.plain_why_splat'      => 'platsch',
    'ui.plain_why_ceiling'    => 'Decke getroffen',
    'ui.plain_why_ground'     => 'Boden getroffen',
    'ui.plain_why_pipe'       => 'Rohr getroffen',
    'ui.plain_ready_full'    => 'Leertaste/Enter/up/w drueckt zum Starten . q quit',
    'ui.plain_ready_short'   => 'Leertaste startet . q quit',
    'ui.plain_live_full'     => 'Leertaste/Enter/up/w Fluegelschlag . r neu . q quit',
    'ui.plain_live_short'    => 'Leertaste schlaegt . q quit',
    'ui.quit_hint' => 'q drücken zum Beenden.',
    'ui.quarantine'           => '⚠ Vorherige Bestenliste war unlesbar — als {file} archiviert',
    'ui.plain_quarantine'     => 'x Vorherige Bestenliste war unlesbar - als {file} archiviert',
    'notty.line1'            => 'candy-flappy braucht ein Terminal, das seine Ausgabe darstellen kann.',
    'notty.line2'            => 'stdout ist kein interaktives Terminal oder TERM=dumb wurde erkannt.',
    'size.notice'            => 'candy-flappy braucht ein {need}-Terminal; dieses hier ist {have}.',
    'size.resume'            => 'Ändere die Fenstergröße – das Spiel setzt genau hier fort.',
];
