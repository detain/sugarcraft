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
- **Vim keybindings** — vi-mode (Insert/Normal/Visual/VisualLine) handled by the shared `candy-forms` `VimKeyHandler` — the same handler backing `sugar-prompt` and `sugar-bits`; new bindings in `VimAction` enum benefit all three libs. Includes text objects: `ci"`, `di(`, `da{`, `yiw` and friends

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
- `Key::CtrlR` / `Key::CtrlS` — reverse / forward incremental history search
- `Key::Escape` / `Key::CtrlC` — abort

## Editing modes (Vi vs Emacs)

`TextPrompt` uses a dual-engine architecture. The prompt itself owns the
buffer, cursor, history, and rendering; an optional **mode** object attached
via `withMode()` owns the key-to-operation mapping:

```php
use SugarCraft\Readline\Mode\EmacsMode;
use SugarCraft\Readline\Mode\ViMode;

$prompt = TextPrompt::new('> ')->withMode(new EmacsMode()); // readline bindings
$prompt = TextPrompt::new('> ')->withMode(new ViMode());    // modal vi bindings
```

Every key fed to `TextPrompt::handleKey()` is delegated to the attached
`Mode\ModeInterface` implementation. The mode translates the key into prompt
operations by calling back into `handleKeyDirect()` (the mode-bypassing
entry point — using `handleKey()` would recurse), then re-attaches itself so
its own state survives the prompt's immutable cloning.

- **`EmacsMode`** implements the classic readline chords — Ctrl+A/E (line
  start/end), Ctrl+B/F (char motion), Alt+B/F/D (word motion/delete),
  Ctrl+W, Ctrl+T (transpose), Ctrl+P/N (history), Ctrl+R/Ctrl+S
  (incremental search). Its only internal state is the Escape/Alt prefix
  flag.
- **`ViMode`** is a modal state machine (insert → Escape → normal → `v` →
  visual, plus pending motions like `dd`/`yy`/`cc`). It does not hardcode vi
  bindings: normal/visual keys are mapped through candy-forms'
  `VimKeyHandler`, which returns a `VimAction` enum case that ViMode then
  executes as TextPrompt operations. New vi bindings therefore belong in
  candy-forms (`VimAction` + `VimKeyHandler`), not in ViMode's branching.
  In normal mode the cursor rests ON the last character (vi semantics), so
  `$` and Escape-at-end land on — not after — the final char.

  Normal mode supports **vi text objects**: an operator (`c` change, `d`
  delete, `y` yank) followed by a scope (`i` inner / `a` around) and a
  target — quotes (`"` `'` `` ` ``), brackets (`(` `)` `b`, `[` `]`,
  `{` `}` `B`, `<` `>`), or `w` for the word under the cursor. So `ci"`
  retypes a quoted string, `da{` deletes braces and contents, `diw`/`daw`
  delete a word (aw takes the trailing space), `yi(` moves the cursor to
  the start of the parenthesized text. Resolution lives in candy-forms
  (`TextObject::resolve()` via `VimKeyHandler::handleTextObject()`):
  innermost bracket pair wins, cursor-on-delimiter counts as inside,
  quotes pair up left-to-right (a cursor before a quoted region jumps
  forward to it), and unmatched/absent delimiters no-op like vim's beep.
  Escape cancels a half-typed sequence. Simplifications vs vim: `a"` does
  not absorb trailing whitespace, escaped quotes are not special, and no
  yank register is populated yet (matching `yy`).

With no mode attached, `TextPrompt` falls back to its built-in default
bindings (arrows, Home/End, Ctrl+U/K/W, Ctrl+R/S, Tab completion).

### Incremental history search

With a history attached (`withHistory()`), Ctrl+R enters reverse
incremental search and Ctrl+S forward search, in any mode. Typed characters
refine the query, repeated Ctrl+R/Ctrl+S steps through older/newer matches,
Enter accepts the match into the buffer (without submitting), and
Escape/Ctrl+G cancels back to the original line. The prompt renders a
``(reverse-i-search)`query': match`` indicator, switching to ``(failed
reverse-i-search)`` when nothing matches (including empty history).

## Submit / Abort Semantics

Each prompt is a state machine with three states: pending, submitted, aborted.

- `submit()` finalises the prompt; for `MultiSelectPrompt` it only succeeds when `canSubmit()` is true.
- `abort()` (or feeding `Key::Escape` / `Key::CtrlC`) discards the prompt; `value()` / `selectedValues()` then return empty.
- `isSubmitted()` / `isAborted()` report status; `currentValue()` (Confirmation) and `selectedValue()` (Selection) reflect the current cursor regardless of submission state.

## License

[MIT](LICENSE)
