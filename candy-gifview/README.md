<img src=".assets/icon.png" alt="candy-gifview" width="160" align="right">

# CandyGifView

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-gifview)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-gifview)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-gifview?label=packagist)](https://packagist.org/packages/sugarcraft/candy-gifview)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/view.gif)

Terminal GIF and `.ansi`-art viewer for PHP 8.3+ — the command-line front end built on [`sugarcraft/sugar-animation`](../sugar-animation/). Point it at a `.gif` on disk and it decodes via `ext-gd`, sizes the frame grid to your terminal, and plays the animation in the alternate screen as 24-bit background-coloured blocks or a Unicode luminance ramp.

```bash
composer require sugarcraft/candy-gifview
candy-gifview my-animation.gif         # solid-block preset (default)
candy-gifview my-animation.gif density # ASCII luminance ramp
```

## Keys

| Key                | Action                              |
|--------------------|-------------------------------------|
| `Space`            | Pause / resume                      |
| `←`                | Step back one frame                 |
| `→`                | Step forward one frame              |
| `d`                | Toggle solid ↔ density preset       |
| `q` / `Esc`        | Quit                                |

## Architecture

The whole animation engine — `Decoder`, `Frame`, `Renderer`, `Player`, and the
`.ansi` art surface (`AnsiArt` / `ArtLibrary` / `ArtLoop`) — lives in
[`sugarcraft/sugar-animation`](../sugar-animation/). This package is only the viewer app:

| File              | Role                                                                 |
|-------------------|----------------------------------------------------------------------|
| `bin/candy-gifview` | Autoload probe + `Cli::main()` shim — deliberately two lines of logic. |
| `src/Cli.php`     | Argv parsing (`--help`, preset token), viewport sizing (`viewportCells()` — descriptor 1 behind a core `stream_isatty` gate), decode-failure exits, Player wiring, Program launch. A `$play` closure seam keeps every branch testable without entering the blocking loop. |
| `src/Lang.php`    | i18n facade over the shared `T` registry, namespace `gifview` (`cli.usage`, `cli.no_autoload`). |

## Test

```bash
composer install
vendor/bin/phpunit
```

## Credits & inspiration

Design antecedent: [`namzug16/gifterm`](https://github.com/namzug16/gifterm). The decode/render/playback layer it mirrors is developed here as [`sugarcraft/sugar-animation`](../sugar-animation/); SugarCraft is a native PHP project on the Charm-inspired TEA stack.
