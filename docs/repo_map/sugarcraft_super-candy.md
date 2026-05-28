# SugarCraft/super-candy

## Metadata
- **URL:** https://github.com/sugarcraft/super-candy
- **Language:** PHP 8.3+
- **Stars:** N/A (internal SugarCraft monorepo package)
- **License:** MIT
- **Description:** Dual-pane terminal file manager built on the SugarCraft stack. Inspired by [yorukot/superfile](https://github.com/yorukot/superfile) and Midnight Commander. Navigate two directories side-by-side, sort by name/mtime/size, multi-select, copy/move/rename/delete with confirmation, tabs, search, and undo.
- **Status:** 🟢 v1 ready
- **Upstream:** [yorukot/superfile](https://github.com/yorukot/superfile) (~17k GitHub stars, Go)

---

## Feature List

### Core Navigation
- **Dual-pane layout** — Two side-by-side file listings with independent cursors
- **Tab swap** — `Tab` key swaps focus between left and right panes
- **Directory traversal** — `Enter`/`→` opens directory under cursor; `←`/`h` goes up one level
- **Parent sentinel** — `..` entry injected at top of every non-root pane listing
- **Hidden files toggle** — `.` key shows/hides dotfiles
- **Keyboard navigation** — Full vi-style (j/k for up/down, g/G for top/bottom, `Home`/`End`)
- **Tab management** — Duplicate tab (`t`), close tab (`Ctrl+w`), cycle tabs (`Ctrl+Tab`/`Ctrl+Shift+Tab`)

### File Operations
- **Multi-select** — `Space` toggles selection; supports batch operations on multiple entries
- **Copy** (`c`) — Copy selection or cursor item to inactive pane; `y` confirms
- **Move** (`m`) — Move selection or cursor item to inactive pane; `y` confirms
- **Rename** (`R`) — Rename cursor entry with confirmation
- **Delete** (`d`) — Delete with `y` confirmation gate; supports undo for files (not directories)
- **Undo/Redo** — `u` or `Ctrl+z` undoes last operation; delete/move/rename reversible; copy informational only

### Sorting and Filtering
- **Sort cycling** (`s`) — Cycles: name-asc → name-desc → mtime-asc → mtime-desc → size-asc → size-desc → back to name-asc
- **Directories-first** — All sort orders group directories above files
- **Case-insensitive sorting** — Name sorts ignore case
- **Search** (`/`) — Live filter by name (case-insensitive substring match); `↑↓` navigate results; `Enter` opens directory; `Escape` exits; `Backspace` removes characters

### Preview and Rendering
- **File preview pane** (`PreviewPane`) — Image preview via `candy-mosaic` (Kitty/iTerm2/Sixel protocols); text metadata block for non-images (size, mtime, mode, MIME type)
- **Bulk rename engine** (`BulkRename`) — PCRE regex pattern + template with placeholders: `{name}`, `{ext}`, `{n}`, `{N}`, `$1`-$9`; sequential numbering with zero-padding
- **Async file operations** (`AsyncOps`) — ReactPHP Promise-based copy/move/rename; `futureTick` defers to next event loop tick; parallel `copyManyAsync`/`moveManyAsync`

### UI and Status
- **Confirmation gate** — Three-phase: `armXxx()` → pending state → next key is `y` (confirm) or anything else (cancel)
- **Status line** — Shows operation results, errors, key help
- **i18n support** — `Lang::t()` facade wrapping `SugarCraft\Core\I18n\T` with `supercandy` namespace; `lang/en.php` with 40+ translation keys
- **Theming** — Uses `candy-sprinkles` (`Style`, `Border`, `Layout`) for ANSI rendering

---

## Architecture

### Core Classes

| File | Lines | Responsibility |
|------|-------|-----------------|
| `Manager.php` | 915 | **God class** — TEA Model, handles all key dispatch, confirm gate, file operations, tabs, search, undo/redo |
| `Pane.php` | 181 | One pane state: cwd, entries, cursor, selection set, sort, showHidden. Immutable with `with*()` helpers |
| `Entry.php` | 61 | Value object: name, isDir, size, mtime, isLink, isHidden. `displaySize()` for human-readable sizes |
| `Sort.php` | 92 | PHP enum with 6 sort orders; `apply()` sorts entries dirs-first; `cycle()` advances through orders |
| `FsLister.php` | 46 | Default lister: `scandir` + `lstat`. Injected as `Closure(string): list<Entry>` for testability |
| `Renderer.php` | 117 | Pure view function: two panes side-by-side via `Layout::joinHorizontal`, pane header, selection markers, cursor arrow |
| `ConfirmState.php` | 21 | Enum: None / DeleteSelected / CopySelected / MoveSelected / RenameSelected |
| `UndoAction.php` | 88 | Value object: description + items array. Factory methods: `delete()`, `move()`, `rename()`, `copy()`, `mkdir()` |
| `AsyncOps.php` | 202 | ReactPHP async wrappers: `copyAsync`, `moveAsync`, `renameAsync`, `copyManyAsync`, `moveManyAsync` |
| `BulkRename.php` | 294 | Immutable bulk rename engine: pattern + template + placeholders + preview + execute |
| `PreviewPane.php` | 299 | Immutable file preview: image rendering via Mosaic, metadata block for others |
| `Lang.php` | 22 | Thin facade extending `SugarCraft\Core\I18n\Lang` with `supercandy` namespace |

### Key Design Patterns

**1. Immutable State + Fluent Setters**
```php
// Every mutation returns a new instance
public function toggleSelection(): self
{
    return new self($this->cwd, $this->entries, $this->cursor, $sel, ...);
}
```

**2. Dependency Injection for Testability**
```php
// Filesystem reads injected as closure — tests pass a fake
Pane::open($cwd, fn(string $path): list<Entry> => $fakeEntries);
```

**3. Three-Phase Confirm Gate**
```php
// Phase 1: arm — sets ConfirmState enum + status prompt
// Phase 2: wait — next KeyMsg consumed by resolveConfirm()
// Phase 3: execute — y confirms, anything else cancels
```

**4. Tab Architecture**
```php
// Each tab: ['left' => Pane, 'right' => Pane, 'activeIdx' => int]
// Tabs shown only when count > 1
// activeIdx: 0 = left pane active, 1 = right pane active
```

**5. Undo Stack with String-Based Action Detection**
```php
// reverseAction() uses str_starts_with($desc, 'delete ') — fragile but works
// Delete captures file content before deletion for restore
// Move/rename stores src→dst mapping for reversal
// Copy is informational only (original preserved)
```

---

## Key Components Detail

### Entry (`src/Entry.php`)
- Immutable value object for one file/directory listing row
- `parent()` factory creates `..` sentinel
- `displaySize()` — directories → "DIR", links → "LINK", files → human-readable (B/KB/MB/GB/TB)
- `isParentSentinel()` — detects `..` entry

### Pane (`src/Pane.php`)
- Immutable pane state container
- `open()` — factory: lists directory via lister, injects parent sentinel, applies sort
- `navigate()` — moves into directory or up via parent sentinel
- `moveCursor()` — clamped to `[0, count-1]`
- `gotoTop()` / `gotoBottom()`
- `toggleSelection()` — adds/removes cursor entry from `selected` map (name-based, not index-based)
- `clearSelection()`
- `setSort()` / `toggleHidden()`
- `currentEntry()` — returns entry at cursor position
- `selectedNames()` — returns list of selected entry names
- `parentPath()` / `join()` — static path helpers

### Sort (`src/Sort.php`)
- PHP 8.1 enum with 6 cases
- `apply()` — sorts entries dirs-first, then by chosen key; `..` sentinel always at index 0
- `cycle()` — advances through the 6 orders in sequence

### Manager (`src/Manager.php`)
- Implements `SugarCraft\Core\Model` (init/update/view interface)
- Uses `SubscriptionCapable` trait
- Key dispatch via `dispatch()` match statement on `KeyType` + `rune`
- `withActivePane()` — applies a `Closure(Pane):Pane` to the currently focused pane
- `armDelete/Copy/Move/Rename()` — set confirm state + pendingOpDest/Type
- `resolveConfirm()` — consumes next keystroke: `y` → performXxx(), else cancel
- `performDelete/Move/Copy/Rename()` — actually executes the file operation
- `copy()` / `move()` / `rename()` — public file operation methods
- `search()` / `exitSearch()` / `moveSearchCursor()` / `openSearchResult()` — search mode
- `undo()` — pops from undoStack, calls `reverseAction()`, pushes to redoStack
- `openNewTab()` / `closeTab()` / `switchTab()` / `duplicateTab()` — tab management
- `reverseAction()` — uses string prefix matching on description; handles delete/restore, move/rename reversal

### Renderer (`src/Renderer.php`)
- Pure static `render(Manager): string`
- `renderPane()` — builds pane box with header (cwd + sort label + hidden indicator), separator line, entry rows
- Entry row format: `▸ ` (cursor) + `✓ ` (selected) + name (dirs get `/` suffix) + padded size
- `renderTabBar()` — shows tab labels when multiple tabs exist
- `renderSearch()` — shows search query + filtered results + cursor

### FsLister (`src/FsLister.php`)
- `lister(): \Closure` — returns closure that uses `scandir` + `lstat`
- Sets `isHidden` via `str_starts_with($name, '.')`

### AsyncOps (`src/AsyncOps.php`)
- Uses `React\Promise\Deferred` + `React\EventLoop\Loop::futureTick()`
- `copyAsync()` / `moveAsync()` / `renameAsync()` — single file/directory async operations
- `copyManyAsync()` / `moveManyAsync()` — parallel batch operations via `React\Promise\all()`

### BulkRename (`src/BulkRename.php`)
- Immutable + fluent: `withPattern()`, `withTemplate()`, `withStartNum()`, `withStepNum()`, `withPadNum()`
- `hasValidPattern()` — validates PCRE pattern
- `preview()` — returns `list<['original' => string, 'renamed' => string]>`
- `renamed()` — returns just the new names
- `execute()` — runs renamer closure on each file
- Template placeholders: `{name}`, `{ext}`, `{n}`, `{N}`, `$1`-$9`

### PreviewPane (`src/PreviewPane.php`)
- Immutable: `forFile()`, `withWidth()`, `withHeight()`
- `isImage()` — checks extension against supported list
- `render()` — delegates to `renderImage()` (Mosaic) or `renderMetadata()`
- `renderMetadata()` — shows size, mtime, mode (rwxrwxrwx), MIME type, symlink target

---

## SugarCraft Mapping

| Component | SugarCraft Dependency | Role |
|-----------|----------------------|------|
| Manager (TEA Model) | `candy-core` | Implements `Model` interface, uses `KeyMsg`, `Cmd`, `KeyType`, `Subscriptions` |
| Rendering | `candy-sprinkles` | `Style`, `Border`, `Layout` for pane boxes and status bar |
| Image preview | `candy-mosaic` | `Mosaic::probe()`, `ImageSource::fromFile()` for Kitty/Sixel/iTerm2 rendering |
| i18n | `candy-core` | `SugarCraft\Core\I18n\Lang` base class |
| Async ops | `react/promise` | `React\Promise\Deferred`, `React\EventLoop\Loop` |

---

## Comparison with Upstream (yorukot/superfile)

### Architecture Differences

| Aspect | superfile (Go) | super-candy (PHP) |
|--------|----------------|------------------|
| **Panel model** | Multi-panel (N file panels, not just 2) | Dual-pane (fixed 2 panes) |
| **Focus system** | `noneFocus` / `focus` / `secondFocus` states | Single `activeIdx` (0 or 1) |
| **File model** | Separate `filemodel.FileModel` package | Single `Manager` class with all state |
| **Panel package** | `src/internal/ui/filepanel/` (refactored in PR #1195) | Flat `Pane` class |
| **Navigation** | `L`/`H` for pane switch, explicit panel objects | `Tab` to swap activeIdx |
| **Search** | fzf-based fuzzy filtering | Substring match (case-insensitive) |

### superfile Advantages
- **Multi-column view** — date/size/permission columns (v1.5.0)
- **Plugin system** — external tool integration
- **Auto-update** — checks GitHub for new releases
- **Video/PDF preview** — built-in via external tools
- **Vim-mode hotkeys** — optional vi keybindings
- **Configurable fast navigation** — booth-style panel navigation
- **Multiple file panels** — more than 2 at once
- **Larger ecosystem** — 110 contributors, 24 releases, v1.5.0

### super-candy Advantages
- **Pure PHP** — no Go toolchain required
- **Testable architecture** — closure injection enables unit testing without fixtures
- **Immutable state** — no shared mutable state; every transition returns new instance
- **Simpler codebase** — 12 source files vs. dozens of Go packages
- **Confirmation gate pattern** — explicit `ConfirmState` enum vs. ad-hoc prompts
- **ReactPHP async** — non-blocking file operations

---

## Comparison with Other File Managers

### Midnight Commander (MC)
- **Origin** — The classic dual-pane file manager (1984-present), ncurses-based
- **super-candy similarity** — Same dual-pane layout, similar keybindings (F5 copy, F6 move, F8 delete)
- **Differences** — MC has functional keys, menu bar, command line, panelize feature, built-in editor/viewer

### yazi (Rust, ~32k stars)
- **Architecture** — Async/await with multi-threaded I/O, real-time progress updates
- **super-candy gap** — yazi has full image preview (kitty/Sixel/iTerm2), code syntax highlighting, Lua plugin system, git integration, mount manager
- **super-candy advantage** — Simpler architecture, pure PHP, easier to extend

### ranger (Python, ~9k stars)
- **Architecture** — Three-column display (parent/current/preview), scope.sh integration
- **super-candy similarity** — Preview pane concept; vi keybindings
- **super-candy gap** — ranger has extensive scope.sh plugins (50+ preview handlers), rifle launcher

### lf (Go, ~8.9k stars)
- **Architecture** — Single-pane client/server, delegates file ops to shell
- **super-candy gap** — lf has no built-in file operations (all shell), asynchronous I/O
- **super-candy advantage** — Built-in file operations with visual feedback and undo

### nnn (C, ~15k stars)
- **Architecture** — Single-pane with "contexts" (virtual tabs), extremely lightweight
- **super-candy gap** — nnn is ~100KB binary, POSIX-compliant, disk usage analyzer
- **super-candy advantage** — Dual-pane view, more visual feedback, confirmation gates

---

## File Manager Patterns

### Multi-Select Handling

**super-candy pattern** (name-based selection map):
```php
// Selected entries stored as map: name → true
public readonly array $selected; // array<string,true>

public function toggleSelection(): self
{
    $sel = $this->selected;
    if (isset($sel[$entry->name])) {
        unset($sel[$entry->name]);
    } else {
        $sel[$entry->name] = true;
    }
    return new self(..., $sel, ...);
}
```
- **Advantage** — Selection survives refresh/re-sort (names, not indices)
- **Pattern used by** — superfile, yazi, ranger

### Keyboard Navigation

| Key | Action | super-candy | superfile | yazi | ranger |
|-----|--------|-------------|-----------|------|--------|
| `Tab` | Swap panes | ✅ | ✅ | N/A (single pane) | N/A |
| `j` / `↓` | Move down | ✅ | ✅ | ✅ | ✅ |
| `k` / `↑` | Move up | ✅ | ✅ | ✅ | ✅ |
| `g` / `Home` | Top | ✅ | ✅ | ✅ | ✅ |
| `G` / `End` | Bottom | ✅ | ✅ | ✅ | ✅ |
| `Enter` / `→` | Open dir | ✅ | ✅ | ✅ | ✅ |
| `←` / `h` | Parent | ✅ | ✅ | ✅ | ✅ |
| `Space` | Toggle select | ✅ | ✅ | ✅ | ✅ |
| `c` | Copy | ✅ | `Ctrl+c` | `yy` | `yy` |
| `m` | Move | ✅ | `Ctrl+x` | `dd` | `dd` |
| `R` | Rename | ✅ | `Ctrl+r` | `r` | `cw` |
| `d` | Delete | ✅ | `Ctrl+d`/`D` | `d`/`D` | `dd` |
| `/` | Search | ✅ | `/` | `/` | `/` |
| `u` | Undo | ✅ | `Ctrl+z` | `u` | `u` |
| `t` | New tab | ✅ | `Ctrl+t` | `tab` | `t` |

### Sort Implementation

super-candy uses a **dirs-first + secondary key** comparator:
```php
$dirsFirst = static function (Entry $a, Entry $b, \Closure $tiebreak): int {
    if ($a->isDir !== $b->isDir) {
        return $a->isDir ? -1 : 1;  // Dirs always first
    }
    return $tiebreak($a, $b);  // Secondary sort as tiebreaker
};
```

This pattern is consistent with Midnight Commander and superfile. Yazi uses a similar approach.

### Confirmation Gate Pattern

super-candy's three-phase confirm gate:
```
State: ConfirmState::None
    ↓ [press 'd']
State: ConfirmState::DeleteSelected (armed, status shows prompt)
    ↓ [press 'y']
State: ConfirmState::None + performDelete() executed
    ↓ OR
State: ConfirmState::None + cancelled (any other key)
```

This pattern prevents accidental deletions. superfile uses similar modal confirmations.

---

## Strengths

1. **Pure immutable state** — Every transition returns a new `Manager` instance; no shared mutable state
2. **Testable architecture** — Filesystem lister injected as closure; all file operations are method calls on `Manager`, not static functions
3. **Confirmation gates** — Explicit `y/n` confirmation for all destructive operations
4. **Comprehensive i18n** — 40+ translation keys via `Lang::t()` facade
5. **ReactPHP async** — Non-blocking file operations keep TUI responsive during large copies
6. **Tab support** — Multiple independent tab sessions, each with own left/right panes
7. **Search mode** — Live incremental filtering with keyboard navigation through results
8. **Bulk rename** — PCRE regex + template engine with sequential numbering
9. **File preview** — Image preview via candy-mosaic; metadata block for other files
10. **Undo stack** — Delete/move/rename reversible; delete captures file content for restore
11. **Documentation** — Well-documented source code; README with ASCII diagram of layout

---

## Weaknesses (Documented in sugarcrash_findings.md)

1. **God class** — `Manager` at 915 lines handles too many responsibilities (navigation, file ops, tabs, search, undo, confirm gate)
2. **Constructor parameter explosion** — 14 constructor parameters (`left`, `right`, `activeIdx`, `status`, `confirm`, `lister`, `searchQuery`, `searchResults`, `searchCursor`, `tabs`, `tabIndex`, `showTabBar`, `undoStack`, `redoStack`, `pendingOpDest`, `pendingOpType`)
3. **String-based action detection** — `reverseAction()` uses `str_starts_with($desc, 'delete ')` for action type detection; fragile if strings change
4. **Error suppression** — `@` suppression in `FsLister::lister()` hides legitimate errors
5. **Renderer::render() ternary** — 200+ character ternary expressions in `renderPane()` hurt readability
6. **Copy undo informational only** — `UndoAction::copy` logged but not reversed (expected behavior, but not documented)
7. **No progress feedback** — File operations show no progress bar during large copies/moves
8. **No vim-mode option** — Unlike superfile's optional vim hotkeys
9. **No plugin system** — superfile has plugin architecture; super-candy does not
10. **No auto-update** — superfile checks GitHub for new releases; super-candy does not
11. **Limited preview formats** — Only images via Mosaic; no PDF, video, or code syntax highlighting

---

## Test Coverage

- **36 tests / 65 assertions** (per README)
- `EntryTest` — size formatting, parent sentinel
- `SortTest` — all 6 orders × dirs-first × cycle
- `PaneTest` — open, navigate, move, select, sort, hidden toggle, parent path, join
- `ManagerTest` — Tab swap, key dispatch per pane, confirm gate, refresh, search, tabs, undo
- `ManagerCopyTest` — arm/confirm/cancel copy flow, recursive directory copy
- `ManagerMoveTest` — arm/confirm/cancel move flow
- `ManagerRenameTest` — arm/confirm/cancel rename flow
- `AsyncOpsTest` — Promise resolution, batch operations, directory copy
- `RendererTest` — Output contains expected markers, handles empty dirs
- `BulkRenameTest` — Pattern validation, template application, conflict detection
- `PreviewPaneTest` — Image detection, metadata rendering
- `UndoActionTest` — Action creation and reversal logic
- `LangCoverageTest` — i18n key coverage verification

---

## Dependencies

```json
{
  "require": {
    "php": ">=8.3",
    "sugarcraft/candy-core": "dev-master",
    "sugarcraft/candy-sprinkles": "dev-master",
    "sugarcraft/candy-mosaic": "dev-master",
    "react/promise": "^3.3"
  }
}
```

| Dependency | Purpose |
|-------------|---------|
| `candy-core` | TEA Model, KeyMsg, Cmd, Program, Ansi utilities |
| `candy-sprinkles` | Style, Border, Layout for pane rendering |
| `candy-mosaic` | Image preview rendering (Kitty/Sixel/iTerm2 protocols) |
| `react/promise` | Async file operations via promises |

---

## Entry Point

```bash
./bin/supercandy [LEFT_DIR] [RIGHT_DIR]
```

Default: left pane = current directory, right pane = `$HOME`.

Uses `SugarCraft\Core\Program` with `useAltScreen: true` option.
