<?php

declare(strict_types=1);

/**
 * Render a Markdown sample with each stock theme.
 *
 *   php examples/render.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Shine\Renderer;
use CandyCore\Shine\Theme;

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
