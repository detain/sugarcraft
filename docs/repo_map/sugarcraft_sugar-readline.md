# SugarCraft / sugar-readline

## Metadata

- **Library:** `sugar-readline`
- **Composer package:** `sugarcraft/sugar-readline`
- **Namespace:** `SugarCraft\Readline`
- **Status:** 🟢 v1 ready
- **Upstream source:** `erikgeiser/promptkit` (Go, ~300-500 stars, MIT)
- **Depends on:** None (pure PHP, no external readline dependency)
- **PHP minimum:** `^8.3`

## Overview

**`sugar-readline`** is a pure-PHP interactive line-editing prompt library that ports the Go `erikgeiser/promptkit` ecosystem. It provides five prompt types (Text, Confirmation, Selection, MultiSelect, Textarea) driven by a clean state-machine model where every input handler returns a new immutable instance. No external readline library is required — the library handles all terminal input and ANSI rendering internally.

This is a **companion/sibling to `sugar-prompt`** (which is a `charmbracelet/huh` port) rather than a replacement. Where `sugar-prompt` is a multi-page form framework with theming, `sugar-readline` is a focused, lightweight prompt toolkit for single-shot interactive inputs — ideal for CLI tools that need one-off prompts without the full form framework overhead.

---

## Architecture

### State Machine Model

Every prompt is a value object with three mutually-exclusive states:

```
 ┌─────────────┐  submit()   ┌────────────┐
 │  pending    │ ─────────▶ │ submitted │
 └─────────────┘            └────────────┘
       │                          │
       │ abort() / Escape / CtrlC
       ▼                          ▼
 ┌─────────────┐            (value
 │  aborted    │             returned
 └─────────────┘             is empty)
```

This is implemented identically across all five prompt types via `submitted` and `aborted` boolean flags. Input is accepted only in `pending` state — every `handleChar()` and `handleKey()` method starts with:

```php
if ($this->submitted || $this->aborted) {
    return $this;
}
```

**Immutable + fluent**: every state-mutating method returns `clone $this`. No property is ever modified in-place.

### Directory Structure

```
sugar-readline/
├── src/
│   ├── Ansi.php                    — Tiny ANSI SGR wrapper (\x1b[${codes}m)
│   ├── AutoSuggest.php              — Fish-style history-based suggestion model
│   ├── Highlight.php               — Syntax highlighting stub (sugar-glow integration planned)
│   ├── Key.php                     — Symbolic key name constants
│   ├── TextPrompt.php              — 612 lines — Single-line text input
│   ├── ConfirmationPrompt.php      — 144 lines — Yes/No prompt
│   ├── SelectionPrompt.php         — 239 lines — Single-choice list
│   ├── MultiSelectPrompt.php       — 309 lines — Multi-choice list
│   ├── TextareaPrompt.php          — 260 lines — Multi-line text input
│   ├── UndoManager.php             — Undo/redo stack (undo/redo stacks parallel)
│   ├── Mode/
│   │   ├── ModeInterface.php      — Vi/Emacs mode contract
│   │   ├── ViMode.php             — 302 lines — Vi keybindings (insert/normal/visual)
│   │   └── EmacsMode.php          — 371 lines — Emacs keybindings (+ Alt+B/F/D)
│   └── History/
│       ├── HistoryInterface.php   — push/getPrevious/getNext/reset/clear contract
│       ├── InMemoryHistory.php     — Newest-first in-memory store
│       └── FileHistory.php        — Extends InMemoryHistory with file persistence
├── tests/
│   ├── ReadlineTest.php            — 708 lines — Full behavior tests
│   ├── AutoSuggestTest.php
│   ├── UndoManagerTest.php
│   ├── HighlightTest.php
│   ├── Mode/EmacsModeTest.php
│   ├── Mode/ViModeTest.php
│   ├── History/FileHistoryTest.php
│   └── History/InMemoryHistoryTest.php
├── examples/
│   ├── basic.php                   — Text, Selection, Confirmation, Textarea demos
│   └── multi-select.php            — MultiSelect with min/max enforcement
├── .vhs/
│   ├── basic.tape + .gif
│   └── multi-select.tape + .gif
└── composer.json
```

---

## Prompt Types

### TextPrompt (`src/TextPrompt.php` — 612 lines)

Single-line text input with cursor navigation. Key features:

