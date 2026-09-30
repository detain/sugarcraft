<img src=".assets/icon.png" alt="candy-files" width="160" align="right">

# CandyFiles

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-files)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-files)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-files?label=packagist)](https://packagist.org/packages/sugarcraft/candy-files)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
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
./bin/candyfiles [LEFT_DIR] [RIGHT_DIR]
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
| `c`             | Copy (selection or cursor) to inactive pane; `y` confirm |
| `m`             | Move (selection or cursor) to inactive pane; `y` confirm |
| `R`             | Rename (cursor entry); new name prompted; `y` confirm    |
| `d`             | Delete (selection or cursor); requires `y` confirm       |
| `r`             | Refresh active pane                                      |
| `q`             | Quit                                                      |
| `/`             | Start search mode; type to filter; Enter to open          |
| `Escape`        | Exit search mode                                          |
| `t`             | Duplicate current tab                                   |
| `Ctrl+w`        | Close current tab                                        |
| `Ctrl+Tab`      | Cycle to next tab                                        |
| `Ctrl+Shift+Tab`| Cycle to previous tab                                    |
| `u` / `Ctrl+z`  | Undo last operation                                      |
| `Ctrl+y`        | Redo last undone operation                               |

## Architecture

The whole transition layer is pure — filesystem I/O is injected as a `Closure(string $path): list<Entry>` so every transition is unit-testable without tmp dirs or stat fixtures.

| File                | Role                                                                     |
|---------------------|--------------------------------------------------------------------------|
| `Entry`             | Value object: name, isDir, size, mtime, isLink, isHidden                |
| `Sort`              | Enum (NameAsc/NameDesc/MtimeAsc/MtimeDesc/SizeAsc/SizeDesc) + comparator |
| `Pane`              | One pane: cwd, entries, cursor, selection set, sort, showHidden          |
| `ConfirmState`      | Pending-confirmation enum (None / Delete / Copy / Move / Rename selected)     |
| `Manager`           | SugarCraft Model — orchestrates two panes, handles all keys + confirm gate |
| `FsLister`          | Default lister: `scandir` + `lstat` against the live filesystem          |
| `Renderer`          | Pure view function — two pane boxes side-by-side + status line           |
| `AsyncOps`          | Async copy via React\Promise + futureTick (move/rename available but sync) |
| `UndoAction`        | Immutable undo record (delete/rename/move/copy) consumed by the undo stacks |
| `ManagerBuilder`    | Fluent builder for `Manager` — avoids 16-argument positional construction   |
| `Msg/CopyCompleted` | Async-copy completion Msg dispatched by the Program into `Manager::update()` |

## Test plan

- 193 tests / 716 assertions
- Pure-state coverage: `Entry` (size formatting, parent sentinel, escape-sequence sweep incl. OSC/CSI/generic-ESC), `Sort` (every order × dirs-first × cycle), `Pane` (open / navigate / move / select / sort / hidden toggle / parent-path / join)
- `Manager` integration: Tab swap (incl. `t <path>` landing on the given directory), key dispatch per pane, confirm gate (`d` arms, `y` confirms, anything else cancels), refresh status
- Async copy end-to-end: the `c`/`y` command is executed against the real event loop and its `CopyCompletedMsg` re-enters `update()` — status, undo entry and refreshed destination pane are all asserted, plus the error-count path
- Renderer: cell-width math for CJK names (size column stays aligned), tab-bar clipping never splits a codepoint, search echo is sanitized
- `LangCoverageTest` enforces translation-key coverage in both directions (every used key exists; no unused key lingers)

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

## Constructing Manager

`Manager` has 16 constructor parameters. Use the fluent builder for readability and to avoid parameter-order mistakes:

```php
$manager = Manager::builder()
    ->withLeft($leftPane)
    ->withRight($rightPane)
    ->withActiveIdx(0)
    ->withStatus('')
    ->withConfirm(ConfirmState::None)
    ->withLister(fn(string $path): list<Entry> => FsLister::lister()($path))
    ->withSearchQuery(null)
    ->withSearchResults([])
    ->withSearchCursor(0)
    ->withTabs([])
    ->withTabIndex(0)
    ->withUndoStack([])
    ->withRedoStack([])
    ->withPendingOpDest(null)
    ->withPendingOpType(null)
    ->build();
```

The direct constructor is kept for backward compatibility only — new code should use `Manager::builder()`.

## Status

Phase 10 entry — copy / move / rename / undo are wired. Delete moves files into a **per-session ephemeral trash**: undo (`u`) recovers them only while the process lives — quitting wipes the trash, and there is no cross-session bin (a persistent XDG-trash implementation is deliberately out of scope). Three-phase confirm gate (`c`/`m`/`R` arms, `y` confirms, anything else cancels). Undo restores delete/move/rename; copy undo is informational (original preserved). Everything underneath (the pure-state transition layer) is already in place.
