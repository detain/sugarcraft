<img src=".assets/icon.png" alt="candy-image" width="160" align="right">

# CandyImage

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-image)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-image)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-image?label=packagist)](https://packagist.org/packages/sugarcraft/candy-image)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

![demo](.vhs/candy-image.gif)

Terminal image viewer for PHP 8.3+ — the **chafa-independent** front end
built on [`sugarcraft/sugar-image`](../sugar-image/). Point it at an image
file and it detects your terminal, picks the best protocol, and writes the
rendered bytes to stdout. This is the F-18 surface: 45 chafa CLI flags
distilled to the ones the SugarCraft core can actually honor — and no
shell-out to `chafa`, ever.

```bash
composer require sugarcraft/candy-image
candy-image photo.png                    # auto-detect + render
candy-image --dump-detect                # why is my image blank?
candy-image --format=half --size=80x24 logo.png
candy-image --format=symbols --symbols=ascii logo.png   # ASCII-art tier
candy-image --format=symbols --canvas=ansi256 --work=7 logo.png
```

## Options

| Option                  | Meaning                                                          |
|-------------------------|------------------------------------------------------------------|
| `--format=F`            | Force a protocol: `auto` `sixel` `kitty` `iterm2` `half` (`halfblock`) `quarter` (`quarterblock`) `ascii` `ansi16` `ansi256` `truecolor` `symbols`. |
| `--symbols=CLASS[+CLASS…]` | Select the symbol tier's glyph corpus with chafa's tag-selector grammar (`all`, `block`, `border`, `braille`, `ascii`, `latin`, `legacy`, `sextant`, `octant`, `technical`, `geometric`, `stipple`, `wedge`, …; `+` adds, `-` removes, a leading sign is relative to the default corpus). Default: sugar-image's byte-frozen 676-glyph corpus. |
| `--canvas=MODE`         | Colour canvas for the text tiers: `truecolor` (default) `ansi256` `ansi16` `fgbg`. A no-op on backends without the dial. |
| `--work=N`              | Candidate-search effort: chafa's integer `1`–`9` (config float = N/10) or the sugar-image dial `0.0`–`1.0`; `9` runs the exhaustive path. |
| `--size=WxH`            | Render box in terminal cells (chafa spelling).                    |
| `--width=N` / `--height=N` | Individual box legs; cannot be mixed with `--size`. Defaults: viewport columns × aspect-derived rows. |
| `--scale=S`             | Fit policy: `fit` `shrink` `fill` `stretch` `none` `crop` (maps onto `SugarCraft\Image\Scale`). |
| `--animate`             | Animated-GIF pass-through into sugar-animation's `Player` (alternate screen). Full playback controls live in `candy-gifview`. |
| `--passthrough=V`       | tmux `allow-passthrough` transaction: `auto` (default) or `none`. |
| `--polite`              | Accepted no-op for chafa muscle-memory — this viewer always renders inline and synchronously. |
| `--dump-detect`         | Print the resolved terminal state: env table, TermDb chain + claims, Detect capability, cell pixel size, default fg/bg, DA1 reply, `auto()` selection, palette report. Exits 0. |
| `--version` / `--help`  | Print the version / usage and exit 0.                             |

## Chafa flag-compat map (what this base honors and why)

| chafa flag       | status here | reason |
|------------------|-------------|--------|
| `--format`       | ✅ subset    | every `Mosaic::fromModeString()` arm except `chafa` (refused by design — F-18's point is a viewer that never shells out). |
| `--size`         | ✅          | cells; `--width`/`--height` as explicit-leg aliases. |
| `--dump-detect`  | ✅          | chafa's debugging gold, re-expressed over `Detect` + `TermDb` + the palette probe. |
| `--passthrough`  | ✅ `auto`/`none` | `tmux` (force) and `screen` (the S7.1 ScreenPassthrough decorator) refuse with a pointer — no seam on this base yet. |
| `--polite`       | ✅ no-op    | sugar-image has no async worker model; every render is already "polite". |
| `--symbols`      | ✅ tag classes | chafa's `--symbols=` class grammar over the ported narrow corpus (1,260 glyphs: ASCII, Latin, block table, legacy, misc, braille, sextant, octant). `[chars]` literal sets and codepoint ranges are not implemented. |
| `--canvas` / `--work` | ✅ (REACH-A04) | the A-07 canvas and A-08 work-factor dials, threaded onto whichever backend speaks them. |
| `--probe=off`    | ⏭ refuses   | `Detect` exposes no env-only probe seam yet. |
| everything else  | ⏭ refuses   | out of scope for a thin composition-only viewer (façade law): the capability stays in the libs. |

## Exit codes

| Code | Meaning |
|------|---------|
| 0    | success (including `--help` / `--version` / `--dump-detect`) |
| 1    | usage error — unknown flag, bad value, refused format, too many files |
| 2    | input/decode error — missing file, undecodable image, empty animation, render failure |

## Architecture

`bin/candy-image` is a two-line shim; all branches live in
`SugarCraft\CandyImage\Cli` and are driven in-process by the tests (in-memory
pipes, plus a `?\Closure $play` seam for the animation loop). Detection,
protocol selection, decode, and scaling are **only ever** sugar-image calls
— no render logic may land in this package (façade law). The namespace is
`SugarCraft\CandyImage\` rather than the mechanical `SugarCraft\Image\`
because the latter is sugar-image's own — a ruling recorded in
`PROJECT_NAMES.md`.

## Keys (while `--animate` plays)

| Key    | Action |
|--------|--------|
| `q` / `Esc` / `Ctrl+C` | quit |

## Localized

CLI messages ship through the sugar-core i18n lookup from `lang/en.php`
(namespace `image`); additional locales follow the repo's add-locale flow.
