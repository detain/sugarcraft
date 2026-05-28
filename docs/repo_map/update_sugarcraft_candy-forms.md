# Overview
`candy-forms` is the foundational library for form primitive widgets in the SugarCraft monorepo. It provides low-level building blocks (TextInput, TextArea, ItemList, Viewport, FilePicker) and the `Field` interface + `Form` compositor that compose into higher-level prompt libraries. Status is 🟡 (in progress) with extraction from `sugar-bits` + `sugar-prompt` underway but gaps remaining in tests and demos.

**Biggest opportunity areas:**
- Snapshot tests for ANSI render outputs (zero currently exist)
- VHS demo tapes demonstrating each field type
- TextArea soft-wrap line tracking for proper cursor positioning
- Matched indices in FuzzyMatcher for filter-highlighting UI

**Biggest missing capabilities:**
- Soft-wrap with LineInfo cursor tracking (upstream Bubbles has complex line tracking)
- Case transforms and character transpose keyboard shortcuts
- Full accessibility mode beyond `Form::withAccessible()`
- Template/result formatting system (promptkit-style)

---

# Internal Capability Summary

## Current Architecture

### Primitives Layer (`src/`)
| Component | File | Key Features |
|---|---|---|
| `TextInput` | `TextInput/TextInput.php` | Multibyte-safe, vim mode, history, suggestions, restrict regex, validation timing |
| `TextArea` | `TextArea/TextArea.php` | Line-based storage, dynamic height, external editor (`$EDITOR`), line numbers |
| `ItemList` | `ItemList/ItemList.php` | Filtering mode, infinite scrolling, status messages |
| `Viewport` | `Viewport/Viewport.php` | Smooth scroll lerp, mouse wheel, horizontal scroll, scrollbar modes |
| `FilePicker` | `FilePicker/FilePicker.php` | Sorting (name/size/mtime), hidden files, icon/size display |
| `Cursor` | `Cursor/Cursor.php` | Blink state machine via Cmd::tick |
| `Spinner` | `Spinner/Spinner.php` | 11 style variants, tick-based animation |
| `Scrollbar` | `Scrollbar/Scrollbar.php` | Thumb/track chars, ScrollbarState tracking |

### Form Engine Layer (`src/`)
| Component | File | Key Features |
|---|---|---|
| `Field` interface | `Field/Field.php` | 13-method contract: key/value/focus/blur/update/view/isFocused/getTitle/getDescription/getError/skippable/consumes/isHidden |
| `Field\Input` | `Field/Input.php` | Wraps TextInput, validator chaining, async/fuzzy suggestions |
| `Field\Text` | `Field/Text.php` | Wraps TextArea, multi-line |
| `Field\Select` | `Field/Select.php` | Wraps ItemList, fuzzy/async, BackedEnum coercion |
| `Field\MultiSelect` | `Field/MultiSelect.php` | Checkbox model, min/max constraints |
| `Field\Confirm` | `Field/Confirm.php` | Yes/no, y/n keys, arrow toggle |
| `Field\Note` | `Field/Note.php` | Read-only, skippable |
| `Field\FilePicker` | `Field/FilePicker.php` | Wraps FilePicker widget |
| `Form` | `Form.php` | Groups compositor, initCmd transport, consumes() routing, typed accessors |
| `Group` | `Group.php` | Per-page metadata, hideFunc, theme override |
| `KeyMap` | `KeyMap.php` | Rebindable nav/submit/abort (list-of-predicates structure) |
| `Theme` | `Theme.php` | 7 presets (ansi, plain, charm, dracula, catppuccin, base16, base) |

### Validation Layer (`src/Validator/`)
`Validator` interface returns `true|string`. Built-ins: `Required`, `Email` (filter_var), `MinLength`, `MaxLength`, `Pattern` (preg_match). Chaining via `buildChainedValidator()` — first error wins.

