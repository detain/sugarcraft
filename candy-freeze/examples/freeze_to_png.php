<?php

declare(strict_types=1);

/**
 * Render a code sample to PNG with each theme. Outputs into ./out/.
 *
 *   php examples/freeze_to_png.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Freeze\PngRenderer;

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
        'dark'       => PngRenderer::dark(),
        'light'      => PngRenderer::light(),
        'dracula'    => PngRenderer::dracula(),
        'tokyoNight' => PngRenderer::tokyoNight(),
        'nord'       => PngRenderer::nord(),
    };
    $png = $renderer->withLineNumbers(true)->render($code);
    file_put_contents("$out/$themeName.png", $png);
    echo "wrote $out/$themeName.png\n";
}

// Also demonstrate ANSI colours.
$ansi = (new PngRenderer())
    ->withTheme(PngRenderer::dark()->theme)
    ->withWindow(true)
    ->withPadding(16)
    ->withBorderRadius(8)
    ->render("Hello World!\n\x1b[31mRed\x1b[0m and \x1b[1mbold\x1b[0m\n\x1b[32mGreen\x1b[0m and \x1b[4munderlined\x1b[0m");

file_put_contents("$out/ansi_demo.png", $ansi);
echo "wrote $out/ansi_demo.png\n";
