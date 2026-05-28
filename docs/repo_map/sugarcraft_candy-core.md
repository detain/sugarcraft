# SugarCraft/candy-core — Innovation & Comparison Report

## Overview

**candy-core** is the foundational TUI runtime library of the SugarCraft monorepo — a PHP port of the Go [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea) ecosystem. It implements The Elm Architecture (TEA): **Model** (immutable state), **Update** (pure state transitions via Messages), and **View** (declarative string rendering). The runtime orchestrates input parsing, the ReactPHP event loop, subscription reconciliation, command dispatching, and frame rendering.

**Key statistics:**
- **~110 source files** across `src/` and `tests/`
- **42+ Msg subclasses** covering keyboard, mouse, focus, paste, resize, clipboard, exec, suspend/resume, color-profile detection
- **ReactPHP-based** event loop with `addReadStream`, `addPeriodicTimer`, `futureTick`
- **Cell-diff renderer** (opt-in) with synchronized output (DEC 2026) and grapheme mode (DEC 2027)
- **Status**: Phase 3 (v1 parity with Bubble Tea v2 core runtime)

---

## Current Architecture

### Core Contracts

The `Model` interface (`src/Model.php`) is the keystone:

```php
interface Model {
    public function init(): ?\Closure;           // Startup Cmd
    public function update(Msg $msg): array;      // [Model, ?Cmd]
    public function view(): string|View;         // Render to string or View
    public function subscriptions(): ?Subscriptions;
}
```

PHP lacks native tuples; the `[Model, ?Cmd]` return type is destructure-ready: `[$model, $cmd] = $model->update($msg)`.

### Program Lifecycle

`Program::run()` (`src/Program.php`, 1009 lines) is the orchestrator:

1. **Terminal setup**: alt screen, raw mode, mouse, focus, bracketed paste modes
2. **Initial dispatch**: `WindowSizeMsg`, `EnvMsg`, `ColorProfileMsg`
3. **Model init**: run `init()` and schedule returned `Cmd`
4. **Stream watcher**: `addReadStream(STDIN)` feeds `InputReader::parse()` → Msgs
5. **Render tick**: `addPeriodicTimer(1/framerate)` calls `renderFrame()` when dirty
6. **Teardown**: restore terminal, cancel subscriptions, close recorder

The dispatch path handles special Msgs inline (BatchMsg, SequenceMsg, TickRequest, RawMsg, PrintMsg, ExecRequest, SuspendMsg, InterruptMsg, QuitMsg, AsyncCmd, SubscriptionsMsg) before routing to the model's `update()`.

### Input Handling

`InputReader` (`src/InputReader.php`, 583 lines) is a stateful byte-stream parser:

- **Buffering**: Accumulates bytes across `fread()` boundaries; partial escape sequences are held until completion
- **CSI decoding**: Handles all standard CSI sequences (arrows, F1-F24, home/end, page up/down, delete)
- **Mouse**: SGR encoding (CSI `<` params `M`/`m`) for click, release, wheel, motion
- **Kitty keyboard protocol**: Full per-key event format (CSI `... u`) with modifiers and text
- **OSC handling**: Window title, foreground/background color, clipboard (OSC 10/11/12/52)
- **DCS handling**: XTVERSION replies
- **Paste**: Bracketed paste mode (CSI `200~` start, CSI `201~` end)

### Rendering System

`Renderer` (`src/Renderer.php`, 290 lines) implements two algorithms:

**Line-diff (default):**
```php
// For each changed row: cursorTo(row, 1) + eraseLine + new_line
```

**Cell-diff ("cursed", opt-in):**
- Tokenize previous and current lines via `Parser`
- Compute longest common token prefix
- Emit partial repaint: `cursorTo(col_after_prefix) + eraseToLineEnd + active_SGR + suffix`
- Falls back to full line repaint when partial would be larger

Both wrap frames in `Ansi::syncBegin()` / `Ansi::syncEnd()` (DEC 2026 synchronized output) to prevent partial-frame flashes.

### Command System

`Cmd` (`src/Cmd.php`, 466 lines) provides factory closures (`Closure(): ?Msg`):

| Factory | Purpose |
|---|---|
| `Cmd::quit()` | Graceful quit |
| `Cmd::suspend()` | Ctrl-Z / SIGTSTP + SIGCONT resume |
| `Cmd::interrupt()` | SIGINT semantics (distinguishable from quit) |
| `Cmd::batch(...$cmds)` | Concurrent execution |
| `Cmd::sequence(...$cmds)` | Sequential execution |
| `Cmd::tick($seconds, $produce)` | Independent timer |
| `Cmd::every($seconds, $produce)` | Wall-clock-aligned periodic tick |
| `Cmd::send($msg)` | Immediate Msg dispatch |
| `Cmd::promise($factory)` | Async Promise → Msg |
| `Cmd::exec($cmd, $capture, $onComplete)` | Shell out and resume |
| `Cmd::raw($bytes)` | Direct escape sequence |
| `Cmd::println($text)` | Print above program region |
| `Cmd::setClipboard($text)` | OSC 52 write |
| `Cmd::readClipboard($selection)` | OSC 52 read request |
| `Cmd::requestCursorPosition()` | DSR-CPR query |
| `Cmd::requestForegroundColor()` | OSC 10 query |
| `Cmd::requestBackgroundColor()` | OSC 11 query |
| `Cmd::requestTerminalVersion()` | XTVERSION query |
| `Cmd::requestKittyKeyboard()` | CSI `? u` query |
| `Cmd::pushKittyKeyboard($flags)` | CSI `> ... u` |
| `Cmd::popKittyKeyboard($n)` | CSI `< ... u` |
| `Cmd::setWindowTitle($title)` | OSC 2 |
| `Cmd::setProgressBar($state, $percent)` | OSC 9;4 |
| `Cmd::enterAltScreen()` / `exitAltScreen()` | DEC 1049 |
| `Cmd::enableMouseCellMotion()` / `enableMouseAllMotion()` | DEC 1002/1003 + 1006 |
| `Cmd::enableReportFocus()` / `disableReportFocus()` | DEC 1004 |
| `Cmd::enableBracketedPaste()` / `disableBracketedPaste()` | DEC 2004 |

