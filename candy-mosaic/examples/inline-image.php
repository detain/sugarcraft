#!/usr/bin/env php
<?php
/**
 * inline-image — render an image to the terminal using the best available protocol.
 *
 * Usage:
 *   php inline-image.php <image-file> [width-in-cells]
 *
 * Examples:
 *   php inline-image.php photo.png
 *   php inline-image.php screenshot.jpg 80
 *   php inline-image.php diagram.gif 60
 *
 * The script detects the terminal's capabilities (Kitty > iTerm2 > Sixel > Half-block)
 * and renders the image using the best protocol available.  The HalfBlock fallback
 * works on any terminal but uses only 256 colours.
 *
 * Exit codes:
 *   0 — image rendered successfully
 *   1 — no image path given or file not found
 *   2 — GD is not available
 */

declare(strict_types=1);

namespace SugarCraft\Mosaic\Examples;

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mosaic\{ImageSource, Mosaic};

function main(array $argv, int $argc): int
{
    // Check GD extension
    if (!extension_loaded('gd')) {
        fwrite(STDERR, "inline-image: ext-gd is required but is not loaded.\n");
        return 2;
    }

    // Parse arguments
    if ($argc < 2) {
        fwrite(STDERR, <<<USAGE
inline-image — render an image to the terminal via the best available protocol.

Usage: php inline-image.php <image-file> [width-in-cells]

Examples:
  php inline-image.php photo.png
  php inline-image.php screenshot.jpg 80

USAGE
        );
        return 1;
    }

    $imagePath = $argv[1];
    $width     = isset($argv[2]) ? (int) $argv[2] : 80;

    if (!file_exists($imagePath)) {
        fwrite(STDERR, "inline-image: file not found: {$imagePath}\n");
        return 1;
    }

    // Load image
    $image = ImageSource::fromFile($imagePath);

    // Detect terminal and render
    $mosaic = Mosaic::probe();

    $cap = $mosaic->capability();
    fprintf(
        STDERR,
        "inline-image: using %s (detected protocol: %s, cells: %s)\n",
        $mosaic->protocol(),
        $cap->detectSummary(),
        "{$width} wide"
    );

    $ansi = $mosaic->render($image, $width);

    // Write ANSI bytes directly to stdout — the terminal renders it
    fwrite(STDOUT, $ansi);
    fwrite(STDOUT, "\n"); // trailing newline for cleanliness

    return 0;
}

exit(main($argv, $argc));
