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

Prompts are localized: the executable activates a detected language from
`$LC_ALL`/`$LANG` (16 catalogues under `lang/`, English by default), and a
confirmation gate honors the affirmative its prompt teaches — `y` always
works, and a German `(j/n)` answers to `j`.

```
┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓  ┌──────────────────────────────────────────┐
┃ /home/alice  [name-asc]                  ┃  │ /var/log  [mtime-desc]                   │
┃ ────────────────────────────────────     ┃  │ ────────────────────────────────────     │
┃ ▸  ../                            DIR    ┃  │    ../                            DIR    │
┃    Documents/                     DIR    ┃  │ ▸✓ syslog                     240.0KB    │
┃    Downloads/                     DIR    ┃  │    auth.log                    12.0KB    │
┃    notes.md                    12.0KB    ┃  │    Xorg.0.log                   4.0KB    │
┃    todo.txt                      512B    ┃  └──────────────────────────────────────────┘
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 focus: left · Tab swap · ↑↓ jk move · Home/End g/G jump · Enter open · ← h up · space select · s sort · . hidden · c copy · m move · R rename · d delete · r refresh · q quit · / search · Esc clear · t duplicate tab · ^w close tab · ^tab cycle · ^PgUp/^PgDn tabs · u/^z undo · ^y redo
```

## Run it

Three channels, same as every SugarCraft app:

**1. Composer consumer** — install the package into a project and run its vendor bin:

```bash
composer require sugarcraft/candy-files:@dev
vendor/bin/candy-files [LEFT_DIR] [RIGHT_DIR]
```

