#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SugarReel example player — runnable demo with synthetic test pattern.
 *
 * Usage:
 *   php examples/play.php                    # synthetic gradient pattern
 *   php examples/play.php video.mp4        # play a real video file
 *   php examples/play.php video.mp4 ascii   # force ASCII rendering mode
 *   php examples/play.php --help           # show usage
 *
 * Controls:
 *   Space      — pause / resume
 *   ← / →      — seek backward / forward 10 frames
 *   [ / ]      — decrease / increase speed (0.25 steps, range 0.25–4.0)
 *   0–9        — seek to 0–90% of duration
 *   m          — cycle rendering mode
 *   q / Esc    — quit
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Reel\Reel;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Synthetic;

function help(string $prog): void
{
    fwrite(STDERR, <<<HELP
Usage: php {$prog} [path|synthetic] [mode]

Play a video file or a built-in synthetic test pattern in the terminal.

Arguments:
  path          Path to a video file (mp4, avi, gif, webm, etc.)
               Use "synthetic" or omit to generate a built-in gradient test pattern.
  mode          Rendering mode (optional, default: auto-detect terminal capability)
               ascii      — grayscale luminance ramp (no color)
               ansi256    — 256-color cube + grey ramp
               truecolor  — 24-bit RGB truecolor (default if supported)
               halfblock  — 24-bit half-block characters, 2× vertical resolution
               sixel     — sixel graphics protocol
               kitty     — kitty graphics protocol
               iterm2    — iTerm2 inline image protocol
               auto      — probe terminal and pick the best available mode

Controls:
  Space        pause / resume
  ← →         seek backward / forward 10 frames
  [ ]          decrease / increase speed (0.25–4.0×)
  0–9          seek to 0–90% of video duration
  m            cycle through rendering modes
  q / Esc      quit

Environment variables:
  SUGAR_REEL_COLS  terminal width in cells (default: auto-detect)
  SUGAR_REEL_ROWS  terminal height in rows (default: auto-detect)

Examples:
  php {$prog}
  php {$prog} video.mp4
  php {$prog} video.mp4 halfblock
  SUGAR_REEL_COLS=120 SUGAR_REEL_ROWS=40 php {$prog} synthetic

HELP
    );
}

// ── CLI argument parsing ──────────────────────────────────────────────────────

$argv = $GLOBALS['argv'] ?? [];
$prog = $argv[0] ?? 'play.php';
$arg1 = $argv[1] ?? '';
$arg2 = $argv[2] ?? '';

if ($arg1 === '--help' || $arg1 === '-h' || $arg1 === '') {
    help($prog);
    exit(1);
}

$pathArg = $arg1 === '' ? 'synthetic' : $arg1;
$modeArg = $arg2 === '' ? 'auto' : $arg2;

if (!in_array($modeArg, ['auto', 'ascii', 'ansi256', 'truecolor', 'halfblock', 'sixel', 'kitty', 'iterm2'], true)) {
    help($prog);
    exit(1);
}

$mode = $modeArg === 'auto'
    ? null
    : Mode::from($modeArg);

// ── Terminal dimensions ───────────────────────────────────────────────────────

$cols = (int) (getenv('SUGAR_REEL_COLS') ?: 80);
$rows = (int) (getenv('SUGAR_REEL_ROWS') ?: 24);
$cols = max(10, min($cols, 200));
$rows = max(5, min($rows, 80));

// ── Resolve video source ─────────────────────────────────────────────────────

if ($pathArg === 'synthetic') {
    $path = Synthetic::generate();
    fwrite(STDERR, "[synthetic test pattern: {$path}]\n");
} else {
    if (!is_file($pathArg)) {
        fwrite(STDERR, "file not found: {$pathArg}\n");
        exit(1);
    }
    $path = $pathArg;
}

// ── Run the player via the Reel facade ─────────────────────────────────────────

fwrite(STDERR, "SugarReel — Space=play  q=quit  m=mode  ? for help\n");

// The synthetic source is an animated GIF that should loop by default.
$reel = Reel::open($path)
    ->withSize($cols, $rows)
    ->withLoop($pathArg === 'synthetic');

// Apply mode override if explicitly requested via CLI.
if ($mode !== null) {
    $reel = $reel->withMode($mode);
}

$reel->play();
