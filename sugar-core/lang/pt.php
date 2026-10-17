<?php

/**
 * Portuguese translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'componente RGB fora do intervalo [0,255]: {value}',
    'color.invalid_hex'           => 'cor hex inválida: {hex}',
    'color.ansi_out_of_range'     => 'índice ansi fora do intervalo [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'índice ansi256 fora do intervalo [0,255]: {index}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'código de primeiro plano de 16 cores inválido: {code}',
    'ansi.invalid_bg_code'        => 'código de fundo de 16 cores inválido: {code}',
    'ansi.component_out_of_range' => '{label} fora do intervalo [0,255]: {value}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open falhou para: {cmd}',
];
