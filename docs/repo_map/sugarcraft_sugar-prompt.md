# SugarCraft / sugar-prompt

## Metadata

- **Library:** `sugar-prompt`
- **Composer package:** `sugarcraft/sugar-prompt`
- **Namespace:** `SugarCraft\Prompt` (re-export from `SugarCraft\Forms`)
- **Status:** 🟢 v1 ready
- **Upstream sources:** `charmbracelet/huh` (primary), `charmbracelet/bubbles` (widget layer)
- **Depends on:** `sugarcraft/candy-core`, `sugarcraft/candy-forms`, `sugarcraft/candy-sprinkles`, `sugarcraft/sugar-bits`
- **PHP minimum:** `^8.3`

## Overview

**`sugar-prompt`** is a PHP port of `charmbracelet/huh` — a form library for building interactive multi-page terminal forms. It provides Note, Input, Confirm, Select, MultiSelect, Text, and FilePicker field types with multi-page Group support, 6 built-in themes, and a form-level `KeyMap` override per binding.

**Critical architectural note:** `sugar-prompt` is a deprecated backward-compatibility re-export layer. The actual implementation lives in **`candy-forms`** (`SugarCraft\Forms\*` namespace). The `sugar-prompt/src/` files are thin `class_alias()` wrappers:

```php
// sugar-prompt/src/Form.php
class_alias(\SugarCraft\Forms\Form::class, Form::class);
```

This means the canonical source of truth for `sugar-prompt`'s features is `candy-forms/`. All field implementations, the `Form` compositor, the `Field` interface, and the `Theme` system are defined in `candy-forms/src/` and re-exported under `SugarCraft\Prompt\*` for upstream API compatibility.

---

## Package Architecture

### Directory Structure

```
sugar-prompt/          ← thin backward-compat re-export layer
├── src/
│   ├── Form.php       — class_alias(\SugarCraft\Forms\Form::class)
│   ├── Group.php      — class_alias(\SugarCraft\Forms\Group::class)
│   ├── Theme.php      — class_alias(\SugarCraft\Forms\Theme::class)
│   ├── KeyMap.php     — class_alias(\SugarCraft\Forms\KeyMap::class)
│   ├── HasDynamicLabels.php — class_alias(\SugarCraft\Forms\HasDynamicLabels::class)
│   ├── HasHideFunc.php    — class_alias(\SugarCraft\Forms\HasHideFunc::class)
│   ├── Field.php         — class_alias(\SugarCraft\Forms\Field::class)
│   ├── Fuzzy/FuzzyMatcher.php — class_alias(\SugarCraft\Forms\Fuzzy\FuzzyMatcher::class)
│   ├── Field/             — class_alias wrappers for each field type
│   │   ├── Field.php, Input.php, Text.php, Select.php
│   │   ├── MultiSelect.php, Confirm.php, Note.php, FilePicker.php
│   └── Validator/         — Validator interface + built-ins
├── tests/               — Full test suite (mirrors candy-forms tests)
├── examples/             — burger.php, multi-page-form.php, confirm.php, etc.
└── .vhs/                 — VHS demo tapes rendered to .gif
```

The **real implementation** lives in `candy-forms/`:

```
candy-forms/src/
├── Form.php              — Top-level form compositor (862 lines)
├── Group.php             — One page of fields (137 lines)
├── Theme.php             — 7 built-in themes (188 lines)
├── KeyMap.php            — Rebindable key bindings (139 lines)
├── HasDynamicLabels.php   — Trait for *Func() dynamic labels (71 lines)
├── HasHideFunc.php       — Trait for runtime visibility predicate (38 lines)
├── Field.php             — Field interface: 13 methods (87 lines)
├── Fuzzy/FuzzyMatcher.php — Smith-Waterman local alignment (115 lines)
├── Field/
│   ├── Field.php         — Interface
│   ├── Input.php         — Single-line text (489 lines)
│   ├── Text.php          — Multi-line textarea field (160 lines)
│   ├── Select.php        — Single-choice picker (334 lines)
│   ├── MultiSelect.php   — Multi-checkbox picker (254 lines)
│   ├── Confirm.php       — Yes/no toggle (161 lines)
│   ├── Note.php          — Read-only paragraph (124 lines)
│   └── FilePicker.php    — Filesystem browser wrapper (125 lines)
├── TextInput/            — Single-line text widget
├── TextArea/             — Multi-line text widget
├── ItemList/             — Filterable selectable list
├── FilePicker/           — Filesystem picker widget
├── Validator/            — Validator interface + 5 built-ins
├── Cursor/               — Cursor blink state machine
└── Spinner/              — Spinner widget
```