### Fuzzy Matching (`src/Fuzzy/`)
Custom Smith-Waterman implementation with adjacent bonus scoring. Returns scored candidates but **not** matched character indices for highlighting.

## Strengths
1. **Clean Field abstraction** — 13-method interface is minimal and uniform; Form is genuinely generic
2. **Immutable with mutate()** — `readonly` properties + `with*()` pattern throughout
3. **consumes() routing** — key insight from huh allowing heterogeneous fields in one Form
4. **Trait composition** — `HasHideFunc` and `HasDynamicLabels` cleanly separated
5. **Multi-page forms** — Group with `withHideFunc()` for wizard flows
6. **Async suggestions** — ReactPHP integration not present in upstream Bubble Tea
7. **Vim mode** — Full vim keybindings (SugarCraft extension, not in upstream)
8. **Smooth scroll** — Viewport lerp animation (not in upstream)

## Weaknesses
1. **No snapshot tests** — ANSI SGR byte assertions absent for all widgets
2. **No VHS demos** — examples/ and .vhs/ directories missing
3. **TextArea soft-wrap gap** — cursor positioning across wrapped lines not implemented
4. **FuzzyMatcher no matched indices** — filter highlighting UI not possible
5. **Smith-Waterman untested at scale** — quality vs sahilm/fuzzy unverified
6. **Async suggestion edge cases** — rapid keystrokes, sequence cancellation on blur untested

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `charmbracelet/huh` | **Critical** — direct upstream for Form/Field architecture | Dynamic forms with `*Func()` + binding invalidation, Eval/Cache system, generic Select[T] | P0 |
| `charmbracelet/bubbletea` | **Critical** — runtime foundation | Elm architecture, Cmd/Batch/Sequence, mouse/focus/paste events | P0 |
| `charmbracelet/gum` | **High** — CLI tool using forms primitives | filter fuzzy matching + matchedRanges for highlighting, PTY handling | P1 |
| `charmbracelet/lipgloss` | **High** — styling foundation | Style system, adaptive colors, border presets, layout utilities | P1 |
| `erikgeiser/promptkit` | **High** — generic type-safe prompts | Generic Selection[T], commonPrefix auto-complete, template rendering | P1 |
| `pterm/pterm` | **Medium** — comprehensive Go TUI | InteractiveSelectPrinter with fuzzy, TextPrinter interface pattern, MapRangeToRange | P2 |
| `textualize/textual` | **Medium** — Python TUI reference | Reactive state + watchers, CSS layout, message pump, Pilot testing | P2 |
| `php-tui/php-tui` | **Medium** — PHP TUI ecosystem | Cassowary layout, Widget/WidgetRenderer trait pattern, buffer diffing | P2 |
| `ratatui/ratatui` | **Low** — Rust upstream for php-tui | StatefulWidget trait, Cassowary constraints, immediate-mode buffer diffing | P3 |

---

# Feature Gap Analysis

## Critical Priority

### 1. Snapshot Tests for Widget Render Outputs
**Description:** No widget currently has golden file assertions on ANSI SGR bytes. All 8 primitives and 7 field types lack regression coverage for their `view()` output.

**Why it matters:** ANSI rendering is fragile — escape sequences can silently break across PHP versions or terminal changes. Without snapshot tests, any regression is invisible until runtime.

**Source:** Standard practice across all compared repos (bubbletea, huh, pterm, ratatui all use golden/snapshot tests).

**Implementation ideas:**
- Create `tests/snapshots/` directory
- Use `vendor/bin/phpunit --filter-snapshot` with a custom assertion
- Export expected `\x1b[...m` bytes as heredoc constants per test
- Use `proc_open('script -q -c "php -r \"echo \$sgrBytes\"" /dev/null` for consistent terminal

**Estimated complexity:** Medium — requires establishing a pattern and applying it across ~15 test files.

**Expected impact:** High — enables confident refactoring and regression detection.

---

