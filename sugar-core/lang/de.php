<?php

/**
 * German translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'RGB-Komponente außerhalb des Bereichs [0,255]: {value}',
    'color.invalid_hex'           => 'Ungültige Hex-Farbe: {hex}',
    'color.ansi_out_of_range'     => 'ANSI-Index außerhalb des Bereichs [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ANSI256-Index außerhalb des Bereichs [0,255]: {index}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'Ungültiger 16-Farben-Vordergrundcode: {code}',
    'ansi.invalid_bg_code'        => 'Ungültiger 16-Farben-Hintergrundcode: {code}',
    'ansi.component_out_of_range' => '{label} außerhalb des Bereichs [0,255]: {value}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open fehlgeschlagen für: {cmd}',
];
