# SugarStash — Caliber Learnings

Accumulated patterns and gotchas discovered while building and auditing
sugar-stash.

---

## [pattern:inline-text-collection] Inline multi-character text collection via key dispatch

When a TUI needs a multi-character string input (e.g. a commit message, branch name) without
spawning a sub-process or blocking, use a dedicated boolean flag + accumulator
string in the Model state:

1. **`collectingCommit: bool`** — gates all normal key handling; when `true`
   only Escape/Ctrl+C (cancel) and Enter (confirm) are processed.
2. **`commitMessage: string`** — appended character-by-character via each
   `KeyMsg` rune in update loop: `withCommitMessage($this->commitMessage . $msg->rune)`.
3. **Cancel path** — Escape resets both flags and clears the accumulator.
4. **Confirm path** — Enter calls `GitDriver::commit()` then exits collection
   mode (sets `collectingCommit=false, commitMessage=''`).

This keeps the model entirely immutable — every keystroke returns a fresh App
with the updated accumulator. No external readline or $stream_get_contents
calls needed.

The same pattern applies to any inline text input: patch messages, branch names,
tag names, etc.

---

## [pattern:diff-viewer-hunk-cursor] Diff viewer with hunk-level cursor

The diff viewer is a full-overlay pane that tracks cursor position at the
hunk level (not line level):

1. **`diffViewer: ?DiffViewer`** — `null` means no overlay; a `DiffViewer`
   instance means the overlay is active.
2. **`hunkStarts: list<int>`** — line indices where each hunk begins, computed
   by `DiffViewer::fromRawDiff()`.
3. **`hunkCursor: int`** — index into `hunkStarts` for the currently selected
   hunk. Navigation (`j`/`k`) clamps to `[0, hunkCount - 1]`.
4. **`withDiffViewer(?DiffViewer)`** — bypasses `withAll()` to handle the
   `null`-explicitly vs `null`-as-unchanged ambiguity.
5. **Selected hunk highlight** — `reverse()` style applied to lines between
   `hunkCursor` and the next hunk start (or end of file).

---

## [pattern:sugar-stash-i18n] Per-library i18n facade

When adding i18n to sugar-stash (a leaf app lib that shells out to `git`),
follow the canonical pattern established by sugar-wishlist, sugar-calendar,
and sugar-toast:

1. **`lang/en.php`** — flat `array<string, string>` keyed by translation
   key (e.g. `'status.clean'`, `'help.keyhints'`). Return type hint via
   `@return array<string, string>` docblock. `declare(strict_types=1)` at
   top.

2. **`src/Lang.php`** — `final class Lang` wrapping
   `SugarCraft\Core\I18n\T`. Bakes the library namespace (`'stash'`) into
   every key. Mirrors the sugar-wishlist/calendar/toast facade pattern.
   Registers the namespace + lang dir on every call (safe; `T::register`
   is idempotent).

3. **Translation keys** — use dot notation (`status.clean`), not camelCase.
   Avoid generic keys like `'label'` — prefix with context (`ui.error_prefix`).

4. **`LangCoverageTest`** — scans `src/` for `Lang::t()` call patterns
   and verifies every key exists in `lang/en.php`. Prevents silent missing
   translations.

5. **`candy-core` dep** — the `T` class lives in `candy-core`, so every
   i18n lib needs `sugarcraft/candy-core: dev-master` in `require` plus a
   path-repo entry in `repositories`.

     6. **Keys in sugar-stash** — `git.spawn_failed`, `git.error`,
      `cli.not_a_repo`, `ui.error_prefix`, `status.clean`, `branches.empty`,
      `log.empty`, `help.keyhints`, `help.context_general`, `help.quit`,
      `help.refresh`, `help.switch_pane`, `help.move_cursor`, `help.close_help`,
      `help.pane_navigation`, `help.pane_status`, `help.pane_branches`,
      `help.stage_single`, `help.stage_all`, `help.checkout`, `help.commit`,
      `help.amend`, `help.new_branch`, `help.discard`, `help.diff_viewer`,
      `checkout.no_branch`, `commit.prompt`, `commit.empty_message`,
      `branch.prompt`, `branch.empty_name`, `diff.hunk_staged`,
      `diff.navigation_hint`.

---

## [pattern:history-entry-factory] HistoryEntry captures op + inverseOp for undo

Every mutation operation that can be undone stores a paired **inverse operation**
in the `HistoryEntry`:

- **Op** — the forward action (e.g. `commit`, `branch_create`, `stage_file`).
- **inverseOp** — the exact inverse needed to undo it (e.g. `commit_undo`,
  `branch_delete`, `stage_undo`).
- **label** — human-readable `{op}` placeholder string used in feedback.

This makes `HistoryManager::undo()` a simple pop + execute inverseOp — no
operation-type switch needed. The entry itself knows how to undo itself.

---

## [pattern:history-manager-undo-redo] Separate undo stack + redo stack; new operation clears redo

`HistoryManager` maintains two stacks:

1. **`undoStack: list<HistoryEntry>`** — committed entries ready to undo.
2. **`redoStack: list<HistoryEntry>`** — undone entries available to redo.

Key invariant: **any new mutation operation** (anything that pushes onto
`undoStack`) **clears `redoStack` entirely**. This matches git/undo semantics —
after you undo N steps then perform a new action, the redo history is discarded.