| Feature | Method | Notes |
|---|---|---|
| Default value | `withDefault(string)` | Sets buffer + cursor to end |
| Password masking | `withHidden(bool, string $mask)` | Replaces chars with mask in `view()` |
| Completions | `withCompletions(list<string>)` | Tab applies common-prefix match |
| Char limit | `withCharLimit(int)` | Clamps at limit |
| Validator | `withValidator(callable(string): bool)` | Runs on `submit()`, shows error |
| History | `withHistory(HistoryInterface)` | Up/Down navigation |
| Vi mode | `withMode(ViMode)` | `h/l/w/b/0/$/i/a/A/dd/yy` |
| Emacs mode | `withMode(EmacsMode)` | `Ctrl+A/E/B/F/P/N/T/W` |
| Undo/redo | `withUndoManager(UndoManager)` | `Ctrl+Z` / `Ctrl+Y` |
| Syntax highlight | `withHighlight(Highlight)` | Stub; planned for sugar-glow integration |
| Auto-suggest | `withAutoSuggest(bool)` | Fish-style dim-gray suggestion from history |

**State properties:**
```php
private string $buffer = '';       // User input
private int $cursor = 0;           // Grapheme index
private bool $hidden = false;      // Password mode
private int $charLimit = 0;       // 0 = unlimited
private bool $submitted = false;
private bool $aborted = false;
private string $error = '';
/** @var list<string> */
private array $completions = [];
/** @var (callable(string): bool)|null */
private $validator = null;
private string $labelStyle = '1;36';       // bold cyan
private string $cursorStyle = '7';          // reverse video
private string $errorStyle = '31';         // red
private string $completionStyle = '90';   // bright black
private string $hideMask = '*';
private ?HistoryInterface $history = null;
private ?HistoryInterface $historyOriginal = null;
private int $historyPosition = -1;
private ?string $bufferBeforeHistory = null;
private ?ModeInterface $mode = null;
private ?UndoManager $undoManager = null;
private ?Highlight $highlight = null;
private bool $autoSuggestEnabled = true;
```

**Key implementation details:**

- **Multibyte-safe**: uses `mb_strlen`/`mb_substr` with `'UTF-8'` throughout. Cursor is a grapheme index, not byte offset.
- **History navigation**: `Up` saves current buffer to `$bufferBeforeHistory`, then walks history newest-first. `Down` walks toward live buffer. Typing resets `$historyPosition = -1`.
- **Undo/redo**: parallel stacks in `UndoManager`. `push()` clears redo. Returns `[newManager, restoredState, ok]` tuple.
- **Completion**: `suggestion()` scans completions for `str_starts_with()`. Tab applies via `applyCompletion()`.
- **Auto-suggest**: scans history entries (cloning to peek without advancing) for first one matching `str_starts_with($entry, $this->buffer)`. Renders remaining as dim (`'2'`) style.

**Rendering** (`view()`): returns multi-line string:
1. Label + buffer (with cursor char wrapped in reverse video)
2. Error line (if any)
3. Completion hint (gray, aligned to cursor column)
4. Auto-suggestion (dim, aligned to cursor column)

---

### ConfirmationPrompt (`src/ConfirmationPrompt.php` — 144 lines)

Yes/No with customizable labels. Key design decision: **selection and submission are decoupled**. Pressing `y`/`n`/`←`/`→`/`Tab` changes the selected value but does not auto-submit. `Enter` is required to commit.

```php
public function __construct(private readonly string $label, bool $defaultValue = true)
```

| Key | Action |
|---|---|
| `y` / `Y` / `←` | Select Yes |
| `n` / `N` / `→` | Select No |
| `Tab` | Toggle |
| `Enter` | `submit()` |
| `Esc` / `CtrlC` | `abort()` |

**State properties:**
```php
private bool $value;              // Current selection (true = Yes)
private bool $submitted = false;
private bool $aborted = false;
private string $confirmLabel = 'Yes';
private string $cancelLabel = 'No';
private string $hint = '[y/n]';
private string $labelStyle = '1;36';
private string $selectedStyle = '1;32';
```

**Methods**: `result()` (final boolean, false when aborted), `currentValue()` (current selection regardless of state), `isSubmitted()`/`isAborted()`.

**Rendering**: `Yes` and `No` rendered with/without square brackets. Selected option wrapped in green `'1;32'` style.

---

### SelectionPrompt (`src/SelectionPrompt.php` — 239 lines)

Single-choice filtered list with cursor navigation and pagination. Filter is applied as a stable view over the original choice list — marks survive filter changes.

