#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SugarVideo example player — runnable demo with synthetic test pattern.
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
if (!class_exists(\SugarCraft\Video\Reel::class)) {
    fwrite(STDERR, "autoload not found: run composer install (or use the monorepo root vendor)\n");
    exit(1);
}

use SugarCraft\Video\Reel;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Synthetic;

function help(string $prog): void
{
    fwrite(STDERR, <<<HELP
Usage: php {$prog} [path|synthetic] [mode]

Play a video file or a built-in synthetic test pattern in the terminal.

Arguments:
  path          Path to a video file (mp4, avi, gif, webm, etc.)
               Use "synthetic" to generate a built-in gradient test pattern.
  mode          Rendering mode (optional, default: auto-detect terminal capability)
               ascii      — grayscale luminance ramp (no color)
               ansi256    — 256-color cube + grey ramp
               truecolor  — 24-bit RGB truecolor (explicit mode; auto never selects it)
               halfblock  — 24-bit half-block characters, 2× vertical resolution
               quarterblock  — 24-bit quarter-block characters, 2×2 sub-cell resolution
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

Environment variables (this example always seeds an explicit size, which
holds until the terminal's first size message overrides it — a live resize
always wins; the library auto-detects only when a Reel opens without withSize()):
  SUGAR_VIDEO_COLS  terminal width in cells (default: 80, clamped to 10-200)
  SUGAR_VIDEO_ROWS  terminal height in rows (default: 24, clamped to 5-80)

Examples:
  php {$prog}
  php {$prog} video.mp4
  php {$prog} video.mp4 halfblock
  SUGAR_VIDEO_COLS=120 SUGAR_VIDEO_ROWS=40 php {$prog} synthetic

HELP
    );
}

// ── CLI argument parsing ──────────────────────────────────────────────────────

$argv = $GLOBALS['argv'] ?? [];
$prog = $argv[0] ?? 'play.php';
$arg1 = $argv[1] ?? '';
$arg2 = $argv[2] ?? '';

if ($arg1 === '--help' || $arg1 === '-h') {
    help($prog);
    exit(0);
}

// A bare invocation runs the built-in synthetic pattern; --help exits 0 (m28).
$pathArg = $arg1 === '' ? 'synthetic' : $arg1;

if (str_starts_with($pathArg, '-')) {
    fwrite(STDERR, "unknown option: {$pathArg}\n");
    exit(1);
}
$modeArg = $arg2 === '' ? 'auto' : $arg2;

if (!in_array($modeArg, ['auto', 'ascii', 'ansi256', 'truecolor', 'halfblock', 'quarterblock', 'sixel', 'kitty', 'iterm2'], true)) {
    help($prog);
    exit(1);
}

$mode = $modeArg === 'auto'
    ? null
    : Mode::from($modeArg);

// Playback paints a live terminal UI, so EVERY invocation - bare, file,
// synthetic, env-sized, explicit mode or auto - needs a TTY on stdout.
// Refuse a pipe up front, before generating or reading any source.
if (!stream_isatty(STDOUT)) {
    fwrite(STDERR, "playback needs a terminal (TTY) on stdout; piping renders nothing useful\n");
    exit(1);
}

// ── Terminal dimensions ───────────────────────────────────────────────────────

$cols = (int) (getenv('SUGAR_VIDEO_COLS') ?: 80);
$rows = (int) (getenv('SUGAR_VIDEO_ROWS') ?: 24);
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

fwrite(STDERR, "SugarVideo — Space=play  q=quit  m=mode  [/] speed  0-9 seek\n");

// The synthetic source is an animated GIF that should loop by default.
// A probe refusal (RuntimeException from VideoSource::probe(): corrupt file,
// or no video stream) is a user-facing outcome, not a bug: print the sentence
// and exit 1, the same class as the not-found path — never a raw fatal+trace.
try {
    $reel = Reel::open($path)
        ->withSize($cols, $rows)
        ->withLoop($pathArg === 'synthetic');

    // Apply mode override if explicitly requested via CLI.
    if ($mode !== null) {
        $reel = $reel->withMode($mode);
    }

    $reel->play();
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
