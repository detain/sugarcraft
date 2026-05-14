# Sugar-Readline Library Research

## Executive Summary

This research analyzes terminal readline/input history libraries across Go, Rust, Python, and Node.js to identify patterns and features that could improve `sugar-readline` (PHP 8.3+). The current implementation provides solid text input with completions but lacks history persistence, vi mode, syntax highlighting, and advanced auto-suggest features found in mature libraries.

---

## 1. Current Implementation Analysis

**Source:** `/home/sites/sugarcraft/sugar-readline/src/`

### What's Implemented

| Feature | Status | Notes |
|---------|--------|-------|
| Text input with cursor | ✅ | Immutable state machine |
| Auto-completion | ✅ | `withCompletions()` + Tab |
| Hidden/password mode | ✅ | `withHidden()` |
| Validation | ✅ | `withValidator()` |
| Multi-line textarea | ✅ | `TextareaPrompt` |
| Selection prompts | ✅ | `SelectionPrompt`, `MultiSelectPrompt` |
| Confirmation prompts | ✅ | `ConfirmationPrompt` |
| ANSI styling | ✅ | `Ansi::wrap()` |
| Key handling | ✅ | `Key` constants (Left, Right, etc.) |

### What's Missing

| Feature | Priority | Impact |
|---------|----------|--------|
| History persistence | **HIGH** | User experience |
| History navigation (↑/↓) | **HIGH** | Core readline feature |
| Vi mode | MEDIUM | Power user expectation |
| Emacs mode | MEDIUM | Default for many |
| Auto-suggest from history | MEDIUM | UX enhancement |
| Syntax highlighting | LOW | Specific use cases |
| Undo/redo | MEDIUM | Common expectation |

---

## 2. Library Comparison

### 2.1 History Persistence

| Library | Approach | Deduplication | Session Merge |
|---------|----------|---------------|---------------|
| **prompt_toolkit** (Python) | `FileHistory` class | ✅ via session | ✅ via `history -n` pattern |
| **rustyline** (Rust) | `FileBackedHistory` + `History` trait | Configurable max size | Manual append |
| **liner** (Go) | `ReadHistory()`/`WriteHistory()` | ✅ last-write-wins | ✅ append-only |
| **chzyer/readline** (Go) | `HistoryFile` + auto-save | ✅ unique entries | ✅ per-session append |
| **reedline** (NuShell) | `FileBackedHistory` | ✅ via `SearchFilter` | ✅ with timestamps |
| **promptkit** (Go - upstream) | In-memory only (extension point) | N/A | N/A |

**Key Patterns:**

1. **Bash-style deduplication** (from Linux research):
   ```bash
   # On session close: deduplicate while preserving order
   history -n; history -w; history -c; history -r;
   ```

2. **Concurrent session handling** (Python prompt_toolkit):
   ```python
   def save(prev_h_len, histfile):
       new_h_len = readline.get_current_history_length()
       readline.append_history_file(new_h_len - prev_h_len, histfile)
   atexit.register(save, h_len, histfile)
   ```

3. **History interface abstraction** (chzyer/readline):
   ```go
   type History interface {
       Append(line string) error
       Get(index int) (string, bool)
       Len() int
   }
   ```

### 2.2 Auto-Completion Approaches

| Library | Static List | Dynamic/Async | Fuzzy Match | Two-tab behavior |
|---------|-------------|---------------|-------------|------------------|
| **prompt_toolkit** | `WordCompleter` | ✅ via custom `Completer` | ❌ (fzf-tab external) | List on 2nd tab |
| **rustyline** | `Completer` trait | ✅ via `Hint` | ❌ | TabCircular/TabPrints |
| **liner** (Go) | `SetCompleter()` | ✅ | ❌ | Cycles through |
| **chzyer/readline** | `PrefixCompleter` | ✅ | ❌ | Lists all |
| **Inquirer.js** | `source` callback | ✅ async with `AbortSignal` | ❌ | Debounced search |
| **promptkit** (Go) | `AutoCompleteFromSlice` | ✅ via function | ❌ | Single match |

**Sugar-Readline Current:** Single `withCompletions(array)` - matches promptkit's basic approach.

### 2.3 Vi/Emacs Mode

| Library | Vi Mode | Emacs Mode | Custom Bindings |
|---------|---------|------------|-----------------|
| **rustyline** | ✅ Full | ✅ Full | `Keybindings::add_binding` |
| **prompt_toolkit** | ✅ `vi_mode=True` | ✅ Default | ✅ via `KeyBindings` |
| **chzyer/readline** | ✅ `VimMode` config | ❌ | Limited |
| **liner** (Go) | ❌ | ✅ Basic | ❌ |
| **reedline** | ✅ Full with insert/normal | ✅ | ✅ |
| **promptkit** (Go) | ❌ | ❌ | ❌ |

