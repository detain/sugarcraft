<img src=".assets/icon.png" alt="sugar-core" width="160" align="right">

# SugarCraft

> Formerly published as `sugarcraft/candy-core` (renamed 2026-10). <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-core)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-core)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-core?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-core)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/counter.gif)
```sh
composer require sugarcraft/sugar-core
```

sugar-core — the Model–Update–View TUI runtime for PHP 8.3+: every app is a
`Model`, an `update()` that folds messages into new state, and a `view()`
that renders it — driven by an event loop with subscriptions, commands and
a full-screen diffing renderer.

```php
use SugarCraft\Core\{Cmd, KeyType, Model, Msg, Program, Subscriptions};
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

    // Model::subscriptions() is part of the contract — return null when
    // the model needs none (or `use SubscriptionCapable;` to get that
    // default for free).
    public function subscriptions(): ?Subscriptions { return null; }
}

(new Program(new Counter()))->run();
```

## Requirements

- PHP 8.3+ (PHP 8.4+ recommended on Windows for FFI `SetConsoleCtrlHandler` support)
- `mbstring` — **required, and declared as `ext-mbstring` in `composer.json`.**
  Escape decoding and clipboard paths call `mb_*` functions on hot paths.
- `intl` — **required, and declared as `ext-intl` in `composer.json`.** `Width`'s grapheme
  segmentation is built on ICU via `grapheme_extract()`. Without it every width measure falls
  back to per-codepoint counting, which is self-consistent but wrong for any cluster — a
  skin-toned emoji measures 4 cells instead of 2 — and the cell-budget invariants the TUI
  renderers rely on stop holding. It was a silent requirement before the 2026-08 hardening
  pass: the manifest omitted it while this list already named it.
