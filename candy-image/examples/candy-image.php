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

// tempnam() creates the base file and the old code appended '.png' to its
// name — so every run orphaned an empty base while only the '.png' path was
// ever unlinked. One component, no double file: a unique name, created
// exclusively ('x' mode, O_EXCL) so a planted path is refused, cleaned up on
// every exit including a Cli::main throwable.
$path = sys_get_temp_dir() . '/candy-image-demo-' . bin2hex(random_bytes(8)) . '.png';
register_shutdown_function(static function () use ($path): void {
    if (is_file($path)) {
        @unlink($path);
    }
});

$handle = fopen($path, 'x');
if ($handle === false) {
    imagedestroy($im);
    fwrite(STDERR, "candy-image demo could not create its temp PNG exclusively\n");
    exit(1);
}
fclose($handle);

if (!imagepng($im, $path)) {
    imagedestroy($im);
    fwrite(STDERR, "candy-image demo could not encode the temp PNG\n");
    exit(1);
}
imagedestroy($im);

$exit = Cli::main(
    ['candy-image', '--format=quarter', '--size=48x12', $path],
    STDOUT,
    STDERR,
);

exit($exit);