### 2. TextArea Soft-Wrap Line Tracking
**Description:** Bubble Tea's textarea maintains complex `LineInfo` accounting for cursor positioning across soft-wrapped lines. PHP port stores flat lines only; cursor positioning in wrapped content is incorrect.

**Why it matters:** Any TextArea with content width exceeding terminal width and wrapping produces incorrect cursor position, making text editing unusable in real terminals.

**Source:** `charmbracelet/bubbles` textarea.Model — `lineInfo` struct with Width, Height, CharWidth, ColumnOffset, RowOffset, CharOffset.

**Implementation ideas:**
- Implement `LineInfo` struct mirroring bubbletea's approach
- Track character offset → visual position mapping
- Use `mb_strwidth()` for character width (single vs double-width CJK)
- Reconstruct cursor position from (row, col) + wrapping rules

**Estimated complexity:** High — requires understanding bubbletea's line-wrapping algorithm in detail.

**Expected impact:** Critical for TextArea usability in production.

---

### 3. VHS Demo Tapes
**Description:** No `examples/` directory or `.vhs/` demo tapes exist for candy-forms. CI cannot render visual verification GIFs without these.

**Why it matters:** SugarCraft's CI validates visual output via VHS workflow; without .tape files, no visual regression exists for forms.

**Source:** All other non-exempt libs in SugarCraft should have .vhs/ demos.

**Implementation ideas:**
- Create `candy-forms/.vhs/` with `examples/` directory
- VHS tapes for: Input (with validation), TextArea, Select, MultiSelect, Confirm, FilePicker
- Each tape: `Set Theme "TokyoNight"`, 800x480 dims, `Type "php examples/<demo>.php"`
- Add slug to `.github/workflows/vhs.yml` hand-maintained `all=(...)` array

**Estimated complexity:** Low — well-established pattern in monorepo.

**Expected impact:** Medium — enables visual CI and demonstrates library usage.

---

## High Priority

### 4. FuzzyMatcher Matched Indices
**Description:** `FuzzyMatcher::match()` returns scored candidates but not the positions of matched characters needed to render highlighted filter matches.

**Why it matters:** ItemList filter mode shows all items but cannot highlight which characters matched — degrading UX vs gum which uses `sahilm/fuzzy` matchedRanges.

**Source:** `charmbracelet/gum` filter.go uses `fuzzy.Find()` and `matchedRanges()` for highlighting.

**Implementation ideas:**
- Return a `struct { string value, float score, list<[start,end]> indices }` from match
- Implement Smith-Waterman traceback to record match positions
- Provide `highlightMatch(string, list<[start,end]>)` helper for view rendering

**Estimated complexity:** Medium — requires traceback implementation alongside forward pass.

**Expected impact:** High — enables proper filter highlighting in Select/ItemList.

---

### 5. Smith-Waterman Quality Verification
**Description:** Custom Smith-Waterman implementation is untested at scale (thousands of candidates). Quality vs `sahilm/fuzzy` library unverified.

**Why it matters:** At large candidate counts, ranking quality directly affects user experience in Select and ItemList filtering.

**Source:** `sahilm/fuzzy` Go library is the reference implementation; both gum and bubbletea use it.

**Implementation ideas:**
- Benchmark against Go `sahilm/fuzzy` output for same inputs (via FFI/exec of Go binary)
- Add large-scale test fixtures with known good outputs
- Consider switching to `sahilm/fuzzy` via FFI if quality significantly better

**Estimated complexity:** Medium — requires benchmarking infrastructure and test fixtures.

**Expected impact:** Medium — current implementation likely adequate but unverified.

---

### 6. Async Suggestion Edge Cases
**Description:** `withAsyncSuggestions()` in Field\Input and Field\Select uses ReactPHP promises + debounce timers. Edge cases (rapid keystrokes, sequence cancellation on blur) may need coverage.

**Why it matters:** Production use with fast typers or blur during async fetch could produce race conditions.

