<img src=".assets/icon.png" alt="sugar-stash" width="160" align="right">

# SugarStash

![demo](.vhs/play.gif)

Three-pane git TUI on the SugarCraft stack — port of [`jesseduffield/lazygit`](https://github.com/jesseduffield/lazygit). Status / branches / log laid out side-by-side, single-key stage / unstage, refresh, and ahead/behind branch summary.

```bash
composer require candycore/sugar-stash
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

## Test

```bash
composer install
vendor/bin/phpunit
```
