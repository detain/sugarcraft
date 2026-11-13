<?php

declare(strict_types=1);

/*
 * Derives the README contrast figures from the palette constants in
 * src/Renderer.php, so a palette edit re-runs this script instead of
 * silently invalidating prose (flappy-39, finding -050). The two surfaces
 * are the documented playing fields: the dark Tokyo Night backdrop the
 * palette is designed on, and white, which is what a light terminal theme
 * serves up.
 *
 * Run: php tools/contrast.php
 */

error_reporting(E_ALL);
$exit = 0;

$source = __DIR__ . '/../src/Renderer.php';
$renderer = @file_get_contents($source);
if ($renderer === false) {
    fwrite(STDERR, "contrast: cannot read {$source}\n");
    exit(1);
}

if (preg_match_all("/private const ([A-Z]+) = '(#[0-9a-fA-F]{6})';/", $renderer, $matches, PREG_SET_ORDER) === false || $matches === []) {
    fwrite(STDERR, "contrast: no palette constants parsed from {$source}\n");
    exit(1);
}

$surfaces = ['dark #1a1b26' => '#1a1b26', 'white #ffffff' => '#ffffff'];

/**
 * WCAG 2.1 relative luminance of a #rrggbb colour.
 */
function luminance(string $hex): float
{
    [$r, $g, $b] = array_map(
        static function (int $channel): float {
            $channel /= 255;

            return $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;
        },
        sscanf($hex, '#%02x%02x%02x'),
    );

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function ratio(string $foreground, string $background): float
{
    $light = max(luminance($foreground), luminance($background));
    $dark = min(luminance($foreground), luminance($background));

    return ($light + 0.05) / ($dark + 0.05);
}

foreach ($surfaces as $label => $surface) {
    echo "{$label}\n";
    foreach ($matches as [, $name, $hex]) {
        printf("  %-6s %-9s %5.2f:1\n", $name, $hex, ratio($hex, $surface));
    }
}

// The README's threshold claims, restated from the same numbers: the hint
// row must stay below the 4.5:1 WCAG small-text line on the dark field.
foreach ($matches as [, $name, $hex]) {
    if ($name === 'HINT' && ratio($hex, '#1a1b26') >= 4.5) {
        fwrite(STDERR, "contrast: HINT now clears 4.5:1 — the README low-vision caveat needs a rewrite\n");
        $exit = 1;
    }
}

exit($exit);
