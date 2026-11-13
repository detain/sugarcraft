<?php

declare(strict_types=1);

/**
 * Build a tiny synthetic ANIMATED GIF in /tmp — three frames, a coloured
 * band sliding left-to-right (red, green, blue) across a black field —
 * decode it to a cell grid, and hand the frames to Player. Keeps the
 * demo self-contained: no binary GIFs in-repo, nothing downloaded.
 *
 *   php examples/play.php
 *
 * Without a controlling terminal (CI probes) it decodes the GIF and
 * prints a one-line summary instead of launching the interactive UI.
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Player;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;

if (extension_loaded('gd') === false) {
    fwrite(STDERR, "sugar-animation example: ext-gd is required\n");
    exit(1);
}

// Per-run unique name + shutdown cleanup: the old fixed shared path let two
// runs clobber each other's bytes and left the demo GIF in tmp forever.
$path = sys_get_temp_dir() . '/sugar-animation-demo-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.gif';
file_put_contents($path, build_demo_gif());
register_shutdown_function(static function () use ($path): void {
    @unlink($path);
});

$frames = Decoder::decode($path, cellsW: 60, cellsH: 18);

if (!stream_isatty(STDOUT)) {
    fwrite(STDOUT, sprintf("decoded %d frame(s)\n", count($frames)));
    exit(0);
}

$player = new Player($frames);

(new Program($player, new ProgramOptions(useAltScreen: true)))->run();

/**
 * GD's imagegif() only ever writes a single-frame GIF, so build one
 * palette image per frame, capture its bytes, and splice the per-frame
 * payloads behind a shared header + NETSCAPE loop extension + Graphic
 * Control Extensions carrying the frame delays. Every frame allocates
 * its palette (black, ink) in the same order, so the colour tables are
 * identical and one header serves all frames.
 */
function build_demo_gif(): string
{
    $bandColours = [[255, 0, 0], [0, 255, 0], [0, 0, 255]];
    $single = [];

    foreach ($bandColours as $k => [$r, $g, $b]) {
        $im = imagecreate(120, 60);
        imagecolorallocate($im, 0, 0, 0);           // index 0: black field
        $ink = imagecolorallocate($im, $r, $g, $b);  // index 1: band colour
        imagefilledrectangle($im, $k * 40, 0, $k * 40 + 39, 59, $ink);
        ob_start();
        imagegif($im);
        $single[] = (string) ob_get_clean();
        imagedestroy($im);
    }

    // 200 ms per frame (centiseconds), disposal unset, no transparency.
    $gce = "\x21\xF9\x04\x00\x14\x00\x00\x00";

    $gif = 'GIF89a' . substr($single[0], 6, gif_header_length($single[0]) - 6);
    // NETSCAPE2.0 application extension, loop count 0 = forever.
    $gif .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
    foreach ($single as $bytes) {
        $gif .= $gce . gif_frame_payload($bytes);
    }

    return $gif . "\x3B";
}

/** Length of the "GIF8?a" header + Logical Screen Descriptor + global colour table. */
function gif_header_length(string $gif): int
{
    $packed = ord($gif[10]);
    $length = 13;
    if (($packed & 0x80) !== 0) {
        $length += 3 * (2 << ($packed & 0x07));
    }

    return $length;
}

/**
 * Extract one frame: image descriptor (skipping any local table), the
 * LZW minimum code size byte, and every data sub-block up to the
 * zero-length terminator — ready to be re-emitted under its own GCE.
 */
function gif_frame_payload(string $gif): string
{
    $i = gif_header_length($gif);
    if (($i <= 6) || (ord($gif[$i]) !== 0x2C)) {
        throw new RuntimeException('play.php: unexpected GIF layout');
    }

    $descPacked = ord($gif[$i + 9]);
    $j = $i + 10;
    if (($descPacked & 0x80) !== 0) {
        $j += 3 * (2 << ($descPacked & 0x07));
    }
    $j++; // LZW minimum code size byte
    while (($len = ord($gif[$j])) !== 0) {
        $j += 1 + $len;
    }

    return substr($gif, $i, $j - $i + 1);
}
