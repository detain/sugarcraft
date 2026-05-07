<?php

/**
 * French translations for candy-core.
 *
 * Keys are flat dot-paths under the `core.*` namespace registered by
 * {@see \SugarCraft\Core\Lang}. Values may use `{name}` placeholders that
 * are substituted at lookup time by {@see \SugarCraft\Core\I18n\T::translate()}.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'composant RVB hors plage [0,255] : {value}',
    'color.invalid_hex'           => 'couleur hex invalide : {hex}',
    'color.ansi_out_of_range'     => 'index ansi hors plage [0,15] : {index}',
    'color.ansi256_out_of_range'  => 'index ansi256 hors plage [0,255] : {index}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'code couleur 16 couleurs fg invalide : {code}',
    'ansi.invalid_bg_code'        => 'code couleur 16 couleurs bg invalide : {code}',
    'ansi.component_out_of_range' => '{label} hors plage [0,255] : {value}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open a échoué pour : {cmd}',
];
