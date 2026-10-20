<img src=".assets/icon.png" alt="sugar-animation" width="160" align="right">

# SugarAnimation

> Formerly published as `sugarcraft/candy-flip` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Flip\` → `SugarCraft\Animation\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-animation)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-animation)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-animation?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-animation)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/play.gif)

ANSI/GIF animation toolkit on the SugarCraft stack for PHP 8.3+. Decodes a `.gif` on disk via `ext-gd`, downsamples each frame to a configurable cell grid, and renders the animation into the terminal as 24-bit background-coloured cells (`solid` preset) or shaded Unicode glyphs from a luminance ramp (`density` preset) at a configurable framerate. It also plays back `.ansi` ANSI-art documents — parse, catalog, and timer-free frame pacing (see [ANSI art playback](#ansi-art-playback-ansiart-artlibrary-artloop) below); `.ansi` playback needs no GD at all.

```bash
composer require sugarcraft/sugar-animation
```

Want a ready-made viewer command? [`sugarcraft/candy-gifview`](../candy-gifview/) is the CLI front end built on this library:

```bash
candy-gifview my-animation.gif         # solid-block preset (default)
candy-gifview my-animation.gif density # ASCII luminance ramp
```

## Player keys

| Key                | Action                              |
|--------------------|-------------------------------------|
| `Space`            | Pause / resume                      |
| `←`                | Step back one frame                 |
| `→`                | Step forward one frame              |
| `d`                | Toggle solid ↔ density preset       |
| `q` / `Esc`        | Quit                                |

## ANSI art playback (AnsiArt / ArtLibrary / ArtLoop)

`.ansi` art documents (SGR-escaped text, optionally a multi-frame redraw
stream) parse into a cell model — one `AnsiCell` (glyph + canonical resolved
SGR state, accumulated ECMA-48-style across rows and frames like a real
terminal) per codepoint — that hosts paint into any surface, or replay
verbatim via `AnsiArt::sgrText()`. `ArtLibrary` catalogs a directory of
`logo-<slug>[-skinny][-anim]-<16|256|tc>-<WxH>.ansi` files and resolves a slug
at the terminal's detected colour depth with a kind/depth cascade (wide form
preferred; depth fallback is adjacent-first). `ArtLoop` is pure pacing math:
no timers, `frameAt(elapsedSeconds)` maps wall-clock offset to frame index.

```php
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Animation\AnsiArt;
use SugarCraft\Animation\ArtLibrary;
use SugarCraft\Animation\ArtLoop;

$library = ArtLibrary::fromDir(__DIR__ . '/logo');           // optional ->mergeManifest('logos.jsonl')
$depth   = ArtLibrary::profileTag(ColorProfile::detect());  // 'tc' | '256' | '16'
$art     = $library->pick('art-deco-gilt', $depth);         // ?AnsiArt, cascade-resolved

if ($art !== null && $art->isAnimated()) {
    $loop  = ArtLoop::new($art->frameCount());              // 0.1 s/frame + 2.5 s closing hold
    $frame = $art->frameCells($loop->frameAt(microtime(true) - $startedAt));
    foreach ($frame as $y => $row) {
        foreach ($row as $x => $cell) {
            $surface->put($x, $y, $cell->glyph, $cell->sgr); // paint cells, or echo $art->sgrText($i)
        }
    }
}
```

## Implementation notes

The decoder uses PHP's built-in `imagecreatefromstring()` for in-memory single-frame extraction — no temporary files are written to disk. It walks the GIF89a byte-stream manually to:

1. Parse the Logical Screen Descriptor and Global Color Table (GCT) from the header.
2. Walk the frame stream, extracting each frame's Graphic Control Extension (GCE) delay (a delay of 0 inherits the previous frame's, defaulting to 10cs), disposal method (0–3), and transparent-color index.
3. Extract per-frame Local Color Table (LCT) when present; fall back to the GCT otherwise.
4. Reassemble a minimal single-frame GIF payload in memory and pass it to `imagecreatefromstring()`.
5. Area-average downsample the resulting `GdImage` to the requested cell grid, skipping transparent pixels in the average.

GIF parsing is hand-rolled to avoid loading the entire animation into a `GdImage` at once; each frame is decoded independently. The decoder caps at 256 frames, refuses logical screens larger than 2,000,000 pixels, and caps the output grid at 100,000 cells, so memory usage stays bounded for typical animations and for hostile headers alike.

## Architecture

| File              | Role                                                                                                  |
|-------------------|------------------------------------------------------------------------------------------------------|
| `Decoder`         | Reads the GIF, extracts per-frame GCE delay + disposal + transparency + local color table + image left/top offset, hands each frame to GD via `imagecreatefromstring()`, area-average downsamples the composited canvas to a cell grid, returns a list of {@see Frame}. Compositing handles DISPOSAL_NONE/KEEP (0/1) to leave the canvas, DISPOSAL_BACKGROUND (2) to clear the prior rect, and DISPOSAL_PREVIOUS (3) to restore a canvas snapshot. |
| `Frame`           | Pure value — 2-D RGB grid in cell coordinates with per-frame `$delay` (centiseconds), `$disposal` method (0–3), and `$transparent` flag. |
| `Renderer`        | ANSI emitter. Two presets: `solid` (24-bit background-coloured spaces) or `density` (luminance ramp). `withAdaptiveSize()` queries the TTY via `SizeIoctl` so the output never overflows the viewport; `withConstraints()` accepts explicit row/col limits for testing. |
| `Player`          | SugarCraft Model — index + paused + preset + renderer state, driven by an ABSOLUTE prefix-sum schedule (E-03): each tick arms the DISTANCE to the next frame deadline, so slow renders cost lateness, never drift (late ticks skip ahead). Periods are the per-frame GIF delays (centiseconds) floored at 1cs, or a uniform 1/fps override via `withFixedFps()`; `withMaxSeconds()` bounds total play time with a model-side deadline (no wall-clock kill). Handles `WindowSizeMsg` by re-clamping the renderer to the new viewport (rows-1 for the status line). |
| `TickMsg`         | Frame-tick message produced by the Cmd.                                                            |

The decoder caps at 256 frames and at a 2,000,000-pixel logical screen so a runaway file — or a crafted header — can't OOM the runtime before the first frame is even composited; pause + manual step are always available even on long animations.

## Test

```bash
composer install
vendor/bin/phpunit
```

## Snapshot tests

Rendering output is pinned via `sugar-testing`'s `assertGoldenAnsi` golden-file
snapshots. Any change to the ANSI cell output must be intentional — re-record the
fixture with `UPDATE_GOLDENS=1 vendor/bin/phpunit` to accept a new canonical render.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: [`namzug16/gifterm`](https://github.com/namzug16/gifterm).
