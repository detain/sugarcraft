<?php

declare(strict_types=1);

/**
 * Build a tiny synthetic GIF in /tmp, then play it through the candy-gifview
 * CLI (Cli::main, the same entry point bin/candy-gifview uses). Keeps the demo
 * self-contained — no binary GIFs in-repo.
 *
 *   php examples/view.php
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\GifView\Cli;

if (extension_loaded('gd') === false) {
    fwrite(STDERR, "candy-gifview example: ext-gd is required\n");
    exit(1);
}

$path = sys_get_temp_dir() . '/candy-gifview-demo.gif';

// Single-frame rainbow GIF — the decoder fans it out to one frame, which is
// plenty to demonstrate the viewer UI.
$im = imagecreatetruecolor(120, 60);
for ($y = 0; $y < 60; $y++) {
    for ($x = 0; $x < 120; $x++) {
        $r = (int) (255 * $x / 120);
        $g = (int) (255 * $y / 60);
        $b = (int) (255 * (($x + $y) % 60) / 60);
        $col = imagecolorallocate($im, $r, $g, $b);
        imagesetpixel($im, $x, $y, $col);
    }
}
imagegif($im, $path);
imagedestroy($im);

exit(Cli::main(['candy-gifview', $path], STDOUT, STDERR));
