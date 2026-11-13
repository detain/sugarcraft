#!/usr/bin/env php
<?php
/**
 * inline-image — render an image to the terminal using the best available protocol.
 *
 * Usage:
 *   php inline-image.php <image-file> [width-in-cells]   (default: 80)
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
 * Unicode-capable terminal keeps more colour than the two-row half-block alone.
 * The text tiers emit 24-bit fg/bg SGR with no automatic palette negotiation:
 * on a limited- or no-colour terminal select a ramp
 * (Mosaic::ascii(AsciiColorMode::Ansi16) for 16 colours, the Mono default for
 * none) or a canvas (CanvasMode::Ansi256/Ansi16/Fgbg on the quarter-block and
 * symbol tiers) explicitly — NO_COLOR does not change what the renderers emit.
 *
 * Exit codes:
 *   0 — image rendered successfully
 *   1 — no image path given, a width that is not a positive integer of
 *       cells, or no file at the given path
 *   2 — GD is not available
 *   3 — the path exists but is not a readable regular file, the file did
 *       not load as a supported image, or the render refused at the
 *       requested size
 *
 * Provenance: original example — it drives the Mosaic facade described
 * above and mirrors no upstream sample; the exit-code alphabet below is
 * this library's own contract.
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

Usage: php inline-image.php <image-file> [width-in-cells]   (default: 80)

Examples:
  php inline-image.php photo.png
  php inline-image.php screenshot.jpg 80

USAGE
        );
        return 1;
    }

    $imagePath = $argv[1];

    // A width is a cell count, so it must arrive as a positive integer:
    // the old (int) cast silently accepted "abc", "", "0" and "-5" and
    // rendered (or mislabelled) as if the user had typed them (finding
    // sugar-image-098). filter_var() is strict about integer-ness AND
    // bounds the range; anything else exits 1 with the usage alphabet.
    $width = 80;
    if ($argc >= 3) {
        $validated = filter_var(
            $argv[2],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($validated === false) {
            fwrite(STDERR, "inline-image: width must be a positive integer of cells, got: {$argv[2]}\n");
            return 1;
        }
        $width = $validated;
    }

    if (!file_exists($imagePath)) {
        fwrite(STDERR, "inline-image: file not found: {$imagePath}\n");
        return 1;
    }

    // An existing path that is not a regular file (a directory, /dev/null,
    // a fifo) is refusal class 3, and the example says so honestly instead
    // of echoing the library's "File not found" wording — those bytes are
    // catalog-pinned and their shape is gated elsewhere (SI-18); the CLI's
    // own first sentence must not be false-to-disk (finding sugar-image-099).
    if (!is_file($imagePath)) {
        fwrite(STDERR, "inline-image: not a readable regular file: {$imagePath}\n");
        return 3;
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
        // The label used to promise "cells: 80 wide" — a count dressed in
        // prose (finding sugar-image-087). Width IS a cell count; the
        // height is derived from the source aspect by the renderer, so
        // the line says exactly what is what.
        "inline-image: using %s (detected protocol: %s, width: %d cells, height derived from aspect)\n",
        $mosaic->protocol(),
        $cap->detectSummary(),
        $width
    );

    // The load door passing says nothing about the render door: a legal
    // width can still be refused there (the canvas-ceiling guard throws
    // InvalidArgumentException on an absurd pixel box) and QA reproduced
    // that escape as an uncaught fatal — exit 255, outside the documented
    // 0/1/2/3 alphabet (finding sugar-image-032). Same catch grammar as
    // the load leg, same refusal class 3.
    try {
        $ansi = $mosaic->render($image, $width);
    } catch (\InvalidArgumentException | \RuntimeException $e) {
        fwrite(STDERR, "inline-image: could not render image: {$e->getMessage()}\n");
        return 3;
    }

    // Write ANSI bytes directly to stdout — the terminal renders it
    fwrite(STDOUT, $ansi);
    fwrite(STDOUT, "\n"); // trailing newline for cleanliness

    return 0;
}

exit(main($argv, $argc));