**Source:** Standard async pattern concern; upstream bubbletea/huh don't have async suggestions (synchronous only).

**Implementation ideas:**
- Add scripted tests with rapid KeyMsg sequences asserting no crashes
- Test blur during in-flight promise asserting no late update
- Add integration tests using ReactPHP's event loop

**Estimated complexity:** Medium — requires understanding ReactPHP promise cancellation.

**Expected impact:** Medium — likely works but untested.

---

### 7. Case Transforms and Character Transpose
**Description:** TextArea lacks upstream keyboard shortcuts: `Alt+U/L/C` for case transforms (upper/lower/title) and `Ctrl+T` for character transpose.

**Why it matters:** Standard terminal text editing conventions; users expect these in text areas.

**Source:** `charmbracelet/bubbles` textarea.go — `alt+u`, `alt+l`, `ctrl+t` key handlers.

**Implementation ideas:**
- Add `TextArea::withCaseTransforms(bool)` and `TextArea::withTranspose(bool)`
- Map key handlers in `update()` matching bubbletea logic
- Title case: capitalize first char of first word, lowercase rest

**Estimated complexity:** Low — straightforward string transforms.

**Expected impact:** Medium — improves text editing ergonomics.

---

## Medium Priority

### 8. Error Summary Rendering
**Description:** `Form::withErrorSummary()` exists but no documentation on how it renders or test of its output.

**Why it matters:** Accessibility and UX — forms should show all errors at submit time, not just first.

**Source:** `charmbracelet/huh` form.go — error summary shows all failed field errors.

**Implementation ideas:**
- Document error summary rendering behavior
- Add snapshot test for form with multiple validation errors
- Ensure error summary shows key + error message for each failed field

**Estimated complexity:** Low — feature exists, needs docs + tests.

---

### 9. Dynamic Form Binding Invalidation
**Description:** huh uses `Eval`/`Cache` with hash-based binding change detection for `*Func()` dynamic fields. candy-forms traits (`HasDynamicLabels`) exist but caching/invalidation pattern not documented.

**Why it matters:** Performance — without caching, dynamic labels recompute on every render even if inputs unchanged.

**Source:** `charmbracelet/huh` eval.go — `shouldUpdate() (bool, uint64)` with hash-based invalidation.

**Implementation ideas:**
- Document `HasDynamicLabels` cache behavior and invalidation strategy
- Add optional hash-based invalidation for expensive label computations
- Benchmark dynamic form rendering at scale

**Estimated complexity:** Medium — requires design and testing of cache invalidation approach.

---

### 10. Template/Result Formatting
**Description:** promptkit uses `text/template` for result templates; huh uses Lip Gloss styling for final output. candy-forms has no equivalent.

**Why it matters:** Production forms often need structured output formatting beyond raw values (e.g., JSON, formatted strings).

**Source:** `erikgeiser/promptkit` — result templates with `text/template`.

**Implementation ideas:**
- Add `Form::withResultTemplate(string $template)` accepting `{{key}}` placeholders
- Use PHP's `Template` class or compiled templates
- Document use cases: JSON export, human-readable summaries

**Estimated complexity:** Medium — requires template parsing and value injection.

---

## Low Priority

### 11. KeyMap Per-Field Rebinding
**Description:** KeyMap on Form is global; per-field keymaps (huh-style) not supported.

**Why it matters:** Complex forms may need field-specific navigation (e.g., vim mode in TextArea, different shortcuts in Select).

**Source:** `charmbracelet/huh` keymap.go — per-field nested keymaps.

**Implementation ideas:**
- Add `Field::withKeyMap(KeyMap)` override per field
- Merge field KeyMap with form KeyMap in `Form::update()`
- Document precedence rules

**Estimated complexity:** Medium — affects Form::update() routing logic.

---

