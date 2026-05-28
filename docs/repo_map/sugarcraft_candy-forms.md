# SugarCraft / candy-forms

## Metadata

- **Library:** `candy-forms`
- **Composer package:** `sugarcraft/candy-forms`
- **Namespace:** `SugarCraft\Forms`
- **Status:** 🟡 in progress (extraction in progress, gaps tracked)
- **Upstream sources:** Extracted from `sugar-bits` + `sugar-prompt`
- **Depends on:** `sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`
- **PHP minimum:** `^8.3`

## Overview

`candy-forms` is the foundational library for form primitive widgets in the SugarCraft monorepo. It provides the low-level building blocks (TextInput, TextArea, ItemList, Viewport, FilePicker) and the `Field` interface + `Form` compositor that compose into the higher-level `sugar-prompt` (which maps to upstream `charmbracelet/huh`). The package status is 🟡 because the extraction from `sugar-bits` and `sugar-prompt` was in progress at the time of this analysis, with some surface area already shipped but gaps remaining in tests and demos.

---

## Package Architecture

### Directory Structure

```
candy-forms/
├── src/
│   ├── Cursor/          # Cursor (blink state machine), Mode enum, BlinkMsg
│   ├── Field/           # Field interface + Confirm, Input, MultiSelect, Note,
│   │                    #   Select, Text, FilePicker field implementations
│   ├── FilePicker/      # FilePicker widget, Entry (file info), SortMode enum
│   ├── Fuzzy/           # Smith-Waterman fuzzy matcher
│   ├── ItemList/        # ItemList widget, Item interface, StringItem
│   ├── Scrollbar/       # Scrollbar component, ScrollbarState
│   ├── Spinner/        # Spinner widget, Style enum, TickMsg
│   ├── TextArea/        # TextArea widget, TextAreaEditedMsg
│   ├── TextInput/       # TextInput widget, EchoMode, Styles, ValidateOn enums
│   ├── Viewport/        # Viewport widget, ViewportTickMsg
│   ├── Validator/       # Validator interface + Required, Email, MinLength,
│   │                    #   MaxLength, Pattern implementations
│   ├── Form.php         # Top-level Form compositor (Groups → Fields → submit/abort)
│   ├── Group.php        # One page of fields in a multi-page Form
│   ├── HasDynamicLabels.php  # Trait for Field *Func() dynamic title/description
│   ├── HasHideFunc.php  # Trait for Field runtime visibility predicate
│   ├── KeyMap.php       # Form-level key bindings (next/prev/submit/abort)
│   ├── Lang.php         # i18n wrapper
│   └── Theme.php        # Stylesheet with 7 presets (ansi, plain, charm,
│                        #   dracula, catppuccin, base16, base)
└── tests/
    ├── Field/           # ConfirmTest, InputTest, SelectTest, TextTest, etc.
    ├── FilePicker/     # FilePickerTest
    ├── Fuzzy/          # FuzzyMatcherTest
    ├── ItemList/       # ItemListTest
    ├── Scrollbar/      # ScrollbarTest
    ├── Spinner/        # SpinnerTest
    ├── TextArea/       # TextAreaTest, TextAreaEditorTest, etc.
    ├── TextInput/      # TextInputTest, VimModeTest, RestrictTest, etc.
    ├── Validator/      # EmailTest, MaxLengthTest, MinLengthTest, etc.
    ├── Viewport/       # ViewportTest, ViewportScrollEndTest, etc.
    └── FormTest.php, FormValidateAllTest.php, etc.
```

---

## Form Primitive Design

### TextInput (`src/TextInput/TextInput.php`)

Single-line text input widget, implements `Model`. Key design features:

- **Multibyte-safe** — uses `mb_substr`/`mb_strlen` throughout, so `cursorPos` is a grapheme index, not byte offset.
- **Echo modes** — `EchoMode::Normal` (default), `EchoMode::Password` (masked), `EchoMode::None` (hidden).
- **Cursor embedded** — composes `Cursor` object for blink animation via `Cmd::tick`.
- **Vim Mode** — full vim-style keybindings in two submodes:
  - Normal mode: `h/l` move, `w/b` word nav, `0/$` line boundaries, `i/a/A/I` enter insert mode, `x` delete char, `u` undo (stubbed).
  - Insert mode: standard editing, `Escape` returns to normal mode.
