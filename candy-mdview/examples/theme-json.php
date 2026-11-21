<?php

declare(strict_types=1);

/**
 * Build a custom theme from the flat JSON shape that `--theme-config` accepts
 * (one style object per element slot) and render a sample through it.
 *
 *   $ php examples/theme-json.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

$json = <<<'JSON'
{
  "heading1": {"foreground": "#dc322f", "bold": true},
  "bold": {"bold": true},
  "link": {"foreground": "#268bd2", "underline": true}
}
JSON;

// Theme::fromJsonString() reads the flat shape; slots left out of the JSON fall
// back to plain styles. Theme::fromJson() takes a path instead, and is how the
// bundled themes/*.json files load.
$theme = Theme::fromJsonString($json);

echo (new Renderer($theme))->withWordWrap(50)->render(
    "# Custom theme\n\nA **bold** word and a [link](https://example.com)."
);
