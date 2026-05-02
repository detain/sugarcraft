<?php

declare(strict_types=1);

/**
 * Themes — render the same Markdown under all 8 built-in themes.
 *
 *   php examples/themes.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Shine\Renderer;
use CandyCore\Shine\Theme;

$md = <<<'MD'
# CandyShine

> The PHP port of `glamour`.

Renders **Markdown** to *ANSI*. Handles:

- bullet lists
- `inline code`
- [hyperlinks](https://sugarcraft.github.io)

```php
echo (new Renderer())->render('# hi');
```
MD;

$themes = [
    'ansi'        => Theme::ansi(),
    'dark'        => Theme::dark(),
    'light'       => Theme::light(),
    'dracula'     => Theme::dracula(),
    'tokyo-night' => Theme::tokyoNight(),
    'pink'        => Theme::pink(),
];

foreach ($themes as $name => $theme) {
    echo "\x1b[1;36m── $name ──\x1b[0m\n";
    echo (new Renderer())->withTheme($theme)->withWordWrap(60)->render($md) . "\n";
}
