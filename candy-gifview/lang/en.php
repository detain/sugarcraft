<?php

/**
 * English (default) translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'usage: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: cannot find composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: unknown option --{flag}',
    'cli.bad_value'   => 'candy-gifview: invalid value \'{value}\' for {flag} (want a positive number)',
    'cli.too_many'    => 'candy-gifview: unexpected argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} takes no value',
    'cli.bad_preset' => 'candy-gifview: unknown preset \'{preset}\' (want solid or density)',
    'cli.no_frames' => 'candy-gifview: no frames to play in \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: could not play \'{path}\': {reason}',
    'cli.reason_frames_over' => 'animation exceeds the {max}-frame intake cap; refusing to load',
    'cli.reason_bytes_over' => 'file is larger than the {max} MiB intake cap',
    'cli.reason_cells_over' => 'animation needs more than the {max}-cell resident budget for this viewport',
    'cli.duration_static' => 'candy-gifview: --duration needs at least two frames; this file decodes to {count}',
    'cli.need_gd' => 'candy-gifview: ext-gd is required to decode GIFs (install php-gd)',
    'cli.decoding' => 'candy-gifview: decoding \'{path}\'...',
    'cli.truncated' => 'candy-gifview: showing the first {max} frames — this viewer does not read longer animations',
];