### Subscription System

`Subscriptions` (`src/Subscriptions.php`) is an immutable collection with fluent builder:

```php
$subs = (new Subscriptions())
    ->withTick('clock', 1.0, fn () => new TickMsg())
    ->withKey('keys', fn () => new SomeKeyMsg())
    ->withSignal('winch', SIGWINCH, fn () => new ResizeMsg())
    ->withCustom('custom-id', ['interval' => 0.5], $produce);
```

Reconciliation after each `update()`: diff old vs new, cancel dropped subscriptions, start new ones, stable ones persist.

### Component Tree

**`Component`** interface extends `Model` with `onMount()` / `onUnmount()` lifecycle hooks.

**`Composite`** manages children by ordinal position, routes Msgs to matching child, reconciles lifecycle on add/remove.

**`ScreenStack`** — immutable stack for modal workflows. `ScreenStackCapable` interface. `RootModelWithScreenStack` concrete implementation routes infrastructure Msgs internally.

### Renderer/View System

**`View`** (`src/View.php`, 64 lines) carries per-frame side effects alongside the body string:
- `$cursor` — shape, blink, position, color
- `$windowTitle` — OSC 2
- `$progressBar` — OSC 9;4
- `$foregroundColor` / `$backgroundColor` — OSC 10/11
- `$mouseMode` — per-frame tracking mode switch
- `$reportFocus` — DEC 1004 toggle
- `$bracketedPaste` — DEC 2004 toggle

Runtime emits only changed side effects (tracked via `last*` fields), avoiding terminal spam.

### Utilities

| Class | Responsibility |
|---|---|
| `Util/Ansi` | 953 lines — all ANSI escape constants and generators (CSI, OSC, DCS, APC, SGR, cursor, mouse, sync, unicode mode) |
| `Util/Parser` | Tokenizing parser for byte streams — CSI, OSC, DCS, APC, SOS, PM grammar |
| `Util/ColorProfile` | Detection via env vars, TERM, COLORTERM, TERM_PROGRAM, WT_SESSION, CI detection |
| `Util/Width` | Unicode width calculation (mbstring-based) |
| `Util/Tty` | Raw mode enable/disable via termios or Windows API |
| `Util/Tty/PosixBackend` | Unix termios implementation |
| `Util/Tty/WindowsBackend` | Windows `SetConsoleMode` / `GetConsoleMode` via FFI |
| `Util/TtyDetect` | TTY detection for non-tty streams |
| `Util/Color` | RGB color value object |
| `Util/Editor` | Open URLs/files in $EDITOR |
| `Util/Open` | Cross-platform open (xdg-open / start) |
| `SgrState` | Tracks SGR state across token prefix for minimal repaint |

---

## Major Systems

### 1. Event Loop (ReactPHP)

```php
$this->loop->addReadStream($stdin, fn($stream) => ...);     // Input
$this->loop->addPeriodicTimer($tickInterval, fn() => ...); // Render tick
$this->loop->futureTick(fn() => ...);                      // Cmd dispatch
$this->loop->addTimer($delay, fn() => ...);                  // TickRequest
$this->loop->cancelTimer($timer);
```

No epoll/kqueue abstraction in PHP (unlike Go's goroutines or Rust's async); everything is callback-based. The loop drives: input parsing, subscription timers, command dispatch, render frames.

### 2. Input Pipeline

```
STDIN bytes
    → InputReader::parse() [stateful byte-stream parser]
        → KeyMsg / KeyPressMsg / KeyReleaseMsg / KeyRepeatMsg
        → MouseClickMsg / MouseReleaseMsg / MouseMotionMsg / MouseWheelMsg
        → PasteStartMsg / PasteEndMsg / PasteMsg
        → FocusGainedMsg / BlurMsg
        → CursorPositionMsg / ForegroundColorMsg / BackgroundColorMsg / CursorColorMsg
        → ClipboardMsg / KeyboardEnhancementsMsg / TerminalVersionMsg / ModeReportMsg
        → WindowSizeMsg
    → Program::dispatch()
        → Model::update()
```

### 3. Output Pipeline

```
Model::view()
    → View | string
        → Renderer::render()
            → Cell-diff or line-diff
            → ANSI sync markers
    → fwrite(STDOUT)
    → Recorder::recordOutput() [if attached]
```

### 4. Subscription Reconciliation

```
Model::update() returns [Model, ?Cmd]
    → scheduleCmd($cmd) → futureTick → $cmd() → $msg → dispatch()
    → subscriptions() reconcile against active set
        → new subscriptions: start timers
        → dropped subscriptions: cancel timers
        → stable: keep running
```

### 5. Worker Pool

`WorkerPool` (`src/WorkerPool.php`, 455 lines) — bounded-concurrency pool for CPU-bound tasks:

- Uses `proc_open()` subprocesses communicating via serialized callables on stdin/stdout
- Default concurrency: 4 workers
- Dispatch returns `PromiseInterface<WorkerResultMsg>`
- Creates temp PHP script per pool instance
- Serializes tasks via `Closure::serialize()` (stateless only) or string eval
- 50ms poll interval for reading worker stdout
- Automatic worker respawn on death

---

## Feature Inventory

### Input Handling
- [x] Full key press/release/repeat with modifier support (Ctrl, Alt, Shift)
- [x] Kitty keyboard protocol (disambiguate, repeat, alternate keys, associated text)
- [x] Arrow keys, function keys F1-F24, home/end/page up/page down/delete
- [x] SS3 sequences for F1-F4 on some terminals
- [x] Mouse: click/release/wheel/motion with SGR encoding (CSI `<` params `M`/`m`)
- [x] Cell-motion vs all-motion mouse mode
- [x] Focus in/out reporting (DEC 1004)
- [x] Bracketed paste mode (DEC 2004)
- [x] Window resize (SIGWINCH + WindowSizeMsg)
- [x] Terminal capability queries (cursor position, colors, mode reports)
- [x] OSC 52 clipboard read/write
- [x] XTVERSION terminal identification

