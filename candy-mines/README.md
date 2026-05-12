<img src=".assets/icon.png" alt="candy-mines" width="160" align="right">

# CandyMines

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-mines)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-mines)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-mines?label=packagist)](https://packagist.org/packages/sugarcraft/candy-mines)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/play.gif)

Minesweeper on the SugarCraft stack — port of [`maxpaulus43/go-sweep`](https://github.com/maxpaulus43/go-sweep). Customisable board, recursive flood-fill, win / lose detection, vim-style movement.

Difficulty presets are available via `Game::withDifficulty(Difficulty::$LEVEL)` — `EASY` (9×9, 10 mines), `MEDIUM` (16×16, 40 mines), `EXPERT` (30×16, 99 mines).

## Run it

```bash
composer install
./bin/candy-mines [width] [height] [mines]   # defaults: 10 10 12
```

## Keys

| Key                | Action                |
|--------------------|-----------------------|
| `↑/↓/←/→` or `hjkl`| Move cursor           |
| `Space` / `Enter`  | Reveal cell           |
| `f`                | Toggle flag           |
| `r`                | Restart with new mines|
| `q` / `Esc`        | Quit                  |

## Architecture

Three pure-state classes plus the runtime Model and a one-pass renderer:

| File              | Role                                                                |
|-------------------|---------------------------------------------------------------------|
| `Cell`            | Value object — mine / revealed / flagged / adjacent count           |
| `Board`           | The grid + every transition (reveal, flag, flood-fill). PRNG injected as `Closure(int):int` for deterministic tests |
| `Game` (Model)    | Cursor + key routing + restart + win/lose gate                      |
| `Renderer`        | Pure view function. CandySprinkles `Style` + `Border::rounded()`    |

The first reveal is always safe — mines are placed only after click 1, with the clicked cell's 3×3 neighbourhood excluded so the player gets a non-trivial flood-fill on every game.

## Demos

### Gameplay

![play](.vhs/play.gif)

### Flagging

![flagging](.vhs/flagging.gif)

## Test

```bash
composer install
vendor/bin/phpunit
```