The inline `:@dev` is required while the release channel is dev-only: Packagist
carries just `dev-master`, so a fresh project's default `minimum-stability:
stable` refuses the bare command.

**2. Source checkout** — from a clone of this repo:

```bash
composer install
./bin/candy-files [LEFT_DIR] [RIGHT_DIR]
```

**3. Rolling phar** — the self-contained build published on GitHub Releases under
`candy-files/dev` as `candy-files.phar` (with `.sha256`/`.sig` verification sidecars,
plus the `.phar.commit` build stamp and `.phar.pins.json` sibling pin-map — the latter
pair is what to quote in a bug report); stable cuts ride the same release pipeline on
`candy-files/v*` tags. The launcher
detects that it is running from inside a phar and `--version` reports that archive's
own build stamp (see `Support\Version` below):

```bash
wget https://github.com/detain/sugarcraft/releases/download/candy-files/dev/candy-files.phar
php candy-files.phar [LEFT_DIR] [RIGHT_DIR]
```

Flags: `--version` (prints the build stamp — phar-aware via `Support\Version::line()`)
and `--help` / `-h` (usage). Both are answered before the positional directory scan,
and the scan fail-fasts with `left/right directory does not exist: <dir>` (exit 1)
rather than opening a mysteriously empty pane. One honest consequence of matching
flags anywhere in argv: a directory literally named `--version` prints the version —
flag-before-scan ordering is the documented design.

Default: left pane = current directory, right pane = `$HOME`.

**Requirements:** PHP `^8.3` with `ext-intl` and `ext-mbstring`. Both extensions are
hard-required transitively by `sugar-core`, whose cell-width math segments through ICU —
on a bare php-cli machine the step that refuses is `composer install`, naming `sugar-core`.

## Keys

| Key             | Action                                              |
|-----------------|-----------------------------------------------------|
| `Tab`           | Swap focus between panes                            |
| `↑` / `k`       | Move cursor up                                      |
| `↓` / `j`       | Move cursor down                                    |
| `Home` / `g`    | Top of listing                                      |
| `End` / `G`     | Bottom of listing                                   |
| `Enter` / `→`   | Open: a directory navigates, a file launches `$EDITOR` (via the `VISUAL`/`EDITOR` chain; refused with a status when none resolves) |
| `l`             | Page the file under cursor through `$PAGER` (binary or > 32 MiB payloads are refused with a status); on a directory it opens — the right half of the vim diamond `h j k l` |
| `x`             | Run the entry under cursor when it carries the exec bit (argv spawn — never a shell string) |
| `←` / `h`       | Go up one directory                                 |
| `Space`         | Toggle selection on the entry under cursor + advance|
| `s`             | Cycle sort order (name → mtime → size, asc / desc)  |
| `.`             | Toggle hidden-file visibility                       |
| `c`             | Copy (selection or cursor) to inactive pane; confirm the armed gate (see Status)  |
| `m`             | Move (selection or cursor) to inactive pane; confirm the armed gate (see Status)  |
| `R`             | Rename cursor entry: text-entry submode — type the new name; `Enter` commits (empty buffer cancels); `Backspace` edits the buffer; `Esc` cancels; every printable char — `y` included — appends |
| `N`             | Create a directory in the active pane: same text-entry submode — `Enter` commits, empty buffer or `Esc` cancels; `u` removes it again while it is empty |
| `d`             | Delete (selection or cursor); confirm the armed gate (see Status)        |
| `r`             | Refresh active pane                                       |
| `q`             | Quit                                                      |
| `/`             | Start search mode: fuzzy filter by **name** over the active pane's listing (current directory only; dotfiles obey the `.` visibility law); `↑`/`↓` move the result cursor, every printable char — `k`, `j`, even `.` — types into the query (`/` itself cannot); `Enter` opens a directory result, and on a file result exits search with the pane cursor landed on it; a `Backspace` that empties the query exits search |
| `Escape`        | Exit search mode; cancels armed confirmations and text-entry (cancel-intent is never a confirm — only the gate's taught affirmative confirms the pending `d`/`c`/`m` op); otherwise clears the selection |
| `t`             | Duplicate current tab                                     |
| `Ctrl+w`        | Close current tab (refused on the last tab: "cannot close last tab") |
| `Ctrl+Tab`      | Cycle to next tab — needs a terminal that reports modified Tab (see below) |
| `Ctrl+Shift+Tab`| Cycle to previous tab — same terminal-support caveat |
| `Ctrl+PgUp` / `Ctrl+PgDn` | Cycle tabs forward / back — standard terminal emulators report the modified Page keys unambiguously (tmux/browser terminals may intercept them) |
| `u` / `Ctrl+z`  | Undo last operation                                      |
| `Ctrl+y`        | Redo last undone operation                               |

 Notation legend: the bottom help line spells chords with a caret — `^w` is Ctrl+w and `^tab` is Ctrl+Tab. That in-app line is a partial cheat sheet; the full binding table is the one above. Plain `PageUp`/`PageDown` (unmodified) are not bound to anything. The trailing `_` shown while renaming is the text-entry caret. The active pane is marked two ways: a thick border, and a localized `focus: left` / `focus: right` label leading every status line (the label is the cue that survives linearized or screen-reader reading). The tab chords are read by the key dispatcher only after search and confirmation modes release it: while either is armed, press `Esc` first, then cycle.

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

The two panes split the live terminal width (`WindowSizeMsg`, reported at startup and on every resize): each pane box measures `(cols − 2) ÷ 2` cells **outer** — border and padding eat four of those, and the name column, header clip, size column and separator rule all derive from the same content width, so two boxes plus the 2-cell gutter land exactly on `cols` at any size (a whole-frame property test pins `Width::of(line) ≤ cols` for every line at 40/80/120/200). The bottom help line is width-responsive: the full roster, then a terse tier, then a minimal tier on narrow frames, and every composed status/search line is cell-clamped — nothing wraps. Before the first size report (and in unit tests) the renderer keeps its historical fixed 40-cell pane.

The vertical axis is windowed too: the frame is clipped to the terminal's live row count, each pane scrolls a window that follows the cursor (fold hints `↑ N more` / `↓ N more` mark the hidden head and tail), and border, status line and any confirmation prompt are ALWAYS on screen. `Home`/`End`/`g`/`G` and search jump past the window edges.

## Architecture

The whole transition layer is pure — filesystem I/O is injected as a `Closure(string $path): list<Entry>` so every transition is unit-testable without tmp dirs or stat fixtures.

| File                | Role                                                                     |
|---------------------|--------------------------------------------------------------------------|
| `Entry`             | Value object: name, isDir, size, mtime, isLink, isHidden                |
| `Sort`              | Enum (NameAsc/NameDesc/MtimeAsc/MtimeDesc/SizeAsc/SizeDesc) + comparator |
| `Pane`              | One pane: cwd, entries, cursor, selection set, sort, showHidden          |
| `ConfirmState`      | Pending-confirmation enum (None / DeleteSelected / CopySelected / MoveSelected / RenameSelected)     |
| `Manager`           | SugarCraft Model — orchestrates two panes, handles all keys + confirm gate |
| `FsLister`          | Default lister: `scandir` + `lstat` against the live filesystem          |
| `Renderer`          | Pure view function — tab bar, two pane boxes side-by-side, optional search overlay, status line |
| `AsyncOps`          | Tick-sliced deferred COPY engine (sole home of the recursive copy, depth-capped at `MAX_COPY_DEPTH` = 256; React\Promise + futureTick); move/rename run synchronously via `@rename` |
| `UndoAction`        | Immutable undo record consumed by the undo stacks — `$items` is one of two shapes: a list of delete-records (`{path, isDir, trash}`) for restores, or an old→new path map for rename/move/copy |
| `Lang`              | i18n wrapper over the `lang/*.php` catalogues (16 locales) consumed by Manager/Renderer strings |
| `Support\Version`   | `--version` build-stamp resolution — the phar archive's own stamp wins when launched from a stamped phar; a source checkout reports `dev-master` |
| `Support\Home`      | Launcher home-directory cascade (CF-18) — POSIX `HOME`, then Windows `USERPROFILE`, then `HOMEDRIVE`+`HOMEPATH`, `/` last |
| `ManagerBuilder`    | Fluent builder for `Manager` — avoids 17-argument positional construction   |
| `Msg/CopyCompletedMsg` | Async-copy completion Msg dispatched by the Program into `Manager::update()` |

## Test plan

- 321 tests / 4805 assertions — this pair is hand-typed: candy-files is lib-local for PHPUnit and absent from the CI figure-pin machinery (`parallel-tests-durations.tsv` / `suite-figure.json`), so re-derive it with `vendor/bin/phpunit` here whenever tests change.
- Pure-state coverage: `Entry` (size formatting, parent sentinel, escape-sequence sweep incl. OSC/CSI/generic-ESC), `Sort` (every order × dirs-first × cycle), `Pane` (open / relist-with-selection-preservation / navigate / move / select / sort / hidden toggle / parent-path / join)
- `Manager` integration: Tab swap, key dispatch per pane, confirm gate (`d` arms, `y` or the prompt's taught localized affirmative confirms, anything else cancels; modified chords never confirm), refresh status, Escape clears selection, resize capture (`WindowSizeMsg` → derived widths **and rows**, other non-key Msgs dropped)
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

Copy / move / rename / mkdir / undo are wired. Delete moves files into a **per-session ephemeral trash**: undo (`u`) recovers them only while the process lives — quitting wipes the trash, and there is no cross-session bin (a persistent XDG-trash implementation is deliberately out of scope). The `d`/`c`/`m` operations use a two-phase confirm gate keyed by three keys: one press **arms** it, the next answers — `y` **or the affirmative the prompt teaches** (a localized letter: `j` under German `(j/n)`, `д` under Russian, `e` under Turkish) confirms; anything else, including `Escape`, cancels, and modified chords never answer on your behalf. `R` rename and `N` mkdir are instead the text-entry submode: type the name, `Enter` commits it (an empty buffer cancels), `Backspace` edits, `Esc` cancels. The undo stack is capped at 50 operations (`UNDO_LIMIT`); the oldest entries fall off. Undo restores delete/move/rename and never renames onto an occupied path — a file created after the delete stays, the original is left in the trash, and the status names the refusal; copy undo is informational (original preserved). Everything underneath (the pure-state transition layer) is already in place.

Status-line semantics (all counts are **actions applied**, never arm-time intentions):

- `deleted N entries` / `copied N entries` / `moved N entries` count entries that really landed.
- When a stale or filtered name was skipped (`..`, empty), the message appends `(S skipped)`; when a leg failed, it says `N entries with E errors` instead — error precedence wins over skipped, and skipped wins over the overwrite note.
- Copying a file onto an existing **file** silently replaces it; the completion message announces `(O overwrote)` for the legs that landed on a pre-existing target (measured at schedule time). Directory copies **merge**: destination-only entries survive. A copied **symlink** replaces an existing file-or-link destination (unlink-then-relink); a symlink landing on a directory destination fails as a counted error leg — the destination keeps its bytes.
- Copy **preserves** source metadata: file legs arrive with the source mode bits (a `0600` secret stays `0600`) and mtime, and copied directories are created in the source mode — the mode lands after the children so a directory is never chmod'd shut mid-walk.
- A delete that had to fall back to **permanent removal** (cross-device EXDEV) says so BEFORE the `y`: the armed gate prompts `PERMANENT (y/n)` and the status counts the permanent legs (`deleted N entries (P permanent)`); undo reports those as an error rather than claiming a restore that has no bytes to recover.
- The selection survives `r` (refresh) and `.` (hidden toggle) for entries that still exist, and `Escape` clears it; failed copy/move legs never enter the undo history.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: [`yorukot/superfile`](https://github.com/yorukot/superfile).
