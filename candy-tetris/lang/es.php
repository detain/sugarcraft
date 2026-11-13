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
    'score_card' => 'puntos: {score}
líneas: {lines}
nivel:  {level}',
    'help_card'  => 'mover
girar horario
girar antihorario
caída suave
caída rápida
reserva
pausa
reiniciar (fin)
salir',
    'next_label' => 'siguiente:',
    'hold_label' => 'reserva:',
    'paused'     => '[ en pausa — pulsa {keys} para continuar ]',

    // Game-over banner
    'over.title'                => 'FIN DEL JUEGO',
    'over.final_score'          => 'puntuación final: {points}',
    'over.restart_hint'         => 'pulsa {restart} para reiniciar — pulsa {quit} para salir',
    'over.reason'               => 'motivo: {reason}',
    'over.reason.spawn_blocked' => 'no hay sitio para la pieza siguiente',
    'over.reason.top_out'       => 'la pila tocó el techo',
    'over.reason.crushed'       => 'aplastado por la basura',

    // On-frame cues
    'hold_used'  => '(usada)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B listo',
    'over.best'    => 'mejor: {points}',
    'over.new_best' => '¡nuevo récord!',
    'over.games' => 'partidas terminadas: {games}',

    // Versus renderer
    'label.player'   => 'JUGADOR',
    'label.computer' => 'ORDENADOR',
    'win.player'     => '¡GANASTE!',
    'win.computer'   => '¡GANA EL ORDENADOR!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'las líneas borradas envían filas de basura al rival, cada una con un hueco',
    'vs.record' => 'balance: {wins} ganadas, {losses} perdidas',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => 'tú',
    'vs.strip.computer' => 'ordenador',
    'vs.strip.score' => 'puntos:',
    'vs.strip.lines' => 'líneas:',
    'vs.strip.level' => 'nivel:',
    'intro.title' => 'bienvenido a candy-tetris',
    'intro.goal' => 'elimina líneas — no llegues arriba',
    'intro.press' => 'pulsa {keys} para jugar',
    'intro.vs' => 'modo versus: candy-tetris -v',
    'key_or' => ' o ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: modo desconocido "{flag}"',
    'help_text'    => 'candy-tetris - clon de Tetris

Uso: candy-tetris [opciones]
  (sin args) Iniciar un jugador
  -v, --vs   Modo contra el ordenador
  -h, --help Mostrar esta ayuda
  --version  Imprimir la versión
',
    'cli.no_tty' => 'candy-tetris necesita una terminal interactiva: ejecútalo desde una TTY (no una tubería ni una redirección) con TERM distinto de dumb.',
    'launch.extras' => 'candy-tetris: solo se acepta un argumento, pero {count} de más: {list}',
    'score.save.failed' => 'candy-tetris: no se pudieron guardar las puntuaciones ({problem})',
    'keys.ignored' => 'candy-tetris: archivo de teclas ilegible, ignorado ({problem})',
    'scores.ignored' => 'candy-tetris: archivo de puntuaciones ilegible, ignorado ({problem})',
    'config.problem' => '{detail} en {path}',
    'cli.refusal' => 'candy-tetris necesita una terminal interactiva (TTY).
Funciona sin color: configura NO_COLOR.',
    'cli.too_small' => 'candy-tetris necesita una terminal {size}; esta es {actual}.
Cambia el tamaño de la ventana y la partida continúa aquí.',
    'cli.too_small_vs' => 'candy-tetris VS necesita una terminal {size}; esta es {actual}.
Cambia el tamaño de la ventana y la partida continúa aquí.',
];
