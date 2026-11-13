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
    'ui.best'                => ' record: {best}',
    'ui.new_best'            => ' 🏆 NUOVO RECORD: {best}',
    'ui.plain_new_best'      => ' NUOVO RECORD: {best}',
    'ui.crash_full'          => '💥 {why} — premi r per ripartire · q per uscire',
    'ui.crash_short'         => '💥 {why} — r ripartire · q esci',
    'ui.crash_floor'         => 'r ripartire · q esci',
    'ui.ready_full'          => 'spazio/⏎/↑/w per iniziare · q uscire',
    'ui.ready_short'         => 'spazio per iniziare · q uscire',
    'ui.live_full'           => 'spazio/⏎/↑/w batte · r ripartire · q uscire',
    'ui.live_short'          => 'spazio batte · q uscire',
    'ui.plain_crash_full'    => 'x {why} - premi r per ripartire - q per uscire',
    'ui.plain_crash_short'   => 'x {why} - r ripartire - q esci',
    'ui.plain_crash_floor'   => 'r ripartire - q esci',
    'ui.why_splat'            => 'splat',
    'ui.why_ceiling'          => 'colpito il soffitto',
    'ui.why_ground'           => 'colpito il suolo',
    'ui.why_pipe'             => 'colpito un tubo',
    'ui.plain_why_splat'      => 'splat',
    'ui.plain_why_ceiling'    => 'colpito il soffitto',
    'ui.plain_why_ground'     => 'colpito il suolo',
    'ui.plain_why_pipe'       => 'colpito un tubo',
    'ui.plain_ready_full'    => 'spazio/Enter/up/w per iniziare . q uscire',
    'ui.plain_ready_short'   => 'spazio per iniziare . q uscire',
    'ui.plain_live_full'     => 'spazio/Enter/up/w batte . r ripartire . q uscire',
    'ui.plain_live_short'    => 'spazio batte . q uscire',
    'ui.quit_hint' => 'premi q per uscire.',
    'ui.quarantine'           => '⚠ La classifica precedente non era leggibile — archiviata come {file}',
    'ui.plain_quarantine'     => 'x La classifica precedente non era leggibile - archiviata come {file}',
    'notty.line1'            => 'candy-flappy richiede un terminale in grado di rendere la sua uscita.',
    'notty.line2'            => 'stdout non è un terminale interattivo oppure è stato rilevato TERM=dumb.',
    'size.notice'            => 'candy-flappy richiede un terminale {need}; questo è {have}.',
    'size.resume'            => 'Ridimensiona la finestra e il gioco riprende esattamente qui.',
];
