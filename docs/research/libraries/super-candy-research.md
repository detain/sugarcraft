# Super-Candy Dual-Pane File Manager Research

**Date:** 2026-05-13
**Project:** super-candy (PHP 8.3+ port of yorukot/superfile)
**Context:** SugarCraft monorepo - TUI library port from Charmbracelet ecosystem

---

## Executive Summary

This research analyzes dual-pane file managers across Go, Rust, and Python to identify implementation patterns and improvement opportunities for super-candy. Key findings:

1. **Panel synchronization** is handled differently - superfile uses explicit "other pane" navigation, while yazi/ranger use implicit single-pane models
2. **File operations** vary from shell-command delegation (lf) to builtin async operations (yazi)
3. **Preview panels** are a major differentiator - yazi leads with image previews, ranger has extensive scope.sh integration
4. **Keybinding patterns** converge on vi-style defaults with ctrl-protected file operations

---

## 1. Implementation Overview

### 1.1 Go Implementations

#### superfile (upstream)
- **Repository:** github.com/yorukot/superfile
- **Stars:** ~9k (estimated)
- **Language:** Go
- **Architecture:** Multi-panel model with independent panes, shared hotkey system
- **Key Strengths:**
  - Multiple file panel support (split vertically/horizontally)
  - Image preview support
  - Plugin system
  - Vim-mode hotkeys option
  - Auto-update functionality

