<?php

/**
 * Spanish translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'puntos: {score}',
    'ui.best'                => ' récord: {best}',
    'ui.new_best'            => ' 🏆 ¡NUEVO RÉCORD!: {best}',
    'ui.plain_new_best'      => ' NUEVO RECORD: {best}',
    'ui.crash_full'          => '💥 {why} — pulsa r para reiniciar · q para salir',
    'ui.crash_short'         => '💥 {why} — r reiniciar · q salir',
    'ui.crash_floor'         => 'r reiniciar · q salir',
    'ui.ready_full'          => 'espacio/⏎/↑/w para empezar · q salir',
    'ui.ready_short'         => 'espacio para empezar · q salir',
    'ui.live_full'           => 'espacio/⏎/↑/w aletea · r reiniciar · q salir',
    'ui.live_short'          => 'espacio aletea · q salir',
    'ui.plain_crash_full'    => 'x {why} - pulsa r para reiniciar - q para salir',
    'ui.plain_crash_short'   => 'x {why} - r reiniciar - q salir',
    'ui.plain_crash_floor'   => 'r reiniciar - q salir',
    'ui.why_splat'            => 'splat',
    'ui.why_ceiling'          => 'chocaste con el techo',
    'ui.why_ground'           => 'chocaste con el suelo',
    'ui.why_pipe'             => 'chocaste con un tubo',
    'ui.plain_why_splat'      => 'splat',
    'ui.plain_why_ceiling'    => 'chocaste con el techo',
    'ui.plain_why_ground'     => 'chocaste con el suelo',
    'ui.plain_why_pipe'       => 'chocaste con un tubo',
    'ui.plain_ready_full'    => 'espacio/Enter/up/w para empezar . q salir',
    'ui.plain_ready_short'   => 'espacio para empezar . q salir',
    'ui.plain_live_full'     => 'espacio/Enter/up/w aletea . r reiniciar . q salir',
    'ui.plain_live_short'    => 'espacio aletea . q salir',
    'ui.quit_hint' => 'pulsa q para salir.',
    'ui.quarantine'           => '⚠ La tabla de récords anterior no se pudo leer — archivada como {file}',
    'ui.plain_quarantine'     => 'x La tabla de récords anterior no se pudo leer - archivada como {file}',
    'notty.line1'            => 'candy-flappy necesita una terminal que pueda mostrar su salida.',
    'notty.line2'            => 'stdout no es una terminal interactiva o se detectó TERM=dumb.',
    'size.notice'            => 'candy-flappy necesita una terminal de {need}; esta es de {have}.',
    'size.resume'            => 'Cambia el tamaño de la ventana y la partida continúa aquí.',
];