### 12. Multi-Select Keyboard Shortcuts
**Description:** huh's MultiSelect supports `Ctrl+A` (select all), `Ctrl+N` (none). candy-forms MultiSelect has basic checkbox navigation only.

**Why it matters:** Power user efficiency — bulk select/deselect without clicking.

**Source:** `charmbracelet/huh` field_multiselect.go — `selectAll`/`deselectAll` keybindings.

**Implementation ideas:**
- Add `MultiSelect::withSelectAllKey(string)` and `withSelectNoneKey(string)` 
- Map `Ctrl+A` and `Ctrl+N` in update() handler
- Test with large selection sets

**Estimated complexity:** Low — straightforward keyboard handling.

---

### 13. Progress Indicator for Async Operations
**Description:** Async suggestions show no progress indicator while loading. pterm/gum show spinners during background ops.

**Why it matters:** User feedback during async operations — unclear if suggestion fetch is in progress.

**Source:** `pterm/pterm` spinner + `charmbracelet/gum` spin command.

**Implementation ideas:**
- Add `Field\Input::withLoadingIndicator(Spinner)` during async suggestion fetch
- Render spinner in view() while `this->loading === true`
- Use existing Spinner component from candy-forms

**Estimated complexity:** Low — reuse existing Spinner.

---

# Algorithm / Performance Opportunities

## Smith-Waterman vs Fuzzy Library
**Current approach:** Custom Smith-Waterman with adjacent bonus scoring, O(mn) per candidate.

**External approach (sahilm/fuzzy):** Go library with optimized ranking and matched indices — used by gum and bubbletea's filter.

**Why external is better:** Well-tested at scale in production (gum has 8.7k stars); matched indices enable highlighting UI; likely more optimized.

**Tradeoffs:** Adding Go FFI dependency vs pure PHP reimplementation; FFI adds platform complexity.

**Applicability:** Would improve ItemList filtering quality for Select and ItemList. Consider a two-pass: quick prefix filter then Smith-Waterman rerank.

---

## Immediate-Mode Buffer Diffing
**Current approach:** `view()` string concatenation on every render — no diffing.

**External approach (ratatui, php-tui):** Buffer cell diffing — only changed cells written to terminal.

**Why external is better:** Dramatically reduces terminal I/O for complex forms — only changed regions update.

**Tradeoffs:** Requires significant architectural change (retained buffer model vs string concatenation); complicates view() implementation.

**Applicability:** Less critical for forms than for complex dashboards — form fields are typically small regions. Defer unless performance issues observed.

---

## Cassowary Constraint Solver
**Current approach:** No layout solver — explicit width/height passed to primitives.

**External approach (ratatui, php-tui):** Cassowary constraint solver for responsive layouts.

**Why external is better:** Enables true responsive forms that adapt to terminal resize without manual calculation.

**Tradeoffs:** Significant implementation complexity; php-tui uses external `php-tui/cassowary` package.

**Applicability:** Would benefit Group-level layout (multi-page form widths). Consider for future `sugar-boxer` (layout) integration.

---

# Architecture Improvements

## 1. State ErrorMap Type
`Form::$errors` is `array<string, string>` (key → error). Should be `array<string, non-empty-string>` to reflect that errors are always non-empty when present.

## 2. Generic Field Type Safety
Go's huh uses `Select[T comparable]` for compile-time type safety. PHP lacks generics. Workaround: `Field\Select::withEnum(BackedEnum::class)` already exists — document this pattern clearly.

## 3. Form::initCmd Transport Clarity
The pattern where `focus()` Cmd is stored in `initCmd` and returned from `Form::init()` is elegant but poorly documented. Add inline documentation explaining the transport mechanism.

## 4. Separating Primitive vs Field Concerns
Currently Field implementations wrap primitives (TextInput → Field\Input). Consider if all Field methods should be on the primitive itself, with Field as a thin adapter. Current design mirrors bubbletea/huh but adds indirection.

