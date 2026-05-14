# Candy-Hermit Library Research Plan

**Library:** `candy-hermit` (PHP port of Genekkion/theHermit)
**Date:** 2026-05-13
**Researcher:** Research Agent
**Output:** `/home/sites/sugarcraft/.tmp/research/libraries/candy-hermit-research.md`

---

## Executive Summary

Candy-hermit is a well-implemented PHP port of the theHermit Go library, correctly capturing the core overlay/quick-fix pattern. However, the current implementation has significant gaps compared to modern shell/TUI libraries in Go (bubbletea/bubbles), Rust (Helix), and Fish Shell. This research identifies **6 high-priority improvements** with effort estimates.

---

## 1. Current Implementation Analysis

### 1.1 Source Files
- `/home/sites/sugarcraft/candy-hermit/src/Hermit.php` — Main overlay renderer (430 lines)
- `/home/sites/sugarcraft/candy-hermit/src/Model.php` — Bubble-Tea-style model interface (31 lines)

### 1.2 What Works Well
- ✅ Immutable/fluent pattern with `with*()` methods
- ✅ Proper `declare(strict_types=1)` compliance
- ✅ Background view compositing (chars replaced, not obliterated)
- ✅ Fuzzy filtering with anchor bias (match must appear in first half)
- ✅ ANSI match highlighting
- ✅ Complete cursor navigation (`cursorUp`, `cursorDown`, `cursorTop`, `cursorBottom`)
- ✅ Backspace/clear filter support
- ✅ Bubble-Tea `Model` interface pattern

### 1.3 Gaps vs Upstream theHermit

| Feature | Upstream (Go) | Candy-Hermit (PHP) | Status |
|---------|---------------|-------------------|--------|
| Tea.Model integration | ✅ Full BubbleTea | ✅ Interface only | ⚠️ Partial |
| Item interface | ✅ `Item` interface with `Title()` | ❌ Plain strings | 🔴 Missing |
| Border styling | ✅ Lipgloss borders | ❌ Plain ASCII | 🔴 Missing |
| Numbered items | ✅ Yes | ❌ No | 🔴 Missing |
| Window auto-resize | ✅ `tea.WindowSizeMsg` | ❌ Fixed dimensions | 🔴 Missing |
| Fuzzy match highlighting | ❌ Basic | ✅ ANSI SGR codes | ✅ Better |
| Filter algorithm | ❌ Simple substring | ✅ Anchor-biased fuzzy | ✅ Better |
| Background compositing | ✅ Yes | ✅ Yes | ✅ Equal |

### 1.4 The Hermit `Model` Struct (Go upstream for reference)

```go
// Source: Genekkion/theHermit/list/model.go:L1-L35
type Model struct {
    isShown bool
    height  int
    width   int
    maxHeight int
    maxWidth  int
    windowHeight int
    windowWidth  int
    leftPadding  int
    title        string
    isNumbered   bool
    cursor       int
    offset       int
    items        []Item
    view         string
    // Styles (lipgloss)
    borderStyle   lipgloss.Style
    titleStyle    lipgloss.Style
    selectedStyle lipgloss.Style
    itemStyle     lipgloss.Style
    border        lipgloss.Border
}
```

---

## 2. Shell Library Pattern Research

### 2.1 Go Libraries

#### 2.1.1 charmbracelet/bubbles List Component
**Source:** https://github.com/charmbracelet/bubbles/blob/master/list/list.go

The `bubbles/list` component provides the canonical reference for TUI list components:

```go
// Key features from bubbles/list
type Model struct {
    showTitle        bool
    showFilter       bool
    showStatusBar    bool
    showPagination   bool
    showHelp         bool
    filteringEnabled bool
    itemNameSingular string
    itemNamePlural   string
    Title            string
    Styles           Styles
    KeyMap           KeyMap
    Filter           FilterFunc  // Function: func(term string, targets []string) []Rank
    spinner          spinner.Model
    width            int
    height           int
    Paginator        paginator.Model
    cursor           int
    Help             help.Model
    FilterInput      textinput.Model
    filterState      FilterState
    StatusMessageLifetime time.Duration
    statusMessage    string
}
```

**Key Patterns:**
- **Filter function injection** — `Filter func(term string, targets []string) []Rank` allows custom filtering algorithms
- **DefaultFilter uses `sahilm/fuzzy`** — Real fuzzy matching with rank scoring
- **Paginator integration** — Handles large lists with pagination
- **KeyMap for key bindings** — Configurable keyboard shortcuts
- **Styles struct** — All styling encapsulated in a `Styles` type
- **Help model** — Built-in help display

