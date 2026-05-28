# Ecosystem Intelligence Report: super-candy

**Package:** sugarcraft/super-candy (dual-pane file manager)
**Report Date:** 2026-05-28
**Upstream:** yorukot/superfile (~17k stars, Go)
**Status:** v1 ready

---

# Overview

super-candy is a dual-pane terminal file manager built on the SugarCraft TUI stack, inspired by yorukot/superfile and Midnight Commander. It provides comprehensive file navigation with two side-by-side panes, vi-style keybindings, multi-select, async file operations, undo/redo, tabs, search, and image preview via candy-mosaic.

**Ecosystem Positioning:** super-candy sits at the intersection of the Charmbracelet/Go ecosystem (upstream superfile) and the SugarCraft/PHP ecosystem. It is the most feature-complete TUI application in SugarCraft, demonstrating full integration of core ( TEA Model), sprinkles (styling), mosaic (image preview), and react/promise (async).

**Biggest Opportunities:**
1. **Plugin architecture** — superfile has a plugin system; super-candy does not
2. **Multi-column metadata display** — superfile v1.5.0 shows date/size/permission columns
3. **Progress feedback during file ops** — yazi and ranger show real-time progress bars
4. **Extended preview formats** — PDF, video, code syntax highlighting (superfile, ranger)
5. **Vim-mode hotkeys** — optional vi keybindings in superfile

**Biggest Missing Capabilities:**
1. No plugin/extensibility system
2. No progress bars for large file operations
3. No multi-column metadata view (date, size, permissions)
4. No vim-mode hotkey option
5. No auto-update mechanism (superfile checks GitHub releases)
6. Search is substring match only (superfile uses fzf fuzzy filtering)

---

# Internal Capability Summary

## Current Architecture

### Core Components

| Component | Lines | Responsibility |
|-----------|-------|-----------------|
| `Manager.php` | 915 | **God class** — TEA Model, all key dispatch, confirm gate, file operations, tabs, search, undo/redo |
| `Pane.php` | 181 | Immutable pane state: cwd, entries, cursor, selection, sort, showHidden |
| `Entry.php` | 61 | Value object: name, isDir, size, mtime, isLink, isHidden |
| `Sort.php` | 92 | PHP 8.1 enum with 6 sort orders; dirs-first comparator |
| `Renderer.php` | 117 | Pure static `render(Manager): string` view function |
| `FsLister.php` | 46 | Default lister: `scandir` + `lstat` as injected closure |
| `AsyncOps.php` | 202 | ReactPHP async wrappers: copy/move/rename with `futureTick` |
| `PreviewPane.php` | 299 | Image preview via Mosaic; metadata block for non-images |
| `BulkRename.php` | 294 | PCRE regex + template bulk rename engine |
| `ConfirmState.php` | 21 | Enum: None/DeleteSelected/CopySelected/MoveSelected/RenameSelected |
| `UndoAction.php` | 88 | Value object: description + items; factory methods for delete/move/rename/copy/mkdir |
| `Lang.php` | 22 | Thin facade extending `SugarCraft\Core\I18n\Lang` with `supercandy` namespace |

### Design Patterns

1. **Immutable State + Fluent Setters** — Every mutation returns a new instance
2. **Dependency Injection for Testability** — `FsLister` injected as `Closure(string): list<Entry>`
3. **Three-Phase Confirm Gate** — `armXxx()` → pending state → `y` confirms / anything else cancels
4. **Tab Architecture** — `['left' => Pane, 'right' => Pane, 'activeIdx' => int]`
5. **Undo Stack with String-Based Action Detection** — `str_starts_with($desc, 'delete ')` for reversal

### Strengths

1. Pure immutable state — no shared mutable state
2. Closure injection enables unit testing without tmp dirs
3. Confirmation gates prevent accidental destructive operations
4. 40+ translation keys via `Lang::t()` facade
5. ReactPHP async keeps TUI responsive during I/O
6. Multiple independent tab sessions
7. Live incremental search filtering
8. PCRE regex bulk rename engine
9. Image preview via candy-mosaic (Kitty/Sixel/iTerm2)
10. Delete/move/rename reversible; delete captures file content for restore

### Weaknesses (Documented)

