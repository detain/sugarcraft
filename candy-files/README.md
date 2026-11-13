<img src=".assets/icon.png" alt="candy-files" width="160" align="right">

# CandyFiles

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-files)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-files)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-files?label=packagist)](https://packagist.org/packages/sugarcraft/candy-files)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

**candy-files** is a dual-pane terminal file manager built on the SugarCraft
stack for PHP 8.3+, in the Midnight Commander lineage — the twin-column layout
is MC's idiom, though MC's signature F-key bar is deliberately absent here.

Prompts are localized: the executable activates a detected language from
`$LC_ALL`/`$LANG` (16 catalogues under `lang/`, English by default), and a
confirmation gate honors the affirmative its prompt teaches — `y` always
works, and a German `(j/n)` answers to `j`.

```
┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓  ┌──────────────────────────────────────────┐
┃ /home/alice  [name-asc]                  ┃  │ /var/log  [mtime-desc]                   ┃
┃ ────────────────────────────────────     ┃  │ ────────────────────────────────────     ┃
┃ ▸  ../                            DIR    ┃  │    ../                            DIR    ┃
┃    Documents/                     DIR    ┃  │ ▸✓ syslog                     240.0KB    ┃
┃    Downloads/                     DIR    ┃  │    auth.log                    12.0KB    ┃
┃    notes.md                    12.0KB    ┃  │    Xorg.0.log                   4.0KB    ┃
┃    todo.txt                      512B    ┃  └──────────────────────────────────────────┘
┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 focus: left · Tab swap · ↑↓ jk move · Home/End g/G jump · Enter open · l view · ← h up · space select · s sort · e ext · . hidden · c copy · m move · R rename · d delete · r refresh · q quit · / search · Esc clear · t duplicate tab · ^w close tab · ^tab cycle · ^PgUp/^PgDn tabs · u/^z undo · ^y redo · ? help · Ctrl+O open · 1-9 tabs · Ctrl+a all · v invert · * pattern
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

**2. Source checkout** — run it from a clone of this repo:

```bash
composer install
./bin/candy-files [LEFT_DIR] [RIGHT_DIR]
```

**3. Rolling phar** — download the self-contained build published on GitHub
Releases under `candy-files/latest` as `candy-files.phar` (with
`.sha256`/`.sig` verification sidecars, plus the `.phar.commit` build stamp and
`.phar.pins.json` sibling pin-map — quote the latter pair in a bug report) and
run it with `php`; stable cuts ride the same release pipeline on
`candy-files/v*` tags. The launcher detects that it is running from inside a
phar and `--version` reports that archive's own build stamp (see
`Support\Version` below):

```bash
wget https://github.com/detain/sugarcraft/releases/download/candy-files/latest/candy-files.phar
php candy-files.phar [LEFT_DIR] [RIGHT_DIR]
```

Flags: `--version` (prints the build stamp — phar-aware via `Support\Version::line()`)
and `--help` / `-h` (usage). Both are answered before the positional directory scan,
and the scan refuses up front with `left/right directory does not exist: <dir>` (exit 1)
rather than opening a mysteriously empty pane. One honest consequence of matching
flags anywhere in argv: a directory literally named `--version` prints the version —
flag-before-scan ordering is the documented design.

### cd on quit (`--print-cd`)

`--print-cd` re-homes your shell where the active pane ended up — the most-requested
workflow from the file-manager tradition. While the flag is set the interface rides
`/dev/tty` and **stdout carries nothing but the final directory** on exit, so a shell
function can capture it. Add to `~/.bashrc` / `~/.zshrc`:

```bash
candy-files-cd() {
    local dir
    dir=$(candy-files --print-cd "$@") || return $?
    if [[ -n "$dir" && -d "$dir" ]]; then
        builtin cd "$dir"
    fi
}
```

Positional directories pass through — `--print-cd` itself is consumed and dropped
from the scan, so `candy-files-cd /srv` opens as expected (a directory literally
named `--print-cd` activates the handshake instead, the same flag-before-scan
design as above). The wrapper's exit status is candy-files' own, so a `^C` abort
leaves the shell where it was. With tabs open, the handshake reports the **active
pane** of the tab that was on screen at exit. Refusing to run headless is
deliberate: with no controlling terminal there is nowhere for the interface to
live while stdout is a capture pipe, and candy-files declines at the door instead
of printing escape sequences into the pipe.

Default: left pane = current directory, right pane = `$HOME`.

**Requirements:** PHP `^8.3` with `ext-intl` and `ext-mbstring`. Both extensions are
hard-required transitively by `sugar-core`, whose cell-width math segments through ICU —
on a bare php-cli machine the step that refuses is `composer install`, which fails
naming `sugar-core`.

## Keys

| Key             | Action                                              |
|-----------------|-----------------------------------------------------|
| `Tab`           | Swap focus between panes                            |
| `↑` / `k`       | Move cursor up                                      |
| `↓` / `j`       | Move cursor down                                    |
| `Home` / `g`    | Top of listing                                      |
| `End` / `G`     | Bottom of listing                                   |
| `Enter` / `→`   | Open: a directory navigates, an archive (`.zip`, `.tar`, `.tar.gz`/`.tgz`, `.tar.bz2`/`.tbz2`) browses as a read-only directory (CF-33), any other file launches `$EDITOR` (via the `VISUAL`/`EDITOR` chain; refused with a status when none resolves) |
| `l`             | Page the file under cursor through `$PAGER` (then `less`, `more`); with none installed the **built-in viewer** opens instead (CF-03, below). Binary or > 32 MiB payloads are refused with a status; on a directory it opens — `l` is the right half of the vim diamond `h j k l` |
| `o`             | Open the entry under cursor with the platform default (`xdg-open`, `open`, or `cmd /c start`) as an argv list — never a shell string, so a hostile filename cannot inject; with no helper installed the status says so (CF-03). The TUI suspends while the helper runs, like `x` and the editor |
| `x`             | Run the entry under cursor when it carries the exec bit (argv spawn — never a shell string). **Inside an archive view `x` extracts** the selection (or cursor entry; directories recurse) into the other pane through the copy confirm gate (CF-33) |
| `←` / `h`       | Go up one directory                                 |
| `Space`         | Toggle selection on the entry under cursor + advance|
| `Ctrl+a`        | Select every listed entry (CF-31); the `..` sentinel is never marked |
| `v`             | Invert the selection (CF-31) — complement over the listing, so pressing it twice restores the set |
| `*`             | Pattern-select prompt (CF-31): type a glob-lite pattern such as `*.txt` — the rules are `fnmatch`'s, never regex (see Modes) |
| `s`             | Cycle sort order (name → mtime → size → extension, asc / desc)  |
| `e`             | Direct-select extension sort (CF-46) — from any other field it lands on extension ascending; on the extension field it only flips direction |
| `.`             | Toggle hidden-file visibility                       |
| `c`             | Copy (selection or cursor) to inactive pane; arms the confirm gate (see Modes) |
| `m`             | Move (selection or cursor) to inactive pane; arms the confirm gate (see Modes) |
| `R`             | Rename the entry under cursor through the text-entry mode (see Modes) |
| `N`             | Create a directory in the active pane through the text-entry mode (see Modes); `u` removes it again while it is empty |
| `d`             | Delete (selection or cursor); arms the confirm gate (see Modes) |
| `y` / `Y`       | Yank (CF-32): stage the selection (or the cursor entry) in the yank register as a copy (`y`) or a move (`Y`). Nothing touches the filesystem — the register remembers the source directory and names, so it survives navigation and tab switches |
| `p`             | Paste the register into the **active pane's directory**: arms the same y/n confirm gate `c`/`m` use (overwrite, undo and in-flight rules unchanged). A copy-yank stays staged for further pastes; a move-yank is consumed once its bytes moved. Pasting into the source directory skips the legs (the CF-43 self-copy guard) |
| `r`             | Refresh active pane                                       |
| `w` / `W`       | Copy the full path (`w`) or the bare name (`W`) of the selection — or the cursor entry, or the pane's directory on `..` — to the system clipboard over OSC 52 (CF-50); several entries go newline-joined. The status says the terminal may ignore the sequence (tmux defaults and many emulators do; the app cannot observe that), a `dumb`/`linux`/unset `TERM` is refused up front, and a payload over 100 KB is refused rather than truncated |
| `a`             | Pin the active pane's directory as a bookmark (CF-30); pinning twice says "already pinned" |
| `B`             | Bookmarks overlay (CF-30): `j`/`k`/arrows move, `Enter` jumps the active pane there (through the `Ctrl+O` path door — a bookmark that vanished or went unreadable is refused and the pane stays put), `d`/`Delete` unpins, `B`/`Esc` closes. The list lives in `$XDG_DATA_HOME/candy-files/bookmarks.json` (default `~/.local/share/candy-files/`, `%APPDATA%` on Windows) — JSON written atomically (temp file + rename), directory `0700`, file `0600`, at most 256 entries; a missing or corrupt file reads as an empty list |
| `I`             | Info overlay (CF-52) for the entry under the cursor (the pane's own directory on `..`): path, type, size in bytes, permissions (`drwxr-xr-x` + octal), owner:group, modified / accessed times, link target and the filesystem's free space — read fresh with `lstat` when the key is pressed. `I` or `Esc` closes it |
| `q`             | Quit; while copy batches are in flight the first press only arms the question ("press q again") and any other key drops it |
| `?`             | Toggle the help overlay (see Modes)                       |
| `/`             | Start search mode over the active pane's listing (see Modes) |
| `Escape`        | Cancel whatever mode owns the keyboard; with no mode active, clears the selection (see Modes) |
| `t`             | Duplicate current tab                                     |
| `Ctrl+w`        | Close current tab (refused on the last tab: "cannot close last tab") |
| `Ctrl+O`        | Open a typed path in the active pane (CF-29, through the text-entry mode): `~` expands through the launcher's home cascade and relative paths join onto the pane's cwd; an unreadable target is refused and the pane stays put |
| `1`–`9`         | Switch to tab 1–9 by number (bare digits or `Alt+n`; past the last tab it is a no-op); the tab bar prints each label with its number, so the bindings are self-named |
| `Ctrl+Tab`      | Cycle to next tab — needs a terminal that reports modified Tab (see below) |
| `Ctrl+Shift+Tab`| Cycle to previous tab — same terminal-support caveat |
| `Ctrl+PgUp` / `Ctrl+PgDn` | Cycle tabs forward / back — standard terminal emulators report the modified Page keys unambiguously (tmux/browser terminals may intercept them) |
| `u` / `Ctrl+z`  | Undo last operation                                      |
| `Ctrl+y`        | Redo last undone operation                               |

At 100 columns and wider each pane row also carries a modification-time column
(`YYYY-MM-DD HH:MM`); narrower terminals keep the name and size columns only
(the same width-tier law as the key-help line).

### Mouse (CF-55)

The launcher asks the terminal for mouse reporting (SGR cell-motion mode), so:

| Gesture | Action |
|---------|--------|
| Wheel | Scrolls the pane under the pointer three rows per notch — the cursor moves and the window follows; focus does not change |
| Click a row | Activates that pane and parks the cursor on the row |
| `Ctrl`+click a row | Toggles the row's selection mark (the CF-31 grammar, same as `Space` but without advancing) |
| Double-click a row (second click on the same row within 400 ms) | Enter semantics: a directory opens, a file launches `$EDITOR` |
| Click a pane's border/header | Activates that pane |
| Click a tab label | Switches to that tab |

Release, drag-motion, middle and right buttons are ignored, and while a modal
frame owns the screen (confirm gate, help, info overlay, search) the pointer is
inert, so a stray click can never answer a prompt. Mouse reporting steals the
terminal's own text selection — hold `Shift` while dragging (most terminals) to
select text.

Notation legend: the bottom help line spells chords with a caret — `^w` is Ctrl+w and
`^tab` is Ctrl+Tab. That in-app line is a partial cheat sheet; the full binding table is
the one above. Plain `PageUp`/`PageDown` (unmodified) are not bound to anything. The
trailing `_` shown while renaming is the text-entry caret. The active pane is marked two
ways: a thick border, and a localized `focus: left` / `focus: right` label leading every
status line (the label is the cue that survives linearized or screen-reader reading).

### Terminal support for Ctrl+Tab

A bare Tab keypress has no way to carry a modifier: on a plain terminal
Ctrl+Tab sends the same 0x09 byte as Tab, and here a plain Tab keeps its
documented pane-swap. Cycling fires whenever the terminal *can* tell the
two apart — natively (xterm-family `CSI 1;5I` / `CSI 1;6I`, e.g. mintty)
or through the kitty keyboard protocol's disambiguate flag, which the
app negotiates at startup (`CSI > 1u`; terminals without the protocol
ignore it), delivering `CSI 9;5u` / `CSI 9;6u` on kitty, WezTerm, foot,
contour and friends. `Ctrl+PgUp`/`Ctrl+PgDn` are the alternative on
standard terminal emulators: there the Page keys report modified
unambiguously — but tmux and browser terminals may intercept modified
Page keys themselves.

### Built-in viewer (CF-03)

When `l` finds no pager, a read-only viewer overlay shows the head (first 256 KiB)
of the text file: `j`/`k`/arrows scroll, `Space`/`PgDn` and `b`/`PgUp` page,
`g`/`G` or `Home`/`End` jump, the wheel scrolls three rows, `q`/`l`/`Esc` close
it (`q` closes the viewer; it never quits the app from behind a frame). Invalid
UTF-8 is scrubbed, tabs expand to four spaces and every line goes through the same
control-byte sanitizer as filenames, so an escape sequence in a log cannot reach
the terminal. A head-only read is announced on the title row.

### Archives (CF-33)

Enter (or `l`) on a `.zip`, `.tar`, `.tar.gz`/`.tgz` or `.tar.bz2`/`.tbz2` opens it
as a **read-only virtual directory** — the header shows paths like
`/home/me/pkg.zip!/docs`. Navigation, selection, search-free paging, `w`/`W`
and the `1`–`9` tabs work as in any pane; `Left`/`h` at the archive root returns
to the folder and parks the cursor on the archive file. Everything that would
write — `d c m R N y Y p`, copying or moving INTO an archive pane, opening an
entry in the editor/pager — is refused with the status `archive view is
read-only — x extracts to the other pane`.

`x` extracts the marked entries (or the one under the cursor; a directory pulls
its whole subtree) into the other pane's directory behind the same y/n gate `c`
uses, with the copy engine's overwrite announcement and in-flight accounting.
Extraction runs on the event loop in 1 MiB slices into a private `0700` staging
tree that the copy engine then places and that is removed on every exit, so a big
archive never freezes the keyboard.

Safety rules (enforced in `Support\Archive` / `ArchiveExtractor`, not trusted to
a library): an entry name that is absolute, carries a drive letter or NUL, or
contains a `..` segment (either slash flavour) is dropped from the listing and
cannot be extracted (zip-slip); symlinks, hard links, devices and encrypted zip
records are never listed or created, and the entry greeting counts what was
hidden; files are written `0644` / dirs `0755` whatever the archive says (no
setuid survives); and two bomb ceilings apply — a compressed tar may expand to at
most 256 MiB while being indexed, and one extraction may write at most 512 MiB
(counted over bytes actually written, never the header's claim) or the whole
batch is rejected, as is an archive of more than 50 000 records. Tar parsing is
in-process (`Support\TarReader`: ustar prefix, GNU long names, pax paths,
checksums verified, truncation detected). `.zip` needs `ext-zip`, `.tar.gz`
`ext-zlib`, `.tar.bz2` `ext-bz2` (all `suggest`ed in `composer.json`); without
the extension the status names the one that is missing.

## Behavior and limits

Candy-files mutates real files as you work. The facts that can cost you data
come first, here and nowhere further down.

### Trash: delete is recoverable only while the process lives

Delete moves files into a **per-session ephemeral trash** — never unlinks them
in place. The root is `$TMPDIR/candyfiles-trash-<pid>-<random>` (mode `0700`,
private to the owning process). Undo (`u`) recovers deleted entries only while
the process lives; a normal exit wipes the root, and after a `SIGKILL` or crash
the bytes **stay there** — that directory is the last-resort archive you can
recover from by hand until the OS clears the temp dir. A new session starts with
an empty undo stack (`nothing to undo`) and does not re-adopt a previous
session's trash. There is no cross-session bin (a persistent XDG-trash
implementation is deliberately out of scope). After every delete that reached
the trash, the status line names the window: recoverable with `u` until you quit.

### A cross-device delete is permanent — and says so before you confirm

When the trash root and the file sit on different devices, staging a delete is
itself a copy (EXDEV). Candy-files refuses to pretend: the armed gate prompts
`PERMANENT (y/n)` **before** the operation runs, the completion status counts
the permanent legs (`deleted N entries (P permanent)`), and undo later reports
those entries as an error rather than claiming a restore that has no bytes to
recover.

### Copy replaces files — announced, and directories merge

Copying a file onto an existing **file** replaces it; the completion message
announces `(O overwrote)` for every leg that landed on a pre-existing target
(measured at schedule time). Directory copies **merge**: destination-only
entries survive. A copied **symlink** replaces an existing file-or-link
destination (unlink-then-relink); a symlink landing on a directory destination
fails as a counted error leg — the destination keeps its bytes.

Copy **preserves** source metadata: file legs arrive with the source mode bits
(a `0600` secret stays `0600`) and mtime, and copied directories are created in
the source mode — the mode lands after the children so a directory is never
chmod'd shut mid-walk.

### The confirm gate never answers for you

`d`, `c`, and `m` use a two-phase confirm gate: one press **arms** it, the next
key answers. `y` **or the affirmative the prompt teaches** (a localized letter:
`j` under German `(j/n)`, `д` under Russian, `e` under Turkish) confirms;
anything else — including `Escape` — cancels. Modified chords never answer on
your behalf, and cancel-intent is never a confirm.

### The undo stack remembers 50 operations — and never clobbers on replay

The undo stack is capped at 50 operations; the oldest entries fall off (the
redo stack is deliberately unbounded). Undo restores delete/move/rename and
never renames onto an occupied path: a file created after the delete stays, the
original is left in the trash, and the status names the refusal. Copy undo is
informational — the original was never moved. Failed copy/move legs never enter
the undo history.

### Modes

The interface has five modes; exactly one owns the keyboard at a time. The
confirm gate and the search intercept consume their keystrokes before anything
else is dispatched, so the tab chords act only once every mode has released the
keyboard.

1. **Browse** — the default: move the cursor, select, sort, open, switch tabs.
2. **Confirm gate** — armed by `d` / `c` / `m` (and, while copy batches are in
   flight, by the first `q`). The next key answers it; see
   [The confirm gate](#the-confirm-gate-never-answers-for-you).
3. **Text entry** — `R` rename, `N` mkdir, `Ctrl+O` path, and `*` pattern-select
   each open a one-line prompt with a trailing `_` caret. Every printable char
   appends (`y` included — in this mode it is just a letter), `Enter` commits (an
   empty buffer cancels), `Backspace` edits the buffer, `Esc` cancels.
4. **Search** — `/` starts a fuzzy filter by **name** over the active pane's
   listing (dotfiles obey the `.` visibility law). Every printable char types
   into the query (`k`, `j`, even `.` are letters here; `/` itself cannot),
   `↑`/`↓` move the result cursor, and `Enter` opens a directory result or, on a
   file result, exits search with the pane cursor landed on it. A `Backspace`
   that empties the query exits search. `Ctrl+R` **inside search** toggles a
   bounded recursive walk of the pane's tree (CF-17): deeper hits render as
   their path relative to the pane, and `Enter` on one homes the pane into the
   hit's directory. Plain `r` **outside** search still refreshes the pane —
   only a chord may own a letter that is also a query character.
5. **Help overlay** — `?` lists every binding one per row, windowed to the
   terminal height with a `+N more` fold when the roster overflows; `?` or
   `Escape` closes it and restores the browse frame.

### Status line: counts are actions applied, never intentions

All counts report what really landed:

- `deleted N entries` / `copied N entries` / `moved N entries` count entries that
  arrived at their destination.
- When a stale or filtered name was skipped (`..`, empty), the message appends
  `(S skipped)`; when a leg failed, it says `N entries with E errors` instead —
  error precedence wins over skipped, and skipped wins over the overwrite note.
- The selection survives `r` (refresh) and `.` (hidden toggle) for entries that
  still exist, and `Escape` clears it.

### Limits

- Recursive copy is depth-capped at 256 (`AsyncOps::MAX_COPY_DEPTH`).
- The recursive search walk (CF-17) is budgeted: depth 8, 20 000 names
  examined, four screens of hits, breadth-first so shallow matches fill the
  budget first. Hidden entries are pruned at the walk (search never surfaces
  what the UI hides), unreadable directories are skipped, symlinks are never
  followed (a bind-mount loop cannot trap the walk), and every cap that fires
  flips the status to "search partial" instead of silently presenting a slice
  as truth.
- Selections survive navigation (CF-31): leaving a directory stashes its marks
  and re-entering re-adopts them for names that still exist — no re-marking
  after "go up and back". The stash holds 128 directories, oldest first out.
- Listings are memoized by directory mtime (CF-39), so go-up-and-back and
  post-operation re-reads cost zero scans; the memo holds 256 paths, oldest
  first out. The disk-trusted points (both refresh paths, `r`, undo/redo
  relists) drop their slots first, so an explicit refresh always believes the
  disk even inside a same-second mtime tie.
- The `l` pager refuses a file that is binary (NUL in the head) or larger than
  32 MiB, with a status instead of a screen of mojibake.
- Extension sort (CF-46) remembers the field per pane, not per directory
  (deliberately scoped out).
- Name matching (CF-48) compares NFC-normalized text on both sides — a composed
  query finds macOS's decomposed bytes and vice versa — while display and
  selection keep the raw on-disk name.

### Accessibility

The interface is monochrome by construction: the renderer emits no color or
font styling anywhere — emphasis rides entirely on text (`▸` cursor, `✓`
selection, the thick border, the localized focus label, `↑/↓ N more` fold
hints), so a NO_COLOR, high-contrast, or inverted palette sees everything.

## Layout & terminal size

The two panes split the live terminal width (`WindowSizeMsg`, reported at startup and on every resize): each pane box measures `(cols - 2) / 2` cells **outer** — border and padding eat four of those, and the name column, header clip, size column and separator rule all derive from the same content width, so two boxes plus the 2-cell gutter land exactly on `cols` at any size (a whole-frame property test pins `Width::of(line) <= cols` for every line at 40/80/120/200). The bottom help line is width-responsive: the full roster, then a terse tier, then a minimal tier on narrow frames, and every composed status/search line is cell-clamped — nothing wraps. Before the first size report (and in unit tests) the renderer keeps its historical fixed 40-cell pane.

The vertical axis is windowed too: the frame is clipped to the terminal's live row count, each pane scrolls a window that follows the cursor (fold hints `↑ N more` / `↓ N more` mark the hidden head and tail), and border, status line and any confirmation prompt are ALWAYS on screen. `Home`/`End`/`g`/`G` and search jump past the window edges.

### Glyph tiers

The chrome glyphs (`▸ ✓ ─ ↑ ↓ ·`) are East Asian Width "Ambiguous": some terminals paint them one cell, some two. CandyFiles renders them one-cell (the fleet-wide `Width` law) unless it detects a surface that doubles them — a `zh`/`ja`/`ko` locale, a legacy CJK codepage (`CP932`/`GBK`/`EUC-JP`/`BIG5`…), or the Linux console — and then the frame falls back to strictly-ASCII chrome (`> v - ^ v |`, plain `+--+` borders; the status line's focus label remains the active-pane cue). Force either tier with `CANDY_FILES_GLYPHS=ascii|unicode`. User filenames are data and are never rewritten by the tier.

## Architecture

Every mutation (copy, move, rename, delete, mkdir, undo/redo) is wired against
the live filesystem, and the whole transition layer is pure: filesystem I/O
enters as an injected `Closure(string $path): list<Entry>`, so every transition
can be tested in memory — no tmp dirs, no stat fixtures — while production
reads through `FsLister`.

| File                | Role                                                                     |
|---------------------|--------------------------------------------------------------------------|
| `Entry`             | Value object: name, isDir, size, mtime, isLink, isHidden                |
| `Sort`              | Enum (NameAsc/NameDesc/MtimeAsc/MtimeDesc/SizeAsc/SizeDesc/ExtAsc/ExtDesc) + comparator |
| `Pane`              | One pane: cwd, entries, cursor, selection set, sort, showHidden          |
| `ConfirmState`      | Mode enum for the keyboard (None / DeleteSelected / CopySelected / MoveSelected / RenameSelected / MkdirPrompt / PathPrompt / SelectPattern) |
| `Manager`           | SugarCraft Model — orchestrates two panes, handles all keys + confirm gate |
| `FsLister`          | Default lister: `scandir` + `lstat` against the live filesystem; `memo()` adds the directory-mtime cache (CF-39) |
| `Renderer`          | Pure view function — tab bar, two pane boxes side-by-side, optional search overlay, status line |
| `AsyncOps`          | Tick-sliced deferred COPY engine (sole home of the recursive copy, depth-capped at `MAX_COPY_DEPTH` = 256; React\Promise + futureTick); move/rename run synchronously via `@rename` |
| `UndoAction`        | Immutable undo record consumed by the undo stacks — `$items` is one of two shapes: a list of delete-records (`{path, isDir, trash}`) for restores, or an old→new path map for rename/move/copy |
| `Lang`              | i18n wrapper over the `lang/*.php` catalogues (16 locales) consumed by Manager/Renderer strings |
| `Support\Version`   | `--version` build-stamp resolution — the phar archive's own stamp wins when launched from a stamped phar; a source checkout reports `dev-master` |
| `Support\Home`      | Launcher home-directory cascade (CF-18) — POSIX `HOME`, then Windows `USERPROFILE`, then `HOMEDRIVE`+`HOMEPATH`, `/` last |
| `Support\Archive`   | Archive format detection, `a.zip!/inner` virtual paths, the safe-name normaliser and the cached read-only index (CF-33) |
| `Support\ArchiveExtractor` | Loop-sliced extraction into a private staging tree with the bomb meter (CF-33) |
| `Support\TarReader` | Forward-only ustar/GNU/pax tar reader over plain or compress.zlib/bzip2 streams (CF-33) |
| `Support\Bookmarks` | Atomic JSON bookmark store under `$XDG_DATA_HOME` (CF-30) |
| `ManagerBuilder`    | Fluent builder for `Manager` — avoids 26-argument positional construction   |
| `Msg/CopyCompletedMsg` | Async-copy completion Msg dispatched by the Program into `Manager::update()` |

## Test plan

- 555 tests / 9510 assertions — this pair is hand-typed: candy-files runs its
  suite lib-local and its totals are not part of the repository-wide pinned
  count, so re-derive them by running `vendor/bin/phpunit` in this directory
  whenever tests change.
- Pure-state coverage: `Entry` (size formatting, parent sentinel, escape-sequence sweep incl. OSC/CSI/generic-ESC), `Sort` (every order × dirs-first × cycle), `Pane` (open / relist-with-selection-preservation / navigate / move / select / sort / hidden toggle / parent-path / join)
- Lister contract (CF-22): `null` answers "cannot read" and is never collapsed to "empty" — a refused `navigate`/`goUp` keeps the last honest listing, `relist` preserves cursor + selection through the outage, the pane header carries the localized `(unreadable)` marker, `r` names the refusal instead of lying `refreshed`, the launcher refuses a missing or unreadable argv directory (`Support\LaunchDir`), and `FsLister` splits an empty dir (`[]`) from a 0000 one (`null`)
- `Manager` integration: Tab swap, key dispatch per pane, confirm gate (`d` arms, `y` or the prompt's taught localized affirmative confirms, anything else cancels; modified chords never confirm), refresh status, Escape clears selection, resize capture (`WindowSizeMsg` → derived widths **and rows**, other non-key Msgs dropped)
- `t` duplicates the current tab; `Manager::openNewTab(path)` lands a tab on the given directory as an API, and since CF-29 a key binding DOES carry a path: `Ctrl+O` opens the typed-path prompt in the active pane. CF-23 made both honest: the duplicate carries BOTH pane cwds and the active side, and every tab mutator re-projects the flat left/right/activeIdx mirror onto the current tab, so the Tab flip and the renderer can never disagree again.
- Async copy end-to-end: the `c`/`y` command is executed against the real event loop and its `CopyCompletedMsg` re-enters `update()` — status, undo entry and refreshed destination pane are all asserted, plus the error-count path, the skipped-name path, the overwrite announcement and undo-recording-successes-only
- Status-line injection defense: filenames carrying raw ESC/OSC bytes render inert in every rename/copy/move message (hostile-input tests), plus structural tests for the rename containment boundary and the single copyDir home
- Renderer: cell-width math for CJK names (size column stays aligned), tab-bar clipping never splits a codepoint, search echo is sanitized, pane widths follow the terminal
- `LangCoverageTest` enforces translation-key coverage in both directions (every used key exists; no unused key lingers); `LocaleParityTest` pins all 16 shipped locales to the English key set with identical placeholder tokens
- Localized chrome (CF-16): search prompt, DIR/LINK size tokens and the sort header label ride the catalogues (dynamic size-column width, byte-stable at `en`), and the launcher activates the detected locale before its first printed byte (order-pinned; German `--help` proven in a pinned child environment)
- Symlink rows (CF-45): a link's row names its target in the same leg (`mylink -> /dir`), the size column answers `LINK` even for a link-to-directory, and a target that does not resolve is tagged `(broken)`
- Normalization-insensitive matching (CF-48): search and pattern-select compare names NFC-normalized on both sides — a composed query finds macOS's decomposed bytes and vice versa — while display and selection keep the raw on-disk name
- Extension sort (CF-46): `s` cycles an eighth order — names group by the suffix after the last dot (case-folded; a dot-file like `.bashrc` has no suffix and groups first) — and `e` selects the field directly instead of cycling to it. The order is total (suffix → mtime → name), so an unchanged listing re-sorts identically every pass; the field is remembered per pane like every other sort, with no per-directory field memory (deliberately scoped out)
- Bracketed paste (CF-49): the launcher turns on DEC mode 2004, so a paste reaches the app as ONE atomic edit instead of a keystroke burst — it lands in the rename/mkdir/path/pattern/search buffer that owns the keyboard (escape bytes already stripped by the input sanitizer, newline and tab runs flattened to spaces), a pasted Enter therefore never commits anything, and a paste arriving with no collecting buffer is dropped rather than executed
- Recursive search (CF-17): `Ctrl+R` inside search mode arms a budgeted, tick-sliced find over the pane's tree — depth 8, 20 000 names examined, four screens of hits — breadth-first so shallow matches fill the budget first, hidden entries pruned at the walk (search never surfaces what the UI hides), unreadable directories skipped, symlinks never followed (a bind-mount loop cannot trap the walk), and the result rows carry the path relative to the pane so two same-named leaves are tellable apart. The chord is deliberate: a bare letter toggle would re-hijack an ordinary query character — the exact defect CF-09 buried. Every cap that fires flips the status to "search partial" instead of silently presenting a slice as truth; the walk answers through the same promise→Msg→`update()` circuit as async copy, so a keystroke burst mid-wait simply outlives its own answer (a resolution whose query no longer matches is dropped), and `Esc` dissolves the recursive wish along with the mode. `matchAll` ranking is shared by both corpora, so a hit two levels down competes on exactly the local results' scale
- Glyph tiers (CF-35): `Support\GlyphSet` picks unicode vs strictly-ASCII chrome from lang/charset/TERM (deterministic table units, `CANDY_FILES_GLYPHS` escape hatch), the ascii frame carries no Ambiguous glyph and fits `termCols`, and user filenames keep their bytes on every tier
- Two-press quit (CF-44): `q` arms instead of exiting while copy batches are in flight, the second `q` completes the quit, any other key drops the armed question, and the test drives the real schedule seam rather than a mock count

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

`Manager` carries 26 constructor parameters (every transition is a copy, so the fields are all promoted `readonly` values). Use the fluent builder for readability and to avoid parameter-order mistakes:

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
    ->withTermRows(0)
    ->build();
```

The builder covers the eighteen founding fields; the state that only the
machine itself produces (recursive-search results and wish, in-flight copy
count, the armed-quit flag, the help overlay, the per-directory selection
stash, the memo invalidator) is constructed internally with safe defaults and
is deliberately not exposed.

The direct constructor is kept for backward compatibility only — new code should use `Manager::builder()`.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: [`yorukot/superfile`](https://github.com/yorukot/superfile).
