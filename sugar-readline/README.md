<img src=".assets/icon.png" alt="sugar-readline" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-readline)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-readline)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-readline?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-readline)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarReadline

sugar-readline — an interactive line-editing prompt library for terminal UIs, for PHP 8.3+.

## Features

- **TextPrompt** — single-line input with validation, auto-completion, hidden/password mode, char limit, default value
- **SelectionPrompt** — filtered list with type-to-filter, cursor navigation and pagination
- **MultiSelectPrompt** — filtered multi-choice with min/max enforcement and FIFO rollover at the cap
- **ConfirmationPrompt** — yes/no with customizable labels, decoupled select-vs-submit
- **TextareaPrompt** — multi-line text input with line/column cursor and optional max-line cap
- **Pure renderer** — every method returns a new immutable instance; `view()` returns ANSI strings, `value()` returns the data
- **Vim keybindings** — vi-mode (Insert/Normal, plus a motion-only Visual scaffold) handled by the shared `sugar-forms` `VimKeyHandler` — the same handler backing `sugar-forms` and `sugar-widgets`; new bindings in `VimAction` enum benefit all three libs. Includes text objects: `ci"`, `di(`, `da{`, `yiw` and friends

## Install

```bash
composer require sugarcraft/sugar-readline
```

## Quick Start

Each prompt is a pure state machine: feed it key characters and it returns a
new prompt. `Readline` is the terminal loop that does the feeding — it reads
keypresses through `sugar-input`'s `InputDriver`, repaints the prompt's
`view()` after every change, and returns the prompt once it is submitted or
aborted. Pass an output stream to `run()` or nothing is painted.

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$prompt = Readline::fromStdin()->run(TextPrompt::new('Enter your name: '), STDOUT);

if ($prompt->isSubmitted()) {
    echo $prompt->value() . "\n";
}
```

You can also drive the state machine directly without a terminal — the shape
the tests use:

```php
$prompt = TextPrompt::new('> ');
$prompt = $prompt->handleChar('h')->handleChar('i');
$prompt = $prompt->handleKey(\SugarCraft\Readline\Key::Enter);
$prompt->value();  // 'hi'
```

### Text Prompt

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$prompt = Readline::fromStdin()->run(
    TextPrompt::new('Enter your name: ')
        ->withDefault('Anonymous')
        ->withCompletions(['Alice', 'Bob', 'Carol']),
    STDOUT
);
// 'Alice' after typing + Tab + Enter
```

### Selection Prompt

Type to filter the list as you go; Backspace edits the filter, Ctrl+U clears
it, Space selects (SelectionPrompt submits the match directly):

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\SelectionPrompt;

$result = Readline::fromStdin()->run(
    SelectionPrompt::new('Choose a fruit:', ['Apple', 'Banana', 'Cherry', 'Date']),
    STDOUT
);
echo $result->selectedValue();  // 'Banana' after typing 'an' + Enter
```

### Multi-Select Prompt

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\MultiSelectPrompt;

$result = Readline::fromStdin()->run(
    MultiSelectPrompt::new('Pick:', ['A', 'B', 'C'])
        ->withMinSelections(1),
    STDOUT
);
print_r($result->selectedValues());  // ['A', 'B'] after Space + navigation + Enter
```

### Textarea Prompt

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextareaPrompt;

$result = Readline::fromStdin()->run(
    TextareaPrompt::new('Describe:', ['First', 'Second', 'Third'])
        ->withMaxLines(5),
    STDOUT
);
print_r($result->lines());  // edited multi-line text
```

### Confirmation Prompt

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\ConfirmationPrompt;

$result = Readline::fromStdin()->run(
    ConfirmationPrompt::new('Delete file?'),
    STDOUT
);
echo $result->result() ? 'yes' : 'no';  // 'yes' or 'no'
```

### Custom Key Handlers

`onKey()` registers additional handlers; it **adds** to the keymap rather
than replacing it, and registering the same key twice keeps the latest
handler for that key. `ctrl_c` still aborts the prompt itself.

```php
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$result = Readline::fromStdin()
    ->onKey('ctrl_c', fn($event) => print("aborted\n"))
    ->onKey('ctrl_u', fn($event) => print("cleared\n"))
    ->run(TextPrompt::new('> '), STDOUT);

echo $result->value();
```

## Terminal mode (raw vs cooked)

