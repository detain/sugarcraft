<?php

/**
 * Italian translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'punti: {score}',
    'ui.best'                => ' migliore: {best}',
    'ui.new_best'            => ' 🏆 NUOVO RECORD: {best}',
    'ui.plain_new_best'      => ' NUOVO RECORD: {best}',
    'ui.crash_full'          => '💥 splat — premi r per riprovare, q per uscire',
    'ui.crash_short'         => '💥 splat — r riprova · q esci',
    'ui.crash_floor'         => 'r riprova · q esci',
    'ui.ready_full'          => 'spazio/⏎/↑/w per iniziare · r ripartire · q uscire',
    'ui.ready_short'         => 'spazio per iniziare · q uscire',
    'ui.live_full'           => 'spazio/⏎/↑/w batte · r ripartire · q uscire',
    'ui.live_short'          => 'spazio batte · q uscire',
    'ui.plain_crash_full'    => 'x splat - premi r per riprovare, q per uscire',
    'ui.plain_crash_short'   => 'x splat - r riprova - q esci',
    'ui.plain_crash_floor'   => 'r riprova - q esci',
    'ui.plain_ready_full'    => 'spazio/Enter/^/w per iniziare . r ripartire . q uscire',
    'ui.plain_ready_short'   => 'spazio per iniziare . q uscire',
    'ui.plain_live_full'     => 'spazio/Enter/^/w batte . r ripartire . q uscire',
    'ui.plain_live_short'    => 'spazio batte . q uscire',
    'ui.quit_hint' => 'premi q per uscire.',
    'notty.line1'            => 'candy-flappy richiede un terminale in grado di rendere la sua uscita.',
    'notty.line2'            => 'stdout non è un terminale interattivo oppure è stato rilevato TERM=dumb.',
    'size.notice'            => 'candy-flappy richiede un terminale {need}; questo è {have}.',
    'size.resume'            => 'Ridimensiona la finestra e il gioco riprende esattamente qui.',
];
