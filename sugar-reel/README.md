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
| `ascii` | Grayscale luminance ramp (` .,:;i1tfLCG08@`) | Any |
| `ansi256` | 256-color cube + grey ramp | 256-color |
| `truecolor` | 24-bit RGB truecolor | 24-bit color |
| `halfblock` | 24-bit `▀` half-blocks, 2× vertical resolution | 24-bit color |
| `sixel` | Sixel graphics protocol (DEC) | Sixel-capable |
| `kitty` | Kitty graphics protocol (APC `\x1b_G`) | Kitty-compatible |
| `iterm2` | iTerm2 inline image (OSC 1337) | iTerm2 / WezTerm |
| `auto` | Probe terminal, pick best available (default) | — |

Auto mode probes the terminal using `Mosaic::diagnose()` (for sixel/kitty/
iTerm2) and falls back to `ColorProfile::detect()` for ANSI modes.

### Luminance ramp selection

ASCII/ANSI256 text modes use a luminance ramp to map pixel brightness to characters.
Three named ramps are available:

| Ramp | Characters | Best for |
|------|-------------|----------|
| `minimal` | ` .:-=+*#%@` | Low-resolution / high contrast |
| `standard` | ` .,:;i1tfLCG08@` | General use (default) |
| `dense` | `` .`^",:;Il!i><~+_-?][}{1)(|\\/tfjrxnuvczXYUJCLQ0OZmwqpdbkhao*#MW&8%B@$`` | High-fidelity ASCII art |

```php
// Use the dense ramp for more detailed ASCII output
Reel::open('video.mp4')->withRamp('dense')->play();
```

## Remote & embedded playback

### Authenticated / signed streams

`Reel::openUrl()` plays an `http(s)` stream and re-presents request headers on
every open and every decoder rebuild (a seek or a resize re-spawns `ffmpeg`, so
a short-lived signed URL or bearer token must be re-sent each time):

```php
use SugarCraft\Reel\Reel;

Reel::openUrl(
    'https://cdn.example.com/private/clip.mp4',
    headers: [
        'Authorization' => 'Bearer <token>',
        'User-Agent'    => 'my-app/1.0',
    ],
    allowedHosts: ['cdn.example.com'], // optional host allowlist
)->play();
```

Headers are validated at the boundary: a name or value containing a CR, LF or
NUL (HPP / request-splitting), an empty name, a colon in the name, or a
malformed field line all throw `InvalidArgumentException` before `ffmpeg` is
ever spawned. **Migration note:** the second parameter used to be
`$allowedHosts`; it is now `$headers` (prefer named arguments). The old
positional shape fails loudly — a positional host list is rejected outright as
the legacy `allowedHosts` shape (its own migration message), `null` as a
`TypeError`. A `User-Agent` value is passed to
`ffmpeg -user_agent`; every other header rides
in a single `-headers` block. Both flags are emitted only for network sources —
for a local file they are dropped and a reason is logged. Non-`http(s)` URL
schemes (`rtsp://`, `rtmp://`, …) are not opened by `openUrl()`. Calling
`openUrl()` without `allowedHosts:` also logs a one-line SSRF advisory
(`ssrf.no_allowlist`, host only — never the path or query): ffmpeg resolves DNS
and follows redirects, so an unbounded remote URL can reach internal hosts.

```php
// Mutate headers fluently on an existing Reel (validated the same way):
$reel = $reel->withHeaders(['Cookie' => 'session=…']);
```

### Embedding the player without owning the loop

`Reel::play()` builds its own candy-core `Program` and blocks. To drive playback
from a host that already owns a `Program`/event loop, use `Reel::toPlayer()` to
get the TEA `Player` model **paused** and mount it yourself. Building the model
opens the source, so its `ffmpeg` child is spawned immediately — a host that
mounts and never starts must still call `stop()` to release it.

```php
$player = Reel::openUrl($url, $headers)->toPlayer(); // paused; decoder child already spawned

// Mount $player in your own Program, or fold it into a larger Model.
// When the host leaves the player screen, release the child processes:
$player->stop(); // idempotent — stops audio companion + closes decoder/ffmpeg
```

**Host contract** — the caller that owns the loop is responsible for:

