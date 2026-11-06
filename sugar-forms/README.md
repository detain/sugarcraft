# SugarForms

> Formerly published as `sugarcraft/candy-forms` (renamed 2026-10). <!-- @rename-keep -->

Form library (port of [charmbracelet/huh](https://github.com/charmbracelet/huh))
and foundation lib for form and input primitives — the raw component
implementations that `sugar-widgets` re-exports. The former standalone prompt
façade has been folded in here, so the full interactive form engine and its
primitives ship as a single standalone dependency.

## Install

```sh
composer require sugarcraft/sugar-forms
```

## Role

SugarForms owns the full primitive + form surface:

- **Input primitives** — `TextInput`, `TextArea`, `ItemList`, `Viewport`,
  `FilePicker`, `Cursor`, `Scrollbar`, `Spinner`.
- **Form engine** — `Field` interface + `Field\{Input,Text,Confirm,Select,
  MultiSelect,Note,FilePicker,Date,Slider,Color,NumberField,RadioPills}`,
  `Form`, `Group`, `KeyMap`, `Theme`,
  `Fuzzy\FuzzyMatcher`, and `Validator\{Required,Email,MinLength,MaxLength,Pattern}`.

`sugar-widgets` (`SugarCraft\Widgets\*`) exposes these classes as thin
`class_alias` shims pointing back here, so existing imports keep working
while the canonical implementation lives in `SugarCraft\Forms\*`.

## Quickstart

A single-field form:

```php
<?php

use SugarCraft\Core\Program;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Validator\Required;

$form = Form::new(
    Input::new('name')
        ->withTitle('What is your name?')
        ->withValidator(new Required())
);

(new Program($form))->run();

echo $form->values()['name'], "\n";
```

`echo $form->view()` on its own renders one static frame; the form is a
SugarCraft Model, so `Program::run()` is what drives it interactively. For a
complete reference — multi-group paging and conditional visibility — see
`examples/multi-page-form.php:53` (`php examples/multi-page-form.php`); the
`examples/` directory holds eleven runnable demos.

### Pre-filling fields

Show a form's current values before the user touches anything — handy for
"edit settings" screens. `Input::withValue()` seeds the editable text (it is
the submitted value until edited; pass `''` to clear). `Select` pre-selects
by value with `withSelected()` (no-op when the value isn't an option) or by
0-based index with `withSelectedIndex()` (negatives clamp to the first option).

```php
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;

Input::new('name')->withValue('Ada');                      // edit box starts as "Ada"
Select::new('theme')->withOptions('Nocturne', 'Daylight', 'Midnight')
    ->withSelected('Midnight');                            // or ->withSelectedIndex(2)
```

Standalone spinner (now lives here, was in sugar-widgets):

```php
<?php

use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style;

$spinner = Spinner::new(Style::dot());
// in your model's init():   return $spinner->init();
// in your model's update():  [$spinner, $cmd] = $spinner->update($msg);
// in your model's view():    "loading {$spinner->view()}"
```

## Forms (huh-style)

Multi-page Group wizards, seven themes, per-field validators, conditional skip
predicates. The form is itself a SugarCraft Model — drop it into a Program.

```php
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Field\{Input, Confirm, Select, Note};

$form = Form::new(
    Note::new('welcome')->title('Onboarding')->desc('A few quick questions.'),
    Input::new('name')->title('Your name?')->placeholder('Ada Lovelace'),
    Confirm::new('newsletter')->title('Subscribe to the newsletter?'),
    Select::new('lang')->title('Favorite language?')->options('PHP', 'Go', 'Rust', 'Python'),
);
```

> Where a field supports a knob, it also exposes a short-form alias for it
> (`title`, `desc`, `placeholder`, `width`, `height`, `validator`, `options`,
> `min`, `max`, …): the short form delegates to the long `with*` name
> (`withTitle`, `withDescription`, …). Pick whichever reads better at the
> call site.

### Field types

| Field         | Description                                                 | Notable knobs  Keys consumed |
| ------------- | ----------------------------------------------------------- | -------------  ----------------- |
| `Input`       | Single-line text (wraps `SugarCraft\Forms\TextInput\TextInput`)     | `withPlaceholder`, `withCharLimit`, `withWidth`, `withPrompt`, `withValidator(Validator|\Closure)` (chains; first error wins), `withTitleFunc` / `withDescriptionFunc`, `withPassword(bool, string $echo = '*')`, `withSuggestions(list<string>)`, `withSuggestionsFunc(\Closure(string):list<string>)`, `withFuzzySuggestions(list<string>)`, `withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)`  typing, Home/End, Left/Right, Backspace/Delete, ctrl+a / ctrl+e / ctrl+u / ctrl+k; Up/Down history recall and the Enter submit-validator run only when a custom map frees those keys (nav by default); paste not consumed |
| `Text`        | Multi-line text editor                                      | `withCharLimit`, `withHeight`, `withWidth`, `withValidator(closure only; replaces)`  full buffer editing; Enter newline and Up/Down caret rows are claimed (they arrive despite nav); ctrl+a/e/u/k + ctrl+c/x/o chords; a host PasteMsg replaces the selection; Tab indents only when a custom map frees it (default: Tab navigates) |
| `Confirm`     | Yes/no boolean                                              | `withLabels(affirmative, negative)`, `withDefault(bool)`, `withValidator(Validator|\Closure(bool):?string|null)` (replaces; null clears), `withTitleFunc`, `withDescriptionFunc`  Left/Right or h/l answer, y / n answer; Tab toggles only when a custom map frees it (default: Tab navigates) |
| `Select`      | Single-choice list (wraps `SugarCraft\Forms\ItemList\ItemList`)     | `withOptions(...)`, `withTitleFunc`, `withDescriptionFunc`, `withFuzzySuggestions(list<string>)`, `withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)`, `withEnum(\BackedEnum::class)`  list keys Up/Down or j/k, PageUp/PageDown, Home/g, End/G; the / key opens the filter (typing + Backspace narrow, Enter/Escape end it - claimed); click to position when focused |
| `MultiSelect` | Multi-choice list (j/k vim keys + space to toggle)          | `withOptions(...)`, `withLimit(int)`  Up/Down or k/j, Home/g, End/G, Space toggles the cursor, digits 1-9 jump-toggle |
| `Note`        | Read-only paragraph; skipped by tab navigation              | `withTitle`, `withDescription`, `withHeight(int)`, `withNext(bool)`, `withNextLabel(string)` (turns it into an interactive button page)  none (inert) |
| `FilePicker`  | Filesystem picker (wraps `SugarCraft\Forms\FilePicker\FilePicker`) | `withDirAllowed`, `withFileAllowed`, `withAllowedExtensions`, `withShowHidden` (starting directory via `FilePicker::new($key, $cwd)`)  Up/Down, Home/End, Enter selects or enters a directory, Backspace/Left ascend, Right enters; no filter typing, no mouse |
| `Date`        | Calendar-grid date picker (`YYYY-MM-DD` value, arrow-walked grid) | `withValue(?string)`, `withValidator(Validator|\Closure|null)` (replaces; null clears)  Left/Right or h/j day, Up/Down or k/j week (claimed arrows); Shift+Left/Right and PageUp/PageDown month, Home/End month edges, Space commits; movement auto-commits |
| `Slider`      | Numeric range slider (keyboard drag, snapping step)         | `withMin` / `withMax` / `withStep`, `withValue(int|float)`, `withValidator(Validator|\Closure|null)` (replaces; null clears)  Left/Right or h/l step, Home/End clamp; mouse only when the host routes MouseMsg or calls the zone-drag API - the widget never negotiates drag itself |
| `Color`       | Hex color picker with swatch preview                        | `withValue(string $hex)`, `withTruecolor(bool)`  Left/Right or h/l green, Up/Down or k/j blue (claimed arrows); PageUp/PageDown red, Home/End clamp green |
| `NumberField` | Bounded numeric input — draft + committed int64-safe value on a step lattice | `withMin` / `withMax` / `withStep`, `withValue(int\|float)`, `withoutValue()` (never-set ≠ 0), `withPlaceholder`, `withWidth`  Up/Down plus or minus 1 (claimed arrows), PageUp/PageDown by tens, Home/End clamp; typing and editing ride the inner Input; Enter commits only when a custom map frees it (default: Enter submits or advances) |
| `RadioPills`  | Horizontal pill row, single choice by arrows or mouse; windows the roster around the selection when a width is set | `withOptions(...)`, `withValue(label)`, `withTitle`, `withDescription`, `withTitleFunc` / `withDescriptionFunc`, `withWidth`  Left/Right or h/l, Home/End; pill-zone clicks when the host routes MouseMsg (press, drag and release equivalent) |

All fields share a common navigation contract: `Tab` / `↓` advances,
`Shift+Tab` / `↑` retreats (Shift+Tab arrives on the wire as CSI `Z`,
decoded to `Tab` with `shift: true`; `Alt+Tab` remains an accepted
alias), `Enter` on the last interactive field submits, `Esc` / `Ctrl+C`
aborts. Skippable fields (e.g. plain `Note`s) are passed over silently.
The bindings live in a {@see \SugarCraft\Forms\KeyMap} — pass your own
via `Form::withKeyMap()` and {@see \SugarCraft\Forms\Form::keyBinds()}
renders the active set, never the defaults.

#### Keyboard & wire shapes

- **Form-level chords** come from the active KeyMap (defaults: tab,
  shift+tab — with alt+tab kept as a legacy alias entry — enter, esc and
  ctrl+c; `keyBinds()` renders the live set, see `Form::withKeyMap()`).
- **Wire spellings:** Shift+Tab arrives on the wire as the two-byte CSI Z
  sequence (`\x1b[Z`) which the host decoder turns into Tab carrying shift;
  sugar-forms itself negotiates no kitty keyboard-protocol flags, so
  whether disambiguated chord forms (CSI 27;5u-style) ever arrive is the
  host reader's decision.
- **The eat order:** a field that claims a nav-bound key through
  `consumes()` sees it *before* the form (Text, FilePicker and the focused
  Select filter claim Enter; Date, Color, MultiSelect, NumberField and
  Select claim the arrows). A field with `consumes() === false` — Input,
  Confirm, Slider, RadioPills, Note — can never see a default-bound key:
  tab, enter, esc, ctrl+c and the arrows stop at the form door. Pass a
  custom map via `withKeyMap()` to free a key for the field; the
  field-types table above marks which arms need that.
  `tests/FormKeyDispatchTest.php` pins both polarities.
- **Paste:** the host reader delivers a PasteMsg; only TextArea consumes
  it (replacing the selection). TextInput — and NumberField's typing
  surface — accept KeyMsg events only and drop PasteMsg (v1 limitation).
- **History recall:** TextInput's Up/Down browse a host-populated list
  (`withHistory()` / `addToHistory()` feed it); under the default map the
  arrows are navigation, so recall needs a freed map (or vim-insert's
  ctrl+p / ctrl+n chords where the host drives the VimKeyHandler).

### Forms and groups

`Form::new(...$fields)` is a single-page form. For multi-page flows build
with `Form::groups(Group::new(...$fields), …)`. Each group carries its own
title / description / hide-predicate / theme override:

```php
Form::groups(
    Group::new(
        Input::new('name')->withTitle('Your name?'),
        Confirm::new('proceed')->withTitle('Continue?'),
    )->withTitle('Step 1'),
    Group::new(
        Note::new('done')->withTitle('Thanks!')->withNext()->withNextLabel('Finish'),
    )
        ->withTheme(Theme::dracula())
        ->withShowHelp(false)
        ->withHideFunc(fn (array $v) => $v['proceed'] !== true),
);
```

### Form-level chainables

| Method | What it does |
| ------ | ------------ |
| `withTheme(Theme)` | Switch the colour palette. |
| `withAccessible(bool)` | Render one `label: value` line for the focused field — for screen readers / dumb terminals. Echo-mode fields project their display characters via `displayValue()`; the stored secret is never read aloud. |
| `withShowHelp(bool)` | Toggles the multi-group `Step X of Y` indicator (view() consults it only alongside more-than-one group and the group's own help flag). It renders no footer: `keyBinds()` / `help()` compose host-facing binding rows that view() never draws. |
| `withShowErrors(bool)` | **Stored, not consumed** (SF-10, M19): fields render their `! error` line whenever an error is stored (`Field/Input.php:668` and siblings, unconditional at render) — no render path reads this flag, so today it suppresses nothing. Implementing consumption in field render vs deprecating the flag is an open owner decision; the row states the shipped truth. |
| `withWidth(int)`, `withHeight(int)` | Pin the rendered geometry. |
| `withTimeout(int $ms)` | Stores a wall-clock budget (ms) for the host runtime to consult; the `Form` model itself starts no timer. |
| `withKeyMap(KeyMap)` (or the `Form` constructor's `$keyMap` prop) | Override the bindings for `Next` / `Prev` / `Submit` / `Quit` (and per-field nav) on a single form. |
| `validateAll(): array<string,string>` | Run all field validators and return `[fieldKey => errorMessage]` for fields that failed. Use at the Program-run boundary (once the `Program` ends) to collect cross-field validation failures that cannot be expressed per-field. |

### Reading values after submit

`values()` returns every visible field keyed by `key()`. For typed access
call `string()`, `int()`, `bool()`, `array()`. For inspecting state during a
run use `errors()`, `hasErrors()`, `focusedField()`, `keyBinds()`, `help()`.
(`getString()` & friends are deprecated forwarders, removed in 2.0.0.)

### Themes & accessibility

Stock themes ship as static factories on `SugarCraft\Forms\Theme`:
`ansi()` (default), `plain()`, `charm()`, `dracula()`, `catppuccin()`,
`base16()`, `base()`. Pass one to `Form::withTheme(...)`. The accessibility
mode flips the entire form to plain-text rendering — useful when you detect
`NO_COLOR=1` or `TERM=dumb`. The accessible line is scoped to the focused
field only — no group titles, no `Step X of Y` pagination, no error-summary
block; runnable full-page shapes live under `examples/`.

### Validators and dynamic labels

`Input`, `Text`, `Confirm`, `Date` and `Slider` support `withValidator` — a ready-made validator instance or a closure (per-field union in the table above; `null` clears wherever the union admits it). The other fields (`Select`, `MultiSelect`, `Note`, `FilePicker`, `NumberField`, `RadioPills`, `Color`) carry no validator today. The closure
runs on every keystroke (or every value flip for `Confirm`) and returns
`null` for valid or an error string. The error renders inline beneath the
field and shows up in `Form::errors()`.

Use `withTitleFunc(\Closure(): string)` / `withDescriptionFunc(...)` on any
field to compute labels lazily — handy when the label depends on values from
a previous group.

#### Built-in validators

`SugarCraft\Forms\Validator` provides five ready-made validators
(`Required`, `Email`, `MinLength`, `MaxLength`, `Pattern`). All implement the
`Validator` interface (returns `true` on valid, an error `string` on
invalid). On `Input`, multiple `withValidator()` calls chain — each call appends
to the validator list and the first error wins. On the other validating fields a
second call replaces the first: `Text` holds one closure-only slot, and
`Confirm`, `Date` and `Slider` hold a single `Validator|\Closure|null` slot
(`null` clears).

| Class | Error message | Notes |
| ----- | ------------- | ----- |
| `Required` | `Value is required` | Fails on empty string only |
| `Email` | `Must be a valid email address` | Skipped when empty; uses `filter_var` |
| `MinLength(int $min)` | `Must be at least N characters` | Uses `mb_strlen` (UTF-8 safe) |
| `MaxLength(int $max)` | `Must be no more than N characters` | Uses `mb_strlen` (UTF-8 safe) |
| `Pattern(string $pattern, string $message)` | `$message` | Skipped when empty; uses `preg_match` |

Custom validators implement `SugarCraft\Forms\Validator\Validator`:

```php
final class NoSpaces implements Validator
{
    public function validate(string $input): true|string
    {
        if (str_contains($input, ' ')) {
            return 'Spaces are not allowed';
        }
        return true;
    }
}
```

#### Predicate validation and error summaries

`Input` / `Text` also take `withValidation(fn($v) => bool, $message)` — a
predicate alternative to `withValidator`. `Form::withErrorSummary(true)`
renders every failed field's message above the form when submit fails.

### Fuzzy suggestions

`Input` and `Select` support `withFuzzySuggestions()` — candidates are
ranked by Smith-Waterman local-alignment score (sugar-fuzzy) and filtered to
positive scores. The `fuzzy()` short alias is equivalent. For programmatic
scoring use `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` directly
(`SugarCraft\Forms\Fuzzy\FuzzyMatcher` remains a deprecated alias of it;
scoring defaults in `SugarCraft\Fuzzy\ScoringProfile`: match `+3`,
mismatch `-3`, gap open `-5`, gap extend `-1`, adjacent bonus `+5`).

### Async suggestions

`withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)` (alias
`async()`) fetches `list<string>` suggestions off the keystroke path with a
debounce; `SuggestionsReadyMsg` arrives via the event loop when fresh
results land.

### ActionSpinner

A standalone blocking spinner for scripts and CLIs that need a visible
"working…" indicator without a full Model–Update–View program (folded in
from the retired prompt façade; the canonical class is `SugarCraft\Forms\ActionSpinner`):

```php
use SugarCraft\Forms\ActionSpinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;

ActionSpinner::new()
    ->withTitle('Crunching numbers...')
    ->withStyle(SpinnerStyle::dot())
    ->withAction(static function () {
        // ... long-running work ...
        // Communicate results out-of-band: the action runs in a forked
        // child on pcntl hosts; in-memory mutations are not visible to
        // the parent after run() returns.
    })
    ->run();
```

**Fork semantics on pcntl hosts:** the action executes in a forked child.
Return values and in-memory mutations are **not** visible to the parent; a
throwing action surfaces as a `\RuntimeException` from `run()` (the original
exception type/message cannot cross the fork boundary). The child is bounded
by a wall-clock ceiling (default 600 s; tune with
`withMaxRuntimeSeconds()`). On expiry the reaper escalates TERM→KILL and
`run()` throws — "was terminated" (child reaped) or "could not be
terminated" with the abandoned child's pid named. Communicate results via
tempfile, pipe, database, or other out-of-band mechanism. On hosts without
`pcntl_fork`, the action runs inline (no animation, no fork) and exceptions
propagate normally.

### Demos

![confirm](.vhs/confirm.gif) ![input](.vhs/input.gif) ![select](.vhs/select.gif)
![multi-select](.vhs/multi-select.gif) ![text](.vhs/text.gif) ![themes](.vhs/themes.gif)
![multi-page form](.vhs/prompt-form.gif)

## Security & trust boundary

Form content is frequently model- or network-produced (agent UIs), so every
display surface runs hostile bytes through `RenderSafe::clean()`: C0/DEL
bytes are stripped (TAB and LF survive) and every ESC-introduced sequence is
disarmed **except true SGR** (`\x1b[[0-9;]*m`), so theme colour renders while
screen clears, cursor moves and OSC payloads (e.g. OSC-8 hyperlinks) become
inert text. The sweep is idempotent and UTF-8 safe (C1 bytes are kept — they
overlap multi-byte sequences).

Cleaned at render: dynamic labels/descriptions, per-field error lines,
Confirm button labels, RadioPills and MultiSelect options, ItemList rows
behind Select, FilePicker cwd/entries/selection, errorHelp rows, the text
echo (Normal and Mask arms of `TextInput::displayedValue()`), the TextArea
render, the accessible view line, and the error summary.

The boundary is the **display**, never the buffer: `value()` returns pasted
bytes verbatim, and dev-authored strings (placeholder text) render raw —
they are trusted input by construction. Mirrors upstream bubbletea's
Viewport sanitisation for externally-sourced content.

## Shared foundations

sugar-forms is built on five shared packages:

- **[sugar-cellbuffer](https://github.com/sugarcraft/sugar-cellbuffer)** — Cell-grid model + `Buffer::toAnsi()` for
  rendering glyphs and styles to ANSI bytes. TextInput / TextArea use the buffer
  path internally; their snapshot tests pin byte-for-byte output.
- **[sugar-constraints](https://github.com/sugarcraft/sugar-constraints)** — `LayoutSolver` + constraint types
  (`Constraint::length`, `Constraint::fill`, etc.). `Form::withConstraints()` stores
  an explicit constraint set on the form; solver region routing is a reserved
  surface — `Form::view()` does not consult it yet, so greedy vertical stacking
  is always used today.
- **[sugar-testing](https://github.com/sugarcraft/sugar-testing)** — `Assertions::assertGoldenAnsi` and
  `Assertions::assertCellGrid` snapshot helpers. Render fixtures live in
  `tests/fixtures/`; set `UPDATE_GOLDENS=1` to regenerate them.
- **[sugar-fuzzy](https://github.com/sugarcraft/sugar-fuzzy)** — `SmithWatermanMatcher` scoring,
  used ONLY by `Select::withFuzzySuggestions()` to rank the suggestion candidates
  (`src/Field/Select.php:326-330`, suggestions listed score-ranked at `:131-135`).
  The Select list filter itself is the plain case-insensitive substring match over
  `Item::filterValue()` inside this lib (`src/ItemList/ItemList.php:248-251`) —
  typing k finds Kitchen because 'kitchen' contains 'k', not by scoring. There is
  no public injection point for an alternative matcher, and MultiSelect does no
  fuzzy matching at all (k is cursor-up there; digits 1-9 are jump-toggles).
- **[sugar-async](https://github.com/sugarcraft/sugar-async)** — `Async\Await` + `CancelledException`
  for async suggestion fetchers. `withAsyncSuggestions` stores a
  `CancellationSource` on the model and cancels the previous timer before
  scheduling the next, so rapid keystrokes only fire one network request.
- **Vim keybindings** — `VimKeyHandler` provides a shared vim mode handler
  (`VimState`: Insert/Normal/Visual/VisualLine; `VimAction`: CursorLeft,
  CursorRight, CursorUp, CursorDown, DeleteChar, YankLine, …). Both text
  widgets run on it — TextInput single-line, TextArea multi-line (TUI-3B:
  `j`/`k` rows, `v` visual selection) — and sugar-widgets and
  sugar-readline delegate to it — new bindings benefit all consumers at once.
  In `VisualLine` mode, however, `j`/`k` step between ENTRIES (the pinned
  horizontal CursorLeft/CursorRight vocabulary of the E736 3.2 owner ruling),
  not rows, and `V` currently aliases `v` into character-wise selection.
  Text objects (`ci"`, `da{`, `yiw`, …) resolve through
  `VimKeyHandler::handleTextObject()`: `VimOperator` (c/d/y) +
  `TextObjectScope` (i/a) + a target key feed `TextObject::resolve()`,
  which returns the `[start, end)` character range on the buffer —
  innermost bracket pair wins, quotes pair left-to-right, unmatched
  delimiters return null (vim's beep).

The legacy `SugarCraft\Forms\Fuzzy\FuzzyMatcher` (step-07 back-compat shim)
remains as a deprecated alias — it delegates to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`
with no behavioural divergence.

## Accessor deprecation (2026-10-24)

The house accessor era (sugar-style precedent) renames bare reads off the
`get*` prefix. In sugar-forms, 34 reads were renamed to bare twins with a
one-line `@deprecated` forwarder (each marked `REMOVED-IN: 2.0.0`) riding the
old name; 19 name-blocked reads keep their `get*` prefix as documented legal
exceptions because the bare slot is a live fluent setter (or the `Field`
contract itself binds the name) — PHP admits one method per name.

The `Field` interface carries two abstract contract members through the same
era: `getDescription()` and `getError()` are now `@deprecated` contract
members whose canonical spelling is `description()` / `error()`. `getTitle()`
is an interface-level exception: eleven of the twelve implementing fields
bind the bare `title(string $title): self` setter, so the interface cannot
adopt a bare read. Renamed class-level pairs:

| Legacy read | Bare read |
| --- | --- |
| `Color::getDescription()` | `description()` |
| `Color::getError()` | `error()` |
| `Confirm::getDescription()` | `description()` |
| `Confirm::getError()` | `error()` |
| `Date::getDescription()` | `description()` |
| `Date::getError()` | `error()` |
| `FilePicker::getDescription()` | `description()` |
| `FilePicker::getError()` | `error()` |
| `Input::getDescription()` | `description()` |
| `Input::getError()` | `error()` |
| `MultiSelect::getDescription()` | `description()` |
| `MultiSelect::getError()` | `error()` |
| `Note::getDescription()` | `description()` |
| `Note::getError()` | `error()` |
| `NumberField::getDescription()` | `description()` |
| `NumberField::getError()` | `error()` |
| `RadioPills::getDescription()` | `description()` |
| `RadioPills::getError()` | `error()` |
| `Select::getDescription()` | `description()` |
| `Select::getError()` | `error()` |
| `Slider::getDescription()` | `description()` |
| `Slider::getError()` | `error()` |
| `Text::getDescription()` | `description()` |
| `Text::getError()` | `error()` |
| `Form::getArray()` | `array()` |
| `Form::getBool()` | `bool()` |
| `Form::getFocusedField()` | `focusedField()` |
| `Form::getInt()` | `int()` |
| `Form::getString()` | `string()` |
| `TextArea::getRowOffset()` | `rowOffset()` |
| `Viewport::getHeight()` | `height()` |
| `Viewport::getWidth()` | `width()` |

Roster and shapes are pinned by `tests/AccessorDeprecationTest.php` (census,
markers, forwarder delegation, BC identity, and the exception list with its
collision reasons).

## Dependencies

Runtime requires (see `composer.json`):

- `sugarcraft/sugar-core` — Model–Update–View TUI runtime
- `sugarcraft/sugar-style` — Declarative styling
- `sugarcraft/sugar-async` — async suggestion support (cancellation sources)
- `sugarcraft/sugar-cellbuffer` — cell-grid rendering for the raw widgets
- `sugarcraft/sugar-constraints` — constraint types stored by `Form::withConstraints()` (solver routing reserved, not yet wired)
- `sugarcraft/sugar-fuzzy` — Smith-Waterman suggestion scoring

(`sugarcraft/sugar-testing` is a dev-only require, used by the golden
snapshot tests.)

### Versioning

Sibling `sugarcraft/*` requirements are pinned to `dev-master` and the
manifest carries `minimum-stability: dev`. That is intentional while the
monorepo pre-dates 1.0: every library moves together and published installs
resolve siblings from Packagist `dev-master` (path repositories are
injected by CI at build time, never committed). At the stable release the
pins become proper `^1.0` constraints — track this in the release plan
(E736 plan 7.4).

## Snapshot tests

Render output is covered by golden-file snapshot tests. Fixture files live
in `tests/fixtures/` with a `.golden` extension and are compared against
actual ANSI byte output via `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()`.
To re-record fixtures after intentional output changes:

```sh
UPDATE_GOLDENS=1 vendor/bin/phpunit
```

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
