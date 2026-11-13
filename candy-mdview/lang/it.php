<?php

/**
 * Italian translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'tema sconosciuto: {name}',
    'render.theme_config_unreadable' => 'configurazione del tema non leggibile: {path}',
    'render.theme_config_too_large' => 'la configurazione del tema supera {limit} byte: {path}',
    'glamour.json_invalid' => 'la configurazione del tema non è un JSON valido',
    'glamour.json_object' => 'la configurazione del tema deve essere un oggetto JSON',
    'glamour.decoration_unsafe' => 'il campo {slot} del tema può contenere solo a capo, tab e spazi',
    'glamour.value_too_large' => 'la chiave {key} del tema supera il massimo di {limit}',
    'render.no_input_tty' => 'nessun input: indicare un file o inviare Markdown sullo standard input',
    'render.no_input_empty' => 'nessun input: lo standard input è vuoto',
    'render.pager_needs_tty' => 'il pager richiede un terminale; renderizza su stdout senza -p',
    'render.file_missing' => 'impossibile aprire {path}: file inesistente',
    'render.file_is_directory' => 'impossibile aprire {path}: è una directory',
    'render.file_unreadable' => 'impossibile aprire {path}: file illeggibile',
    'render.binary_input' => 'rendering rifiutato per {path}: non è un file di testo',
    'render.input_too_large' => 'l\'input supera {limit} byte: {path}',
    'render.width_invalid' => 'valore --width non valido: {value} (previsto un intero tra 0 e 9999; lo 0 disattiva il ritorno a capo)',
];