`undo()` pops from `undoStack`, executes `inverseOp`, pushes to `redoStack`.
`redo()` pops from `redoStack`, executes `op`, pushes to `undoStack`.

---

## [pattern:rebase-in-progress-check] Detect ongoing rebase via git rev-parse or git status

Before entering rebase UI or offering continue/abort/skip, detect whether a
rebase is actually in progress:

```php
// Safe: works even when .git is a file (worktree)
$gitDir = trim(shell_exec('git rev-parse --absolute-git-dir 2>/dev/null') ?? '');
$inRebase = is_dir($gitDir . '/rebase-merge') || is_dir($gitDir . '/rebase-apply');
```

Or fall back to `git status` (slower, but always correct):
```php
$status = trim(shell_exec('git status --porcelain 2>/dev/null') ?? '');
$inRebase = str_contains($status, 'rebase in progress');
```

Use the `--absolute-git-dir` form over bare `git rev-parse --git-dir` because
it returns an absolute path and works inside worktree linked dirs.

---

## [pattern:stash-overlay-state] Stash manager overlay (S key)

Stash management is a full-overlay mode activated by `S` (capital S), showing
a list of stashes with cursor navigation and apply/drop actions:

1. **`stashManager: ?StashManager`** — `null` means no overlay; a
   `StashManager` instance is the active overlay.
2. **`StashManager::fromGitOutput(array $lines): list<StashEntry>`** — parses
   `git stash list` output into `StashEntry` objects.
3. **Key handling** — `a` applies the current stash, `d` drops it,
   `↑/↓` or `j/k` navigates, `Esc` dismisses the overlay.
4. **`StashEntry::stashRef(): string`** — returns `"stash@{n}"` for use in
   `git stash apply/drop <ref>`.
5. **Immutable** — `withCursor(int $dir)` and `withStashes(array)` return
   fresh instances; the App's `withStashManager()` bypasses `withAll()` to
   handle the `null`-explicitly vs `null`-as-unchanged ambiguity.

---

## [pattern:cherry-pick-state] Cherry-pick state (V key)

Cherry-pick mode is a single-state collector (not a full overlay pane) that
accumulates a commit ref character-by-character:

1. **`cherryPick: ?CherryPick`** — `null` when inactive; `CherryPick` instance
   while collecting.
2. **`CherryPick::collecting(): self`** — factory entering collection mode.
3. **`withChar(string $rune): self`** — appends a character to the accumulated
   `commitRef`, returning a new `CherryPick`.
4. **`cancel(): self`** — exits collection mode, clears `commitRef`.
5. **Key handling** — type characters to build ref, `Enter` to execute
   `git cherry-pick <ref>`, `Esc` to cancel.
6. **Immutable** — every method returns a new instance; `App::withCherryPick()`
   bypasses `withAll()` for the same null-explicitness reason as stash.

---

## [pattern:worktree-overlay-state] Worktree manager overlay (w key, branches pane)

Worktree management is a full-overlay mode in the branches pane (`w`), with
two sub-modes (add and remove) each collecting path/branch input:

1. **`worktrees: ?Worktrees`** — `null` when inactive.
2. **`Worktrees::$adding: bool`** — when true, `Enter` confirms adding a new
   worktree at `$newPath` from `$newBranch`.
3. **`Worktrees::$removing: bool`** — when true, `Enter` confirms removing the
   current worktree.
4. **Key handling** — `a` enters add-mode, `d` enters remove-mode,
   `c` cancels any active sub-mode, `↑/↓` or `j/k` navigates, `Esc` dismisses.
5. **`WorktreeEntry`** — immutable entry with `path`, `branch`, `isBare`, `HEAD`.
6. **`Worktrees::fromGitOutput(array $lines): array`** — parses
   `git worktree list --porcelain` into `WorktreeEntry` objects.
7. **Immutable** — `withCursor()`, `startAdding()`, `withNewPath()`,
   `withNewBranch()`, `cancelAdding()`, `startRemoving()`, `cancelRemoving()`
   all return fresh instances.

---

## [pattern:interactive-rebase-todo] Interactive rebase todo list (i key)

Interactive rebase shows a todo list of commits, each with an assignable action
(pick/reword/edit/squash/drop), then dispatches the rebase via the GitDriver's
`stagePatch` or `reset` methods:

1. **`interactiveRebase: ?InteractiveRebase`** — `null` when inactive.
2. **`RebaseAction` enum** — `Pick`, `Reword`, `Edit`, `Squash`, `Drop`.
3. **`RebaseCommit`** — immutable entry: `sha`, `subject`, `action` (default
   `Pick`) + `withAction(RebaseAction): self`.
4. **Two phases** — `selectingN` (user types a digit count, `Enter` confirms) and
   `!selectingN` (todo list is shown, user navigates and changes actions).
5. **`withCountDigit(string $digit): self`** — only accepts `ctype_digit`
   input; returns `$this` unchanged for non-digits (no-op, not an error).
6. **`cycleAction(): self`** — advances the current commit's action through the
   enum cycle: Pick → Reword → Edit → Squash → Drop → Pick.
7. **`dropCurrent(): self`** — removes the current commit from the todo list,
   re-indexing the cursor to stay in bounds.
8. **Display** — `RebaseCommit::displayLine()` returns `"<action> <sha> <subject>"`.
9. **Immutability** — every state-changing method returns a new instance;
   `App::withInteractiveRebase()` bypasses `withAll()` for null-explicitness.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
