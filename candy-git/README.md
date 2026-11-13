<img src=".assets/icon.png" alt="candy-git" width="160" align="right">

# CandyGit

> Formerly published as `sugarcraft/sugar-stash` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Stash\` → `SugarCraft\Git\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-git)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-git)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-git?label=packagist)](https://packagist.org/packages/sugarcraft/candy-git)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/play.gif)

candy-git — a three-pane git TUI for PHP 8.3+, built on the SugarCraft stack. Status on the left, branches stacked over the log on the right; single-key stage / unstage, refresh, and an ahead/behind summary for branches that track an upstream.

```bash
composer require sugarcraft/candy-git
vendor/bin/candy-git    # run inside any git working tree
```

From a clone of this repository, run `composer install` inside `candy-git/`
first — `vendor/` is gitignored, and without it the launcher exits with
`candy-git: cannot find composer autoload.php`. Then `php candy-git/bin/candy-git`
works. Release users can grab the `candy-git.phar` from the GitHub release and run
`php candy-git.phar`. Until a stable `candy-git/v*` tag is cut, the only release
channel is the rolling `candy-git/latest` prerelease. `--version`, `--help`, and the
`-h` alias short-circuit and exit 0 anywhere; any *other* argument exits 2 with a
usage line rather than being silently ignored.

## Requirements

- PHP 8.3+
- git **2.23 or newer** — the hard floor is `git restore` (candy-git's discard
  and unstage engine); the `--porcelain=v1` status engine predates it. candy-git
  checks `git --version` at launch and refuses older binaries (or a missing git)
  with one readable sentence, exit code 1.
- An interactive terminal: the UI is a full-screen alt-buffer program, so a
  piped stdout is refused instead of rendering escape-sequence soup.
- A checked-out work tree — bare repositories have no status pane to show and
  are refused at launch.

- **Operating systems.** Linux and macOS use the pipe-based runner. On Windows
  the runner takes a polled temp-file ladder instead (`stream_select` cannot poll
  `proc_open` pipes there; child stdin is the `NUL` device or a payload file, and
  a timed-out child's process tree is stopped with `taskkill /F /T`). The OS
  differences live behind the injectable `Platform` seam, both branches are
  unit-tested on every host, and `candy-git` is in the Windows CI leg
  (`WINDOWS_LIBS`) so the real thing is exercised on `windows-latest`. A launch
  failure that is not a missing git (the binary is on PATH but would not start)
  prints the OS reason instead of the install hint.

See **Trust boundary** below for what candy-git disables inside a repository's
own git config.

## Scope (v1)

candy-git v1 covers the local-repository loop: status / stage / unstage /
discard, branches (checkout, create, delete), log, the diff viewer, commit and
amend, merge, cherry-pick, the sequencer menu (continue / abort / skip), stash
list / apply / drop, worktrees (list / add / remove), an interactive-rebase todo
preview, and undo / redo of the last operation.

Not in v1 (absent, not hidden behind a key): **remotes** (fetch, pull, push,
remote management), **tags**, **reflog**, **bisect**, **submodules**,
hunk- and line-level **patch staging**, an in-app **conflict-resolution** view,
creating a stash, and *executing* the interactive-rebase todo (it is a preview
today). Every git call is a synchronous, timeout-bounded subprocess on the UI
thread; moving git work onto the ReactPHP loop as async commands (so slow
remotes cannot stall rendering) is the v2 direction and the prerequisite for the
remote family.

## Keys

| Key                      | Action                                                  |
|--------------------------|---------------------------------------------------------|
| `Tab`                    | Cycle pane focus                                        |
| `↑/↓` or `k/j`           | Move cursor in active pane                             |
| `PageUp/PageDown`        | Jump the cursor a paneful in active pane                |
| `Home/End`               | Jump the cursor to the first / last entry               |
| `s` (status pane)        | Stage / unstage the highlighted entry — on conflicted or untracked rows the first press stages (there is no staged side to reverse) |
| `a` (status pane)        | Stage all files                                         |
| `d` (status pane)        | Discard changes to the highlighted file (press twice)    |
| `P` (status pane)        | Open diff viewer for the highlighted file                |
| `Space` (branches pane)  | Checkout branch                                         |
| `c`                      | Commit (type message, Enter to confirm, Esc to cancel) |
| `A`                      | Amend last commit                                        |
| `n`                      | Create and switch to a new branch                        |
| `u`                      | Undo last operation                                    |
| `Ctrl+r`                 | Redo last undone operation                             |
| `D` (branches pane)      | Delete the selected branch (press twice) — refuses to delete the current branch |
| `M`                      | Merge — prompt for a branch/ref to merge into current (free text; the selected row is never read) |
| `r`                      | Open rebase menu (continue / abort / skip) — when in progress only: opens while a rebase or cherry-pick has stopped; on an idle repo it reports "no rebase in progress" |
| `S`                      | Open stash manager (list / apply / drop; drop asks twice)   |
| `V`                      | Cherry-pick — type a commit ref and press Enter       |
| `w` (branches pane)     | Open worktrees manager (list / add / remove) — path collection refuses option-injection and `..` traversal; it does not jail the destination inside the repository |
| `i`                      | Interactive rebase todo list — preview only; the todo is not yet executed |
| `R`                      | Refresh from disk                                       |
| `?`                      | Context-sensitive help                                  |
| `q`                      | Quit — while an overlay is open it closes the innermost one (same chain as `Esc`), and while typing in a text buffer `q` is just a letter |
| `Ctrl+C`                 | Quit — the same chord as `q` |
| `Esc`                    | Cancel the innermost overlay/collection; quits when nothing is open    |

### Text-entry keys

While collecting text (commit message with `c`, branch name with `n`,
merge target with `M`, cherry-pick ref with `V`, worktree path with `a`
in the worktrees overlay): printable keys and `Space` append, `Backspace`
deletes the last character, a bracketed `paste` inserts the (sanitized,
newline-flattened) text, `Enter` confirms, and `Esc` cancels.

### Diff viewer keys

| Key              | Action                          |
|------------------|--------------------------------|
| `Space`          | Stage the current hunk — then the viewer closes and the status pane refreshes; re-open (`P`) for the next hunk |
| `↑/↓` or `k/j`   | Navigate between hunks         |
| `PageUp/PageDown`| Navigate between hunks         |
| `Home/End`       | First / last hunk              |
| `Esc`            | Close diff viewer              |
| `d`              | Close diff viewer (same action as `Esc`) |

### Overlay keys

**Sequencer menu** — opens on `r` while a rebase or cherry-pick has stopped
on a conflict; the prompt names the armed operation:

| Key              | Action                                          |
|------------------|--------------------------------------------------|
| `c`              | Continue the stopped rebase / cherry-pick       |
| `a`              | Abort it                                        |
| `s`              | Skip the stopped commit — rebases only; a cherry-pick stop is `c`/`a` only, dropping commits belongs to the interactive-rebase todo |
| `Esc`            | Close the menu (the operation stays stopped)    |

**Stash manager** (`S`):

| Key              | Action                                          |
|------------------|--------------------------------------------------|
| `j/k` or `↑/↓`  | Move between entries                            |
| `a`              | Apply the selected stash                        |
| `d`              | Drop the selected stash (press twice)           |
| `Esc`            | Close                                           |

**Worktrees** (`w`):

| Key              | Action                                          |
|------------------|--------------------------------------------------|
| `j/k` or `↑/↓`  | Move between worktrees                          |
| `a`              | Start adding a worktree — type the path, `Enter` confirms (relative paths anchor to the repo root; an absolute path is used as given; `..` segments are refused) |
| `d`              | Start removing the selected worktree — `Enter` confirms |
| `c`              | Cancel a pending add/remove                     |
| `Esc`            | Close the whole overlay (even mid-add)          |

**Interactive rebase** (`i`) — opens asking for a commit count:

| Key              | Action                                          |
|------------------|--------------------------------------------------|
| digits + `Enter` | Choose how many commits to rebase               |
| `j/k` or `↑/↓`  | Move through the todo                           |
| `Space` or `l`   | Cycle the entry's action (pick → reword → edit → squash → drop) |
| `d`              | Drop the selected entry                         |
| `Esc`            | Close                                           |

**Help (`?`):** context-sensitive — it covers the general keys plus the
Status and Branches panes, and closes the help overlay itself when pressed
while help is open. `?` is not delivered to the global handler while another
overlay (sequencer menu, stash, worktrees, cherry-pick, interactive rebase,
diff viewer, or a text buffer) is open — close that layer first.

## Accessibility

Every state the UI needs you to know is carried by **text**, not by color
alone: status rows show git's porcelain `XY` codes, diff lines keep their
leading `+`/`-` markers, the rebase todo spells its actions as words
(`pick`/`reword`/`squash`/`drop`), and the ahead/behind summary is git's own
`## …[ahead N, behind M]` upstream header rendered verbatim — so the panes
stay readable under `NO_COLOR`, `TERM=dumb`, or an ASCII-only palette. Pane
**focus** rides two channels too: the accent
border *and* a `▸` text marker prefixed to the focused pane's title — the
marker carries no color, so tab cycling stays visible under `NO_COLOR`,
`TERM=dumb`, and an ASCII-only palette.

- **Row cursor** — the selected row is rendered with reverse video (SGR 7),
  a channel that survives color stripping on every terminal we target.
- **Text buffers** — commit messages, branch names, and merge/cherry-pick
  targets are append-only: printable keys and `Space` append, `Backspace`
  deletes the last character, and a bracketed `paste` inserts the whole
  (sanitized, newline-flattened) blob, so screen readers and keyboard-only
  flows never need arrow navigation inside an input.
- **Palette contrast (design note)** — the hint strip (`#7d6e98`) lands at
  roughly 3.4:1 and the blurred unfocused border (`#4a3868`) at roughly
  2.1:1 against the dark background the demos ship with; actual ratios move
  with the user's terminal palette. These are observations queued for a
  future palette pass — no WCAG conformance is claimed here.

## Architecture

| File               | Role                                                                                                                             |
|--------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| `Git`             | Concrete `git` shell-out — `status --porcelain=v1 -b`, `for-each-ref`, `log --pretty=format:…`, `add`, `restore --staged`; `stagePatch()` pipes a selected hunk through `git apply --cached --unidiff-zero -`. Throws `RuntimeException` on non-zero exit. |
| `GitDriver`        | Interface for all git operations. Tests inject a fixture-backed driver so transition correctness is asserted without staging a real repo. |
| `Platform`         | OS seam for `Process` — null device (`/dev/null` / `NUL`), PATH lookup, tree-kill (`taskkill /F /T`) — injectable so both ladders are tested on any host. |
| `Process`          | The ONLY proc_open site — bounded drain loop (a polled temp-file loop on Windows), pinned child env (`GIT_TERMINAL_PROMPT=0`, pagers and editors neutered), stdin isolated from the UI tty, TERM->grace->KILL teardown. |
| `HistoryEntry`     | One undoable operation with its forward args and inverse (or a `refuse` marker when undo would lie — discard, amend, merge, plus a checkout with no origin to return to and a branch delete whose pre-delete SHA was not recorded). |
| `HistoryManager`   | Bounded undo/redo stacks (`MAX_HISTORY`); a new operation clears the redo lane. |
| `DiffViewer`       | Immutable diff overlay state — raw lines, hunk starts, selected hunk; `hunkCount()` / `withHunkCursor()` drive navigation and `currentHunkPatch()` yields the selection to stage (applying it is `Git::stagePatch()`). |
| `DiffHighlighter`  | Per-line hunk-header/old/new token spans for the diff overlay. |
| `Lang`             | i18n lookup over `lang/*.php` (16 locales) on the core `T` registry, namespace `git`. |
| `Support\Version`  | `--version` line + phar build stamping — the archive-first read mirrors candy-top's version channels: the stamp of the phar the process launched from wins, and the `dev-build` sentinel is never read as a version. |
| `Support\GitRequirement` | The git-version floor (`MINIMUM = '2.23'`) as pure parse/compare so launcher and tests share one authority. |
| `Pane`            | Enum — `Status`, `Branches`, `Log` — with `next()` for Tab cycling.                                                               |
| `App` (Model)     | Owns the three lists + cursors + focus + overlays (stash/cherry-pick/worktrees/interactive-rebase). Pure-state — every key returns a fresh App. |
| `Renderer`        | Pure view function — three `Style::border(rounded)` panes joined horizontally / vertically; the focused pane gets a brighter accent **and** a `▸` text prefix on its title, so focus survives color stripping. |
| `StashEntry`        | Immutable stash entry — `index`, `sha`, `branch`, `message` + `stashRef()` / `displayLine()`.                                      |
| `StashManager`     | Immutable stash list state — `stashes`, `cursor`, `withCursor()`, `withStashes()`, `current()`, `count()`.                        |
| `CherryPick`       | Immutable cherry-pick state — `collecting` flag + `commitRef` accumulator + `withChar()`/`cancel()`.                              |
| `WorktreeEntry`    | Immutable worktree entry — `path`, `branch`, `isBare`, `HEAD`.                                                                     |
| `Worktrees`        | Immutable worktree manager state — `worktrees`, `cursor`, `adding`/`removing` modes + path collection (new worktrees branch from HEAD).                     |
| `RebaseAction`      | Enum — `Pick`, `Reword`, `Edit`, `Squash`, `Drop`.                                                                               |
| `RebaseCommit`      | Immutable rebase todo entry — `sha`, `subject`, `action` + `withAction()` / `displayLine()`.                                      |
| `InteractiveRebase` | Immutable interactive-rebase state — `commits` todo list, `cursor`, `selectingN`/`countInput`, `cycleAction()`, `dropCurrent()`.    |

`CandyGit` shells out to `git` directly via the system, so users keep their existing commit hooks and signing config — while the repo-controlled executable hooks that could fire unasked (`core.fsmonitor`, `diff.external`, and any `merge.<name>.driver` the repository's own config defines) are switched off, and git filter drivers (`filter.*.clean|smudge|process`) are detected and announced rather than switched off — disarming those would break git-lfs-class tooling. See **Trust boundary**.

**Note:** All git operations are synchronous and block the event loop. This is by design for v1 — a TTY application naturally runs synchronously at the terminal. Async git operations are planned for v2 (see `GitDriver` interface for deprecation notes).

## Internationalization

User-facing strings are internationalized via `SugarCraft\Git\Lang::t()`.
All translatable strings live in `lang/en.php` under the `'git'` namespace.

**Namespacing:** `Lang::t()` bakes the `git` namespace into every lookup, so a
file key like `git.timeout` resolves through the core `T` registry as
`git.git.timeout`. Always call through `SugarCraft\Git\Lang`; reaching for
`T::translate('git.error')` directly misses the registered key and returns the
raw string.

**Available keys** (`lang/en.php` — an excerpt; the file is the full roster
of 137 keys):

| Key                    | Default string                                                    | Parameters  |
|------------------------|-------------------------------------------------------------------|------------|
| `git.spawn_failed`      | `git: failed to spawn`                                           | —          |
| `git.error`            | `git: {stderr}`                                                  | `{stderr}` |
| `git.timeout`          | `git: command timed out after {seconds}s`                        | `{seconds}` |
| `git.unsafe_path`      | `git: unsafe worktree path: {path}`                              | `{path}`   |
| `git.unsafe_merge_driver` | `git: the repository defines a merge driver that cannot be safely disabled; merge refused` | — |
| `git.unsafe_filter_driver` | `git: the repository defines a filter driver; strict filter mode refused the command` | — |
| `cli.not_a_repo`       | `candy-git: not a git repository: {cwd}`             | `{cwd}`    |
| `cli.unknown_flag`     | `candy-git: unknown argument: {flag}`                             | `{flag}`   |
| `ui.error_prefix`      | `error: `                                                        | —          |
| `status.clean`          | `clean working tree`                                            | —          |
| `branches.empty`       | `(no branches)`                                                  | —          |
| `log.empty`            | `(empty log)`                                                    | —          |
| `help.keyhints`         | `S  stash  ·  V  cherry-pick  ·  i  rebase-i  ·  w  worktrees  ·  tab  switch pane  ·  j/k  move  ·  s  stage/unstage  ·  a  stage all  ·  d  discard  ·  P  diff  ·  space  checkout  ·  c  commit  ·  A  amend  ·  n  new branch  ·  R  refresh  ·  u  undo  ·  M  merge  ·  r  rebase  ·  ?  help  ·  q  quit` | —          |

**Switching locale:** 16 locales ship (`lang/*.php`). The `bin/candy-git`
launcher adopts the environment automatically at startup via
`T::setLocale(T::detect())` — the core chain reads `LC_ALL`, then
`LC_MESSAGES`, then `LANG`, strips the encoding suffix (`de_DE.UTF-8` → `de`),
and falls back to `en` (`C` and `POSIX` count as unset). So
`LANG=de_DE.UTF-8 candy-git` gives a German UI. Embedders drive it directly
with `SugarCraft\Core\I18n\T::setLocale('de')`.

To add a locale, copy `lang/en.php` to `lang/<code>.php` and translate the
values. The lookup chain follows `SugarCraft\Core\I18n\T`:
exact locale → base language → `en` → raw key.

**Using the facade:**

```php
use SugarCraft\Git\Lang;

$msg = Lang::t('status.clean');                 // 'clean working tree'
$hint = Lang::t('help.keyhints');               // 'S  stash  ·  V  cherry-pick  ·  i  rebase-i  ·  …'
$err = Lang::t('ui.error_prefix') . $error;     // 'error: <msg>'
```

**Adding new translatable strings:**

```php
// In any source file:
use SugarCraft\Git\Lang;

// Simple key:
$msg = Lang::t('status.clean');

// With placeholder:
$msg = Lang::t('git.error', ['stderr' => $stderr]);
```

## Demos

### Hero tour

The gif at the top of this README is `.vhs/play.gif`: `play.tape` drives
`examples/play.php` — a fixture-backed, repo-less build of the same TUI — so
the tour is hermetic and renders identically on every machine. It is the
friendliest first look: no repository required.

### Staging workflow

![stage](.vhs/stage.gif)

> `stage.tape` drives the real `./bin/candy-git` against the CI checkout, so its
> rows are environment-dependent; the hero demo above (`play.tape`) is hermetic.

## Trust boundary

Opening a repository executes its configuration unless told otherwise, and git
has several config keys that name **executables**. CandyGit runs every git
call through a fixed argument prelude that disarms the repo-controlled
execution channels it can neutralize without breaking legitimate tooling;
what it cannot disarm safely, it detects and discloses:

- `-c core.quotepath=off` — git octal-escapes non-ASCII path bytes in porcelain
  output by default; the prelude pins the flag off so a repository with unicode
  filenames lists real paths in the status pane and stage/discard targets
  round-trip correctly.
- `-c core.fsmonitor=false` — `core.fsmonitor` (in the cloned repo's
  `.git/config`) names a program git invokes on the very first `status`; a
  hostile clone would otherwise achieve instant code execution before you see
  a pane. `diff.external` is the same class of hazard and is disabled with
  `--no-ext-diff` on every diff.
- Merge drivers are enumerated and neutralized on every merge-capable
  command. `merge.<name>.driver` is the same species of executable-naming
  key: one `.gitattributes` line (`*.txt merge=evil`) plus a driver definition
  in the repository's *own* config — local, `config.worktree`, or pulled in
  through `include.path` — sends git's 3-way merge machinery to an
  attacker-chosen program, and a merge is exactly what a conflict prompts
  you to do. Before running `merge`, `rebase --continue`/`--skip`,
  `cherry-pick`, or `stash apply`/`pop`, CandyGit lists every reachable
  `merge.*.driver` key in the merged config scope and overrides each one with
  `false` — git launches driver values as programs, so this resolves through
  PATH to the inert exit-1 coreutils binary rather than anything git-internal:
  a hostile clone then produces a plain
  CONFLICT instead of code execution. If a driver key exists in a shape that
  cannot be re-expressed as an override, the merge is *refused*, not run —
  and because the enumeration reads the merged config scope, such a key in
  *your own* global or system config refuses CandyGit merges in every
  repository you open; plain `git` in a terminal is unaffected. A second
  side effect, disclosed on purpose: **your own** legitimate custom merge
  drivers — global, system, or repo — never execute under CandyGit merges.
- Filter drivers (`filter.<name>.clean|smudge|process`) are the same class of
  repo-controlled executable — `git add`, `commit`, a branch-switching
  `checkout`, `reset`, `restore`, `stash apply` and friends fire them through
  `.gitattributes`. They are **detected and announced, not disarmed**, on
  purpose: git-lfs and other real filter users depend on their drivers
  *working*, and silently forcing every filter to `false` would corrupt the
  files those tools manage — a worse defect than the disclosed trust gap.
  Detection is value-granular over the merged config (`--list -z
  --show-origin`, so multi-line driver values cannot forge their way past a
  line-matching baseline) minus the origins and values the *operator's own*
  global/system chains define: what remains are the lanes **the repository
  itself defines**. On every filter-capable command CandyGit prints a stderr
  notice naming those lanes (re-announcing when the set changes) and
  proceeds — the drivers run, exactly as plain `git` would; only lane names
  are printed, never config values. Two disclosures: (1) a repo row whose
  full value byte-matches your global/system value runs *your own* command
  string and stays notice-silent by design — the git-lfs-class nagging the
  disarm ruling rejected — but it still counts in strict mode; (2) strict
  refuses repo *definitions*, not filters per se: a repo with no filter
  config whose `.gitattributes` still routes paths to your own global
  git-lfs runs that trusted driver in every mode. Set
  `CANDY_GIT_STRICT_FILTERS=1` (env) or `withStrictFilters()` (API) to turn
  the posture into a refusal of every filter-capable command on which the
  repository defines at least one lane — including the identical-value
  shadow above. Threat model: a hostile clone that ships `.gitattributes` +
  filter config gets code execution the moment you stage or check out —
  clone, open, and operate only what you trust, and use strict mode if you
  script CandyGit over untrusted directories.
- Child environment is pinned: `GIT_TERMINAL_PROMPT=0`, `GIT_PAGER=cat`,
  `GIT_EDITOR`/`GIT_SEQUENCE_EDITOR=true` (no credential, pager, or editor
  can hijack the TUI's terminal), and `LC_ALL=C`/`LANG=C` because the
  porcelain parsers depend on git's canonical English byte shapes.
- The child's stdin is `/dev/null` unless we deliberately pipe a patch into
  `git apply`, so no git subprocess can read (or block on) your keystrokes.

Deliberately **out of scope**: git's own commit machinery still runs when you
ask CandyGit to commit — that means the standard `pre-commit`, `commit-msg`,
`post-checkout`, and friends, *and* the same redirections that make them
repo-controlled: `core.hooksPath` (a cloned repo can point it at any path,
including one outside itself, and the hooks there fire on your commit) and
`include.path`-delivered hook settings. These are not disarmed on purpose:
running a project's hooks as `false` would silently delete somebody's commit
gate, which is a correctness harm the app has no license to inflict on a
repository you chose to commit in. The exec exception stays bounded and
honest: hooks fire exactly when you invoke a command whose normal git
behavior includes them, never unasked. Treat repository hooks as executing
code: clone, open, and commit only what you trust.

## Shared foundations

candy-git uses [sugar-fuzzy](https://sugarcraft.github.io/lib/sugar-fuzzy.html): `StashManager::fuzzyFilter()` is a live, tested public API backed by its Smith-Waterman scored matcher, ranking stash entries by message and branch. The stash overlay UI is not wired to it yet — that integration is the future work, not the matching engine.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Credits & inspiration

Design antecedent: [jesseduffield/lazygit](https://github.com/jesseduffield/lazygit); SugarCraft is developed as a native PHP project.
