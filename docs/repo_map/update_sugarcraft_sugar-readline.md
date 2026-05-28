# Overview

`sugar-readline` is a pure-PHP interactive line-editing prompt library that ports the Go `erikgeiser/promptkit` ecosystem. It provides five prompt types (Text, Confirmation, Selection, MultiSelect, Textarea) driven by a clean state-machine model where every input handler returns a new immutable instance. As a 🟢 v1-ready package with zero external dependencies, it is the lightweight alternative to the full form framework `sugar-prompt`/`candy-forms`.

**Biggest opportunity areas:** Style system overhaul (replace hardcoded ANSI codes with proper Style objects), input driver integration (currently prompts have no way to read actual terminal input), cursor blink animation, mouse support, soft-wrap in Textarea, and a built-in Help component.

**Biggest missing capabilities:** No way to read real terminal keypresses (only simulated key names), no cursor blink, no mouse interaction, Highlight is an empty stub, no vim visual mode selection range, no soft-wrap, case-insensitive substring-only filtering (no fuzzy), and no built-in help view.

---

# Internal Capability Summary

## Architecture

`sugar-readline` implements prompts as **immutable value objects** — each `handleChar()`/`handleKey()` call returns a `clone` with modified state. This differs fundamentally from Bubble Tea's mutable TEA models and even from `candy-forms`'s `mutate()` helper approach.

**Three-state machine:** `pending` → `submitted` or `aborted`. Every handler guards with:
```php
if ($this->submitted || $this->aborted) { return $this; }
```

**Prompt types:**
- `TextPrompt` (612 lines) — single-line input, password mode, completions, char limit, validator, history, Vi/Emacs modes, undo/redo, auto-suggest, syntax highlight stub
- `ConfirmationPrompt` (144 lines) — yes/no, decoupled select/submit (y/n changes selection, Enter commits)
- `SelectionPrompt` (239 lines) — filtered single-choice list with pagination
- `MultiSelectPrompt` (309 lines) — multi-choice with min/max enforcement and FIFO rollover
- `TextareaPrompt` (260 lines) — multi-line input, line/col cursor, max-lines cap

**Key systems:**
- `Key` class — symbolic string constants for all supported keys
- `ModeInterface` — ViMode (insert/normal/visual) and EmacsMode implementations
- `HistoryInterface` — InMemoryHistory (newest-first) and FileHistory (append-only with `flock`)
- `UndoManager` — parallel undo/redo stacks (newest-first)
- `AutoSuggest` — fish-style suggestion model for history-based completions
- `Highlight` — syntax highlighting stub (currently no-op, planned sugar-glow integration)
- `Ansi` — tiny SGR wrapper (`\x1b[${codes}m...\x1b[0m`)

## Strengths

1. **True immutability** — `clone $this` on every mutation; no shared mutable state
2. **No external dependencies** — pure PHP, no ext-readline, no FFI, works anywhere PHP 8.3 runs
3. **Unicode-aware** — all cursor/slicing uses `mb_strlen`/`mb_substr` with `'UTF-8'`
4. **Clean state machine** — three-state (pending/submitted/aborted) with consistent flag-based implementation
5. **Vi/Emacs modes** — ModeInterface abstraction allows third-party mode implementations
6. **Confirmation decoupled select/submit** — better UX than auto-submit on selection
7. **MultiSelect marks survive filter changes** — marks stored by original choice index
8. **FIFO rollover for MultiSelect** — predictable eviction when at max capacity
9. **Parallel undo/redo stacks** — cleaner than single-stack approach
10. **Comprehensive test coverage** — 708-line test covers every prompt type and key binding

## Weaknesses