#### 2.1.2 reeflective/readline History Management
**Source:** https://github.com/reeflective/readline/blob/master/history.go

The readline library provides comprehensive history management patterns:

```go
// History interface for pluggable history sources
type History = history.Source

// NewHistoryFromFile creates file-based history
var NewHistoryFromFile = history.NewSourceFromFile

// History operations
func (rl *Shell) incrementalForwardSearchHistory()
func (rl *Shell) incrementalBackwardSearchHistory()
func (rl *Shell) historyCompletion(forward, filter, regexp bool)
func (rl *Shell) inferNextHistory()  // Auto-suggest from history
func (rl *Shell) saveLine()          // Save without executing
func (rl *Shell) historySourceNext() // Cycle between history sources
```

**Key Patterns:**
- **Multiple history sources** — Can bind multiple history providers
- **Incremental search** — Real-time filtering as you type
- **History inference** — Suggest next command based on context
- **Undo/redo for history** — `History.Undo()` / `History.Redo()`

#### 2.1.3 chzyer/readline File-based History
**Source:** https://github.com/chzyer/readline/blob/master/history.go

```go
// Persistent history with file backend
type opHistory struct {
    cfg     *Config
    history *list.List  // Linked list for efficient insertions
    fd      *os.File    // History file handle
    enable  bool        // Can be disabled at runtime
}

func (o *opHistory) Init() {
    if o.cfg.HistoryFile != "" {
        o.historyUpdatePath(o.cfg.HistoryFile)
    }
}

func (o *opHistory) Compact() {
    // Enforce HistoryLimit by removing oldest entries
    for o.history.Len() > o.cfg.HistoryLimit {
        o.history.Remove(o.history.Front())
    }
}
```

**Key Patterns:**
- **Append-only writes** — New entries appended, not rewritten
- **Compact on limit** — Rewrites file when exceeding limit
- **Tmp file + rename** — Atomic file updates (`Write -> Rename`)
- **Skipping duplicates** — Won't add if identical to previous

### 2.2 Rust/Helix Patterns

#### 2.2.1 Helix Editor Completion System
**Source:** https://docs.helix-editor.com/master/editor.html

```toml
# Configuration options from Helix
[editor]
auto-completion = true        # Auto popup
completion-trigger-len = 2    # Min chars to trigger
preview-completion-insert = true  # Apply on selection
path-completion = true        # File path completion

[editor.lsp]
display-messages = true
auto-signature-help = true
display-inlay-hints = false

[editor.word-completion]
enable = true
trigger-length = 7
```

**Key Patterns:**
- **Async completion** — Non-blocking UI during completion lookup
- **Trigger length config** — Don't start until N chars typed
- **Preview on select** — Show what selection would look like
- **LSP integration** — Language-aware completions via Language Server Protocol
- **Multiple completer sources** — Can chain completers (path, LSP, words)

#### 2.2.2 Helix Shell Command Completion
**Source:** https://github.com/helix-editor/helix/pull/12883

```rust
// Shell command completion strategy
// Problem: First arg should complete programs, rest are files
// Solution: Split arguments, use different completers per position
```

**Key Patterns:**
- **Position-aware completion** — First arg ≠ second arg
- **Program discovery** — Scan $PATH for executables
- **Async PATH scanning** — Don't block UI on slow filesystems
- **Cache directory mtime** — Only rescan directories when modified

### 2.3 Fish Shell Patterns

#### 2.3.1 Fish History Management
**Source:** https://fishshell.com/docs/current/cmds/history.html

```fish
# Session-based history (not global by default)
set -x fish_history "session_name"  # Per-session history
set -x fish_history ""              # Disable history
set -x fish_history "default"       # Shared history

# History search operations
history search --contains "git"
history delete --exact --case-sensitive "password"
history merge  # Import from other sessions
history clear  # Interactive clear
```

**Key Patterns:**
- **Session isolation** — Can have private sessions (good for sudo)
- **XDG paths** — `$XDG_DATA_HOME/fish/fish_history`
- **History merging** — Real-time sync across sessions
- **Search with --contains** — Substring matching

#### 2.3.2 Fish Completion Architecture
**Source:** https://fishshell.com/docs/current/completions.html

