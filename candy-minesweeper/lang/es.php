<?php

/**
 * Spanish translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'tablero demasiado pequeño',
    'board.minecount_out_of_range' => 'mineCount fuera de rango',
    'board.rows_shape_mismatch' => 'la forma de las filas no coincide con ancho/alto',
    'custom.min_rows' => 'las filas deben ser al menos 2',
    'custom.min_cols' => 'las columnas deben ser al menos 2',
    'custom.max_rows' => 'las filas deben ser como máximo 50',
    'custom.max_cols' => 'las columnas deben ser como máximo 50',
    'custom.min_mines' => 'se requiere al menos 1 mina',
    'custom.max_mines' => 'demasiadas minas — deja un área de inicio segura',
    'ui.status' => 'minas: {mines}  ·  banderas: {flags}  ·  restantes: {remaining}  ·  tiempo: {time}',
    'ui.boom' => '💥 bum — pulsa r para reiniciar',
    'ui.cleared' => '★ superado — pulsa r para jugar de nuevo',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  mover  ·  espacio / intro  revelar',
    'ui.help_board' => 'f  bandera  ·  c / clic central  acorde  ·  clic derecho  bandera',
    'ui.help_session' => 'r  reiniciar  ·  S  guardar  ·  L  cargar  ·  q / Esc / Ctrl+C  salir',
];