## 5. Error Handling in FilePicker
`FilePicker::scandir()` failures are captured silently (returns empty array). Consider exposing errors via a `?string $lastError` field or logging.

---

# API / Developer Experience Improvements

## 1. Unified Field Builder Pattern
All field types use slightly different constructor patterns:
- `Input::new('key')` — static factory
- `Select::new('key')` — static factory  
- `Confirm::new('key')` — static factory

But initialization differs: `Input` uses `withTitle()` chain, `Select` uses `options()` after construction. Document and potentially normalize.

## 2. Typed Form Accessors
`Form::getString(key)` returns `?string`, `getInt(key)` returns `?int`, etc. Add `getEnum<T>` for BackedEnum coercion:

```php
public function getEnum<T as BackedEnum>(string $key): ?T {
    $value = $this->values[$key] ?? null;
    return $value !== null ? T::from($value) : null;
}
```

## 3. Form Validation Result Object
Currently `Form::validate()` returns void and populates `Form::$errors`. Consider returning a `ValidationResult` object with `isValid(): bool`, `errors: array<string, string>`, `validValues: array<string, mixed>`.

## 4. Field Description as Markdown
huh supports markdown in descriptions (rendered via glamour). Consider `Field::withDescription(string $md, bool $renderMarkdown = true)` using `candy-shine`.

---

# Documentation / Cookbook Opportunities

## 1. README.md is Minimal
Current README (70 lines) is extraction announcement. Needs comprehensive usage docs:
- Full quickstart with all field types
- Multi-group wizard example
- Custom validator example
- Async suggestion example
- Theming example with all 7 presets

## 2. CALIBER_LEARNINGS.md has Only 4 Entries
Document:
- Spinner extraction pattern (already documented)
- class_alias shim pattern (already documented)
- mutiate() immutability pattern
- Field vs primitive separation
- Async suggestion ReactPHP pattern

## 3. API Reference Documentation
No online API docs exist. Consider generating from source via `phpdocumentor` or similar.

## 4. Cookbook / Examples
No `examples/` directory. Add:
- `examples/wizard.php` — multi-step form
- `examples/async-suggestions.php` — async autocomplete
- `examples/custom-validator.php` — validation chaining
- `examples/dynamic-labels.php` — `*Func()` pattern
- `examples/themed.php` — all 7 theme presets

---

# UX / TUI Improvements

## 1. Accessibility Mode Audit
`Form::withAccessible()` exists but:
- Not tested with screen readers
- No documentation on what accessible mode renders differently
- No tests asserting specific accessibility output

## 2. Help Display System
huh's KeyMap includes help text (`key.NewBinding(key.WithKeys("..."), key.WithHelp("...", "..."))`). candy-forms KeyMap doesn't surface help text in view.

## 3. Vim Mode Help Overlay
TextInput has full vim mode but no `?` help overlay showing available commands (standard vim convention).

## 4. Form Progress Indicator
Multi-page forms (wizard) have no progress indicator (e.g., "Step 2 of 4"). huh does not either, but PromptKit could inspire a pattern.

## 5. Error Animation
Validation errors appear instantly. Consider shake animation (ASCII art) for error display — pterm has no animation but textual's message system could inspire.

---

# Testing / Reliability Improvements

## 1. Snapshot Tests (Critical Gap)
Zero ANSI render snapshots exist. Add for:
- Each primitive view() output
- Each field type view() output  
- Form with various field combinations
- Theme variations

## 2. Behaviour Tests with Scripted Input
Drive `update()` with `KeyMsg` sequences and assert `[Model, ?Cmd]` tuples. Pattern exists but not comprehensive.

## 3. Coercion Edge Cases
Test validators with:
- Negative/oversized index on Select
- Empty string for required fields with whitespace
- Null values through typed accessors
- Enum coercion with invalid value

## 4. Concurrent Access Safety
ReactPHP integration means form may be updated from multiple event loop ticks. No tests for concurrent update safety.

