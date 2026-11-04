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

candy-git — a three-pane git TUI for PHP 8.3+, built on the SugarCraft stack. Status / branches / log laid out side-by-side, single-key stage / unstage, refresh, and ahead/behind branch summary.

```bash
composer require sugarcraft/candy-git
vendor/bin/candy-git    # run inside any git working tree
```

From a clone of this repository, `php candy-git/bin/candy-git` works too, and
release users can grab the `candy-git.phar` from the GitHub release and run
`php candy-git.phar`. `--version` and `--help` answer anywhere; any other
argument exits 2 with a usage line rather than being silently ignored.

## Requirements

- PHP 8.3+
- git **2.23 or newer** — the floor for `git restore` and the `--porcelain=v1`
  status engine. candy-git checks `git --version` at launch and refuses older
  binaries (or a missing git) with one readable sentence, exit code 1.
- An interactive terminal: the UI is a full-screen alt-buffer program, so a
  piped stdout is refused instead of rendering escape-sequence soup.
- A checked-out work tree — bare repositories have no status pane to show and
  are refused at launch.

See **Trust boundary** below for what candy-git disables inside a repository's
own git config.

## Keys

| Key                      | Action                                                  |
|--------------------------|---------------------------------------------------------|
| `Tab`                    | Cycle pane focus                                        |
| `↑/↓` or `k/j`           | Move cursor in active pane                             |
| `PageUp/PageDown`        | Jump the cursor a paneful in active pane                |
| `Home/End`               | Jump the cursor to the first / last entry               |
| `s` (status pane)        | Stage / unstage the highlighted entry                    |
| `a` (status pane)        | Stage all files                                         |
| `d` (status pane)        | Discard changes to the highlighted file (press twice)    |
| `P` (status pane)        | Open diff viewer for the highlighted file                |
| `Space` (branches pane)  | Checkout branch                                         |
| `c`                      | Commit (type message, Enter to confirm, Esc to cancel) |
| `A`                      | Amend last commit                                        |
| `n`                      | Create and switch to a new branch                        |
| `u`                      | Undo last operation                                    |
| `Ctrl+r`                 | Redo last undone operation                             |
| `D` (branches pane)      | Delete the selected branch (press twice)                 |
| `M`                      | Merge selected branch into current                     |
| `r`                      | Open rebase menu (continue / abort / skip / cancel)   |
| `S`                      | Open stash manager (list / apply / drop; drop asks twice)   |
| `V`                      | Cherry-pick — type a commit ref and press Enter       |
| `w` (branches pane)     | Open worktrees manager (list / add / remove)            |
| `i`                      | Interactive rebase todo list — preview only; the todo is not yet executed |
| `R`                      | Refresh from disk                                       |
| `?`                      | Context-sensitive help                                  |
| `q`                      | Quit — except while typing in a text buffer, where `q` is just a letter |
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
| `Space`          | Stage current hunk              |
| `↑/↓` or `k/j`   | Navigate between hunks         |
| `PageUp/PageDown`| Navigate between hunks         |
| `Home/End`       | First / last hunk              |
| `Esc`            | Close diff viewer              |

## Architecture

| File               | Role                                                                                                                             |
|--------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| `Git`             | Concrete `git` shell-out — `status --porcelain=v1 -b`, `for-each-ref`, `log --pretty=format:…`, `add`, `restore --staged`. Throws `RuntimeException` on non-zero exit. |
| `GitDriver`        | Interface for all git operations. Tests inject a fixture-backed driver so transition correctness is asserted without staging a real repo. |
| `Process`          | The ONLY proc_open site — bounded drain loop, pinned child env (`GIT_TERMINAL_PROMPT=0`, pagers and editors neutered), stdin isolated from the UI tty, TERM->grace->KILL teardown. |
| `HistoryEntry`     | One undoable operation with its forward args and inverse (or a `refuse` marker when undo would lie — discard, amend, merge). |
| `HistoryManager`   | Bounded undo/redo stacks (`MAX_HISTORY`); a new operation clears the redo lane. |
| `DiffViewer`       | Immutable diff overlay state — raw lines, hunk starts, selected hunk; `stagePatch()` turns the selection into an `apply --cached` patch. |
| `DiffHighlighter`  | Per-line hunk-header/old/new token spans for the diff overlay. |
| `Lang`             | i18n lookup over `lang/*.php` (16 locales) on the core `T` registry, namespace `git`. |
| `Support\\Version`  | `--version` line + phar build stamping (dev-build detection mirrors candy-top's DST-1 shape). |
| `Support\\GitRequirement` | The git-version floor (`MINIMUM = '2.23'`) as pure parse/compare so launcher and tests share one authority. |
| `Pane`            | Enum — `Status`, `Branches`, `Log` — with `next()` for Tab cycling.                                                               |
| `App` (Model)     | Owns the three lists + cursors + focus + overlays (stash/cherry-pick/worktrees/interactive-rebase). Pure-state — every key returns a fresh App. |
| `Renderer`        | Pure view function — three `Style::border(rounded)` panes joined horizontally / vertically; the focused pane gets a brighter accent. |
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

**Available keys** (`lang/en.php`):

| Key                    | Default string                                                    | Parameters  |
|------------------------|-------------------------------------------------------------------|------------|
| `git.spawn_failed`      | `git: failed to spawn`                                           | —          |
| `git.error`            | `git: {stderr}`                                                  | `{stderr}` |
| `cli.not_a_repo`       | `candy-git: not a git repository: {cwd}`             | `{cwd}`    |
| `ui.error_prefix`      | `error: `                                                        | —          |
| `status.clean`          | `clean working tree`                                            | —          |
| `branches.empty`       | `(no branches)`                                                  | —          |
| `log.empty`            | `(empty log)`                                                    | —          |
| `help.keyhints`         | `S  stash  ·  V  cherry-pick  ·  i  rebase-i  ·  w  worktrees  ·  tab  switch pane  ·  j/k  move  ·  s  stage/unstage  ·  a  stage all  ·  d  discard  ·  P  diff  ·  space  checkout  ·  c  commit  ·  A  amend  ·  n  new branch  ·  R  refresh  ·  u  undo  ·  M  merge  ·  r  rebase  ·  ?  help  ·  q  quit` | —          |

To add a locale, copy `lang/en.php` to `lang/<code>.php` and translate the
values. The lookup chain follows `SugarCraft\Core\I18n\T`:
exact locale → base language → `en` → raw key.

**Using the facade:**

```php
use SugarCraft\Git\Lang;

$msg = Lang::t('status.clean');                 // 'clean working tree'
$hint = Lang::t('help.keyhints');               // 'tab  switch pane  ·  ...'
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
  `false` (git's inert exit-1 builtin): a hostile clone then produces a plain
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

candy-git uses [sugar-fuzzy](https://github.com/detain/sugarcraft#sugar-fuzzy) for Smith-Waterman scored matching infrastructure, laying the groundwork for future fuzzy stash search.

## Test

```bash
composer install
vendor/bin/phpunit
```

## Credits & inspiration

Design antecedent: [jesseduffield/lazygit](https://github.com/jesseduffield/lazygit); SugarCraft is developed as a native PHP project.