```php
public function __construct(private readonly string $label, array $choices)
// choices stored at indices 0..N-1, filtered is list of those indices
```

**State properties:**
```php
/** @var list<string> */
private array $choices;          // Original (never mutated)
/** @var list<int> */
private array $filtered;          // Indices into $choices that pass the filter
private string $filter = '';
private int $cursor = 0;          // Index into $filtered
private int $page = 0;
private int $pageSize = 10;
private bool $submitted = false;
private bool $aborted = false;
```

**Filter algorithm** (case-insensitive substring):
```php
$clone->filtered = $needle === ''
    ? array_keys($clone->choices)
    : array_values(array_filter(
        array_keys($clone->choices),
        fn(int $i): bool => stripos($clone->choices[$i], $needle) !== false,
    ));
```

**Pagination**: `totalPages()` returns `max(1, ceil(count($filtered) / pageSize))`. Cursor position is automatically clamped to the current page when page changes.

**Rendering**: Shows filter bar with match count, page items with `❯` marker on cursor line, page number if `totalPages() > 1`.

---

### MultiSelectPrompt (`src/MultiSelectPrompt.php` — 309 lines)

Multi-choice list with min/max enforcement and FIFO rollover at cap.

**Key design**: marks are stored by *original* choice index (not filtered index), so they survive filter changes. `selectedValues()` iterates original `$choices` and returns values in original order.

```php
/** @var array<int,true> */
private array $marked = [];       // Set of marked indices into $choices
private int $minSelect = 0;
private int $maxSelect = 0;       // 0 = unlimited
```

**FIFO rollover** when at max capacity:
```php
private function toggleCurrent(): self
{
    // ...
    if ($clone->maxSelect > 0 && count($clone->marked) >= $clone->maxSelect) {
        $oldest = array_key_first($clone->marked);
        if ($oldest !== null) {
            unset($clone->marked[$oldest]);
        }
    }
    $clone->marked[$choiceIdx] = true;
    return $clone;
}
```

**Key bindings**: `Space` toggles, `Enter` submits (only if `canSubmit()` returns true), `Esc`/`CtrlC` aborts.

---

### TextareaPrompt (`src/TextareaPrompt.php` — 260 lines)

Multi-line text input. Up/Down move between lines; Home/End jump within the current line; Enter inserts a newline; Backspace at column 0 merges with previous line.

**State properties:**
```php
/** @var list<string> */
private array $lines = [''];      // Each entry is one line's text
private int $line = 0;           // Current line index
private int $col = 0;            // Grapheme index within current line
private int $maxLines = 0;       // 0 = unlimited
private bool $submitted = false;
private bool $aborted = false;
```

**Rendering**: iterates `$this->lines`, highlights the cursor line with the cursor character wrapped in reverse video.

**Line merge on backspace** (when at column 0):
```php
$clone->lines[$clone->line - 1] = $prev . $current;
array_splice($clone->lines, $clone->line, 1);
$clone->line--;
$clone->col = self::charCount($prev);
```

---

## KeyBindings

The `Key` class provides symbolic constants:

```php
final class Key
{
    public const Up        = 'up';
    public const Down      = 'down';
    public const Left      = 'left';
    public const Right     = 'right';
    public const Home      = 'home';
    public const End       = 'end';
    public const PageUp    = 'pageup';
    public const PageDown  = 'pagedown';
    public const Tab       = 'tab';
    public const Enter     = 'enter';
    public const Backspace = 'backspace';
    public const Delete    = 'delete';
    public const Space     = 'space';
    public const Escape    = 'esc';
    public const CtrlC     = 'ctrl_c';
    public const CtrlU     = 'ctrl_u';
    public const CtrlK     = 'ctrl_k';
    public const CtrlW     = 'ctrl_w';
    public const Undo       = 'undo';
    public const Redo       = 'redo';
}
```

**Note**: This is a string-constant class (not a PHP enum) so callers can pass raw strings for interoperability with input parsers that already produce key names.

---

## History System

### HistoryInterface

```php
interface HistoryInterface
{
    public function push(string $line): void;
    public function getPrevious(): ?string;   // Move deeper (older)
    public function getNext(): ?string;      // Move toward live buffer
    public function reset(): void;           // Back to -1 (live buffer)
    public function clear(): void;
}
```

### InMemoryHistory

Newest-first storage (`index 0 = most recent`). Position `-1` = live buffer (no entry selected).

