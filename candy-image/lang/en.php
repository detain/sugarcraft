<?php

/**
 * English (default) translations for candy-image.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => 'usage: candy-image <file> [--format=auto|sixel|kitty|iterm2|half|quarter|ascii|ansi16|ansi256|truecolor|symbols|symbol|sym]'
        . ' [--width=N|--size=WxH] [--height=N] [--scale=fit|shrink|fill|stretch|none|crop]'
        . ' [--symbols=CLASS[+CLASS...]] [--canvas=truecolor|ansi256|ansi16|fgbg] [--work=N]'
        . ' [--animate] [--passthrough=auto|none] [--polite] [--dump-detect] [--version] [--help|-h]',
    // Dead as a lookup: bin/candy-image cannot reach Lang before the autoloader
    // resolves and prints the twin literal itself. Key kept so LangTest's
    // existence arm stays green and the wording has one canonical home.
    'cli.no_autoload'  => 'candy-image: cannot find composer autoload.php',
    'cli.bad_flag'     => 'candy-image: unknown option --{flag} (see --help)',
    'cli.bad_value'    => 'candy-image: invalid value \'{value}\' for {flag}',
    'cli.bad_format'   => 'candy-image: unknown render format \'{value}\' (--help lists the formats available on this build)',
    'cli.chafa_refused' => 'candy-image: --format=chafa is deliberately unsupported (this viewer is chafa-independent; use sixel|kitty|iterm2 for pixel protocols)',
    'cli.bad_symbols'  => 'candy-image: unknown symbol class or selector in \'{value}\' (chafa classes: all, none, space, solid, stipple, block, border, diagonal, dot, quad, half, hhalf, vhalf, inverted, braille, sextant, octant, wedge, technical, geometric, ascii, alpha, digit, narrow, wide, ambiguous, ugly, extra, alnum, bad, legacy, latin, import, imported)',
    'cli.passthrough_refused' => 'candy-image: --passthrough={value} is not available on this base (the GNU Screen and tmux passthrough envelopes are applied automatically by detection; they cannot be forced from here — use auto or none)',
    'cli.too_many'     => 'candy-image: unexpected argument \'{arg}\' (this viewer takes one image file)',
    'cli.no_frames'    => 'candy-image: no animation frames decoded from \'{path}\'',
];
