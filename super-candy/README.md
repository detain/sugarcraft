<img src=".assets/icon.png" alt="super-candy" width="160" align="right">

# SuperCandy

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=super-candy)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=super-candy)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/super-candy?label=packagist)](https://packagist.org/packages/sugarcraft/super-candy)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/navigate.gif)

Dual-pane terminal file manager built on the SugarCraft stack — port of [`yorukot/superfile`](https://github.com/yorukot/superfile), with the Midnight Commander look.

```
┌────────────────────────────────────┐  ┌────────────────────────────────────┐
│ /home/alice  [name-asc]            │  │ /var/log  [mtime-desc]             │
│ ──────────────────                 │  │ ──────────────────                 │
│ ▸  ../                       DIR   │  │    ../                       DIR   │
│    Documents/               DIR   │  │ ▸✓ syslog                  240KB  │
│    Downloads/               DIR   │  │    auth.log                 12KB  │
│    .config/                 DIR   │  │    Xorg.0.log              4.0KB  │
│    notes.md                12KB   │  │                                    │
│    todo.txt              512B    │  │                                    │
└────────────────────────────────────┘  └────────────────────────────────────┘
 Tab swap · ↑↓ jk move · Enter open · ← h up · space select · s sort · . hidden · d delete · r refresh · q quit · / search · t tabs · u undo
```

## Run it

```bash
composer install
./bin/supercandy [LEFT_DIR] [RIGHT_DIR]
```

Default: left pane = current directory, right pane = `$HOME`.

## Keys

| Key             | Action                                              |
|-----------------|-----------------------------------------------------|
| `Tab`           | Swap focus between panes                            |
| `↑` / `k`       | Move cursor up                                      |
| `↓` / `j`       | Move cursor down                                    |
| `Home` / `g`    | Top of listing                                      |
| `End` / `G`     | Bottom of listing                                   |
| `Enter` / `→`   | Open directory (or no-op on a file)                 |
| `←` / `h`       | Go up one directory                                 |
| `Space`         | Toggle selection on the entry under cursor + advance|
| `s`             | Cycle sort order (name → mtime → size, asc / desc)  |
| `.`             | Toggle hidden-file visibility                       |
| `d`             | Delete (selection or cursor); requires `y` confirm  |
| `r`             | Refresh active pane                                 |
| `q`             | Quit                                                |
| `/`             | Start search mode; type to filter; Enter to open    |
| `Escape`        | Exit search mode                                    |
| `t`             | Duplicate current tab                               |
| `Ctrl+w`        | Close current tab                                   |
| `Ctrl+Tab`      | Cycle to next tab                                   |
| `Ctrl+Shift+Tab`| Cycle to previous tab                               |
| `u` / `Ctrl+z`  | Undo last delete operation                          |

## Architecture

The whole transition layer is pure — filesystem I/O is injected as a `Closure(string $path): list<Entry>` so every transition is unit-testable without tmp dirs or stat fixtures.

| File                | Role                                                                     |
|---------------------|--------------------------------------------------------------------------|
| `Entry`             | Value object: name, isDir, size, mtime, isLink, isHidden                |
| `Sort`              | Enum (NameAsc/NameDesc/MtimeAsc/MtimeDesc/SizeAsc/SizeDesc) + comparator |
| `Pane`              | One pane: cwd, entries, cursor, selection set, sort, showHidden          |
| `ConfirmState`      | Pending-confirmation enum (None / DeleteSelected)                        |
| `Manager`           | SugarCraft Model — orchestrates two panes, handles all keys + confirm gate |
| `FsLister`          | Default lister: `scandir` + `lstat` against the live filesystem          |
| `Renderer`          | Pure view function — two pane boxes side-by-side + status line           |

## Test plan

- 36 tests / 65 assertions
- Pure-state coverage: `Entry` (size formatting, parent sentinel), `Sort` (every order × dirs-first × cycle), `Pane` (open / navigate / move / select / sort / hidden toggle / parent-path / join)
- `Manager` integration: Tab swap, key dispatch per pane, confirm gate (`d` arms, `y` confirms, anything else cancels), refresh status

## Demos

### Navigation

![navigate](.vhs/navigate.gif)

### Search

![search](.vhs/search.gif)

### Multi-select and delete

![multi-select](.vhs/multi-select.gif)

### Tab management

![tabs](.vhs/tabs.gif)

### Sort cycling

![sort-cycle](.vhs/sort-cycle.gif)

### Undo delete

![undo](.vhs/undo.gif)

### Hidden-file toggle

![hidden-files](.vhs/hidden-files.gif)

## Status

Phase 9+ entry #20 — first cut. Read + delete are wired; search, tabs, and undo are fully implemented; copy / move / rename / new-dir are obvious next steps. Everything underneath them (the pure-state transition layer) is already in place.
