<img src=".assets/icon.png" alt="sugar-stash" width="160" align="right">

# SugarStash

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-stash)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-stash)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-stash?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-stash)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/play.gif)

Three-pane git TUI on the SugarCraft stack — port of [`jesseduffield/lazygit`](https://github.com/jesseduffield/lazygit). Status / branches / log laid out side-by-side, single-key stage / unstage, refresh, and ahead/behind branch summary.

```bash
composer require sugarcraft/sugar-stash
sugar-stash    # run inside any git working tree
```

## Keys

| Key                  | Action                                  |
|----------------------|-----------------------------------------|
| `Tab`                | Cycle pane focus                        |
| `↑/↓` or `k/j`       | Move cursor in active pane              |
| `s` (status pane)    | Stage / unstage the highlighted entry   |
| `R`                  | Refresh from disk                       |
| `q` / `Esc`          | Quit                                    |

## Architecture

| File         | Role                                                                   |
|--------------|------------------------------------------------------------------------|
| `Git`        | Concrete `git` shell-out — `status --porcelain=v1 -b`, `for-each-ref`, `log --pretty=format:…`, `add`, `restore --staged`. Throws `RuntimeException` on non-zero exit. |
| `GitDriver`  | Interface for the four read methods + stage/unstage. Tests inject a fixture-backed driver so transition correctness is asserted without staging a real repo. |
| `Pane`       | Enum — `Status`, `Branches`, `Log` — with `next()` for Tab cycling.    |
| `App` (Model)| Owns the three lists + cursors + focus + error string. Pure-state — every key returns a fresh App. |
| `Renderer`   | Pure view function — three `Style::border(rounded)` panes joined horizontally / vertically; the focused pane gets a brighter accent. |

`SugarStash` is intentionally read-mostly: every git mutation that goes beyond stage / unstage shells out to `git` directly via the system, so users keep their existing aliases, hooks, and signing config. Anything more (interactive rebase, cherry-pick, bisect) belongs in a follow-up release.

## Demos

### Staging workflow

![stage](.vhs/stage.gif)

## Test

```bash
composer install
vendor/bin/phpunit
```
