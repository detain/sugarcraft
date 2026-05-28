# SugarCraft/sugar-stash

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-stash
- **Language:** PHP 8.3+
- **License:** MIT
- **Status:** 🟢 v1 Ready
- **Description:** Three-pane git TUI — port of `jesseduffield/lazygit` on the SugarCraft stack. Shells out to the system `git` binary for all operations, preserving user aliases, hooks, and signing config.

## Architecture Overview

### Core Design: GitDriver Interface Pattern

sugar-stash employs a **pluggable git backend** architecture with a clean interface/implementation split:

```
GitDriver (interface)
    |
    +-- Git (concrete: shells out to system git binary)
    +-- FixtureGit (test doubles, injected in tests)
```

**`GitDriver` interface** (`src/GitDriver.php` — 117 lines) defines all git operations:
- `status()` — `git status --porcelain=v1 -b`
- `branches()` — `git for-each-ref --format=... refs/heads`
- `log(int $limit)` — `git log --pretty=format:...`
- `stage(string $path)` / `unstage(string $path)` / `stageAll()`
- `discard(string $path)` — `git restore --worktree`
- `commit(string $message)` / `amend()` / `reset()`
- `stagePatch(string $path, string $hunk)` — `git apply --cached`
- `createBranch(string $name)` / `deleteBranch(string $name)`
- `merge(string $branch)` / `rebaseContinue/Abort/Skip()`
- `stashList/Apply/Drop()` / `cherryPick()` / `worktreeList/Add/Remove()`

**`Git` class** (`src/Git.php` — 246 lines) is the concrete implementation:
- Uses `proc_open()` with piped stdout/stderr
- Passes `-C <cwd>` flag for all commands
- Throws `RuntimeException` on non-zero exit
- `runPatch()` method for hunk staging (stdin + stdout separately managed)

### Three-Pane Layout

The UI is a **three-pane terminal layout**:

```
┌─────────────────┬──────────────────────┐
│                 │     branches         │
│    status       ├──────────────────────┤
│   (left pane)   │        log           │
│                 │   (bottom right)     │
└─────────────────┴──────────────────────┘
```

- **Status pane (left):** `git status --porcelain=v1 -b` parsed into index/work status + path
- **Branches pane (top right):** `git for-each-ref` showing HEAD-marked branch + short SHA
- **Log pane (bottom right):** `git log` with SHA, subject, author, relative time

**Pane enum** (`src/Pane.php` — 27 lines):
```php
enum Pane: string {
    case Status   = 'status';
    case Branches = 'branches';
    case Log      = 'log';

    public function next(): self  // Tab cycles: Status → Branches → Log → Status
}
```

### App Model (State Machine)

**`App` class** (`src/App.php` — 1201 lines) is the main Model:
- Implements `Model` interface (`init()`/`update()`/`view()`/`subscriptions()`)
- All state is `readonly` — every `update()` returns fresh `App`
- Tracks: three pane lists + cursors, active pane, overlays (diff/stash/cherry-pick/worktrees/interactive-rebase)

**State properties:**
```php
public function __construct(
    public readonly GitDriver $git,
    public readonly array $status = [],
    public readonly array $branches = [],
    public readonly array $log = [],
    public readonly string $branchSummary = '',
    public readonly Pane $pane = Pane::Status,
    public readonly int $statusCursor = 0,
    public readonly int $branchesCursor = 0,
    public readonly int $logCursor = 0,
    public readonly ?string $error = null,
    public readonly bool $showHelp = false,
    public readonly bool $collectingCommit = false,
    public readonly string $commitMessage = '',
    public readonly ?DiffViewer $diffViewer = null,
    // ... branch name collection, merge target, rebase menu, stash, cherry-pick, worktrees, interactive rebase
)
```

### Rendering

**`Renderer` class** (`src/Renderer.php` — 480 lines) is a pure view function:
- `Layout::joinHorizontal()` merges left pane with right vertical stack
- `Border::rounded()` for pane frames
- Focused pane gets bright border (`#ff5f87`), others dim (`#4a3868`)
- Overlays render atop body: help, diff viewer, rebase menu, stash manager, cherry-pick bar, worktrees, interactive rebase

### Keyboard Navigation

