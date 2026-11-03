<img src=".assets/icon.png" alt="candy-flappy" width="160" align="right">

# CandyFlappy

> Formerly published as `sugarcraft/honey-flap` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Flap\` → `SugarCraft\Flappy\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-flappy)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-flappy)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-flappy?label=packagist)](https://packagist.org/packages/sugarcraft/candy-flappy)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/play.gif)

candy-flappy — a Flappy-Bird-style game for the terminal, built on the SugarCraft stack, for PHP 8.3+. The bird's vertical motion is a SugarSpring projectile (gravity + an upward velocity kick on each tap), pipes scroll left at a fixed cell rate, collision is per-cell.

## Run it

```bash
composer install
./bin/candy-flappy
```

## Keys

| Key                  | Action                                                |
|----------------------|-------------------------------------------------------|
| `Space` / `Enter` / `↑` / `w` | Flap (the first flap also starts the run)  |
| `r`                  | Restart — after a crash or mid-flight; lands on the ready card |
| `q` / `Esc` / `Ctrl+C` | Quit                                              |

The board opens on a **ready card**: the bird hovers and the world is frozen
until your first flap, so launching never drops you into the floor. Quitting
mid-flight with a qualifying score settles it onto the leaderboard before the
process exits — you do not have to die to keep your best.

## Terminal requirements

The framed world is a fixed 62 x 21 cells (`Game::MIN_COLS` x `Game::MIN_ROWS`).

- **Smaller viewport** — a plain "needs a 62x21 terminal" notice replaces the
  board and the world freezes (no ticks burn on an unplayable frame). Resize
  back and the game resumes in place; `r` never snaps the layout away.
- **`TERM=dumb` / no TTY** (`ColorProfile::NoTty`) — candy-flappy refuses to
  play with a plain message instead of spraying escape codes into a terminal
  that cannot render them.
- **ASCII-only terminal** (`ColorProfile::Ascii`, e.g. `NO_COLOR` on a plain
  tty) — same layout in plain glyphs: `+--+` borders, `#` pipes, no emoji,
  no colour.
- **Colour tiers** — 16/256-bit/TrueColor palettes degrade through
  sugar-style; the status hint collapses to a short form before any line
  could wrap past the window width.

## Architecture

| File            | Role                                                                           |
|-----------------|--------------------------------------------------------------------------------|
| `Bird`          | Wraps a SugarSpring `Projectile` — gravity pulls it down, `flap()` resets vertical velocity to a fixed kick, and fall speed is capped at terminal velocity. |
| `Pipe`          | Single-column pipe pair with a centred gap. Slides left one cell per tick.     |
| `TickMsg`       | Frame-tick message fired by the `game-tick` subscription timer (`Game::TICK_SECONDS`, 1/30 s exactly). |
| `Game` (Model)  | Pure-state world: bird + pipes + score + crashed flag. Injects PRNG closure for deterministic gap placements in tests. |
| `Renderer`      | Pure view — single playfield walk, ANSI-styled glyphs, rounded border.         |
| `PipeGenerator` | Generates pipes with variable gap height — gap shrinks as score increases, raising difficulty. Gap starts at 6 cells, shrinks by 1 every 5 points, floors at 3. |

The PRNG is injected as a `Closure(int $maxInclusive): int` so unit tests can pin the pipe layout to a specific sequence — the standard SugarCraft pattern.

## Difficulty scaling

The pipe gap height adapts to the player's score:

| Score range | Gap height |
|-------------|------------|
| 0–4         | 6 cells    |
| 5–9         | 5 cells    |
| 10–14       | 4 cells    |
| 15+         | 3 cells    |

Gap shrinks by 1 every 5 points, bottoming out at 3 cells to keep the game playable. This is implemented by `PipeGenerator::gapHeightForScore()` and applied automatically when `Game` spawns new pipes via `PipeGenerator::makePipe()`.

## Bird physics & tuning

The bird's vertical motion is a SugarSpring `Projectile` in the Y-down convention (positive velocity = falling). Three constants on `Bird` tune the feel for an 18-row playfield at 30 ticks/sec:

| Constant             | Value        | Role                                                        |
|----------------------|--------------|-------------------------------------------------------------|
| `Bird::FLAP_KICK`    | `-10.0`      | Upward velocity (cells/sec) applied by a flap.              |
| `Bird::GRAVITY`      | `18.0`       | Constant downward acceleration (cells/sec²).                |
| `Bird::TICKS_PER_SEC`| `30`         | Simulation rate baked into the projectile's `deltaTime`.    |

Fall speed is clamped to `Projectile::TERMINAL_GRAVITY` (53 cells/sec) on every tick, so an uninterrupted drop can never accumulate enough velocity to teleport the bird several rows in a single frame and skip a pipe without a collision.

## Crash rules

The game ends on any of three contacts, all handled identically:

- **Floor** — the bird's row reaches `Game::HEIGHT` (row ≥ 18).
- **Top wall** — the bird's row goes above the ceiling (row < 0). A hard flap into the top edge crashes just like the floor.
- **Pipe** — the bird's cell lands outside a pipe's open gap.

## High-score persistence

On a game-over that beats the current best, the new score is merged into a leaderboard and written to `scores.json` under the config dir (`$XDG_CONFIG_HOME` or `$HOME/.config`, in `.candy-flappy/`). The list is bounded to the top `Game::MAX_HIGH_SCORES` (10) entries. The write runs off the synchronous `update()` path via a `Cmd` and swallows I/O errors, so a full or unwritable disk can never crash the render loop. The file itself is published through sugar-core's `AtomicJsonFile` (flock + temp-and-rename, `0600` mode) — never a bare truncate-write. Saved scores are loaded on the next `Game::start()`, which **fails soft**: a corrupt, unreadable, or oversized (over `Game::MAX_SCORE_FILE_BYTES`) save is quarantined aside as `scores.json.corrupt-<timestamp>` and play starts with an empty board, so a bad file can never brick launch. Entries are legality-clamped on read: non-integers, zero, negatives, and scores of `Game::SCORE_CEILING` or above are dropped.

## Test

```bash
composer install
vendor/bin/phpunit
```

## Snapshot tests

Game frame output is pinned via `sugar-testing`'s `assertGoldenAnsi` golden-file
snapshots. Any change to the ANSI playfield output must be intentional — re-record the
fixture with `--update-golden` to accept a new canonical render.

## Credits & inspiration

Design antecedent: [kbrgl/flapioca](https://github.com/kbrgl/flapioca); SugarCraft is developed as a native PHP project.
