<img src=".assets/icon.png" alt="sugar-readline" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-readline)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-readline)
[![Packagist Channel](https://img.shields.io/badge/packagist-dev--master-blue)](https://packagist.org/packages/sugarcraft/sugar-readline)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarReadline

sugar-readline — an interactive line-editing prompt library for terminal UIs on PHP 8.3+.

## Features

- **TextPrompt** — single-line input with validation, autocomplete, hidden/password mode, char limit, default value, history navigation, and an optional display-width viewport
- **SelectionPrompt** — filtered list with type-to-filter, cursor navigation and pagination
- **MultiSelectPrompt** — filtered multi-choice with min/max enforcement and FIFO rollover at the cap
- **ConfirmationPrompt** — Yes/No with customisable labels: choose with arrows/Tab/Space or pick directly with `y`/`n` (one keystroke to set), commit with Enter
- **TextareaPrompt** — multi-line text input with line/column cursor and optional max-line cap; Enter submits, Shift+Enter and Ctrl+J break lines — the pump pushes the kitty keyboard protocol so capable terminals report those chords; on wires that never report modified keys both send a bare Enter byte and submit
- **Immutable state** — every input-handling method returns a new instance; `view()` returns the ANSI string while each prompt exposes its own data accessor (`value()` on TextPrompt/TextareaPrompt, `selectedValue()` on SelectionPrompt, `selectedValues()` on MultiSelectPrompt, `result()` on ConfirmationPrompt), and the `Readline` pump owns all terminal writes
- **Vim keybindings** — vi-mode (Insert/Normal, plus a motion-only Visual scaffold); `ViMode` delegates its key-to-operation mapping to `VimKeyHandler` in `sugar-forms` — the same handler that the `sugar-forms` widgets drive and whose widgets `sugar-widgets` re-exports through its `class_alias` facade; new bindings in the `VimAction` enum land in `sugar-forms` and benefit all three libs. Includes text objects: `ci"`, `di(`, `da{`, `yiw` and friends, with a working unnamed register (`x`/`dd`/`yy`/text-object yanks feed `p`)
- **NO_COLOR respected** — when the `NO_COLOR` environment variable is set to a non-empty value, every decoration (colour and style alike) falls away and prompts print as plain text
- **Terminal requirements** — the `Readline` pump repaints every frame with cursor escapes it emits unconditionally: carriage-return `\r`, cursor-up `CSI nA`, and clear-below `CSI 0J` (Ctrl+L adds `CSI 2J CSI H`). A real terminal consumes them silently; under `TERM=dumb` and inside some assistive or logging wrappers they pile up as visible noise — on those surfaces drive the prompts as pure state machines instead (see the Quick Start's terminal-free shape)

## Install

```bash
composer require sugarcraft/sugar-readline:dev-master
```

No tagged release exists yet, so `dev-master` is the only resolvable
constraint — the versionless form fails Packagist's default stability
filter. Working from a checkout of this library? Run `composer install`
inside `sugar-readline/` before executing the `examples/` scripts; they
boot from `vendor/autoload.php`.

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

That direct shape is also the screen-reader- and CI-friendly path: prompts
never emit cursor escapes themselves — only `Readline`'s repaint loop does —
so a host that feeds keys and renders `view()` through its own output channel
gets identical state without the cursor traffic.

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

The remaining prompt surface (all fluent, all returning new instances):
`withValidator(callable)` gates `submit()` on a rule — the gate is
falsy-only: `withValidator(fn (string $v): bool => $v !== '')` rejects the
empty buffer, but any truthy return accepts, even an error string, and a
throwing validator is never caught, so its exception escapes `submit()`,
`handleKey()` and `run()` — signal rejection by returning `false`, `''` or
`null`, never by throwing — `withCharLimit(int)`
caps the buffer, `withHidden()` masks input (secrets) and disables history
pushes and completion echoes — the masked frame renders one glyph per typed
character (`*` by default; the setter's `$mask` argument changes the glyph),
so assistive output perceives the length of what is typed but never its
content; when even a growing mask run is unwanted, drive the prompt through
the pure state-machine API and render whatever subset you choose,
`withAutoSuggest(bool)` proposes the most
recent matching history entry as a dim row under the prompt (on by default
whenever history is attached — the setter's practical use is turning it off,
and the proposal scans the whole history on each repaint), and
`withUndoManager(manager)`
attaches an `UndoManager` — an immutable bounded (512-state) snapshot stack
fed automatically as you type and edit, driven by Ctrl+Z/Ctrl+Y (or vi `u`
in normal mode). `ConfirmationPrompt::withHint(string)` paints a hint row
(e.g. "Press Enter to confirm") under the Yes/No choice.

### Selection Prompt

Type to filter the list as you go; Backspace edits the filter, Ctrl+U clears
it, and Space acts on the row under the cursor — a second Enter in
SelectionPrompt (it submits the match), a mark toggle in MultiSelectPrompt.
Space never joins the filter — it is a bound key, so a filter containing a
space is unreachable and typing one acts on the row under the cursor
instead. While the filter matches nothing, the list renders `(no matches)`
and Enter/Space are refused until Backspace restores a match:

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
    TextareaPrompt::new('Describe:')->withMaxLines(5),
    STDOUT
);
print_r($result->lines());  // edited multi-line text
```

`TextareaPrompt::new()` takes only the label — a textarea has no choices.
Keyboard contract: **Enter submits**, **Shift+Enter** and **Ctrl+J** insert a
new line, **Ctrl+U** kills the text before the cursor on the current line
only, Up/Down move between lines, Escape/Ctrl+C abort. The pump negotiates
modified-key reporting for the life of the prompt — the kitty keyboard
protocol's disambiguate flag, pushed on start and popped on teardown, TTY
output only — so terminals that honor it deliver the break chords as distinct
frames. Where the terminal ignores the push, or the output is not a TTY,
Shift+Enter and Ctrl+J degrade to bare 0x0A/0x0D — indistinguishable from
Enter — and submit. When a `maxLines`
cap is reached, further line breaks are silently refused wherever the cursor
sits and text accumulates on the line under the cursor.

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

`onMouse()`, `onFocus()` and `onPaste()` register the same way for mouse
reporting, focus in/out, and bracketed paste. The bracketed-paste enable is
gated on the **output** stream being a tty, so a program with piped stdin
behind a tty stdout may still receive pastes as raw bytes. On the genuine
bracketed path — a `PasteEvent` framed by `ESC[200~` / `ESC[201~` — the
content is inserted as **literal text**: `TextPrompt` and `TextareaPrompt`
expose `insertPastedText(string)`, which strips every control byte except
TAB before inserting — the textarea additionally keeps LF and honours
embedded newlines as line breaks — so a bracketed paste payload can never
forge a submit or replay editor commands. A raw-fallback delivery has no
such framing: its bytes take the ordinary key path as they arrive, so a
pasted CR or LF submits exactly as if it had been typed. TAB survives on
**both** surfaces, so a host relying on the strip to vet a secret or URL
field must still reject tabs itself.

### Untrusted content

Prompt material routinely derives from data the program did not author —
completion candidates from the filesystem, choices fetched over the
network, git branch names, history left by an earlier session. Every
emission choke runs that content through `Ansi::sanitize()`: C0 control
bytes and DEL are stripped (CR included; TAB and LF survive because they
have legitimate display roles), so hostile text cannot inject escape
sequences into the frame a prompt paints.

The guarantee is **display-only**. Values returned by `value()`,
`selectedValue()` / `selectedValues()` and entries pushed into history
keep their raw bytes — the library sanitises what *it* renders, not what
it hands back. Printing a prompt's result somewhere else is the host's
responsibility: sanitise or re-encode it before you emit it.

### Long lines and repaint

`view()` returns one logical row; painting that row wider than the terminal
makes it wrap and the cursor-up repaint maths desyncs (the classic
staircase). `TextPrompt::withDisplayWidth($cells)` bounds the editable
region to a window around the cursor (clipped ends marked `…`, cell-width
aware; `0` renders the row unbounded). The state `run()` keys on is *unset*:
only a prompt still at `null` (the default, restored by
`withDisplayWidth(null)`) auto-adopts the `COLUMNS` environment value — and
only that: `bash` does not export `COLUMNS` by default (zsh does), so under
bash nothing is adopted unless the host exports it or probes the size itself
(e.g. `stty size`) and passes the result to `withDisplayWidth()`. An
explicit width always survives: a non-zero bound is kept as given, and an
explicit `0` is honoured as a deliberate unbounded choice, never
overwritten by adoption.
Multi-line textarea output and wrapped labels can still exceed the window —
long **prompts** (labels) are the host's layout responsibility either way.

## Terminal mode (raw vs cooked)

`run()` never touches termios — the library deliberately leaves terminal
settings to the host program. On a **cooked** TTY the line discipline
consumes Enter, Backspace, and Ctrl+ combinations, so arrow keys and
multi-key chords cannot reach the prompt. For the full editing experience put
the terminal in non-canonical mode around the loop and restore it afterwards:

```php
$isTty = stream_isatty(STDIN);
$saved = $isTty ? trim((string) shell_exec('stty -g')) : '';
if ($isTty) {
    shell_exec('stty -icanon -echo -isig -ixon min 1 time 0');
}
try {
    $prompt = Readline::fromStdin()->run(TextPrompt::new('> '), STDOUT);
} finally {
    if ($isTty && $saved !== '') {
        shell_exec('stty ' . escapeshellarg($saved));
    }
}
```

The `stream_isatty(STDIN)` guard is load-bearing, not ceremony: run the
unguarded recipe with piped stdin and `stty` errors "Inappropriate ioctl for
device" on every call — the captured state is empty and the restore degrades
to a usage dump (this is the shape `examples/interactive.php` carries). `stty`
is also POSIX-only — there is no such command on Windows.

`-isig` matters: with signals left enabled, Ctrl+C raises SIGINT, which
kills the process **before** the `finally` restore runs — the shell is then
left with echo and canonical mode off. With `-isig`, Ctrl+C arrives as an
ordinary byte the prompt aborts on, and the restore always executes.

`-ixon` matters for the same reason: with output flow control left enabled,
the discipline swallows Ctrl+S (0x13, XOFF) as a stop-output request before
the prompt ever sees it, so forward history search is unreachable and the
terminal simply freezes until Ctrl+Q (0x11, XON) resumes it. With `-ixon`,
Ctrl+S arrives as an ordinary byte and the prompt opens forward search.

The captured `-g` string is implementation-specific — GNU `stty` prints it as
dense single tokens (`-icanon`) while BSD/macOS `stty` uses space-separated
words — so capture and restore on the same machine in the same run; never
store the string to replay it on another platform.

`sugar-pty`'s FFI `Termios::makeRaw()` (`cfmakeraw`) clears signal generation
and delivers the same transition. `sugar-core`'s
`SugarCraft\Core\Util\RawMode` delivers the same flow-control half — it
runs `-ixon` — but deliberately leaves signal generation ON: Ctrl+C
raises SIGINT there, and the abort chords documented below remain the
stty `-isig` recipe's contract; only that recipe — carried by
`examples/interactive.php` with a shutdown-safe restore — delivers them.
Over a pipe or socket (no tty at all) no stty is needed — the bytes
arrive as written, which is exactly how the test-suite drives chords.

## Input Driver

`Readline` accepts an optional `SugarCraft\Input\InputDriver` to control where input comes from. `new Readline()` and `Readline::fromStdin()` both open `php://stdin` themselves — with that default pump the loop also detects a true EOF (see below). For testing, inject a driver over a fixture stream:

```php
// Testing: inject a fake stream (socket pair or pipe — the driver polls
// the stream, so non-selectable php://memory is not a good fixture here)
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
fwrite($pair[1], "hello\x0d");   // \x0d = Enter
fclose($pair[1]);
$readline = new Readline(new SugarCraft\Input\Driver\StreamInputDriver($pair[0]));
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
normalised to one canonical language at the choke point. The `Key` class
lists the primary bindings; it is not an exhaustive enumeration of every
sequence a terminal can send.

- `Key::Left` / `Key::Right` — move cursor (text input); also Ctrl+B / Ctrl+F
- `Key::Up` / `Key::Down` — navigate selection list / history / textarea line; Ctrl+P / Ctrl+N alias them on `TextPrompt` only
- `Key::Home` / `Key::End` — jump within the current line / list; Ctrl+A / Ctrl+E alias them on `TextPrompt` only
- `Key::Enter` — submit text or select current choice (in the textarea, Shift+Enter and Ctrl+J break a line instead — reported as chords on terminals that honor the pump's kitty-protocol push, and plain Enter submits where the wire never distinguishes them)
- `Key::Space` — a second Enter in SelectionPrompt (submits the row under the cursor); toggles the mark in MultiSelectPrompt; toggles Yes/No in ConfirmationPrompt; inserts a literal space in text inputs
- `Key::Tab` — autocomplete or toggle confirmation value; pressing Tab again cycles the matches (first Tab extends to their longest common prefix)
- `y` / `n` (any case) — set Yes/No directly in ConfirmationPrompt (Enter still commits); there the Left/Right arrows alias to the same one-keystroke Yes/No picks
- `Key::Backspace` / `Key::Delete` — delete characters; Ctrl+H erases before the cursor (both the BS 0x08 and DEL 0x7F spellings bind to Backspace); Ctrl+D deletes under the cursor and, on an empty buffer, aborts (like GNU `delete-char-or-eof`)
- `Key::CtrlU` / `Key::CtrlK` — delete to start / end of line; Ctrl+W deletes the previous word
- `Key::CtrlR` / `Key::CtrlS` — reverse / forward incremental history search; without the `-ixon` recipe (see *Terminal mode* above) the discipline intercepts Ctrl+S as XOFF and freezes output instead of opening forward search — Ctrl+Q (XON) unfreezes it
- Ctrl+Z / Ctrl+Y — undo / redo the last edit run (a run of consecutive typing collapses into one undo step)
- Ctrl+L — `Readline` clears the screen and repaints the prompt
- `Key::PageUp` / `Key::PageDown` — jump the selection list by one page
- `Key::Escape` / `Key::CtrlC` — abort. On a real tty the Ctrl+C chord requires the `-isig` recipe above — with signal generation still on, 0x03 raises SIGINT before the prompt sees it. Inside an attached `EmacsMode`, Escape is the meta prefix instead (a second Escape cancels it); use Ctrl+C to abort there. While an incremental history search is active, Ctrl+C is reclaimed by the search: it cancels back to the pre-search line rather than aborting.

Not every `Ctrl+X` shown has a matching `Key::` constant — there is no
`Key::CtrlD`, `CtrlL`, `CtrlZ` or `CtrlY`, for example. Those bindings are
implemented under the canonical string spelling instead: `Readline::onKey()`
and every prompt's key path accept all of `'ctrl_a'` through `'ctrl_z'`,
which is what `Key::normalize()` turns a raw control byte into.

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
`Mode\ModeInterface` implementation — with two carve-outs: while an
incremental search is active the search consumes keys before the mode is
consulted, and a plain character the mode does not claim is inserted without
going through its `handleKey()`. Typed characters are routed through the
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
  search), Ctrl+K/U (kill to end/start). Ctrl+L reaches the mode as a
  no-op: screen clearing is the `Readline` pump's job. Escape arms the meta
  prefix; a second Escape cancels it. The mode never aborts the prompt on its
  own — Escape only arms the prefix, while Ctrl+C still falls through to the
  prompt's abort binding; leave it detached if Escape-quit is what you want.
- **`ViMode`** is a modal state machine (starts in insert; Escape → normal;
  `v` → a visual mode that tracks motion only — selection is not yet rendered
  or operated on). Pending motions like `dd`/`yy`/`cc` are supported. In
  normal mode `j`/`k` walk history, `x` cuts the char under the cursor, `p`
  puts the unnamed register, `u` undoes the last edit, `Enter` submits the
  line, space moves right, and Ctrl+R/Ctrl+S start incremental search just
  like in insert mode. It does
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
  delete a word (aw takes the trailing space), `yi(` copies the parenthesised
  text into the unnamed register and moves the cursor to its start.
  Resolution lives in sugar-forms
  (`TextObject::resolve()` via `VimKeyHandler::handleTextObject()`):
  innermost bracket pair wins, cursor-on-delimiter counts as inside,
  quotes pair up left-to-right (a cursor before a quoted region jumps
  forward to it), and unmatched/absent delimiters are a silent no-op
  (where vim would beep).
  Escape cancels a half-typed sequence. Simplifications vs vim: `a"` does
  not absorb trailing whitespace, escaped quotes are not special, and the
  unnamed register is a single slot (no named registers, no
  linewise/charwise distinction on put). `dd`, `cc` and `yy` store the
  affected line in the register before acting; `p` pastes register contents
  as literal text after the cursor — pasted control characters never
  replay as commands.

With no mode attached, `TextPrompt` still carries the default GNU-readline
movement chords (Ctrl+A/E/B/F/P/N) plus arrows, Home/End, space,
Ctrl+U/K/W/D/H/R/S, Ctrl+Z/Ctrl+Y undo/redo, Ctrl+L screen clear (via
`Readline`), Tab completion with match cycling, and Escape/Ctrl+C abort.
Attached modes carry their own state, and `view()` never paints it:
`mode()` returns the `ModeInterface` object (or null), and the vi submode
string sits one hop behind it at `mode()?->viMode()` — an accessor only
`ViMode` defines (`EmacsMode` has no submodes). The prompt renders
identically across vi submodes, so a host that needs a visible
`--NORMAL--` marker must read the submode and paint it itself.

### Incremental history search

With a history attached (`withHistory()`), Ctrl+R enters reverse
incremental search and Ctrl+S forward search, in any mode. Ctrl+S only
arrives at all on a terminal with flow control switched off (the `-ixon`
recipe above): otherwise the discipline takes 0x13 as an XOFF stop-output
request and Ctrl+Q sends XON to resume. Typed characters
refine the query, repeated Ctrl+R/Ctrl+S steps through older/newer matches,
Enter accepts the match into the buffer (without submitting), and
Escape/Ctrl+G cancels back to the original line — and so does Ctrl+C while a
search is active: it cancels the search, not the prompt, and only the next
keypress can abort. The prompt renders a ``(reverse-i-search)`query': match``
indicator, switching to ``(failed reverse-i-search)`query': match`` when
nothing matches (including empty history). On a
hidden prompt the line masks query and match alike, in the same idiom.

## History

Prompts start with **no** history attached: navigation and Ctrl+R do nothing
until you pass a store via `withHistory()`. `InMemoryHistory` keeps entries
for the process lifetime; `FileHistory` persists them across processes —
`new FileHistory($filePath, $maxHistory, $tempDir, $deferWrites)`: the file is
created (or tightened, if it exists) at mode `0600`, loaded on construction,
and flushed at the latest on destruction. With `$deferWrites = true`, pushes
queue in memory until `flush()` batch-writes them — queued entries are lost
if the process dies before any flush runs (e.g. SIGKILL), so call `flush()`
at each submit if the history must survive a hard kill. Pass a `$maxHistory` and
the cap bounds the **file** too: bounded stores rewrite the whole file on
each push, merging anything another process appended in between.

Two write-path prerequisites: every store call stages a temp file beside the
history and renames it over the target, so the history **directory** must be
writable — a read-only mount or a cross-device boundary (when the staging
directory and the target live on different filesystems) throws
`RuntimeException` at the first append, never half-writes. The `$tempDir`
argument overrides where that temp file is staged (default: `dirname($path)`)
— point it inside the history's own filesystem when `/tmp` is elsewhere. And
the sidecar `<path>.lock` persists by design: it is never written to (only
its inode is `flock`ed) and never unlinked, because an unlink/rename rotation
would reintroduce the very race the lock closes.

History is a trust boundary of its own: the file records what was typed, so
it can contain secrets. The constructor therefore insists on `0600` — a
pre-existing file whose mode cannot be tightened (typically foreign-owned) is
refused loudly rather than loaded and appended under someone else's
permissions. Keep the history in a private directory: `0600` protects the
**content**, not the filename listing, so the mere name of the file is
visible to anyone who can read its directory.

Entries are stored one per line with backslash escapes (`\\`, `\n`, `\r`)
applied at the write choke and undone on load — a value that somehow
contains a raw newline survives a save/reload round-trip as **one** entry
instead of splitting into two. A history file written before the escaping
format existed can therefore have a literal `\n` two-character sequence
decoded into a real newline on the first load — a documented consequence of
adopting the escape format. A history path that is a **symlink** is
refused loudly at construction and again under the write lock: without the
check, an attacker-planted link turns every append (and the `0600` chmod)
against, say, `~/.bashrc`.

Dedup is stricter than the base `HistoryInterface` minimum: `FileHistory`
skips a line equal to **any** entry already retained (including ones loaded
from disk) — zsh `HIST_IGNORE_ALL_DUPS` style — while plain
`InMemoryHistory` collapses only **adjacent** duplicates (`HIST_IGNORE_DUPS`).
All writers serialise on a sidecar `<file>.lock` (`flock` exclusive, held
across read-write-rename), so two processes pushing at once never lose an
entry; a write that cannot happen throws rather than dropping the line —
but that guarantee covers `push()` and explicit `flush()` calls only: the
automatic flush in `__destruct` cannot throw usefully, so it degrades to an
`error_log` line and any queued `$deferWrites` entries are lost at exit. The
rename makes torn reads impossible, but there is no `fsync` — a kernel crash
can still lose the most recent writes.

Hidden prompts (`withHidden()`) never push their value: a secret stays out of
both in-memory and on-disk history, and it never enters an attached
`UndoManager` chain either. Incremental history search is the one surface
hidden mode does not suppress — Ctrl+R/Ctrl+S still open a search on a hidden
prompt with a history attached — so the search line masks the typed query
exactly like the buffer, character for character, with the same mask glyph;
what you type as a query never appears in clear text.

## Submit / Abort Semantics

Each prompt is a state machine with three states: pending, submitted, aborted.

- `submit()` finalises the prompt; for `MultiSelectPrompt` it only succeeds when `canSubmit()` is true.
- `abort()` (or feeding `Key::Escape` / `Key::CtrlC` on the default bindings; inside an attached `EmacsMode`, Ctrl+C) discards the prompt; `value()` / `selectedValues()` then return empty. The Ctrl+C chord on a live tty requires the `-isig` raw-mode recipe above — with signals on it is a SIGINT death, not an abort. During an active incremental history search these keys cancel the search back to the original line instead — abort resumes ownership on the next keypress.
- `isSubmitted()` / `isAborted()` report status. `currentValue()` (Confirmation) always reflects the current choice, submitted or not. `selectedValue()` (Selection) tracks the cursor while one exists, but returns `null` after an abort and whenever the filter matches no choice — with no cursor to point at, there is no value to report, submitted state or not.
- True end-of-input: when `Readline` owns the stdin pump and the stream reaches EOF after the decoder drains, `run()` returns the prompt in its current state — neither submitted nor aborted. The pump is per-`Readline`-instance, **not** per-`run()`: calling `run()` again feeds the next prompt from wherever the stream stands, so `printf 'a\nb\n' | php app.php` drives two successive prompts with `a` and `b`; once EOF is seen it stays seen. Injected drivers report EOF and "nothing yet" identically, so they cannot trigger this exit; end those loops by submitting, aborting, or `stop()`.
- `stop()` requests the loop exit; it lands within one idle poll interval (capped at 20 ms) **when the driver's read is non-blocking** (`min 1 time 0`, or an injected driver that returns `null` promptly). A driver blocking in `read()` is not interrupted — the stop takes effect on the first pass after it wakes.

## Accessor deprecation (2026-10-24)

Per the house bare-accessor convention (`AGENTS.md`: *"Bare accessors (no* `get`*)*), the history navigation pair `getPrevious()`/`getNext()` was renamed to its bare twins `previous()`/`next()`, with the whole family moved together with the interface: `HistoryInterface` now declares only the bare pair (PHP forbids interface method bodies, so the legacy spellings could not ride the contract as default forwarders). `InMemoryHistory` — and `FileHistory` by inheritance — keep the old names as byte-identical one-line BC forwarders, each marked `@deprecated` with `REMOVED-IN: 2.0.0` in its docblock — they will be removed in sugar-readline v2.0.0.

| Namespace | Class | Deprecated | Use instead |
|-----------|-------|------------|-------------|
| `History` | `InMemoryHistory` | `getPrevious()` | `previous()` |
| `History` | `InMemoryHistory` | `getNext()` | `next()` |

Navigation is stateful (each call advances the position cursor), so `tests/AccessorDeprecationTest.php` pins the BC legs as full walks through both spellings, plus forwarder shape, an interface-contract leg, a discovery-first `src/` census, and the README table agreement. Internal call sites in `src/` were switched to the bare forms in the same commit.

## License

MIT

## Credits & inspiration

Design antecedent: [erikgeiser/promptkit](https://github.com/erikgeiser/promptkit); SugarCraft is developed as a native PHP project.
