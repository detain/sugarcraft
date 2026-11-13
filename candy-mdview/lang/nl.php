<?php

/**
 * Dutch translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'onbekend thema: {name}',
    'render.theme_config_unreadable' => 'thema-configuratie niet leesbaar: {path}',
    'render.theme_config_too_large' => 'thema-configuratie overschrijdt {limit} bytes: {path}',
    'render.theme_config_unrecognized' => 'themaconfiguratie voldoet niet aan het platte of het glamour-schema: {path}',
    'glamour.json_invalid' => 'thema-configuratie is geen geldige JSON',
    'glamour.json_object' => 'thema-configuratie moet een JSON-object zijn',
    'glamour.decoration_unsafe' => 'thema-veld {slot} mag alleen newlines, tabs en spaties bevatten',
    'glamour.value_too_large' => 'themasleutel {key} overschrijdt het maximum van {limit}',
    'render.no_input_tty' => 'geen invoer: geef een bestand op, stuur Markdown naar standard input of voer --help uit',
    'render.no_input_empty' => 'geen invoer: standard input is leeg',
    'render.pager_needs_tty' => 'de pager heeft een terminal nodig; render naar standaarduitvoer zonder -p',
    'render.file_missing' => 'kan {path} niet openen: bestand bestaat niet',
    'render.file_is_directory' => 'kan {path} niet openen: het is een map',
    'render.file_unreadable' => 'kan {path} niet openen: bestand niet leesbaar',
    'render.binary_input' => 'weergeven van {path} geweigerd: geen tekstbestand',
    'render.input_too_large' => 'invoer overschrijdt {limit} bytes: {path}',
    'render.fetch_failed' => 'kan {url} niet ophalen: {reason}',
    'render.directory_empty' => 'geen Markdown-document in {path}: gezocht naar README.md, README.markdown, index.md of één *.md',
    'render.directory_ambiguous' => 'geen enkel document in {path}: gevonden {found} — geef er een expliciet op',
    'render.directory_chosen' => 'bezig {chosen} uit mapargument {path} te renderen',
    'render.width_invalid' => 'ongeldige --width-waarde: {value} (verwacht een geheel getal tussen 0 en 9999; 0 schakelt afbreken uit)',
];
