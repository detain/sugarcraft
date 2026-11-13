<?php

/**
 * English (default) translations for candy-gifview.
 *
 * cli.no_autoload has no runtime consumer that could bind before autoload;
 * the bin shim prints the literal below with a hardcoded fwrite, because
 * translation cannot run before the vendor autoload exists. The key is kept
 * in every locale file so translators document (and parity tests see) the
 * exact string the bin exits with — LangTest pins the en value byte-equal
 * to the bin literal.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'usage: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: cannot find composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: unknown option --{flag}',
    'cli.empty_flag'  => 'candy-gifview: missing option name after --',
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
