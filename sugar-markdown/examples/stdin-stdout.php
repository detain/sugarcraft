<?php

declare(strict_types=1);

/**
 * Pipe demo — render Markdown read from stdin to stdout.
 *
 *   $ cat README.md | php examples/stdin-stdout.php
 *
 * Pair with `--theme` for a different colour scheme:
 *
 *   $ cat docs/x.md | php examples/stdin-stdout.php --theme=dracula
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

$themeName = 'ansi';
foreach ($argv as $arg) {
    if (preg_match('/^--theme=(.+)$/', $arg, $m)) {
        $themeName = $m[1];
    }
}
$theme = Theme::byName($themeName) ?? Theme::ansi();

// Bounded read: a pipe can carry any size, and the renderer's cost grows
// with what it is given. Past the cap the demo refuses rather than stalling.
const MAX_INPUT_BYTES = 1048576;
$markdown = (string) stream_get_contents(STDIN, MAX_INPUT_BYTES + 1);
if (strlen($markdown) > MAX_INPUT_BYTES) {
    fwrite(STDERR, 'input exceeds ' . MAX_INPUT_BYTES . " bytes; refusing to render\n");
    exit(2);
}
echo (new Renderer($theme))->render($markdown);
echo "\n";
