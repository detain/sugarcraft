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
  MultiSelect,Note,FilePicker}`, `Form`, `Group`, `KeyMap`, `Theme`,
  `Fuzzy\FuzzyMatcher`, and `Validator\{Required,Email,MinLength,MaxLength,Pattern}`.

`sugar-widgets` (`SugarCraft\Widgets\*`) exposes these classes as thin
`class_alias` shims pointing back here, so existing imports keep working
while the canonical implementation lives in `SugarCraft\Forms\*`.

## Quickstart

A single-field form:

```php
<?php

use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Validator\Required;

$form = Form::new(
    Input::new('name')
        ->withTitle('What is your name?')
        ->withValidator(new Required())
);

echo $form->view();
```

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

Multi-page Group wizards, six themes, per-field validators, conditional skip
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

> Every field exposes short-form aliases (`title`, `desc`, `placeholder`,
> `width`, `height`, `validator`, `options`, `min`, `max`, …). The long forms
> (`withTitle`, `withDescription`, …) work identically — pick whichever reads
> better at the call site.

### Field types

| Field         | Description                                                 | Notable knobs |
| ------------- | ----------------------------------------------------------- | ------------- |
| `Input`       | Single-line text (wraps `SugarCraft\Widgets\TextInput`)             | `withPlaceholder`, `withCharLimit`, `withWidth`, `withPrompt`, `withValidator(\Closure)`, `withTitleFunc` / `withDescriptionFunc`, `withPassword(bool, string $echo = '*')`, `withSuggestions(list<string>)`, `withSuggestionsFunc(\Closure(string):list<string>)`, `withFuzzySuggestions(list<string>)`, `withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)` |
| `Text`        | Multi-line text editor                                      | `withCharLimit`, `withMaxLines`, `withShowLineNumbers`, `withValidator` |
| `Confirm`     | Yes/no boolean                                              | `withAffirmative`/`withNegative`, `withValidator(\Closure(bool):?string)`, `withTitleFunc`, `withDescriptionFunc` |
| `Select`      | Single-choice list (wraps `SugarCraft\Widgets\ItemList`)            | `withOptions(...)`, `withTitleFunc`, `withDescriptionFunc`, `withFuzzySuggestions(list<string>)`, `withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)`, `withEnum(\BackedEnum::class)` |
| `MultiSelect` | Multi-choice list (j/k vim keys + space to toggle)          | `withOptions(...)`, `withLimit(int)` |
| `Note`        | Read-only paragraph; skipped by tab navigation              | `withTitle`, `withDescription`, `withHeight(int)`, `withNext(bool)`, `withNextLabel(string)` (turns it into an interactive button page) |
| `FilePicker`  | Filesystem picker (wraps `SugarCraft\Widgets\FilePicker`)          | `withCwd`, `withAllowDirs`, `withAllowFiles`, `withShowSize`, `withShowHidden` |
| `Date`        | Calendar-grid date picker (`YYYY-MM-DD` value, arrow-walked grid) | `withValue(?string)`, `withWidth`, `withValidator(\Closure)` |
| `Slider`      | Numeric range slider (keyboard drag, snapping step)         | `withMin` / `withMax` / `withStep`, `withValue(int|float)`, `withValidator` |
| `Color`       | Hex color picker with swatch preview                        | `withValue(string $hex)`, `withTruecolor(bool)`, `withValidator` |

All fields share a common navigation contract: `Tab` / `↓` advances,
`Shift+Tab` / `↑` retreats, `Enter` on the last interactive field submits,
`Esc` / `Ctrl+C` aborts. Skippable fields (e.g. plain `Note`s) are passed
over silently.

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
| `withAccessible(bool)` | Render plain `label: value` text — for screen readers / dumb terminals. |
| `withShowHelp(bool)` | Toggle the help footer. |
| `withShowErrors(bool)` | Toggle the inline `! error` line on validation failures. |
| `withWidth(int)`, `withHeight(int)` | Pin the rendered geometry. |
| `withTimeout(int $ms)` | Auto-abort after `$ms` of wall clock. |
| `withKeyMap(KeyMap)` (or the `Form` constructor's `$keyMap` prop) | Override the bindings for `Next` / `Prev` / `Submit` / `Quit` (and per-field nav) on a single form. |
| `validateAll(): array<string,string>` | Run all field validators and return `[fieldKey => errorMessage]` for fields that failed. Use after `Form::run()` to collect cross-field validation failures that cannot be expressed per-field. |

### Reading values after submit

`values()` returns every visible field keyed by `key()`. For typed access
call `getString`, `getInt`, `getBool`, `getArray`. For inspecting validation
state during a run use `errors()`, `hasErrors()`, `getFocusedField()`,
`keyBinds()`, `help()`.

### Themes & accessibility

Stock themes ship as static factories on `SugarCraft\Forms\Theme`:
`ansi()` (default), `plain()`, `charm()`, `dracula()`, `catppuccin()`,
`base16()`, `base()`. Pass one to `Form::withTheme(...)`. The accessibility
mode flips the entire form to plain-text rendering — useful when you detect
`NO_COLOR=1` or `TERM=dumb`.

### Validators and dynamic labels

Every value-producing field supports `withValidator(\Closure)`. The closure
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
invalid). Multiple `withValidator()` calls chain, first error wins.

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

## Shared foundations

sugar-forms is built on five shared packages:

- **[candy-buffer](/sugarcraft/candy-buffer)** — Cell-grid model + `Buffer::toAnsi()` for
  rendering glyphs and styles to ANSI bytes. TextInput / TextArea use the buffer
  path internally; their snapshot tests pin byte-for-byte output.
- **[sugar-constraints](/sugarcraft/sugar-constraints)** — `LayoutSolver` + constraint types
  (`Constraint::length`, `Constraint::fill`, etc.). `Form::withConstraints()` routes
  through the solver when an explicit constraint set is provided.
- **[candy-testing](/sugarcraft/candy-testing)** — `Assertions::assertGoldenAnsi` and
  `Assertions::assertCellGrid` snapshot helpers. Render fixtures live in
  `tests/fixtures/`; set `UPDATE_GOLDENS=1` to regenerate them.
- **[sugar-fuzzy](/sugarcraft/sugar-fuzzy)** — `SmithWatermanMatcher` scores.
  Select / MultiSelect filter delegates to it internally; the existing public
  `withFilter(callable)` injection point is preserved for custom filters.
- **[candy-async](/sugarcraft/candy-async)** — `Async\Await` + `CancelledException`
  for async suggestion fetchers. `withAsyncSuggestions` stores a
  `CancellationSource` on the model and cancels the previous timer before
  scheduling the next, so rapid keystrokes only fire one network request.
- **Vim keybindings** — `VimKeyHandler` provides a shared vim mode handler
  (`VimState`: Insert/Normal/Visual/VisualLine; `VimAction`: CursorLeft,
  CursorRight, DeleteChar, YankLine, …). sugar-widgets and
  sugar-readline delegate to it — new bindings benefit all 3 libs at once.
  Text objects (`ci"`, `da{`, `yiw`, …) resolve through
  `VimKeyHandler::handleTextObject()`: `VimOperator` (c/d/y) +
  `TextObjectScope` (i/a) + a target key feed `TextObject::resolve()`,
  which returns the `[start, end)` character range on the buffer —
  innermost bracket pair wins, quotes pair left-to-right, unmatched
  delimiters return null (vim's beep).

The legacy `SugarCraft\Forms\Fuzzy\FuzzyMatcher` (step-07 back-compat shim)
remains as a deprecated alias — it delegates to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`
with no behavioural divergence.

## Dependencies

- `sugarcraft/candy-core` — Model–Update–View TUI runtime
- `sugarcraft/candy-sprinkles` — Declarative styling

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