### Rendering
- [x] Line-diff renderer (baseline, always correct)
- [x] Cell-diff renderer (Bubble Tea v2 algorithm, opt-in)
- [x] Synchronized output mode (DEC 2026)
- [x] Grapheme cluster mode (DEC 2027)
- [x] Alt screen vs inline mode
- [x] Cursor hide/show/shape/color
- [x] ANSI SGR styling (bold/italic/underline/blink/reverse/conceal/strike/overline)
- [x] Foreground/background color (16/256/TrueColor)
- [x] Underline color
- [x] Hyperlink support (OSC 8)
- [x] Scroll region control (DECSTBM)
- [x] Progress bar (OSC 9;4)
- [x] Window title (OSC 0/2)
- [x] iTerm2 inline images (OSC 1337)
- [x] Kitty graphics protocol (AGM APC)
- [x] Sixel graphics (DCS-based)

### Terminal Control
- [x] Raw mode (termios on POSIX, SetConsoleMode on Windows via FFI)
- [x] Signal handling (SIGINT/SIGWINCH/SIGTSTP/SIGCONT)
- [x] Suspend/resume cycle
- [x] External command execution with TTY release
- [x] Program options: filter, withoutSignalHandler, withoutRenderer, catchPanics
- [x] /dev/tty reopening for piped stdin

### Program Control
- [x] Batch commands (concurrent)
- [x] Sequence commands (ordered)
- [x] Tick (independent timer)
- [x] Every (wall-clock-aligned periodic)
- [x] Promise-based async
- [x] Program::send() for external Msg injection
- [x] Program::quit() / kill()
- [x] Program::releaseTerminal() / restoreTerminal() for $EDITOR shelling
- [x] Program::println() / printf()

### Composition
- [x] Component interface (onMount/onUnmount lifecycle)
- [x] Composite (child management by id)
- [x] ScreenStack (immutable modal stack)
- [x] RootModelWithScreenStack (concrete implementation)
- [x] SubscriptionCapable trait
- [x] ScreenStackCapable interface

### I18n
- [x] T::t() translation registry with namespace
- [x] Locale detection from $LANG
- [x] Exact locale → base language → en fallback chain
- [x] Application-level namespace override

---

## Strengths

1. **Faithful Elm Architecture port** — The Model/Update/View/Message pattern is well-implemented. Immutable `with*()` builders throughout. `SubscriptionCapable` trait provides null-default for simple models.

2. **Comprehensive input coverage** — Full Kitty keyboard protocol, SGR mouse, focus, bracketed paste. The InputReader is 583 lines of well-structured state-machine parsing.

3. **Cell-diff renderer** — The opt-in cursed renderer produces dramatically smaller SSH payloads. The algorithm (token-aware longest-common-prefix partial repaint) is correctly implemented.

4. **ReactPHP integration** — Clean integration with `addReadStream`, `addPeriodicTimer`, `futureTick`, `addTimer`. Graceful fallback when pcntl isn't available.

5. **View side-effect tracking** — Only emitting changed terminal state (cursor shape, title, mouse mode, colors) prevents terminal spam. The tracking fields (`lastWindowTitle`, `lastCursor`, etc.) are a clean pattern.

6. **WorkerPool** — Subprocess-based CPU offloading with bounded concurrency and promise-based API. The serialization approach (Closure::serialize() or string eval) is pragmatic.

7. **Extensive Msg taxonomy** — 42+ Msg subclasses covering every terminal event. Concrete subclasses for mouse event types (MouseClickMsg, MouseMotionMsg, etc.) allow instanceof pattern matching.

8. **ANSI library completeness** — 953-line Ansi utility covers every escape sequence needed: cursor, SGR, mouse, sync mode, unicode mode, Kitty keyboard, hyperlinks, iTerm2 images, Sixel, Kitty graphics.

9. **Good test coverage** — 40+ test files covering renderer diffing, input parsing, subscriptions, component lifecycle, worker pool, SGR state, color profile detection.

10. **Graceful degradation** — Non-TTY output, missing pcntl, missing FFI all handled gracefully. `NO_COLOR` respected, CI environment detection.

---

## Weaknesses

1. **PHP's lack of concurrency primitives** — No true parallelism (pthreads not used), no async/await native syntax, no goroutine equivalent. Every concurrent operation is callback-based via ReactPHP. Bubble Tea's goroutines (`go func() { ... }()`) don't map naturally to PHP.

2. **Tuple simulation via arrays** — `[$model, $cmd] = $model->update($msg)` works but lacks Go's type-safe tuple returns. No compile-time enforcement.

3. **Closure serialization limitation** — `WorkerPool` can only serialize stateless closures or string-eval code. Bound objects and internal class refs don't survive. This is a fundamental PHP limitation.

4. **Frame diff is string-based, not cell-based** — Ratatui and ultraviolet use a `Buffer`/`Cell` grid model where each cell has style + content. candy-core's diff operates on the rendered string output, not the logical cell state. This means:
   - Cannot render partial updates at the cell level
   - View side-effects (cursor position changes) can't be diffed together with content
   - The "cursed renderer" is token-aware string diffing, not true cell diffing

5. **No layout system** — `Renderer` just paints the string returned by `view()`. Unlike Ratatui's constraint-based `Layout` or bubbleboxer's tree composition, there is no built-in layout algorithm. Consumers must compose strings manually.

6. **No widget/component library in core** — Bubble Tea v2 ships with `bubbles` (spinner, textinput, viewport, etc.). candy-core is runtime-only; all components are separate leaf libs.

7. **No streaming/reactive output** — The renderer redraws the full view on every dirty frame. No incremental rendering for large terminal buffers.

8. **Subscription timers polling model** — Key subscriptions use a 0.1s polling interval (`addPeriodicTimer(0.1, ...)`). This is less efficient than event-driven key detection would be, though it reflects how terminal input works (polling the byte stream).