```php
public function push(string $line): void
{
    if ($line === '') return;
    // Skip duplicate of most recent
    if (($this->history[0] ?? null) === $line) return;
    array_unshift($this->history, $line);   // Prepend (newest-first)
    $this->position = -1;
}
```

### FileHistory extends InMemoryHistory

Persists to a plain-text file (one entry per line, oldest-first = append-only). Uses `flock(LOCK_EX)` for concurrent-safe writes. Loads on construction, so the same file can be shared across sessions.

```php
public function __construct(string $filePath)
{
    $this->filePath = $filePath;
    if (!file_exists($this->filePath)) {
        touch($this->filePath);
    }
    $this->load();
}

private function load(): void
{
    // File is oldest→newest; read all, then push each (which prepends)
    // so newest ends up at index 0 (newest-first in-memory format)
    foreach ($lines as $entry) {
        parent::push($entry);
    }
}
```

---

## Vi Mode (`src/Mode/ViMode.php` — 302 lines)

Three submodes: `insert` (default), `normal`, `visual`.

| Submode | Entry | Keys |
|---|---|---|
| insert | Default / `i` from normal | Type inserts; `Esc` → normal |
| normal | `Esc` from insert/visual | `h/l` move, `w/b` word, `0/$` line, `i/a/A` insert, `v` visual, `d` pending motion, `y` pending motion, `Ctrl+P/N` history |
| visual | `v` from normal | `h/l/w/b/0/$` extend selection; `Esc` → normal |

**Pending motion** for `dd` (delete line):
```php
private function resolvePendingMotion(TextPrompt $prompt, string $key): TextPrompt
{
    if ($motion === 'd' && $key === 'd') {
        $prompt = $this->deleteLine($prompt);  // Home + CtrlK
    }
    return $this->withViMode($nextMode)->withPendingMotion(null)->attachTo($prompt);
}
```

**Cursor movement** for word navigation:
```php
private function wordForward(TextPrompt $prompt): TextPrompt
{
    // Skip word chars, then non-word chars, return position
    while ($cursor < $len && $this->isWordChar($buffer, $cursor)) $cursor++;
    while ($cursor < $len && !$this->isWordChar($buffer, $cursor)) $cursor++;
    return $this->moveCursorTo($prompt, $cursor);
}

private function isWordChar(string $buffer, int $pos): bool
{
    $char = mb_substr($buffer, $pos, 1, 'UTF-8');
    return $char !== '' && preg_match('/[a-zA-Z0-9_\p{L}]/u', $char) === 1;
}
```

---

## Emacs Mode (`src/Mode/EmacsMode.php` — 371 lines)

Key bindings beyond TextPrompt defaults:

| Binding | Action |
|---|---|
| `Ctrl+A` | Move to line start |
| `Ctrl+E` | Move to line end |
| `Ctrl+B` | Move left (back) |
| `Ctrl+F` | Move right (forward) |
| `Alt+B` | Move one word backward |
| `Alt+F` | Move one word forward |
| `Ctrl+W` | Delete word before cursor |
| `Alt+D` | Delete word after cursor |
| `Ctrl+T` | Transpose characters |
| `Ctrl+P` | History previous (`Up`) |
| `Ctrl+N` | History next (`Down`) |
| `Esc` prefix | Enter Alt-prefix mode for `Alt+B/F/D` |

**Alt-prefix handling** uses a stateful flag `$altPrefix`. On `Escape`, it sets the flag and returns. The next key in `Alt+B/F/D` is then consumed by `handleAltKey()`:

```php
if ($key === Key::Escape) {
    return $this->withAltPrefix(true)->attachTo($prompt);
}
if ($this->isAltPrefix()) {
    return $this->handleAltKey($prompt, $key);
}
```

**Word-based operations** use the same `isWordChar()` regex as ViMode — Unicode-aware (`\p{L}`).

---

## Comparison with Upstream Repos

### erikgeiser/promptkit (primary upstream)

`sugar-readline` is a direct port of `promptkit` from Go to PHP. The upstream provides:

