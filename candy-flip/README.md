<img src=".assets/icon.png" alt="candy-flip" width="160" align="right">

# CandyFlip

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-flip)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-flip)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-flip?label=packagist)](https://packagist.org/packages/sugarcraft/candy-flip)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/play.gif)

ASCII GIF viewer on the SugarCraft stack — port of [`namzug16/gifterm`](https://github.com/namzug16/gifterm). Decodes a `.gif` on disk via `ext-gd`, downsamples each frame to a configurable cell grid, and renders the animation into the terminal as ANSI-coloured Unicode block-glyphs at a configurable framerate.

```bash
composer require sugarcraft/candy-flip
candy-flip my-animation.gif         # solid-block preset (default)
candy-flip my-animation.gif density # ASCII luminance ramp
```

## Keys

| Key                | Action                              |
|--------------------|-------------------------------------|
| `Space`            | Pause / resume                      |
| `←`                | Step back one frame                 |
| `→`                | Step forward one frame              |
| `d`                | Toggle solid ↔ density preset       |
| `q` / `Esc`        | Quit                                |

## Implementation notes

The decoder uses PHP's built-in `imagecreatefromstring()` for in-memory single-frame extraction — no temporary files are written to disk. It walks the GIF89a byte-stream manually to:

1. Parse the Logical Screen Descriptor and Global Color Table (GCT) from the header.
2. Walk the frame stream, extracting each frame's Graphic Control Extension (GCE) delay, disposal method (0–3), and transparent-color index.
3. Extract per-frame Local Color Table (LCT) when present; fall back to the GCT otherwise.
4. Reassemble a minimal single-frame GIF payload in memory and pass it to `imagecreatefromstring()`.
5. Area-average downsample the resulting `GdImage` to the requested cell grid, skipping transparent pixels in the average.

GIF parsing is hand-rolled to avoid loading the entire animation into a `GdImage` at once; each frame is decoded independently so memory usage stays bounded regardless of input size.

## Architecture

| File              | Role                                                                                                  |
|-------------------|------------------------------------------------------------------------------------------------------|
| `Decoder`         | Reads the GIF, extracts per-frame GCE delay + disposal + transparency + local color table, hands each frame to GD via `imagecreatefromstring()`, area-average downsamples to a cell grid, returns a list of {@see Frame}. |
| `Frame`           | Pure value — 2-D RGB grid in cell coordinates with per-frame `$delay` (centiseconds), `$disposal` method (0–3), and `$transparent` flag. |
| `Downsampler`     | Image downsampler with two modes: `NEAREST` (center-pixel sample) and `AREA_AVERAGE` (area-weighted RGB average — higher quality, default). |
| `Dither\FloydSteinberg` | Floyd-Steinberg error-diffusion dithering against a fixed palette. Source image is not modified; returns a new `GdImage`. |
| `Renderer`        | ANSI emitter. Two presets: `solid` (24-bit `█` blocks) or `density` (luminance ramp). `withAdaptiveSize()` queries the TTY via `SizeIoctl` so the output never overflows the viewport; `withConstraints()` accepts explicit row/col limits for testing. |
| `Cache/FrameCache` | WeakMap-backed memoization cache keyed by `Frame` object identity. Identical frames skip the rendering step on re-playback, and entries are dropped automatically when the `Frame` is garbage-collected. |
| `Player`          | SugarCraft Model — index + paused + preset state. `Cmd::tick(...)` schedules frame advance using per-frame delays. |
| `TickMsg`         | Frame-tick message produced by the Cmd.                                                            |

The decoder caps at 256 frames so a runaway file can't OOM the runtime; pause + manual step are always available even on long animations.

## Test

```bash
composer install
vendor/bin/phpunit
```

## Snapshot tests

Rendering output is pinned via `candy-testing`'s `assertGoldenAnsi` golden-file
snapshots. Any change to the ANSI cell output must be intentional — re-record the
fixture with `--update-golden` to accept a new canonical render.