1. **No input driver** — cannot read actual terminal keypresses; only processes already-decoded key names; examples show simulated keypresses only
2. **Hardcoded ANSI codes** — bare SGR strings (`'1;36'`, `'7'`, `'31'`) not a proper style system; customization requires string manipulation
3. **No cursor blink** — TextPrompt renders static reverse video cursor; no animation
4. **No mouse support** — Selection/MultiSelect don't respond to mouse clicks
5. **Highlight is empty stub** — `Highlight::highlight()` returns single unstyled span; sugar-glow integration planned but not started
6. **No soft-wrap in TextareaPrompt** — long lines stored as one array entry; no word-wrap at column width
7. **Substring-only filter** — no fuzzy matching, no diacritic folding, no regex; compare to Smith-Waterman in `candy-forms`
8. **Vi visual mode is placeholder** — `handleVisualMode()` tracks no selection state
9. **No built-in Help view** — no equivalent to bubbles' `Help` component showing keybindings
10. **Confirmation submit feedback missing** — `MultiSelect::submit()` silently returns `$this` when `canSubmit()` is false

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `charmbracelet/bubbles` | HIGH | TextInput, TextArea, List, Help, Cursor blink, fuzzy filtering, paste handling | P0 |
| `charmbracelet/bubbletea` | HIGH | TEA architecture, input handling, mouse events, cursor control, synchronized output (mode 2026) | P0 |
| `charmbracelet/lipgloss` | HIGH | Style system (pure value types, CSS-like API, CIELAB colors, borders, layout) | P1 |
| `textualize/textual` | MEDIUM | Reactive state, CSS layout, command palette, widget system, testing (Pilot class) | P1 |
| `ratatui/ratatui` | MEDIUM | Cassowary constraint layout, buffer diffing, Widget trait pattern, StatefulWidget | P2 |
| `erikgeiser/promptkit` | DIRECT UPSTREAM | All five prompt types, history, mode system — already fully ported | P0 |

---

# Feature Gap Analysis

## Critical Priority