**Key Bindings (rustyline Vi Mode):**

```
Vi Normal Mode:
- h/l: left/right
- w/b: word forward/back
- 0/$: line start/end
- i/a: insert mode
- Esc: normal mode
- /: search
- n/N: next/prev search match

Vi Insert Mode:
- Esc: normal mode
- Ctrl-O: single command then return
```

### 2.4 Syntax Highlighting

| Library | Approach | Pygments Integration |
|---------|----------|---------------------|
| **prompt_toolkit** | `PygmentsLexer` wrapper | ✅ Full |
| **rustyline** | `syntect` crate (external) | ❌ Native |
| **reedline** | `Highlighter` trait | ❌ Native |
| **chzyer/readline** | `Painter` interface | ❌ Custom |
| **liner** (Go) | ❌ | N/A |

**Reedline Highlighter Pattern:**
```rust
impl Highlighter for MyHighlighter {
    fn highlight(&self, line: &str, _cursor: usize) -> StyledText {
        let mut styled = StyledText::new();
        let style = if self.keywords.iter().any(|k| line.starts_with(k)) {
            Style::new().fg(Color::Green)
        } else {
            Style::new().fg(Color::Red)
        };
        styled.push((style, line.to_string()));
        styled
    }
}
```

### 2.5 Auto-Suggest from History

**prompt_toolkit** (most mature implementation):
```python
from prompt_toolkit import PromptSession
from prompt_toolkit.auto_suggest import AutoSuggestFromHistory

session = PromptSession(
    history=FileHistory("~/.myapp_history"),
    auto_suggest=AutoSuggestFromHistory()
)
# As user types "git ", shows "git commit", "git push" etc.
```

**liner (Go) - `PromptWithSuggestion`:**
```go
name, err := line.PromptWithSuggestion("Name: ", "default", 8)
```

---

## 3. Key Insights & Patterns

### 3.1 History Management Pattern

All mature libraries separate:
1. **History storage** (in-memory list, file-backed, database)
2. **History interface** (append, search, truncate)
3. **Persistence strategy** (on-exit append, periodic flush, manual save)

**Recommended Sugar-Readline Interface:**
```php
interface History {
    public function append(string $line): void;
    public function search(string $prefix): ?string;
    /** @return list<string> */
    public function all(): array;
    public function save(string $path): void;
    public static function load(string $path): self;
}
```

### 3.2 Session-Aware History Deduplication

From bash history research:
- **Problem:** Concurrent sessions create duplicate entries
- **Solution:** On session close, merge by:
  1. `history -n` (read new entries from file)
  2. `history -w` (write merged, deduplicated)
  3. `history -c` (clear memory)
  4. `history -r` (reload clean)

**PHP Implementation Pattern:**
```php
final class FileHistory implements History {
    public function __construct(
        private string $path,
        private int $maxSize = 500,
    ) {
        $this->load();
    }

    private function load(): void {
        if (file_exists($this->path)) {
            $lines = file($this->path, FILE_IGNORE_NEW_LINES);
            $this->entries = $lines ?: [];
            array_map($this->trim(...), $this->entries);
        }
    }

    public function save(): void {
        $content = implode("\n", $this->entries);
        file_put_contents($this->path, $content);
    }

    public function append(string $line): void {
        $line = trim($line);
        if ($line === '') return;

        // Deduplicate: remove if already exists
        $this->entries = array_filter(
            $this->entries,
            fn($e) => $e !== $line
        );

        $this->entries[] = $line;

        // Trim to max size
        if (count($this->entries) > $this->maxSize) {
            array_shift($this->entries);
        }
    }
}
```

### 3.3 Completion with Async Support

**Inquirer.js Pattern (Node.js):**
```typescript
const pkg = await search({
  source: async (term, { signal }) => {
    if (!term) return [];
    const res = await fetch(`api/search?q=${term}`, { signal });
    return res.json();
  }
});
```

**Sugar-Readline Enhancement:**
```php
final class AsyncCompleter {
    public function __construct(
        private callable $provider, // fn(string $input, AbortSignal $signal): array
        private int $debounceMs = 300,
    ) {}
}
```

### 3.4 Cursor Movement Strategy

All libraries use **UTF-8 aware cursor positioning**:

```php
// Current sugar-readline (already correct)
private static function charCount(string $s): int {
    return mb_strlen($s, 'UTF-8');
}

private static function sliceChars(string $s, int $start, ?int $length = null): string {
    return $length === null
        ? mb_substr($s, $start, null, 'UTF-8')
        : mb_substr($s, $start, $length, 'UTF-8');
}
```