```fish
# Completion specification
complete -c myprog -s o --long output -f -a "one two three"

# Autoload from $fish_complete_path
# Files named after command: myprog.fish

# Useful functions
__fish_print_filesystems     # List of known filesystems
__fish_complete_directories  # Directory completion
__fish_complete_path         # Path with description
```

**Key Patterns:**
- **Lazy loading** — Completions loaded on-demand
- **Per-command files** — `~/.config/fish/completions/cmake.fish`
- **Function-based generators** — Dynamic completion computation
- **Description support** — Tab shows "option: description"

---

## 3. candy-hermit Improvement Recommendations

### 3.1 Priority 1: Item Interface + Numbered Items

**Problem:** Current implementation uses plain `string[]` for items. Upstream theHermit uses `Item` interface with `Title()` method, and numbered display.

**Source:** Genekkion/theHermit/list/item.go:L1-L6
```go
type Item interface {
    Title() string
}
```

**Source:** Genekkion/theHermit/list/views.go:L150-L170
```go
// Numbered item rendering
if model.isNumbered {
    itemText = fmt.Sprintf("%d. %s", index+1, (*item).Title())
    if lipgloss.Width(itemText) > model.width-2 {
        itemText = itemText[:model.width-2]
    }
    if index == model.cursor {
        itemText = model.selectedStyle.Render(itemText)
    }
}
```

**Recommendation:**
```php
// New src/Item.php interface
namespace SugarCraft\Hermit;

interface Item {
    public function title(): string;
}

// Hermit.php changes:
/** @var list<Item> */
private array $allItems = [];

// WithItems accepts Item[] or string[]
public function withItems(array $items): self {
    $clone = clone $this;
    $clone->allItems = \array_map(fn($i) => $i instanceof Item ? $i : new StringItem((string)$i), $items);
    // ...
}

// StringItem adapter for backward compat
final readonly class StringItem implements Item {
    public function __construct(private string $value) {}
    public function title(): string { return $this->value; }
}
```

**Effort:** 2-3 hours
**Files:** New `src/Item.php`, modify `src/Hermit.php`

---

### 3.2 Priority 2: Filter Function Injection

**Problem:** Filter algorithm is hardcoded. bubbles/list allows custom filter functions.

**Source:** charmbracelet/bubbles/list/list.go:L48-L58
```go
// Filter function signature
type FilterFunc func(term string, targets []string) []Rank

type Rank struct {
    Index          int
    MatchedIndexes []int
}

// DefaultFilter uses sahilm/fuzzy
func DefaultFilter(term string, targets []string) []Rank {
    ranks := fuzzy.Find(term, targets)
    // ...
}
```

**Recommendation:**
```php
// Hermit.php changes
/** @var callable(string $term, list<string> $targets): list<array{index:int, matchedIndexes:list<int>}> */
private callable $filterFunc;

public function setFilterFunc(callable $fn): self {
    $clone = clone $this;
    $clone->filterFunc = $fn;
    return $clone;
}

// Built-in filters
public static function fuzzyFilter(string $term, array $targets): array {
    // Use sahilm/fuzzy port or implement basic fuzzy
}

public static function substringFilter(string $term, array $targets): array {
    $lower = \strtolower($term);
    return \array_values(\array_filter(
        $targets,
        fn($t) => \strpos(\strtolower($t), $lower) !== false
    ));
}
```

**Effort:** 2 hours
**Files:** Modify `src/Hermit.php`

---

### 3.3 Priority 3: Persistent History Support

**Problem:** No history persistence. Shell libraries persist across sessions.

**Source:** chzyer/readline/history.go:L60-L90
```go
// Pattern: Open file append-only, compact on limit
func (o *opHistory) historyUpdatePath(path string) {
    f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_RDWR, 0666)
    r := bufio.NewReader(f)
    for {
        line, err := r.ReadString('\n')
        if err != nil { break }
        line = strings.TrimSpace(line)
        if len(line) == 0 { continue }
        o.Push([]rune(line))
        o.Compact()
    }
    if total > o.cfg.HistoryLimit {
        o.rewriteLocked()
    }
}
```

**Recommendation:**
```php
// New src/History.php
namespace SugarCraft\Hermit;

final class History {
    private const DEFAULT_LIMIT = 500;
    
    public function __construct(
        private string $filePath,
        private int $limit = self::DEFAULT_LIMIT,
    ) {}
    
    public function load(): void { /* Read from file */ }
    public function add(string $line): void { /* Append, compact if needed */ }
    public function search(string $prefix): list<string> { /* Prefix match */ }
    public function clear(): void { /* Truncate file */ }
}
```