| Feature | promptkit (Go) | sugar-readline (PHP) | Notes |
|---|---|---|---|
| TextInput | 🟢 | 🟢 | Full parity: validation, completions, hidden mode, history |
| Confirmation | 🟢 | 🟢 | Selection/submission decoupled identically |
| Selection | 🟢 | 🟢 | Filter + pagination + cursor navigation |
| MultiSelection | 🟢 | 🟢 | Min/max + FIFO rollover |
| TextArea | 🟢 | 🟢 | Line/col cursor, max-lines cap |
| Vi mode | 🟢 | 🟢 | insert/normal/visual + `dd` motion |
| Emacs mode | 🟢 | 🟢 | Full bindings + Alt-prefix |
| History | 🟢 | 🟢 | InMemory + File implementations |
| Template rendering | 🟢 | 🔶 | promptkit uses `text/template`; sugar-readline uses direct string concat |
| Generic types | 🟢 | N/A | Go generics; PHP workaround not needed for prompts |
| Undo/redo | 🟢 | 🟢 | Parallel undo/redo stacks |
| Auto-suggest | 🟢 | 🟢 | Fish-style dim suggestion from history |
| Syntax highlight | N/A | 🔶 | Stub only (planned sugar-glow integration) |

**Template rendering difference**: promptkit uses Go's `text/template` with helper functions (`Repeat`, `Len`, `Min`, `Max`, `TrimPrefix`, etc.) and Lipgloss styles. sugar-readline uses direct PHP string concatenation with hardcoded ANSI SGR codes. This is simpler and more portable but less flexible for custom templates.

### charmbracelet/huh (form library)

**Relationship**: `sugar-prompt` (🟢) ports `huh` as a multi-page form framework. `sugar-readline` ports `promptkit` as standalone prompts. They are complementary, not competing.

| Feature | huh | sugar-readline |
|---|---|---|
| Form/Group/Field hierarchy | 🟢 | N/A |
| Multi-page forms | 🟢 | N/A |
| Per-field theming | 🟢 | Limited (hardcoded ANSI styles) |
| Dynamic labels (*Func) | 🟢 | N/A |
| Async suggestions | 🟢 (ReactPHP) | N/A |
| Validation | 🟢 | 🟢 (via `withValidator`) |
| Single-shot prompts | 🟢 | 🟢 (lighter weight) |

`sugar-readline` is ideal for single-shot prompts in CLI tools. `sugar-prompt`/`candy-forms` is better for multi-page forms with dynamic state.

### charmbracelet/bubbles (TextInput, TextArea)

Bubbles provides `TextInput` and `TextArea` as Bubble Tea models. sugar-readline's equivalents are simpler — they don't require the Bubble Tea runtime, work as pure value objects, and handle their own rendering.

| Feature | bubbles TextInput | sugar-readline TextPrompt |
|---|---|---|
| State model | TEA Model (Init/Update/View) | Immutable value object (handleChar/handleKey) |
| Echo modes | 🟢 | 🟢 (password mode) |
| Suggestions | 🟢 | 🟢 |
| History | 🟢 | 🟢 |
| Vim mode | 🔶 | 🟢 (more complete) |
| Undo/redo | 🟢 | 🟢 |
| Validation | 🟢 (ValidateFunc) | 🟢 (withValidator) |

---

## Innovation Points (SugarCraft Extensions over Upstream)

1. **Pure PHP, no external dependency**: No `readline` extension, no FFI, no external binary. Works anywhere PHP 8.3 runs.

2. **In-memory + File history**: Both implementations of `HistoryInterface`. FileHistory uses simple append-only format with `flock` for concurrency — zero-dependency persistence.

3. **Parallel undo/redo stacks**: The `UndoManager` maintains separate undo and redo stacks (both newest-first). `undo()` pops from undo, pushes to redo. `redo()` reverses. This is cleaner than the typical single-stack approach.

4. **FIFO rollover for MultiSelect**: When `maxSelections` is set and the user marks a new item beyond the cap, the oldest mark is evicted — predictable, fair behavior.

5. **Marks survive filter changes**: MultiSelect stores marks by original choice index. Filtering recalculates the filtered view but never loses marks.

6. **Confirmation: decoupled select/submit**: `y`/`n`/`←`/`→` change selection but don't auto-submit. This is a better UX than the common pattern of auto-submitting on selection.

7. **ModeInterface abstraction**: Vi and Emacs are separate classes implementing `ModeInterface`. A third party could implement `DvorakMode` or `HJKLMode` by implementing the interface.

8. **No runtime required**: Unlike Bubble Tea models (which require a running `tea.Program` with message loop), sugar-readline prompts are plain PHP objects. Call `handleChar`/`handleKey` in a loop, render with `view()` — done. No event loop.

