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

Dual-pane terminal file manager built on the SugarCraft stack for PHP 8.3+, with the Midnight Commander look.

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
 Tab swap · ↑↓ jk move · Enter open · ← h up · space select · s sort · . hidden · c copy · m move · R rename · d delete · r refresh · q quit · / search · t new tab · ^w close tab · ^tab cycle
```

## Run it

```bash
composer install
./bin/candy-files [LEFT_DIR] [RIGHT_DIR]
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
| `Escape`        | Exit search mode; rename prompt: cancel; otherwise: clear the selection |
| `t`             | Duplicate current tab                                   |
| `Ctrl+w`        | Close current tab                                        |
| `Ctrl+Tab`      | Cycle to next tab — needs a terminal that reports modified Tab (see below) |
| `Ctrl+Shift+Tab`| Cycle to previous tab — same terminal-support caveat     |
| `Ctrl+PgUp` / `Ctrl+PgDn` | Cycle tabs forward / back — standard terminal emulators (tmux/browser terminals may intercept modified Page keys) |
| `u` / `Ctrl+z`  | Undo last operation                                      |
| `Ctrl+y`        | Redo last undone operation                               |

 Notation legend: the bottom help line spells chords with a caret — `^w` is Ctrl+w and `^tab` is Ctrl+Tab. The trailing `_` shown while renaming is the text-entry caret.

### Terminal support for Ctrl+Tab

A bare Tab keypress has no way to carry a modifier: on a plain terminal
Ctrl+Tab sends the same 0x09 byte as Tab, and here that keeps its
documented pane-swap. Cycling fires whenever the terminal *can* tell the
two apart — natively (xterm-family `CSI 1;5I` / `CSI 1;6I`, e.g. mintty)
or through the kitty keyboard protocol's disambiguate flag, which the
app negotiates at startup (`CSI > 1u`; terminals without the protocol
ignore it), delivering `CSI 9;5u` / `CSI 9;6u` on kitty, WezTerm, foot,
contour and friends. `Ctrl+PgUp`/`Ctrl+PgDn` are the alternative on
standard terminal emulators: there the Page keys report modified
unambiguously — but tmux and browser terminals may intercept modified
Page keys themselves.

## Layout & terminal size

The two panes split the live terminal width (`WindowSizeMsg`, reported at startup and on every resize): each pane gets `max(40, (cols − 2) ÷ 2)` cells, so the layout is usable from about **82 columns** up, and widens gracefully on larger frames. Below that floor the panes stay 40 cells wide and the outer frame will wrap — there is no horizontal scroll. The name column, header clip and separator rule all derive from the same width. Before the first size report (and in unit tests) the renderer keeps its historical fixed 40-cell pane.

Honest limitation: there is **no scroll viewport**. A listing longer than the frame is clipped by the window; the cursor moves through entries but the view does not follow it past the bottom row. Tall directories need a tall terminal (or `Home`/`End`/search) until a scroll window is built.

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
| `AsyncOps`          | Tick-deferred copy/move/rename futures (React\Promise + futureTick); sole home of the recursive copy engine + depth cap |
| `UndoAction`        | Immutable undo record (delete/rename/move/copy) consumed by the undo stacks |
| `ManagerBuilder`    | Fluent builder for `Manager` — avoids 17-argument positional construction   |
| `Msg/CopyCompletedMsg` | Async-copy completion Msg dispatched by the Program into `Manager::update()` |

## Test plan

- 222 tests / 2931 assertions
- Pure-state coverage: `Entry` (size formatting, parent sentinel, escape-sequence sweep incl. OSC/CSI/generic-ESC), `Sort` (every order × dirs-first × cycle), `Pane` (open / relist-with-selection-preservation / navigate / move / select / sort / hidden toggle / parent-path / join)
- `Manager` integration: Tab swap, key dispatch per pane, confirm gate (`d` arms, `y` confirms, anything else cancels), refresh status, Escape clears selection, resize capture (`WindowSizeMsg` → derived widths, other non-key Msgs dropped)
- `t` duplicates the current tab; `Manager::openNewTab(path)` lands a tab on the given directory as an API (no key binding carries a path)
- Async copy end-to-end: the `c`/`y` command is executed against the real event loop and its `CopyCompletedMsg` re-enters `update()` — status, undo entry and refreshed destination pane are all asserted, plus the error-count path, the skipped-name path, the overwrite announcement and undo-recording-successes-only
- Status-line injection defense: filenames carrying raw ESC/OSC bytes render inert in every rename/copy/move message (adversarial pins), plus the structural pins for the rename containment boundary and the single copyDir home
- Renderer: cell-width math for CJK names (size column stays aligned), tab-bar clipping never splits a codepoint, search echo is sanitized, pane widths follow the terminal
- `LangCoverageTest` enforces translation-key coverage in both directions (every used key exists; no unused key lingers); `LocaleParityTest` pins all 16 shipped locales to the English key set with identical placeholder tokens

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

`Manager` has 17 constructor parameters. Use the fluent builder for readability and to avoid parameter-order mistakes:

```php
$manager = Manager::builder()
    ->withLeft($leftPane)
    ->withRight($rightPane)
    ->withActiveIdx(0)
    ->withStatus('')
    ->withConfirm(ConfirmState::None)
    ->withLister(FsLister::lister())
    ->withSearchQuery(null)
    ->withSearchResults([])
    ->withSearchCursor(0)
    ->withTabs([])
    ->withTabIndex(0)
    ->withUndoStack([])
    ->withRedoStack([])
    ->withPendingOpDest(null)
    ->withPendingOpType(null)
    ->withInputBuffer(null)
    ->withTermCols(0)
    ->build();
```

The direct constructor is kept for backward compatibility only — new code should use `Manager::builder()`.

## Status

Phase 10 entry — copy / move / rename / undo are wired. Delete moves files into a **per-session ephemeral trash**: undo (`u`) recovers them only while the process lives — quitting wipes the trash, and there is no cross-session bin (a persistent XDG-trash implementation is deliberately out of scope). Three-phase confirm gate (`c`/`m`/`R` arms, `y` confirms, anything else cancels). Undo restores delete/move/rename; copy undo is informational (original preserved). Everything underneath (the pure-state transition layer) is already in place.

Status-line semantics (all counts are **actions applied**, never arm-time intentions):

- `deleted N entries` / `copied N entries` / `moved N entries` count entries that really landed.
- When a stale or filtered name was skipped (`..`, empty), the message appends `(S skipped)`; when a leg failed, it says `N entries with E errors` instead — error precedence wins over skipped, and skipped wins over the overwrite note.
- Copy silently **replaces** an existing destination; the completion message announces `(O overwrote)` for the legs that landed on a pre-existing target (measured at schedule time).
- A delete that had to fall back to **permanent removal** (cross-device EXDEV) cannot be undone — undo reports it as an error rather than claiming a restore that has no bytes to recover.
- The selection survives `r` (refresh) and `.` (hidden toggle) for entries that still exist, and `Escape` clears it; failed copy/move legs never enter the undo history.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: [`yorukot/superfile`](https://github.com/yorukot/superfile).