| Key | Action | Pane Context |
|-----|--------|--------------|
| `Tab` | Cycle pane focus | Global |
| `↑/↓` or `j/k` | Move cursor in active pane | Global |
| `s` | Stage / unstage entry | Status |
| `a` | Stage all files | Status |
| `d` | Discard changes | Status |
| `P` | Open diff viewer | Status |
| `Space` | Checkout branch | Branches |
| `D` | Delete selected branch | Branches |
| `w` | Open worktrees manager | Branches |
| `c` | Commit (type message, Enter to confirm) | Global |
| `A` | Amend last commit | Global |
| `n` | Create new branch | Global |
| `M` | Merge branch | Global |
| `r` | Show rebase menu (continue/abort/skip) | Global |
| `S` | Open stash manager | Global |
| `V` | Cherry-pick (type commit ref) | Global |
| `i` | Interactive rebase (select N commits) | Global |
| `u` | Undo last operation | Global |
| `Ctrl+r` | Redo last undone operation | Global |
| `R` | Refresh from disk | Global |
| `?` | Show context-sensitive help | Global |
| `q`/`Esc` | Quit / close overlay | Global |

### Inline Text Collection Pattern

For multi-character inputs (commit message, branch name, merge target, cherry-pick ref), sugar-stash uses a **gated input state** pattern:

1. Boolean flag gates all normal key handling (`collectingCommit`, `collectingBranchName`, etc.)
2. Accumulator string grows via `KeyMsg` rune character-by-character
3. `Enter` confirms and executes the operation
4. `Esc` cancels and clears the accumulator

```php
// From App.php lines 124-133
if ($this->collectingCommit) {
    if ($msg->type === KeyType::Enter) {
        return [$this->executeCommit(), null];
    }
    if ($msg->type === KeyType::Char && $msg->rune !== '') {
        return [$this->withCommitMessage($this->commitMessage . $msg->rune), null];
    }
    return [$this, null];
}
```

### Diff Viewer with Hunk Cursor

**`DiffViewer` class** (`src/DiffViewer.php` — 93 lines) manages staged hunk selection:
- Parses `@@ -N,N +N,N @@` hunk headers from `git diff --no-color -- <path>`
- `hunkStarts: list<int>` — line indices where each hunk begins
- `hunkCursor: int` — index into `hunkStarts` for selected hunk
- `currentHunkPatch()` returns unified diff for `git apply --cached`

**Navigation:** `↑/↓` or `j/k` navigate hunks, `Space` stages current hunk, `Esc` closes.

### Stash Manager Overlay

**`StashManager` class** (`src/StashManager.php` — 95 lines):
- Parses `git stash list` output into `StashEntry` objects (index, sha, branch, message)
- `stashRef()` returns `"stash@{n}"` format for apply/drop
- Overlay: `a` applies, `d` drops, `↑/↓` navigates, `Esc` closes

### Interactive Rebase

**`InteractiveRebase` class** (`src/InteractiveRebase.php` — 224 lines):
- `RebaseAction` enum: `Pick`, `Reword`, `Edit`, `Squash`, `Drop`
- Two phases: `selectingN` (user types digit count), then todo list editor
- `cycleAction()` advances through action states
- `dropCurrent()` removes commit from todo list

### Undo/Redo System

**`HistoryManager` class** (`src/HistoryManager.php` — 72 lines):
- Two stacks: `undoStack` and `redoStack`
- New mutation clears `redoStack`
- `HistoryEntry` captures `op` + `inverseOp` pairs:

| Op | InverseOp |
|----|-----------|
| `stage` | `unstage` |
| `unstage` | `stage` |
| `discard` | (no inverse) |
| `checkout` | (no inverse) |
| `commit` | `reset` |
| `amend` | `reset` |
| `createBranch` | `deleteBranch` |
| `stageAll` | (no inverse) |
| `stagePatch` | (no inverse) |
| `merge` | (no inverse) |
| `abort` | (no inverse) |

---

## Comparison to Upstream jesseduffield/lazygit

### Feature Parity Matrix

| Feature | lazygit (Go) | sugar-stash (PHP) | Status |
|---------|-------------|-------------------|--------|
| Three-pane layout (status/branches/log) | ✅ | ✅ | Complete |
| Stage/unstage single file | ✅ | ✅ | Complete |
| Stage all files | ✅ | ✅ | Complete |
| Discard changes | ✅ | ✅ | Complete |
| Commit with message | ✅ | ✅ | Complete |
| Amend commit | ✅ | ✅ | Complete |
| Branch checkout | ✅ | ✅ | Complete |
| Branch create/delete | ✅ | ✅ | Complete |
| Merge | ✅ | ✅ | Complete |
| Diff viewer (per-file) | ✅ | ✅ | Complete |
| Stage per-hunk | ✅ | ✅ | Complete |
| Stash list/apply/drop | ✅ | ✅ | Complete |
| Cherry-pick | ✅ | ✅ | Complete |
| Worktrees | ✅ | ✅ | Complete |
| Interactive rebase (todo list) | ✅ | ✅ | Complete |
| Undo/redo | ✅ | ✅ | Complete |
| Context-sensitive help | ✅ | ✅ | Complete |
| Commit graph visualization | ✅ | ❌ | Not implemented |
| Bisect | ✅ | ❌ | Not implemented |
| Custom commands | ✅ | ❌ | Not implemented |
| Filter/search in panels | ✅ | ❌ | Not implemented |
| Push/pull remote | ✅ | ❌ | Not implemented |
| Submodules | ✅ | ❌ | Not implemented |
| Tags | ✅ | ❌ | Not implemented |
| Blame | ✅ | ❌ | Not implemented |
| Rebase magic (patches) | ✅ | ❌ | Not implemented |

