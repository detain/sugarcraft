# Plan: external `$EDITOR` integration (`x/editor`)

## Goal

Spawn the user's `$EDITOR` with a temp file holding seed text, return
the edited text after the editor exits. Mirrors
`charmbracelet/x/editor`. Two consumers:

1. **Standalone helper** — `Util\Editor::edit($seed)` returns string
2. **sugar-bits TextArea** — bind Ctrl+O to "open in external editor",
   replace value on return (matches upstream `bubbles/textarea`
   `editor.OpenEditor` pattern)

## Scope

**In**

- Discover editor via `$VISUAL → $EDITOR → vi → nano → notepad`
- Write seed to a `tempnam()` file with a configurable extension (so
  syntax-aware editors do the right thing)
- Spawn editor with TTY *inherited* (we hand the terminal to the editor
  for its lifetime)
- Suspend candy-core renderer so it doesn't fight the editor for the
  screen
- Read file back, unlink, return

**Out**

- Async editing (we block the runtime until editor exits — same as upstream)
- Custom args to the editor — out of v1
- Inline / split-pane editing — totally different feature

## Where it lives

- **`candy-core/src/Util/Editor.php`** — pure helper, no candy-core dependencies beyond `Util/Tty`
- **`sugar-bits/src/TextArea.php`** — Ctrl+O binding wired through `update()`

## Public API

```php
namespace SugarCraft\Core\Util;

final class Editor
{
    public static function edit(
        string $seed = '',
        string $extension = '.txt',
        ?string $editor = null  # override the discovery chain
    ): string;
}
```

Returns the file contents after the editor exits successfully. Throws
`\RuntimeException` on:

- editor not found
- non-zero exit from editor
- file unreadable after exit (user `:cq` from vim)

## Discovery chain

```php
private static function discover(): string
{
    foreach ([
        getenv('VISUAL') ?: null,
        getenv('EDITOR') ?: null,
        DIRECTORY_SEPARATOR === '\\' ? 'notepad' : 'vi',
        DIRECTORY_SEPARATOR === '\\' ? null      : 'nano',
    ] as $candidate) {
        if ($candidate === null) continue;
        $bin = self::which($candidate);
        if ($bin !== null) return $bin;
    }
    throw new \RuntimeException('No usable editor found ($VISUAL/$EDITOR/vi/nano).');
}
```

`which()` is a PHP-side `command -v` / `where` shim that handles `$EDITOR`
values like `"vim -p"` (split args, take first token).

## TTY-inherit spawn

```php
$descriptors = [
    0 => STDIN,    # editor reads keys
    1 => STDOUT,   # editor draws
    2 => STDERR,   # error visible
];
$proc = proc_open([$bin, $tmpfile], $descriptors, $pipes);
$exit = proc_close($proc);
if ($exit !== 0) throw new \RuntimeException("Editor exited $exit");
```

When this is called from inside a running `Program`, `Program::executeRequest`
already pauses the renderer and restores cooked-mode-ish for the child's
benefit. Pattern matches existing `ExecRequest` code path.

## sugar-bits TextArea integration

Add to `KeyMap`:

```php
public readonly Key $editorOpen = Key::ctrl('o');
```

In `update()`:

```php
if ($msg instanceof KeyMsg && $msg->matches($this->keymap->editorOpen)) {
    $cmd = ExecRequest::run(static fn() => Editor::edit(
        seed: $this->value,
        extension: $this->editorExtension,  # default .txt; configurable via withEditorExtension(string)
    ), onComplete: fn(string $edited) => new TextAreaEditedMsg($edited));
    return [$this, $cmd];
}

if ($msg instanceof TextAreaEditedMsg) {
    return [$this->withValue($msg->value), null];
}
```

`ExecRequest` is the existing candy-core hand-off mechanism for
synchronous-blocking work; it pauses the renderer, runs the closure,
resumes. Editor invocation is its canonical use case in upstream.

## Discovered during execution

- **`ExecRequest::run(closure)` does not exist in candy-core.** The
  upstream Bubble Tea pattern (`tea.ExecProcess(c, callback)`) takes a
  pre-built `*exec.Cmd` plus a Go-closure callback that returns a Msg.
  `SugarCraft\Core\Cmd::exec()` mirrors that — it accepts `string|array
  $command` plus `?\Closure $onComplete`, but **not** a "run this PHP
  closure with the TTY suspended" variant. The plan's
  `ExecRequest::run(static fn() => Editor::edit(...))` snippet was
  drafted against a feature we don't ship yet.
