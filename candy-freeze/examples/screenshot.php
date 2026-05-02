<?php

declare(strict_types=1);

/**
 * Render a code sample to SVG with each theme. Outputs into ./out/.
 *
 *   php examples/screenshot.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Freeze\SvgRenderer;

$code = <<<'PHP'
<?php
declare(strict_types=1);

function greet(string $name): string
{
    return "Hello, {$name}!";
}

echo greet('candyfreeze') . "\n";
PHP;

$out = __DIR__ . '/out';
if (!is_dir($out)) {
    mkdir($out, recursive: true);
}

foreach (['dark', 'light', 'dracula', 'tokyoNight', 'nord'] as $themeName) {
    $renderer = match ($themeName) {
        'dark'       => SvgRenderer::dark(),
        'light'      => SvgRenderer::light(),
        'dracula'    => SvgRenderer::dracula(),
        'tokyoNight' => SvgRenderer::tokyoNight(),
        'nord'       => SvgRenderer::nord(),
    };
    $svg = $renderer->withLineNumbers(true)->render($code);
    file_put_contents("$out/$themeName.svg", $svg);
    echo "wrote $out/$themeName.svg\n";
}