9. **Unicode-aware throughout**: All cursor and slicing operations use `mb_strlen`/`mb_substr` with `'UTF-8'`. Handles any Unicode input correctly.

---

## Strengths

1. **Clean state machine**: Three-state model (pending/submitted/aborted) with consistent flag-based implementation across all five prompt types. Easy to reason about.

2. **True immutability**: `clone $this` on every mutation. No shared mutable state. Prompts can be safely held in arrays or passed as values without defensive copying.

3. **No dependencies**: Zero external dependencies. Composer.json requires only `php: ^8.3` and `phpunit/phpunit: ^10.5` (dev).

4. **Comprehensive test coverage**: 708-line `ReadlineTest.php` covers every prompt type, every key binding, history navigation edge cases (empty buffer, past oldest, typing resets position), MultiSelect FIFO rollover, confirmation select/submit decoupling, Textarea line merging.

5. **Well-documented examples**: `basic.php` demonstrates all five prompt types; `multi-select.php` shows min/max enforcement with simulated key presses.

6. **ANSI styling constants exposed**: Styles like `$labelStyle = '1;36'` are public properties (not private), allowing callers to customize appearance without subclassing.

---

## Weaknesses

1. **No input driver**: `sugar-readline` has no code that reads actual keypresses from a terminal. It only processes already-decoded key names. A higher-level driver (like a TTY loop in `candy-core` or a ReactPHP stream reader) is needed to turn terminal escape sequences into `Key` constants. The README examples show simulated keypresses only.

2. **Hardcoded ANSI codes**: Styles are bare SGR strings (`'1;36'`, `'7'`, `'31'`) rather than a proper style system. Customization requires string manipulation. Compare to `candy-shine` which has a proper `Style` class with chainable methods.

3. **No cursor blink**: Unlike `candy-forms` TextInput which has a `Cursor` blink state machine, sugar-readline's TextPrompt renders cursor as static reverse video with no blinking animation.

4. **No mouse support**: Selection and MultiSelect don't respond to mouse clicks for positioning or selection. Upstream `promptkit` also lacks this.

5. **Highlight is a stub**: `Highlight.php` returns a single unstyled span. Full syntax highlighting via `sugar-glow` integration is planned but not yet implemented.

6. **No soft-wrap in Textarea**: TextareaPrompt stores each line as a separate array entry. There is no soft-wrap (word-wrap at a given column width). If a user types a long line, it stays as one line in the array — no automatic wrapping.

7. **Filter is case-insensitive substring only**: No fuzzy filtering, no regex, no diacritic folding. Compare to `candy-forms` ItemList which has Smith-Waterman fuzzy matching.

8. **No vim visual mode selection**: ViMode has visual mode entry (`v` from normal) but doesn't expose the selected range — `handleVisualMode()` tracks no selection state. It's a placeholder for future completion.

9. **Confirmation submit is a no-op on canSubmit** (MultiSelect only): For `MultiSelectPrompt`, `submit()` returns `$this` without transition if `canSubmit()` is false — the prompt stays in `pending` state but the user has no indication why Enter didn't work.

10. **No built-in help view**: No equivalent to bubbles' `Help` component that shows keybindings. Users must reference documentation.

---

## File References

### Source Files

| File | Lines | Description |
|---|---|---|
| `sugar-readline/src/TextPrompt.php` | 612 | Single-line text input with all features |
| `sugar-readline/src/ConfirmationPrompt.php` | 144 | Yes/No prompt |
| `sugar-readline/src/SelectionPrompt.php` | 239 | Single-choice filtered list |
| `sugar-readline/src/MultiSelectPrompt.php` | 309 | Multi-choice with FIFO rollover |
| `sugar-readline/src/TextareaPrompt.php` | 260 | Multi-line text input |
| `sugar-readline/src/Key.php` | 37 | Symbolic key constants |
| `sugar-readline/src/Ansi.php` | 22 | ANSI SGR wrapper |
| `sugar-readline/src/UndoManager.php` | 100 | Parallel undo/redo stacks |
| `sugar-readline/src/AutoSuggest.php` | 57 | Fish-style suggestion model |
| `sugar-readline/src/Highlight.php` | 31 | Syntax highlight stub |
| `sugar-readline/src/Mode/ModeInterface.php` | 32 | Vi/Emacs mode contract |
| `sugar-readline/src/Mode/ViMode.php` | 302 | Vi keybindings (insert/normal/visual) |
| `sugar-readline/src/Mode/EmacsMode.php` | 371 | Emacs keybindings + Alt-prefix |
| `sugar-readline/src/History/HistoryInterface.php` | 38 | History contract |
| `sugar-readline/src/History/InMemoryHistory.php` | 82 | In-memory newest-first store |
| `sugar-readline/src/History/FileHistory.php` | 148 | File-persisted history |

