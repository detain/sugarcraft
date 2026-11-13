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
    'ui.best'                => ' nejlepší: {best}',
    'ui.new_best'            => ' 🏆 NOVÝ REKORD: {best}',
    'ui.plain_new_best'      => ' NOVY REKORD: {best}',
    'ui.crash_full'          => '💥 plác — pro další pokus stiskněte r, pro konec q',
    'ui.crash_short'         => '💥 plác — r znovu · q konec',
    'ui.ready_full'          => 'pro start stiskněte mezerník/⏎/↑/w · r znovu · q konec',
    'ui.ready_short'         => 'mezerník startuje · q konec',
    'ui.live_full'           => 'mezerník/⏎/↑/w mává · r znovu · q konec',
    'ui.live_short'          => 'mezerník mává · q konec',
    'ui.plain_crash_full'    => 'x plac - pro dalsi pokus stisknete r, pro konec q',
    'ui.plain_crash_short'   => 'x plac - r znovu - q konec',
    'ui.plain_ready_full'    => 'pro start stisknete mezernik/Enter/^/w . r znovu . q konec',
    'ui.plain_ready_short'   => 'mezernik startuje . q konec',
    'ui.plain_live_full'     => 'mezernik/Enter/^/w mava . r znovu . q konec',
    'ui.plain_live_short'    => 'mezernik mava . q konec',
    'notty.line1'            => 'candy-flappy potřebuje interaktivní terminál.',
    'notty.line2'            => 'Nezjistila se žádná použitelná barevná způsobilost (stdout není TTY nebo TERM=dumb).',
    'size.notice'            => 'candy-flappy potřebuje terminál {need}; tento má {have}.',
    'size.resume'            => 'Změňte velikost okna a hra pokračuje přesně tady.',
];
