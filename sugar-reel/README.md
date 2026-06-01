# SugarReel

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-reel)](https://app.codecov.io/gh/sugarcraft?flags%5B0%5D=sugar-reel)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-reel?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-reel)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

Terminal **video player** — plays `mp4` / `gif` / `avi` / `webm` and more on
the fly, rendering each frame as ASCII, ANSI 256-color, truecolor half-blocks,
or via modern graphics protocols (sixel / kitty / iTerm2). Like `mpv -vo tct`,
but in PHP and reusing the SugarCraft rendering stack throughout.

```sh
composer require sugarcraft/sugar-reel
```

```php
use SugarCraft\Reel\Player;

// Play a video with auto-detected terminal capability.
$player = Player::open('clip.mp4', cols: 80, rows: 24);

// Run it (Space=play, q=quit).
(new \SugarCraft\Core\Program($player))->run();
```

> **Status:** Step 7 ✓ — full implementation with ffmpeg decode pipe,
> pure-PHP GIF fallback, all rendering modes (ascii/ansi256/truecolor/
> half-block/sixel/kitty/iTerm2), delta repaint, seek, speed control,
> and a runnable example.

## Install

```sh
composer require sugarcraft/sugar-reel
```

Requires:
- PHP 8.3+
- `ffmpeg` and `ffprobe` in `$PATH` for `mp4`/`avi`/`webm` playback
- `ext-gd` for `.gif` playback (pure-PHP fallback, no ffmpeg needed)
- A terminal with at least 256-color support for ANSI modes

## Usage

```sh
# Built-in synthetic test pattern (no video file needed)
php examples/play.php

# Play a real video file
php examples/play.php video.mp4

# Force a specific rendering mode
php examples/play.php video.mp4 halfblock
php examples/play.php video.mp4 ascii

# Force auto mode (probe terminal, pick best available)
php examples/play.php video.mp4 auto

# Set terminal dimensions
SUGAR_REEL_COLS=120 SUGAR_REEL_ROWS=40 php examples/play.php
```

### Rendering modes

| Mode | Description | Terminal requirement |
|------|-------------|---------------------|
| `ascii` | Grayscale luminance ramp (` .:-;+=*#@`) | Any |
| `ansi256` | 256-color cube + grey ramp | 256-color |
| `truecolor` | 24-bit RGB truecolor | 24-bit color |
| `halfblock` | 24-bit `▀` half-blocks, 2× vertical resolution | 24-bit color |
| `sixel` | Sixel graphics protocol (DEC) | Sixel-capable |
| `kitty` | Kitty graphics protocol (DCS APC) | Kitty-compatible |
| `iterm2` | iTerm2 inline image (OSC 1337) | iTerm2 / WezTerm |
| `auto` | Probe terminal, pick best available (default) | — |

Auto mode probes the terminal using `Mosaic::diagnose()` (for sixel/kitty/
iTerm2) and falls back to `ColorProfile::detect()` for ANSI modes.

## Keyboard controls

| Key | Action |
|-----|--------|
| `Space` | Pause / resume |
| `←` | Seek backward 10 frames |
| `→` | Seek forward 10 frames |
| `[` | Decrease playback speed (−0.25×, min 0.25×) |
| `]` | Increase playback speed (+0.25×, max 4.0×) |
| `0`–`9` | Seek to 0–90% of video duration |
| `m` | Cycle to next rendering mode |
| `q` / `Esc` | Quit |

## Architecture

```
video file (mp4/gif/avi/webm)
        │
        ▼
┌───────────────────┐     ┌─────────────────┐
│ VideoSource::probe│     │ DecoderFactory  │
│   (ffprobe JSON)  │────▶│ create()        │
└───────────────────┘     └────────┬─────────┘
                                 │
                    ┌────────────┴────────────┐
                    │                        │
               GifDecoder             FfmpegDecoder
               (pure PHP / GD)         (ffmpeg pipe)
                    │                        │
                    └────────────┬───────────┘
                                ▼
                    ┌──────────────────────┐
                    │   RgbFrame (rgb24)    │
                    └──────────┬─────────────┘
                               │
                    ┌─────────┴──────────────┐
                    │   FrameRenderer /       │
                    │   Mosaic bridge         │
                    └─────────┬──────────────┘
                              │
                    ┌─────────┴──────────────┐
                    │   Player (TEA Model)    │
                    │   tick() → view()       │
                    └─────────┬──────────────┘
                              │
                    ┌─────────▼──────────────┐
                    │   Program (candy-core)  │
                    │   raw mode + alt screen│
                    └────────────────────────┘
```

- **Decode:** `FfmpegDecoder` shells out to `ffmpeg` for raw RGB frames (pre-scaled to cell dimensions). `GifDecoder` wraps candy-flip's pure-PHP GIF decoder.
- **Render:** Delegates to candy-mosaic for sixel/kitty/iTerm2. Uses candy-palette for color mapping. Delta repaint via candy-buffer.
- **Pace:** `Cmd::tick()` wall-clock alignment via `Sync`, no busy-waiting.
- **Audio:** `AudioPlayer` shells out to `ffplay` or `mpv --no-video` as the audio master clock.

## Prior art

SugarReel has no single upstream. Its decode → render → pace pipeline draws on
three terminal-video projects, credited here:

- [maxcurzi/tplay](https://github.com/maxcurzi/tplay) — Rust terminal media player.
- [seatedro/glyph](https://github.com/seatedro/glyph) — edge-aware ASCII/ANSI video renderer.
- [joelibaceta/video-to-ascii](https://github.com/joelibaceta/video-to-ascii) — Python video-to-ASCII player.

The rendering stack is reused from the SugarCraft ecosystem rather than
reinvented: [candy-mosaic](../candy-mosaic) (image → cell renderers),
[candy-flip](../candy-flip) (downsampling / dithering), [candy-palette](../candy-palette)
(color mapping), and [candy-core](../candy-core) (TEA runtime + frame pacing).
