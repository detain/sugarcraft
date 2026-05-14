# SugarStash Git TUI Research Plan

**Date:** 2026-05-13
**Project:** sugar-stash (PHP git TUI, lazygit port)
**Sources:** lazygit, gitui, gitu, pygitzen, octotui, libgit2

---

## Executive Summary

SugarStash currently implements a minimal three-pane git TUI (status/branches/log) with basic staging via `git add`/`git restore --staged`. This research identifies **20+ improvements** across file staging, diff viewing, commit handling, branch management, and keyboard shortcuts, drawing from lazygit (upstream), gitui (Rust), gitu (Rust), and Python alternatives.

**Recommended Priority Order:**
1. **P0 (MVP gaps):** Commit creation, context-sensitive help, branch checkout
2. **P1 (Core UX):** Diff viewer panel, hunk/line staging, discard changes, stage-all
3. **P2 (Power features):** Undo/redo, amend commits, interactive rebase basics
4. **P3 (Polish):** Custom commands, worktrees, bisect, cherry-pick

---

## 1. Current Implementation Analysis

**Source:** `/home/sites/sugarcraft/sugar-stash/src/`

### What SugarStash Has
- ✅ Three-pane layout: Status (left), Branches (top-right), Log (bottom-right)
- ✅ Tab cycling between panes
- ✅ `j/k` and arrow key navigation
- ✅ `s` to stage/unstage single file
- ✅ `R` to refresh
- ✅ `q`/Esc/Ctrl+C to quit
- ✅ Branch summary with ahead/behind indicator
- ✅ Fixture-based test pattern via `GitDriver` interface

### What SugarStash Is Missing
- ❌ Commit creation
- ❌ Diff viewing
- ❌ Hunk/line-level staging
- ❌ Branch operations (checkout, create, delete)
- ❌ Context-sensitive help (`?`)
- ❌ Undo/redo
- ❌ Stash management
- ❌ Interactive rebase
- ❌ Custom commands

---

## 2. File Staging/Unstaging

### Current State (sugar-stash)
- **Single file only** via `git add <path>` and `git restore --staged -- <path>`
- No hunk or line-level staging
- No stage-all or discard functionality

**Source:** `src/Git.php:L71-79`
```php
public function stage(string $path): void
{
    $this->run(['add', '--', $path]);
}

public function unstage(string $path): void
{
    $this->run(['restore', '--staged', '--', $path]);
}
```

### Upstream Patterns (lazygit)

**Keybindings (lazygit):**
| Key | Action |
|-----|--------|
| `Space` | Stage / unstage file |
| `a` | Stage all / unstage all |
| `Enter` | Open file in hunk view |
| `d` | Discard changes to file |
| `D` | Advanced discard options |
| `v` | Toggle visual range selection (in hunk view) |
| `o` | Open file in editor |

**Hunk/Line staging:**
- `Enter` on file opens split diff view
- `Space` stages/unstages selected line
- `a` stages/unstages entire hunk
- `v` starts range selection for multi-line staging
- Recent improvement: hunk mode now selects "blocks of changes" rather than raw git hunks

