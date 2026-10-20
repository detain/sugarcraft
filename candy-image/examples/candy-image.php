<?php

/**
 * candy-image demo: build a small synthetic PNG in GD (no binary asset in
 * the repo), then run it through the exact CLI path the bin takes — same
 * Cli::main, same stdout — so the .vhs tape records the real viewer.
 */

declare(strict_types=1);

if (!extension_loaded('gd')) {
    fwrite(STDERR, "candy-image demo requires ext-gd\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\CandyImage\Cli;

$im = imagecreatetruecolor(96, 48);

// A deterministic gradient + bars so the tape looks identical per render.
for ($x = 0; $x < 96; ++$x) {
    for ($y = 0; $y < 24; ++$y) {
        imagesetpixel($im, $x, $y, imagecolorallocate($im, 10 + $x * 2, 30 + $y * 8, 200 - $x));
    }
}
for ($x = 0; $x < 96; ++$x) {
    for ($y = 24; $y < 48; ++$y) {
        imagesetpixel($im, $x, $y, imagecolorallocate($im, 240 - $y * 3, 200 - $x, 40 + $x));
    }
}
for ($i = 0; $i < 8; ++$i) {
    imagefilledrectangle($im, $i * 12, 40, $i * 12 + 9, 47, imagecolorallocate($im, $i * 30, 255 - $i * 30, 128));
}

$path = tempnam(sys_get_temp_dir(), 'candy_image_demo_') . '.png';
imagepng($im, $path);
imagedestroy($im);

$exit = Cli::main(
    ['candy-image', '--format=quarter', '--size=48x12', $path],
    STDOUT,
    STDERR,
);

unlink($path);

exit($exit);