---

## 4. Prioritized Recommendations

### 4.1 High Priority (Foundational)

| # | Feature | Description | Effort | Source |
|---|---------|-------------|--------|--------|
| 1 | **History Interface** | Abstract `History` interface with `FileHistory` implementation | 2 days | Based on rustyline, chzyer/readline |
| 2 | **History Navigation** | Add ↑/↓ keys to `TextPrompt` for history traversal | 1 day | All libraries |
| 3 | **History Persistence** | Auto-save on submit, load on init | 1 day | prompt_toolkit, liner |
| 4 | **Auto-Suggest from History** | Show matching history entry as ghost text | 2 days | prompt_toolkit pattern |

### 4.2 Medium Priority (Enhanced UX)

| # | Feature | Description | Effort | Source |
|---|---------|-------------|--------|--------|
| 5 | **Vi Mode** | Full vi keybindings for TextPrompt | 3 days | rustyline, reedline |
| 6 | **Emacs Mode** | Emacs keybindings (Ctrl-A/E/K/etc) | 2 days | rustyline |
| 7 | **Undo/Redo** | Ctrl-Z / Ctrl-Shift-Z | 2 days | chzyer/readline |
| 8 | **Word-based Movement** | Alt-Left/Right for word navigation | 1 day | rustyline |
| 9 | **Incremental Search** | Ctrl-R for reverse history search | 2 days | GNU readline |

### 4.3 Low Priority (Advanced)

| # | Feature | Description | Effort | Source |
|---|---------|-------------|--------|--------|
| 10 | **Syntax Highlighting** | Pluggable `Highlighter` interface | 3 days | reedline, prompt_toolkit |
| 11 | **Async Completions** | Debounced async completion provider | 2 days | Inquirer.js |
| 12 | **Multiple History Sources** | Cycle through history banks | 2 days | reeflective/readline |
| 13 | **History Timestamps** | Store/display when commands ran | 1 day | reedline, bash |

---

## 5. Implementation Roadmap

### Phase 1: History Foundation (Week 1)
```
Goal: Add persistent history to TextPrompt

1. Create History interface
2. Implement InMemoryHistory (default)
3. Implement FileHistory with deduplication
4. Add history navigation (↑/↓) to TextPrompt
5. Auto-save on submit, load on construct
```

**Deliverable:** `TextPrompt` with working history that persists across sessions.

### Phase 2: Key Binding Modes (Week 2)
```
Goal: Add vi/emacs mode switching

1. Create KeyBindings configuration class
2. Implement ViEditMode with insert/normal states
3. Implement EmacsEditMode with standard bindings
4. Add Mode switcher to TextPrompt
```

**Deliverable:** `TextPrompt::withEditMode(EditMode::vi())` etc.

### Phase 3: Auto-Suggest & Completion (Week 3)
```
Goal: Enhanced suggestion UX

1. Add AutoSuggestFromHistory
2. Add ghost text rendering to TextPrompt
3. Add async completer support with debounce
4. Add fuzzy completion option
```

### Phase 4: Advanced Features (Week 4+)
```
Goal: Match upstream feature parity

1. Undo/redo system
2. Syntax highlighting interface
3. Incremental search (Ctrl-R)
4. Multiple history banks
```

---

## 6. Code Examples

### 6.1 Enhanced TextPrompt with History

```php
final class TextPrompt
{
    // ... existing properties ...

    private ?History $history = null;
    private int $historyIndex = -1;
    private bool $viMode = false;

    public function withHistory(History $history): self
    {
        $clone = clone $this;
        $clone->history = $history;
        $clone->historyIndex = $history->count();
        return $clone;
    }

    public function handleKey(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        // History navigation
        if ($this->history !== null) {
            if ($key === Key::Up) {
                return $this->historyPrevious();
            }
            if ($key === Key::Down) {
                return $this->historyNext();
            }
        }

        return match ($key) {
            // ... existing keys ...
            Key::CtrlR => $this->startHistorySearch(),
            default => $this,
        };
    }

    private function historyPrevious(): self
    {
        if ($this->history === null) return $this;

        $matches = $this->history->searchPrefix($this->buffer);
        if (empty($matches)) return $this;

        $this->historyIndex = min($this->historyIndex, count($matches) - 1);
        if ($this->historyIndex < 0) {
            $this->historyIndex = count($matches) - 1;
        }

        return $this->withBuffer($matches[$this->historyIndex]);
    }

    private function historyNext(): self
    {
        if ($this->history === null) return $this;

        $this->historyIndex++;
        $matches = $this->history->searchPrefix($this->buffer);

        if ($this->historyIndex >= count($matches)) {
            $this->historyIndex = count($matches);
            return $this->withBuffer('');
        }

        return $this->withBuffer($matches[$this->historyIndex]);
    }
}
```