---

## Form Primitive Design

### The Field Interface (`SugarCraft\Forms\Field`)

The `Field` interface is the central abstraction enabling the `Form` compositor to drive heterogeneous field types uniformly. It consists of 13 methods:

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

The **`consumes()`** method is the key design insight from huh that allows heterogeneous fields (text input, checkbox, list, textarea) to coexist in one `Form` without conflicts. When `Select` is in filter mode it claims Enter/Up/Down so the Form doesn't steal them for navigation. When `Text` has focus it claims Enter for newline insertion.

### Immutable with*() Pattern

Every field and the `Form` itself follow immutable construction: every state-changing method returns a new instance via a private `mutate()` helper. `readonly` properties on `Form` mean any mutation creates a new instance — true immutability throughout.

### Trait Composition

Fields use two traits composed via `use HasHideFunc; use HasDynamicLabels;`:

- **`HasHideFunc`** — provides `withHideFunc(Closure): static` and `isHidden(array $values): bool` for conditional field display.
- **`HasDynamicLabels`** — provides `withTitleFunc(Closure): static`, `withDescriptionFunc(Closure): static`, `resolveTitle(string): string`, `resolveDescription(string): string` for render-time dynamic labels (mirrors huh's `*Func` pattern). The funcs are preserved across mutations via `clone $this`.

### Form as Model

`Form` implements `Model` (the Bubble Tea `tea.Model` interface), meaning forms can be embedded inside larger TUI applications. The `Form::update()` message loop dispatches to the focused field's `update()` and applies form-level navigation on top.

---

## Field Type Analysis

### Input (`Field\Input`) — single-line text

Wraps `TextInput` widget. Key features:

- **Multibyte-safe** — uses `mb_substr`/`mb_strlen` throughout, cursorPos is a grapheme index, not byte offset.
- **Echo modes** — `EchoMode::Normal` (default), `EchoMode::Password` (masked), `EchoMode::None` (hidden).
- **Vim Mode** — full vim-style keybindings in two submodes:
  - Normal mode: `h/l` move, `w/b` word nav, `0/$` line boundaries, `i/a/A/I` enter insert mode, `x` delete char, `u` undo (stubbed).
  - Insert mode: standard editing, `Escape` returns to normal mode.
- **History navigation** — up/down arrow browsing through previous entries.
- **Suggestions** — static pool with case-insensitive prefix filter.
- **Character restrict** — `withRestrict()` regex filter applied per keystroke.
- **Validation timing** — `ValidateOn::None` (immediate), `Blur`, `Change`, `Submit`.
- **Async suggestions** — ReactPHP promise-based with debounce timers (unique to SugarCraft, not in upstream huh).
- **Fuzzy suggestions** — Smith-Waterman local alignment scoring via `withFuzzySuggestions()`.
- **Validator chaining** — multiple `withValidator()` calls chain via `buildChainedValidator()` — first error wins.

```php
Input::new('email')
    ->withTitle('Email address')
    ->withPlaceholder('you@example.com')
    ->withValidator(new Required())
    ->withValidator(new Email())
    ->withFuzzySuggestions(['php', 'python', 'go'])
    ->async(fn($query) => fetchFromApi($query));
```

### Text (`Field\Text`) — multi-line textarea

Wraps `TextArea` widget. Enter inserts a newline inside the field rather than advancing the form — the field declares itself a consumer of Enter via `consumes()`. Supports char limit, max lines, line numbers, and validation.

### Select (`Field\Select`) — single-choice list

Wraps `ItemList` widget. Fuzzy/async suggestions support mirrors `Input`. Supports `BackedEnum` coercion via `withEnum()`. In filter mode (triggered by `/`), claims Enter/Up/Down keys so the Form doesn't steal them.

```php
Select::new('lang')
    ->withTitle('Favorite language?')
    ->withOptions('PHP', 'Go', 'Rust', 'Python')
    ->withFuzzySuggestions(['PHP', 'Python', 'Go', 'Rust'])
    ->withEnum(MyEnum::class);
```

### MultiSelect (`Field\MultiSelect`) — multi-checkbox

Pure implementation without a wrapped widget (unlike Input/Select/Text/FilePicker which wrap primitives). Uses `j/k` vim keys for navigation, claims Up/Down via `consumes()` so the Form doesn't steal them. `withMin()`/`withMax()`/`withLimit()` constrain selections. `value()` returns `list<string>` of selected option strings in declaration order.

### Confirm (`Field\Confirm`) — yes/no toggle

Yes/No question toggled with `←/→/h/l` or `y`/`n` directly. Wraps no widget — self-contained. Supports `withValidator(\Closure(bool):?string)` for predicate-based validation run on every value change.

### Note (`Field\Note`) — read-only paragraph

Skippable by default (`skippable()` returns `!$this->next`). When `withNext(true)` is enabled, renders an interactive button that participates in form navigation and can be activated with Enter/Space. Supports `withHeight()` for fixed-height padding.

### FilePicker (`Field\FilePicker`) — filesystem picker

Wraps `FilePicker` widget. Claims Enter/Backspace/Up/Down via `consumes()` for picker navigation. Supports `withShowHidden()`, `withAllowedExtensions()`, `withDirAllowed()`, `withFileAllowed()`, `withHeight()`.

---

## Theming System

### Theme Class (`Theme`)

The `Theme` class is a simple struct with 11 `Style` properties:

```php
public function __construct(
    public readonly Style $title,
    public readonly Style $description,
    public readonly Style $focusedTitle,
    public readonly Style $blurredTitle,
    public readonly Style $error,
    public readonly Style $errorSummary,
    public readonly Style $cursor,
    public readonly Style $option,
    public readonly Style $selectedOption,
    public readonly Style $help,
    public readonly Style $prompt,
) {}
```

### Built-in Presets (7 total)

| Preset | Description | Color palette |
|---|---|---|
| `ansi()` | Default colored theme | Bright magenta title, cyan focus, grey description |
| `plain()` | No-style fallback | Every slot renders text verbatim |
| `charm()` | Charm-brand | Pink + cyan |
| `dracula()` | Dracula palette | Dark magenta / cyan / green |
| `catppuccin()` | Catppuccin Mocha | Pastel mauve / teal / green |
| `base16()` | Neutral Base16 | Red accent / cyan focus |
| `base()` | Bare-bones monochrome | Bold focus, reverse cursor, no colour |

Themes are applied via `Form::withTheme(Theme::dracula())`. Per-Group overrides take priority via `Group::withTheme()`. The accessibility mode (`Form::withAccessible()`) degrades to plain-text rendering regardless of theme.

### Style Implementation

`Style` comes from `SugarCraft\Sprinkles` (the Lipgloss analog). Styles are built from `Style::new()->bold()->foreground(Color::hex('#ff79c6'))` chains and render to ANSI SGR bytes via `->render(string)`.

---

## Reactivity Model

### Dynamic Labels (`HasDynamicLabels` trait)

The `withTitleFunc(Closure(): string)` and `withDescriptionFunc(Closure(): string)` methods evaluate at **render time** via `resolveTitle()`/`resolveDescription()`. This enables labels that track live form values:

```php
Input::new('counter')
    ->withTitleFunc(fn() => "Count: {$values['counter'] ?? 0}");
```

The closures capture `$values` from the enclosing scope. Unlike Go's huh which uses a binding/invalidation system, PHP's closures capture enclosing scope directly.

### Dynamic Visibility (`HasHideFunc` trait)

`withHideFunc(Closure(array $values): bool)` evaluates on page transitions. Hidden groups skip both navigation and value collection. Used for conditional wizard steps:

```php
Group::new(
    Confirm::new('newsletter')->withTitle('Subscribe?'),
    Select::new('frequency')->withOptions('Weekly', 'Monthly'),
)
    ->withHideFunc(fn(array $v) => empty($v['newsletter']));
```

### Async Suggestions

`withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)` uses ReactPHP's `Loop::addTimer()` for debouncing and `React\Promise\Deferred` for promise chaining. The fetcher receives the current query string and returns `PromiseInterface<list<string>>`. A `SuggestionsReadyMsg` is dispatched when results arrive. This pattern is unique to SugarCraft — upstream huh uses synchronous callbacks.

```php
->async(fn($query) => fetchFromApi($query), 150)
```

### Fuzzy Suggestions

`withFuzzySuggestions(list<string>)` uses a custom Smith-Waterman local alignment implementation in `FuzzyMatcher`. Scoring constants: match=`+3`, mismatch=`-3`, gap open=`-5`, gap extend=`-1`, adjacent bonus=`+5` for consecutive matches.

---

## Accessibility Features

### Accessible Mode (`Form::withAccessible(bool)`)

When enabled, `Form::view()` calls `accessibleView()` which renders a single line `"title: value"` for the focused field. Designed for screen readers and `TERM=dumb` terminals:

```php
$form = Form::new(...)->withAccessible(getenv('TERM') === 'dumb');
```

### Keyboard Navigation

Standard navigation: `Tab`/`↓` advances, `Shift+Tab`/`↑` retreats, `Enter` on the last field submits, `Esc`/`Ctrl+C` aborts. `j/k` vim-style navigation also works for many field types. The `KeyMap` class allows full rebinding of these.

### Focus Management

All fields track focus state. `focus()` returns `[$newField, ?Cmd]` where the `Cmd` typically starts a cursor blink timer. `blur()` returns a new unfocused field. The `Form` tracks which group+field index holds focus and routes `KeyMsg` accordingly.

---

## Comparison with candy-forms

**candy-forms** (status 🟡) is the foundational library for form primitive widgets in SugarCraft. It provides the low-level building blocks:

- `TextInput` — standalone single-line input widget
- `TextArea` — standalone multi-line text widget
- `ItemList` — standalone selectable, scrollable, filterable list
- `FilePicker` — standalone filesystem browser widget
- `Field` interface + concrete implementations (`Input`, `Text`, `Select`, `MultiSelect`, `Confirm`, `Note`, `FilePicker`)
- `Form` compositor
- `Group` page model
- `Theme` stylesheet
- `KeyMap`, `HasDynamicLabels`, `HasHideFunc`, `FuzzyMatcher`, validators

**sugar-prompt** (status 🟢) is the `charmbracelet/huh` analog — a thin re-export layer that maps the `SugarCraft\Forms` namespace to `SugarCraft\Prompt` and adds the upstream-mirroring API surface. The relationship is:

- `sugar-prompt` = `candy-forms` + backward-compat re-exports
- `sugar-prompt`'s `composer.json` depends on `sugarcraft/candy-forms`
- When `candy-forms` reaches 🟢, `sugar-prompt` will likely be deprecated entirely in favor of direct `SugarCraft\Forms\*` usage

The naming reflects the upstream mapping: `huh` → `sugar-prompt` (form library) while `bubbles` → `sugar-bits` + `candy-forms` (widget primitives).

---

## Comparison with Upstream Repos

### charmbracelet/huh (primary upstream, ~4.9k stars)

`huh` is the Go reference implementation that `sugar-prompt` ports. Key architectural comparisons:

| Feature | huh | sugar-prompt/candy-forms | Notes |
|---|---|---|---|
| Form + Groups + Fields | 🟢 | 🟢 | Identical hierarchy |
| Input/Text/Select/etc. | 🟢 | 🟢 | All 7 field types present |
| Dynamic labels (*Func) | 🟢 | 🟢 | Identical pattern |
| Theme system | 🟢 | 🟢 (7 presets) | huh uses Lipgloss; SugarCraft uses Sprinkles |
| KeyMap override | 🟢 | 🟢 | Per-form rebinding (huh #272) |
| Accessible mode | 🟢 | 🟢 | `withAccessible()` |
| Error summary | 🟢 | 🟢 | `withErrorSummary()` |
| Generics for Select | 🟢 | 🔶 | Go generics → PHP `BackedEnum` coercion via `withEnum()` |
| Go-specific Eval/Cache | 🟢 | N/A | ReactPHP closures handle reactive evaluation differently |
| Async suggestions | N/A | 🟢 | SugarCraft extension using ReactPHP promises |
| Vim mode | N/A | 🟢 | SugarCraft extension in TextInput |
| Smith-Waterman fuzzy | N/A | 🟢 | SugarCraft custom impl; upstream uses `sahilm/fuzzy` |

### charmbracelet/bubbles (widget layer, ~2k stars)

Bubbles provides 14 primitives. sugar-prompt uses several as wrapped widgets:

| Bubbles | candy-forms | Used by |
|---|---|---|
| `textinput.Model` | `TextInput` | `Field\Input` |
| `textarea.Model` | `TextArea` | `Field\Text` |
| `list.Model` | `ItemList` | `Field\Select` |
| `viewport.Model` | `Viewport` | Not directly in forms |
| `filepicker` | `FilePicker` | `Field\FilePicker` |
| `cursor` | `Cursor` | Embedded in TextInput |
| `spinner` | `Spinner` | Standalone |

### charmbracelet/gum (CLI tool, ~8.7k stars)

Gum wraps huh/bubbles into shell commands. SugarCraft would need a `CandyShell` CLI wrapper to be equivalent. The gum commands map to sugar-prompt fields:

| gum command | sugar-prompt field |
|---|---|
| `gum input` | `Field\Input` |
| `gum write` | `Field\Text` |
| `gum filter` | `Field\Select` (filtering mode) |
| `gum choose` | `Field\Select` / `Field\MultiSelect` |
| `gum confirm` | `Field\Confirm` |
| `gum file` | `Field\FilePicker` |
| `gum spin` | `Spinner` |

### erikgeiser/promptkit (Go, ~300-500 stars)

PromptKit is a BubbleTea-based prompt library. Key differences:

| Feature | PromptKit | sugar-prompt/candy-forms |
|---|---|---|
| Generic type-safe selections | 🟢 Go generics | 🔶 PHP `BackedEnum` coercion |
| Template rendering | 🟢 `text/template` | N/A (direct ANSI concat) |
| Per-field KeyMap | 🟢 | 🔶 Form-level only |
| Auto-pagination | 🟢 binary search | 🔶 Manual via ItemList config |
| Common-prefix autocomplete | 🟢 | 🔶 Fuzzy only |

### php-school/cli-menu (PHP, ~1.9k stars)

The only other PHP-native TUI form library in the comparison. Key architectural differences:

| Feature | cli-menu | sugar-prompt/candy-forms |
|---|---|---|
| Event loop | Blocking `while` loop | Bubble Tea `Model::update()` pattern |
| ReactPHP compatibility | None | Native (async suggestions) |
| Form hierarchy | Submenu tree | Group/Field/Form with typed accessors |
| Key rebinding | Per-menu | Per-form via `KeyMap` |
| Validation | `InputIO` validates | Per-field validators with chaining |
| Vim mode | j/k for up/down | Full vim in TextInput + j/k in MultiSelect |
| Fuzzy/async suggestions | None | Both via ReactPHP |
| Accessibility mode | None | `withAccessible()` plain-text fallback |

### KevM/bubbleo (Go, navigation)

Bubbleo provides vim-style area navigation. The `consumes()` pattern in sugar-prompt solves the same key-routing problem — preventing the Form from stealing keys that inner widgets need.

---

## What Remains Incomplete

As a 🟢 v1-ready package, sugar-prompt has no major gaps. Remaining minor items in candy-forms (which sugar-prompt re-exports):

### TextArea Soft-wrap

The PHP port stores flat lines only. Upstream Bubbles `TextArea` maintains complex `LineInfo` accounting for wide Unicode characters across wrapped lines — not yet ported.

### FuzzyMatcher — Matched Indices

The Go `sahilm/fuzzy` library returns character indices for highlighted filter matches. `FuzzyMatcher::match()` returns scored candidates but not the positions for highlighting in the UI.

### Snapshot Tests

Component render outputs (`\x1b[...m` ANSI bytes) need golden file assertions. Currently absent for most widgets.

---

## Innovation Points (SugarCraft Extensions over Upstream)

1. **Async suggestions via ReactPHP** — Upstream huh uses synchronous callbacks; sugar-prompt uses `Loop::addTimer()` debounce + `Deferred` promises for genuine async autocomplete.

2. **Vim mode in TextInput** — Full vim keybindings (`h/l/w/b/0/$/i/a/A/x/u`) in a TUI text input. Not present in upstream bubbles/huh.

3. **Smith-Waterman fuzzy matching** — Custom implementation with adjacent bonus scoring (`+5` for consecutive matches), providing better ranking than simple substring matching.

4. **Per-form KeyMap rebinding** — Mirrors the long-requested huh #272 feature. sugar-prompt has `Form::withKeyMap()` for arbitrary rebinding of next/prev/submit/abort without forking the runtime.

5. **Per-group theme overrides** — When `Group::withTheme()` is set, `Form::activeTheme()` returns the group override, enabling per-page styling variations. Not in upstream huh.

6. **Multi-group (wizard) flow** — `Form::groups(Group...)` with `advanceGroup()` traverses groups, blurring current and focusing first non-skippable in next. `Group::withHideFunc()` enables conditional pages.

7. **Accessible mode** — `Form::withAccessible()` degrades to single-line `"label: value"` rendering for screen readers and `TERM=dumb`.

8. **Error summary** — `Form::withErrorSummary()` renders all validation errors at end of form after failed submit.

9. **`validateAll()`** — Runs all field validators and returns `[fieldKey => errorMessage]` for cross-field validation not expressible per-field.

---

## Analysis

### Strengths of the Architecture

1. **Clean Field abstraction** — 13 methods, uniform contract. Every widget implements `key()/value()/focus()/update()/view()` making `Form` a genuinely generic compositor.

2. **Immutable with*() pattern** — `readonly` properties + private `mutate()` helper = truly immutable field state throughout. No accidental mutation.

3. **`consumes()` routing** — The key design insight from huh that allows heterogeneous fields to coexist in one `Form` without conflicts. When `Select` is in filter mode it claims Enter/Up/Down so the Form doesn't steal them for navigation.

4. **Trait composition** — `HasHideFunc` and `HasDynamicLabels` are cleanly separated concerns that field implementations opt into with `use`. No deep inheritance hierarchies.

5. **ReactPHP integration** — Async suggestions using promises and debounce timers is a sophisticated pattern not present in the upstream Bubble Tea ecosystem (which uses blocking synchronous calls).

6. **Smith-Waterman fuzzy matching** — Custom implementation with adjacent bonus scoring provides better ranking than simple substring matching.

### Relative to Upstream Repos

| Aspect | sugar-prompt Advantage | Disadvantage |
|---|---|---|
| **PHP ecosystem** | Usable from any PHP 8.3+ project | Not usable from Go/other langs |
| **Bubble Tea pattern** | `Model::init/update/view` + `Msg`/`Cmd` | Not idiomatic PHP (ReactPHP prefers async) |
| **Immutable state** | `readonly` + `with*()` = truly immutable | More object allocations |
| **Generic types** | PHP lacks generics for `Select<T>` | `BackedEnum` coercion is a workaround |
| **Async suggestions** | ReactPHP integration | Upstream uses synchronous callbacks |
| **Fuzzy matching** | Custom Smith-Waterman | Not tested at scale vs `sahilm/fuzzy` |
| **Vim mode** | Full vim keybindings in TextInput | Not in upstream |
| **Per-form KeyMap** | Full rebinding support | Upstream only per-field via KeyBindings |
| **Smooth scroll** | Viewport lerp animation | Not in upstream bubbles |
| **Theme presets** | 7 built-in themes | Fewer than huh's Lipgloss customization |

### Key Architectural Insights

1. **Separation of primitive vs. field** — `TextInput` is a standalone `Model` that can be used independently of forms. `Field\Input` wraps it and adds the `Field` interface adapter. This mirrors bubbles (standalone widgets) vs. huh (form fields).

2. **Form as Model** — `Form` itself implements `Model`, meaning forms can be embedded inside larger TUI applications.

3. **Cmd transport** — `focus()` returning `[$next, ?Closure]` allows the focused field to schedule side effects (cursor blink, async suggestion fetch). The `Closure` is returned from `Form::update()` to the runtime loop.

4. **KeyMap flexibility** — The `KeyMap` class with list-of-predicates structure (not a fixed enum or bitmask) allows arbitrary rebinding. Better design than upstream's static `KeyMap` struct.

5. **Theme overrides per Group** — When `Group::withTheme()` is set, `Form::activeTheme()` returns the group override.

---

## File References

### Core Implementation (candy-forms — real source)

- `/home/sites/sugarcraft/candy-forms/src/Form.php` — 862 lines — Top-level form compositor
- `/home/sites/sugarcraft/candy-forms/src/Group.php` — 137 lines — One page of fields
- `/home/sites/sugarcraft/candy-forms/src/Theme.php` — 188 lines — 7 built-in themes
- `/home/sites/sugarcraft/candy-forms/src/KeyMap.php` — 139 lines — Rebindable key bindings
- `/home/sites/sugarcraft/candy-forms/src/Field/Field.php` — 87 lines — Field interface
- `/home/sites/sugarcraft/candy-forms/src/Field/Input.php` — 489 lines — Single-line text field
- `/home/sites/sugarcraft/candy-forms/src/Field/Text.php` — 160 lines — Multi-line text field
- `/home/sites/sugarcraft/candy-forms/src/Field/Select.php` — 334 lines — Single-choice picker
- `/home/sites/sugarcraft/candy-forms/src/Field/MultiSelect.php` — 254 lines — Multi-checkbox picker
- `/home/sites/sugarcraft/candy-forms/src/Field/Confirm.php` — 161 lines — Yes/no toggle
- `/home/sites/sugarcraft/candy-forms/src/Field/Note.php` — 124 lines — Read-only paragraph
- `/home/sites/sugarcraft/candy-forms/src/Field/FilePicker.php` — 125 lines — Filesystem picker
- `/home/sites/sugarcraft/candy-forms/src/HasDynamicLabels.php` — 71 lines — *Func() dynamic labels
- `/home/sites/sugarcraft/candy-forms/src/HasHideFunc.php` — 38 lines — Runtime visibility predicate
- `/home/sites/sugarcraft/candy-forms/src/Fuzzy/FuzzyMatcher.php` — 115 lines — Smith-Waterman scorer

### sugar-prompt Re-exports

- `/home/sites/sugarcraft/sugar-prompt/src/Form.php` — `class_alias(\SugarCraft\Forms\Form::class, Form::class)`
- `/home/sites/sugarcraft/sugar-prompt/src/Group.php` — `class_alias(\SugarCraft\Forms\Group::class, Group::class)`
- `/home/sites/sugarcraft/sugar-prompt/src/Field/Field.php` — `class_alias(\SugarCraft\Forms\Field\Field::class, Field::class)`

### Examples

- `/home/sites/sugarcraft/sugar-prompt/examples/burger.php` — Canonical burger order form
- `/home/sites/sugarcraft/sugar-prompt/examples/multi-page-form.php` — Multi-page form with conditional Group hide

### Tests

- `/home/sites/sugarcraft/sugar-prompt/tests/FormTest.php`
- `/home/sites/sugarcraft/sugar-prompt/tests/Field/InputTest.php`
- `/home/sites/sugarcraft/sugar-prompt/tests/Field/SelectTest.php`
- `/home/sites/sugarcraft/sugar-prompt/tests/Field/MultiSelectTest.php`
- `/home/sites/sugarcraft/sugar-prompt/tests/Field/ConfirmTest.php`
- `/home/sites/sugarcraft/sugar-prompt/tests/Field/NoteTest.php`
- `/home/sites/sugarcraft/sugar-prompt/tests/Field/FilePickerFieldTest.php`
- `/home/sites/sugarcraft/sugar-prompt/tests/AsyncSuggestionsTest.php`
- `/home/sites/sugarcraft/sugar-prompt/tests/KeyMapTest.php`
- `/home/sites/sugarcraft/sugar-prompt/tests/ThemeTest.php`
