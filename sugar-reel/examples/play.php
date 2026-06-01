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

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\Mode;

const SYNTHETIC_GIF_PATH = '/tmp/sugar-reel-synthetic.gif';

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

/**
 * Build a 120×60 rainbow gradient GIF in /tmp and return its path.
 * The GifDecoder fans this single frame out so the player loops over
 * it repeatedly — enough to show all the rendering modes working.
 */
function buildSyntheticGif(): string
{
    if (!extension_loaded('gd')) {
        fwrite(STDERR, "synthetic test pattern requires ext-gd\n");
        exit(1);
    }

    $w = 120;
    $h = 60;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            // Smooth rainbow: R sweeps left→right, G sweeps top→bottom,
            // B counter-modulates to produce a full color wheel.
            $r = (int) min(255, 255 * $x / $w);
            $g = (int) min(255, 255 * $y / $h);
            $b = (int) min(255, 255 * (($x + $y) % $w) / $w);
            $col = imagecolorallocate($im, $r, $g, $b);
            imagesetpixel($im, $x, $y, $col);
        }
    }
    imagegif($im, SYNTHETIC_GIF_PATH);
    imagedestroy($im);

    return SYNTHETIC_GIF_PATH;
}

// ── CLI argument parsing ──────────────────────────────────────────────────────

$argv = $GLOBALS['argv'] ?? [];
$prog = $argv[0] ?? 'play.php';

if (($argv[1] ?? '') === '--help' || $argv[1] === '-h' || $argv[1] === '') {
    help($prog);
    exit(1);
}

$pathArg = $argv[1] ?? 'synthetic';
$modeArg = $argv[2] ?? 'auto';

if (!in_array($modeArg, ['auto', 'ascii', 'ansi256', 'truecolor', 'halfblock', 'sixel', 'kitty', 'iterm2'], true)) {
    help($prog);
    exit(1);
}

$mode = $modeArg === 'auto' || $modeArg === 'auto'
    ? null
    : Mode::from($modeArg);

// ── Terminal dimensions ───────────────────────────────────────────────────────

$cols = (int) ($_ENV['SUGAR_REEL_COLS'] ?? 80);
$rows = (int) ($_ENV['SUGAR_REEL_ROWS'] ?? 24);
$cols = max(10, min($cols, 200));
$rows = max(5, min($rows, 80));

// ── Resolve video source ─────────────────────────────────────────────────────

if ($pathArg === 'synthetic') {
    $path = buildSyntheticGif();
    fwrite(STDERR, "[synthetic test pattern: {$path}]\n");
} else {
    if (!is_file($pathArg)) {
        fwrite(STDERR, "file not found: {$pathArg}\n");
        exit(1);
    }
    $path = $pathArg;
}

// ── Open player ───────────────────────────────────────────────────────────────

// VideoSource::probe() and DecoderFactory::create() are called inside
// Player::open(). They gracefully degrade when ffprobe is absent.
$player = Player::open($path, $cols, $rows);

// Override mode if explicitly requested.
if ($mode !== null) {
    // Player starts paused (Space to play). Mutate to set the requested mode.
    $player = $player->mutate(['mode' => $mode]);
}

// ── Run the TEA player ─────────────────────────────────────────────────────────

$options = new ProgramOptions(
    useAltScreen: true,
    hideCursor: true,
);

fwrite(STDERR, "SugarReel — Space=play  q=quit  m=mode  ? for help\n");
(new Program($player, $options))->run();