- `pcntl` (signal handling — POSIX only; not available on Windows)
- `FFI` extension (required on Windows for raw TTY support)
- `react/event-loop` ^1.6 (Composer)
- `sugarcraft/sugar-pty` `@dev` — the runtime's raw-mode/termios backend. sugar-core and
  sugar-pty hard-require each other (sugar-pty consumes core's primitives); CI injects the
  path-repo closure so the cycle resolves inside the monorepo, and standalone installs take
  both from Packagist. `Util\Tty\Termios` therefore appears in a public constructor type
  (`ProgramOptions::$termios`).
- Windows 10 version 1809+ (for `ENABLE_VIRTUAL_TERMINAL_PROCESSING`)

> Install note: sibling `sugarcraft/*` packages track `@dev` and the monorepo sets
> `minimum-stability: dev` with `prefer-stable: true`. A consumer pinning stable releases
> only should mirror that pair in its own root manifest or install fails to resolve.

## Environment variables

Every environment variable sugar-core reads, who reads it, and what
happens when it is unset. Apps may of course read their own; this is the
library's full surface.

| Variable | Read by | When unset |
| --- | --- | --- |
| `TERM` | `Util\ColorProfile::detect()` | falls through to the `Ascii` tier |
| `COLORTERM` | `Util\ColorProfile::detect()` | no early TrueColor bail |
| `TERM_PROGRAM` | `Util\ColorProfile::detect()`, `Util\Tty\EnvDetect` (mintty), `Util\Clipboard` (backend plan) | no known-terminal match |
| `NO_COLOR` | `Util\ColorProfile::detect()` | colour stays enabled |
| `CLICOLOR` | `Util\ColorProfile::detect()` (`CLICOLOR=0` opts out of colour) | no opt-out signal |
| `CLICOLOR_FORCE` | `Util\ColorProfile::detect()` | no force (truthy = non-empty, not `0`, not `false`) |
| `FORCE_COLOR` | `Util\ColorProfile::detect()` | no force |
| `WT_SESSION` | `Util\ColorProfile::detect()` | not Windows Terminal |
| `CI` | `Util\ColorProfile::detect()` | not treated as CI |
| `GITHUB_ACTIONS` | `Util\ColorProfile::detect()` | not treated as CI |
| `GITLAB_CI` | `Util\ColorProfile::detect()` | not treated as CI |
| `BUILDKITE` | `Util\ColorProfile::detect()` | not treated as CI |
| `WSL_DISTRO_NAME` | `Util\Tty\EnvDetect` (WSL id), `Util\Open` (opener choice) | not WSL by this signal |
| `WSL_INTEROP` | `Util\Tty\EnvDetect` (WSL id), `Util\Open` (opener choice) | not WSL by this signal |
| `MSYSTEM` | `Util\Tty\EnvDetect` (MSYS2 id) | not MSYS2 |
| `MINTTY_SHORTCUT` | `Util\Tty\EnvDetect` (mintty id) | not mintty by this signal |
| `OSTYPE` | `Util\Tty\EnvDetect` (`cygwin` id) | not Cygwin |
| `PATH` | `Util\Executable\Locator` (the search path `locate()` walks, after any `$extraPath` argument) | empty — nothing is found unless the caller passed `$extraPath` |
| `PATHEXT` | `Util\Executable\Locator` (Windows extensions; POSIX never reads it) | `DEFAULT_WINDOWS_EXTENSIONS` = `.COM`, `.EXE`, `.BAT`, `.CMD` |
| `COLUMNS` | `Util\Tty\PosixBackend` (size fallback when stdout is not a tty) | ignored (read as 0) — next fallback wins |
| `LINES` | `Util\Tty\PosixBackend` (same shape as `COLUMNS`) | ignored (read as 0) — next fallback wins |
| `HOME` | `WorkerPool` (worker environment seed) | `/tmp` |
| `VISUAL` | `Util\Editor` (discovery: explicit override → `VISUAL` → `EDITOR` → platform default) | falls to `EDITOR` |
| `EDITOR` | `Util\Editor` | falls to the platform default editor |
| `TMPDIR` | PHP's `sys_get_temp_dir()`, consumed by `Util\Editor`'s temp-file scaffold | system temp (`/tmp`) |
| `LC_ALL` | `I18n\T::detect()` (chain `LC_ALL` → `LC_MESSAGES` → `LANG`; `$_SERVER` first, then `getenv()`; `''`, `C` and `POSIX` are treated as unusable and fall through) | falls to `LC_MESSAGES` |
| `LC_MESSAGES` | `I18n\T::detect()` (`''`/`C`/`POSIX` unusable here too) | falls to `LANG` |
| `LANG` | `I18n\T::detect()` (`LANG=C` is common in minimal, screen-reader and CI shells — it is unusable, not "use English") | locale `en` |
| `TMUX` | `Util\Clipboard` (backend plan) | not inside tmux |
| `DISPLAY` | `Util\Clipboard` (X11 backend plan) | no X11 clipboard candidate |
| `WAYLAND_DISPLAY` | `Util\Clipboard` (Wayland backend plan) | no Wayland clipboard candidate |

### Color-profile detection order

`ColorProfile::detect()` decides in exactly this order (the docblock on
the enum carries the same list; both are derived from the code):

1. `CLICOLOR_FORCE` or `FORCE_COLOR` truthy counts as a terminal;
   otherwise, when a stdout resource is passed and it is **not** a tty →
   `NoTty` (so `NO_COLOR` on a pipe yields `NoTty`, never escape bytes).
2. `TERM=dumb` → `NoTty` unless forced.
3. `NO_COLOR` truthy → `Ascii` (decoration kept, per no-color.org) — it
   outranks the force variables because it is consulted first.
4. `CLICOLOR=0` → `Ascii` (the BSD opt-out: colour disabled, decoration
   kept), unless a force variable already won.
5. Forced (`CLICOLOR_FORCE`/`FORCE_COLOR`) → `TrueColor`.
6. `COLORTERM` = `truecolor` or `24bit` → `TrueColor`.
7. Known `TERM_PROGRAM` values → the matched tier (iTerm/WezTerm/VS Code/
   Kitty/alacritty/Ghostty/Hyper → `TrueColor`; Apple_Terminal →
   `Ansi256`).
8. `WT_SESSION` set → `TrueColor`.
9. `TERM` contains `truecolor` or `direct` → `TrueColor`; contains `256`
   → `Ansi256`; contains `color` or equals `xterm`/`screen`/`tmux` →
   `Ansi`.
10. Any CI variable truthy (`CI`, `GITHUB_ACTIONS`, `GITLAB_CI`,
    `BUILDKITE`) → `Ansi`.
11. Fallback → `Ascii`.

Colour note: the runtime's own renderer emits SGR unconditionally —
`ColorProfile` tells *your* code which tier the terminal supports, but it
is not a suppression switch inside the renderer. If your app must honour
`NO_COLOR` end-to-end, consult `ColorProfile::detect()` and emit plain
text yourself; there is no automatic downgrade.

## Architecture

- **`Model`** — your app implements `init()`, `update(Msg)`, `view()`, `subscriptions()`.
- **`Msg`** — marker interface for events. Built-ins: `KeyMsg`, `WindowSizeMsg`, `QuitMsg`
  (the full catalogue is indexed under [Message catalogue](#message-catalogue)).
- **`Cmd`** — `Closure(): ?Msg`. Async work whose result is dispatched as a Msg. Helpers in `Cmd::quit()`, `Cmd::batch()`, `Cmd::send()`.
- **`Program`** — orchestrator. Sets up TTY, runs the ReactPHP event loop, dispatches Msgs, drives renders at the configured framerate.
- **Timer accuracy** — on a libuv-backed loop (`ExtUvLoop`, picked automatically when `ext-uv` is installed) a timer's deadline is computed against a clock refreshed once per loop iteration, so a timer armed after long blocking work in a callback fires early by that much. Keep callbacks short; see the notes on `Program::run()` for the measured numbers and the safe paths.
- **Timers in tests** — a PHPUnit process runs the loop in short bursts with long synchronous gaps, which is the same hazard writ large: a safety timer can fire instantly. Suites that arm one should pin a clock-fresh loop from `tests/bootstrap.php` with sugar-testing's `SugarCraft\Testing\LoopPin::pinStableClock()`.
- **`InputReader`** — stateful byte-stream parser; handles split escape sequences across reads.
- **`Renderer`** — line-diff full-screen renderer by default (repaints only changed rows inside a DEC 2026 synchronized-output update); pass `cellDiffRenderer: true` for the per-cell "cursed" diff. Both are implemented; there is no plain full-repaint mode.
- **`Util/`** — `Ansi`, `Color`, `ColorProfile`, `Width`, `Tty`, `TtyDetect`, `RawMode`, `Open` foundation utilities, shared with SugarStyle. `RawMode::enable($stream)` / `RawMode::disable($stream)` is the portable `stty`-based raw-mode toggle for the controlling terminal — a safe no-op on non-tty streams.
- **`KeyBindings`** (in `Keys/`) — immutable action→tokens key registry (finding m27): named actions each own a lowercase token set, `matches(KeyMsg, id)` answers "does this press actuate that action" (press-only, m11; case-folded, m26; both space wire shapes normalize to `space`), `withOverride(id, ...tokens)` returns a new registry, and `label(id)` renders the derived display column help text is composed from — so a user remap lands in dispatch AND the UI from one map. Modifier chords are a declared non-goal (bare letters match as they always did).
- **`Semaphore`** (in `Util/`) — bounded-concurrency permit pool over ReactPHP promises: `Semaphore::new(6)->run(fn() => fetch($url))` caps in-flight work, parks the rest FIFO, and returns each permit when its promise settles. Share one pool across every component touching the same scarce resource, and `close()` it when the owner is retired so parked waiters fail (with `SemaphoreClosedException`) instead of pending. Distinct from `WorkerPool` (subprocess concurrency) — see its docblock.
- **`LruMap`** (in `Util/`) — fixed-capacity least-recently-used map: `get()` promotes, `peek()` reads without disturbing recency, `put()` evicts the longest-unused entry past the cap, `evictions()` reports how often that happened, and an optional `maxCapacity` growth guard caps `resize()`. Iterates most-recently-used first; `keys()` is the string-safe key view. The size bound that complements a TTL cache; it does not replace one.
- **`Subscription`** — value object: id, Kind, params, produce closure.
- **`Subscriptions`** — immutable collection with `withTick()`, `withKey()`, `withSignal()`, `withCustom()`, `all()`, `has()`.
- **`Kind`** — pure enum: Tick / Key / Signal / Custom.
- **`SubscriptionCapable`** — trait providing the default `subscriptions(): null`.

### Composition primitives

- **`ScreenStack`** — immutable stack with `push()` / `pop()` / `current()` / `breadcrumb()` / `isEmpty()` / `count()`.
- **`Screen`** — value object: `model`, optional `title`, `onEnter` closure, `onExit` closure.
- **`ScreenStackCapable`** — interface: `screens(): ScreenStack`. Implement to activate screen-stack routing.
- **`RootModelWithScreenStack`** — concrete root model owning a `ScreenStack`; routes `ScreenStackPushedMsg` / `ScreenStackPoppedMsg` internally, delegates `view()` to the active screen.
- **`PushScreenCmd`** / **`PopScreenCmd`** (in `SugarCraft\Core\Cmd`) — Cmd factories that emit `ScreenStackPushedMsg` / `ScreenStackPoppedMsg`.
- **`ScreenStackPushedMsg`** / **`ScreenStackPoppedMsg`** — infrastructure messages dispatched through the loop; always handled by the root model, never by the active screen.
- **`Component`** — interface extending `Model`; adds `onMount(): ?Closure` (fired when mounted into a `Composite`) and `onUnmount(): ?Closure` (fired when removed). Return a `Cmd` to run immediately or `null` for no-op.
- **`Composite`** — `Model` that manages a list of child `Component`s. Tracks `pendingCmds` and drains them during reconcile, calling child `onMount()`/`onUnmount()` Cmds synchronously. Children are reconciled by *presence*: a child that stays in the list keeps its state; adding or removing triggers lifecycle events. Reordering alone does not re-run lifecycle.
- **`Panes` / `Pane` / `Rect`** — split-region bookkeeping for apps that manage multiple viewports. Geometry is advisory: the runtime does **not** clip a pane's `view()` output to its `Rect` — models must size their own frames (see [Viewport fit](#viewport-fit)).
- **`WorkerPool`** — bounded-concurrency pool (default 4 workers) for offloading CPU-bound tasks to subprocesses. Tasks are named global functions or serialized `Serializable`/closure-free callables; a `Closure` cannot cross the process boundary, so dispatching one returns an already-rejected promise. Worker children run `php` without your application autoloader: only PHP built-ins and whatever the worker's own script loads are resolvable inside the child.
- **`WorkerCmd`** — `Cmd` factory: `WorkerCmd::run($pool, $callable)` dispatches a task to the pool and yields a `WorkerResultMsg` when complete.
- **`WorkerResultMsg`** — result `Msg` carrying `$result`, `$error`, and `$workerId` from a pool worker.

## Subscriptions

Subscription reconciliation lets a Model declare recurring events
(ticks, key events, signals) without managing timers manually. After each
`update()` cycle the runtime diffs the returned `Subscriptions` set against
the active one — new subscriptions start, dropped ones cancel, stable ones
keep running.

```php
use SugarCraft\Core\{Model, Msg, Program, Subscriptions};

/** App-local message — sugar-core ships no TickMsg; the produce closure
 *  returns whatever Msg your update() understands. */
final class TickMsg implements Msg {}

final class Clock implements Model
{
    public function __construct(public readonly int $ticks = 0) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof TickMsg) {
            return [new self($this->ticks + 1), null];
        }
        return [$this, null];
    }

    public function view(): string { return "ticks: $this->ticks\n"; }

    public function subscriptions(): ?Subscriptions
    {
        return (new Subscriptions())->withTick('clock-tick', 1.0, static fn (): Msg => new TickMsg());
    }
}

(new Program(new Clock()))->run();
```

The `SubscriptionCapable` trait satisfies `Model::subscriptions()` with a null
default — use it in Models that don't need subscriptions:

```php
use SugarCraft\Core\{Model, SubscriptionCapable};

final class StaticModel implements Model
{
    use SubscriptionCapable;
    // ... init()/update()/view() still required; no subscriptions() needed
}
```

Two reconciliation facts are worth knowing (both pinned by tests):

- **Ids are the identity.** A subscription is stable while its `id` is
  present across cycles. Changing the closure or interval *under the same
  id* keeps the originally-started timer — to change cadence or producer,
  use a different id. Duplicate ids in one `Subscriptions` collapse to the
  first entry.
- **`SubscribeCmd` is transient.** Its `SubscriptionsMsg` reconciles the
  given set immediately, but the next `update()` cycle re-diffs against
  `Model::subscriptions()`, which cancels anything the model itself does
  not declare. For durable subscriptions, return them from
  `subscriptions()`; use `SubscribeCmd` only for one-shot or
  model-declared-parity sets.

## Screen / ScreenStack

Modal and sub-screen workflows use an immutable `ScreenStack` owned by a
`ScreenStackCapable` root model. The `Program` routes `init()` / `update()` /
`view()` to the active screen's model, writes the screen's next model back
into the stack, and keeps infrastructure messages
(`ScreenStackPushedMsg` / `ScreenStackPoppedMsg`) on the root model.

```php
use SugarCraft\Core\{Cmd, KeyType, Model, Msg, Program, ScreenStack, ScreenStackCapable, SubscriptionCapable};
use SugarCraft\Core\Cmd\{PushScreenCmd, PopScreenCmd};
use SugarCraft\Core\Msg\{KeyMsg, ScreenStackPushedMsg, ScreenStackPoppedMsg};

final class DetailScreen implements Model
{
    public function __construct(public readonly string $id) {}
    public function init(): ?\Closure { return null; }
    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->rune === 'b') {
            return [$this, new PopScreenCmd()];
        }
        return [$this, null];
    }
    public function view(): string { return "Detail: {$this->id}\n(press b to go back)\n"; }
    public function subscriptions(): ?Subscriptions { return null; }
}

// Minimal root: the base layer is a plain string; the active screen (if
// any) replaces it. Infrastructure push/pop messages rebuild the stack.
final class App implements Model, ScreenStackCapable
{
    use SubscriptionCapable;

    public function __construct(public ScreenStack $screens = new ScreenStack()) {}
    public function screens(): ScreenStack { return $this->screens; }
    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof ScreenStackPushedMsg) {
            return [new self($this->screens->push($msg->screen)), null];
        }
        if ($msg instanceof ScreenStackPoppedMsg) {
            return [new self($this->screens->pop()), null];
        }
        if ($this->screens->isEmpty() && $msg instanceof KeyMsg && $msg->rune === 'n') {
            return [$this, new PushScreenCmd(new Screen(new DetailScreen('item-1'), title: 'Item 1'))];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return $this->screens->isEmpty()
            ? "Push a screen with n\n"
            : (string) $this->screens->current()->model->view();
    }
}

(new Program(new App()))->run();
```

`sugar-core` also ships `RootModelWithScreenStack`, a ready-made root model
with this exact routing pre-implemented — extend-free to use, with
`pushedIds` / `poppedIds` tracking for tests.

`examples/screen-stack.php` is a runnable demo showing 3-deep push / pop.

## Viewport fit

The runtime paints whatever `view()` returns. It does **not** clip frames to
the terminal, and `Pane`/`Rect` carry bounds purely as advisory metadata —
no code path in the renderer reads them. A frame wider than the viewport
soft-wraps and shifts every following row, which is why border-alignment
defects surface so often across TUI apps. Read `WindowSizeMsg` (1-based
`$cols` / `$rows` arrive at startup and on every resize), size your content
to it, and truncate with `Util\Width::truncateAnsi()` so SGR bytes survive
the cut.

## Demos

### Counter Model

![counter](.vhs/counter.gif)

### Timer

![timer](.vhs/timer.gif)


## Status

- **Foundation utilities**: 🟢 complete.
- **Runtime**: 🟢 Program loop, mouse (cell-motion + all-motion + SGR 1006), focus / blur, bracketed paste, full function-key set including F13–F63 and the Kitty PUA range, the cell-diff "cursed" renderer (synchronized output 2026 + unicode mode 2027), inline-mode rendering, declarative `View` struct, plus the Cmd surface (`Suspend` / `Interrupt` / `Exec` / `Sequence` / `Every` / `Printf` / `Raw` and more — a resume is deliberately not a Cmd: SIGCONT dispatches a `ResumeMsg` from the runtime) and the Program control methods (`wait` / `kill` / `releaseTerminal` / `restoreTerminal`).
- **Terminal recovery**: 🟢 `setupTerminal()` arms a one-shot shutdown backstop. `run()`'s `finally` restores the terminal on every path it can see; a `die()`, `exit()` or zend bailout that skips pending `finally` blocks still lands on the registered shutdown function, so the library that entered the alt screen is the library that leaves it and consumers do not need to write their own `bin` guard. The hook holds a `WeakReference` and compares the install pid at fire time — finished Programs are never pinned alive by the shutdown queue, and a forked child exiting never releases the parent's terminal.


## Companion libraries

SugarCraft is the foundation — the rest of the SugarCraft stack
builds on it. From the same monorepo:

- **SugarStyle** — declarative styling + layout.
- **SugarWidgets** — prebuilt terminal components.
- **SugarForms** — multi-page form library.
- **SugarCharts** — sparkline / bar / line / heatmap / OHLC.
- **CandyScriptui** — composer-installable CLI (`gum`-style subcommands).
- **SugarMarkdown** — Markdown → ANSI renderer.
- **SugarMouse** — mouse-zone tracker.
- **SugarSpring** — spring physics + Newtonian projectile sim.
- **SugarCli** — opinionated CLI presentation helpers.
- **CandyCodeshot** — code → SVG screenshot.
- **SugarSshServer** — SSH server middleware framework.
- **CandyAnsiInspect** — ANSI escape-sequence inspector.

See the matchup table in [docs/MATCHUPS.md](../docs/MATCHUPS.md) for status,
package names, and namespace mappings.

## Localization (i18n)

sugar-core ships a tiny zero-dep translation registry that the rest of
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

Scope of the registry: it translates **validation and user-facing refusal
messages** — the strings the library routes through `Lang::t()` (indexed in
`lang/en.php`). Low-level programming errors (`\InvalidArgumentException`
for bad internal arguments, `LogicException` for protocol misuse) stay raw
SPL strings by design: they mark developer bugs, not product copy, and
translating them would promise a coverage the registry does not target.

### Adding a new locale

1.  Copy `sugar-core/lang/en.php` to `sugar-core/lang/<locale>.php`
    (e.g. `fr.php`).
2.  Translate the values, keeping keys and `{placeholders}` intact.
3.  Set the locale at app startup with `T::setLocale('fr')` or
    `T::setLocale(T::detect())`.

Lookup chain: **exact locale → base language → `en` → raw key**. So a
single `fr.php` automatically serves `fr-fr`, `fr-ca`, `fr-be`, etc. —
only add a regional file (e.g. `pt-br.php`) when the wording genuinely
diverges from the base language. A key missing from your locale file falls
back to the English value, so partial locales surface as mixed-language
text, never a fatal error; `tests/LocaleKeyParityTest` fails the build when
a shipped locale drifts from the `en` key set.

See [`LOCALES.md`](https://github.com/detain/sugarcraft/blob/master/LOCALES.md)
in the SugarCraft monorepo for the recommended set of codes plus a list
of every base language a contributor can target.

### Application-level overrides

Apps can ship their own translations of any library's strings without
patching upstream:

```php
T::overrideNamespace('charts', '/etc/myapp/lang/charts');
```

See the [`SugarCraft\Core\I18n\T`](src/I18n/T.php) docblock for the
full API surface (`register`, `translate`, `setLocale`, `locale`,
`detect`, `overrideNamespace`, `charset`, `t`, `reset`).

## Composing Cmds

The runtime ships several Cmd combinators. The cheat-sheet below
maps common orchestration needs to the PHP combinators:

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
| Run an external program (`$EDITOR`) | `Cmd::exec($command, captureOutput: false, onComplete: fn (int $exitCode) => $msg)` |
| Offload to a worker subprocess | `WorkerCmd::run($pool, $callable)` → `WorkerResultMsg` |
| Replace the subscription set for this cycle | `new SubscribeCmd($subscriptions)` |

`init()` returns a Cmd (or null) to fire once at startup — for the active
model only: a screen pushed later is *not* re-inited; initialise it in the
`Screen`'s `onEnter` or in its first `update()` instead. `update()`
returns `[Model, ?Cmd]` — the runtime applies the Cmd, dispatches
its Msg, and feeds the result back into `update()`.

The `examples/` directory has runnable demos for each pattern:
[`counter`](examples/counter.php) (basic), [`simple`](examples/simple.php)
(minimal quit-on-key), [`timer`](examples/timer.php)
(tick scheduling), [`realtime`](examples/realtime.php) (self-rescheduling
tick), [`sequence`](examples/sequence.php) (`Cmd::sequence`),
[`send-msg`](examples/send-msg.php) (custom Msg + `Cmd::tick`),
[`tabs`](examples/tabs.php) (state-driven view selection),
[`views`](examples/views.php) (multi-view switcher),
[`splash`](examples/splash.php) (animated splash → main view),
[`suspend`](examples/suspend.php) (`Cmd::suspend` + `ResumeMsg`),
[`altscreen-toggle`](examples/altscreen-toggle.php),
[`panes`](examples/panes.php),
[`screen-stack`](examples/screen-stack.php),
[`mouse`](examples/mouse.php), [`focus-blur`](examples/focus-blur.php),
[`window-size`](examples/window-size.php), [`print-key`](examples/print-key.php),
[`set-window-title`](examples/set-window-title.php), and
[`prevent-quit`](examples/prevent-quit.php).

## Message catalogue

Built-in `Msg` classes under `SugarCraft\Core\Msg\` (all implement the
`Msg` marker interface; addressed messages implement
`ComponentAddressedMsg` so a `Composite` can route by child address):

| Group | Classes |
|---|---|
| Keyboard | `KeyMsg`, `KeyPressMsg`, `KeyReleaseMsg`, `KeyRepeatMsg` |
| Mouse | `MouseMsg`, `MouseClickMsg`, `MouseReleaseMsg`, `MouseMotionMsg`, `MouseWheelMsg` |
| Paste | `PasteMsg`, `PasteStartMsg`, `PasteEndMsg` |
| Lifecycle | `QuitMsg`, `SuspendMsg`, `ResumeMsg`, `InterruptMsg`, `Empty`-family via `RawMsg` |
| Terminal | `WindowSizeMsg` (`$cols`/`$rows`), `CursorPositionMsg`, `BackgroundColorMsg`, `ForegroundColorMsg`, `CursorColorMsg`, `ColorProfileMsg`, `TerminalVersionMsg`, `ModeReportMsg`, `CapabilityMsg`, `KeyboardEnhancementsMsg`, `ClipboardMsg` |
| Focus | `FocusGainedMsg`, `FocusLostMsg` |
| Screen stack | `ScreenStackPushedMsg`, `ScreenStackPoppedMsg` |
| Composition | `AddComponentMsg`, `RemoveComponentMsg`, `ComponentAddressedMsg` (interface) |
| Async / infra | `ExceptionMsg`, `ExecMsg`, `EnvMsg`, `PrintMsg`, `RawMsg`, `BatchMsg`, `SequenceMsg`, `SubscriptionsMsg`, `SuggestionsReadyMsg`, `TickRequest` (Msg-shaped request), `WorkerResultMsg` |

## Alt-screen vs inline mode

The shipped default is **neither**: `useAltScreen: false` and
`inlineMode: false` paint over the current screen with the diff renderer.

Pass `useAltScreen: true` to `ProgramOptions` and the
runtime takes over the alt-screen — the user's previous content is
preserved underneath, and `Cmd::quit()` restores it. Best for
fullscreen TUIs.

Pass `useAltScreen: false` + `inlineMode: true` for a program that
shares scrollback with the surrounding shell. The runtime saves the
cursor on first frame and restores it after each repaint, so
preceding shell output stays visible. Pair with `Cmd::println()` to
emit lines that scroll above the program region.

A typical CandyScriptui prompt (`gum input`-style) uses inline mode;
a fullscreen filter (`gum filter`-style) uses alt-screen.

## Tutorial — building a shopping list

Every SugarCraft program is three things: a **Model** (the state), an
**update** (state transitions), and a **view** (a string). Here's a
shopping list that walks through all three.

```php
use SugarCraft\Core\{Cmd, KeyType, Model, Msg, Program, Subscriptions};
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

    public function subscriptions(): ?Subscriptions { return null; }
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

An uncaught exception thrown from `update()` or `view()` reaches the
program's exception handler (default: rethrow), which means a raw PHP
stack trace with local paths lands on the recovered screen — informative
for the developer, alarming for the user. Install `withExceptionHandler()`
if you want to log it and resume (or show a friendlier frame) instead.

Other gotchas:

- **Don't return `null` from `Model::view()`.** The runtime expects
  a string. Return `''` for an empty frame.
- **Don't block the main thread** in `update()` or `view()` — the
  runtime won't pump frames while you're sleeping. Long work goes
  in a Cmd that emits a Msg when it finishes.
- **Test the Model in isolation.** Drive `update()` with scripted
  Msgs in PHPUnit; the runtime is irrelevant for state-machine
  testing. (See `sugar-core/tests/ProgramTest.php` for the pattern.)
- **Keep `view()` deterministic.** The line-diff (and especially the
  cell-diff, `cellDiffRenderer: true`) renderer skips unchanged rows
  only when the frame is byte-stable — randomised or time-dependent
  output defeats the diff and repaints everything every frame.

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
  motion event lands in `update()`, so enable it only while a
  hover-sensitive view is up (`View->mouseMode`) instead of
  process-wide.

`MouseMsg` carries a `MouseAction` enum (`Press` / `Release` /
`Motion`) and 1-based `$x` / `$y` coordinates. The wheel is not an
action: wheel events arrive as `Press` with `MouseButton::WheelUp` /
`MouseButton::WheelDown`. The four `MouseClickMsg` / `MouseReleaseMsg` /
`MouseMotionMsg` / `MouseWheelMsg` subclasses let you match by class
when that's more convenient.

`examples/mouse.php` is a runnable demonstrator.

## ProgramOptions

`ProgramOptions` is a 24-parameter value object (`readonly` promoted
properties). Pass it as `Program`'s second argument:

```php
use SugarCraft\Core\{Program, ProgramOptions};

$program = new Program(
    new Counter(),
    new ProgramOptions(framerate: 60.0, useAltScreen: true, mouseMode: MouseMode::CellMotion),
);
$program->run();
```

For code that assembles options incrementally there is
`SugarCraft\Core\ProgramOptions\ProgramOptionsBuilder` — a fluent mirror
of the same 24 options (`withFramerate()`, `withUseAltScreen()`,
`withMouseMode()`, `withInlineMode()`, …); `build()` returns the
`ProgramOptions` and performs no validation beyond the types:

```php
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions\ProgramOptionsBuilder;

$opts = (new ProgramOptionsBuilder())
    ->withUseAltScreen(true)
    ->withFramerate(60.0)
    ->withCellDiffRenderer(true)
    ->build();

(new Program(new Counter(), $opts))->run();
```

## Program helpers

### withLogger

Inject a PSR-3-shaped logger to receive runtime lifecycle events.
The default is the library's own `SugarCraft\Core\Util\NullLogger` (a
silent sink — sugar-core does not depend on `psr/log`); any object
exposing `info()` / `error()` / `warning()` is accepted, and missing
methods are skipped:

```php
use SugarCraft\Core\Program;
use SugarCraft\Core\Util\NullLogger;

$program = (new Program(new Counter()))
    ->withLogger(new NullLogger());   // immutable — returns new instance
```

### withExceptionHandler

Register a callable that fires when an uncaught exception escapes `update()` or `view()`. Default re-throws:

```php
use SugarCraft\Core\Program;

$program = (new Program(new Counter()))
    ->withExceptionHandler(fn (\Throwable $t) => error_log($t->getMessage()));
```

### lastFrameDuration

Seconds elapsed for the most recent render cycle — useful for adaptive framerate:

```php
use SugarCraft\Core\Program;

$program = new Program(new Counter());
$program->run();
echo $program->lastFrameDuration();  // 0.0 before first render, >0 after
```

## ProgressReporter

The `ProgressReporter` interface reports ongoing operation progress. It is
a shared vocabulary shipped by the foundation; as of today no monorepo
package consumes it — apps (or future library releases) adopt it directly:

```php
use SugarCraft\Core\ProgressReporter;
use SugarCraft\Core\Progress\CallbackProgressReporter;

interface ProgressReporter
{
    /** @param int $current 0-based current step @param int $total Total steps @param string|null $label Optional label */
    public function report(int $current, int $total, ?string $label = null): void;
}
```

Default implementations:

```php
use SugarCraft\Core\Progress\CallbackProgressReporter;
use SugarCraft\Core\Progress\SilentProgressReporter;

// Callback — fires a closure with ($current, $total, $label)
$reporter = CallbackProgressReporter::new(
    fn (int $current, int $total, ?string $label) => printf("%d/%d %s\n", $current, $total, $label ?? '')
);
$reporter->report(3, 10, 'downloading');

// Silent — no-op (assertable via spy in tests)
$reporter = new SilentProgressReporter();
```

## UndoActionType

Typed enum for apps that want structured undo routing instead of
string-prefix detection (`str_starts_with($desc, 'delete ')` patterns):

```php
use SugarCraft\Core\Undo\UndoActionType;
use SugarCraft\Core\Undo\UndoAction;

enum UndoActionType: string
{
    case Delete = 'delete';
    case Move   = 'move';
    case Rename = 'rename';
    case Copy   = 'copy';
    case Insert = 'insert';
    case Modify = 'modify';
    case Custom = 'custom';
}

// Readonly value object pairing a type with its payload + display label
$action = UndoAction::new(UndoActionType::Delete, ['path' => '/tmp/foo'], 'delete foo');
echo $action->type->value;   // 'delete'
echo $action->label;          // 'delete foo'
```

## Atomic durable JSON files (`Util\AtomicJsonFile`)

The pattern eight sibling libs consolidate onto: `AtomicJsonFile::path($file)
->write($data)` serialises to a `0600` temp inode, `fsync`s, then
`rename()`s, so a reader never sees a half-written file, and takes a
`Semaphore`-guarded lock so concurrent processes serialise.

Operator notes:

- **Stale `.lock` sidecar.** The lock lives in `<file>.lock`. If a process
  is `SIGKILL`ed while holding it, the sidecar can outlive its flock lease;
  on a multi-uid host the residue may even belong to another user, and
  every later write fails until an operator removes the stale `.lock`
  (the flock itself auto-releases when the owning process is gone — the
  unlink is purely cosmetic hygiene, safe to `rm` when `flock -n` proves
  nobody holds it).
- **Publish permissions.** Resulting file modes derive from the process
  umask (opt-in `withPermissions()` tightens them explicitly); a
  world-readable umask publishes world-readable state files.

## Renamed packages

When a package in this monorepo is renamed, apps should keep working for users who still set old env vars or have data in old directories. Two shims in `SugarCraft\Core\Util` cover that:

- `LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_'])` <!-- @rename-keep --> — copies every env var set under a pre-rename prefix onto the new prefix, never overwriting a var the user already set under the new name, and returns the imported old names (sorted) so the app can print a deprecation notice. Call it at the very top of `bin/<app>`, right after the autoloader.
- `LegacyPaths::migrate($oldDir, $newDir)` — one-time atomic move of a pre-rename data/config directory (parents created 0700); returns a human-readable notice, and never merges when the new path already exists.

Every line that spells an old name must carry the `@rename-keep` marker, or `tools/check-rename-residue.php` will flag it.

## Test

```sh
cd sugar-core && composer install && vendor/bin/phpunit
```

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
