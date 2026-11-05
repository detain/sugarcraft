<?php

/**
 * Polish translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'nieznany motyw: {name}',
    'render.theme_config_unreadable' => 'konfiguracja motywu nieczytelna: {path}',
    'render.theme_config_too_large' => 'konfiguracja motywu przekracza {limit} bajtów: {path}',
    'glamour.json_invalid' => 'konfiguracja motywu nie jest prawidłowym JSON-em',
    'glamour.json_object' => 'konfiguracja motywu musi być obiektem JSON',
    'render.no_input_tty' => 'brak wejścia: podaj plik lub prześlij Markdown na standardowe wejście',
    'render.no_input_empty' => 'brak wejścia: standardowe wejście jest puste',
    'render.file_missing' => 'nie można otworzyć {path}: nie ma takiego pliku',
    'render.file_is_directory' => 'nie można otworzyć {path}: to katalog',
    'render.file_unreadable' => 'nie można otworzyć {path}: plik nieczytelny',
    'render.binary_input' => 'odmowa renderowania {path}: to nie plik tekstowy',
    'render.input_too_large' => 'wejście przekracza {limit} bajtów: {path}',
    'render.width_invalid' => 'nieprawidłowa wartość --width: {value} (oczekiwano liczby całkowitej od 0 do 9999; 0 wyłącza łamanie wierszy)',
];
