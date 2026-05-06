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

- **TextPrompt** — single-line input with validation, auto-completion, hidden/password mode, default value editing
- **SelectionPrompt** — filtered list with cursor navigation, pagination, multi-select support
- **ConfirmationPrompt** — yes/no confirmation with customizable labels
- **TextareaPrompt** — multi-line text input with cursor movement
- **Pure renderer** — outputs ANSI strings; works with any TUI framework

## Install

```bash
composer require candycore/sugar-readline
```

## Quick Start

### Text Prompt

```php
use CandyCore\Readline\TextPrompt;

$prompt = TextPrompt::new('Enter your name:');
$prompt = $prompt->WithDefault('Anonymous');
$prompt = $prompt->WithCompletion(['Alice', 'Bob', 'Carol']);

// Simulate input
$prompt = $prompt->HandleChar('A');
$prompt = $prompt->HandleChar('l');
$prompt = $prompt->Confirm();  // submit

echo $prompt->Value();  // 'Al'
```

### Selection Prompt

```php
use CandyCore\Readline\SelectionPrompt;

$prompt = SelectionPrompt::new('Choose a fruit:', ['Apple', 'Banana', 'Cherry', 'Date']);
$prompt = $prompt->Filter('an');  // filter by 'an' -> Banana

echo $prompt->SelectedValue();  // 'Banana'
```

### Confirmation Prompt

```php
use CandyCore\Readline\ConfirmationPrompt;

$prompt = ConfirmationPrompt::new('Delete file?');
$prompt = $prompt->Confirm();   // true
// or $prompt->Cancel();         // false
```

## Key Bindings

- `←/→` — move cursor (text input)
- `↑/↓` — navigate selection list
- `Enter` — confirm selection / submit text
- `Esc` — cancel / clear filter
- `Ctrl+C` — cancel
- `Tab` — auto-complete
- `Backspace` — delete character

## License

[MIT](LICENSE)
