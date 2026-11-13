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
    'ui.best'                => ' mejor: {best}',
    'ui.new_best'            => ' 🏆 ¡NUEVO RÉCORD!: {best}',
    'ui.plain_new_best'      => ' NUEVO RECORD: {best}',
    'ui.crash_full'          => '💥 ¡splat! — pulsa r para reintentar, q para salir',
    'ui.crash_short'         => '💥 splat — r reintentar · q salir',
    'ui.ready_full'          => 'espacio/⏎/↑/w para empezar · r reiniciar · q salir',
    'ui.ready_short'         => 'espacio para empezar · q salir',
    'ui.live_full'           => 'espacio/⏎/↑/w aletea · r reiniciar · q salir',
    'ui.live_short'          => 'espacio aletea · q salir',
    'ui.plain_crash_full'    => 'x splat - pulsa r para reintentar, q para salir',
    'ui.plain_crash_short'   => 'x splat - r reintentar - q salir',
    'ui.plain_ready_full'    => 'espacio/Enter/^/w para empezar . r reiniciar . q salir',
    'ui.plain_ready_short'   => 'espacio para empezar . q salir',
    'ui.plain_live_full'     => 'espacio/Enter/^/w aletea . r reiniciar . q salir',
    'ui.plain_live_short'    => 'espacio aletea . q salir',
    'notty.line1'            => 'candy-flappy necesita una terminal interactiva.',
    'notty.line2'            => 'No se detectó ninguna capacidad de color útil (stdout no-TTY o TERM=dumb).',
    'size.notice'            => 'candy-flappy necesita una terminal de {need}; esta es de {have}.',
    'size.resume'            => 'Cambia el tamaño de la ventana y la partida continúa aquí.',
];
