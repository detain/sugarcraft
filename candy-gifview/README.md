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

Terminal GIF viewer for PHP 8.3+ — the command-line front end built on
[`sugarcraft/sugar-animation`](../sugar-animation/). Point it at a `.gif` on
disk and it decodes via `ext-gd`, sizes the frame grid to your terminal, and
plays the animation in the alternate screen as 24-bit background-coloured
blocks or an ASCII luminance ramp.

```bash
composer require sugarcraft/candy-gifview
candy-gifview my-animation.gif         # solid-block preset (default)
candy-gifview my-animation.gif density # ASCII luminance ramp
```

With a project-local install, invoke the binary as `./vendor/bin/candy-gifview`.

## Options

| Option                | Meaning                                                          |
|-----------------------|------------------------------------------------------------------|
| `--fps=N`             | Play at a uniform N frames/second instead of the GIF's delays.    |
| `--speed=N`           | N × the GIF's nominal rate.                                       |
| `--speed=Nfps`        | An absolute rate of N frames/second.                              |
| `--speed=max`         | Burn frames as fast as the scheduler's floors allow.              |
| `--duration=SECONDS`  | Stop after SECONDS of playback (a schedule deadline in the model — no wall-clock kill). |
| `--watch`             | Reload automatically when the source file changes, via a 10 ms event-loop poll. Bare flag — it takes no value. |
| `--help` / `-h`       | Print the usage line (localized) and exit 0.                      |

Flags may appear before or after the positional `<gif>` and preset token.
When both `--fps` and `--speed` are given, the last one wins. An unknown
preset token (a typo like `densiy`) silently falls back to `solid`; a third
positional argument is refused.

A GIF's authored NETSCAPE loop count is honoured by default: `raw N`
means N extra repeats after the first play (so N+1 total passes) and `raw 0`
— like a file with no loop extension at all, the deliberate divergence from
libnsgif's single-play default — plays forever.

## Keys

| Key                    | Action                         |
|------------------------|--------------------------------|
| `Space`                | Pause / resume                 |
| `←`                    | Step back one frame            |
| `→`                    | Step forward one frame         |
| `d`                    | Toggle solid ↔ density preset  |
| `q` / `Esc` / `Ctrl+C` | Quit                           |

## Requirements

- **ext-gd** decodes the GIF (declared as a `suggest`; without it the
  decoder exits non-zero with a clear message).
- Both presets emit 24-bit truecolor SGR (`38;2`/`48;2`). There is no
  256-color fallback ladder and no `NO_COLOR` / monochrome mode.
- Alternate screen plus an ioctl size query on descriptor 1; off a tty the
  viewport falls back to a deterministic 60×24 cell grid (piped output, CI).
- GIF transparency is composited against the terminal's own background —
  transparent cells render as whatever colour your terminal uses there.

## Limits

- The whole animation is materialised in RAM while playing, so intake is
  capped at 4096 frames (`Cli::MAX_FRAMES`): over-cap files are refused
  loudly at launch, and under `--watch` a reload over the cap keeps the
  stale frames.
- `--watch` reloads decode synchronously inside the poll tick — a large
  replacement file can stall one tick.
- Frames are decoded at the launch size: growing the window reveals no
  extra detail until the next file-change reload, while shrinking
  re-clamps and hides.
- Startup decodes before the first frame is painted; large files block
  with no "loading…" affordance. Quitting after the loop count completes
  keeps the last frame on screen.

## Exit codes

| Code | Meaning                                                                |
|------|------------------------------------------------------------------------|
| 0    | `--help` printed, or playback ended normally.                          |
| 1    | Flag/argument refusal or a decode failure — the message lands on stderr. |

## Architecture

The whole animation engine — `Decoder`, `Frame`, `Renderer`, `Player`, and
the separate `.ansi` art surface (`AnsiArt` / `ArtLibrary` / `ArtLoop`,
which this viewer does not expose) — lives in
[`sugarcraft/sugar-animation`](../sugar-animation/). This package is only the viewer app:

| File              | Role                                                                 |
|-------------------|----------------------------------------------------------------------|
| `bin/candy-gifview` | Autoload probe + `Cli::main()` shim — deliberately two lines of logic. |
| `src/Cli.php`     | Argv parsing (`--help`, preset token, `--fps`/`--speed`/`--duration`/`--watch`), viewport sizing (`viewportCells()` — descriptor 1 behind a core `stream_isatty` gate), the 4096-frame intake cap, decode-failure exits (rescue catches take any `\Throwable` so a raw engine `\Error` still exits 1 loudly), Player wiring, Program launch. `$play`, `$watchStat` and `$decode` closure seams keep every branch testable without entering the blocking loop. |
| `src/Lang.php`    | i18n facade over the shared `T` registry, namespace `gifview` (`cli.usage`, `cli.bad_flag`, `cli.bad_value`, `cli.too_many`, `cli.unexpected_value` — all shipping in 16 locales). `cli.no_autoload` mirrors, for parity, the string the bin shim prints before autoload exists, where translation cannot run. |

## Test

```bash
composer install
vendor/bin/phpunit
```

## Credits & inspiration

Design antecedent: [`namzug16/gifterm`](https://github.com/namzug16/gifterm). The decode/render/playback layer it mirrors is developed here as [`sugarcraft/sugar-animation`](../sugar-animation/); SugarCraft is a native PHP project on the Charm-inspired TEA stack.
