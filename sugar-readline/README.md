<img src=".assets/icon.png" alt="sugar-readline" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-readline)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-readline)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-readline?label=packagist)](https://packagist.org/packages/sugarcore/sugar-readline)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarReadline

PHP port of [erikgeiser/promptkit](https://github.com/erikgeiser/promptkit) — interactive line-editing prompt library for terminal UIs.

## Features

- **TextPrompt** — single-line input with validation, auto-completion, hidden/password mode, char limit, default value
- **SelectionPrompt** — filtered list with cursor navigation and pagination
- **MultiSelectPrompt** — filtered multi-choice with min/max enforcement and FIFO rollover at the cap
- **ConfirmationPrompt** — yes/no with customizable labels, decoupled select-vs-submit
- **TextareaPrompt** — multi-line text input with line/column cursor and optional max-line cap
- **Pure renderer** — every method returns a new immutable instance; `view()` returns ANSI strings, `value()` returns the data
- **Vim keybindings** — vi-mode (Insert/Normal/Visual/VisualLine) handled by the shared `candy-forms` `VimKeyHandler` — the same handler backing `sugar-prompt` and `sugar-bits`; new bindings in `VimAction` enum benefit all three libs

## Install

```bash
composer require sugarcraft/sugar-readline
```

## Quick Start

`Readline` reads real TTY keypresses via `candy-input`'s `InputDriver`. In production, `StreamInputDriver::fromStdin()` is the default — no configuration needed. For testing, inject a driver over a fixture stream.

### Text Prompt

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$readline = Readline::fromStdin();

$prompt = TextPrompt::new('Enter your name: ')
    ->withDefault('Anonymous')
    ->withCompletions(['Alice', 'Bob', 'Carol']);

$result = $readline->run($prompt);
echo $result->value();  // 'Alice' (after typing + Tab + Enter)
```

### Selection Prompt

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\SelectionPrompt;

$result = Readline::fromStdin()->run(
    SelectionPrompt::new('Choose a fruit:', ['Apple', 'Banana', 'Cherry', 'Date'])
        ->withFilter('an')   // Banana matches
);
echo $result->selectedValue();  // 'Banana'
```

### Multi-Select Prompt

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\MultiSelectPrompt;

$result = Readline::fromStdin()->run(
    MultiSelectPrompt::new('Pick:', ['A', 'B', 'C'])
        ->withMinSelections(1)
);
print_r($result->selectedValues());  // ['A', 'B'] after navigation + Enter
```

### Confirmation Prompt

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\ConfirmationPrompt;

$result = Readline::fromStdin()->run(
    ConfirmationPrompt::new('Delete file?')
);
echo $result->result() ? 'yes' : 'no';  // 'yes' or 'no'
```

### Custom Key Handlers

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$result = Readline::fromStdin()
    ->onKey('ctrl_c', fn($event) => print("aborted\n"))
    ->onKey('ctrl_u', fn($event) => print("cleared\n"))
    ->run(TextPrompt::new('> '));

echo $result->value();
```

## Input Driver

`Readline` accepts an optional `SugarCraft\Input\InputDriver` to control where input comes from. Production code uses the default `StreamInputDriver::fromStdin()` which needs no configuration. Tests inject a driver over a fixture stream for deterministic byte-fed test cases.

```php
// Production: reads real TTY keypresses (default)
$readline = new Readline();                        // uses StreamInputDriver::fromStdin()
$readline = Readline::fromStdin();                  // equivalent

// Testing: inject a fake stream
$fake = fopen('php://memory', 'r+');
fwrite($fake, "hello\x0d");                          // \x0d = Enter
rewind($fake);
$driver = new StreamInputDriver($fake);
$readline = new Readline($driver);
$result = $readline->run(TextPrompt::new('> '));
// $result->value() === 'hello'
```

## Key Bindings

The `SugarCraft\Readline\Key` class exposes symbolic constants for every supported key.

- `Key::Left` / `Key::Right` — move cursor (text input)
- `Key::Up` / `Key::Down` — navigate selection list / change line in textarea
- `Key::PageUp` / `Key::PageDown` — page through long lists
- `Key::Home` / `Key::End` — jump within the current line / list
- `Key::Enter` — submit text or select current choice
- `Key::Space` — toggle mark in multi-select
- `Key::Tab` — auto-complete or toggle confirmation value
- `Key::Backspace` / `Key::Delete` — delete characters
- `Key::CtrlU` / `Key::CtrlK` — delete to start / end of line
- `Key::Escape` / `Key::CtrlC` — abort

## Submit / Abort Semantics

Each prompt is a state machine with three states: pending, submitted, aborted.

- `submit()` finalises the prompt; for `MultiSelectPrompt` it only succeeds when `canSubmit()` is true.
- `abort()` (or feeding `Key::Escape` / `Key::CtrlC`) discards the prompt; `value()` / `selectedValues()` then return empty.
- `isSubmitted()` / `isAborted()` report status; `currentValue()` (Confirmation) and `selectedValue()` (Selection) reflect the current cursor regardless of submission state.

## License

[MIT](LICENSE)
