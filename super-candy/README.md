<img src=".assets/icon.png" alt="super-candy" width="160" align="right">

# SuperCandy

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
 Tab swap · ↑↓ jk move · Enter open · ← h up · space select · s sort · . hidden · d delete · r refresh · q quit
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

## Architecture

The whole transition layer is pure — filesystem I/O is injected as a `Closure(string $path): list<Entry>` so every transition is unit-testable without tmp dirs or stat fixtures.

| File                | Role                                                                     |
|---------------------|--------------------------------------------------------------------------|
| `Entry`             | Value object: name, isDir, size, mtime, isLink, isHidden                |
| `Sort`              | Enum (NameAsc/NameDesc/MtimeAsc/MtimeDesc/SizeAsc/SizeDesc) + comparator |
| `Pane`              | One pane: cwd, entries, cursor, selection set, sort, showHidden          |
| `ConfirmState`      | Pending-confirmation enum (None / DeleteSelected)                        |
| `Manager`           | CandyCore Model — orchestrates two panes, handles all keys + confirm gate |
| `FsLister`          | Default lister: `scandir` + `lstat` against the live filesystem          |
| `Renderer`          | Pure view function — two pane boxes side-by-side + status line           |

## Test plan

- 36 tests / 65 assertions
- Pure-state coverage: `Entry` (size formatting, parent sentinel), `Sort` (every order × dirs-first × cycle), `Pane` (open / navigate / move / select / sort / hidden toggle / parent-path / join)
- `Manager` integration: Tab swap, key dispatch per pane, confirm gate (`d` arms, `y` confirms, anything else cancels), refresh status

## Status

Phase 9+ entry #20 — first cut. Read + delete are wired; copy / move / rename / new-dir are obvious next steps. Everything underneath them (the pure-state transition layer) is already in place.
