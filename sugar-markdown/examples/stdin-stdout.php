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

$markdown = (string) stream_get_contents(STDIN);
echo (new Renderer($theme))->render($markdown);
echo "\n";
