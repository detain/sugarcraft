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
[`sugarcraft/sugar-animation`](https://github.com/detain/sugarcraft/tree/master/sugar-animation). Point it at a `.gif` on
disk and it decodes via `ext-gd`, sizes the frame grid to your terminal, and
plays the animation in the alternate screen as 24-bit background-coloured
blocks or an ASCII luminance ramp. GIF is the only input format it reads.

```bash
composer require sugarcraft/candy-gifview
candy-gifview my-animation.gif         # solid-block preset (default)
candy-gifview my-animation.gif density # ASCII luminance ramp
```

With a project-local install, invoke the binary as `./vendor/bin/candy-gifview`.
A rolling development phar is published on GitHub Releases under
`candy-gifview/dev` as `candy-gifview.phar`.

The viewer paints with pure SGR background blocks rather than a terminal
graphics protocol (kitty/sixel/iTerm2), so it runs anywhere 24-bit colour
works — inline-protocol rendering is a different route, offered by
`sugar-image`.

## Options

| Option                | Meaning                                                          |
|-----------------------|------------------------------------------------------------------|
| `--fps=N`             | Play at a uniform N frames/second instead of the GIF's delays.    |
| `--speed=N`           | N × the GIF's nominal rate.                                       |
| `--speed=Nfps`        | An absolute rate of N frames/second.                              |
| `--speed=max`         | Burn frames as fast as the scheduler's floors allow.              |
| `--duration=SECONDS`  | Stop after SECONDS of playback (a schedule deadline in the model — no wall-clock kill). Needs at least two frames: a single-frame file combined with `--duration` is refused. |
| `--watch`             | Reload automatically when the source file changes, via a 10 ms event-loop poll. Bare flag — it takes no value. |
| `--paused`            | Start paused on the first frame (step with the arrow keys, `Space` resumes). Bare flag — it takes no value. |
| `--help` / `-h`       | Print the usage line (localized) and exit 0.                      |
| `--version` / `-v`    | Print the version stamp and exit 0.                               |

Flags may appear before or after the positional `<gif>` and preset token.
`--fps` and `--speed` compose: `--fps` sets the uniform base rate and
`--speed` multiplies it (`--fps=10 --speed=2` plays at 20 fps), so the order
of the two flags does not matter. An unknown preset token (a typo like
`densiy`) is refused with an explicit message — the two valid tokens are
`solid` and `density`; a third positional argument is likewise refused.

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

The key table is fixed in the player model; keys are not rebindable.

## Requirements

- **ext-gd** decodes the GIF (declared as a `suggest`). The CLI checks for it
  before touching the file and exits non-zero with a clear message.
- Both presets emit 24-bit truecolor SGR (`38;2`/`48;2`). There is no
  256-color fallback ladder and no `NO_COLOR` / monochrome mode. On a
  terminal without truecolor the blocks degrade (often to default or
  nearby-index colour); the `density` preset's character ramp stays
  legible there and is the better choice. On `TERM=dumb` or otherwise
  capability-poor terminals, expect raw escape sequences rather than a
  usable frame.
- Alternate screen plus an ioctl size query on descriptor 1; off a tty the
  viewport falls back to a deterministic 60×24 cell grid (piped output, CI).
- GIF transparency is composited against the terminal's own background —
  transparent cells render as whatever colour your terminal uses there.

## Limits

- The engine decodes at most 256 frames per animation
  (`Decoder::MAX_FRAMES`); longer files are truncated to the first 256 at
  intake and an explicit notice is printed on stderr. The notice fires
  whenever the decoded list fills the window — a file with exactly 256
  frames also gets it, because until the engine reports the total seen,
  "256 decoded" cannot tell a full window from a truncated stream, and
  over-disclosing is the honest direction. `Cli::MAX_FRAMES`
  (4096) is only the outer belt.
- Intake is also bounded by file size (`Cli::MAX_INPUT_BYTES`, 64 MiB —
  larger files are refused before being buffered) and by a resident-cell
  budget (`Cli::MAX_RESIDENT_CELLS`, 6 million frame cells for the current
  viewport). The engine separately caps a single frame at 100 000 cells and
  the whole canvas at 2 million pixels.
- `--watch` reloads decode synchronously inside the poll tick — a large
  replacement file can stall one tick — and a reload that trips any cap
  above keeps the stale frames rather than blanking the screen.
- Frames are decoded at the launch size: growing the window reveals no
  extra detail until the next file-change reload, while shrinking
  re-clamps and hides.
- Startup decodes before the first frame is painted; a line announcing the
  decode is written to stderr first, so a long wait is at least not silent.
  Quitting restores the terminal's prior contents — the last frame does not
  stay on screen (the viewer runs in the alternate screen).

## Exit codes

| Code | Meaning                                                                |
|------|------------------------------------------------------------------------|
| 0    | `--help`/`--version` printed, or playback ended normally.              |
| 1    | Flag/argument refusal or a decode failure — the message lands on stderr. |
| 128+N | The loop was stopped by signal N (e.g. 143 for SIGTERM).              |

## Accessibility

- The `density` preset encodes luminance as character ramp density, not
  only as colour, so it survives colour-blindness and poor-colour
  terminals better than `solid`.
- Playback can start paused (`--paused`) and is fully steppable by key
  (`←`/`→`) — motion is never mandatory to see content.
- The player caps frame rate at its own scheduler floors; `--speed=max` is
  opt-in, never the default.
- All CLI messages are localized via environment locale
  (`LC_ALL`/`LC_MESSAGES`/`LANG`) across 16 languages; in-app playback
  chrome (the status line) is currently English-only.
- **Reduced motion is not auto-detected**: unlike `sugar-spring`,
  `sugar-toast` and `sugar-overlay`, this viewer does NOT read
  `REDUCE_MOTION`/`PREFERS_REDUCED_MOTION` (nor
  `Probe::reducedMotion()` from `sugar-colorprofile`) — a motion-averse
  user must opt in with `--paused` (or step with `←`/`→`) rather than
  relying on the environment signal. This is a known gap against the
  fleet convention, disclosed here rather than silently ignored; the
  engine exposes no autoplay-suppression seam for the app to honour the
  flag today.

## Architecture

The whole animation engine — `Decoder`, `Frame`, `Renderer`, `Player`, and
the separate `.ansi` art surface (`AnsiArt` / `ArtLibrary` / `ArtLoop`,
which this viewer does not expose) — lives in
[`sugarcraft/sugar-animation`](https://github.com/detain/sugarcraft/tree/master/sugar-animation). This package is only the viewer app:

| File              | Role                                                                 |
|-------------------|----------------------------------------------------------------------|
| `bin/candy-gifview` | Autoload probe, locale wiring (`T::setLocale(T::detect())` before the first message), and a `Cli::main()` hand-off — deliberately a thin shim. |
| `src/Cli.php`     | Argv parsing (`--help`/`--version`, preset token with loud refusal, `--fps`/`--speed`/`--duration`/`--watch`/`--paused`, single-dash refusals), gd preflight, viewport sizing (`viewportCells()` — descriptor 1 behind a core `stream_isatty` gate, two rows reserved for the status line), intake caps (frame belt, 64 MiB file gate, resident-cell budget), decode-failure exits (rescue catches take any `\Throwable` so a raw engine `\Error` still exits 1 loudly, wrapped in one localized `could not play` line; every interpolated echo — paths, argv tokens, and the relayed engine reason (which itself embeds a raw path) — passes a single `printable()` control-char choke before it reaches the terminal), Player wiring, Program launch, and signal re-encoding (`stopSignal()` → 128+N). `$play`, `$watchStat` and `$decode` closure seams keep every branch testable without entering the blocking loop. |
| `src/Support/Version.php` | Resolves the version stamp: the phar's embedded `metadata['version']` when running from a phar (release `x.y.z` or an honest `dev-<sha>`/`dev-build` stamp), falling back to the compiled `CURRENT` constant. |
| `src/Lang.php`    | i18n facade over the shared `T` registry, namespace `gifview` (`cli.usage`, `cli.bad_flag`, `cli.bad_value`, `cli.too_many`, `cli.unexpected_value`, `cli.bad_preset`, `cli.no_frames`, `cli.cannot_play`, `cli.reason_frames_over`, `cli.reason_bytes_over`, `cli.reason_cells_over`, `cli.duration_static`, `cli.need_gd`, `cli.decoding`, `cli.truncated` — all shipping in 16 locales). `cli.no_autoload` mirrors, for parity, the string the bin shim prints before autoload exists, where translation cannot run. |

## Test

```bash
composer install
vendor/bin/phpunit
```

## Credits & inspiration

Design antecedent: [`namzug16/gifterm`](https://github.com/namzug16/gifterm). The decode/render/playback layer it mirrors is developed here as [`sugarcraft/sugar-animation`](https://github.com/detain/sugarcraft/tree/master/sugar-animation); SugarCraft is a native PHP project on the Charm-inspired TEA stack.
