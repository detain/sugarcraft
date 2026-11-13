<?php

/**
 * German translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'unbekanntes Theme: {name}',
    'render.theme_config_unreadable' => 'Themenkonfiguration nicht lesbar: {path}',
    'render.theme_config_too_large' => 'Themenkonfiguration überschreitet {limit} Bytes: {path}',
    'glamour.json_invalid' => 'Themenkonfiguration ist kein gültiges JSON',
    'glamour.json_object' => 'Themenkonfiguration muss ein JSON-Objekt sein',
    'render.no_input_tty' => 'keine Eingabe: Datei als Argument übergeben oder Markdown in die Standardeingabe pipeilen',
    'render.no_input_empty' => 'keine Eingabe: die Standardeingabe ist leer',
    'render.pager_needs_tty' => 'Pager benötigt ein Terminal; stattdessen ohne -p auf die Standardausgabe rendern',
    'render.file_missing' => 'kann {path} nicht öffnen: Datei nicht gefunden',
    'render.file_is_directory' => 'kann {path} nicht öffnen: ist ein Verzeichnis',
    'render.file_unreadable' => 'kann {path} nicht öffnen: Datei nicht lesbar',
    'render.binary_input' => 'Renderen von {path} verweigert: keine Textdatei',
    'render.input_too_large' => 'Eingabe überschreitet {limit} Bytes: {path}',
    'render.width_invalid' => 'ungültiger --width-Wert: {value} (erwartet eine Ganzzahl zwischen 0 und 9999; 0 deaktiviert den Umbruch)',
];
