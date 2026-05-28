# sugar-prompt Update Report

## Overview

**sugar-prompt** is a 🟢 v1-ready PHP port of `charmbracelet/huh` — a form library for building interactive multi-page terminal forms. It provides Input, Text, Select, MultiSelect, Confirm, Note, and FilePicker field types with multi-page Group support, 6 built-in themes, and full key rebinding via `KeyMap`.

**Critical architectural note:** `sugar-prompt` is a deprecated backward-compatibility re-export layer. The actual implementation lives in **`candy-forms`** (`SugarCraft\Forms\*` namespace). The `sugar-prompt/src/` files are thin `class_alias()` wrappers pointing to `candy-forms` — making candy-forms the canonical source of truth.

**Biggest opportunity areas:**
- ReactPHP async integration for genuinely asynchronous suggestions (unique to SugarCraft)
- Smith-Waterman fuzzy matching with custom adjacent bonus scoring
- Vim keybindings in text input (full vim mode)

**Biggest missing capabilities:**
- Snapshot/visual regression tests for all field types
- Matched indices from FuzzyMatcher (UI highlighting in filter results)
- TextArea soft-wrap support for wide Unicode characters
- Template-based rendering (unlike Go's huh which uses Lipgloss directly)

---

## Internal Capability Summary

### Current Architecture

The implementation lives in `candy-forms/src/` with re-exports in `sugar-prompt/src/`:

**Core Components:**
- `Form.php` (862 lines) — Top-level form compositor, implements `Model` interface
- `Group.php` (137 lines) — One page of fields with hide predicate and theme override
- `Field.php` (87 lines) — Field interface with 13 methods: `key()`, `value()`, `focus()`, `blur()`, `update()`, `view()`, `isFocused()`, `getTitle()`, `getDescription()`, `getError()`, `skippable()`, `consumes()`, `isHidden()`
- `Theme.php` (188 lines) — 7 built-in themes with `Style` properties
- `KeyMap.php` (139 lines) — Rebindable key bindings for next/prev/submit/quit

**Field Types:**
- `Field\Input` (489 lines) — Single-line text, wraps `TextInput` widget
- `Field\Text` (160 lines) — Multi-line textarea, wraps `TextArea` widget
- `Field\Select` (334 lines) — Single-choice list, wraps `ItemList` widget
- `Field\MultiSelect` (254 lines) — Multi-checkbox picker (self-contained)
- `Field\Confirm` (161 lines) — Yes/no toggle (self-contained)
- `Field\Note` (124 lines) — Read-only paragraph, skippable by default
- `Field\FilePicker` (125 lines) — Filesystem browser, wraps `FilePicker` widget

**Supporting Systems:**
- `HasDynamicLabels.php` (71 lines) — `*Func()` dynamic label trait
- `HasHideFunc.php` (38 lines) — Runtime visibility predicate trait
- `Fuzzy/FuzzyMatcher.php` (115 lines) — Smith-Waterman local alignment scorer
- `Validator/` — Validator interface + 5 built-ins (Required, Email, MinLength, MaxLength, Pattern)
- `TextInput/` — Single-line text widget with vim mode, history, suggestions
- `TextArea/` — Multi-line text widget
- `ItemList/` — Filterable selectable list
- `FilePicker/` — Filesystem picker widget
- `Cursor/` — Cursor blink state machine
- `Spinner/` — Spinner widget

### Strengths

1. **Clean 13-method Field abstraction** — Every widget implements uniform contract, `Form` is genuinely generic
2. **Immutable with*() pattern** — `readonly` properties + private `mutate()` = truly immutable state
3. **`consumes()` routing** — Key design insight allowing heterogeneous fields in one Form
4. **Trait composition** — `HasHideFunc` + `HasDynamicLabels` cleanly separated via `use`
5. **ReactPHP integration** — Async suggestions using `Loop::addTimer()` debounce + `Deferred` promises
6. **Smith-Waterman fuzzy matching** — Custom impl with adjacent bonus scoring (`+5` consecutive matches)
7. **7 built-in themes** — `ansi()`, `plain()`, `charm()`, `dracula()`, `catppuccin()`, `base16()`, `base()`
8. **Per-form KeyMap rebinding** — Full rebinding without forking runtime
9. **Per-group theme overrides** — `Group::withTheme()` overrides form theme
10. **Multi-group wizard flow** — `Form::groups()` with `advanceGroup()` traverses groups

### Weaknesses

1. **No snapshot tests** — Component render outputs (`\x1b[...m` ANSI bytes) lack golden file assertions
2. **FuzzyMatcher returns no matched indices** — Cannot highlight filter matches in UI
3. **TextArea soft-wrap incomplete** — Flat lines only, no wide Unicode complex line wrapping
4. **No template rendering** — Direct ANSI concat vs upstream's `text/template` approach
5. **Form as only Model** — Cannot embed individual fields as Tea models outside Form context

---

## Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|----------|
| `charmbracelet/huh` | Primary upstream | All field types, Form/Group/Field hierarchy, theming, KeyMap rebinding | Critical |
| `charmbracelet/bubbles` | Widget layer | TextInput, TextArea, ItemList, FilePicker, Cursor, Spinner | Critical |
| `charmbracelet/gum` | CLI wrapper | Shell-friendly prompt patterns, exit codes, pipe composition | High |
| `erikgeiser/promptkit` | Go form lib | Go generics `Selection[T]`, template rendering, auto-pagination | High |
| `textualize/textual` | Python TUI reference | CSS-based styling, reactive state, widget composition | High |
| `ratatui/ratatui` | Rust TUI reference | Cassowary layout, buffer diffing, `StatefulWidget` trait | Medium |
| `php-tui/php-tui` | PHP TUI lib | Cassowary layout, buffer diffing, widget renderer pattern | Medium |
| `KevM/bubbleo` | Navigation | NavStack push/pop, Closable interface, breadcrumb trail | Medium |
| `c9s/CLIFramework` | PHP CLI framework | Hierarchical commands, shell completion, interactive prompts | Medium |
| `treilik/bubblelister` | List component | Prefixer/Suffixer interfaces, viewport cursor offset, concurrent search | Low |
| `charmbracelet/bubbletea` | Core framework | Elm architecture, Cmd/Msg pattern, renderer architecture | Reference |

---

## Feature Gap Analysis

### Critical Priority

#### 1. Snapshot/Visual Regression Tests
- **Title:** Add snapshot tests for all field type renders
- **Description:** No golden file assertions exist for ANSI SGR byte output. Every `view()` method needs a corresponding test asserting exact `\x1b[...m` bytes.
- **Why it matters:** Without snapshot tests, any rendering regression is silent. Forms render complex multi-line ANSI output — regressions in color codes, cursor positioning, or layout break user-facing demos.
- **Source repo:** `charmbracelet/huh` (upstream has comprehensive tests)
- **Source reference:** `sugar-prompt` tests currently only test logic, not rendering
- **Implementation ideas:** Use `vendor/bin/phpunit --snapshot-update` pattern. Store expected SGR bytes in `tests/snapshots/` per field type per theme.
- **Estimated complexity:** Medium — requires writing all snapshots first, then CI enforcement
- **Expected impact:** High — enables confident refactoring of any `view()` method

#### 2. FuzzyMatcher Matched Indices
- **Title:** Return matched character indices from FuzzyMatcher
- **Description:** `FuzzyMatcher::match()` returns scored candidates but not positions for UI highlighting. Upstream `sahilm/fuzzy` returns byte indices for highlighted filter matches.
- **Why it matters:** Cannot implement highlighted filter results in Select or Input with suggestions. Users see matched items but cannot see *why* they matched.
- **Source repo:** `erikgeiser/promptkit` — uses `sahilm/fuzzy`
- **Source reference:** `charmbracelet/gum` filter uses `matchedRanges()` to convert byte offsets to visible char positions
- **Implementation ideas:** Extend `FuzzyMatcher::match()` to return `list<array{string, int, list<int>}}>` with indices. Requires DP table access for traceback.
- **Estimated complexity:** Medium — Smith-Waterman traceback not currently implemented
- **Expected impact:** High — enables highlighted suggestions in filter mode

### High Value Priority

#### 3. Template-Based Rendering
- **Title:** Add optional template rendering system
- **Description:** Upstream huh uses Lipgloss directly for styling. PromptKit uses Go's `text/template` with rich helper functions. SugarCraft uses direct ANSI concat.
- **Why it matters:** Template rendering enables user-defined field layouts without modifying core code. Complex real-world forms often need custom arrangements.
- **Source repo:** `erikgeiser/promptkit`
- **Source reference:** `promptkit/selection/model.go:L99-L128` shows template initialization with `FuncMap`
- **Implementation ideas:** Add `withTemplate(string $tmpl)` to fields. Parse via `SugarCraft\Template::parse()`. Provide field helpers (`{{.Title}}`, `{{.Value}}`, `{{.Error}}`, `{{.Focused}}`).
- **Estimated complexity:** High — requires new `Template` class and per-field template parsing
- **Expected impact:** Medium — niche use case but high for advanced users

#### 4. Auto-Pagination
- **Title:** Add auto-pagination based on terminal height
- **Description:** PromptKit uses binary search to find page size that fits terminal height. SugarCraft's ItemList requires manual `withPageSize()` configuration.
- **Why it matters:** Users shouldn't guess terminal height. Forms should auto-adjust item count to fit available space.
- **Source repo:** `erikgeiser/promptkit`
- **Source reference:** `promptkit/selection/model.go:L194-L202` — `forceUpdatePageSizeForHeight()` using brute force search
- **Implementation ideas:** Add `withAutoPagination(bool)` to Select/MultiSelect. On `WindowSizeMsg`, compute max acceptable page size via binary search.
- **Estimated complexity:** Medium — requires `WindowSizeMsg` handling in form context
- **Expected impact:** High — significantly improves OOTB user experience

#### 5. Common Prefix Auto-Complete
- **Title:** Add common prefix detection for suggestions
- **Description:** PromptKit implements `commonPrefix()` using sorted array endpoints (O(n log n) sort, then single comparison). SugarCraft only has fuzzy and async suggestions.
- **Why it matters:** CLI users expect single-tab completion when only one suggestion matches. Without it, users must manually type complete match.
- **Source repo:** `erikgeiser/promptkit`
- **Source reference:** `promptkit/textinput/autocomplete.go:L78-L99`
- **Implementation ideas:** Add `withCommonPrefix(bool)` to Input/Select. When suggestions reduce to single match, auto-complete.
- **Estimated complexity:** Low — algorithm is trivial, integration is the work
- **Expected impact:** Medium — standard CLI UX expectation

### Medium Priority

#### 6. TextArea Soft-Wrap with Wide Unicode
- **Title:** Implement soft-wrap accounting for East Asian wide characters
- **Description:** PHP port stores flat lines only. Upstream Bubbles `TextArea` maintains complex `LineInfo` accounting for wide Unicode characters across wrapped lines.
- **Why it matters:** CJK users cannot use TextArea properly without wide char support.
- **Source repo:** `charmbracelet/bubbles`
- **Source reference:** `bubbles/textarea.go` — `LineInfo` struct with `startX`, `width`, `charWidth`
- **Implementation ideas:** Track `LineInfo[]` in TextArea. Use `mb_strwidth()` for width calculation. Recalculate on every edit.
- **Estimated complexity:** High — complex state machine with line splitting
- **Expected impact:** Medium — affects specific locale users significantly

#### 7. Closable Interface for Resource Cleanup
- **Title:** Add Closable interface for Group/Form cleanup
- **Description:** bubbleo's `Closable` interface calls `Close()` when items are popped from NavStack. SugarCraft Forms have no cleanup hook.
- **Why it matters:** Applications embedding Forms may need to release resources (cancel async ops, close file handles) when form completes or is dismissed.
- **Source repo:** `KevM/bubbleo`
- **Source reference:** `bubbleo/navstack.go:L93-L96` — `Closable interface { Close() error }`
- **Implementation ideas:** Add `interface Closable { close(): void }`. Call on Form run completion. Allow fields to implement for cleanup.
- **Estimated complexity:** Low — simple interface + call site
- **Expected impact:** Medium — enables resource cleanup in embedded contexts

#### 8. Input History Persistence
- **Title:** Add optional history persistence across sessions
- **Description:** TextInput has history navigation (up/down) but no persistence. PromptKit's TextInput also lacks this.
- **Why it matters:** CLI tools like `gum input --value` benefit from remembering previous inputs across invocations.
- **Source repo:** `charmbracelet/gum`
- **Source reference:** `gum/input/input.go` — stores history but no cross-session persistence
- **Implementation ideas:** Add `withHistoryFile(string $path)` to Input. On blur, append to file. On focus, load file. Dedupe adjacent entries.
- **Estimated complexity:** Medium — file I/O integration
- **Expected impact:** Low — niche but valuable for shell tool authors

### Low Priority

#### 9. Shell Completion Generation
- **Title:** Add shell completion for form field names/values
- **Description:** CLIFramework generates bash/zsh completion. SugarCraft Forms with typed accessors could generate completions automatically.
- **Why it matters:** Users building CLI tools with forms want tab completion for field keys and enum values.
- **Source repo:** `c9s/CLIFramework`
- **Source reference:** `CLIFramework/Completion/ZshGenerator.php` and `BashGenerator.php`
- **Implementation ideas:** Add `Form::completionScript(string $shell): string` generating completion for all field keys + enum options.
- **Estimated complexity:** Medium — requires shell script generation
- **Expected impact:** Low — specialized tooling feature

#### 10. External Editor Integration
- **Title:** Add `withEditor()` to Text field for external $EDITOR
- **Description:** gum's `write` command uses `charmbracelet/x/editor` to open `$EDITOR` for multi-line input. SugarCraft Text has no editor integration.
- **Why it matters:** Long-form text input is cumbersome in-terminal. Users prefer their configured editor.
- **Source repo:** `charmbracelet/gum`
- **Source reference:** `gum/write/write.go` — `startEditorMsg`/`editorFinishedMsg` for PTY-based editor
- **Implementation ideas:** Add `withEditor(bool $useEditor = true)` to Text. On activate, spawn `$EDITOR` via PTY, capture output on close.
- **Estimated complexity:** High — requires PTY handling from `candy-pty`
- **Expected impact:** Low — advanced users only

---

## Algorithm / Performance Opportunities

### 1. Smith-Waterman vs Substring Matching
- **Current approach:** Custom Smith-Waterman implementation with adjacent bonus scoring
- **External approach:** Upstream `sahilm/fuzzy` uses simpler substring scoring with character index tracking
- **Why external is better:** `sahilm/fuzzy` is battle-tested across thousands of Go projects; returns matched indices for free
- **Tradeoffs:** Custom impl enables adjacent bonus (`+5` for consecutive matches) not in upstream
- **Applicability:** SugarCraft's fuzzy is superior for ranking but lacks index reporting

### 2. Auto-Pagination Binary Search
- **Current approach:** Manual `withPageSize()` configuration
- **External approach:** PromptKit brute-forces page size by trying 1..n until fit
- **Why external is better:** Automatic adaptation to terminal height improves OOTB UX
- **Tradeoffs:** Binary search is O(log n) but needs viewport height knowledge
- **Applicability:** Would significantly improve Select/MultiSelect UX

### 3. Common Prefix from Sorted Array
- **Current approach:** No common prefix detection
- **External approach:** Sort suggestions, compare first and last strings
- **Why external is better:** O(n log n) sort enables O(1) prefix detection
- **Tradeoffs:** Sorting may be expensive for large suggestion sets; fuzzy is better anyway
- **Applicability:** Only useful for non-fuzzy exact prefix scenarios

### 4. Buffer Diffing (Rendering)
- **Current approach:** Full re-render on every frame
- **External approach:** Ratatui/php-tui compute cell-level diff and only write changed cells
- **Why external is better:** Massive reduction in terminal I/O for complex forms
- **Tradeoffs:** Requires double-buffering infrastructure in `candy-core` renderer
- **Applicability:** Would benefit any form with static sections (notes, descriptions)

### 5. Cassowary Constraint Solver (Layout)
- **Current approach:** Fixed width/height dimensions
- **External approach:** Ratatui/php-tui use Cassowary for responsive layouts
- **Why external is better:** Enables layouts that adapt to terminal resize without explicit handling
- **Tradeoffs:** Significant complexity; probably belongs in `candy-shine` not forms
- **Applicability:** Would benefit multi-group forms with complex geometry

---

## Architecture Improvements

### 1. Separating Field Interface from Widget Primitives

**Current:** `Field\Input` wraps `TextInput` widget directly in same class
**Problem:** Cannot use `TextInput` as standalone model outside Form context
**Improvement:** Follow bubbles pattern where widget is standalone `Model`, field is adapter

```php
// Current: Field\Input IS a TextInput
// Improved: Field\Input HAS a TextInput, delegates to Model interface
```

**Reference:** `charmbracelet/bubbletea.md` — "Separation of primitive vs. field"

### 2. Form as Composition Root

**Current:** Form is both compositor AND embeddable model
**Problem:** Cannot easily embed form rendering inside larger TUI without Form running the loop
**Improvement:** Separate `FormRenderer` (just view/update) from `FormRunner` (runs loop)

### 3. Message Type Discovery

**Current:** `Msg` classes are concrete, hard to extend
**Problem:** Custom fields need to dispatch custom messages into form's update loop
**Improvement:** Consider a message bus or event dispatcher pattern

**Reference:** `textualize_textual.md` — "Message Pump Pattern" with bubbling

---

## API / Developer Experience Improvements

### 1. Unified Field Builder Pattern

**Current:** Mixed short-form (`title()`, `desc()`) and long-form (`withTitle()`, `withDescription()`)
**Improvement:** Standardize on `with*()` for consistency; short-forms as aliases

**Reference:** `sugarcraft_sugar-prompt.md` notes this in README

### 2. Error Accumulation

**Current:** `validateAll()` returns all errors but individual field validation can stop at first error
**Improvement:** Add `withValidateAll(bool)` to collect ALL field errors before showing

**Reference:** `charmbracelet/huh` — error summary pattern

### 3. Type-Safe Select via BackedEnum

**Current:** `Select::new('x')->withEnum(MyEnum::class)` workaround for PHP's lack of generics
**Improvement:** First-class `Select<MyEnum>` via generic factory or enum-specific field types

**Reference:** `erikgeiser_promptkit.md` — "Generic Type-Safe Prompts"

---

## Documentation / Cookbook Opportunities

### 1. Multi-Step Wizard Pattern
- Conditional group visibility with `withHideFunc()`
- Progress indication in breadcrumb style
- Data validation across group boundaries

**Reference:** `sugarcraft_sugar-prompt.md` — "Multi-group (wizard) flow"

### 2. Async Autocomplete with ReactPHP
- Debounced API fetching
- Loading states
- Error handling for network failures

**Reference:** `sugarcraft_sugar-prompt.md` — "Async Suggestions" section

### 3. Custom Theme Creation
- Composing `Theme` from `Style` building blocks
- Per-group theme overrides
- Accessible mode theming

### 4. Embedded Forms in larger TUIs
- Form as `Model` in a `Program`
- Mixing form fields with custom widgets
- Navigation between form and other views

---

## UX / TUI Improvements

### 1. Focus Transition Animations
- **Current:** Instant focus changes between fields
- **Improvement:** Subtle animation (cursor blink, highlight pulse) on focus change
- **Reference:** `textualize_textual.md` — "Animation System"

### 2. Help Footer Improvements
- **Current:** Simple `?` shows all keybindings
- **Improvement:** Context-aware help showing only relevant keys for focused field
- **Reference:** `charmbracelet/gum` — consistent help display

### 3. Error Highlighting
- **Current:** Error text below field after failed submit
- **Improvement:** Animate error highlight (red background pulse) on validation failure

### 4. Loading States for Async Suggestions
- **Current:** No visual indication during async fetch
- **Improvement:** Spinner or shimmer in suggestions dropdown during fetch

---

## Testing / Reliability Improvements

### 1. Snapshot Tests (Critical)
**Current:** No ANSI byte assertions
**Improvement:** Golden files per field type per theme
**Command:** `vendor/bin/phpunit --snapshot-update` after writing initial snapshots

### 2. Behavior Tests for Key Routing
**Current:** `consumes()` logic tested indirectly
**Improvement:** Explicit tests for key claiming in filter mode, multi-line mode

### 3. Cross-Field Validation Tests
**Current:** `validateAll()` tested at integration level only
**Improvement:** Unit tests for cross-field predicates

### 4. FFI/PTY Test Gating
**Current:** Some tests require syscalls
**Improvement:** `requirePtySyscalls()` gating for FFI-dependent tests

**Reference:** `sugarcraft_sugar-prompt.md` — "FFI tests gate on `requirePtySyscalls()`"

---

## Ecosystem / Integration Opportunities

### 1. Gum CLI Wrapper (`sugar-prompt` → `gum`-equivalent)
- **Current:** `sugar-prompt` is a library, not a CLI
- **Opportunity:** `candy-shell` could provide `sugar` CLI wrapping sugar-prompt fields
- **Reference:** `charmbracelet_gum.md` — 13 commands mapping to form fields

### 2. PHPStorm/IDE Plugin for Form Building
- **Opportunity:** Visual form builder with drag-drop fields
- **Reference:** `textualize_textual.md` — "DevTools" with hot-reloading

### 3. Live Preview CLI Tool
- **Opportunity:** `sugar preview` command showing form in all themes
- **Reference:** `charmbracelet/gum` — animated GIFs in README

### 4. Form Schema / Serialization
- **Current:** Forms built in PHP code
- **Opportunity:** JSON/YAML form schema with field definitions, parsed to Form
- **Reference:** `erikgeiser_promptkit.md` — template-based rendering enables schema

---

## Notable PRs / Issues / Discussions

### charmbracelet/huh #272 — Per-Form KeyMap Override
- **Summary:** Feature request for per-form KeyMap rebinding
- **Status:** Implemented in huh
- **Relevance:** sugar-prompt already has this via `Form::withKeyMap()`
- **Lessons:** This was highly requested; sugar-prompt should emphasize it in docs

### charmbracelet/huh — Dynamic Labels (*Func pattern)
- **Summary:** `withTitleFunc()` / `withDescriptionFunc()` for render-time evaluation
- **Status:** Both upstream and SugarCraft have identical pattern
- **Lessons:** Capturing `$values` in closures is cleaner than Go's binding/invalidation

### erikgeiser/promptkit — Template-Based Rendering
- **Summary:** Uses Go `text/template` with rich FuncMap
- **Status:** Active development, pre-1.0
- **Lessons:** Template rendering enables complex custom UIs without core changes

### textualize/textual — CSS-Based Styling
- **Summary:** TCSS with selectors, properties, hot-reload
- **Status:** Production-ready, 30k+ stars
- **Lessons:** Web developers find CSS styling paradigm intuitive for TUI

### ratatui/ratatui — Buffer Diffing
- **Summary:** Immediate mode rendering with cell-level diff computation
- **Status:** 19.6k stars, production-ready
- **Lessons:** Buffer diffing is essential for performance in complex TUIs

---

## Recommended Roadmap

### Immediate Wins (0-2 weeks)

1. **Snapshot tests** — Add golden files for all field `view()` outputs
2. **Auto-pagination** — Add `withAutoPagination()` to Select/MultiSelect
3. **Common prefix auto-complete** — Single-match auto-completion
4. **FuzzyMatcher indices** — Return matched positions for UI highlighting

### Medium-Term Improvements (1-3 months)

5. **Template rendering** — Optional `withTemplate()` on fields
6. **Closable interface** — Resource cleanup on form completion
7. **Input history persistence** — `withHistoryFile()` for CLI tools
8. **Per-field KeyMap** — Allow individual field key overrides (upstream gap)

### Major Architectural Upgrades (3-6 months)

9. **Buffer diffing renderer** — Double-buffering with cell-level updates
10. **Cassowary layout** — Constraint-based responsive layouts
11. **External editor integration** — `withEditor()` using `candy-pty`
12. **Shell completion generation** — `Form::completionScript()`

### Experimental Ideas (6+ months)

13. **Visual form builder** — GUI tool for drag-drop form construction
14. **JSON schema parsing** — Declarative form definitions
15. **WebAssembly rendering** — Serve forms in browser (reference textual-web)
16. **AI-assisted form building** — Natural language to form schema

---

## Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|-------------|--------|------------|------|----------|
| Snapshot tests | High | Medium | Low | **Critical** |
| FuzzyMatcher indices | High | Medium | Low | **Critical** |
| Auto-pagination | High | Medium | Low | **High** |
| Common prefix auto-complete | Medium | Low | Low | **High** |
| Closable interface | Medium | Low | Low | **Medium** |
| Input history persistence | Medium | Medium | Medium | **Medium** |
| Template rendering | Medium | High | Medium | **Medium** |
| Shell completion | Medium | Medium | Low | **Medium** |
| Buffer diffing renderer | High | High | High | **Low** |
| Cassowary layout | High | High | Medium | **Low** |
| External editor integration | Medium | High | High | **Low** |
| Per-field KeyMap | Medium | Medium | Low | **Medium** |

---

## Final Strategic Assessment

sugar-prompt is a mature, well-architected form library that successfully ports the charmbracelet/huh design to PHP. Its strengths—immutable field state, clean `consumes()` key routing, ReactPHP async integration, and Smith-Waterman fuzzy matching—represent genuine innovations over the upstream Go implementation in several areas.

The most critical gaps are testing-related: **snapshot tests** and **FuzzyMatcher indices** are both high-impact, medium-complexity items that should be addressed immediately. These directly impact developer confidence and user-facing UX respectively.

The medium-term roadmap should focus on UX polish: **auto-pagination**, **common prefix auto-complete**, and **history persistence** are all low-risk improvements that significantly enhance the CLI user experience. The **Closable interface** is a simple addition that enables important embedded use cases.

The most ambitious architectural opportunities—**buffer diffing** and **Cassowary layout**—would bring SugarCraft's rendering infrastructure to parity with ratatui and php-tui. However, these belong in `candy-core` (renderer) and `candy-shine` (layout) respectively, not directly in sugar-prompt. Their value to sugar-prompt is indirect—better rendering for all fields.

The strategic positioning of sugar-prompt is strong: it's the canonical form library for the SugarCraft ecosystem, living atop the foundational `candy-forms` layer. As the ecosystem matures, sugar-prompt will benefit from improvements in all dependent libraries. The deprecation path (direct `SugarCraft\Forms\*` usage) is already clear, making sugar-prompt a stable API surface for users.

**Key recommendations:**
1. **Immediately** add snapshot tests and FuzzyMatcher indices
2. **Focus on** auto-pagination and common prefix for CLI UX parity
3. **Invest in** documentation and cookbook examples for common patterns
4. **Plan for** buffer diffing as part of candy-core renderer improvements
5. **Monitor** upstream huh for features to port (per-form KeyMap already done)
