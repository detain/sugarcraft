<?php

/**
 * German translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Aufruf: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: composer autoload.php nicht gefunden',
    'cli.bad_flag'    => 'candy-gifview: unbekannte Option --{flag}',
    'cli.empty_flag'  => 'candy-gifview: fehlender Optionsname nach --',
    'cli.bad_value'   => 'candy-gifview: ungültiger Wert \'{value}\' für {flag} (erwartet eine positive Zahl)',
    'cli.too_many'    => 'candy-gifview: unerwartetes Argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} erwartet keinen Wert',
    'cli.bad_preset' => 'candy-gifview: unbekanntes Preset \'{preset}\' (erwartet solid oder density)',
    'cli.no_frames' => 'candy-gifview: keine Frames zum Abspielen in \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: konnte \'{path}\' nicht abspielen: {reason}',
    'cli.reason_frames_over' => 'Animation überschreitet die Grenze von {max} Frames; Laden verweigert',
    'cli.reason_bytes_over' => 'Datei ist größer als die Grenze von {max} MiB',
    'cli.reason_cells_over' => 'Animation benötigt mehr als das Budget von {max} Zellen für dieses Ansichtsfenster',
    'cli.duration_static' => 'candy-gifview: --duration braucht mindestens zwei Frames; diese Datei wird zu {count} dekodiert',
    'cli.need_gd' => 'candy-gifview: ext-gd ist zum Dekodieren von GIFs erforderlich (php-gd installieren)',
    'cli.decoding' => 'candy-gifview: dekodiere \'{path}\'...',
    'cli.truncated' => 'candy-gifview: zeige die ersten {max} Frames — längere Animationen liest dieser Betrachter nicht',
];