9. **Windows FFI dependency** — Raw mode on Windows requires FFI (`candy-pty`'s `Kernel32Interface`). FFI can be disabled at compile time, and it has security implications.

10. **No built-in logging/tracing** — Debug tip in README recommends `error_log()` to a file. No structured logging, no debug mode with frame timing information.

11. **Synchronous event loop blocking** — `exec()` uses synchronous `proc_open()` + `proc_close()`. The program is blocked during external command execution, not async.

---

## UX Evaluation

### Developer Experience

**Positives:**
- Clean `Model` contract — `init()`/`update()`/`view()` is simple to understand
- Fluent `Subscriptions` builder is elegant
- `Cmd::batch()` / `Cmd::sequence()` / `Cmd::every()` cover the main async patterns
- `Program::run()` is a single blocking call — easy to understand lifecycle
- Good README with shopping list tutorial

**Pain points:**
- `update()` return type `array{0: Model, 1: ?Closure}` requires destructuring
- No IDE autocomplete for Msg subclasses — `instanceof KeyMsg` is the pattern
- `WorkerPool` requires named functions or string-eval for serialization — closures silently fail
- Debugging requires `error_log()` or `Cmd::println()` since stdout is the renderer's domain
- `Ansi` constants are public but not documented with their escape sequence meanings

### SugarShell Integration

The README notes that `gum input`/`gum confirm`/`gum spin` use inline mode (no alt screen). This is well-supported but requires `useAltScreen: false` + `inlineMode: true`. The interaction between these two options is documented but non-obvious.

---

## Developer Experience Evaluation

**Strong areas:**
- Clean separation of concerns (InputReader parses, Program dispatches, Renderer paints)
- Immutable value objects throughout (Subscription, Subscriptions, Screen, ScreenStack, View, Cursor, Progress, Rect)
- `SubscriptionCapable` trait reduces boilerplate for simple models
- `Model` docblock documents the Elm pattern clearly
- `Component` + `Composite` provides lifecycle-aware composition

**Needs improvement:**
- No `Program::withLogger()` — structured logging missing
- No `Program::withExceptionHandler()` — exception handling customization
- `ProgramOptions` is a large constructor with 16 parameters — consider a builder
- `InputReader::parse()` returns `list<Msg>` but the caller typically only cares about one at a time in simple cases
- `SgrState` is internal but used in `Renderer` — no public documentation of its purpose

---

## Performance Notes

### Rendering

- **Line-diff**: O(n) where n = number of changed rows. Each changed row emits `cursorTo + eraseLine + content`. No per-cell optimization.

- **Cell-diff**: Tokenizes both lines via `Parser`, then walks tokens to find LCP. Token parsing is O(m) where m = line length in bytes. The per-row comparison is bounded by token count, not byte count. In the worst case (complete repaint), same as line-diff.

- **Synchronized output** (DEC 2026): Terminal buffers the entire frame before committing. Eliminates tearing but adds one round-trip latency. Worth it for SSH; negligible locally.

- **Grapheme mode** (DEC 2027): Tells terminal to count cursor advances per grapheme cluster, not codepoint. Fixes emoji width drift but requires terminal support (xterm, iTerm2, WezTerm, Kitty, Ghostty).

### Input

- `InputReader` is stateful but O(1) per input byte — just byte matching and string slicing.
- Key subscriptions poll at 0.1s intervals — not ideal for latency-sensitive key handling but acceptable for most TUIs.
- `addReadStream` on STDIN is event-driven, not polling — input side is efficient.

### Memory

- `Renderer::$lastLines` holds previous frame as `list<string>`. For an 80x24 terminal, that's ~1920 bytes. For a 200x50 terminal, ~10KB. Negligible.
- `WorkerPool` creates temp files in `sys_get_temp_dir()` — should be cleaned up on process exit (they are via `unlink()` in `createWorkerScript()`).
- `Parser::$pending` holds at most one incomplete escape sequence (~10 bytes).

### WorkerPool Throughput

- 4 workers by default (configurable)
- 50ms poll interval — 20 polls/second per worker
- Each worker reads chunks of 8192 bytes from stdout
- Serialization uses `base64(serialize())` — not the most efficient but portable
- String-eval path in worker is `eval('return ' . $code . ';')` — security implication if arbitrary code is passed

---

## Extensibility Analysis

### Plugin Points

1. **ProgramOptions::filter** — Pre-process every Msg before `update()`. Use cases: global hotkeys, Msg transformation, rate limiting.

2. **ProgramOptions::subscriptions** — External subscription provider. Allows computing subscriptions outside the Model.

3. **ProgramOptions::termios** — Injectable termios backend. Use for testing or custom terminal implementations.

4. **Model::subscriptions()** — Per-model subscription declaration.

5. **Component::onMount() / onUnmount()** — Lifecycle hooks for resource acquisition/release.

6. **Composite** pattern — Extend to add new child management strategies (not abstract, but the pattern is there).

7. **Recorder** — Attach to record/replay sessions for testing.

### What's NOT Extensible

- Renderer algorithm is fixed (line-diff or cell-diff chosen at construction)
- InputReader parsing is not extensible (cannot add custom escape sequences without modifying the class)
- No public hooks for mid-frame rendering (cannot inject output between lines)
- No subscription source abstraction — `Subscription` kinds (Tick/Key/Signal/Custom) are fixed

---

## Comparison Against Mapped Repositories

### vs. charmbracelet/bubbletea (Go, 23k stars)

**Direct port** — candy-core maps 1:1 with bubbletea's core runtime. Key differences:

| Aspect | bubbletea | candy-core |
|---|---|---|
| Concurrency | Goroutines (`go func() { ... }()`) | ReactPHP callbacks |
| Msg return from Cmd | Direct `Cmd: func() Msg` | `Closure(): ?Msg` |
| Batch | `tea.Batch` — concurrent goroutines | `Cmd::batch` — multiple `futureTick` |
| Sequence | `tea.Sequence` — sequential goroutines | `Cmd::sequence` — chain of `futureTick` |
| Update return | `(Model, Cmd)` tuple | `array{0: Model, 1: ?Closure}` |
| Subscription | `model.subscriptions()` | `Subscriptions` immutable collection |
| Renderer | ultraviolet cell-based | string-based line/cell diff |
| Panic recovery | Built-in | `catchPanics` option |
| Logging | `tea.LogToFile()` | `error_log()` to file (manual) |

bubbletea's strengths: battle-tested in production at Microsoft/AWS/NVIDIA/CockroachDB, goroutine-based concurrency is far more efficient than PHP callbacks, comprehensive examples (60+).

candy-core advantages: Works in PHP, which is the language of the surrounding codebase.

**Verdict**: candy-core is a faithful port that correctly captures the Elm architecture. The main gap is concurrency (Go's goroutines vs PHP's callback model) and the lack of a cell-based renderer (ultraviolet's Buffer/Cell model vs string diffing).

---

### vs. charmbracelet/ultraviolet (Go, 2.5k stars)

**Ultraviolet is the rendering foundation for Bubble Tea v2:**

| Feature | ultraviolet | candy-core |
|---|---|---|
| Buffer model | `Buffer` / `Cell` grid with style + content | String-based |
| Cell diffing | `RenderBuffer.TouchLine()` tracks dirty cells | Token-aware string LCP |
| Layout | `Layout` with Cassowary constraint solver | None (string composition) |
| Style diff | `Style.Diff()` minimal SGR transition | `SgrState` tracks SGR prefix for repaint |
| Window/view | `Window` with shared-buffer views | None |
| Border primitives | `NormalBorder()`, `RoundedBorder()`, etc. | None |
| Styled string | `StyledString` with word wrapping | None |

ultraviolet's cell-based buffer (each cell has `rune`, `style`, `link`, `width`) allows true per-cell diffing — only changed cells are repainted. candy-core's cell-diff operates on tokenized strings, not logical cells.

**Verdict**: ultraviolet is a more sophisticated renderer. The cell-based model enables finer-grained repaints. candy-core's token-aware string diffing is a reasonable approximation for PHP but not as precise.

---

### vs. ratatui/ratatui (Rust, 19.6k stars)

**Ratatui provides the rendering foundation:**

| Feature | ratatui | candy-core |
|---|---|---|
| Architecture | Immediate-mode with Buffer diffing | Retained-mode string diffing |
| Widget system | `Widget` / `StatefulWidget` traits | None (string view only) |
| Layout | `Layout` with Cassowary constraints | None |
| Rendering | `Terminal::draw()` → `Frame::render_widget()` | `Renderer::render()` |
| Backend | Crossterm (default), Termion, Termwiz | Direct write to output stream |
| State | `StatefulWidget` + `*State` types | Model carries all state |

Ratatui's architecture: every frame the app redraws everything into a `Buffer`, ratatui diffs the buffer against the previous frame, only changed cells are written. This is more efficient than string-based diffing but requires a `Buffer` abstraction.

**Verdict**: ratatui's immediate-mode + buffer-diffing + widget trait pattern is more sophisticated than candy-core's retained string model. However, ratatui delegates event handling to backend libraries (no built-in event handling), while candy-core has the full Elm loop.

---

### vs. textualize/textual (Python, 30k stars)

**Textual is the Python state-of-the-art TUI framework:**

| Feature | textual | candy-core |
|---|---|---|
| Architecture | Elm variant with async message pump | Elm with ReactPHP event loop |
| Reactivity | `Reactive` descriptors, `watch_*()` | Immutable models + `update()` |
| Widget system | 40+ built-in widgets | None in core |
| Styling | TCSS (CSS for terminals) | String-based (no styles in core) |
| Layout | CSS Flexbox/Grid layouts | None |
| Async | Native async/await | ReactPHP promises |
| State | Reactive vars with auto-watchers | Immutable builders |
| Message | Bubbling hierarchy | Flat dispatch |

Textual's message pump has bubbling (messages bubble up through DOM tree) and propagation (handlers can mark messages handled). candy-core's flat dispatch to `Model::update()` is simpler.

**Verdict**: textual's reactive state + CSS styling + widget library is more feature-rich. However, textual is Python-only. The Elm pattern is portable — candy-core's adaptation is sound.

---

### vs. php-tui/php-tui (PHP, Ratatui port)

**Direct PHP TUI competition:**

| Feature | php-tui | candy-core |
|---|---|---|
| Architecture | Ratatui-style Buffer/Widget/Backend | Elm-style Model/Update/View |
| Widgets | 15+ built-in | None in core |
| Layout | Cassowary constraint solver | None |
| Backend | `PhpTermBackend` abstraction | Direct output stream |
| Buffer diffing | `Buffer::diff()` — cell-level | `Renderer::diffLines/Cells` — string-level |
| Async | Not built-in (docs mention ReactPHP/Amph) | ReactPHP-based |
| Extensions | `BdfExtension`, `ImageMagickExtension` | None |

php-tui is a more complete TUI framework (widgets, layout, backend abstraction). candy-core is a leaner Elm runtime without widgets. They serve different use cases: php-tui for rich widget-based apps, candy-core for Elm-architecture apps.

**Verdict**: php-tui is a more complete framework but uses a different programming model (Ratatui's immediate-mode + widget traits). candy-core's Elm model is more familiar to bubbletea developers.

---

### vs. WhispPHP/whisp (PHP, SSH server)

**Whisp is an SSH server, not a TUI framework — but architectural overlap:**

| Feature | whisp | candy-core |
|---|---|---|
| Event loop | `socket_select()` + forking | ReactPHP |
| PTY management | FFI-based PTY | candy-pty (separate) |
| Signal handling | SIGHUP/SIGUSR2/SIGINT/SIGTERM | SIGINT/SIGWINCH/SIGTSTP/SIGCONT |
| Process model | Fork per connection | Single process + ReactPHP |
| Protocol | SSH (with crypto) | Terminal I/O |

Whisp uses a similar event-driven pattern but for network I/O rather than terminal I/O. The PTY management patterns could inform candy-pty integration.

---

### vs. alecrabbit/php-console-spinner (PHP)

**ANSI cursor management patterns:**

| Feature | php-console-spinner | candy-core |
|---|---|---|
| Cursor hide/show | `\e[?25l` / `\e[?25h` | `Ansi::cursorHide()` / `cursorShow()` |
| Frame timing | `hrtime()` for sub-ms precision | `microtime(true)` for tick scheduling |
| Rendering | Two-buffer with SequenceStateWriter | Single output stream + diffing |
| Event loop | ReactPHP or Revolt | ReactPHP |

The cursor management patterns are identical. php-console-spinner's delta-timer using `hrtime(true)` is more precise than `microtime(true)`. The two-buffer erase/overwrite pattern is similar to candy-core's inline rendering.

---

## Cross-Repo Innovation Opportunities

### From ultraviolet

1. **Cell-based buffer model** — Each logical cell holds (rune, style, link, width). The renderer diffs cells, not strings. This would enable true partial repaints and cursor position diffing.

   *Implementation*: Add `Cell` value object, `Buffer` class with `Area` dimensions. Replace `Renderer::render(string)` with `render(Buffer)`. Each `Model::view()` would write into a `Buffer` instead of returning a string.

2. **Style diff algorithm** — `Style.Diff(from, to)` emits minimal SGR sequence to transition between styles, avoiding full `reset + new style` when cheaper. `SgrState` in candy-core is a partial implementation; extend it to handle all attributes (underline color,overline) in correct order.

3. **Window/View hierarchy** — `NewWindow` / `NewView` for child buffers sharing parent's content. Useful for modal dialogs that don't clobber the underlying frame.

4. **Border functions** — `NormalBorder()`, `RoundedBorder()`, `BlockBorder()`, etc. as string constants. Currently no border support in core.

### From ratatui

1. **Widget trait** — `interface Widget { function render(self, area: Rect, buf: mut Buffer); }`. Implementations: `Paragraph`, `Block`, `List`, `Table`, etc. candy-core has no equivalent.

2. **StatefulWidget** — Widget with associated state type (`ListState`, `TableState`). Enables persistent scroll position, selection, etc. within the widget.

3. **Layout with Cassowary** — `Layout::default()` → `direction(Direction)` → `constraints([Length(10), Percent(50), Fill()])` → `split(area)`. Returns `Areas`. Currently no layout in candy-core.

4. **Stylize trait** — Fluent style shorthands: `"hello".red().on_blue().bold()` → `Span`. Useful for composing styled text.

### From textual

1. **Reactive state descriptors** — `var(value)` with auto-watcher injection. Instead of `update()` returning new model every time, reactive properties auto-update the view when changed.

   *PHP implementation*: `readonly` properties with `__set` that triggers re-render. Or a `Reactive` attribute class.

2. **Message bubbling** — Messages bubble up through a DOM-like tree. Handlers can mark messages `handled` to stop propagation.

3. **CSS-based styling** — TCSS for terminal styling with selectors. Major undertaking but would provide web-developer familiarity.

4. **Pilot testing** — `app.run_test()` with `press("q")`, `click("#button")`, `pause(0.5)`, `resize_terminal(80, 24)`. Automated UI testing without screenshots.

### From php-tui

1. **Backend abstraction** — `Backend` interface with `size()`, `draw()`, `cursorPosition()`, `moveCursor()`. Allows swapping terminal backends. Currently candy-core writes directly to `$output` stream.

2. **Widget renderer visitor pattern** — `WidgetRenderer::render(self, Widget, Buffer, Area)`. Allows composing widget renderers.

3. **Extension system** — `DisplayExtension` interface for adding widget renderers and shape painters without modifying core. Very clean plugin pattern.

### From bubbleboxer

1. **Tree-based layout composition** — `Boxer` with `LayoutTree Node` + `ModelMap`. Interior nodes define orientation; leaves reference models by address string.

2. **SizeFunc custom allocation** — `function(node, available) -> perChildSizes`. Enables weighted layouts beyond even-split.

3. **Safe edit pattern** — `EditLeaf(address, editFunc)` that rolls back on error. candy-core's immutable `with*()` builders provide similar safety.

### From whisp

1. **FFI-based PTY management** — Whisp's FFI layer for `ioctl(TIOCSCTTY)`, `TIOCSWINSZ`, `grantpt()` is directly relevant to `candy-pty`. Could inform a tighter integration.

2. **Process per connection model** — For very concurrent use cases (SSH server), fork-based concurrency is appropriate. For TUI, single process + ReactPHP is better.

---

## Missing Features

1. **Cell-based buffer rendering** — The most significant gap vs. ultraviolet/ratatui. Enables per-cell diffing, cursor position diffing, partial line repaints with accurate cell boundaries.

2. **Layout system** — No constraint-based layout, no flex/grid/table layout. `view()` must compose strings manually.

3. **Widget library** — No prebuilt components (text input, spinner, list, table, etc.) in core. sugar-bits provides these but as separate leaf libs.

4. **Streaming/incremental rendering** — Full frame redraw every time. No dirty-region tracking beyond changed-row detection.

5. **Async command cancellation** — Once a `Cmd` is scheduled, it runs to completion. No way to cancel a long-running operation.

6. **Structured logging** — No `Program::withLogger()`. Debug output goes to `error_log()`.

7. **Exception/panic handler customization** — `catchPanics` is boolean; no custom exception handler.

8. **Terminal capability queries** — `Ansi` has all the sequences but no high-level API to query "does the terminal support X?" and gracefully degrade.

9. **Sixel/Kitty graphics rendering** — Sequences are defined in `Ansi` but no actual renderer for these graphics formats.

10. **Built-in animations** — No animation system like textual's `Animator`. `honey-bounce` provides spring physics but no animation driver in core.

---

## Smarter Implementations To Adopt

### From ultraviolet: Cell-Based Render Buffer

Replace string-based `Renderer` with a `Buffer` class:

```php
final readonly class Cell {
    public function __construct(
        public string $char,
        public Style $style,
        public ?string $hyperlink,
    ) {}
}

final class Buffer {
    public function __construct(
        public int $width,
        public int $height,
        /** @var list<list<Cell>> */
        private array $cells,
    ) {}

    public function setCell(int $x, int $y, Cell $cell): void { ... }
    public function getCell(int $x, int $y): Cell { ... }
    public function diff(self $other): list<BufferUpdate> { ... }
}

final class BufferUpdate {
    public function __construct(
        public int $x, public int $y,
        public Cell $cell,
    ) {}
}
```

Then `Model::view(Buffer $buf)` instead of `view(): string`. This enables per-cell diffing.

### From ratatui: Cassowary Layout Solver

Port the Cassowary algorithm (already exists in `honey-bounce`?):

```php
final class Layout {
    public static function vertical(Rect $area, array $constraints): list<Rect> { ... }
    public static function horizontal(Rect $area, array $constraints): list<Rect> { ... }
}

enum Constraint {
    case Length(int);
    case Percentage(int);
    case Ratio(int, int);
    case Fill(int);
    case Min(int);
    case Max(int);
}
```

Models then compute child areas via `Layout::vertical($area, [Length(1), Fill(2)])` rather than manual string composition.

### From textual: Reactive State Descriptors

```php
#[Attribute]
final class Reactive {
    public function __construct(
        public mixed $default,
        public bool $layout = false,
        public bool $repaint = true,
    ) {}
}

final class MyModel {
    #[Reactive(0)]
    public int $count;

    public function watch_count(int $old, int $new): void {
        // Auto-called when $count changes
    }
}
```

### From php-tui: Backend Abstraction

```php
interface Backend {
    public function size(): Area;
    public function draw(list<BufferUpdate> $updates): void;
    public function cursorPosition(): Position;
    public function moveCursor(Position $pos): void;
}

final class Program {
    public function __construct(
        Model $model,
        Backend $backend = new PhpTermBackend(),
    ) {}
}
```

---

## High-Value Refactors

### 1. Extract `Renderer` interface

Currently `Renderer` is concrete. Extract an interface to allow:
- Cell-based renderer (future)
- Test renderer (captures output without terminal I/O)
- Network renderer (sends diffs over wire for remote display)

```php
interface RendererInterface {
    public function render(string $frame): void;
    public function reset(): void;
}
```

### 2. Separate InputReader parsing from dispatch

Extract `InputReader` parsing from `Program::dispatch()`. Currently the input loop is tightly coupled:

```php
$this->loop->addReadStream($this->input, function ($stream): void {
    $bytes = @fread($stream, 4096);
    foreach ($this->reader->parse($bytes) as $msg) {
        $this->dispatch($msg);
    }
});
```

Could be refactored to: `InputSource` interface with `attach(LoopInterface, callable(Msg))`.

### 3. Subscription timer cleanup

`startSubscription()` for `Kind::Key` uses `addPeriodicTimer(0.1, ...)` — polling. Better: integrate with the input stream watcher to receive key events directly without polling. However, this requires terminal support for async key events (not universally available).

### 4. View side-effect emission ordering

`applyViewSideEffects()` in `Program` emits terminal control sequences before or after the frame body. For synchronized output mode to work correctly, all frame content should be wrapped in `syncBegin/syncEnd`. Currently side effects (cursor shape, window title) are emitted outside this boundary. Move side effects inside the sync boundary.

### 5. WorkerPool task serialization

Current approach (Closure::serialize() or string eval) is fragile. Consider:
- Use `Opis\Closure` for better serialization
- Or require tasks to be named functions or static methods
- Document the limitation clearly

---

## Architectural Recommendations

### Short-term (within current architecture)

1. **Builder for ProgramOptions** — With 16 constructor parameters, a fluent builder would improve DX:

```php
$options = ProgramOptions::create()
    ->withAltScreen(true)
    ->withMouseMode(MouseMode::CellMotion)
    ->withCellDiffRenderer(true)
    ->build();
```

2. **Cell-diff as default** — Now that it's been validated, make cell-diff the default. The CPU overhead is negligible at typical terminal sizes.

3. **Document extension points** — Add a section in README about writing custom Renderers, InputSources, and Subscription producers.

4. **Add Program::withExceptionHandler()** — Wrap `catchPanics` in an interface:

```php
$program->withExceptionHandler(fn(\Throwable $e) => ...);
```

### Medium-term (requires breaking changes)

5. **Buffer/Cell model** — The most impactful change. Replace `Renderer::render(string)` with `render(Buffer)`. `Model::view()` receives a `Buffer` to write into.

6. **Layout system** — Add `Layout` with `Constraint` types. This enables `view()` methods to compute child positions without manual string padding.

7. **Widget trait** — `interface Widget { public function render(Buffer $buf, Rect $area): void; }`. Enables reusable drawing primitives.

### Long-term (new capabilities)

8. **Streaming renderer** — For very large terminal buffers (e.g., 200+ rows), chunk rendering across multiple frames.

9. **Async command cancellation** — `CancellationToken` that Cmd factories receive and check periodically.

10. **Network renderer** — For serving TUI over SSH without X11 forwarding. Capture diffs and send over wire.

---

## Quick Wins

1. **Document the `cellDiffRenderer` option in README** — It's buried in ProgramOptions. Many users don't know it exists.

2. **Add `Program::withLogger()`** wrapping `error_log()` — Even a simple PSR-3 interface would help.

3. **Document SGR state tracking in Renderer** — `SgrState` is internal but critical to the cell-diff algorithm. Add inline comments explaining the algorithm.

4. **Expose `Ansi` constants as public API** — The constants are used throughout but not well-documented. A constants file with docblocks would help consumers.

5. **Add `Model::view(Buffer $buf)` overload** — In addition to `view(): string`, allow buffer-based rendering for efficient partial updates.

6. **Add SIGTSTP/SIGCONT signal handlers in README** — The suspend/resume example is in `examples/suspend.php` but the pattern (restore terminal, stop loop, SIGTSTP, restore on SIGCONT) isn't well-documented.

7. **Expose frame timing** — `Program` could expose `$lastFrameDuration` after render. Useful for adaptive framerate.

---

## Long-Term Opportunities

1. **Distributed TUI** — Render on server, display on client via WebSocket. The renderer diffing algorithm is already designed for this use case (bandwidth optimization). Add a `NetworkRenderer` that sends diffs over a socket instead of writing to stdout.

2. **Incremental widget rendering** — Large lists/tables that render visible rows only. Currently requires manual optimization in the Model.

3. **CSS-like stylesheet** — `Stylesheet` parser + `Styles` value object with cascading. Very ambitious but would bring web developer familiarity.

4. **Animation engine** — `Animator` class with easing functions and `Transition` objects. `honey-bounce` provides the physics; candy-core could provide the animation driver that calls `view()` at interpolated states.

5. **Hot reload** — Watch source files and recompile on change. Combined with a `View` that can re-execute without restarting the Program.

---

## Notable Clever Implementations Found Elsewhere

### ultraviolet: Style Diff Algorithm

From `cell.go:L258-L408`, the `Style.Diff()` function computes minimal SGR transition:

```go
// Omit reset (0) if transitioning FROM default style
// Omit attributes that are already active
// Order: bold(1), faint(2), italic(3), underline(4), blink(5), ...
// Foreground 256: emit 38;5;N
// Foreground RGB: emit 38;2;R;G;B
// Background: 48 instead of 38
```

candy-core's `SgrState` (`src/SgrState.php`) implements a subset — tracks the last-emitted SGR parameters and computes what's needed to transition. The full algorithm would handle underline color (58), overline (53), and proper 256-color → truecolor downgrade.

### ratatui: Cassowary Layout Solver

Ported from `kasuari` crate (Rust). The algorithm:

1. Create symbolic variables for each layout variable (e.g., `x1`, `width1`)
2. Add constraints with priorities: `Required > Strong > Medium > Weak`
3. Add stay constraints for fixed-size elements
4. Solve — if conflicts, drop lowest-priority constraints until solved

This enables: "sidebar is 30% width" + "main + sidebar = terminal width" → solve for both. Currently candy-core has no such solver.

### textual: Spatial Map for Hit Testing

`_spatial_map.SpatialMap` in textual uses an R-tree-like index for O(log n) widget lookup by screen position. For candy-core, this would be useful for mouse event routing — determining which "zone" or component a mouse click landed on. Currently mouse coordinates come in as `MouseMsg` with col/row, but routing to the correct component is the Model's responsibility.

### whisp: FFI PTY Management

`Pty::open()` uses `/dev/ptmx` master opening, then `ioctl(TIOCPTSNAME)` to get slave name, `grantpt()` for permission, `unlockpt()`. This is directly relevant to `candy-pty`. The cross-platform FFI approach (different constant sets for Linux vs macOS) is well-engineered.

### bubbleboxer: ModelMap Pattern

Separates layout tree from model instances:

```go
type Boxer struct {
    LayoutTree Node
    ModelMap map[string]tea.Model  // Address → Model
}
```

This allows modifying a model without touching the layout tree. candy-core's `Composite` uses a similar pattern with `children` array keyed by string id.

---

## Suggested Roadmap

### Phase 1: Stability & DX (low-risk)

- [ ] Builder for `ProgramOptions` (16 params is unwieldy)
- [ ] `Program::withLogger()` / `Program::withExceptionHandler()`
- [ ] Document cell-diff renderer and make it default
- [ ] Expose `$lastFrameDuration` for adaptive framerate
- [ ] Better README section on extension points (filter, subscriptions, termios)
- [ ] Add `RendererInterface` for testability

### Phase 2: Renderer Evolution (medium-risk)

- [ ] Extract `Buffer` / `Cell` value objects
- [ ] Add `RendererInterface` with current implementation as default
- [ ] Implement `CellBufferRenderer` as second implementation
- [ ] `Model::view(Buffer $buf)` optional signature
- [ ] Deprecate `view(): string` in favor of buffer-based rendering

### Phase 3: Layout (medium-risk)

- [ ] Add `Layout` class with `Constraint` enum
- [ ] `Layout::vertical(Rect, list<Constraint>): list<Rect>`
- [ ] `Layout::horizontal(Rect, list<Constraint>): list<Rect>`
- [ ] Document use in `view()` methods
- [ ] Consider Cassowary port from ratatui if constraints are insufficient

### Phase 4: Widget System (long-term)

- [ ] `Widget` interface: `render(Buffer, Rect): void`
- [ ] `StatefulWidget` interface with associated `*State` type
- [ ] Port basic widgets from `sugar-bits` into core: `Block`, `Paragraph`
- [ ] `WidgetRenderer` visitor pattern for composing renderers
- [ ] `Extension` system for optional widget sets

### Phase 5: Advanced Features (exploratory)

- [ ] Streaming renderer for large buffers
- [ ] Network renderer for distributed TUI
- [ ] Animation driver for smooth transitions
- [ ] CSS stylesheet parser

---

## Implementation References

| File | Purpose |
|---|---|
| `src/Program.php` | Elm runtime orchestrator (1009 lines) |
| `src/Model.php` | Core interface contract |
| `src/Cmd.php` | Command factory closures (466 lines) |
| `src/Renderer.php` | Frame diff renderer (290 lines) |
| `src/InputReader.php` | Byte-stream state machine parser (583 lines) |
| `src/View.php` | Per-frame view container (64 lines) |
| `src/Subscriptions.php` | Immutable subscription collection |
| `src/Composite.php` | Child component manager |
| `src/ScreenStack.php` | Immutable modal stack |
| `src/WorkerPool.php` | Subprocess worker pool (455 lines) |
| `src/Util/Ansi.php` | ANSI escape sequences (953 lines) |
| `src/Util/Parser.php` | Tokenizing parser (252 lines) |
| `src/Util/ColorProfile.php` | Terminal capability detection |
| `src/Util/Tty.php` | Raw mode management |
| `src/Util/Tty/PosixBackend.php` | Unix termios |
| `src/Util/Tty/WindowsBackend.php` | Windows FFI backend |
| `src/SgrState.php` | SGR state tracker for minimal repaint |
| `src/ProgramOptions.php` | Runtime tunables (143 lines) |

---

## Conclusion

candy-core is a well-engineered PHP port of the Elm Architecture for TUIs. It correctly implements the core TEA pattern (Model/Update/View) with comprehensive input handling (keyboard, mouse, focus, paste, resize), a working cell-diff renderer, and ReactPHP-based concurrency. Its main architectural gaps relative to upstream Go implementations (ultraviolet/ratatui) are the lack of a cell-based buffer model (string diffing vs cell diffing) and the absence of a constraint-based layout system. The extension points are clean (filter, subscriptions, termios injection) but could be better documented. With the suggested refinements — particularly the buffer/cell model evolution — candy-core would be a solid foundation for professional PHP TUI applications.