**Effort:** 4-5 hours
**Files:** New `src/History.php`

---

### 3.4 Priority 4: Border/Style Struct

**Problem:** Current styling is ad-hoc via `matchStyle` string. Upstream uses structured styles.

**Source:** Genekkion/theHermit/list/model.go:L38-L48
```go
// Structured styles
borderStyle: lipgloss.NewStyle().
    Background(lipgloss.Color("#16161D")).
    BorderBackground(lipgloss.Color("#16161D")).
    Foreground(lipgloss.Color("#2D4F67")),
titleStyle: lipgloss.NewStyle().
    Foreground(lipgloss.Color("#7FB4CA")).
    Bold(true),
itemStyle: lipgloss.NewStyle().
    Width(width - 2).
    Background(lipgloss.Color("#16161D")).
    Foreground(lipgloss.Color("#DCD7BA")),
selectedStyle: lipgloss.NewStyle().
    Width(width - 2).
    Background(lipgloss.Color("#DCD7BA")).
    Foreground(lipgloss.Color("#1F1F28")).
    Bold(true),
border: lipgloss.NormalBorder(),
```

**Recommendation:**
```php
// New src/Styles.php
namespace SugarCraft\Hermit;

final readonly class Styles {
    public function __construct(
        public string $border = '─',
        public string $borderStyle = '',
        public string $titleStyle = '',
        public string $itemStyle = '',
        public string $selectedStyle = '',
        public string $matchStyle = '',
    ) {}
    
    public static function default(): self { /* ... */ }
    public static function tokyonight(): self { /* ... */ }
}
```

**Effort:** 2 hours
**Files:** New `src/Styles.php`

---

### 3.5 Priority 5: Window Auto-Resize

**Problem:** Fixed dimensions. Upstream responds to `tea.WindowSizeMsg`.

**Source:** Genekkion/theHermit/list/model.go:L85-L100
```go
case tea.WindowSizeMsg:
    if message.Height >= model.height {
        model.height = min(model.maxHeight, message.Height)
    }
    if message.Width >= model.width {
        model.width = min(model.maxWidth, message.Width)
    }
    model.leftPadding = (message.Width - model.width) / 2
    model.windowHeight = message.Height
    model.windowWidth = message.Width
```

**Recommendation:**
```php
// Add to Hermit.php
public const DEFAULT_WIDTH = 80;
public const DEFAULT_HEIGHT = 14;
public const MAX_WIDTH = 120;
public const MAX_HEIGHT = 40;

/** @var int */
private int $windowHeight = self::DEFAULT_HEIGHT;
/** @var int */
private int $windowWidth = self::DEFAULT_WIDTH;
/** @var int */
private int $maxHeight = self::MAX_HEIGHT;
/** @var int */
private int $maxWidth = self::MAX_WIDTH;

public function setWindowSize(int $width, int $height): self {
    $clone = clone $this;
    $clone->windowWidth = \min($width, $clone->maxWidth);
    $clone->windowHeight = \min($height, $clone->maxHeight);
    return $clone;
}

public function updateFromTerminalSize(int $termWidth, int $termHeight): self {
    $clone = clone $this;
    $clone->windowWidth = \min(\max($termWidth, $clone->width), $clone->maxWidth);
    $clone->windowHeight = \min(\max($termHeight, $clone->height), $clone->maxHeight);
    $clone->leftPadding = (int)(($termWidth - $clone->width) / 2);
    return $clone;
}
```

**Effort:** 2 hours
**Files:** Modify `src/Hermit.php`

---

### 3.6 Priority 6: Help/Status Bar

**Problem:** No built-in help display. bubbles/list has optional help.

**Source:** charmbracelet/bubbles/list/list.go
```go
type Model struct {
    showHelp bool
    showStatusBar bool
    Help help.Model
    statusMessage string
    StatusMessageLifetime time.Duration
}

type KeyMap struct {
    // Navigation
    CursorUp     key.Binding
    CursorDown   key.Binding
    // Filtering
    Filter       key.Binding
    ClearFilter  key.Binding
    // Selection
    Select       key.Binding
    // Navigation
    NextPage     key.Binding
    PrevPage     key.Binding
    GoToStart    key.Binding
    GoToEnd      key.Binding
}
```