1. **God class** — `Manager` at 915 lines handles too many responsibilities
2. **Constructor parameter explosion** — 15 constructor parameters
3. **String-based action detection** — fragile if strings change
4. **Error suppression** — `@` in `FsLister::lister()` hides legitimate errors
5. **Renderer ternary expressions** — 200+ char expressions hurt readability
6. **Copy undo informational only** — not reversed, but not documented
7. **No progress feedback** — file operations show no progress bar
8. **No vim-mode option** — unlike superfile
9. **No plugin system** — superfile has plugin architecture
10. **No auto-update** — superfile checks GitHub for new releases
11. **Limited preview formats** — only images; no PDF, video, or code highlighting

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|------------|---------------------------|---------|
| `yorukot/superfile` | **DIRECT UPSTREAM** | Multi-panel, plugin system, fzf search, vim-mode, auto-update, multi-column metadata | CRITICAL |
| `charmbracelet/bubbletea` | **TUI FRAMEWORK** | Elm architecture, cell-based delta rendering, synchronized output (mode 2026), command pattern | CRITICAL |
| `charmbracelet/bubbletea` PR analysis | **PRODUCER** | Multi-model routing pain, data races, signal leaks, testing gaps, declarative views | HIGH |
| `textualize/textual` | **SISTER FRAMEWORK** | Reactive state, CSS layout, message pump, widget composition, command palette | HIGH |
| `php-tui/php-tui` | **COMPETITOR/PORT** | Cassowary constraint layout, double-buffering with diff, widget visitor pattern | MEDIUM |
| `treilik/bubblelister` | **COMPONENT** | tea.Model list component, viewport cursor offset, concurrent search, sort.Interface pattern | MEDIUM |
| `yazi-rs/yazi` | **COMPETITOR FM** | Async/await with threads, real-time progress, Lua plugin system, git integration, code highlighting | HIGH |
| `ranger/ranger` | **COMPETITOR FM** | scope.sh preview plugins (50+ handlers), rifle launcher, three-column display, vim keys | HIGH |
| `g Charities/soft-serve` | **REFERENCE APP** | Bubble Tea TUI patterns, bubblezone mouse, syntax highlighting, BubbleTeaMiddleware stack | MEDIUM |
| `charmbracelet/vhs` | **TOOLING** | .tape DSL format, frame capture for demos, theme system | LOW |
| `charmbracelet/glow` | **REFERENCE APP** | Fuzzy filtering, fsnotify file watching, markdown rendering, adaptive colors | LOW |

---

# Feature Gap Analysis

## Critical

