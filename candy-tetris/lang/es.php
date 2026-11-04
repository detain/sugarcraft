<?php

/**
 * Spanish (Castilian) UI strings for candy-tetris.
 *
 * Keys, key order and every {placeholder} / sprintf token mirror
 * lang/en.php, which stays the source of truth. Flag spellings in the
 * usage screen are kept verbatim, so a refusal is never half-translated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card' => 'puntos: %d
líneas: %d
nivel:  %d',
    'help_card'  => '← →  mover
↑ x  girar horario
z    girar antihorario
↓    caída suave
spc  caída rápida
c    reserva
p    pausa
r    reiniciar
q    salir',
    'next_label' => 'siguiente:',
    'hold_label' => 'reserva:',
    'paused'     => '[ en pausa — pulsa p para continuar ]',

    // Game-over banner
    'over.title'                => 'FIN DEL JUEGO',
    'over.final_score'          => 'puntuación final: {points}',
    'over.restart_hint'         => 'pulsa r para reiniciar — pulsa q para salir',
    'over.reason'               => 'motivo: {reason}',
    'over.reason.spawn_blocked' => 'no hay sitio para la pieza siguiente',
    'over.reason.top_out'       => 'la pila tocó el techo',
    'over.reason.crushed'       => 'aplastado por la basura',

    // On-frame cues
    'hold_used'  => '(usada)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B listo',

    // Versus renderer
    'label.player'   => 'JUGADOR',
    'label.computer' => 'ORDENADOR',
    'win.player'     => '¡GANASTE!',
    'win.computer'   => '¡GANA EL ORDENADOR!',
    'over.quit_hint' => 'pulsa q para salir',
    'vs_keys'        => 'spc caída  c reserva  p pausa  r reinicio  q salir',

    // Launch screen
    'mode.unknown' => 'candy-tetris: modo desconocido "{flag}"',
    'help_text'    => 'candy-tetris - clon de Tetris

Uso: candy-tetris [opciones]
  (sin args) Iniciar un jugador
  -v, --vs   Modo contra el ordenador
  -h, --help Mostrar esta ayuda
  --version  Imprimir la versión
'
];