**Recommendation:**
```php
// New src/HelpBar.php
namespace SugarCraft\Hermit;

final readonly class HelpBar {
    /** @var list<array{keys: string, description: string}> */
    private array $bindings = [];
    
    public function __construct(
        private string $keyUp = '↑',
        private string $keyDown = '↓',
        private string $keyEnter = '↵',
        private string $keyEsc = 'esc',
        private string $keyDel = '⌫',
    ) {}
    
    public function render(): string { /* ... */ }
}

// Add to Hermit
public function withHelpBar(HelpBar $help): self { /* ... */ }
public function setStatusMessage(string $msg, int $ttlSeconds = 3): self { /* ... */ }
```

**Effort:** 3-4 hours
**Files:** New `src/HelpBar.php`, modify `src/Hermit.php`

---

## 4. Implementation Priority Matrix

| Priority | Feature | Effort | Impact | Files |
|----------|---------|--------|--------|-------|
| 1 | Item Interface + Numbered | 2-3h | High | New `Item.php` |
| 2 | Filter Function Injection | 2h | High | Hermit.php |
| 3 | Persistent History | 4-5h | Medium | New `History.php` |
| 4 | Border/Style Struct | 2h | Medium | New `Styles.php` |
| 5 | Window Auto-Resize | 2h | Medium | Hermit.php |
| 6 | Help/Status Bar | 3-4h | Low | New `HelpBar.php` |

**Total Estimated Effort:** 15-18 hours

---

## 5. Files to Create/Modify

### 5.1 New Files
```
candy-hermit/src/Item.php          # Item interface
candy-hermit/src/StringItem.php    # String adapter
candy-hermit/src/Styles.php        # Style struct
candy-hermit/src/History.php       # Persistent history
candy-hermit/src/HelpBar.php       # Help display
candy-hermit/src/KeyMap.php        # Key binding config
```

### 5.2 Files to Modify
```
candy-hermit/src/Hermit.php        # Add features 1,2,4,5
candy-hermit/src/Model.php         # May need updates
candy-hermit/composer.json         # Add dependencies if needed
candy-hermit/phpunit.xml           # Update if new classes
candy-hermit/tests/HermitTest.php  # Add tests for new features
```

---

## 6. Dependencies

### Recommended for Future
- `sahilm/fuzzy` PHP port or native implementation for better fuzzy matching
- `brick/font` or similar for box-drawing characters
- Consider `symfony/styler` for style composition

### Current
- Only `php: ^8.3` required (good!)

---

## 7. References

### Upstream
- **Genekkion/theHermit:** https://github.com/Genekkion/theHermit
  - `list/model.go` — Main model structure
  - `list/item.go` — Item interface
  - `list/views.go` — Rendering logic
  - `list/misc.go` — Getters/setters

### Go Libraries
- **charmbracelet/bubbles:** https://github.com/charmbracelet/bubbles
  - `list/list.go` — Reference implementation with filter injection
  - Uses `sahilm/fuzzy` for fuzzy ranking

- **reeflective/readline:** https://github.com/reeflective/readline
  - `history.go` — History management with multiple sources
  - Supports `.inputrc` configuration

- **chzyer/readline:** https://github.com/chzyer/readline
  - `history.go` — File-based persistence pattern
  - Well-documented history file format

### Rust Libraries
- **Helix Editor:** https://github.com/helix-editor/helix
  - `book/src/editor.md` — Editor configuration reference
  - `book/src/languages.md` — LSP completion patterns
  - PR #12883 — Shell command completion

### Fish Shell
- **fish-shell:** https://github.com/fish-shell/fish-shell
  - `completions/` — Completion file format
  - `history.rs` — History management
  - `reader.rs` — Line editing

---

## 8. Conclusion

Candy-hermit is a solid initial port that correctly captures the overlay compositing pattern from the upstream Go library. However, to reach feature parity with modern TUI shell libraries and provide a more complete developer experience, the following are recommended:

1. **Item Interface** — Enable structured items with metadata (priority 1)
2. **Filter Injection** — Allow custom filtering algorithms (priority 2)
3. **History** — Add persistent command history (priority 3)
4. **Style Struct** — Structured styling like upstream (priority 4)
5. **Auto-resize** — Respond to terminal size changes (priority 5)
6. **Help Bar** — Built-in keyboard shortcuts display (priority 6)

The immutability and fluent interface patterns already in place make these additions straightforward. The codebase demonstrates good PHP 8.3+ practices throughout.

---

*Research compiled by Research Agent — 2026-05-13*