### 1. No Progress Feedback During File Operations
**Title:** Missing progress bar for large copy/move operations
**Description:** When copying or moving large directories, super-candy provides no visual feedback. The user sees only a spinning status after completion.
**Why it matters:** Users performing bulk operations on large files have no indication of progress, time remaining, or whether the operation is hung.
**Source:** `docs/repo_map/sugarcraft_super-candy.md` (documented weakness #7)
**Implementation ideas:**
- Use bubbletea's built-in progress bar (merged PR #1499, mapped as `View::progressBar` in sugar-bits)
- Stream file sizes upfront; calculate percentage as bytes copied/total
- Show per-file progress for batch operations
- Use ANSI progress bar escape sequences compatible with modern terminals
**Complexity:** Medium — requires threading progress state through AsyncOps callbacks
**Impact:** High — critical UX gap vs. yazi, ranger, and superfile

### 2. String-Based Undo Action Detection
**Title:** Fragile `reverseAction()` string prefix matching
**Description:** `reverseAction()` uses `str_starts_with($desc, 'delete ')` to determine action type. If translation strings or descriptions change, reversal breaks silently.
**Why it matters:** Undo for delete/move/rename is a core safety feature. Fragile detection can cause data loss.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:352`
**Implementation ideas:**
- Replace string detection with `UndoAction` enum property `actionType: enum{delete,move,rename,copy,mkdir}`
- Factory methods already exist (`UndoAction::delete()`, `::move()`, etc.) — just store the type
- Simplest fix: add `private const ACTION_TYPE = 'delete'` to each factory method's return
**Complexity:** Low — add enum property, change one string match to property check
**Impact:** High — data safety critical

## High

### 3. Plugin/Extension Architecture
**Title:** No extensibility system for custom previews or operations
**Description:** superfile has a plugin system for integrating external tools. super-candy has no extension point.
**Why it matters:** Users cannot add PDF preview, video thumbnail, git status, or custom operations without modifying core code.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:358` (superfile advantage #1)
**Implementation ideas:**
- Define a `PluginInterface` with `preview(FileInfo): ?string` and `operate(string $op, array $args): mixed`
- Register plugins via `Manager::withPlugin(Plugin)` returning new Manager
- `PreviewPane::render()` checks plugins before default image/metadata handling
- Similar to how treilik/bubblelister uses pluggable Prefixers/Suffixers
**Complexity:** High — requires architectural thought on isolation, lifecycle, configuration
**Impact:** High — major differentiator vs. superfile

### 4. Search Quality — Fuzzy vs Substring
**Title:** Search uses case-insensitive substring match; superfile uses fzf
**Description:** `/` initiates live filter by case-insensitive substring. superfile uses fzf fuzzy matching with scoring.
**Why it matters:** Fuzzy matching finds files more naturally (e.g., "ndl" finds "news_delivery"). Substring requires sequential characters.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:207` (superfile vs super-candy comparison)
**Implementation ideas:**
- Port or wrap a PHP fuzzy matching library (e.g., `subtlesoft/sphynx`, or `noriance/php-fuzzy`)
- Or use SQLite FTS5 for fuzzy matching (heavy for this use case)
- Simple middle ground: add fuzzy ranking to existing substring results
**Complexity:** Medium — requires evaluating PHP fuzzy libraries or algorithmic implementation
**Impact:** Medium — meaningfully improves UX for large directories

### 5. Multi-Column Metadata Display
**Title:** Only name + size displayed; no date/permission columns
**Description:** superfile v1.5.0 added multi-column display (date, size, permission). super-candy shows only name and size.
**Why it matters:** Users cannot sort by mtime without cycling through all sort orders; cannot see file permissions at a glance.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:210` (superfile advantage)
**Implementation ideas:**
- Add optional columns: mtime (human-readable), mode (rwxrwxrwx), owner/group
- Toggle column visibility via keybinding (e.g., `Ctrl+l` for layout toggle)
- Use php-tui's `TableWidget` concept (from `docs/repo_map/php-tui_php-tui.md`) for column layout
- Respect available terminal width — collapse to name+size on narrow terminals
**Complexity:** Medium — requires pane layout refactoring, width-aware rendering
**Impact:** Medium — matches superfile feature parity

### 6. Vim-Mode Hotkeys
**Title:** No optional vi keybindings beyond basic j/k navigation
**Description:** superfile offers optional vim-mode hotkeys. super-candy has vi-style j/k navigation but not mode-based vi.
**Why it matters:** Power users familiar with vim expect `dd` to delete, `yy` to yank, `p` to paste.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:214` (superfile advantage)
**Implementation ideas:**
- Add `vimMode: bool` to Manager state
- When enabled, remap single-key operations through vim state machine (normal/visual/pending)
- Visual mode: `v` enters visual, `d`/`y`/`x` operate on selection
- `:` for command-line mode (not needed for MVP)
**Complexity:** Medium — requires vim state machine with mode transitions
**Impact:** Medium — appeals to vim users; differentiator from superfile's approach

### 7. No Auto-Update Mechanism
**Title:** super-candy does not check for new releases
**Description:** superfile auto-checks GitHub for new releases and prompts user. super-candy has no update check.
**Why it matters:** Users may be running outdated versions with bugs or security issues.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:359`
**Implementation ideas:**
- On startup, fire a non-blocking HTTP request to GitHub API for latest release
- Compare `composer.json` version to latest tag
- Display badge in status line if update available
- Do not auto-update — just notify (like superfile)
**Complexity:** Low — one HTTP call on startup, compare versions
**Impact:** Low-Medium — nice-to-have for user experience

## Medium

### 8. Limited Preview Formats (No PDF/Video/Code Highlighting)
**Title:** Preview pane only handles images via Mosaic
**Description:** PreviewPane uses candy-mosaic for images; all other files get a metadata block. No content preview for PDFs, videos, or code.
**Why it matters:** superfile and ranger can preview PDFs, videos, and code files directly in the TUI.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:361`
**Implementation ideas:**
- For PDFs: use `pdftotext` + render as text, or `pdfinfo` for metadata
- For videos: use `ffmpegthumbnailer` or `ffprobe` for metadata + frame extraction
- For code: integrate with `charmbracelet/glamour` or a PHP syntax highlighter (e.g., `lexporter/code-block`)
- Register handlers via PluginInterface
**Complexity:** High — requires external tool integration, platform-specific binaries
**Impact:** Medium — table stakes for file managers; users expect preview

### 9. Constructor Parameter Explosion
**Title:** Manager constructor has 15 parameters
**Description:** `Manager` constructor takes: left, right, activeIdx, status, confirm, lister, searchQuery, searchResults, searchCursor, tabs, tabIndex, showTabBar, undoStack, redoStack, pendingOpDest, pendingOpType
**Why it matters:** Violates PSR-12 and makes instantiation error-prone. Hard to test individual state transitions.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:351`
**Implementation ideas:**
- Introduce a `ManagerState` value object containing all mutable state
- Constructor takes only dependencies (lister) + initial `ManagerState`
- `withState(ManagerState)` returns new Manager for transitions
- Builder pattern for construction: `ManagerBuilder::create()->withPane($left)->withPane($right)->build()`
**Complexity:** Medium — refactoring but doesn't change behavior
**Impact:** Medium — code quality, testability, maintainability

### 10. Renderer Ternary Readability
**Title:** 200+ character ternary expressions in Renderer::renderPane()
**Description:** Entry row formatting uses complex nested ternary operators, reducing readability.
**Why it matters:** Maintenance and modification are error-prone. Hard to debug rendering issues.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:354`
**Implementation ideas:**
- Extract row formatting to private helper methods: `formatCursorMarker()`, `formatSelectionMarker()`, `formatSize()`, `formatName()`
- Use early returns instead of nested ternaries
- Consider using `candy-sprinkles` `Style` objects for composed styling rather than inline conditionals
**Complexity:** Low — pure refactor, no behavior change
**Impact:** Low — code quality improvement

### 11. God Class — Manager at 915 Lines
**Title:** Manager handles navigation, file ops, tabs, search, undo, confirm gate
**Description:** Single class at 915 lines violates single responsibility principle.
**Why it matters:** Hard to test independently, hard to understand, likely has hidden耦合.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:350`
**Implementation ideas:**
- Extract `SearchState` to its own class with search-specific logic
- Extract `TabManager` handling tabs and active pane state
- Extract `UndoRedoManager` handling undo/redo stack operations
- Keep Manager as thin coordinator (TEA Model interface only)
- Each extracted class is immutable with `with*()` transitions
**Complexity:** Medium-High — requires architectural refactor with careful testing
**Impact:** Medium — technical debt; not user-facing but affects development velocity

## Low

### 12. Error Suppression in FsLister
**Title:** `@` operator in FsLister::lister() hides legitimate errors
**Description:** Error suppression hides permission denied, I/O errors, broken symlinks.
**Why it matters:** Silent failures make debugging difficult; users don't know why a directory appears empty.
**Source:** `docs/repo_map/sugarcraft_super-candy.md:353`
**Implementation ideas:**
- Remove `@` suppression
- Let `Entry::forPath()` return `Entry` with `isError: true` flag for inaccessible entries
- Renderer shows error entries differently (e.g., red text, "PERMISSION DENIED")
**Complexity:** Low — remove `@`, add error entry type
**Impact:** Low — diagnostics improvement

---

# Algorithm / Performance Opportunities

## 1. Cell-Based Delta Rendering vs Full Redraw

**Current approach:** `Renderer::render()` produces a complete string each frame. Any change redraws everything.

**External approach (charmbracelet/bubbletea v2 Cursed Renderer):**
- Cell-based buffer tracking
- Delta rendering — only changed cells sent to terminal
- Synchronized output mode (ANSI 2026) for atomic updates
- Debounced resize handling

**Why external is better:**
- Massive reduction in terminal I/O for large listings
- Flicker-free on supported terminals
- Over SSH, monetarily quantifiable bandwidth savings
- Critical for 60 FPS smoothness

**Tradeoffs:**
- Requires tracking per-cell state (char, fg, bg, modifiers)
- More complex renderer implementation
- Must handle terminal capability detection for graceful degradation

**Applicability to super-candy:** HIGH — candy's existing cell-buffer concept in `candy-core` View could be extended. super-candy's `Renderer` is already pure (no side effects), making delta computation feasible.

**Implementation path:**
1. Cache previous `Buffer` (cell grid) in `Manager` state
2. `render()` returns `Buffer` instead of `string`
3. Diff previous and current buffer to compute `BufferUpdates`
4. Only apply changes to terminal

## 2. Concurrent Directory Scanning

**Current approach (FsLister):** Synchronous `scandir()` + `lstat()` per entry sequentially.

**External approach (yazi, treilik/bubblelister):**
- yazi: async/await with multi-threaded I/O
- bubblelister: parallel `GetIndex` using goroutines per item

**Why external is better:**
- On large directories (100k+ files), synchronous listing blocks the event loop
- Concurrent stat calls overlap I/O wait times

**Tradeoffs:**
- ReactPHP doesn't have true threads — coroutines still sequential on I/O
- For directory listing, the bottleneck is syscalls, not CPU
- Concurrent scanning benefit only for network filesystems (NFS, FUSE)

**Applicability:** MEDIUM — most local SSDs won't benefit significantly. Could help on network mounts.

## 3. Search Algorithm — Substring vs Fuzzy

**Current:** Case-insensitive `str_contains()` substring match on entry names.

**External (superfile via fzf):** Fuzzy matching with character highlighting and scoring.

**Applicability:** MEDIUM — substring is simpler and sufficient for most cases. Fuzzy adds meaningful UX improvement.

## 4. Async File Operation Progress Tracking

**Current:** `AsyncOps` uses `React\Promise\Deferred` + `futureTick`. No progress callbacks.

**External (yazi):** Real-time progress updates as bytes copied, with cancellation support.

**Why external is better:**
- User sees progress during large copies
- Can cancel in-progress operations
- Better perceived responsiveness

**Tradeoffs:**
- PHP filesystem APIs don't support progress callbacks natively
- Would need to read in chunks and emit progress events manually
- More complex than promise-based completion notification

**Applicability:** HIGH — important UX gap

---

# Architecture Improvements

## 1. Extract Search State into SearchManager

Currently embedded in `Manager`:

```php
// Current: mixed into Manager
private readonly ?string $searchQuery;
private readonly array $searchResults;
private readonly int $searchCursor;

// Extract to:
final class SearchState
{
    public function __construct(
        public readonly ?string $query,
        public readonly array $results,  // list<Entry>
        public readonly int $cursor,
        public readonly bool $isActive,
    ) {}

    public function withQuery(string $q): self;
    public function withResults(list<Entry> $results): self;
    public function moveCursor(int $delta): self;
    public function activate(): self;
    public function deactivate(): self;
}
```

**Benefit:** Search logic isolated, testable, composable.

## 2. Extract Tab Management into TabManager

```php
final class TabManager
{
    public function __construct(
        public readonly int $activeIndex,
        public readonly array $tabs,  // list<TabState>
    ) {}

    public function addTab(TabState $tab): self;
    public function removeTab(int $index): self;
    public function switchTo(int $index): self;
    public function activeTab(): TabState;
}

final class TabState
{
    public function __construct(
        public readonly Pane $left,
        public readonly Pane $right,
        public readonly int $activePaneIndex,  // 0=left, 1=right
    ) {}
}
```

**Benefit:** Tab operations isolated; each `TabState` is immutable.

## 3. Extract Undo/Redo Stack Manager

```php
final class UndoRedoManager
{
    public function __construct(
        public readonly array $undoStack,  // list<UndoAction>
        public readonly array $redoStack,
    ) {}

    public function push(UndoAction $action): self;
    public function pop(): array{0: ?UndoAction, 1: self};
    public function redo(): array{0: ?UndoAction, 1: self};
    public function clear(): self;
}

// UndoAction gets enum property instead of string detection
enum UndoActionType { Delete, Move, Rename, Copy, Mkdir }

final class UndoAction
{
    public function __construct(
        public readonly UndoActionType $type,  // ADD THIS
        public readonly string $description,
        public readonly array $items,
    ) {}
}
```

**Benefit:** Fixes fragile string-based detection; isolated and testable.

## 4. Reduce Manager Constructor to Dependency Injection

```php
// Current: 15 parameters
public function __construct(
    Pane $left, Pane $right, int $activeIdx,
    string $status, ConfirmState $confirm,
    \Closure $lister,
    ?string $searchQuery, array $searchResults, int $searchCursor,
    array $tabs, int $tabIndex, bool $showTabBar,
    array $undoStack, array $redoStack,
    ?string $pendingOpDest, ?string $pendingOpType,
)

// Proposed: Builder pattern
public static function builder(\Closure $lister): self
{
    return new self($lister);
}

private function __construct(\Closure $lister)
{
    $this->lister = $lister;
    // ... init all state to defaults via setters
}
```

---

# API / Developer Experience Improvements

## 1. Expose File Type Detection

Currently hidden in `PreviewPane::isImage()`. Expose as `FileTypeDetector` utility:

```php
final class FileType
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const PDF = 'pdf';
    public const AUDIO = 'audio';
    public const CODE = 'code';
    public const TEXT = 'text';
    public const BINARY = 'binary';
}

final class FileTypeDetector
{
    public function detect(string $path): FileType;
    public function mimeType(string $path): ?string;
}
```

## 2. Progress Callback for AsyncOps

```php
public function copyAsync(
    string $src,
    string $dst,
    ?\Closure $onProgress = null,  // fn(int $bytesCopied, int $totalBytes): void
): \React\Promise\PromiseInterface;
```

## 3. Plugin Interface for Preview/Operations

```php
interface PreviewPlugin
{
    public function supports(Entry $entry): bool;
    public function preview(Entry $entry, int $width, int $height): string;
}

interface OperationPlugin
{
    public function name(): string;  // 'git-status', 'disk-usage', etc.
    public function execute(array $entries, array $args): mixed;
}
```

## 4. Configuration Object

Replace global constants with configuration:

```php
final class Config
{
    public function __construct(
        public readonly bool $showHidden = true,
        public readonly bool $vimMode = false,
        public readonly string $sortOrder = 'name-asc',
        public readonly bool $confirmDelete = true,
        public readonly int $undoStackSize = 100,
    ) {}
}

$manager = Manager::create($lister, Config::fromFile('~/.supercandy.json'));
```

---

# Documentation / Cookbook Opportunities

1. **"Building a TUI File Manager"** — Full architectural walkthrough of super-candy (like `charmbracelet/glow` docs)
2. **"Writing a Preview Plugin"** — Step-by-step adding PDF preview
3. **"Customizing Keybindings"** — How to add vim-mode or custom hotkeys
4. **"Internationalization Guide"** — Adding new language translations
5. **"Performance Tuning"** — When to use async ops, how to profile rendering
6. **"Testing TUIs with Snapshot Tests"** — Using Manager's pure transitions for deterministic tests

---

# UX / TUI Improvements

## 1. Status Line Improvements

Current: Shows operation results, errors, key help. Limited to one line.

**Improvements:**
- **Two-line status:** First line for content, second for key hints/contextual help
- **Progress on status line:** During copy/move, show `[████░░░░░░] 45% — 12.3MB / 27.1MB`
- **Error summary:** After batch delete, show `Deleted 5 files (2.1MB freed)`

## 2. Help System

Current: Single help line at bottom of screen.

**Improvements (per `charmbracelet/glow`):**
- `?` key shows full-screen help overlay
- Contextual help per mode (navigation, search, selection, tabs)
- Fuzzy help search: type `/` in help to filter commands
- Categorized help: Navigation | Selection | File Operations | View | Tabs

## 3. Mouse Support

Currently minimal or absent.

**Improvements:**
- Click to select file
- Double-click to open directory
- Click pane header to switch active pane
- Right-click context menu (copy, move, rename, delete)
- Mouse wheel to scroll

**Reference:** `charmbracelet/bubbletea` mouse events, `treilik/bubblelister` bubblezone integration

## 4. Command Palette

**Missing feature:** superfile has quick-command access. Not present in super-candy.

**Implementation ideas:**
- `:` key opens command palette (similar to `charmbracelet/huh` or `textualize/textual` command palette)
- Commands: `goto <path>`, `filter <pattern>`, `sort <name|mtime|size>`, `set <option>`
- Fuzzy completion as user types

---

# Testing / Reliability Improvements

## 1. Snapshot Testing for Renderer

Currently `RendererTest` checks for expected markers. Add comprehensive snapshot tests:

```php
public function testRenderPaneWithSelection(): void
{
    $manager = $this->buildManagerWithState([...]);
    $output = Renderer::render($manager);

    $this->assertEquals(
        file_get_contents(__DIR__ . '/snapshots/selected-files.txt'),
        $output
    );
}
```

## 2. Property-Based Testing for Sort

Use `php-diegendoc/faker` or custom generators to test sort stability:

```php
public function testSortStabilityWithRandomEntries(): void
{
    for ($i = 0; $i < 100; $i++) {
        $entries = Entry::generateRandom(50);
        $original = $entries;
        $sorted = Sort::mtimeDesc()->apply($entries);
        // Verify dirs-first invariant
        $this->assertTrue($this->dirsAlwaysBeforeFiles($sorted));
    }
}
```

## 3. Concurrent AsyncOps Testing

Test that `copyManyAsync` and `moveManyAsync` work correctly under concurrent load:

```php
public function testConcurrentCopyStress(): void
{
    $ops = [];
    for ($i = 0; $i < 50; $i++) {
        $ops[] = $this->asyncOps->copyAsync("src/file$i.txt", "dst/file$i.txt");
    }

    $results = \React\Promise\Utils::unwrapAll($ops);
    // All promises should resolve, none reject
}
```

## 4. Undo Action Reversal Property Tests

```php
public function testUndoReversesAllDeleteActions(): void
{
    // Create file, delete it, undo — file should reappear
    $file = $this->createTempFile();
    $manager = $this->buildManager()->delete($file)->confirm();
    $this->manager = $manager->undo();

    $this->assertTrue(file_exists($file));
}
```

---

# Ecosystem / Integration Opportunities

## 1. Git Integration

**Gap:** super-candy shows no git status. superfile shows modified/added files.

**Implementation:**
- Use `candy-pty` to spawn `git status --porcelain`
- Parse output and annotate entries with status marker (`M`, `A`, `D`, `??`)
- Color code: green for added, red for modified, cyan for untracked

## 2. SSH/SFTP Remote Navigation

**Gap:** super-candy only works on local filesystem.

**Implementation:**
- Use `phpseclib/phpseclib` for SSH connections
- Mount remote as virtual filesystem via `sshfs` (shell dependency)
- Or implement `RemoteFsLister` interface for remote directory listings

## 3. trash-cli Integration

**Gap:** `d` permanently deletes files. Some users prefer trash.

**Implementation:**
- Detect `trash-cli` availability
- Add `trash()` operation alongside `delete()`
- Keybinding: `D` (shift+d) for trash, `d` for permanent delete
- Configuration option to make `d` use trash instead

## 4. Archives as Virtual Directories

**Gap:** Cannot navigate into `.zip`, `.tar.gz` without extracting.

**Implementation:**
- Register archive handler plugin
- `.zip`: use `ZipArchive`
- `.tar.gz`: use `PharData`
- Treat archive contents as virtual listing; extract on copy-out

## 5. Media Server Integration

**Gap:** No way to browse DLNA/UPnP media servers.

**Implementation:**
- Use `clue/xml-react` to parse SSDP announcements
- Browse media servers via `sugar-bits` tree component
- Preview images via Mosaic

---

# Notable PRs / Issues / Discussions

## From charmbracelet/bubbletea (PR analysis report)

### Issue #1655: DevTools Inspector Proposal
A community proposal for F12-toggleable inspector showing message log, state dump, component tree.

**Lesson for super-candy:** Consider building introspection into Manager from day one. A `Manager::debug()` method returning structured state would enable future DevTools.

### Issue #1654: Testing Framework Proposal
Simulator-based testing with `SendKey()`, `Type()`, `Resize()` and golden snapshot testing.

**Lesson:** Testing infrastructure is a known gap in TUI ecosystems. super-candy should have headless test mode: `Manager::create()->withInput($keys)->run()` returning final state.

### Issue #1673: Signal Channel Leak
`signal.Notify(c, ...)` without `signal.Stop(c)` on exit causes channel leak.

**Direct risk to super-candy:** YES — if any signal handling exists in candy-core/pty. Must ensure all signal handlers are deregistered.

### Issue #1690: Data Race Between Mouse Events and Cursed Renderer
`lastView` read without mutex in `onMouse()` while written in `flush()`.

**Lesson:** Any state shared between input handlers and render loop must be mutex-protected. In PHP this manifests differently (single-threaded), but ReactPHP async operations can create similar races.

### Issue #958: Sequence/Empty Commands
`tea.Sequence` causes infinite loop with 100% CPU when commands return nil.

**Lesson for SugarCraft:** Both `Batch` and `Sequence` helpers must handle edge cases: `[]`, `[null]`, `[null, null]`, `[cmd, null, cmd]`.

### PR #1500: Declarative View API (v2)
v2 changed from imperative commands to declarative View struct fields.

**Lesson:** Declarative view is superior. sugar-candy's `View` properties should be the single source of truth for terminal state, never imperative commands for visual properties.

### Issue #1522: Nested Model Message Routing
Switch statements with single case arguments fail to match when >12-15 sub-models involved.

**Lesson:** SugarCraft's `Manager::dispatch()` match statement should use multi-case fallthrough pattern to avoid similar issues.

## From yorukot/superfile

### Superfile Plugin Architecture
superfile supports external tool integration via plugin interface.

**Lesson:** Plugin system is the most-requested feature for superfile. super-candy should implement a simple PluginInterface.

### Superfile Auto-Update
superfile checks GitHub releases on startup, notifies if update available.

**Lesson:** Low complexity addition — one HTTP call comparing versions.

### Superfile fzf Search
superfile uses fzf for fuzzy filtering with scoring and highlighting.

**Lesson:** super-candy's substring search is a known limitation. Either port fzf or integrate a PHP fuzzy library.

---

# Recommended Roadmap

## Immediate Wins (1-2 days each)

1. **Fix fragile undo action detection** — Add `UndoActionType` enum, replace `str_starts_with()` with property check
2. **Remove error suppression in FsLister** — Add error entries to listing with visible error marker
3. **Extract renderer helper methods** — Break 200+ char ternaries into named helpers
4. **Add `Manager::builder()`** — Reduce constructor parameter burden

## Medium-Term (1-2 weeks each)

5. **Extract SearchState class** — Isolate search logic from Manager
6. **Extract TabManager class** — Isolate tab management from Manager
7. **Extract UndoRedoManager class** — Isolate undo/redo from Manager
8. **Add progress callbacks to AsyncOps** — Show per-file progress for batch operations
9. **Add fuzzy search option** — Integrate PHP fuzzy matching library
10. **Add vim-mode hotkeys** — Mode-based vi keybindings
11. **Add multi-column metadata view** — Date, size, permissions as toggleable columns

## Major Upgrades (2-4 weeks each)

12. **Plugin/Extension system** — Define PluginInterface with preview and operation hooks
13. **Cell-based delta rendering** — Cache buffer, diff, only update changed cells
14. **Mouse support** — Click selection, double-click open, context menu
15. **Git integration** — Annotate files with git status
16. **PDF/Video preview** — Via external tool plugins

## Experimental (Future)

17. **trash-cli integration** — Optional trash instead of permanent delete
18. **SSH/SFTP remote navigation** — Browse remote filesystems
19. **Archive as virtual directories** — Navigate .zip/.tar.gz without extracting
20. **Auto-update mechanism** — GitHub release checker
21. **Web DevTools inspector** — Unix socket + web UI for introspection

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|------------|--------|------------|------|----------|
| Fix fragile undo action detection | High | Low | Low | **CRITICAL** |
| Add progress callbacks to AsyncOps | High | Medium | Low | **CRITICAL** |
| Remove error suppression in FsLister | High | Low | Low | **HIGH** |
| Extract SearchState class | Medium | Medium | Low | **HIGH** |
| Add fuzzy search option | Medium | Medium | Low | **HIGH** |
| Add vim-mode hotkeys | Medium | Medium | Low | **HIGH** |
| Multi-column metadata view | Medium | Medium | Medium | **HIGH** |
| Extract TabManager class | Medium | Medium | Low | **MEDIUM** |
| Extract UndoRedoManager class | Medium | Medium | Low | **MEDIUM** |
| Add `Manager::builder()` | Medium | Low | Low | **MEDIUM** |
| Refactor renderer helpers | Low | Low | Low | **MEDIUM** |
| Plugin/Extension system | High | High | High | **MEDIUM** |
| Cell-based delta rendering | High | High | Medium | **LOW** |
| Mouse support | Medium | Medium | Medium | **LOW** |
| Git integration | Medium | Medium | Medium | **LOW** |
| PDF/Video preview | Medium | High | High | **LOW** |
| trash-cli integration | Low | Low | Low | **LOW** |
| SSH/SFTP remote | Low | High | High | **LOW** |
| Auto-update mechanism | Low | Low | Low | **LOW** |
| Web DevTools inspector | Low | High | Medium | **LOW** |

---

# Final Strategic Assessment

super-candy is the most mature TUI application in the SugarCraft ecosystem, demonstrating full integration of TEA model, styling, image preview, and async operations. Its solid foundation — immutable state, testable architecture, confirmation gates, and i18n — provides a strong base for incremental improvement.

**Critical priorities:**
1. Fix the fragile undo action detection (data safety)
2. Add progress feedback for async operations (critical UX gap vs. yazi/ranger)
3. Extract SearchState and TabManager from the God class Manager (technical debt)

**Key differentiators to maintain:**
- Pure immutable state (vs. superfile's mutable Go structs)
- Closure injection for filesystem reads (enables unit testing without tmpfs)
- ReactPHP async (vs. synchronous Go goroutines)
- Confirmation gate pattern (prevents accidental data loss)

**Competitive gap analysis:**
- vs. superfile: Missing plugin system, fuzzy search, auto-update, vim-mode
- vs. yazi: Missing progress bars, async/await architecture, Lua plugin system
- vs. ranger: Missing scope.sh preview plugins, rifle launcher, code highlighting

**The most impactful single change** would be adding progress callbacks to AsyncOps combined with status line progress display. This directly addresses the most visible user-facing gap vs. modern file managers. Combined with fixing the fragile undo detection (which is a data safety issue), these two changes would significantly improve reliability and user experience.

**Architecture direction:** The path toward a maintainable, extensible file manager goes through extracting the God class into focused, single-responsibility components. This is a well-understood refactor with clear precedent from how `Pane`, `Entry`, and `Sort` already demonstrate the pattern. The `Manager` should become a thin coordinator, not the vessel of all logic.