### Test Files

| File | Lines | Coverage |
|---|---|---|
| `sugar-readline/tests/ReadlineTest.php` | 708 | All prompt types, all keybindings, history edge cases |
| `sugar-readline/tests/Mode/ViModeTest.php` | — | Vi mode submodes |
| `sugar-readline/tests/Mode/EmacsModeTest.php` | — | Emacs bindings |
| `sugar-readline/tests/History/FileHistoryTest.php` | — | File persistence |
| `sugar-readline/tests/History/InMemoryHistoryTest.php` | — | In-memory navigation |

### Examples

| File | Demonstrates |
|---|---|
| `sugar-readline/examples/basic.php` | TextPrompt completions, SelectionPrompt filter, ConfirmationPrompt, TextareaPrompt |
| `sugar-readline/examples/multi-select.php` | MultiSelectPrompt min/max, FIFO rollover |

---

## Analysis

### Architectural Insight: Prompts as Value Objects

The most distinctive architectural decision in `sugar-readline` is modeling prompts as **immutable value objects** rather than mutable Tea models. Each `handleChar()` / `handleKey()` returns a new instance. This means:

1. **No hidden state**: You always hold the complete state in a variable. No risk of stale object references.
2. **Natural history tracking**: Previous states are accessible by keeping older references. Undo is as simple as keeping an array of instances.
3. **Natural testability**: Every behavior is a pure function from prompt + input → new prompt. No mock needed for `view()` — just assert on returned string.
4. **Concurrency-safe**: Multiple prompts can be processed in parallel without shared mutable state.

The tradeoff: more object allocations. For a CLI tool this is negligible. For a high-frequency interactive use case, `candy-forms` with its mutable `mutate()` helper might be more efficient.

### Relationship to sugar-prompt / candy-forms

`sugar-readline` and `sugar-prompt` address overlapping use cases (interactive prompts) but with different philosophies:

| Aspect | sugar-readline | sugar-prompt / candy-forms |
|---|---|---|
| Model | Immutable value object | TEA Model (mutable with `mutate()`) |
| Runtime | No event loop | Bubble Tea runtime with Msg/Cmd |
| State | Held in single variable | Distributed across form/field |
| Best for | Single-shot prompts | Multi-page forms |
| Theming | Hardcoded ANSI strings | Full `candy-shine` Style system |
| Per-field customization | Properties on each prompt | Fluent `with*()` methods |
| Async | None | ReactPHP for async suggestions |

`sugar-readline` is lighter weight and more portable (no Bubble Tea dependency), while `sugar-prompt` is more powerful for complex form flows.

### No-External-Dependency Design

`sugar-readline`'s most significant engineering achievement is implementing prompt behavior without any external readline library. It handles:
- Key input via symbolic string names (not raw bytes)
- History via pure PHP arrays
- ANSI rendering via simple `sprintf` with `\x1b[]m` patterns

This makes it trivially embeddable in any PHP 8.3+ project without worrying about `ext-readline` availability or FFI complexity on Windows.

---

## What Remains Incomplete

As a 🟢 v1-ready package, there are no blocking gaps. The following are planned future work:

1. **sugar-glow integration** for `Highlight.php` — Full syntax highlighting for code input (step 10.24 per doc comment).

2. **Vi visual mode** — Store selection state and expose it via accessors when visual mode is active.

3. **Cursor blink timer** — `TextPrompt` renders a static cursor. A real cursor blink (on/off every 500ms) would require a timer mechanism and would make the TUI feel more alive.

4. **Mouse support** — Click-to-position cursor in TextPrompt, click-to-select in Selection/MultiSelect.

5. **Soft-wrap in Textarea** — Word-wrap long lines at a configurable column width rather than storing as one array entry.

6. **Built-in help view** — Render keybinding reference for each prompt type, similar to bubbles' `Help` component.

7. **Confirmation submit feedback** — When `MultiSelect::submit()` fails due to `!canSubmit()`, some visual indication (flash or message) should inform the user why Enter did nothing.
