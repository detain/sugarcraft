<img src=".assets/icon.png" alt="sugar-prompt" width="160" align="right">

# SugarPrompt

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-prompt)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-prompt)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-prompt?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-prompt)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/form.gif)
```sh
composer require sugarcraft/sugar-prompt
```

PHP port of [charmbracelet/huh](https://github.com/charmbracelet/huh) —
interactive form library built on top of SugarCraft + SugarBits.

```php
use SugarCraft\Prompt\Form;
use SugarCraft\Prompt\Field\{Input, Confirm, Select, Note};

$form = Form::new(
    Note::new('welcome')->title('Onboarding')->desc('A few quick questions.'),
    Input::new('name')->title('Your name?')->placeholder('Ada Lovelace'),
    Confirm::new('newsletter')->title('Subscribe to the newsletter?'),
    Select::new('lang')->title('Favorite language?')->options('PHP', 'Go', 'Rust', 'Python'),
);
// $form is a SugarCraft Model — drop it into a Program.
```

> Every field exposes short-form aliases (`title`, `desc`, `placeholder`,
> `width`, `height`, `validator`, `options`, `min`, `max`, …). The
> upstream-mirroring long forms (`withTitle`, `withDescription`, …)
> work identically — pick whichever reads better at the call site.

## Shared foundations

sugar-prompt is built on top of five shared foundation packages:

| Package | Role |
| ------- | ---- |
| `sugarcraft/candy-async` | Async/await engine — drives `withAsyncSuggestions` cancellable fetchers via `Async\Await` + `CancelledException` |
| `sugarcraft/candy-buffer` | Ring-buffer output renderer — handles SGR sequence batching and viewport sync for the form viewport |
| `sugarcraft/candy-fuzzy` | Smith-Waterman local-alignment fuzzy matcher — powers `withFuzzySuggestions()` on `Input` and `Select` fields |
| `sugarcraft/candy-testing` | Test harness — provides `ProgramSimulator`, `ScriptedInput`, and golden-file tape helpers for TEA-program tests |
| **Vim keybindings** | **Via candy-forms `VimKeyHandler`** — `TextInput` vim mode (Insert/Normal/Visual) is shared across all 4 libs; new bindings added to `VimAction` enum benefit sugar-prompt automatically |

## Field types

| Field         | Description                                                 | Notable knobs |
| ------------- | ----------------------------------------------------------- | ------------- |
| `Input`       | Single-line text (wraps `SugarBits\TextInput`)              | `withPlaceholder`, `withCharLimit`, `withWidth`, `withPrompt`, `withValidator(\Closure)`, `withTitleFunc` / `withDescriptionFunc`, `withPassword(bool, string $echo = '*')`, `withSuggestions(list<string>)`, `withSuggestionsFunc(\Closure(string):list<string>)`, `withFuzzySuggestions(list<string>)`, `withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)` |
| `Text`        | Multi-line text editor                                      | `withCharLimit`, `withMaxLines`, `withShowLineNumbers`, `withValidator` |
| `Confirm`     | Yes/no boolean                                              | `withAffirmative`/`withNegative`, `withValidator(\Closure(bool):?string)`, `withTitleFunc`, `withDescriptionFunc` |
| `Select`      | Single-choice list (wraps `SugarBits\ItemList`)             | `withOptions(...)`, `withTitleFunc`, `withDescriptionFunc`, `withFuzzySuggestions(list<string>)`, `withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)`, `withEnum(\BackedEnum::class)` |
| `MultiSelect` | Multi-choice list (j/k vim keys + space to toggle)          | `withOptions(...)`, `withLimit(int)` |
| `Note`        | Read-only paragraph; skipped by tab navigation              | `withTitle`, `withDescription`, `withHeight(int)`, `withNext(bool)`, `withNextLabel(string)` (turns it into an interactive button page) |
| `FilePicker`  | Filesystem picker (wraps `SugarBits\FileTree`)              | `withCwd`, `withAllowDirs`, `withAllowFiles`, `withShowSize`, `withShowHidden` |

All fields share a common navigation contract: `Tab` / `↓` advances,
`Shift+Tab` / `↑` retreats, `Enter` on the last interactive field
submits, `Esc` / `Ctrl+C` aborts. Skippable fields (e.g. plain
`Note`s) are passed over silently.

## Forms and groups

`Form::new(...$fields)` is a single-page form. For multi-page flows
build with `Form::groups(Group::new(...$fields), …)`. Each group
carries its own title / description / hide-predicate / theme override:

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
| `keyMap(KeyMap)` / `withKeyMap(KeyMap)` | Override the bindings for `Next` / `Prev` / `Submit` / `Quit` (and per-field nav) on a single form. Mirrors upstream huh #272. |
| `validateAll(): array<string,string>` | Run all field validators and return `[fieldKey => errorMessage]` for fields that failed. Use after `Form::run()` to collect cross-field validation failures that cannot be expressed per-field. |

### Reading values after submit

`values()` returns every visible field keyed by `key()`. For typed
access call `getString`, `getInt`, `getBool`, `getArray`. For
inspecting validation state during a run use `errors()`,
`hasErrors()`, `getFocusedField()`, `keyBinds()`, `help()`.

### Themes & accessibility

Stock themes ship as static factories on `SugarCraft\Prompt\Theme`:
`ansi()` (default), `plain()`, `charm()`, `dracula()`, `catppuccin()`,
`base16()`. Pass one to `Form::withTheme(...)`. The accessibility
mode flips the entire form to plain-text rendering — useful when you
detect `NO_COLOR=1` or `TERM=dumb`.

### Validators and dynamic labels

Every value-producing field supports `withValidator(\Closure)`. The
closure runs on every keystroke (or every value flip for `Confirm`)
and returns `null` for valid or an error string. The error renders
inline beneath the field and shows up in `Form::errors()`.

Use `withTitleFunc(\Closure(): string)` / `withDescriptionFunc(...)`
on any field to compute labels lazily — handy when the label depends
on values from a previous group.

### Built-in validators

`SugarCraft\Prompt\Validator` provides five ready-made validators that
cover the most common input constraints. All five implement the
`Validator` interface (which returns `true` on valid input, an error
`string` on invalid). Pass one or more to `Input::withValidator()` —
multiple calls chain validators together, each running in sequence with
the first error message winning.

```php
use SugarCraft\Prompt\Form;
use SugarCraft\Prompt\Field\Input;
use SugarCraft\Prompt\Validator\{Required, Email, MinLength, MaxLength, Pattern};

$form = Form::new(
    Input::new('name')
        ->withTitle('Full name')
        ->withPlaceholder('Ada Lovelace')
        ->withValidator(new Required())
        ->withValidator(new MinLength(2)),
    Input::new('email')
        ->withTitle('Email address')
        ->withPlaceholder('you@example.com')
        ->withValidator(new Required())
        ->withValidator(new Email()),
    Input::new('username')
        ->withTitle('Username')
        ->withPlaceholder('ada_lovelace')
        ->withValidator(new Required())
        ->withValidator(new MinLength(3))
        ->withValidator(new MaxLength(20))
        ->withValidator(new Pattern('/^[a-z0-9_]+$/i', 'Only letters, numbers, and underscores')),
);
```

| Class | Error message | Notes |
| ----- | ------------- | ----- |
| `Required` | `Value is required` | Fails on empty string only |
| `Email` | `Must be a valid email address` | Skipped when empty; uses `filter_var` |
| `MinLength(int $min)` | `Must be at least N characters` | Uses `mb_strlen` (UTF-8 safe) |
| `MaxLength(int $max)` | `Must be no more than N characters` | Uses `mb_strlen` (UTF-8 safe) |
| `Pattern(string $pattern, string $message)` | `$message` | Skipped when empty; uses `preg_match` |

To create a custom validator, implement `Validator` yourself:

```php
use SugarCraft\Prompt\Validator\Validator;

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

### Fuzzy suggestions

`Input` and `Select` fields support `withFuzzySuggestions()` for
fuzzy substring matching via Smith-Waterman local alignment scoring.
Candidates are ranked by score and filtered to only matches with a
positive score.

```php
use SugarCraft\Prompt\Form;
use SugarCraft\Prompt\Field\{Input, Select};

$form = Form::new(
    Input::new('language')
        ->withTitle('Pick a language')
        ->withFuzzySuggestions(['PHP', 'Python', 'Go', 'Rust', 'JavaScript', 'TypeScript']),
    Select::new('framework')
        ->withTitle('Pick a framework')
        ->withOptions(['Laravel', 'Symfony', 'Rails', 'Django', 'FastAPI', 'Fiber'])
        ->withFuzzySuggestions(['Laravel', 'Symfony', 'Rails', 'Django', 'FastAPI', 'Fiber']),
);
```

The `fuzzy()` short alias is equivalent:

```php
->fuzzy(['PHP', 'Python', 'Go', 'Rust'])
```

For fine-grained control, use `FuzzyMatcher` directly:

```php
use SugarCraft\Prompt\Fuzzy\FuzzyMatcher;

$matcher = new FuzzyMatcher();

// Score a single candidate (higher = better match)
$score = $matcher->score('js', 'JavaScript'); // 9

// Rank all candidates — returns list<[string, int]> sorted by score desc
$matches = $matcher->match('py', ['Python', 'PHP', 'Ruby', 'JavaScript']);
// [['Python', 8], ['JavaScript', 1]]
```

Scoring constants: match=`+3`, mismatch=`-3`, gap open=`-5`, gap extend=`-1`,
adjacent bonus=`+5` for consecutive matches.

### Async suggestions

`Input` and `Select` support `withAsyncSuggestions()` for suggestions
fetched asynchronously with a debounce delay. The `$fetcher` callable
receives the current query string and must return `list<string>`.

The default debounce is 150 ms — tuned to avoid firing on every keystroke
while still feeling responsive. A `SuggestionsReadyMsg` is dispatched
via the event loop when fresh suggestions are available.

```php
use SugarCraft\Prompt\Form;
use SugarCraft\Prompt\Field\Input;
use React\Async\defer;

$form = Form::new(
    Input::new('language')
        ->withTitle('Pick a language')
        ->withAsyncSuggestions(
            defer(fn($query) => fetchFromApi($query)),
            150,
        ),
);
```

The `async()` short alias is equivalent:

```php
->async(fn($query) => fetchFromApi($query))
```

## Validation

### Field-level validation with `withValidation`

`Input` and `Text` fields support `withValidation()` for predicate-based
validation — a cleaner alternative to `withValidator`:

```php
$input = Input::new('email')
    ->withTitle('Email address')
    ->withPlaceholder('you@example.com')
    ->withValidation(fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL), 'Must be a valid email');

