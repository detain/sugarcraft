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

## Install

```bash
composer require sugarcraft/sugar-readline
```

## Quick Start

### Text Prompt

```php
use SugarCraft\Readline\{Key, TextPrompt};

$p = TextPrompt::new('Enter your name: ')
    ->withDefault('Anonymous')
    ->withCompletions(['Alice', 'Bob', 'Carol']);

$p = $p->handleChar('A')->handleChar('l')->handleKey(Key::Tab)->submit();
echo $p->value();  // 'Alice'
```

### Selection Prompt

```php
use SugarCraft\Readline\SelectionPrompt;

$p = SelectionPrompt::new('Choose a fruit:', ['Apple', 'Banana', 'Cherry', 'Date'])
    ->withFilter('an');                 // Banana matches
echo $p->selectedValue();              // 'Banana'
```

### Multi-Select Prompt

```php
use SugarCraft\Readline\{Key, MultiSelectPrompt};

$p = MultiSelectPrompt::new('Pick:', ['A', 'B', 'C'])
    ->withMinSelections(1)
    ->handleKey(Key::Space)              // mark A
    ->handleKey(Key::Down)
    ->handleKey(Key::Space)              // mark B
    ->handleKey(Key::Enter);             // submit (min satisfied)

print_r($p->selectedValues());          // ['A', 'B']
```

### Confirmation Prompt

```php
use SugarCraft\Readline\{ConfirmationPrompt, Key};

$p = ConfirmationPrompt::new('Delete file?')
    ->handleKey('n')                     // selects No (does not auto-submit)
    ->handleKey(Key::Left)               // changes mind back to Yes
    ->submit();
echo $p->result() ? 'yes' : 'no';       // 'yes'
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