- **History navigation** — up/down arrow browsing through previous entries (`withHistory()`, `addToHistory()`).
- **Suggestions** — static pool with case-insensitive prefix filter; `withSuggestions()`, `matchedSuggestions()`, `acceptSuggestion()`.
- **Character restrict** — `withRestrict()` regex filter applied per keystroke (`preg_match('/pattern/', $rune)`).
- **Validation timing** — `ValidateOn::None` (immediate, default), `Blur`, `Change`, `Submit`.
- **Char limit** — hard cap applied at insert time.
- **Prefix/suffix** — fixed strings rendered before/after input, not editable.
- **Width cap** — horizontal scroll window when `$width > 0`, cursor stays visible.

Notable: Line 870-881 in `mutate()` re-validates automatically on value change unless `validateOn` is `Blur` or `Submit`.

### TextArea (`src/TextArea/TextArea.php`)

Multi-line text input widget, implements `Model`. Key features:

- **Line-based storage** — internal `$lines: list<string>` array, cursor as `(row, col)`.
- **Multibyte-safe** — same `mb_substr`/`mb_strlen` approach.
- **Dynamic height mode** — `withDynamic(true)` renders only as many rows as content has, capped by `$maxHeight` (mirrors upstream Bubbles #910).
- **External editor** — `Ctrl+O` opens `$EDITOR` via `proc_open`, writes temp file with seed content, reads result back via `TextAreaEditedMsg`.
- **Line gutter** — optional `showLineNumbers()` and `promptFunc(rowIndex, line)` for per-line dynamic prompts.
- **End-of-buffer character** — vim-style `~` for rows beyond last line (default `~`, configurable).
- **Editor extension** — `withEditorExtension('.md')` sets temp filename suffix for `$EDITOR` syntax detection.

### ItemList (`src/ItemList/ItemList.php`)

Selectable, scrollable, filterable list of `Item`s. Key features:

- **Normal mode** — `↑/k`, `↓/j`, `Home/g`, `End/G`, `PgUp/PgDn`, `/` enters filter mode.
- **Filtering mode** — keystrokes append to filter string (case-insensitive substring match against `Item::filterValue()`), `Backspace` deletes char, `Esc` clears, `Enter` exits (keeps filter).
- **Infinite scrolling** — `withInfiniteScrolling(true)` wraps cursor instead of clamping.
- **Status messages** — transient `newStatusMessage()` with expiration time for user feedback.
- **Cursor prefix** — configurable `cursorPrefix`/`unselectedPrefix` glyphs (default `'> '`/`'  '`).

### Viewport (`src/Viewport/Viewport.php`)

Scrollable text area over arbitrarily long content. Key features:

- **Navigation** — `↑/↓/j/k`, `←/→/h/l` horizontal, `PgUp/PgDn`, `Ctrl+U/D` half-page, `Home/End`.
- **Mouse wheel** — configurable delta per notch, bypasses smooth scroll (instant jump).
- **Smooth scroll** — programmatic position changes animate via lerp over 10 frames (~200ms at 20ms/tick).
- **Scrollbar** — two modes: inline paint (built-in thumb/track chars) and injected `Scrollbar` component via `withVerticalScrollbar(Scrollbar)`.
- **Horizontal scroll** — `scrollLeft()`/`scrollRight()` with configurable `horizontalStep` (default 6).

### FilePicker (`src/FilePicker/FilePicker.php`)

Directory browser with filesystem navigation. Key features:

- **Filtering** — `showHidden` toggle, `allowedExtensions` extension whitelist.
- **Sorting** — `SortMode::Name/Size/MTime` with `directoryFirst` (default on) and `reverseSort`.
- **Entry metadata** — size, mtime, hidden flag stored in `Entry`.
- **Icons/size display** — optional `showIcons`, `showSize` rendering.
- **Error handling** — captures `scandir()` failures silently (returns empty).

---

## Field Interface Architecture

The `Field` interface (`src/Field/Field.php`) is the contract all form widgets implement. It is the central abstraction that enables the `Form` compositor to drive heterogeneous field types uniformly.

### Core Methods

| Method | Returns | Purpose |
|---|---|---|
| `key(): string` | Stable ID for `Form::values()` lookup | |
| `value(): mixed` | Current value (type varies by field) | |
| `focus(): array{0:Field,1:?\Closure}` | New focused field + optional Cmd | |
| `blur(): Field` | New unfocused field | |
| `update(Msg $msg): array{0:Field,1:?\Closure}` | Bubble-Tea-style update | |
| `view(): string` | ANSI-rendered multi-line string | |
| `isFocused(): bool` | True while holding keyboard focus | |
| `getTitle(): string` | Static or resolved dynamic title | |
| `getDescription(): string` | Static or resolved dynamic description | |
| `getError(): ?string` | Latest validation error | |
| `skippable(): bool` | Notes/separators skip Tab nav | |
| `consumes(Msg $msg): bool` | True when field claims a key that would otherwise be form-level navigation | |
| `isHidden(array $values): bool` | Runtime visibility predicate | |

### Field Trait Composition

Fields use two traits composed via `use HasHideFunc; use HasDynamicLabels;`:

- **`HasHideFunc`** — provides `withHideFunc(Closure): static` and `isHidden(array $values): bool` for conditional field display.
- **`HasDynamicLabels`** — provides `withTitleFunc(Closure): static`, `withDescriptionFunc(Closure): static`, `resolveTitle(string): string`, `resolveDescription(string): string` for render-time dynamic labels (mirrors huh's `*Func` pattern).

### Field Implementations

| Field | Value type | Notes |
|---|---|---|
| `Field\Input` | `string` | Wraps `TextInput`; supports validator chaining, sync/async suggestions, fuzzy suggestions |
| `Field\Text` | `string` | Wraps `TextArea`; multi-line, consumes Enter/Up/Down |
| `Field\Select` | `string\|BackedEnum` | Wraps `ItemList`; fuzzy/async suggestions, enum coercion |
| `Field\MultiSelect` | `list<string>` | Multiple checkboxes with `withMin()`/`withMax()` constraints |
| `Field\Confirm` | `bool` | Yes/no toggle; direct `y`/`n` keys + arrow toggle |
| `Field\Note` | `null` | Read-only; skippable unless `withNext(true)` |
| `Field\FilePicker` | `?string` | Wraps `FilePicker` widget; consumes Enter/Backspace/Up/Down |

---

## Validation Patterns

### Validator Interface (`src/Validator/Validator.php`)

```php
interface Validator {
    public function validate(string $input): true|string;
}
```

Returns `true` on valid, error message string on invalid.

### Built-in Validators

| Class | Logic |
|---|---|
| `Required` | Returns error if trimmed string is `''` |
| `Email` | `filter_var($v, FILTER_VALIDATE_EMAIL)` |
| `MinLength(int)` | `$v !== '' && mb_strlen($v) < $n` |
| `MaxLength(int)` | `mb_strlen($v) > $n` |
| `Pattern(string)` | `preg_match("/$pattern/", $v)` — raw PCRE, no delimiters |

### Chaining on Input Field

`Field\Input::withValidator()` accepts `Validator|\Closure`. Multiple calls chain via `buildChainedValidator()` — the first error wins:

```php
$field = Input::new('email')
    ->required()
    ->email()
    ->minlength(5);
```

### Validation Timing (TextInput)

`ValidateOn` enum controls when validators run:
- `None` — validate on every edit (immediate, default).
- `Blur` — validate when input loses focus.
- `Change` — validate on every keystroke.
- `Submit` — validate only on Enter keypress.

---

## Composition Model

### Form (`src/Form.php`)

Top-level compositor implementing `Model`. Manages groups, focused field index, submit/abort state.

**Key design decisions:**

- **`groups` + `fieldsByGroup` separation** — `Group` objects carry static metadata (title, description, hideFunc, theme override); `fieldsByGroup` is a `list<list<Field>>` cached for re-render. This avoids recomputing field lists on every render.
- **`initCmd` transport** — the focused field's `focus()` Cmd is stored in `initCmd` and returned from `Form::init()` so the runtime schedules it (cursor blink, autocomplete preload).
- **`consumes()` routing** — before applying form-level nav/submit/abort, `update()` checks whether the focused field claims the key via `consumes()`. This allows `Select` in filter mode to keep Enter/Up/Down and `Text` to keep Enter for newline insertion.
- **Multi-group (wizard) flow** — `Form::groups(Group...)` creates multi-page forms. `advanceGroup()` traverses groups, blurring current and focusing first non-skippable in next. `withHideFunc()` on `Group` enables conditional pages.
- **Typed accessors** — `getString()`, `getInt()`, `getBool()`, `getArray()` with coercion and sensible defaults.
- **`withKeyMap(?KeyMap)`** — mirrors the long-requested huh #272 feature, rebindable nav/submit/abort without forking the runtime.

**Navigation algorithm:**
1. Scan for first non-skippable field in direction from current position.
2. If none found in current group, call `advanceGroup(+direction)`.
3. Blur current field, focus new field, return new field's `focus()` Cmd.

### Group (`src/Group.php`)

One page in a `Form`. Carries title, description, optional `hideFunc` predicate evaluated on page transitions, per-group `showHelp` toggle, and per-group `Theme` override.

---

## Comparison with Mapped Upstream Repos

### charmbracelet/bubbles

`bubbles` provides 14 primitives (TextInput, TextArea, List, Viewport, FilePicker, Spinner, etc.). `candy-forms` has direct ports for most:

| Bubbles | candy-forms | Status |
|---|---|---|
| `textinput.Model` | `TextInput` | 🟢 Complete (vim mode is SugarCraft extension) |
| `textarea.Model` | `TextArea` | 🟡 Missing soft-wrap line tracking, case transforms, transpose |
| `list.Model` | `ItemList` | 🟡 Missing fuzzy library quality (uses custom Smith-Waterman), pagination delegate |
| `viewport.Model` | `Viewport` | 🟢 Complete + smooth scroll extension |
| `filepicker` | `FilePicker` | 🟢 Complete |
| `spinner` | `Spinner` | 🟢 Complete |
| `cursor` | `Cursor` | 🟢 Complete |

Key gaps vs bubbles:
- **TextArea soft-wrap line tracking** — Bubbles maintains complex `LineInfo` (Width, Height, CharWidth, ColumnOffset, RowOffset, CharOffset) for cursor positioning across wrapped lines. PHP port stores flat lines only.
- **Fuzzy filtering quality** — Bubbles uses `sahilm/fuzzy` (Go library producing ranked matches with matched indices). PHP port uses a custom Smith-Waterman implementation in `FuzzyMatcher` — functionally equivalent but untested at scale.
- **TextArea case transforms** (`alt+u/l/c`) and **character transpose** (`ctrl+t`) — not yet ported.

### charmbracelet/huh

`huh` is a form library that builds on bubbles. `candy-forms` is the extraction of huh's form primitives plus some additional primitives. The mapping in MATCHUPS.md (`huh` → `sugar-prompt`) was intended for the full form library; `candy-forms` provides the foundation that `sugar-prompt` will use.

| huh Feature | candy-forms Status |
|---|---|
| Form + Groups + Fields | 🟢 `Form` + `Group` + `Field` interface |
| Input field with suggestions | 🟢 `Field\Input` with async/fuzzy suggestions |
| Text field | 🟢 `Field\Text` |
| Select | 🟢 `Field\Select` with fuzzy/async |
| MultiSelect | 🟢 `Field\MultiSelect` |
| Confirm | 🟢 `Field\Confirm` |
| FilePicker | 🟢 `Field\FilePicker` |
| Note | 🟢 `Field\Note` |
| Theme system | 🟢 `Theme` with 7 presets |
| KeyMap override | 🟢 `Form::withKeyMap()` |
| Accessible mode | 🟢 `Form::withAccessible()` |
| Error summary | 🟢 `Form::withErrorSummary()` |
| Dynamic labels | 🟢 `HasDynamicLabels` trait |

### erikgeiser/promptkit

PromptKit provides selection prompts, text input, text area, and confirmation prompts. Comparison:

| PromptKit | candy-forms | Notes |
|---|---|---|
| Generic `Selection[T]` | `Field\Select` | PromptKit uses Go generics for type-safe bindings; PHP lacks generics |
| `MultiSelection[T]` | `Field\MultiSelect` | Similar constraint model (`min`/`max`) |
| `TextInput` | `Field\Input` | PromptKit has auto-completion with common-prefix detection |
| `TextArea` | `Field\Text` | PromptKit has auto-resize behavior |
| `Confirmation` | `Field\Confirm` | PromptKit has template styles (Arrow, Y/N) |
| Template rendering | Not implemented | PromptKit uses `text/template`; candy-forms uses direct ANSI string concat |

**Key difference:** PromptKit uses Go generics for type-safe selections; PHP's `Field\Select` uses string values with optional `BackedEnum` coercion via `withEnum()`.

### php-school/cli-menu

cli-menu is a mature PHP TTY menu library. Comparison:

| cli-menu | candy-forms | Notes |
|---|---|---|
| Builder pattern | Fluent `with*()` | Similar ergonomics |
| Submenus | Group-level multi-page | Different mental model |
| CheckboxItem/RadioItem | `MultiSelect` / custom | cli-menu uses `CheckboxItem`/`RadioItem` as separate item types |
| Input dialogs | `Field\Input` | cli-menu has `Number` (with up/down), `Password`, `Text`; candy-forms has all via TextInput |
| Validation | `InputIO` validates | Same pattern |
| Style propagation | `Theme` per-group | Different mechanisms |

**Key architectural difference:** cli-menu uses a blocking event loop (`CliMenu::display()` is a `while` loop). `candy-forms` follows Bubble Tea's `Model::update()` pattern where the runtime calls `update()` with each `Msg` — making it composable with ReactPHP event loops.

### pterm

pterm provides 25+ interactive printers including `InteractiveSelectPrinter`, `InteractiveMultiSelectPrinter`, `InteractiveConfirmPrinter`, `InteractiveTextInputPrinter`. These map to:

| pterm | candy-forms |
|---|---|
| `InteractiveSelectPrinter` + fuzzy | `Field\Select` with fuzzy/async suggestions |
| `InteractiveMultiSelectPrinter` | `Field\MultiSelect` |
| `InteractiveConfirmPrinter` | `Field\Confirm` |
| `InteractiveTextInputPrinter` | `Field\Input` |
| `ProgressbarPrinter` | Not in candy-forms (in sugar-bits) |

**Key difference:** pterm's interactive components use `atomicgo.dev/keyboard` for blocking keyboard capture. candy-forms uses the Bubble Tea message-passing model via `candy-core` with `KeyMsg` dispatched from the runtime's TTY reader.

### charmbracelet/gum

gum is a CLI tool wrapping bubbles/huh. candy-forms provides the primitives that would be used to build equivalent PHP CLI tools:

| gum command | candy-forms primitive |
|---|---|
| `gum input` | `Field\Input` |
| `gum write` | `Field\Text` |
| `gum filter` | `ItemList` (filtering mode) |
| `gum choose` | `Field\Select` |
| `gum confirm` | `Field\Confirm` |
| `gum file` | `Field\FilePicker` + `FilePicker` widget |
| `gum pager` | `Viewport` |
| `gum spin` | `Spinner` |

---

## What Remains Incomplete (Status 🟡)

Based on analysis, the following gaps exist:

### Tests
- **Snapshot tests** — component render outputs (`\x1b[...m` ANSI bytes) need golden file assertions to prevent regressions. Currently absent for most widgets.
- **Behaviour tests** — scripted `KeyMsg`/`MouseMsg` driving `update()` with assertions on `[Model, ?Cmd]` tuples are partially present but not comprehensive.
- **Coercion tests** — edge cases for validators (negative/oversized index, empty, null) need clamped/no-op assertions.

### Examples
- No `examples/` directory exists (candy-forms has no examples yet, per the glob result). Demo tape files (`.vhs/*.tape`) and rendered `.gif` files are absent.

### TextArea Gaps
- **Soft-wrap line tracking** — cursor positioning across wrapped lines is not implemented. The upstream Bubbles `LineInfo` accounting for wide Unicode characters across soft-wrapped lines is not present.
- **Case transforms** and **character transpose** (`ctrl+t`) keyboard shortcuts from upstream Bubbles are not implemented.

### Fuzzy Filtering
- **Smith-Waterman quality** — the `FuzzyMatcher` implementation is a custom port. At scale (thousands of candidates), performance and ranking quality against the reference `sahilm/fuzzy` library is unverified.
- **Matched indices for highlighting** — the Go library returns matched character indices for rendering highlighted filter matches. `FuzzyMatcher::match()` returns scored candidates but not the positions for highlighting.

### Async Suggestions
- The `withAsyncSuggestions()` implementation in `Field\Input` and `Field\Select` uses ReactPHP promises and debounce timers. The pattern is implemented but edge cases (rapid keystrokes, sequence cancellation on blur) may need additional test coverage.

### Documentation
- `candy-forms/README.md` is present but likely minimal (extraction in progress).
- `CALIBER_LEARNINGS.md` may not exist yet for this specific library.

---

## Analysis

### Strengths of the Architecture

1. **Clean Field abstraction** — `Field` interface is minimal (13 methods) and uniform. Every widget implements the same `key()/value()/focus()/update()/view()` contract, making `Form` a genuinely generic compositor.

2. **Immutable with pattern** — every state-changing method returns a new instance via private `mutate()` helper. `readonly` properties on `Form` mean any mutation creates a new `Form` instance — true immutability throughout.

3. **`consumes()` routing** — the key design insight from huh that allows heterogeneous fields (text input, checkbox, list, textarea) to coexist in one `Form` without conflicts. When `Select` is in filter mode it claims Enter/Up/Down so the Form doesn't steal them for navigation. When `Text` has focus it claims Enter for newline insertion.

4. **Trait composition** — `HasHideFunc` and `HasDynamicLabels` are cleanly separated concerns that field implementations opt into with `use`. This avoids deep inheritance hierarchies.

5. **Multi-page forms** — `Group` with `withHideFunc()` enables wizard-style flows with conditional pages, mirroring huh's multi-group flow.

6. **Async suggestions** — integration of ReactPHP promises with debounce timers for async autocomplete is a sophisticated pattern not present in the upstream Bubble Tea ecosystem (which uses blocking synchronous calls).

7. **Smith-Waterman fuzzy matching** — custom implementation with adjacent bonus scoring provides better ranking than simple substring matching.

### Relative to Upstream Repos

| Aspect | candy-forms Advantage | Disadvantage |
|---|---|---|
| **PHP ecosystem** | Usable from any PHP 8.3+ project | Not usable from Go/other langs |
| **Bubble Tea pattern** | `Model::init/update/view` + `Msg`/`Cmd`/`Subscriptions` | Not idiomatic PHP (ReactPHP prefers async patterns) |
| **Immutable state** | `readonly` properties + `with*()` = truly immutable | More object allocations; `clone $this` in traits |
| **Generic types** | PHP lacks generics for `Select<T>` | `BackedEnum` coercion is a workaround |
| **Async suggestions** | ReactPHP integration | Upstream uses synchronous callbacks |
| **Fuzzy matching** | Custom Smith-Waterman | Not tested against `sahilm/fuzzy` at scale |
| **Vim mode** | Full vim keybindings | Not present in upstream bubbles/huh |
| **Smooth scroll** | Viewport lerp animation | Not present in upstream bubbles |
| **Theme presets** | 7 built-in themes | Fewer than huh's Lipgloss-based full customization |

### Key Architectural Insights

1. **Separation of primitive vs. field** — `TextInput` is a standalone `Model` that can be used independently of forms. `Field\Input` wraps it and adds the `Field` interface adapter. This mirrors bubbles (standalone widgets) vs. huh (form fields).

2. **Form as Model** — `Form` itself implements `Model`, meaning forms can be embedded inside larger TUI applications. The `Form::update()` message loop dispatches to the focused field's `update()` and applies form-level navigation on top. This is the core TEA pattern.

3. **Cmd transport** — `focus()` returning `[$next, ?Closure]` allows the focused field to schedule side effects (cursor blink, async suggestion fetch). The `Closure` is returned from `Form::update()` to the runtime loop.

4. **KeyMap flexibility** — the `KeyMap` class with list-of-predicates structure (not a fixed enum or bitmask) allows arbitrary rebinding. This is a better design than the upstream's static `KeyMap` struct.

5. **Theme overrides per Group** — when `Group::withTheme()` is set, `Form::activeTheme()` returns the group override, enabling per-page styling variations.

### Recommendations

The primary gaps to address for `candy-forms` to reach 🟢:

1. **Add VHS demos** and examples directory showing each field type
2. **Add snapshot tests** for all widgets asserting exact ANSI SGR bytes
3. **Add TextArea soft-wrap** line tracking for cursor positioning across wrapped lines
4. **Add matched indices** to `FuzzyMatcher::match()` return type for filter highlighting UI
5. **Create `CALIBER_LEARNINGS.md`** documenting patterns and gotchas for this lib
