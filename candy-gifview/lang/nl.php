<?php

/**
 * Dutch translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Gebruik: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: kan composer autoload.php niet vinden',
    'cli.bad_flag'    => 'candy-gifview: onbekende optie --{flag}',
    'cli.empty_flag'  => 'candy-gifview: optionsnaam ontbreekt na --',
    'cli.bad_value'   => 'candy-gifview: ongeldige waarde \'{value}\' voor {flag} (positief getal verwacht)',
    'cli.too_many'    => 'candy-gifview: onverwacht argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} verwacht geen waarde',
    'cli.bad_preset' => 'candy-gifview: onbekende preset \'{preset}\' (verwacht solid of density)',
    'cli.no_frames' => 'candy-gifview: geen frames om af te spelen in \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: kon \'{path}\' niet afspelen: {reason}',
    'cli.reason_frames_over' => 'animatie overschrijdt de limiet van {max} frames; laden geweigerd',
    'cli.reason_bytes_over' => 'bestand is groter dan de limiet van {max} MiB',
    'cli.reason_cells_over' => 'animatie heeft meer dan het budget van {max} cellen nodig voor dit venster',
    'cli.duration_static' => 'candy-gifview: --duration heeft minstens twee frames nodig; dit bestand decodeert tot {count}',
    'cli.need_gd' => 'candy-gifview: ext-gd is vereist om GIF\'s te decoderen (installeer php-gd)',
    'cli.decoding' => 'candy-gifview: \'{path}\' decoderen...',
    'cli.truncated' => 'candy-gifview: toon de eerste {max} frames — langere animaties leest deze viewer niet',
];