### 6.2 History Interface

```php
interface History
{
    public function append(string $line): void;
    public function search(string $prefix): ?string;
    /** @return list<string> */
    public function searchPrefix(string $prefix): array;
    public function count(): int;
    public function clear(): void;
    public function save(string $path): void;
    public static function load(string $path): self;
}
```

### 6.3 FileHistory Implementation

```php
final class FileHistory implements History
{
    /** @var list<string> */
    private array $entries = [];
    private int $maxSize;

    public function __construct(
        private readonly string $path,
        int $maxSize = 500,
    ) {
        $this->maxSize = $maxSize;
        $this->load();
    }

    public function append(string $line): void
    {
        $line = trim($line);
        if ($line === '') return;

        // Remove existing duplicate (erasedups behavior)
        $this->entries = array_values(
            array_filter($this->entries, fn($e) => $e !== $line)
        );

        $this->entries[] = $line;

        // Trim to max size
        while (count($this->entries) > $this->maxSize) {
            array_shift($this->entries);
        }
    }

    public function search(string $prefix): ?string
    {
        foreach (array_reverse($this->entries) as $entry) {
            if (str_starts_with($entry, $prefix)) {
                return $entry;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function searchPrefix(string $prefix): array
    {
        return array_values(
            array_filter(
                array_reverse($this->entries),
                fn($e) => str_starts_with($e, $prefix)
            )
        );
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function save(string $path): void
    {
        $content = implode("\n", $this->entries);
        file_put_contents($path, $content);
    }

    public static function load(string $path): self
    {
        return new self($path);
    }

    private function load(): void
    {
        if (!file_exists($this->path)) {
            return;
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $this->entries[] = $trimmed;
            }
        }

        // Trim to max size on load
        while (count($this->entries) > $this->maxSize) {
            array_shift($this->entries);
        }
    }
}
```

---

## 7. References

### Libraries Researched

| Library | Language | Repository | Key Feature |
|---------|----------|------------|-------------|
| promptkit | Go | erikgeiser/promptkit | Upstream reference |
| liner | Go | peterh/liner | Pure Go, history search |
| chzyer/readline | Go | chzyer/readline | Full-featured, vi mode |
| rustyline | Rust | kkawakam/rustyline | Emacs/Vi, extensible |
| reedline | Rust | nushell/reedline | Syntax highlighting |
| prompt_toolkit | Python | prompt-toolkit/python-prompt-toolkit | Auto-suggest, Pygments |
| Inquirer.js | Node.js | sboudrias/inquirer.js | Async completion |

### Documentation Sources

- [RustyLine README.md](https://github.com/kkawakam/rustyline/blob/master/README.md)
- [prompt_toolkit asking_for_input.md](https://github.com/prompt-toolkit/python-prompt-toolkit/blob/main/docs/pages/asking_for_input.md)
- [reedline README.md](https://github.com/nushell/reedline/blob/main/README.md)
- [liner GoDoc](http://godoc.org/github.com/peterh/liner)
- [GNU Readline History](https://manpages.debian.org/experimental/readline-common/history.3readline.en.html)
- [Python readline module](https://docs.python.org/3/library/readline.html)
- [Bash History Deduplication](https://unix.stackexchange.com/questions/18212/bash-history-ignoredups-and-erasedups-setting-conflict-with-common-history)

---

## 8. Appendix: Key Binding Reference

### Emacs Mode (Default in Most Libraries)

| Key | Action |
|-----|--------|
| Ctrl-A | Move to line start |
| Ctrl-E | Move to line end |
| Ctrl-B | Move left |
| Ctrl-F | Move right |
| Ctrl-H | Delete before cursor |
| Ctrl-D | Delete under cursor |
| Ctrl-K | Delete to end of line |
| Ctrl-U | Delete to start of line |
| Ctrl-P | Previous history |
| Ctrl-N | Next history |
| Ctrl-R | Reverse search |
| Ctrl-Y | Yank (paste) |
| Ctrl-_ | Undo |

### Vi Mode (Based on rustyline)

| Key | Action |
|-----|--------|
| `h` | Left |
| `l` | Right |
| `w` | Word forward |
| `b` | Word back |
| `0` | Line start |
| `$` | Line end |
| `i` | Insert mode |
| `a` | Append after cursor |
| `A` | Append at line end |
| `x` | Delete char |
| `dd` | Delete line |
| `Esc` | Normal mode |
| `/` | Search forward |
| `n` | Next search match |
| `N` | Previous search match |
| `Ctrl-R` | Undo |