**Source:** [superfile GitHub](https://github.com/yorukot/superfile) | [Documentation](https://superfile.dev/)

#### lf (list files)
- **Repository:** github.com/gokcehan/lf
- **Stars:** ~8.9k
- **Language:** Go
- **Architecture:** Single-pane with client/server for multiple instances
- **Key Strengths:**
  - Server/client architecture for multi-instance management
  - Asynchronous I/O to avoid UI locking
  - Extendable via shell commands
  - Very fast startup, low memory
- **Notable Non-Features:** No built-in tabs, no builtin file operations (delegates to shell)

**Source:** [lf GitHub](https://github.com/gokcehan/lf)

#### nnn
- **Repository:** github.com/jarun/nnn
- **Stars:** ~15k (estimated)
- **Language:** C
- **Architecture:** Single-pane with "contexts" (virtual tabs), lightweight design
- **Key Strengths:**
  - Extremely lightweight (~100KB binary)
  - POSIX-compliant, works on many platforms including Termux
  - Disk usage analyzer
  - File picker mode
  - Rich plugin ecosystem
  - Integration with clipboard, trash

**Source:** [nnn GitHub](https://github.com/jarun/nnn)

### 1.2 Rust Implementations

#### yazi
- **Repository:** github.com/sxyazi/yazi
- **Stars:** ~32k (estimated, rapidly growing)
- **Language:** Rust
- **Architecture:** Async task scheduling, multi-threaded I/O
- **Key Strengths:**
  - Full async/await with real-time progress updates
  - Built-in image preview (kitty, iTerm2, Sixel, etc.)
  - Code syntax highlighting
  - Lua plugin system
  - Multi-tab support with cross-directory selection
  - Git integration plugins
  - Mount manager for remote filesystems
  - Package manager for plugins/themes

**Source:** [yazi GitHub](https://github.com/sxyazi/yazi) | [Why is Yazi Fast?](https://yazi-rs.github.io/blog/why-is-yazi-fast)

#### xplr
- **Repository:** github.com/sl哪里73/xplr
- **Language:** Rust
- **Architecture:** Pipable, scriptable
- **Key Strengths:**
  - Highly customizable via Lua/HCL config
  - stdin/stdout pipeline integration
  - Node selection and batch operations

### 1.3 Python Implementation

#### ranger
- **Repository:** github.com/ranger/ranger
- **Stars:** ~9k (estimated)
- **Language:** Python
- **Architecture:** Three-column display (parent, current, preview)
- **Key Strengths:**
  - VI key bindings
  - Preview pane with scope.sh integration
  - Automatically determines file types
  - rifle launcher (opens files with correct programs)
  - Tabs, bookmarks, mouse support
  - Extensible via plugins

**Source:** [ranger GitHub](https://github.com/ranger/ranger)

---

## 2. Comparative Analysis

### 2.1 Panel Synchronization

| Manager | Approach | Details |
|---------|----------|---------|
| **superfile** | Explicit navigation | `L`/`H` or `tab` to switch panes, separate cursor per pane |
| **lf** | Single pane | No dual-pane concept; server/client for multiple instances |
| **nnn** | Contexts (virtual tabs) | Multiple independent directory contexts |
| **yazi** | Multi-tab + single pane | Tabs with pane switching; no persistent dual-pane |
| **ranger** | Three-column | Parent directory, current directory, preview |

**Current super-candy:** Explicit `activeIdx` (0 or 1) + `withActivePane()` pattern. Uses Tab to swap active pane.

**Key Insight:** superfile's approach of having independent pane state per panel is the closest model to super-candy's architecture. The `activeIdx` maps directly to superfile's `focus` concept.

**Recommendation:** Super-candy's current model is sound. Potential improvement:
```php
// Add "follow cursor" mode where inactive pane mirrors navigation
private bool $followCursor = false;

// When navigating in active pane, optionally sync inactive pane's cursor
public function navigate(): self {
    return $this->withActivePane(fn(Pane $p) => $p->navigate($this->lister))
        ->maybeSyncOtherPane();
}
```

### 2.2 File Operations

| Manager | Copy | Move | Delete | Rename | Bulk Operations |
|---------|------|------|--------|--------|-----------------|
| **superfile** | `ctrl+c` | `ctrl+x` | `ctrl+d`/`D` | `ctrl+r` | Yes, via selection mode |
| **lf** | Shell `cp` | Shell `mv` | Shell `rm` | Shell `mv` | Via `:rename` command |
| **nnn** | `c` (copy as) | `m` (move as) | `d`/`D` | `r` | Batch rename plugin |
| **yazi** | `yy`/`dd`/`pp` | Vim-style | `d`/`D` | `r` | `:bulk rename` command |
| **ranger** | `yy`/`dd`/`pp` | Vim-style | `dd` | `cw` | `:bulkrename` |

**Current super-candy:** Only delete implemented (`d` → arm → `y`). No copy/move/rename.

**Key Insight:** Two patterns emerge:
1. **Shell delegation** (lf): Fast, flexible, but less visual feedback
2. **Builtin operations** (yazi, ranger): Better progress UI, undo support

**Recommendations for super-candy:**

```php
// HIGH PRIORITY - Add copy operation
public const OP_COPY = 'copy';
public const OP_MOVE = 'move';

private function performCopy(): self {
    $source = $this->activePane()->selectedNames();
    $dest = $this->inactivePane()->cwd;
    // Use copy() with progress callbacks
    foreach ($source as $name) {
        $this->copyWithProgress($dest, $name);
    }
    return $this->refresh();
}
```

**Effort Estimates:**
| Operation | Effort | Priority |
|-----------|--------|----------|
| Copy (cp shell) | 2 hours | HIGH |
| Move (mv shell) | 2 hours | HIGH |
| Rename | 3 hours | HIGH |
| Bulk rename | 6-8 hours | MEDIUM |
| Progress UI | 4 hours | MEDIUM |
| Async operations | 8-10 hours | LOW (requires ReactPHP) |

### 2.3 Preview Panels

| Manager | Preview Type | Implementation |
|---------|--------------|----------------|
| **superfile** | File preview panel | Toggle with `f`, uses external tools |
| **yazi** | Images, PDF, video, code | Built-in with Chafa fallback |
| **ranger** | Text, images, archives | scope.sh script integration |
| **nnn** | FIFO-based preview | External previewer plugin |
| **lf** | Image, text | Requires ueberzug integration |

**Current super-candy:** No preview panel.

**Key Insight:** Preview systems range from:
1. **Builtin protocols** (yazi): kitty graphics, Sixel, iTerm2 inline images
2. **External scripts** (ranger): scope.sh with 50+ preview handlers
3. **FIFO-based** (nnn): Spawn previewer process, communicate via named pipe

**Recommendation for super-candy:**
```php
// MEDIUM PRIORITY - Preview panel architecture
final class PreviewPane {
    public function __construct(
        public readonly ?string $path = null,
        public readonly ?string $content = null,
        public readonly PreviewType $type = PreviewType::None,
    ) {}
}

enum PreviewType {
    case None;
    case Text;
    case Image;
    case Binary;
}

// Toggle preview panel with 'i' key
case KeyType::Char && $msg->rune === 'i'
    => $this->withPreviewPanel($this->activePane()->currentEntry());
```

**Effort Estimates:**
| Feature | Effort | Priority |
|---------|--------|----------|
| Text preview (cat/highlight) | 4 hours | HIGH |
| Image preview (kitty/sixel) | 8 hours | MEDIUM |
| PDF preview | 6 hours | MEDIUM |
| Archive listing | 4 hours | MEDIUM |

### 2.4 Keybindings

**Pattern Analysis:**

1. **Ctrl-protected file operations** (superfile philosophy):
   - `ctrl+c` = copy, `ctrl+x` = cut, `ctrl+d` = delete
   - Non-ctrl keys never modify files
   - Safety-first approach

2. **Vim-style yank/delete/put** (yazi, ranger):
   - `yy` = copy, `dd` = cut, `p` = paste
   - More ergonomic for vim muscle memory
   - Requires visual mode for multi-select

3. **Single-key operations** (nnn):
   - `c` = copy, `m` = move, `d` = delete
   - Faster for experts
   - Less discoverable

**Current super-candy keybindings:**
```
Tab        → Swap active pane
↑↓ jk      → Move cursor
Enter/→    → Navigate into directory
←/h        → Go up to parent
Space      → Toggle selection + move down
s          → Cycle sort
.          → Toggle hidden files
d          → Delete (armed confirmation)
r          → Refresh
q          → Quit
/          → Search
t          → New tab
Ctrl+w     → Close tab
Ctrl+Tab   → Cycle tabs
u/Ctrl+z   → Undo
```

**Recommendations (vi-style enhancements):**
```php
// Add vim-style keybindings (optional mode)
case KeyType::Char && $msg->rune === 'y'
    => $this->yank();  // Yank (copy) selected to clipboard

case KeyType::Char && $msg->rune === 'x'
    => $this->cut();   // Cut (move) selected

case KeyType::Char && $msg->rune === 'p'
    => $this->put();   // Paste from clipboard

case KeyType::Char && $msg->rune === 'Y'
    => $this->copyPath();  // Copy full path

case KeyType::Char && $msg->rune === 'g' && $msg->shift
    => $this->gotoBottom();  // G is already gotoBottom

case KeyType::Char && $msg->rune === 'o'
    => $this->splitPane();  // Split new pane
```

---

## 3. Specific Improvements for Super-Candy

### 3.1 High Priority (MVP Enhancements)

#### 3.1.1 Copy Operation
**Effort:** 2-3 hours
**Pattern:** Shell `cp` with symlink handling

```php
// Add to Manager.php
private function yank(): self {
    $pane = $this->activePane();
    $names = $pane->selectedNames();
    if ($names === []) {
        $current = $pane->currentEntry();
        if ($current !== null && !$current->isParentSentinel()) {
            $names = [$current->name];
        }
    }
    if ($names === []) {
        return $this->withStatus('nothing to yank');
    }
    // Store in internal clipboard
    return $this->withClipboard($names, ClipboardOp::Copy)
        ->withStatus('yanked ' . count($names) . ' items');
}

private function put(): self {
    if ($this->clipboard === null) {
        return $this->withStatus('clipboard empty');
    }
    $dest = $this->inactivePane()->cwd;
    $errors = $this->performFileOp($this->clipboard->items, $dest, $this->clipboard->op);
    return $this->refresh()->withStatus("pasted {$this->clipboard->op->value}");
}
```

#### 3.1.2 Move Operation
**Effort:** 2 hours
**Pattern:** Shell `mv` with rename fallback for cross-filesystem

#### 3.1.3 Bulk Rename
**Effort:** 6-8 hours
**Pattern:** Visual mode + input prompt with regex replacement

```php
// Rename pattern: :s/foo/bar/g
case KeyType::Char && $msg->rune === 'R'
    => $this->enterBulkRename();
```

### 3.2 Medium Priority (User Experience)

#### 3.2.1 Progress Feedback for Operations
**Effort:** 4 hours
**Pattern:** Async with progress callback

```php
// For long operations, show progress bar in status
// Use ReactPHP Process for async cp/mv with stdout parsing
private function copyWithProgress(string $dest, string $name): void {
    $src = Pane::join($this->activePane()->cwd, $name);
    $process = new Process(['cp', '-r', $src, $dest]);
    $process->start();

    $process->on('stdout', function($out) {
        // Parse cp output for progress if available
    });
}
```

#### 3.2.2 Text Preview Panel
**Effort:** 4 hours
**Pattern:** Separate render mode with syntax highlighting via `highlight` or `bat`

#### 3.2.3 Bookmarks
**Effort:** 5 hours
**Pattern:** Persist to config, quick-jump with `'`

```php
case KeyType::Char && $msg->rune === 'm'
    => $this->markCurrentDirectory();

case KeyType::Char && $msg->rune === "'"
    => $this->showBookmarks();
```

### 3.3 Low Priority (Polish)

#### 3.3.1 Image Preview (Kitty/Sixel)
**Effort:** 8-10 hours
**Requires:** Terminal-specific protocol support

#### 3.3.2 Git Integration
**Effort:** 12 hours
**Pattern:** Spawn `git status`/`git diff` on demand, cache results

#### 3.3.3 Async File Operations
**Effort:** 10-15 hours (requires architecture change)
**Pattern:** ReactPHP Process pool with progress UI

---

## 4. Key Architectural Insights

### 4.1 Immutable State Pattern

All analyzed managers converge on immutable state for the model:
- **yazi:** Component-based, messages create new state
- **ranger:** FM class with copy-on-write directory objects
- **super-candy:** Already uses this pattern correctly

Super-candy's `withActivePane()` pattern is the correct approach.

### 4.2 Tab/Context Management

**yazi:** Tabs are independent, each with own pane state
**superfile:** File panels are independent views of the same filesystem
**super-candy:** Tabs contain `['left' => Pane, 'right' => Pane, 'activeIdx' => int]`

This is well-designed. Consider enhancement:
```php
// Option to link tab navigation (sync both panes to same directory)
private bool $syncTabNavigation = false;
```

### 4.3 Clipboard/Register Architecture

**ranger/yazi:** Use registers (named clipboards) for storing yanks
**nnn:** Single clipboard with positional copy/move
**super-candy:** Has `UndoAction` pattern, needs matching `ClipboardAction`

```php
final class ClipboardAction {
    public function __construct(
        public readonly array $items,      // ['path' => string, 'isDir' => bool]
        public readonly ClipboardOp $op,   // Copy or Move
        public readonly string $sourceDir,
    ) {}
}

enum ClipboardOp {
    case Copy;
    case case Move;
}
```

### 4.4 Error Handling

**Current super-candy:** Silent failure with error count in status

**Better pattern from superfile:**
1. Detailed error messages per-file
2. Option to abort on first error
3. Error log accessible post-operation

```php
private function performFileOp(array $items, string $dest, ClipboardOp $op): int {
    $errors = 0;
    $errorLog = [];
    foreach ($items as $item) {
        $result = match($op) {
            ClipboardOp::Copy => $this->safeCopy($item['path'], $dest),
            ClipboardOp::Move => $this->safeMove($item['path'], $dest),
        };
        if (!$result->success) {
            $errors++;
            $errorLog[] = $result->error;
        }
    }
    if ($errorLog !== []) {
        $this->withErrorLog($errorLog);
    }
    return $errors;
}
```

---

## 5. Recommended Implementation Roadmap

### Phase 1: Core Operations (Week 1)
| Task | Hours | Acceptance Criteria |
|------|-------|---------------------|
| Copy (yank) | 2 | `y` key yanks, internal clipboard stores paths |
| Paste (put) | 2 | `p` pastes to inactive pane, files copied |
| Move (cut) | 2 | `x` cuts, `p` moves instead of copying |
| Error reporting | 1 | Status shows "copied 3 items" or "copy failed: permission denied" |

### Phase 2: Enhanced Navigation (Week 2)
| Task | Hours | Acceptance Criteria |
|------|-------|---------------------|
| Path display | 1 | Full path visible, clickable path segments |
| Jump to parent | 1 | Click on path component navigates |
| Bookmarks | 5 | `m` to mark, `'` to show bookmark list |
| Quick navigation | 2 | `gc` goes to config, `gd` goes to downloads, etc. |

### Phase 3: Preview System (Week 3-4)
| Task | Hours | Acceptance Criteria |
|------|-------|---------------------|
| Text preview | 4 | `i` toggles preview pane, syntax highlighted |
| Image preview | 8 | Kitty/Sixel image display |
| Archive preview | 4 | List archive contents without extraction |

### Phase 4: Polish (Week 5+)
| Task | Hours | Acceptance Criteria |
|------|-------|---------------------|
| Bulk rename | 6 | Visual regex replacement |
| Git status | 8 | Show git status per-file, diff on enter |
| Async ops | 10 | Progress bars for large copy/move |
| Themes | 3 | Configurable colors |

---

## 6. References

### Upstream Sources
- **superfile:** https://github.com/yorukot/superfile | https://superfile.dev/
- **yazi:** https://github.com/sxyazi/yazi | https://yazi-rs.github.io/
- **ranger:** https://github.com/ranger/ranger | https://ranger.fm/
- **lf:** https://github.com/gokcehan/lf
- **nnn:** https://github.com/jarun/nnn | https://github.com/jarun/nnn/wiki

### Key Documentation Links
- superfile hotkeys: https://superfile.dev/configure/custom-hotkeys/
- yazi features: https://yazi-rs.github.io/features
- ranger scope.sh: https://github.com/ranger/ranger/blob/master/examples/scope.sh
- nnn plugins: https://github.com/jarun/nnn/tree/master/plugins

### Architecture References
- **Yazi async design:** https://yazi-rs.github.io/blog/why-is-yazi-fast
- **ReactPHP for async PHP:** https://reactphp.org/

---

## Appendix A: super-candy Current Implementation

### File Structure
```
super-candy/
├── src/
│   ├── Manager.php    # Main model, handles state transitions
│   ├── Pane.php       # Single pane state (cwd, entries, cursor, selection)
│   ├── Entry.php      # File/directory value object
│   ├── FsLister.php   # Filesystem listing closure
│   ├── Renderer.php   # Pure view function, ANSI output
│   ├── Sort.php       # Sort enum (NameAsc, NameDesc, SizeDesc, etc.)
│   ├── ConfirmState.php  # Delete confirmation enum
│   └── UndoAction.php    # Undo stack entries
├── tests/
│   ├── ManagerTest.php   # 24KB, extensive behavior tests
│   ├── PaneTest.php
│   ├── EntryTest.php
│   ├── RendererTest.php
│   └── SortTest.php
├── composer.json
└── phpunit.xml
```

### Current Capabilities
- ✅ Dual panes (left/right)
- ✅ Tab management (new, close, cycle)
- ✅ Navigation (enter, back, home, end)
- ✅ Selection (space to toggle, multi-select)
- ✅ Delete with confirmation
- ✅ Sort cycling
- ✅ Hidden file toggle
- ✅ Search (incremental, enter to open)
- ✅ Undo stack
- ✅ Refresh

### Missing Capabilities (Priority Order)
1. ❌ Copy (yank/paste)
2. ❌ Move (cut/paste)
3. ❌ Rename
4. ❌ Progress feedback
5. ❌ Preview panel
6. ❌ Bookmarks
7. ❌ Bulk rename
8. ❌ Async operations
