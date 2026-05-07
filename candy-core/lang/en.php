<?php

/**
 * English (default) translations for candy-core.
 *
 * Keys are flat dot-paths under the `core.*` namespace registered by
 * {@see \SugarCraft\Core\Lang}. Values may use `{name}` placeholders that
 * are substituted at lookup time by {@see \SugarCraft\Core\I18n\T::translate()}.
 *
 * To add a new locale, copy this file to `lang/<locale>.php` (e.g.
 * `lang/fr.php`) and translate the values, leaving the keys and
 * placeholder names intact.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'rgb component out of range [0,255]: {value}',
    'color.invalid_hex'           => 'invalid hex color: {hex}',
    'color.ansi_out_of_range'     => 'ansi index out of range [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ansi256 index out of range [0,255]: {index}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'invalid 16-color fg code: {code}',
    'ansi.invalid_bg_code'        => 'invalid 16-color bg code: {code}',
    'ansi.component_out_of_range' => '{label} out of range [0,255]: {value}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open failed for: {cmd}',
];