## 5. Terminal Resize Handling
Viewport and FilePicker use terminal dimensions. No tests for resize events mid-interaction.

---

# Ecosystem / Integration Opportunities

## 1. sugar-prompt Integration
`sugar-prompt` (huh equivalent) should be the primary consumer of candy-forms. Currently class_alias shims point back. Ensure `sugar-prompt` properly tests the full form flow.

## 2. sugar-bits Integration
Some components (e.g., progress bars, tables) may need forms for configuration. Document integration pattern.

## 3. candy-shell Integration
TTY initialization (raw mode, alternate screen) happens in `candy-core` runtime. candy-forms should work seamlessly with `candy-shell` TTY setup.

## 4. Third-party Validator Library
Current validators are basic (Required, Email, MinLength, etc.). Consider a `sugarcraft/candy-forms-validators` companion package with:
- URL validation
- IP address validation  
- Date/time parsing
- Custom regex with named groups
- Cross-field validation (e.g., "password === confirm")

## 5. ReactPHP Integration Documentation
Document how async suggestions integrate with ReactPHP event loop. Current implementation is the only SugarCraft lib with ReactPHP promises — needs explicit documentation.

---

# Notable PRs / Issues / Discussions

## charmbracelet/huh #272 — KeyMap Override
User-requested feature: per-field keybinding customization. huh initially had global KeyMap only. This was implemented in huh as `WithKeyMap()` on Field. candy-forms already implements this pattern via `Form::withKeyMap()`. Lesson: keymap flexibility was important enough to warrant a dedicated issue — good that candy-forms has it.

## charmbracelet/huh Dynamic Forms Discussion
huh's `*Func()` pattern with hash-based cache invalidation (`shouldUpdate()`) is the most sophisticated dynamic form system in the ecosystem. candy-forms traits (`HasDynamicLabels`) provide the interface but not the caching. This is the main architectural gap vs huh.

## erikgeiser/promptkit Common Prefix Auto-Complete
The `commonPrefix()` algorithm (sort then compare first+last) is elegant O(n log n) for finding longest common prefix among suggestions. Used in promptkit's auto-complete. Should be considered for candy-forms suggestions.

## pterm #352 — Interactive Printer Keyboard Handling
pterm's interactive printers use `atomicgo.dev/keyboard` which is a blocking call. This is a known limitation — concurrent interactive components not supported. candy-forms avoids this by using Bubble Tea's message-passing model. This is a structural advantage of the Elm architecture.

## textualize/textual CSS Layout
textual's CSS-based layout (`display: flex`, `display: grid`) represents the most sophisticated TUI layout system available. While not directly portable, should inform future `sugar-boxer` (layout) development.

---

# Recommended Roadmap

## Immediate Wins (0-2 weeks)
1. **Add VHS demos** for each field type — enables visual CI
2. **Add snapshot tests** for TextInput and TextArea view() output
3. **Document CALIBER_LEARNINGS.md** with all known patterns
4. **Add commonPrefix()** utility from promptkit for auto-complete

## Medium-Term Improvements (1-3 months)
5. **TextArea soft-wrap line tracking** — critical for real usage
6. **FuzzyMatcher matched indices** — enables filter highlighting
7. **Expand snapshot test coverage** to all 8 primitives
8. **Add examples/ cookbook** with wizard, async suggestions, custom validators
9. **Case transforms and transpose** keyboard shortcuts

## Major Architectural Upgrades (3-6 months)
10. **Eval/Cache dynamic form invalidation** — mirror huh's approach
11. **Template/result formatting** system
12. **Form ValidationResult object** — typed validation return
13. **Per-field KeyMap rebinding** — huh-style
14. **Accessible mode audit + tests**