$text = Text::new('bio')
    ->withTitle('Biography')
    ->withValidation(fn($v) => mb_strlen($v) >= 10, 'Must be at least 10 characters');
```

The predicate receives the field value and must return `true` for valid
or `false` for invalid. The error message renders inline beneath the field
and is collected into `Form::errors()`.

### Error summary with `withErrorSummary`

Enable `withErrorSummary()` on a `Form` to display all validation errors
at the end when submission fails:

```php
$form = Form::new(
    Input::new('name')
        ->withTitle('Name')
        ->withValidation(fn($v) => !empty(trim($v)), 'Name is required'),
    Input::new('email')
        ->withTitle('Email')
        ->withValidation(fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL), 'Must be a valid email'),
)->withErrorSummary(true);
```

When enabled and the form is submitted with errors, an error summary
renders above the form listing every failed field and its error message.

## Test

```sh
cd sugar-prompt && composer install && vendor/bin/phpunit
```

## Demos

### Confirm

![confirm](.vhs/confirm.gif)

### Multi-page form

![form](.vhs/form.gif)

### Input

![input](.vhs/input.gif)

### Multi-select

![multi-select](.vhs/multi-select.gif)

### Select

![select](.vhs/select.gif)

### Text (multi-line)

![text](.vhs/text.gif)

### Themes

![themes](.vhs/themes.gif)