**Source:** [lazygit.dev/keybindings](https://lazygit.dev/keybindings/), [PR #4684](https://github.com/jesseduffield/lazygit/pull/4684)

### GitUI Patterns (Rust)

GitUI uses `asyncgit` library with explicit hunk management:
```rust
// From asyncgit sync API
stage_hunk(&repo_path, "src/main.rs", hunk.header_hash, None)?;
unstage_hunk(&repo_path, "src/main.rs", hunk.header_hash, None)?;
reset_hunk(&repo_path, "src/main.rs", hunk.header_hash, None)?;  // discard
```

**Keybindings (gitui):**
| Key | Action |
|-----|--------|
| `Space` | Stage / unstage hunk |
| `Tab` | Switch staged/unstaged in diff view |
| `c` | Commit |
| `U` | Reset item (discard) |
| `s` | Stage lines (in diff) |
| `u` | Unstage lines (in diff) |

**Source:** [Context7: gitui staging](https://context7.com/gitui-org/gitui/llms.txt)

### Implementation Approaches for SugarStash

**Option A: Parse `git diff --cached` / `git diff` output**
- Pros: No new dependencies, works with existing git CLI
- Cons: Complex parsing, fragile to git output format changes

**Option B: Use `git add -p` (interactive patch mode)**
- Pros: Git handles hunk parsing
- Cons: Not suitable for TUI integration (requires stdin/stdout)

**Option C: PHP-native diff parsing via regex**
- Parse `git diff` output to extract hunks and lines
- Requires implementing hunk boundaries (`^@@.*@@` pattern)
- Pros: Full control, no external deps
- Cons: Non-trivial implementation

**Recommended:** Option C with libgit2 FFI (if PHP FFI available) or pure regex parsing

### Specific Improvements for Staging

| Improvement | Effort | Impact | Priority |
|-------------|--------|--------|----------|
| Stage all (`a` key) | Low | High | P1 |
| Discard changes (`d` key) | Low | High | P1 |
| Hunk-level staging | High | High | P1 |
| Line-level staging | High | Medium | P2 |
| Stage selected range (`v` + `Space`) | High | Medium | P2 |

---

## 3. Diff Viewing

### Current State (sugar-stash)
- ❌ No diff viewing at all
- Status pane only shows file paths with index/work status indicators

### Lazygit Diff Pattern

Lazygit uses a **split view** when entering staging:
- Left pane: unstaged changes
- Right pane: staged changes
- Tab switches focus between panes
- Syntax highlighting via external pager (delta) or built-in

**Keybinding:** `Enter` on file opens diff view

**Source:** [lazygit.dev Keybindings](https://lazygit.dev/keybindings/)

### GitUI Diff Pattern

GitUI renders diffs inline with syntax highlighting:
```
+ added line (green)
- removed line (red)
  context line (default)
```

Uses `asyncgit::sync::diff::{get_diff, DiffOptions}` to retrieve diffs with hunks.

**Source:** [Context7: gitui staging](https://context7.com/gitui-org/gitui/llms.txt)

### Python Patterns (pygitzen)

pygitzen shows commits panel with auto-updating patch panel:
- Patch panel shows diff when commit selected
- Syntax highlighted via Rich library
- Auto-refreshes on navigation

**Source:** [pygitzen GitHub](https://github.com/SunnyTamang/pygitzen)

### Implementation for SugarStash

**Git commands for diff retrieval:**
```bash
# Staged diff (what would be committed)
git diff --cached [--no-color] [path]

# Unstaged diff (working tree changes)
git diff [--no-color] [path]

# Commit diff
git show <sha> [--no-color] [--format=]
```

**PHP Parsing Strategy:**
```php
// Parse diff hunks from git output
$output = $this->run(['diff', '--cached', '--', $path]);
$hunks = $this->parseDiffHunks($output);

// DiffHunk structure:
// - old_start, old_lines (before)
// - new_start, new_lines (after)
// - header ("@@ -X,Y +Z,W @@")
// - lines: [+/-/space prefix + content]
```

**Specific Improvements:**
| Improvement | Effort | Impact | Priority |
|-------------|--------|--------|----------|
| Basic diff display in commit log panel | Medium | High | P1 |
| Syntax highlighting for diff | Medium | Medium | P1 |
| Split view (staged/unstaged) | High | High | P2 |
| Clickable line numbers for staging | High | Medium | P2 |

---

## 4. Commit Handling

### Current State (sugar-stash)
- ❌ No commit creation
- ❌ No commit amending
- ❌ No commit message input

### Lazygit Commit Workflow

**Keybindings:**
| Key | Action |
|-----|--------|
| `c` | Commit staged changes |
| `w` | Commit without pre-commit hooks |
| `A` | Amend last commit with staged changes |
| `C` | Commit using git editor |
| `Ctrl+f` | Find base commit for fixup |

**Commit message input:**
- Popup with two panels: summary + description
- `Tab` toggles between panels
- `↑/↓` cycles through previous commit messages
- `Enter` confirms commit
- Supports Git hooks (pre-commit, commit-msg, etc.)

**Source:** [PR #2390](https://github.com/jesseduffield/lazygit/pull/2390)

### GitUI Commit Workflow

GitUI commits via asyncgit:
```rust
// Create commit (does not run hooks automatically unless configured)
let commit_id: CommitId = commit(&repo_path, "feat: add new feature")?;

// Amend existing commit
let new_commit_id = amend(&repo_path, commit_id, "amended message")?;
```

**Supports Git hooks:** pre-commit, commit-msg, post-commit, prepare-commit-msg

**Source:** [Context7: gitui commit](https://context7.com/gitui-org/gitui/llms.txt)

### SugarStash Implementation

**Git commands:**
```php
// Create commit with message
$this->run(['commit', '-m', $message]);

// Amend last commit
$this->run(['commit', '--amend', '-m', $message]);
// Or: $this->run(['commit', '--amend', '--no-edit']);

// Commit with editor (uses GIT_EDITOR)
$this->run(['commit']);
```

**UI Pattern for Commit Input:**
```
┌─────────────────────────────────────────┐
│ Commit Message                    [c]   │
├─────────────────────────────────────────┤
│ Summary: ________________________________│
│                                         │
│ Description:                            │
│ ______________________________________  │
│                                         │
└─────────────────────────────────────────┘
```

**Specific Improvements:**
| Improvement | Effort | Impact | Priority |
|-------------|--------|--------|----------|
| Basic commit (`c` key) | Low | High | P0 |
| Commit with message input | Medium | High | P0 |
| Amend last commit (`A` key) | Low | High | P1 |
| Commit description field | Medium | Medium | P1 |
| Cycling previous messages | Medium | Low | P2 |
| Git hooks support | Medium | Medium | P2 |

---

## 5. Branch Management

### Current State (sugar-stash)
- ✅ Shows branch list with current branch highlighted
- ❌ No branch checkout
- ❌ No branch creation/deletion
- ❌ No merge/rebase

**Source:** `src/App.php` - Branches pane is read-only

### Lazygit Branch Keybindings

| Key | Action |
|-----|--------|
| `Space` | Checkout selected branch |
| `n` | Create new branch (from current) |
| `d` | Delete branch |
| `D` | Force delete branch |
| `r` | Rebase current onto selected |
| `M` | Merge selected into current |
| `f` | Force-checkout (discard changes) |
| `u` | Set upstream |
| `p` | Pull |
| `P` | Push |

**Source:** [lazygit.dev Keybindings](https://lazygit.dev/keybindings/)

### GitUI Branch Operations

```rust
// From asyncgit sync API
create_branch(&repo_path, "feature/new-feature")?;
checkout_branch(&repo_path, "main")?;
delete_branch(&repo_path, "refs/heads/feature/old-feature")?;
branch_compare_upstream(&repo_path, "main")?;  // Returns ahead/behind
```

**Source:** [Context7: gitui branch](https://context7.com/gitui-org/gitui/llms.txt)

### SugarStash Git Commands

```php
// Checkout branch
$this->run(['checkout', $branchName]);

// Create and checkout new branch
$this->run(['checkout', '-b', $newBranchName]);

// Delete branch
$this->run(['branch', '-d', $branchName]);

// Force delete
$this->run(['branch', '-D', $branchName]);

// Merge
$this->run(['merge', $branchName]);

// Rebase
$this->run(['rebase', $branchName]);

// Get ahead/behind
$this->run(['rev-list', '--left-right', '--count', 
    "$branchName...origin/$branchName"]);
```

**Specific Improvements:**
| Improvement | Effort | Impact | Priority |
|-------------|--------|--------|----------|
| Branch checkout (`Space` key) | Low | High | P0 |
| Create branch (`n` key) | Low | High | P1 |
| Delete branch (`d` key) | Low | Medium | P1 |
| Force checkout (`f` key) | Low | Medium | P1 |
| Ahead/behind in branch list | Low | Medium | P1 |
| Merge (`M` key) | Medium | Medium | P2 |
| Rebase (`r` key) | Medium | Medium | P2 |

---

## 6. Keyboard Shortcuts

### Current State (sugar-stash)

| Key | Action |
|-----|--------|
| `Tab` | Cycle pane focus |
| `↑/↓` or `j/k` | Move cursor |
| `s` | Stage/unstage (status pane) |
| `R` | Refresh |
| `q`/Esc/Ctrl+C | Quit |

### Missing Keys (High Priority)

| Key | Action | Priority |
|-----|--------|----------|
| `?` | Context-sensitive help | P0 |
| `c` | Commit | P0 |
| `Space` | Branch checkout | P0 |
| `a` | Stage all / unstage all | P1 |
| `d` | Discard changes | P1 |
| `A` | Amend last commit | P1 |
| `n` | Create branch | P1 |
| `Enter` | Expand/open selected item | P1 |

### Undo/Redo Pattern (Lazygit)

Lazygit implements undo/redo via git reflog:
| Key | Action |
|-----|--------|
| `Ctrl+z` | Undo last action |
| `Ctrl+y` | Redo |

**Implementation approach:** Track action type + target, use `git reset --hard HEAD@{N}` for undo.

### Context-Sensitive Help Pattern

Lazygit shows `?` popup with keys specific to current pane and context:
- Files pane: stage, unstage, discard, enter diff view
- Branches pane: checkout, create, delete, merge
- Commits pane: squash, fixup, drop, reword

**SugarStash pattern:**
```php
public function view(): string
{
    $help = match($this->pane) {
        Pane::Status => 's stage · a stage all · d discard · Enter diff',
        Pane::Branches => 'Space checkout · n new · d delete',
        Pane::Log => 'Enter view · c commit',
    };
    // ...
}
```

### Keybinding Philosophy (from gitui)

GitUI config file (`key_config.ron`) demonstrates context-based binding:
```ron
// Vim-style navigation in gitui
move_up: Some(( code: Char('k'), modifiers: "")),
move_down: Some(( code: Char('j'), modifiers: "")),
```

**Recommendation:** Make keybindings extensible via config in future version.

---

## 7. Advanced Features (Priority P2-P3)

### Stash Management (from lazygit)

| Key | Action |
|-----|--------|
| `s` | Stash changes |
| `S` | Stash options (staged only, etc.) |
| `g` | Pop stash (apply + delete) |
| ` ` | Apply stash (keep) |
| `d` | Drop stash |
| `n` | New branch from stash |

### Interactive Rebase (from lazygit)

| Key | Action |
|-----|--------|
| `i` | Start interactive rebase |
| `s` | Squash commit |
| `f` | Fixup commit |
| `d` | Drop commit |
| `e` | Edit commit |
| `Ctrl+k/j` | Move commit up/down |

### Undo/Redo (from lazygit)

Uses git reflog internally. Records every mutation action.

**SugarStash implementation approach:**
```php
private array $undoStack = [];
private array $redoStack = [];

// On stage: push {action: 'stage', path: $path}
// On unstage: push {action: 'unstage', path: $path}

private function undo(): self {
    $action = array_pop($this->undoStack);
    if ($action['action'] === 'stage') {
        $this->git->unstage($action['path']);
    } else {
        $this->git->stage($action['path']);
    }
    $this->redoStack[] = $action;
    return $this->refresh();
}
```

### Custom Commands (from lazygit)

Allows binding shell commands to keys with context (selected branch, file, commit).

### Worktrees (from lazygit)

| Key | Action |
|-----|--------|
| `w` | Create worktree |
| `-` | Remove worktree |

---

## 8. Architecture Patterns

### Model-View-Update (Elm Architecture)

SugarStash follows this pattern correctly:
- **Model:** `App` class with immutable state
- **View:** `Renderer::render()` produces terminal output
- **Update:** `App::update(Msg $msg)` returns `[Model, ?Cmd]`

Lazygit uses similar pattern in Go with gocui library.

### Command Pattern for Effects

SugarStash uses `Cmd` for side effects (quit, refresh):
```php
public function update(Msg $msg): array {
    if ($msg->type === KeyType::Escape) {
        return [$this, Cmd::quit()];  // [newModel, command]
    }
    return [$this, null];
}
```

### Pluggable Backend (GitDriver Interface)

Good pattern already in place:
```php
interface GitDriver {
    public function status(): array;
    public function branches(): array;
    public function log(int $limit = 25): array;
    public function stage(string $path): void;
    public function unstage(string $path): void;
}
```

**Future extension:** Add `diffStaged()`, `diffUnstaged()`, `commit()`, `discard()` to interface.

### Async Patterns (GitUI)

GitUI uses async git operations for fluid UI:
```rust
// asyncgit uses tokio for async
async fn refresh(&mut self) -> Result<(), AsyncgitError> {
    self.repo.fetch().await?;
    self.status = self.git.status().await?;
}
```

**SugarStash consideration:** ReactPHP could enable async git operations if needed for large repos.

---

## 9. Testing Patterns

### Current Pattern (Good)

SugarStash uses fixture-based `GitDriver` implementation for tests:
```php
final class FixtureGit implements GitDriver {
    public array $stages = [];
    public array $unstages = [];
    public function stage(string $path): void { $this->stages[] = $path; }
    // ...
}

public function testStageInvokesGitForUnstagedEntry(): void {
    $g = $this->git();
    $a = App::start($g);
    [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
    [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
    $this->assertSame(['src/B.php'], $g->stages);
}
```

### Snapshot Testing

Lazygit tests use snapshot testing for view rendering:
```go
// Example: assert view renders expected ANSI bytes
assert.Equal(t, "\x1b[1mbold text\x1b[0m", view())
```

SugarStash `RendererTest` could add snapshot tests once diff viewing is added.

---

## 10. Prioritized Recommendations

### Phase 1: MVP Gaps (Effort: Low-Medium)

| # | Improvement | Files | Effort | Notes |
|---|-------------|-------|--------|-------|
| 1 | Context-sensitive help (`?` key) | `App.php`, `Renderer.php` | Low | Show pane-specific keybindings |
| 2 | Branch checkout (`Space` key) | `App.php`, `Git.php`, `GitDriver.php` | Low | Add `checkout()` to GitDriver |
| 3 | Commit creation (`c` key) | `App.php`, `Git.php`, `GitDriver.php` | Medium | Needs commit message input UI |
| 4 | Stage all (`a` key) | `App.php`, `Git.php` | Low | Add `stageAll()` method |

### Phase 2: Core UX (Effort: Medium-High)

| # | Improvement | Files | Effort | Notes |
|---|-------------|-------|--------|-------|
| 5 | Basic diff viewer panel | New `DiffPane.php`, `Git.php` | Medium | Show file diff in new panel |
| 6 | Discard changes (`d` key) | `App.php`, `Git.php` | Low | `git checkout -- <path>` |
| 7 | Amend last commit (`A` key) | `App.php`, `Git.php` | Low | `git commit --amend` |
| 8 | Hunk-level staging | `Git.php`, new parser | High | Parse `git diff` output |
| 9 | Create branch (`n` key) | `App.php`, `Git.php` | Low | `git checkout -b` |

### Phase 3: Power Features (Effort: Medium-High)

| # | Improvement | Files | Effort | Notes |
|---|-------------|-------|--------|-------|
| 10 | Undo/redo (`Ctrl+z/y`) | `App.php` | Medium | Track action history |
| 11 | Line-level staging | `Git.php` | High | Extend hunk parser |
| 12 | Delete branch (`d` key) | `App.php`, `Git.php` | Low | `git branch -d` |
| 13 | Merge (`M` key) | `App.php`, `Git.php` | Medium | `git merge` |
| 14 | Rebase (`r` key) | `App.php`, `Git.php` | Medium | `git rebase` |

### Phase 4: Polish (Effort: High)

| # | Improvement | Files | Effort | Notes |
|---|-------------|-------|--------|-------|
| 15 | Interactive rebase UI | Multiple | High | Complex state machine |
| 16 | Stash management | `App.php`, `Git.php` | Medium | `git stash` commands |
| 17 | Cherry-pick | `App.php`, `Git.php` | Medium | `git cherry-pick` |
| 18 | Worktrees | `App.php`, `Git.php` | Medium | `git worktree` |
| 19 | Custom commands | Configuration | High | Extensible command system |
| 20 | Syntax highlighting | `Renderer.php` | Medium | Use library or regex |

---

## 11. Suggested File Changes

### `src/GitDriver.php` (Interface Extension)

```php
interface GitDriver {
    // ... existing methods ...

    // New methods for v2
    public function diffStaged(?string $path = null): array;
    public function diffUnstaged(?string $path = null): array;
    public function discard(string $path): void;
    public function commit(string $message, ?string $description = null): void;
    public function amend(string $message): void;
    public function checkout(string $branch): void;
    public function createBranch(string $name): void;
    public function deleteBranch(string $name, bool $force = false): void;
}
```

### `src/App.php` (Key Handler Extension)

```php
public function update(Msg $msg): array {
    // ... existing handlers ...

    // New keybindings
    if ($msg->type === KeyType::Char && $msg->rune === '?') {
        return [$this->withShowHelp(!$this->showHelp), null];
    }
    if ($msg->type === KeyType::Char && $msg->rune === 'c') {
        return [$this, Cmd::openCommitInput()];
    }
    if ($msg->type === KeyType::Char && $msg->rune === 'a') {
        return [$this->toggleStageAll(), null];
    }
    // ... etc
}
```

### `src/DiffParser.php` (New File)

```php
final class DiffParser {
    /**
     * @return list<DiffHunk>
     */
    public static function parse(string $output): array {
        // Parse git diff output into structured hunks/lines
    }
}

final class DiffHunk {
    public function __construct(
        public readonly int $oldStart,
        public readonly int $oldLines,
        public readonly int $newStart,
        public readonly int $newLines,
        public readonly string $header,
        public readonly array $lines,
    ) {}
}

final class DiffLine {
    public function __construct(
        public readonly string $origin,  // '+', '-', ' ', '\'
        public readonly ?int $oldLineNo,
        public readonly ?int $newLineNo,
        public readonly string $content,
    ) {}
}
```

---

## 12. Effort Estimates

| Phase | Features | Total Effort | Complexity |
|-------|----------|--------------|------------|
| Phase 1 | 4 features | 2-3 days | Low-Medium |
| Phase 2 | 5 features | 3-5 days | Medium-High |
| Phase 3 | 5 features | 3-5 days | Medium-High |
| Phase 4 | 6 features | 5-7 days | High |

**Total: ~13-20 days for full feature parity with current lazygit core features**

---

## 13. References

- **Lazygit:** https://github.com/jesseduffield/lazygit
- **Lazygit Keybindings:** https://lazygit.dev/keybindings/
- **Lazygit Features:** https://lazygit.dev/features/
- **GitUI:** https://github.com/gitui-org/gitui
- **Gitu:** https://github.com/altsem/gitu
- **pygitzen:** https://github.com/SunnyTamang/pygitzen
- **octotui:** https://github.com/never-use-gui/octotui
- **libgit2 diff API:** https://libgit2.org/docs/reference/main/diff/
- **git2go (Go bindings):** https://github.com/libgit2/git2go

---

## Appendix A: Keybinding Comparison Table

| Action | SugarStash | Lazygit | GitUI | Gitu |
|--------|------------|---------|-------|------|
| Stage file | `s` | `Space` | `Space` | `s` |
| Stage all | - | `a` | `a` | `S-a` |
| Stage hunk | - | `Space` (in diff) | `Space` | `s` |
| Stage line | - | `Space` (in diff) | `s` | `s` |
| Unstage | `s` | `Space` | `Space` | `u` |
| Discard | - | `d` | `U` | `D` |
| Commit | - | `c` | `c` | `c` |
| Amend | - | `A` | `C` | `a` |
| Checkout branch | - | `Space` | `Space` | `RET` |
| Create branch | - | `n` | `n` | `c` |
| Delete branch | - | `d` | `d` | `B-d` |
| Refresh | `R` | `R` | `r` | `g` |
| Help | - | `?` | `F1` | `h` |
| Quit | `q` | `q` | `q` | `q` |
| Undo | - | `Ctrl+z` | `z` | `z` |
| Redo | - | `Ctrl+y` | `y` | `Z` |
| Enter diff view | - | `Enter` | `l` | `RET` |
| Tab (panes) | `Tab` | `Tab` | `Tab` | `Tab` |

---

*Research compiled from upstream documentation, code analysis, and API references.*