### 1. Input Driver / TTY Integration
- **Title:** No way to read actual terminal keypresses
- **Description:** `sugar-readline` only processes symbolic key names. It has no mechanism to decode terminal escape sequences into `Key` constants. A higher-level driver (like ReactPHP stream reader or PTY loop) is needed.
- **Why it matters:** Without this, the library cannot be used for real interactive CLI tools — only demo/testing scenarios with simulated input.
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (mapping file, weakness #1)
- **Source repo:** `charmbracelet/bubbletea.md` — `charmbracelet/bubbletea` handles full key decoding including Kitty keyboard protocol
- **Implementation ideas:**
  1. Create `candy-readline` input driver package that wraps `candy-vt` for escape sequence parsing
  2. Use `candy-pty` PTY support for raw mode input
  3. Adapt `charmbracelet/bubbletea`'s key decoding logic (KeyPressMsg, modifier support, Kitty protocol)
- **Expected impact:** Enables real-world usage; unlocks all other missing features (mouse, cursor blink depend on input loop)
- **Estimated complexity:** High — requires understanding of terminal escape sequences, PTY/raw mode, and async I/O

### 2. Style System Overhaul
- **Title:** Hardcoded ANSI SGR codes instead of proper style objects
- **Description:** Styles like `$labelStyle = '1;36'` are bare SGR strings. Compare to `candy-sprinkles` which has a proper `Style` class with chainable methods and CIELAB color blending.
- **Why it matters:** Cannot customize prompt appearance without manipulating raw ANSI strings; no color adaptability for light/dark terminals; no border/box model
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (weakness #2)
- **Source repo:** `charmbracelet_lipgloss.md` — pure value Style type with fluent API; `charmbracelet_bubbles.md` — Cursor component with blink modes
- **Implementation ideas:**
  1. Adopt `SugarCraft\Sprinkles\Style` from `candy-sprinkles` for styling
  2. Add per-prompt style properties (`labelStyle`, `cursorStyle`, `errorStyle`, etc.) that accept `Style` objects
  3. Implement cursor blink via `candy-forms/Cursor` pattern (Timer/Cmd tick)
- **Expected impact:** Unlocks theming, better visual rendering, cursor animation
- **Estimated complexity:** Medium

### 3. Cursor Blink
- **Title:** No cursor blink animation on TextPrompt
- **Description:** TextPrompt renders cursor as static reverse video. Real cursor blink (on/off every ~530ms) makes TUI feel alive.
- **Why it matters:** Static cursor is a noticeable regression from what users expect from interactive prompts
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (weakness #3)
- **Source repo:** `charmbracelet_bubbles.md` — `Cursor` component with `Blink()` method; `pr_charmbracelet_bubbles.md` — v2 real cursor support
- **Implementation ideas:**
  1. Use `candy-forms/Cursor` blink state machine as reference
  2. Sugar-readline's stateless design makes blink tricky — would need a rendering loop wrapper that toggles cursor visibility
  3. Consider a `PromptRenderer` class that wraps a prompt and animates cursor in a loop
- **Expected impact:** Better perceived interactivity and polish
- **Estimated complexity:** Medium (needs rendering loop integration)

## High Value

### 4. Mouse Support
- **Title:** No mouse interaction for positioning or selection
- **Description:** SelectionPrompt and MultiSelectPrompt don't respond to mouse clicks for cursor positioning or item selection. No mouse wheel scrolling.
- **Why it matters:** Modern terminal apps expect mouse interaction (click to position, wheel to scroll)
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (weakness #4)
- **Source repo:** `charmbracelet_bubbletea.md` — SGR extended mouse mode, click/release/wheel events; `pr_charmbracelet_bubbletea.md` — mouse event data race lessons
- **Implementation ideas:**
  1. Extend Key class with mouse constants (`Key::MouseClick`, `Key::MouseRelease`, `Key::MouseWheel`)
  2. Add `handleMouse(int $x, int $y, string $button)` method to prompts
  3. Decode SGR extended mouse sequences in input driver
- **Expected impact:** Full parity with modern terminal expectations
- **Estimated complexity:** Medium

### 5. Textarea Soft-Wrap
- **Title:** TextareaPrompt stores each line as a separate array entry with no word-wrap
- **Description:** Long input lines are stored as one array entry. There is no soft-wrap at a given column width. Compare to `candy-forms/TextArea` which has `withDynamic(true)` for auto-growing height.
- **Why it matters:** Multi-line text input without wrapping is unusable for real text editing
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (weakness #6)
- **Source repo:** `charmbracelet_bubbles.md` — TextArea soft-wrap with LineInfo tracking (Width, CharWidth, ColumnOffset, RowOffset); `pr_charmbracelet_bubbles.md` — issue #823 viewport refactor with 35% CPU improvement from caching
- **Implementation ideas:**
  1. Store wrapped line offsets in a cache keyed by (content, width) pair
  2. Invalidate cache on content change or width change
  3. Track visual line vs logical line for cursor positioning
- **Expected impact:** Usable multi-line text editing
- **Estimated complexity:** High — cursor tracking across wrapped lines is complex

### 6. Fuzzy/Advanced Filtering
- **Title:** SelectionPrompt and MultiSelectPrompt use case-insensitive substring filter only
- **Description:** Filter is `stripos($clone->choices[$i], $needle) !== false`. No fuzzy matching, no diacritic folding, no regex.
- **Why it matters:** Users expect fuzzy finding (substr in any position, not just consecutive). `candy-forms` has Smith-Waterman fuzzy matching.
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (weakness #7)
- **Source repo:** `charmbracelet_bubbles.md` — List uses `sahilm/fuzzy` for real-time filtering with matched index reporting; `pr_charmbracelet_bubbles.md` — issue #810 list performance, fuzzy library is external dependency
- **Implementation ideas:**
  1. Adapt `candy-forms/Fuzzy/FuzzyMatcher.php` (Smith-Waterman local alignment) for readline
  2. Or integrate `sahilm/fuzzy` equivalent: `php-cFuzzy` or custom PHP implementation
  3. Return matched character indices for filter highlighting
- **Expected impact:** Significantly better UX for selection prompts with many options
- **Estimated complexity:** Medium — fuzzy matching algorithm exists in candy-forms

### 7. Built-in Help View
- **Title:** No Help component showing keybindings per prompt type
- **Description:** Users must reference documentation to know keybindings. Bubbles' `Help` component generates help text from `KeyMap` interface introspection.
- **Why it matters:** Discoverability is poor without inline help; users don't know what keys are available
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (weakness #10)
- **Source repo:** `charmbracelet_bubbles.md` — Help component with `ShortHelp() / FullHelp() []key.Binding`; `pr_charmbracelet_bubbles.md` — issue #461 help rendering width issues
- **Implementation ideas:**
  1. Create `HelpPrompt` class or `withHelp(bool)` method on all prompts
  2. Generate help text from key binding metadata
  3. Show short help (most common keys) by default, full help on `?` or F1
- **Expected impact:** Better discoverability, lower learning curve
- **Estimated complexity:** Low

## Medium Priority

### 8. sugar-glow Syntax Highlighting Integration
- **Title:** Highlight.php is a no-op stub returning single unstyled span
- **Description:** Full syntax highlighting via sugar-glow integration is planned (step 10.24) but not implemented.
- **Why it matters:** Users cannot do syntax-highlighted code input; limits use cases for developer tooling
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (weakness #5)
- **Source repo:** `charmbracelet/bubbletea` — `charmbracelet/glow` for markdown rendering; `charmbracelet/gum` — colored output
- **Implementation ideas:**
  1. Wait for `sugar-glow` to be implemented (planned step 10.24)
  2. Then integrate: `Highlight::highlight()` calls sugar-glow's tokenizer
  3. Return list of styled spans from `Highlight::highlight()`
  4. Modify `TextPrompt::view()` to render multiple spans with styles
- **Estimated complexity:** Medium (depends on sugar-glow)

### 9. Vi Visual Mode Selection Range
- **Title:** ViMode enters visual mode (v key) but tracks no selection state
- **Description:** `handleVisualMode()` is a placeholder that only changes mode indicator but doesn't store selection start/end.
- **Why it matters:** Visual mode for selecting and operating on text regions is a core vim feature
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (weakness #8)
- **Implementation ideas:**
  1. Add `selectionStart` and `selectionEnd` properties to TextPrompt
  2. Track selection range during visual mode navigation
  3. Expose selection via `selectionStart()` / `selectionEnd()` accessors
  4. Apply operations (delete, yank) to selection range
- **Estimated complexity:** Medium

### 10. Confirmation Submit Feedback
- **Title:** MultiSelect::submit() silently stays pending when canSubmit() returns false
- **Description:** When user presses Enter but min selections not met, the prompt just stays in pending state with no indication why.
- **Why it matters:** Poor UX — user doesn't know why Enter didn't work
- **Source:** `docs/repo_map/sugarcraft_sugar-readline.md` (weakness #9)
- **Source repo:** `charmbracelet_bubbles.md` — feedback patterns in various components
- **Implementation ideas:**
  1. Flash an error message or shake the prompt when submit fails
  2. Show remaining count: "Select N more item(s)"
  3. Or highlight the hint area in red momentarily
- **Estimated complexity:** Low

## Low Priority

### 11. Partial Block Progress Indicator
- **Title:** No progress bar component in sugar-readline
- **Description:** sugar-readline doesn't have a progress indicator. If needed, user would need to integrate `sugar-bits/Progress`.
- **Why it matters:** Some interactive prompts may need progress feedback
- **Source repo:** `charmbracelet_bubbles.md` — Progress bar with gradient fills, half-block Unicode (▌) for 2x color resolution; `pr_charmbracelet_bubbles.md` — partial blocks requested in issue #395
- **Implementation ideas:** Consider if sugar-readline needs its own progress or should delegate to sugar-bits
- **Estimated complexity:** N/A — may not belong in readline

### 12. Undo/Redo for MultiSelect
- **Title:** UndoManager is TextPrompt-only; MultiSelectPrompt has no undo/redo
- **Description:** Undo/redo is only available for TextPrompt via withUndoManager(). MultiSelect marks are not undoable.
- **Why it matters:** Users may accidentally mark/unmark items and want to undo
- **Source repo:** `charmbracelet_bubbles.md` — component patterns
- **Implementation ideas:** Extend UndoManager concept to MultiSelectPrompt
- **Estimated complexity:** Low

---

# Algorithm / Performance Opportunities

## Textarea Soft-Wrap Memoization
- **Current approach:** No wrapping — long lines stored as-is
- **Better approach from `pr_charmbracelet_bubbles.md`:** `TextArea memoization reduced 42s→3s for pasting 1000 chars`. Wrap computation is expensive and must be memoized keyed by (content, width) pair.
- **Tradeoffs:** Memoization adds memory overhead; must invalidate on content/width change
- **Applicability:** Directly applicable to TextareaPrompt when soft-wrap is implemented

## List/Selection String Building
- **Current approach:** Uses PHP array ops and string concatenation in filter rendering
- **Better approach from `pr_charmbracelet_bubbles.md`:** Issue #810 — string concatenation in loops (for pagination dots) caused lag with 8000 items. Fix: `strings.Builder` pattern equivalent in PHP: use `implode()` with array instead of `.=` in loops.
- **Tradeoffs:** PHP doesn't have strings.Builder; array + implode() is equivalent
- **Applicability:** SelectionPrompt pagination rendering

## Escape Sequence Stripping for Truncation
- **Current approach:** Direct string operations may not handle ANSI codes in text
- **Better approach from `pr_charmbracelet_bubbles.md`:** Always strip ANSI sequences before measuring/truncating text, then reapply. Pattern: `preg_replace('/\x1b\[[^m]*m/', '', $text)`.
- **Tradeoffs:** Adds overhead per truncation; needed for correctness with colored text
- **Applicability:** SelectionPrompt filter rendering, TextPrompt completion hint

---

# Architecture Improvements

## 1. Input Driver Separation
The most critical architectural improvement is separating input decoding from prompt logic. Currently `sugar-readline` has no input mechanism at all. Consider:

```php
// Current: Prompts only handle pre-decoded key names
$p = $p->handleChar('a')->handleKey(Key::Enter);

// Better: InputDriver class handles escape sequence decoding
// and emits properly typed key events
class TtyInputDriver {
    public function __construct(resource $stream) { ... }
    public function nextKey(): Key { ... }  // blocks for keypress
    public function attachTo(TextPrompt $prompt): self { ... }
}
```

This mirrors how `charmbracelet/bubbletea` separates input handling from model logic.

## 2. Style Object Integration
Replace hardcoded ANSI codes with `SugarCraft\Sprinkles\Style` objects:

```php
// Current
private string $labelStyle = '1;36';

// Better
private Style $labelStyle;

public function withLabelStyle(Style $style): self {
    $clone = clone $this;
    $clone->labelStyle = $style;
    return $clone;
}
```

This enables color profile adaptation (light/dark terminals), CSS-like styling, and proper composition.

## 3. Rendering Loop Wrapper
For cursor blink and real-time updates, consider a `PromptRenderer` class:

```php
class PromptRenderer {
    public function __construct(TextPrompt $prompt, resource $tty) { ... }
    public function run(): string { ... }  // blocking prompt loop
    public function withBlink(bool $blink): self { ... }
    public function withMouse(bool $enabled): self { ... }
}
```

This provides the "real app" experience while keeping prompts stateless.

---

# API / Developer Experience Improvements

## 1. Consistent Return Types
Currently the `submit()` method returns `$this` (self) when validation fails or when MultiSelect can't submit. Consider:

```php
// Current: returns self even on validation failure (error stored in $this->error)
public function submit(): self { ... }

// Better: use Result pattern or throw on validation failure
public function submit(): Result { ... }
// where Result is { submitted: bool, aborted: bool, value: mixed, error: ?string }
```

## 2. Factory Method Naming
Sugar-readline uses `new()` as the primary factory (`TextPrompt::new('label')`). Consider also adding `::prompt()` alias for familiarity with other SugarCraft libs:

```php
public static function prompt(string $label): self { ... }
public static function new(string $label): self { ... }  // existing
```

## 3. Type-Safe Key Handling
Currently all keys are strings. Consider using string enum backed by constants for better IDE support:

```php
// Current
public function handleKey(string $key): self

// Better: union types
public function handleKey(Key::UP|Key::DOWN|Key::ENTER|... $key): self
```

## 4. Prompt Composition
Allow composing multiple prompts (e.g., a form with Text + Confirm + Select):

```php
$form = PromptForm::new()
    ->add(TextPrompt::new('Name: '))
    ->add(ConfirmationPrompt::new('Confirm?'))
    ->run();
```

This would bridge sugar-readline's lightweight prompts with the fuller form system.

---

# Documentation / Cookbook Opportunities

## 1. Interactive CLI Tool Tutorial
Create a step-by-step guide building a real CLI tool (e.g., `todo CLI` or `contact manager`) using sugar-readline prompts. Show:
- Reading real terminal input
- Building a prompt loop
- Handling submit/abort
- Persisting history to file

## 2. Keybinding Reference Card
Create a reference card showing all keybindings for each prompt type, organized by mode (default/Vi/Emacs).

## 3. History Backend Examples
Show examples of custom `HistoryInterface` implementations:
- SQLite-backed history for persistent cross-session history
- Network-fetched history (e.g., company knowledge base suggestions)
- Signed/encrypted history for sensitive input

## 4. Input Driver Implementation Guide
Document how to wire up different input sources:
- Raw TTY stdin for simple cases
- PTY for advanced (including subprocess input)
- ReactPHP stream reader for async integration

---

# UX / TUI Improvements

## 1. Cursor Style Options
- Block cursor (current default, reverse video)
- Underline cursor (`_`)
- Bar cursor (`|`)
- Blinking variants of each

## 2. Loading/Spinner During Async Operations
For prompts that do async work (history fetch, completion lookup), show a spinner via `sugar-bits/Spinner` integration.

## 3. Transition Animations
Smooth transitions between prompt states (submit confirmation flash, error shake).

## 4. Sound/Haptic Feedback
Optional audio cues for submit, error, completion match (via terminal bell or notification daemon).

---

# Testing / Reliability Improvements

## 1. Golden File Snapshot Tests
Currently tests use programmatic assertions. Add golden file tests for `view()` output:

```php
// First run creates golden file
// Subsequent runs compare against it
public function testTextPromptView(): void {
    $prompt = TextPrompt::new('Name: ')->withDefault('Alice');
    $this->assertSnapshot('textprompt/default', $prompt->view());
}
```

## 2. Property-Based Testing
Add Hypothesis-style property tests for:
- Multibyte input (emoji, CJK, ZWJ sequences)
- Very long input (10k+ characters)
- History edge cases (empty, single entry, duplicates)
- Undo/redo at boundaries

## 3. Fuzz Testing for Input Decoding
Fuzz test the input driver with random byte sequences to ensure no crashes or hangs.

## 4. Terminal Compatibility Matrix
Test on multiple terminals: xterm, gnome-terminal, Konsole, Windows Terminal, iTerm2, macOS Terminal.app. Document known issues.

---

# Ecosystem / Integration Opportunities

## 1. sugar-glow Integration
`sugar-readline` is the natural input library for sugar-glow-powered syntax highlighting. When sugar-glow is ready, the integration is:
```php
$prompt = TextPrompt::new('Enter code: ')
    ->withHighlight(new SugarGlowHighlight('php'));
```

## 2. sugar-prompt / candy-forms Bridge
sugar-readline prompts could serve as low-level inputs for `candy-forms` field types:
```php
// sugar-readline TextPrompt as candy-forms Input field source
class ReadlineInputField implements Field {
    private TextPrompt $prompt;
    // ... implements Field interface using TextPrompt
}
```

## 3. candy-kit Command Palette Integration
The command palette in `candy-kit` could use sugar-readline prompts for fuzzy-filtered command selection.

## 4. ReactPHP Integration
For async completion/suggestion fetching:
```php
// Async suggestion provider
$prompt = TextPrompt::new('Search: ')
    ->withSuggestionProvider(async function(string $prefix) {
        return yield $this->searchApi->search($prefix);
    });
```

---

# Notable PRs / Issues / Discussions

## From `charmbracelet/bubbles` ecosystem:

### Issue #1652: Textarea infinite loop on empty input word navigation (Critical)
- **Summary:** `wordLeft()` has unconditional loop with only exit being finding non-space rune. When textarea empty, infinite loop.
- **Relevance:** SugarCraft TextareaPrompt has same loop structure — boundary check before iteration is essential
- **Fix:** Add `if ($this->row === 0 && $this->col === 0) { return; }` before navigation loops
- **Source:** `docs/repo_map/pr_charmbracelet_bubbles.md`

### Issue #263: Control characters in clipboard input cause corruption
- **Summary:** Bracketed paste support needed; control characters corrupt state
- **Relevance:** SugarCraft must sanitize clipboard input before processing
- **Fix:** Implement bracketed paste mode detection and control char stripping
- **Source:** `docs/repo_map/pr_charmbracelet_bubbles.md`

### Issue #301: Textarea performance — 42s → 3s via memoization
- **Summary:** Pasting 1000 chars at 72 char width took 42 seconds. Memoizing wrap() results reduced to 3s (93% improvement)
- **Relevance:** When TextareaPrompt implements soft-wrap, memoization is critical for performance
- **Source:** `docs/repo_map/pr_charmbracelet_bubbles.md`

### Issue #810: List paginator string concatenation performance
- **Summary:** 8000 item list had lag due to string concatenation in pagination dots
- **Fix:** Use `strings.Builder` (or PHP `implode()` from array)
- **Relevance:** SelectionPrompt pagination uses similar pattern
- **Source:** `docs/repo_map/pr_charmbracelet_bubbles.md`

### Issue #566: AdaptiveColor causing freezes
- **Summary:** `AdaptiveColor` style detection during `View()` caused hangs
- **Lesson:** Never compute styles during `View()` — pre-compute and cache
- **Source:** `docs/repo_map/pr_charmbracelet_bubbles.md`

### Issue #823: Viewport softwrap performance refactor (35% CPU, 38% mem reduction)
- **Summary:** Inefficient allocations, re-invocations of heavy methods, re-processing data
- **Lesson:** Profile before optimizing; cache wrapped lines; invalidate only on content/width change
- **Source:** `docs/repo_map/pr_charmbracelet_bubbles.md`

## From `charmbracelet/bubbletea` ecosystem:

### Issue #1627: Terminal capability query leak on short-lived programs
- **Summary:** Async capability queries (mode 2026, mode 2027) leak escape sequences after exit
- **Relevance:** If sugar-readline queries terminal capabilities, same leak could occur
- **Fix:** Handle capability queries synchronously or provide opt-out
- **Source:** `docs/repo_map/pr_charmbracelet_bubbletea.md`

### Issue #1599 / #1690: Data races in renderer
- **Summary:** `lastView` read without mutex in `onMouse()` caused race with render loop
- **Lesson:** Any state shared between input and render loops needs mutex protection
- **Source:** `docs/repo_map/pr_charmbracelet_bubbletea.md`

---

# Recommended Roadmap

## Immediate Wins (0.1.x)

1. **Fix boundary checks** in TextareaPrompt word navigation loops (issue #1652 pattern)
2. **Add `withHelp()` built-in help view** — low effort, high discoverability impact
3. **Confirmation submit feedback** — flash error when submit fails on MultiSelect
4. **Escape sequence stripping** before text truncation/measurement

## Medium-Term (0.2.x - 0.3.x)

5. **Style system integration** — adopt `candy-sprinkles/Style` objects instead of ANSI strings
6. **Cursor blink** — use candy-forms Cursor pattern or timer-based blink
7. **Input driver** — create `TtyInputDriver` or integrate with `candy-vt`
8. **Mouse support** — click to position cursor, click to select items
9. **Fuzzy filtering** — adapt Smith-Waterman from `candy-forms` for SelectionPrompt

## Major Architectural (0.4.x - 0.5.x)

10. **Textarea soft-wrap** with memoized line wrapping
11. **Vi visual mode selection range**
12. **PromptRenderer** with real-time cursor blink
13. **sugar-glow integration** when available

## Experimental (Future)

14. **Prompt composition** — compose multiple prompts into a form
15. **Async suggestion provider** — ReactPHP-powered async completions
16. **SQLite-backed history** — cross-session persistent history

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|---|---|---|---|---|
| Input driver integration | Critical | High | Medium | P0 |
| Style system overhaul | High | Medium | Low | P0 |
| Cursor blink animation | Medium | Medium | Low | P1 |
| Mouse support | High | Medium | Medium | P1 |
| Textarea soft-wrap | High | High | Medium | P1 |
| Fuzzy filtering | High | Medium | Low | P1 |
| Built-in Help view | Medium | Low | Low | P1 |
| sugar-glow integration | Medium | Medium | Medium | P2 |
| Vi visual mode | Medium | Medium | Low | P2 |
| Confirmation submit feedback | Low | Low | Low | P2 |
| Undo/redo for MultiSelect | Low | Low | Low | P3 |
| Partial block progress | Low | N/A | N/A | N/A |

---

# Final Strategic Assessment

`sugar-readline` occupies a valuable niche in the SugarCraft ecosystem as the **lightweight, single-shot prompt library** — complementary to, not competitive with, the full form framework `sugar-prompt`/`candy-forms`. Its pure-PHP, dependency-free design is a significant engineering achievement that makes it trivially embeddable.

The most critical gap is **input handling** — the library cannot read actual terminal input today. This is the single blocker preventing real-world usage. Fixing this requires either creating a new `candy-readline` input driver package or clearly documenting how to wire `candy-vt` into these prompts.

The second most impactful improvement is the **style system** — hardcoded ANSI codes are a maintainability liability and prevent proper theming, color profile adaptation, and visual polish. Adopting `candy-sprinkles/Style` would bring sugar-readline in line with the rest of the ecosystem.

**Key differentiators to preserve:**
1. True immutability — unique among TUI prompt libraries
2. Pure PHP, zero external dependencies
3. Unicode-aware throughout
4. ModeInterface abstraction for Vi/Emacs/custom modes

**Key differentiators to build:**
1. First-class input driver story (vs bubbles' Go-specific event loop)
2. Better PHP integration (generators for history, WeakMap for memoization)
3. ReactPHP async integration for suggestions/history
4. Superior fuzzy matching (leverage PHP's mb_* functions)

The `erikgeiser/promptkit` upstream is well-matched to PHP's capabilities. The port is structurally complete for the basic feature set. The remaining work is refinement: better styling, real input handling, cursor animation, and soft-wrap.
