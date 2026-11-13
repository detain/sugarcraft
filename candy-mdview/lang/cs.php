<?php

/**
 * Czech translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'neznámé téma: {name}',
    'render.theme_config_unreadable' => 'konfigurace motivu nečitelná: {path}',
    'render.theme_config_too_large' => 'konfigurace motivu překračuje {limit} bajtů: {path}',
    'render.theme_config_unrecognized' => 'konfigurace motivu neodpovídá ani plochému, ani glamour schématu: {path}',
    'glamour.json_invalid' => 'konfigurace motivu není platný JSON',
    'glamour.json_object' => 'konfigurace motivu musí být objekt JSON',
    'glamour.decoration_unsafe' => 'pole motivu {slot} musí obsahovat pouze konce řádků, tabulátory a mezery',
    'glamour.value_too_large' => 'klíč motivu {key} překračuje maximum {limit}',
    'render.no_input_tty' => 'žádný vstup: zadejte soubor nebo nasměrujte Markdown na standardní vstup',
    'render.no_input_empty' => 'žádný vstup: standardní vstup je prázdný',
    'render.pager_needs_tty' => 'pager vyžaduje terminál; vykreslete na standardní výstup bez -p',
    'render.file_missing' => 'nelze otevřít {path}: soubor neexistuje',
    'render.file_is_directory' => 'nelze otevřít {path}: je to adresář',
    'render.file_unreadable' => 'nelze otevřít {path}: soubor nelze číst',
    'render.binary_input' => 'zamítnuto zobrazení {path}: není textový soubor',
    'render.input_too_large' => 'vstup přesahuje {limit} bajtů: {path}',
    'render.width_invalid' => 'neplatná hodnota --width: {value} (očekává se celé číslo od 0 do 9999; 0 vypíná zalomení)',
];