## Experimental Ideas (6+ months)
15. **Smith-Waterman vs sahilm/fuzzy** benchmarking + potential FFI
16. **Cassowary layout solver** integration for responsive forms
17. **Buffer diffing** for optimized form rendering
18. **candy-forms-validators** companion package

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|---|---|---|---|---|
| Snapshot tests for view() output | High | Medium | Low | P0 — Critical for confidence |
| VHS demo tapes | High | Low | Low | P0 — Enables visual CI |
| TextArea soft-wrap line tracking | Critical | High | Medium | P1 — Critical usability |
| FuzzyMatcher matched indices | High | Medium | Low | P1 — Filter highlighting |
| Async suggestion edge case tests | Medium | Medium | Low | P1 — Reliability |
| README expansion + examples | High | Low | Low | P1 — DX improvement |
| CALIBER_LEARNINGS.md documentation | Medium | Low | Low | P1 — Knowledge capture |
| Case transforms + transpose | Medium | Low | Low | P2 — Ergonomics |
| Error summary rendering | Medium | Low | Low | P2 — UX |
| Template/result formatting | Medium | Medium | Low | P2 — Feature completeness |
| Per-field KeyMap rebinding | Medium | Medium | Low | P2 — Flexibility |
| Smith-Waterman quality benchmark | Medium | Medium | Medium | P3 — Unproven need |
| Cassowary layout solver | Medium | High | High | P3 — Future consideration |
| Buffer diffing optimization | Low | High | High | P3 — Premature optimization |

---

# Final Strategic Assessment

`candy-forms` is a well-architected foundation for form primitives in SugarCraft, achieving a clean port of the Bubble Tea + huh ecosystem's core concepts into idiomatic PHP 8.3+. The `Field` interface abstraction is minimal (13 methods) and genuinely enables generic form composition. The `consumes()` routing pattern is the key architectural insight that allows heterogeneous field types (text input, textarea, select, confirm, etc.) to coexist in one `Form` without keybinding conflicts — this is the design decision that makes huh (and by extension candy-forms) work.

**Primary strengths:**
- Clean immutable pattern with `mutate()` helper throughout
- Trait composition for optional capabilities (`HasHideFunc`, `HasDynamicLabels`)
- Multi-group forms with conditional pages via `Group::withHideFunc()`
- ReactPHP async suggestions (not in upstream — unique to SugarCraft)
- Vim mode on TextInput and smooth scroll on Viewport (SugarCraft extensions)

**Primary gaps vs upstream:**
- No snapshot tests for ANSI rendering outputs (critical)
- TextArea soft-wrap line tracking missing (critical usability)
- FuzzyMatcher lacks matched indices for highlighting
- No VHS demo tapes for visual CI
- Async suggestion edge cases untested
- README and documentation minimal

**Competitive positioning:**
Against `charmbracelet/huh` (Go): Feature parity on form primitives; gaps in dynamic form binding invalidation (huh's Eval/Cache) and generic type safety (Go generics). PHP advantage: ReactPHP async integration for suggestions.

Against `erikgeiser/promptkit` (Go): Feature parity; promptkit's template rendering and common-prefix auto-complete are superior. However, promptkit lacks vim mode and async suggestions.

Against `php-tui/php-tui` (PHP): Different model — widget trait pattern vs Bubble Tea's Model/Update/View. php-tui has superior layout (Cassowary) but no form system equivalent.

Against `textualize/textual` (Python): Python's ecosystem advantage (Rich library, async-first); textual's CSS layout and reactive state are more sophisticated. PHP advantage: ReactPHP integration, stricter immutability.

**Path to 🟢:**
1. Add snapshot tests for all primitives (eliminates regression risk)
2. Add VHS demo tapes for each field type (enables visual CI)  
3. Implement TextArea soft-wrap line tracking (fixes critical usability gap)
4. Implement FuzzyMatcher matched indices (enables filter highlighting)
5. Expand README with comprehensive examples and cookbook

Once these are addressed, candy-forms will be a production-ready, fully-tested form primitive library with clear separation from leaf libs (`sugar-bits`, `sugar-prompt`).
