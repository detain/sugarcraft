#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * text-tiers — render the committed fixture through each Unicode text tier.
 *
 * Usage:
 *   php examples/text-tiers.php [width-in-cells]   (default: 24)
 *
 * Every tier here emits plain SGR and glyphs, so the output needs no graphics
 * protocol and no chafa binary, and it is safe to capture or pipe. Compare the
 * tiers to choose a floor for terminals that cannot show pixels.
 *
 * Exit codes:
 *   0 — every tier rendered
 *   1 — the width is not a positive integer of cells
 *   2 — ext-gd is not loaded
 *   3 — the fixture could not be decoded or rendered
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Renderer\AsciiColorMode;

$arg   = $argv[1] ?? '24';
$width = filter_var($arg, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($width === false) {
    fwrite(STDERR, "text-tiers: width must be a positive integer of cells, got '{$arg}'\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "text-tiers: ext-gd is required to decode the fixture\n");
    exit(2);
}

$tiers = [
    'half-block'          => Mosaic::halfBlock(),
    'quarter-block'       => Mosaic::quarterBlock(),
    'ascii ramp (mono)'   => Mosaic::ascii(),
    'ascii ramp (ansi16)' => Mosaic::ascii(AsciiColorMode::Ansi16),
    'symbol map'          => Mosaic::symbols(),
];

try {
    $image = ImageSource::fromFile(__DIR__ . '/fixture-gradient.png');
    foreach ($tiers as $label => $mosaic) {
        echo "== {$label} ==\n";
        echo $mosaic->render($image, $width), "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'text-tiers: ' . $e->getMessage() . "\n");
    exit(3);
}

exit(0);
