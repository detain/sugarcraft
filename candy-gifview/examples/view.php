<?php

declare(strict_types=1);

/**
 * Build a tiny synthetic animated GIF in /tmp, then play it through the
 * candy-gifview CLI (Cli::main, the same entry point bin/candy-gifview
 * uses). Keeps the demo self-contained — no binary GIFs in-repo. The
 * temporary file is removed again on shutdown.
 *
 *   php examples/view.php
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\GifView\Cli;

if (extension_loaded('gd') === false) {
    fwrite(STDERR, "candy-gifview example: ext-gd is required\n");
    exit(1);
}

$path = (string) tempnam(sys_get_temp_dir(), 'sc_gifview_demo_');
register_shutdown_function(static function () use ($path): void {
    @unlink($path);
});

// A palette-stable 8×8 colour grid: every frame allocates the SAME 64
// colours in the SAME order, so GD writes an identical global colour table
// per frame and the per-frame data blocks are interchangeable when spliced.
$palette = static function (): array {
    $cols = [];
    for ($i = 0; $i < 64; ++$i) {
        $cols[] = [
            (int) (255 * ($i % 8) / 7),
            (int) (255 * intdiv($i, 8) / 7),
            (int) (255 * (($i % 8 + intdiv($i, 8)) % 8) / 7),
        ];
    }

    return $cols;
};

/**
 * Render one frame phase and return its bytes AFTER the GIF header
 * (descriptor + LZW data + block terminator), so the frames can be spliced
 * behind a single shared global colour table.
 */
$frameBlock = static function (int $phase) use ($palette): string {
    $scratch = (string) tempnam(sys_get_temp_dir(), 'sc_gifview_frame_');
    // Palette-native image: imagegif() writes the colour table in allocation
    // order verbatim, so every frame shares one identical global table.
    $im = imagecreate(64, 64);
    $indices = [];
    foreach ($palette() as [$r, $g, $b]) {
        $allocated = imagecolorallocate($im, $r, $g, $b);
        $indices[] = $allocated === false ? 0 : $allocated;
    }
    for ($y = 0; $y < 64; ++$y) {
        for ($x = 0; $x < 64; ++$x) {
            $cx = (intdiv($x, 8) + $phase) % 8;
            $pixel = $indices[$cx + intdiv($y, 8) * 8];
            imagesetpixel($im, $x, $y, $pixel);
        }
    }
    imagegif($im, $scratch);
    imagedestroy($im);

    $bytes = (string) file_get_contents($scratch);
    unlink($scratch);

    // Header = signature + LSD (+ global colour table when present); the
    // LSD flags byte lives at offset 10.
    $flags = ord($bytes[10]);
    $headerEnd = 13 + (($flags & 0x80) !== 0 ? 3 * (2 << ($flags & 7)) : 0);
    $trailer = strrpos($bytes, ';');
    if ($trailer === false || $trailer < $headerEnd) {
        fwrite(STDERR, "candy-gifview example: could not splice GD output\n");
        exit(1);
    }

    return substr($bytes, $headerEnd, $trailer - $headerEnd);
};

// Four frames, 100 ms apart, wrapped in graphic-control extensions so the
// decoder sees authored delays.
$blocks = [$frameBlock(0), $frameBlock(1), $frameBlock(2), $frameBlock(3)];
$gif = (function () use ($palette): string {
    $im = imagecreate(64, 64);
    foreach ($palette() as [$r, $g, $b]) {
        imagecolorallocate($im, $r, $g, $b);
    }
    $scratch = (string) tempnam(sys_get_temp_dir(), 'sc_gifview_head_');
    imagegif($im, $scratch);
    imagedestroy($im);
    $bytes = (string) file_get_contents($scratch);
    unlink($scratch);
    $flags = ord($bytes[10]);
    $headerEnd = 13 + (($flags & 0x80) !== 0 ? 3 * (2 << ($flags & 7)) : 0);

    return substr($bytes, 0, $headerEnd);
})();

foreach ($blocks as $block) {
    // GCE: block size 4, flags (disposal = do not dispose), delay 10
    // centiseconds, no transparent index, block terminator.
    $gif .= "\x21\xF9\x04\x04" . \pack('v', 10) . "\x00\x00" . $block;
}
$gif .= "\x3B";
file_put_contents($path, $gif);

exit(Cli::main(['candy-gifview', $path], STDOUT, STDERR));