- **Resize:** forward terminal resizes to the player as a `WindowSizeMsg`.
  `Player` clamps columns to `[10, 200]` and rows to `[5, 80]` (the
  `MIN_COLS`/`MAX_COLS`/`MIN_ROWS`/`MAX_ROWS` constants) and ignores a resize
  that does not change the clamped cell grid. A clamp change rebuilds the
  decoder **off the render hot path** (in `update`, never in `view`).
- **Quit keys:** decide when to quit. Standalone playback quits on `q`, `Esc`,
  or `Ctrl-C` and each of those paths calls `Player::stop()` first; a host
  embedding the player may route different keys but must still call `stop()` on
  teardown so no `ffmpeg`/audio child is orphaned.
- **Tick:** the player self-schedules with `Cmd::tick()` while playing; do not
  poll it from a separate timer. `toPlayer()` returns it **paused** — call
  `$player->play()` (or send `Space`) to start.
- **Source binding:** `toPlayer()` requires a bound source (a path or URL). An
  unbound `Reel::new()` throws `InvalidArgumentException` rather than
  silently substituting the synthetic pattern that `play()` uses.

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
| `resize` | Terminal resize (SIGWINCH) re-scales video automatically |
| `loop` | Loop is set at open time via `Reel::new()->withLoop(true)->play()` (no keyboard shortcut) |

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

## Known limitations

- **Audio plays at 1.0× regardless of playback speed.** Changing speed with `[`/`]` only affects video pacing. The audio companion (ffplay/mpv) always plays at normal speed. A/V will diverge noticeably when using speeds other than 1.0×.

- **Seeking repositions audio but not frame-exactly.** A seek creates a new AudioPlayer at the correct offset, but the video frame timing and audio timing are only approximately synchronized (frame-skip resync keeps them close at 1.0×).

- **GIF playback fills the terminal in HalfBlock mode** (each cell = 2 source rows). In text modes (ascii/ansi256/truecolor) the GIF renders at its native pixel dimensions without 2× vertical scaling.

- **Seek is fast, slightly less frame-exact (keyframe snap).** `seekToSeconds()`
  uses ffmpeg *input* seeking (`-ss` placed before `-i`), which decodes from the
  keyframe at or just before the requested time rather than walking the whole
  file. That is what makes scrubbing a multi-GB network stream instant; the
  trade-off is that the landed frame can be a touch less frame-exact than output
  seeking, and `videoTime`/`frameIndex` are set to the requested target, so the
  on-screen clock can lead the first displayed frame by up to one GOP on
  sparsely-keyed sources. Index-based backward seeks (`withSeek()` to an earlier
  frame) instead reopen from t0 and decode forward, which is frame-exact.

- **Audio pause/seek on Windows.** `AudioPlayer` no longer relies on
  `SIGSTOP`/`SIGCONT` (which are absent on Windows and unreliable under a PTY):
  pause terminates the subprocess and resume re-spawns `ffplay`/`mpv` from the
  banked playback position (`-ss`/`--start`). The trade-off is a short
  re-open latency on resume, and the audio clock is quantised to the last
  pause boundary rather than frame-exact.

- **Authenticated video, unauthenticated audio.** The request headers given to
  `Reel::openUrl()` ride on ffmpeg's video input (`-headers`/`-user_agent`) but
  are NOT forwarded to the audio companion — `ffplay`/`mpv` spawn with the bare
  URL. On a signed or 403-gated stream the video plays while audio silently
  fails to connect (treat such sources as video-only until audio headers are
  wired; `HttpHeaders::toMpvHeaderFields()` already renders the mpv form for
  that future work).

- **Decode is synchronous with an optional per-tick budget.** `next()` reads the
  ffmpeg stdout pipe — non-blocking, behind a bounded `stream_select()` deadline —
  so a stalled network source surfaces as end-of-stream instead of hanging the
  loop. A tick that falls behind by many frames will decode the catch-up frames
  inline; set a budget via `Player::open(..., frameBudgetMs: 8.0)` to cap that
  work per tick (remaining catch-up converges over subsequent ticks instead of
  blocking one). The budget is checked between frames, so it cannot preempt a
  single frame decode already in flight; per-read bounding comes from the pipe
  timeout.