### Architectural Differences

**Lazygit (Go):**
- Direct Go-git library bindings (no shell spawning)
- Full git state kept in memory, synced via `git status --porcelain=v2`
- Complex state machine with dedicated panels for each git domain
- Built on bubbletea (Charm's Elm-architecture TUI framework)
- ~60+ files, 30k+ lines of Go

**sugar-stash (PHP):**
- Shells out to `git` binary for every operation (preserves aliases, hooks, config)
- Simpler state: refresh on every keypress or explicit `R`
- Built on SugarCraft/candy-core (PHP Elm-architecture port)
- ~25 files, ~3500 lines of PHP

### Key Implementation Differences

1. **Git integration:** lazygit uses libgit2; sugar-stash uses `proc_open()` to spawn `git -C <cwd>`. This means sugar-stash respects user git aliases and hooks automatically, but is slower for high-frequency operations.

2. **Diff rendering:** lazygit uses a dedicated diff panel with side-by-side view; sugar-stash renders unified diff in an overlay with syntax highlighting.

3. **Interactive rebase:** lazygit has full `git rebase -i` todo file manipulation; sugar-stash builds a todo list from `git log` output and cycles through actions (pick/reword/edit/squash/drop) but does not generate the full rebase script.

4. **Undo/redo:** Both maintain operation history; lazygit's is more sophisticated with nested transaction support.

---

## Comparison to Related TUI Applications

### lazygit Alternatives

| Tool | Language | Stars | Comparison to sugar-stash |
|------|----------|--------|---------------------------|
| [lazygit](https://github.com/jesseduffield/lazygit) | Go | 76k | Primary upstream — full libgit2 bindings, all features complete |
| [lazysql](https://github.com/jorgerojas26/lazysql) | Go | 4k | Inspired by lazygit but for SQL databases |
| [superfile](https://github.com/yorukot/superfile) | Go | 4k | Two-pane file manager, not git-specific |

### SugarCraft Internal Comparison

sugar-stash is the most **complete application** in the SugarCraft monorepo:

| Aspect | sugar-stash | Other SugarCraft libs |
|--------|-------------|----------------------|
| Architecture | Full app with multiple overlays | Components/libraries |
| State management | Complex state machine with undo/redo | Single-purpose components |
| Git integration | Shells out to `git` | N/A |
| Test coverage | Extensive behavior tests via `FixtureGit` | Varies |
| i18n | Full i18n via `Lang::t()` facade | Varies |
| Entry point | `bin/sugar-stash` CLI | Usually composable libraries |

---

## Key Files and Line Counts

| File | Lines | Purpose |
|------|-------|---------|
| `src/App.php` | 1201 | Main model, all key handling |
| `src/Renderer.php` | 480 | Pure view function, three-pane rendering |
| `src/Git.php` | 246 | Concrete git shell-out implementation |
| `src/InteractiveRebase.php` | 224 | Interactive rebase state machine |
| `src/GitDriver.php` | 117 | Interface for all git operations |
| `src/HistoryManager.php` | 72 | Undo/redo stacks |
| `src/DiffViewer.php` | 93 | Diff with hunk cursor |
| `src/StashManager.php` | 95 | Stash list overlay state |
| `src/Pane.php` | 27 | Pane enum with `next()` |
| `src/CherryPick.php` | ~60 | Cherry-pick state collector |
| `src/Worktrees.php` | ~120 | Worktree manager overlay state |
| `src/StashEntry.php` | ~40 | Stash entry value object |
| `src/WorktreeEntry.php` | ~40 | Worktree entry value object |
| `src/HistoryEntry.php` | ~80 | Op/inverseOp pairs |
| `bin/sugar-stash` | 29 | CLI entry point |
| `examples/play.php` | 48 | In-memory fixture demo |

**Total: ~2800 lines of PHP** (excluding vendor/test bootstrap)

---

## Tests

**`tests/AppTest.php` (705 lines)** — Comprehensive behavior tests using `FixtureGit`:
- Pane cycling (`Tab` cycles Status → Branches → Log → Status)
- Cursor movement clamping
- Stage/unstage on single file
- Stage all (`a` key)
- Commit message collection
- Branch checkout (`Space` in branches pane)
- Diff viewer open/close
- Hunk staging in diff viewer
- Undo/redo (`u` / `Ctrl+r`)
- Branch create/delete
- Merge flow
- Stash apply/drop
- Cherry-pick
- Worktree add/remove
- Interactive rebase

**`tests/StashManagerTest.php`** — Stash list parsing and cursor navigation
**`tests/DiffViewerTest.php`** — Hunk parsing and cursor navigation
**`tests/InteractiveRebaseTest.php`** — Rebase action cycling and drop
**`tests/WorktreesTest.php`** — Worktree parsing and add/remove flows
**`tests/CherryPickTest.php`** — Cherry-pick state machine
**`tests/RendererTest.php`** — Snapshot tests for ANSI output
**`tests/LangCoverageTest.php`** — i18n key coverage

---

## Innovation Points (SugarCraft Enhancements)

1. **Pluggable GitDriver interface** — Enables testable architecture with fixture doubles
2. **Immutable state with readonly properties** — Every `update()` returns a fresh `App`
3. **Private `withAll()` helper** — Atomic multi-field updates for complex transitions
4. **Gated input collection pattern** — Clean separation of normal key handling vs. text input mode
5. **Per-library i18n facade** — `Lang::t()` wrapper with namespace baked in
6. **Two-phase interactive rebase** — `selectingN` → todo-list editor pattern
7. **Undo/redo with explicit inverseOp** — Each `HistoryEntry` knows how to undo itself
8. **Hunk-level diff staging** — `currentHunkPatch()` for surgical staging

---

## Strengths

1. **Clean architecture** — `GitDriver` interface allows swapping implementation (real git vs. fixtures)
2. **Immutable + fluent** — All state is readonly, every mutation returns new instance
3. **Comprehensive test coverage** — 700+ lines of behavior tests with `FixtureGit`
4. **i18n ready** — `Lang::t()` facade with 16 locales (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar)
5. **VHS demo** — `.vhs/play.tape` and `.vhs/stage.tape` for CI rendering to GIF
6. **CLI binary** — `bin/sugar-stash` with proper autoload resolution
7. **Preserves git config** — Shells out to system `git`, respecting user aliases and hooks

---

## Gaps / Future Work

1. **Commit graph visualization** — Colored branch topology like lazygit's `--graph`
2. **Bisect support** — `git bisect` integration for finding bugs
3. **Custom commands** — User-defined commands bound to keybindings
4. **Filter/search** — `/` key to filter any panel's content
5. **Push/pull** — Remote operations
6. **Tags management** — List/create/delete tags
7. **Submodules** — Submodule support
8. **Blame view** — Per-line blame annotation
9. **Rebase magic** — Custom patch application during rebase
10. **Commit amend old commits** — Amend any commit (not just HEAD) via rebase

---

## Dependencies

```json
{
  "sugarcraft/candy-core": "dev-master",      // TUI framework (Model, Msg, KeyType, Program)
  "sugarcraft/candy-sprinkles": "dev-master"    // Styling system (Style, Border, Layout, Color)
}
```

---

## Analysis

**sugar-stash** is a well-architected PHP port of jesseduffield/lazygit that demonstrates the power of the SugarCraft stack for building complete applications. The decision to shell out to `git` rather than using libgit2 bindings is a pragmatic choice that preserves user configuration while keeping the implementation simple.

**Architecture highlights:**
- The `GitDriver` interface pattern is exemplary — it makes the entire application testable without staging real repos
- Immutable state with readonly properties throughout
- Clean separation of concerns: `App` (model), `Renderer` (view), `GitDriver` (git operations)
- Comprehensive test coverage with behavior-driven tests

**Comparison to upstream lazygit:**
- sugar-stash implements ~60% of lazygit's features but with 10x less code
- The most critical git workflows (stage, commit, branch, stash, rebase) are all functional
- Missing features are largely advanced workflows (bisect, submodules, custom commands)

**Strategic position:**
- sugar-stash demonstrates that SugarCraft can build real-world applications
- The pluggable `GitDriver` architecture could support alternative backends (e.g., GitHub CLI, libgit2)
- The undo/redo system is a standout feature that makes the application feel polished

---

## Related Reports

- `/home/sites/sugarcraft/repo_map/charmbracelet_bubbletea.md` — Primary upstream TUI framework
- `/home/sites/sugarcraft/repo_map/sugarcraft_candy-core.md` — TUI framework foundation
- `/home/sites/sugarcraft/repo_map/sugarcraft_candy-sprinkles.md` — Styling system
- `/home/sites/sugarcraft/repo_map/sugarcraft_sugar-bits.md` — Component library comparison
