<?php

declare(strict_types=1);

/**
 * Render a Markdown string to ANSI text with SugarMarkdown, the same
 * pipeline the stdout mode of candy-mdview runs.
 *
 *   $ php examples/render-string.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Markdown\Renderer;

$markdown = <<<'MD'
# Release notes

Version **2.0** drops the legacy `--plain` flag. Read the
[migration guide](https://example.com/migrate) before upgrading.

- Faster startup
- Theme files load from JSON
- OSC 8 links are on by default
MD;

// Renderer::dark() is one of the stock presets; withWordWrap() pins the column
// count, the same way `candy-mdview --width 60` does.
$renderer = Renderer::dark()->withWordWrap(60);

echo $renderer->render($markdown), "\n";
