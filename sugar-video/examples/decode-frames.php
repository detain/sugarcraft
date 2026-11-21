#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * decode-frames — decode a synthetic animated GIF with the pure-PHP GifDecoder
 * and report each frame, with no terminal and no ffmpeg needed.
 *
 * Usage:
 *   php examples/decode-frames.php [frames]   (default: 4, range 1-64)
 *
 * The example writes its test animation into a private temporary directory and
 * removes it on exit. Exit codes:
 *   0 — every frame decoded
 *   1 — the frame count is not an integer in range
 *   2 — ext-gd is not loaded (the GIF decode path needs it)
 *   3 — the decoder could not open or read the animation
 */

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',      // lib vendor (linked or installed)
    __DIR__ . '/../../vendor/autoload.php',   // monorepo root fallback
];
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}
if (!class_exists(\SugarCraft\Video\Synthetic::class)) {
    fwrite(STDERR, "autoload not found: run composer install (or use the monorepo root vendor)\n");
    exit(1);
}

use SugarCraft\Video\Decode\GifDecoder;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Synthetic;

$arg    = $argv[1] ?? '4';
$frames = filter_var($arg, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 64]]);
if ($frames === false) {
    fwrite(STDERR, "decode-frames: frame count must be an integer from 1 to 64, got '{$arg}'\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "decode-frames: ext-gd is required for GIF decoding\n");
    exit(2);
}

$dir = sys_get_temp_dir() . '/sugar-video-decode-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "decode-frames: cannot create a temporary directory\n");
    exit(3);
}

$status = 0;
try {
    // generate() returns the path it actually wrote, which is the one to open.
    $path = Synthetic::generate($dir . '/synthetic.gif', 64, 32, $frames, 8);

    $decoder = new GifDecoder();
    $decoder->open($path, 40, 12, 10.0, Mode::HalfBlock);

    $decoded = 0;
    while (($frame = $decoder->next()) !== null) {
        $decoded++;
        printf("frame %d: %dx%d px\n", $decoded, $frame->w, $frame->h);
    }
    $decoder->close();

    printf("decoded %d of %d frames\n", $decoded, $frames);
    if ($decoded === 0) {
        $status = 3;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'decode-frames: ' . $e->getMessage() . "\n");
    $status = 3;
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

exit($status);
