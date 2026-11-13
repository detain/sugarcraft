<?php

/**
 * Czech translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'skóre: {score}',
    'ui.best'                => ' rekord: {best}',
    'ui.new_best'            => ' 🏆 NOVÝ REKORD: {best}',
    'ui.plain_new_best'      => ' NOVY REKORD: {best}',
    'ui.crash_full'          => '💥 plác — stiskněte r znovu · q konec',
    'ui.crash_short'         => '💥 plác — r znovu · q konec',
    'ui.crash_floor'         => 'r znovu · q konec',
    'ui.ready_full'          => 'pro start stiskněte mezerník/⏎/↑/w · q konec',
    'ui.ready_short'         => 'mezerník startuje · q konec',
    'ui.live_full'           => 'mezerník/⏎/↑/w mává · r znovu · q konec',
    'ui.live_short'          => 'mezerník mává · q konec',
    'ui.plain_crash_full'    => 'x plac - stisknete r znovu - q konec',
    'ui.plain_crash_short'   => 'x plac - r znovu - q konec',
    'ui.plain_crash_floor'   => 'r znovu - q konec',
    'ui.plain_ready_full'    => 'pro start stisknete mezernik/Enter/up/w . q konec',
    'ui.plain_ready_short'   => 'mezernik startuje . q konec',
    'ui.plain_live_full'     => 'mezernik/Enter/up/w mava . r znovu . q konec',
    'ui.plain_live_short'    => 'mezernik mava . q konec',
    'ui.quit_hint' => 'stiskněte q pro ukončení.',
    'ui.quarantine'           => '⚠ Předchozí tabulku nejlepších výsledků se nepodařilo přečíst — archivováno jako {file}',
    'ui.plain_quarantine'     => 'x Předchozí tabulku nejlepších výsledků se nepodařilo přečíst - archivováno jako {file}',
    'notty.line1'            => 'candy-flappy potřebuje terminál, který umí vykreslit jeho výstup.',
    'notty.line2'            => 'stdout není interaktivní terminál nebo byl zjištěn TERM=dumb.',
    'size.notice'            => 'candy-flappy potřebuje terminál {need}; tento má {have}.',
    'size.resume'            => 'Změňte velikost okna a hra pokračuje přesně tady.',
];
