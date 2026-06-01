# SugarReel

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-reel)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-reel)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-reel?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-reel)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

Terminal **video player** — plays `mp4`/video on the fly as ASCII / ANSI /
truecolor half-block / sixel / kitty. Part of [SugarCraft](https://github.com/detain/sugarcraft).
Decodes frames on the fly and renders each to the terminal, like `mpv -vo tct`.

```sh
composer require sugarcraft/sugar-reel
```

```php
use SugarCraft\Reel\Reel;

Reel::open('clip.mp4'); // playback arrives in later build steps
```

> Status: Step 6 ✓ (Graphics backends sixel/kitty/iterm2 + auto-mode). Each
> graphics protocol delegates to candy-mosaic's Mosaic facade (sixel/kitty/iTerm2
> renderers). RendererFactory::auto() probes Mosaic::diagnose() and selects
> sixel → kitty → iterm2 → half-block → ansi256 → ascii in order of preference.
> Examples, VHS demo, and final polish come in the last step.

## Planned modes

Each frame will render through one of these output modes (auto-selected from the
terminal's capabilities, or forced):

- `ascii` — grayscale luminance ramp.
- `ansi256` — 256-color cube + grey ramp for non-truecolor TTYs.
- `truecolor half-block` — 24-bit `▀` half-blocks, 2× vertical resolution (default).
- `braille` — 2×4 braille dot grid.
- `sixel` — sixel graphics.
- `kitty` — kitty graphics protocol.
- `iterm2` — iTerm2 inline-image protocol.

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