`run()` never touches termios — the library deliberately leaves terminal
settings to the host program. On a **cooked** TTY the line discipline
consumes Enter, Backspace, and Ctrl+ combinations, so arrow keys and
multi-key chords cannot reach the prompt. For the full editing experience put
the terminal in non-canonical mode around the loop and restore it afterwards:

```php
$saved = trim((string) shell_exec('stty -g'));
shell_exec('stty -icanon -echo min 1 time 0');
try {
    $prompt = Readline::fromStdin()->run(TextPrompt::new('> '), STDOUT);
} finally {
    shell_exec('stty ' . escapeshellarg($saved));
}
```

`sugar-core`'s `SugarCraft\Core\Util\RawMode` and the `sugar-pty` library
provide the same transition as turnkey helpers if you already depend on them.
`examples/interactive.php` carries the full recipe with shutdown-safe
restore. Over a pipe or socket (no tty at all) no stty is needed — the bytes
arrive as written, which is exactly how the test-suite drives chords.

## Input Driver

`Readline` accepts an optional `SugarCraft\Input\InputDriver` to control where input comes from. `new Readline()` and `Readline::fromStdin()` both open `php://stdin` themselves — with that default pump the loop also detects a true EOF (see below). For testing, inject a driver over a fixture stream:

```php
// Testing: inject a fake stream (socket pair or pipe — the driver polls
// the stream, so non-selectable php://memory is not a good fixture here)
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
fwrite($pair[1], "hello\x0d");   // \x0d = Enter
fclose($pair[1]);
$readline = new Readline(new SugarCraft\Input\StreamInputDriver($pair[0]));
$result = $readline->run(TextPrompt::new('> '));
// $result->value() === 'hello'
```

An injected driver is reported "no event yet" and "stream ended" by the same
`read() === null` answer (`InputDriver` cannot distinguish them), so the loop
keeps idling until the prompt ends or `stop()` is called — close or drain the
fixture stream and arm `stop()` from your test when it runs dry.

## Key Bindings

Bindings fire from both spellings: raw control bytes off a live stream and
symbolic key names (`Key::` constants or plain strings like `'ctrl_a'`) are
normalized to one canonical language at the choke point. The `Key` class
lists the primary bindings; it is not an exhaustive enumeration of every
sequence a terminal can send.

- `Key::Left` / `Key::Right` — move cursor (text input); also Ctrl+B / Ctrl+F
- `Key::Up` / `Key::Down` — navigate selection list / history / textarea line; also Ctrl+P / Ctrl+N
- `Key::Home` / `Key::End` — jump within the current line / list; also Ctrl+A / Ctrl+E
- `Key::Enter` — submit text or select current choice
- `Key::Space` — select/submits in SelectionPrompt; toggles mark in multi-select
- `Key::Tab` — auto-complete or toggle confirmation value
- `Key::Backspace` / `Key::Delete` — delete characters; Ctrl+D deletes under the cursor and, on an empty buffer, aborts (like GNU `delete-char-or-eof`)
- `Key::CtrlU` / `Key::CtrlK` — delete to start / end of line; Ctrl+W deletes the previous word
- `Key::CtrlR` / `Key::CtrlS` — reverse / forward incremental history search
- `Key::Escape` / `Key::CtrlC` — abort. Inside an attached `EmacsMode`, Escape is the meta prefix instead (a second Escape cancels it); use Ctrl+C to abort there.