- **PR2 adapts**: TextArea resolves the editor argv via
  `Editor::command()`, creates + seeds the temp file at update time,
  and returns `Cmd::exec([...$argv, $tmp], onComplete: ...)`. The
  `onComplete` callback reads the temp file, unlinks it, and produces
  `TextAreaEditedMsg` on exit 0 / `null` on non-zero (`:cq` discard).
  This matches the upstream Go pattern exactly, just without the
  `Editor::edit()` round-trip helper sitting in the middle.
- **`Util\Editor::edit()` remains useful** for non-Program callers
  (CLI tools, sugar-prompt one-shot flows) — it does its own
  `proc_open` and is fine *outside* a running renderer.
- **No KeyMap struct on TextArea.** The plan referenced
  `$this->keymap->editorOpen = Key::ctrl('o')`, but TextArea today
  uses an inline `match ($msg->rune)` table over Ctrl modifiers. PR2
  added `'o' => openInEditor()` alongside the existing
  `a`/`e`/`u`/`k` cases. Adding a public `KeyMap` for TextArea is a
  separate refactor — out of scope for this slice.

## Implementation slices

### PR1 — `Util\Editor` helper (~3 hours) ✅

Shipped in [#264](https://github.com/detain/sugarcraft/pull/264). 19 new
PHPUnit tests in `candy-core/tests/Util/EditorTest.php` covering the
discovery chain, runner injection, extension handling, temp-file
cleanup, and real-`cat` / real-`sed`-via-shim integration.

- `Util/Editor.php`: discover + spawn + round-trip
- `tests/Util/EditorTest.php`: round-trip with `EDITOR=cat` (cat exits immediately, file is unmodified seed)
- Integration test: `EDITOR='sed -i s/foo/bar/'` (POSIX sed) seed `foo` → result `bar`. Skip on Windows.

### PR2 — sugar-bits TextArea Ctrl+O (~2 hours) ✅

- KeyMap entry `editorOpen = Ctrl+O`
- `update()` Ctrl+O branch + `TextAreaEditedMsg` handling
- New `withEditorExtension(string)` fluent setter (private prop `$editorExtension = '.txt'`)
- Snapshot tests:
  - Ctrl+O dispatched returns `[$model, ExecRequest]`
  - Receiving `TextAreaEditedMsg('new')` updates value
- Update sugar-bits README with Ctrl+O binding documentation

## Caveats

1. **Editor expects no scrollback** — most editors clear+restore on
   exit. candy-core's altscreen is different; we suspend the renderer
   *and* exit altscreen (`Ansi::exitAltScreen()`) before `proc_open`
   and re-enter on return. `ExecRequest` handles this already.
2. **Windows `notepad`** — works but UX is poor (no terminal feel).
   Document `$EDITOR=code -w` (VS Code wait flag) as the recommended
   Windows alternative.
3. **Vim `:cq`** — exits non-zero on purpose to signal "discard". Our
   wrapper throws — caller (TextArea) catches and keeps the original
   value. Update sugar-bits message handling to convert thrown
   `RuntimeException` into a no-op transition.
4. **Race on temp-file unlink** — we unlink in a `finally` block to
   ensure cleanup even on throw. tempnam atomically reserves; no race
   on creation.
5. **Extension matters** — `*.md` triggers vim's markdown ftplugin,
   `*.txt` triggers nothing. Caller chooses.

## Effort

- PR1 helper: ~3 hours
- PR2 TextArea integration: ~2 hours
- **Total: ~half day**

## Dependencies

- candy-core's `ExecRequest` (already exists)
- Optional: x-windows backend not blocking — Editor works on Windows
  via `notepad` whether or not raw-mode upgrade landed

## Tracking

- `MATCHUPS.md` — no new row
- `UPSTREAM_OPPORTUNITIES.md` — flip `x/editor` row to 🟢 after PR2 lands
- `sugar-bits/CALIBER_LEARNINGS.md` — note the `:cq` non-zero-exit pattern
