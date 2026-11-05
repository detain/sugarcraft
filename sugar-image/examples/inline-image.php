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
 * The script probes the terminal (Mosaic::probe()) and renders through the best
 * protocol available: Kitty, then iTerm2, then Sixel, then the text tiers.  The
 * chafa rung is explicit opt-in only (it is the one tier that shells out), and the
 * practical floor is the symbol-map renderer rather than plain half-block, so a
 * Unicode-capable terminal keeps more colour than the two-row half block alone.
 * The text tiers emit 24-bit fg/bg SGR with no automatic palette negotiation:
 * on a limited- or no-colour terminal select a ramp
 * (Mosaic::ascii(AsciiColorMode::Ansi16) for 16 colours, the Mono default for
 * none) or a canvas (CanvasMode::Ansi256/Ansi16/Fgbg on the quarter-block and
 * symbol tiers) explicitly — NO_COLOR does not change what the renderers emit.
 *
 * Exit codes:
 *   0 — image rendered successfully
 *   1 — no image path given or file not found
 *   2 — GD is not available
 *   3 — the file exists but did not load as a supported image
 */

declare(strict_types=1);

namespace SugarCraft\Image\Examples;

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Image\{ImageSource, Mosaic};

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

    // Load image — a present-but-refused file (bad MIME, decode failure,
    // pixel-guard refusal) must exit with the documented alphabet, not an
    // uncaught fatal.
    try {
        $image = ImageSource::fromFile($imagePath);
    } catch (\InvalidArgumentException | \RuntimeException $e) {
        fwrite(STDERR, "inline-image: could not load image: {$e->getMessage()}\n");
        return 3;
    }

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
