<img src=".assets/icon.png" alt="candy-core" width="160" align="right">

# SugarCraft

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-core)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-core)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-core?label=packagist)](https://packagist.org/packages/sugarcraft/candy-core)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/counter.gif)
```sh
composer require sugarcraft/candy-core
```

PHP port of [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea) —
the Elm-architecture TUI runtime at the heart of the Charmbracelet stack.

```php
use SugarCraft\Core\{Cmd, KeyType, Model, Msg, Program};
use SugarCraft\Core\Msg\{KeyMsg, WindowSizeMsg};

final class Counter implements Model
{
    public function __construct(public readonly int $count = 0) {}
    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            return match (true) {
                $msg->type === KeyType::Char && $msg->rune === 'q' => [$this, Cmd::quit()],
                $msg->type === KeyType::Up    => [new self($this->count + 1), null],
                $msg->type === KeyType::Down  => [new self($this->count - 1), null],
                default => [$this, null],
            };
        }
        return [$this, null];
    }

    public function view(): string { return "count: $this->count\n(↑/↓ to change, q to quit)"; }
}

(new Program(new Counter()))->run();
```

## Requirements

- PHP 8.1+
- `mbstring`, `intl` (for grapheme width)
- `pcntl` (signal handling — POSIX only)
- `react/event-loop` ^1.6 (Composer)

## Architecture

- **`Model`** — your app implements `init()`, `update(Msg)`, `view()`.
- **`Msg`** — marker interface for events. Built-ins: `KeyMsg`, `WindowSizeMsg`, `QuitMsg`.
- **`Cmd`** — `Closure(): ?Msg`. Async work whose result is dispatched as a Msg. Helpers in `Cmd::quit()`, `Cmd::batch()`, `Cmd::send()`.
- **`Program`** — orchestrator. Sets up TTY, runs the ReactPHP event loop, dispatches Msgs, drives renders at the configured framerate.
- **`InputReader`** — stateful byte-stream parser; handles split escape sequences across reads.
- **`Renderer`** — minimal cursor-home + erase + write. Diff-based renderer is a follow-up.
- **`Util/`** — `Ansi`, `Color`, `ColorProfile`, `Width`, `Tty` foundation utilities, shared with CandySprinkles.

## Demos

### Counter Model

![counter](.vhs/counter.gif)

### Timer

![timer](.vhs/timer.gif)


## Status

- **Phase 0** (foundation utilities): 🟢 complete.
- **Phase 3** (runtime): 🟢 v1 — Program loop, mouse (cell-motion + all-motion + SGR 1006), focus / blur, bracketed paste, full function-key set including F13–F63 and the Kitty PUA range, the cell-diff "cursed" renderer (synchronized output 2026 + unicode mode 2027), inline-mode rendering, declarative `View` struct, plus the v2 Cmd surface (`Suspend` / `Interrupt` / `Resume` / `Exec` / `Sequence` / `Every` / `Printf` / `Raw` / `wait` / `kill` / `releaseTerminal` / `restoreTerminal`).

See [../CONVERSION.md](../CONVERSION.md) for the full roadmap and the
[v2 parity sweep](../CONVERSION.md#phase-11--v2-parity-sweep-bubble-tea--lipgloss--bubbles)
table tracking each Bubble Tea v2 / Lipgloss v2 / Bubbles v2
feature.

## Companion libraries

SugarCraft is the foundation — the rest of the SugarCraft stack
builds on it. From the same monorepo:

- **CandySprinkles** (← lipgloss) — declarative styling + layout.
- **SugarBits** (← bubbles) — 14 prebuilt components.
- **SugarPrompt** (← huh) — multi-page form library.
- **SugarCharts** (← ntcharts) — sparkline / bar / line / heatmap / OHLC.
- **CandyShell** (← gum) — composer-installable CLI of 13 subcommands.
- **CandyShine** (← glamour) — Markdown → ANSI renderer.
- **CandyZone** (← bubblezone) — mouse-zone tracker.
- **HoneyBounce** (← harmonica) — spring physics + Newtonian projectile sim.
- **CandyKit** (← fang) — opinionated CLI presentation helpers.
- **CandyFreeze** (← freeze) — code → SVG screenshot.
- **CandyWish** (← wish) — SSH server middleware framework.
- **SugarSpark** (← sequin) — ANSI escape-sequence inspector.

See the matchup table in [../MATCHUPS.md](../MATCHUPS.md) for status,
package names, and namespace mappings.

## Localization (i18n)

candy-core ships a tiny zero-dep translation registry that the rest of
the SugarCraft monorepo plugs into. Every library owns a **namespace**
(`core`, `charts`, `prompt`, …) and a `lang/<locale>.php` file per
locale — call sites look strings up by fully-qualified key.

```php
use SugarCraft\Core\I18n\T;

T::setLocale(T::detect());                 // 'en' / 'fr' / 'de' from $LANG
echo T::t('core.color.invalid_hex', ['hex' => '#zz']);
// => "invalid hex color: #zz"
```

Each library exposes a thin `Lang::t($key, $params)` wrapper with its
namespace baked in, so call sites stay short:

```php
use SugarCraft\Core\Lang;

throw new \InvalidArgumentException(
    Lang::t('color.invalid_hex', ['hex' => $hex])
);
```

### Adding a new locale

1.  Copy `candy-core/lang/en.php` to `candy-core/lang/<locale>.php`
    (e.g. `fr.php`).
2.  Translate the values, keeping keys and `{placeholders}` intact.
3.  Set the locale at app startup with `T::setLocale('fr')` or
    `T::setLocale(T::detect())`.

Lookup chain: **exact locale → base language → `en` → raw key**. So a
single `fr.php` automatically serves `fr-fr`, `fr-ca`, `fr-be`, etc. —
only add a regional file (e.g. `pt-br.php`) when the wording genuinely
diverges from the base language. A forgotten string is visible, never
a fatal error.

See [`lang/LOCALES.md`](lang/LOCALES.md) for the recommended set of
codes plus a list of every base language a contributor can target.

### Application-level overrides

Apps can ship their own translations of any library's strings without
patching upstream:

```php
T::overrideNamespace('charts', '/etc/myapp/lang/charts');
```

See the [`SugarCraft\Core\I18n\T`](src/I18n/T.php) docblock for the
full API surface (`register`, `translate`, `setLocale`, `locale`,
`detect`, `overrideNamespace`, `reset`).

## Composing Cmds

The runtime ships several Cmd combinators. The cheat-sheet below
maps Bubble Tea idioms to the PHP equivalents:

| Need | Use |
|---|---|
| Run several Cmds in parallel | `Cmd::batch(...$cmds)` |
| Run several Cmds one-after-the-other | `Cmd::sequence(...$cmds)` |
| Schedule a Msg in N seconds | `Cmd::tick($seconds, fn () => $msg)` |
| Schedule a Msg on every wall-clock multiple of N seconds | `Cmd::every($seconds, fn () => $msg)` |
| Dispatch a Msg right away | `Cmd::send($msg)` |
| Quit the program | `Cmd::quit()` |
| Hard-kill (after `quit` failed) | `$program->kill()` (from outside the loop) |
| Print text above the program region | `Cmd::println($s)` / `Cmd::printf($fmt, ...)` |
| Drop bytes onto the wire | `Cmd::raw($bytes)` |
| Suspend on Ctrl+Z, resume on SIGCONT | `Cmd::suspend()` (returns to a `ResumeMsg`) |
| Run an external program (`$EDITOR`) | `Cmd::exec($cmd, $args, fn ($exit) => $msg)` |

`init()` returns a Cmd (or null) to fire once at startup. `update()`
returns `[Model, ?Cmd]` — the runtime applies the Cmd, dispatches
its Msg, and feeds the result back into `update()`.

The `examples/` directory has runnable demos for each pattern:
[`counter`](examples/counter.php) (basic), [`timer`](examples/timer.php)
(tick scheduling), [`realtime`](examples/realtime.php) (self-rescheduling
tick), [`sequence`](examples/sequence.php) (`Cmd::sequence`),
[`send-msg`](examples/send-msg.php) (custom Msg + `Cmd::tick`),
[`tabs`](examples/tabs.php) (state-driven view selection),
[`views`](examples/views.php) (multi-view switcher),
[`splash`](examples/splash.php) (animated splash → main view),
[`suspend`](examples/suspend.php) (`Cmd::suspend` + `ResumeMsg`),
[`mouse`](examples/mouse.php), [`focus-blur`](examples/focus-blur.php),
[`window-size`](examples/window-size.php), [`print-key`](examples/print-key.php),
[`set-window-title`](examples/set-window-title.php), and
[`prevent-quit`](examples/prevent-quit.php).

## Alt-screen vs inline mode

Pass `useAltScreen: true` (the default) to `ProgramOptions` and the
runtime takes over the alt-screen — the user's previous content is
preserved underneath, and `Cmd::quit()` restores it. Best for
fullscreen TUIs.

Pass `useAltScreen: false` + `inlineMode: true` for a program that
shares scrollback with the surrounding shell. The runtime saves the
cursor on first frame and restores it after each repaint, so
preceding shell output stays visible. Pair with `Cmd::println()` to
emit lines that scroll above the program region.

A typical CandyShell prompt (`gum input`-style) uses inline mode;
a fullscreen filter (`gum filter`-style) uses alt-screen.

## Tutorial — building a shopping list

Every SugarCraft program is three things: a **Model** (the state), an
**update** (state transitions), and a **view** (a string). Here's a
shopping list that walks through all three.

```php
use SugarCraft\Core\{Cmd, KeyType, Model, Msg, Program};
use SugarCraft\Core\Msg\KeyMsg;

final class ShoppingList implements Model
{
    /** @param list<string> $items @param array<int,bool> $bought */
    public function __construct(
        public readonly array $items,
        public readonly array $bought = [],
        public readonly int $cursor = 0,
    ) {}

    // 1. init() runs once at startup. Return a Cmd or null.
    public function init(): ?\Closure { return null; }

    // 2. update() takes a Msg and returns [newModel, ?Cmd].
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        return match (true) {
            $msg->type === KeyType::Char && $msg->rune === 'q'
                => [$this, Cmd::quit()],
            $msg->type === KeyType::Up
                => [new self($this->items, $this->bought, max(0, $this->cursor - 1)), null],
            $msg->type === KeyType::Down
                => [new self($this->items, $this->bought, min(count($this->items) - 1, $this->cursor + 1)), null],
            $msg->type === KeyType::Space => [
                new self(
                    $this->items,
                    [...$this->bought, $this->cursor => !($this->bought[$this->cursor] ?? false)],
                    $this->cursor,
                ),
                null,
            ],
            default => [$this, null],
        };
    }

    // 3. view() renders the current state. Pure function — no side effects.
    public function view(): string
    {
        $lines = ["Shopping list:\n"];
        foreach ($this->items as $i => $name) {
            $cursor = $i === $this->cursor ? '>' : ' ';
            $check  = ($this->bought[$i] ?? false) ? '[x]' : '[ ]';
            $lines[] = "  $cursor $check $name";
        }
        $lines[] = "\n(↑/↓ to move, space to toggle, q to quit)";
        return implode("\n", $lines);
    }
}

(new Program(new ShoppingList(['eggs', 'milk', 'bread', 'candy'])))->run();
```

Three rules carry through to every program:

1. **Model is immutable.** `update()` returns a *new* Model, never
   mutates the receiver. This buys you snapshot debugging, time
   travel, undo — all for free.
2. **Cmds run async.** `update()` decides what should happen; the
   runtime applies the resulting Cmd. A Cmd is a closure returning
   a Msg.
3. **view() is pure.** Same Model in → same string out. Side
   effects (writing to disk, hitting an HTTP endpoint, blinking the
   cursor) all live in Cmds, never in `view()`.

Once you've internalised the loop, every other SugarCraft feature is
just a richer Msg or a more interesting Cmd.

## Debugging tips

The renderer owns stdout — printing to it from `update()` or `view()`
will be overwritten on the next frame. The two ways to surface
debug info from inside a running program:

1. **Log to a file.** Tail the file from another terminal:
   ```php
   error_log('counter is now ' . $count . "\n", 3, '/tmp/candy.log');
   ```
   ```sh
   $ tail -f /tmp/candy.log
   ```
2. **Use `Cmd::println()`.** Lines emitted via `Cmd::println()`
   print *above* the program region (alt-screen and inline mode
   both honour this) — perfect for "I got here" prints during
   development.

Other gotchas:

- **Don't return `null` from `Model::view()`.** The runtime expects
  a string. Return `''` for an empty frame.
- **Don't block the main thread** in `update()` or `view()` — the
  runtime won't pump frames while you're sleeping. Long work goes
  in a Cmd that emits a Msg when it finishes.
- **Test the Model in isolation.** Drive `update()` with scripted
  Msgs in PHPUnit; the runtime is irrelevant for state-machine
  testing. (See `candy-core/tests/Model/` for the pattern.)
- **Profile with `--bail`.** If a render is slow, the cell-diff
  renderer skips unchanged regions — make sure your `view()` is
  deterministic so the diff stays cheap.

## Mouse support — cell-motion vs all-motion

Pass `MouseMode::CellMotion` (only emit motion events while a
button is held) or `MouseMode::AllMotion` (emit every motion event
including bare-cursor moves) to `ProgramOptions`. Pick:

- **`CellMotion`** when the model only cares about clicks + drags
  (most apps). Fewer Msgs flow through `update()`, lighter on the
  parser, plays nicely with terminal copy/paste because the user
  can hold Shift to bypass mouse capture.
- **`AllMotion`** when the model reacts to hover state (tooltips,
  fancy cursor effects, drag-preview overlays). Trade: every
  motion event lands in `update()`, so use a `MouseMode::CellMotion`
  stub for non-hover frames if perf bites.

`MouseMsg` carries a `MouseAction` enum (`Press` / `Release` /
`Motion` / `WheelUp` / `WheelDown`) and 1-based `col` / `row`
coordinates. The four `MouseClickMsg` / `MouseReleaseMsg` /
`MouseMotionMsg` / `MouseWheelMsg` subclasses let you match by class
when that's more convenient.

`examples/mouse.php` is a runnable demonstrator.

## Test

```sh
cd candy-core && composer install && vendor/bin/phpunit
```
