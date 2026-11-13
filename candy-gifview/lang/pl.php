<?php

/**
 * Polish translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Użycie: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--lang=CODE] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: nie można znaleźć composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: nieznana opcja --{flag}',
    'cli.empty_flag'  => 'candy-gifview: brak nazwy opcji po --',
    'cli.bad_value'   => 'candy-gifview: nieprawidłowa wartość \'{value}\' dla {flag} (oczekiwano liczby dodatniej)',
    'cli.too_many'    => 'candy-gifview: nieoczekiwany argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} nie przyjmuje wartości',
    'cli.bad_preset' => 'candy-gifview: nieznany preset \'{preset}\' (oczekiwano solid lub density)',
    'cli.no_frames' => 'candy-gifview: brak klatek do odtworzenia w \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: nie można odtworzyć \'{path}\': {reason}',
    'cli.reason_frames_over' => 'animacja przekracza limit {max} klatek; ładowanie odrzucone',
    'cli.reason_bytes_over' => 'plik jest większy niż limit {max} MiB',
    'cli.reason_cells_over' => 'animacja wymaga dla tego okna więcej niż {max} komórek budżetu',
    'cli.duration_static' => 'candy-gifview: --duration wymaga co najmniej dwóch klatek; ten plik dekoduje się do {count}',
    'cli.need_gd' => 'candy-gifview: ext-gd jest wymagany do dekodowania GIF (zainstaluj php-gd)',
    'cli.decoding' => 'candy-gifview: dekodowanie \'{path}\'...',
    'cli.truncated' => 'candy-gifview: wyświetlanych jest pierwszych {max} klatek — dłuższych animacji ten podgląd nie czyta',
];