Ctrl+[ is an ESC on every terminal and behaves as `esc`, not as Ctrl+C.

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
`Mode\ModeInterface` implementation. Typed characters are routed through the
mode too while the mode is *claiming* them (`consumesCharacters()` — an armed
Emacs meta prefix, or Vi outside insert mode), so `^W` never lands in the
buffer while the prefix waits. The mode translates the key into prompt
operations by calling back into `handleKeyDirect()` (the mode-bypassing
entry point — using `handleKey()` would recurse), then re-attaches itself so
its own state survives the prompt's immutable cloning.

- **`EmacsMode`** implements the classic readline chords — Ctrl+A/E (line
  start/end), Ctrl+B/F (char motion), Alt+B/F/D (word motion/delete, via the
  Escape prefix or a Kitty-style `alt_b`), Ctrl+W (delete word before),
  Ctrl+T (transpose), Ctrl+P/N (history), Ctrl+R/Ctrl+S (incremental
  search), Ctrl+K/U/L. Escape arms the meta prefix; a second Escape cancels
  it. The mode never aborts the prompt — leave it detached if Escape-quit is
  what you want.
- **`ViMode`** is a modal state machine (starts in insert; Escape → normal;
  `v` → a visual mode that tracks motion only — selection is not yet rendered
  or operated on). Pending motions like `dd`/`yy`/`cc` are supported. It does
  not hardcode vi bindings: normal/visual keys are mapped through sugar-forms'
  `VimKeyHandler`, which returns a `VimAction` enum case that ViMode then
  executes as TextPrompt operations. New vi bindings therefore belong in
  sugar-forms (`VimAction` + `VimKeyHandler`), not in ViMode's branching.
  In normal mode the cursor rests ON the last character (vi semantics), so
  `$` and Escape-at-end land on — not after — the final char.

  Normal mode supports **vi text objects**: an operator (`c` change, `d`
  delete, `y` yank) followed by a scope (`i` inner / `a` around) and a
  target — quotes (`"` `'` `` ` ``), brackets (`(` `)` `b`, `[` `]`,
  `{` `}` `B`, `<` `>`), or `w` for the word under the cursor. So `ci"`
  retypes a quoted string, `da{` deletes braces and contents, `diw`/`daw`
  delete a word (aw takes the trailing space), `yi(` moves the cursor to
  the start of the parenthesized text. Resolution lives in sugar-forms
  (`TextObject::resolve()` via `VimKeyHandler::handleTextObject()`):
  innermost bracket pair wins, cursor-on-delimiter counts as inside,
  quotes pair up left-to-right (a cursor before a quoted region jumps
  forward to it), and unmatched/absent delimiters no-op like vim's beep.
  Escape cancels a half-typed sequence. Simplifications vs vim: `a"` does
  not absorb trailing whitespace, escaped quotes are not special, and no
  yank register is populated yet (matching `yy`).

With no mode attached, `TextPrompt` still carries the default GNU-readline
movement chords (Ctrl+A/E/B/F/P/N) plus arrows, Home/End, Ctrl+U/K/W/D/R/S,
Tab completion, Escape/Ctrl+C abort, and undo/redo.

### Incremental history search

With a history attached (`withHistory()`), Ctrl+R enters reverse
incremental search and Ctrl+S forward search, in any mode. Typed characters
refine the query, repeated Ctrl+R/Ctrl+S steps through older/newer matches,
Enter accepts the match into the buffer (without submitting), and
Escape/Ctrl+G cancels back to the original line. The prompt renders a
`(reverse-i-search)`query': match` indicator, switching to `(failed
reverse-i-search)` when nothing matches (including empty history).

## History

`InMemoryHistory` is the default store. `FileHistory` persists entries across
processes: pass a path, and the file is created (or tightened, if it exists)
at mode `0600`, loaded on construction, and flushed at the latest on
destruction — `withDeferredWrites()` queues appends until `flush()`.

Dedup is stricter than the base `HistoryInterface` minimum: a line equal to
**any** entry already retained (including ones loaded from disk) is skipped,
zsh `HIST_IGNORE_DUPS` style. All writers serialize on a sidecar
`<file>.lock` (`flock` exclusive, held across read-write-rename), so two
processes pushing at once never lose an entry; a write that cannot happen
loudly throws rather than dropping the line.

Hidden prompts (`withHidden()`) never push their value: a secret stays out of
both in-memory and on-disk history.

## Submit / Abort Semantics

Each prompt is a state machine with three states: pending, submitted, aborted.

- `submit()` finalises the prompt; for `MultiSelectPrompt` it only succeeds when `canSubmit()` is true.
- `abort()` (or feeding `Key::Escape` / `Key::CtrlC` on the default bindings; inside an attached `EmacsMode`, Ctrl+C) discards the prompt; `value()` / `selectedValues()` then return empty.
- `isSubmitted()` / `isAborted()` report status; `currentValue()` (Confirmation) and `selectedValue()` (Selection) reflect the current cursor regardless of submission state.
- True end-of-input: when `Readline` owns the stdin pump and the stream reaches EOF after the decoder drains, `run()` returns the prompt in its current state — neither submitted nor aborted. Injected drivers report EOF and "nothing yet" identically, so they cannot trigger this exit; end those loops by submitting, aborting, or `stop()`.

## License

MIT

## Credits & inspiration

Design antecedent: [erikgeiser/promptkit](https://github.com/erikgeiser/promptkit); SugarCraft is developed as a native PHP project.
