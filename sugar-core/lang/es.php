<?php

/**
 * Spanish translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'componente RGB fuera de rango [0,255]: {value}',
    'color.invalid_hex'           => 'color hex inválido: {hex}',
    'color.ansi_out_of_range'     => 'índice ansi fuera de rango [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'índice ansi256 fuera de rango [0,255]: {index}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'código de primer plano de 16 colores inválido: {code}',
    'ansi.invalid_bg_code'        => 'código de fondo de 16 colores inválido: {code}',
    'ansi.component_out_of_range' => '{label} fuera de rango [0,255]: {value}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open falló para: {cmd}',
];
