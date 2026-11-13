<?php

declare(strict_types=1);

/**
 * Render a Markdown sample with a handful of stock themes. With
 * GLAMOUR_STYLE or NO_COLOR set, an extra section shows what
 * Theme::fromEnvironment() picks:
 *
 *   $ php examples/render.php
 *   $ GLAMOUR_STYLE=tokyo-night php examples/render.php
 *   $ NO_COLOR=1 php examples/render.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

$markdown = <<<MD
# Heading

A paragraph with **bold**, _italic_, ~~strike~~, and `inline code`.

- bullet one
- bullet two
- [a link](https://example.com)

```php
<?php
echo "fenced code block\n";
```
MD;

if (getenv('GLAMOUR_STYLE') !== false || getenv('NO_COLOR') !== false) {
    echo "═══ environment ═══\n\n";
    echo (new Renderer(Theme::fromEnvironment()))->withWordWrap(60)->render($markdown);
    echo "\n\n";
}

foreach (['ansi', 'dracula', 'tokyoNight', 'pink'] as $name) {
    echo "═══ $name ═══\n\n";
    $theme = match ($name) {
        'ansi'       => Theme::ansi(),
        'dracula'    => Theme::dracula(),
        'tokyoNight' => Theme::tokyoNight(),
        'pink'       => Theme::pink(),
    };
    echo (new Renderer($theme))->withWordWrap(60)->render($markdown);
    echo "\n\n";
}
