<?php

declare(strict_types=1);

/**
 * Build a tiny synthetic animated GIF in /tmp, then drop into candy-flip
 * to play it. Keeps the demo self-contained — no binary GIFs in-repo.
 *
 *   php examples/play.php
 */
require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Flip\Decoder;
use CandyCore\Flip\Player;

if (!extension_loaded('gd')) {
    fwrite(STDERR, "candy-flip example: ext-gd is required\n");
    exit(1);
}

$path = sys_get_temp_dir() . '/candy-flip-demo.gif';

// Build a single-frame rainbow GIF — the decoder fans it out to one
// frame, which is plenty to demonstrate the player UI.
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

$frames = Decoder::decode($path, cellsW: 60, cellsH: 18);
$player = new Player($frames, interval: 0.08);

(new Program($player, new ProgramOptions(useAltScreen: true)))->run();
