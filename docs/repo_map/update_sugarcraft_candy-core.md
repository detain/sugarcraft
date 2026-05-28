# Overview

**candy-core** is the foundational TUI runtime library of the SugarCraft monorepo — a PHP port of the Go [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea) ecosystem implementing The Elm Architecture (TEA). It provides the core runtime for terminal applications with comprehensive input handling (keyboard, mouse, focus, paste, resize), a working cell-diff renderer, and ReactPHP-based concurrency.

**Biggest opportunity areas:**
1. **Cell-based buffer model** — Replace string-based diffing with true cell-based rendering (like ultraviolet/ratatui) for finer-grained partial repaints
2. **Constraint-based layout system** — Cassowary solver for responsive layouts that adapt to terminal resize
3. **Widget trait system** — Enable reusable drawing primitives via a `Widget` interface pattern
4. **Reactive state descriptors** — Auto-watcher injection for reactive properties (like textual's `Reactive`)
5. **Backend abstraction** — Pluggable terminal backend (like php-tui's `Backend` interface)

**Biggest missing capabilities:**
1. No built-in layout algorithm (constraint-based or otherwise)
2. No widget/component library in core (all components are separate leaf libs)
3. No streaming/incremental rendering for large terminal buffers
4. No async command cancellation mechanism
5. No structured logging / debug mode with frame timing

---

# Internal Capability Summary

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

### Program Lifecycle

`Program::run()` (`src/Program.php`, 1009 lines) orchestrates:
1. Terminal setup: alt screen, raw mode, mouse, focus, bracketed paste modes
2. Initial dispatch: `WindowSizeMsg`, `EnvMsg`, `ColorProfileMsg`
3. Model init: run `init()` and schedule returned `Cmd`
4. Stream watcher: `addReadStream(STDIN)` feeds `InputReader::parse()` → Msgs
5. Render tick: `addPeriodicTimer(1/framerate)` calls `renderFrame()` when dirty
6. Teardown: restore terminal, cancel subscriptions, close recorder

### Input Handling

`InputReader` (`src/InputReader.php`, 583 lines) is a stateful byte-stream parser handling:
- Buffering across `fread()` boundaries for partial escape sequences
- Full CSI decoding (arrows, F1-F24, home/end, page up/down, delete)
- Mouse: SGR encoding (CSI `<` params `M`/`m`)
- Kitty keyboard protocol with modifiers
- OSC handling: window title, colors, clipboard
- DCS handling: XTVERSION replies
- Bracketed paste mode (CSI `200~` / CSI `201~`)

### Rendering System

`Renderer` (`src/Renderer.php`, 290 lines) implements two algorithms:

**Line-diff (default):** For each changed row: `cursorTo(row, 1) + eraseLine + new_line`

**Cell-diff ("cursed", opt-in):**
- Tokenize previous and current lines via `Parser`
- Compute longest common token prefix
- Emit partial repaint: `cursorTo(col_after_prefix) + eraseToLineEnd + active_SGR + suffix`
- Falls back to full line repaint when partial would be larger

Both wrap frames in `Ansi::syncBegin()` / `Ansi::syncEnd()` (DEC 2026 synchronized output).

### Command System

`Cmd` (`src/Cmd.php`, 466 lines) provides factory closures (`Closure(): ?Msg`) including: `quit()`, `suspend()`, `interrupt()`, `batch()`, `sequence()`, `tick()`, `every()`, `send()`, `promise()`, `exec()`, `raw()`, `println()`, `setClipboard()`, `readClipboard()`, plus terminal capability queries and mouse/keyboard mode controls.

### Subscription System

`Subscriptions` (`src/Subscriptions.php`) is an immutable collection with fluent builder supporting `withTick()`, `withKey()`, `withSignal()`, `withCustom()`. Reconciliation after each `update()` diffs old vs new, canceling dropped and starting new subscriptions.

### Component Tree

- **`Component`** interface extends `Model` with `onMount()` / `onUnmount()` lifecycle hooks
- **`Composite`** manages children by ordinal position, routes Msgs to matching child
- **`ScreenStack`** — immutable stack for modal workflows

### Utilities

| Class | Responsibility |
|---|---|
| `Util/Ansi` | 953 lines — all ANSI escape constants and generators |
| `Util/Parser` | Tokenizing parser for byte streams |
| `Util/ColorProfile` | Detection via env vars, TERM, COLORTERM |
| `Util/Width` | Unicode width calculation (mbstring-based) |
| `Util/Tty` | Raw mode enable/disable via termios or Windows API |
| `Util/Tty/PosixBackend` | Unix termios implementation |
| `Util/Tty/WindowsBackend` | Windows `SetConsoleMode` / `GetConsoleMode` via FFI |
| `SgrState` | Tracks SGR state across token prefix for minimal repaint |

### WorkerPool

`WorkerPool` (`src/WorkerPool.php`, 455 lines) — bounded-concurrency pool for CPU-bound tasks:
- Uses `proc_open()` subprocesses with serialized callables on stdin/stdout
- Default concurrency: 4 workers
- 50ms poll interval for reading worker stdout
- Automatic worker respawn on death

## Strengths

1. **Faithful Elm Architecture port** — Model/Update/View/Message pattern well-implemented with immutable `with*()` builders
2. **Comprehensive input coverage** — Full Kitty keyboard protocol, SGR mouse, focus, bracketed paste
3. **Cell-diff renderer** — Token-aware longest-common-prefix partial repaint, dramatically reduces SSH payloads
4. **ReactPHP integration** — Clean integration with `addReadStream`, `addPeriodicTimer`, `futureTick`, `addTimer`
5. **View side-effect tracking** — Only emitting changed terminal state prevents terminal spam
6. **WorkerPool** — Subprocess-based CPU offloading with bounded concurrency
7. **Extensive Msg taxonomy** — 42+ Msg subclasses covering every terminal event
8. **ANSI library completeness** — 953-line Ansi utility covering every escape sequence needed
9. **Good test coverage** — 40+ test files
10. **Graceful degradation** — Non-TTY output, missing pcntl, missing FFI all handled gracefully

## Weaknesses

1. **PHP's lack of concurrency primitives** — No true parallelism, goroutine equivalent, or native async/await syntax
2. **Tuple simulation via arrays** — `[$model, $cmd] = $model->update($msg)` lacks compile-time enforcement
3. **Closure serialization limitation** — `WorkerPool` can only serialize stateless closures
4. **Frame diff is string-based, not cell-based** — Cannot render partial updates at the cell level
5. **No layout system** — No built-in layout algorithm; consumers must compose strings manually
6. **No widget/component library in core** — All components are separate leaf libs
7. **No streaming/reactive output** — Full view redraw every dirty frame
8. **Subscription timers polling model** — Key subscriptions use 0.1s polling interval
9. **Windows FFI dependency** — Raw mode on Windows requires FFI
10. **No built-in logging/tracing** — Debug output goes to `error_log()`
11. **Synchronous event loop blocking** — `exec()` blocks during external command execution

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `charmbracelet/bubbletea` | Direct upstream — 1:1 port source | Elm architecture, command pattern, cell-based renderer (via ultraviolet) | Critical |
| `charmbracelet/ultraviolet` | Rendering foundation for Bubble Tea v2 | Cell-based buffer model, style diff algorithm, touched-line tracking, Cassowary layout, border primitives | Critical |
| `ratatui/ratatui` | Rust TUI benchmark (19.6k stars) | Buffer diffing, Widget/StatefulWidget traits, Cassowary layout, immediate-mode rendering | Critical |
| `textualize/textual` | Python state-of-the-art (30k stars) | Reactive state descriptors, message bubbling, CSS styling, layout engine, Pilot testing | Critical |
| `php-tui/php-tui` | Direct PHP competition | Backend abstraction, Widget visitor pattern, Extension system, cell-based diffing | High |
| `WhispPHP/whisp` | PHP SSH server with PTY | FFI-based PTY management, process per connection model | Medium |
| `alecrabbit/php-console-spinner` | PHP spinner patterns | Delta-timer using `hrtime()`, cursor management, two-buffer erase/overwrite | Low |

---

# Feature Gap Analysis

## Critical

### 1. Cell-based buffer rendering
**Title:** True cell-based buffer model for per-cell diffing

**Description:** Currently candy-core's cell-diff operates on tokenized strings, not logical cells. ultraviolet and ratatui use a `Buffer`/`Cell` grid model where each cell has style + content. This enables true per-cell diffing — only changed cells are repainted.

**Why it matters:** Dramatically reduces bandwidth for SSH connections, enables cursor position diffing, allows partial line repaints with accurate cell boundaries.

**Source:** `docs/repo_map/charmbracelet_ultraviolet.md` — "Cell-Based Diffing Renderer" section; `docs/repo_map/ratatui_ratatui.md` — "Buffer Cell Representation" section

**Implementation ideas:**
- Add `Cell` value object with `char`, `style`, `link`, `width` fields
- Add `Buffer` class with `Area` dimensions holding a grid of `Cell`s
- Add `BufferUpdate` value object for changed cells
- Replace `Renderer::render(string)` with `render(Buffer)`
- Each `Model::view()` receives a `Buffer` to write into
- Implement `RenderBuffer.TouchLine()` style dirty-line tracking

**Estimated complexity:** High — requires new value object hierarchy, buffer writing API, and renderer rewrite

**Expected impact:** High — enables next-gen rendering optimization

### 2. Constraint-based layout system
**Title:** Cassowary constraint solver for responsive layouts

**Description:** No built-in layout algorithm exists. Models must manually compute positions via string padding. ratatui and ultraviolet both use Cassowary constraint solver for layouts that adapt to terminal resize.

**Why it matters:** Enables `view()` methods to compute child positions declaratively rather than manually. "Sidebar is 30% width" + "main + sidebar = terminal width" → solver resolves both.

**Source:** `docs/repo_map/ratatui_ratatui.md` — "Cassowary Constraint Solver (Layout)" section; `docs/repo_map/charmbracelet_ultraviolet.md` — "Cassowary constraint-based layout solver" in feature list

**Implementation ideas:**
- Port Cassowary algorithm (already partially exists in `honey-bounce`?)
- Add `Layout` class with `Constraint` enum: `Length()`, `Percentage()`, `Ratio()`, `Fill()`, `Min()`, `Max()`
- Add `Layout::vertical(Rect, list<Constraint>): list<Rect>` and `horizontal()` variants
- Document use in `view()` methods
- Consider adopting from `honey-bounce` if Cassowary port exists there

**Estimated complexity:** Medium-High — algorithm port + API design + documentation

**Expected impact:** High — transforms how views are composed

## High Value

### 3. Widget trait system
**Title:** `Widget` interface for reusable drawing primitives

**Description:** ratatui's `Widget` / `StatefulWidget` traits enable reusable drawing primitives. candy-core has no equivalent in core.

**Why it matters:** Enables third-party widget extensions without modifying core. `interface Widget { public function render(Buffer $buf, Rect $area): void; }`

**Source:** `docs/repo_map/ratatui_ratatui.md` — "Core Traits" section; `docs/repo_map/php-tui_php-tui.md` — "Widget Rendering" section with visitor pattern

**Implementation ideas:**
- Define `Widget` interface with `render(Buffer, Rect): void`
- Define `StatefulWidget` interface with associated `*State` type
- Add `WidgetRenderer` visitor pattern for composing renderers
- Port basic widgets from `sugar-bits` into core: `Block`, `Paragraph`

**Estimated complexity:** Medium — interface design + initial implementations

**Expected impact:** High — unlocks ecosystem extensibility

### 4. Backend abstraction
**Title:** Pluggable terminal backend interface

**Description:** php-tui's `Backend` interface allows swapping terminal backends. Currently candy-core writes directly to `$output` stream.

**Why it matters:** Enables testing without real terminal, remote display over network, alternative terminal backends.

**Source:** `docs/repo_map/php-tui_php-tui.md` — "Backend (interface)" section; `docs/repo_map/charmbracelet_ultraviolet.md` — "Screen interface" pattern

**Implementation ideas:**
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

**Estimated complexity:** Low-Medium — interface + one implementation + Program wiring

**Expected impact:** Medium — testing and extensibility

### 5. Reactive state descriptors
**Title:** Auto-watcher reactive properties

**Description:** textual uses `Reactive` descriptors with auto-watcher injection. Instead of `update()` returning new model every time, reactive properties auto-update the view when changed.

**Why it matters:** Reduces boilerplate for models with many observable properties. Watchers automatically triggered on property change.

**Source:** `docs/repo_map/textualize_textual.md` — "Reactive State Management" section

**Implementation ideas:**
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

**Estimated complexity:** Medium — attribute + magic method injection + documentation

**Expected impact:** Medium — DX improvement for complex models

## Medium Priority

### 6. Structured logging
**Title:** `Program::withLogger()` for PSR-3 compatible logging

**Description:** No `Program::withLogger()`. Debug output goes to `error_log()`. bubbletea has `tea.LogToFile()`.

**Source:** `docs/repo_map/charmbracelet_bubbletea.md` — "Logging: Debug logging to file via `tea.LogToFile()`"

**Implementation ideas:**
- Add `Program::withLogger(LoggerInterface $logger)` option
- Log frame timing, Msg dispatch, subscription reconciliation
- Add debug mode with `$lastFrameDuration` exposure

**Estimated complexity:** Low — PSR-3 interface + ProgramOptions builder addition

**Expected impact:** Medium — debugging experience

### 7. Exception handler customization
**Title:** `Program::withExceptionHandler()` for panic recovery

**Description:** `catchPanics` is boolean; no custom exception handler. bubbletea has built-in panic recovery.

**Source:** `docs/repo_map/charmbracelet_bubbletea.md` — "Panic Recovery: Automatic recovery with terminal restoration"

**Implementation ideas:**
```php
$program->withExceptionHandler(fn(\Throwable $e) => ...);
```

**Estimated complexity:** Low — callback storage + call site in Program

**Expected impact:** Medium — production robustness

### 8. Terminal capability queries
**Title:** High-level API for "does terminal support X?"

**Description:** `Ansi` has all sequences but no high-level capability detection. Graceful degradation for feature detection.

**Source:** `docs/repo_map/charmbracelet_ultraviolet.md` — "shouldQuerySynchronizedOutput" pattern

**Implementation ideas:**
- `Capability` class/enum for feature flags
- `TerminalCapabilities::detect(): self` via probe sequences
- `TerminalCapabilities::supportsSynchronizedOutput(): bool`

**Estimated complexity:** Medium — detection logic + API surface

**Expected impact:** Medium — cross-terminal compatibility

### 9. Async command cancellation
**Title:** `CancellationToken` for Cmd cancellation

**Description:** Once a `Cmd` is scheduled, it runs to completion. No way to cancel a long-running operation.

**Source:** `docs/repo_map/charmbracelet_bubbletea.md` — "Async Command Cancellation: Commands cannot be cancelled mid-execution" (noted as weakness)

**Implementation ideas:**
- Add `CancellationToken` passed to Cmd factories
- Cmd factories check token periodically
- `Cmd::timeout($seconds, $produce)` wraps with cancellation

**Estimated complexity:** Medium — token design + integration + documentation

**Expected impact:** Medium — prevents stuck operations

### 10. Built-in animation engine
**Title:** `Animator` class with easing functions and `Transition` objects

**Description:** textual has `Animator` with easing functions. `honey-bounce` provides spring physics but no animation driver.

**Source:** `docs/repo_map/textualize_textual.md` — "Animation System: Animator with easing functions and transitions"

**Implementation ideas:**
- `Animator` class that calls `view()` at interpolated states
- `Transition` objects with easing functions
- Integration with `Cmd::every()` for frame scheduling

**Estimated complexity:** High — requires physics integration + animation driver design

**Expected impact:** Low-Medium — enhancement for visual polish

## Low Priority

### 11. Streaming/incremental renderer
**Title:** Chunked rendering for large terminal buffers

**Description:** Full frame redraw every dirty frame. No dirty-region tracking beyond changed-row detection.

**Source:** `docs/repo_map/charmbracelet_ultraviolet.md` — "async rendering" noted as weakness

**Implementation ideas:**
- For buffers 200+ rows, chunk rendering across multiple frames
- Priority-based dirty region ordering

**Estimated complexity:** High — scheduling algorithm + frame chunking

**Expected impact:** Low — edge case for very large terminals

### 12. Network renderer
**Title:** Serve TUI over SSH without X11

**Description:** Render on server, display on client via diffs over socket. Already designed for bandwidth optimization.

**Source:** `docs/repo_map/charmbracelet_ultraviolet.md` — architecture notes

**Implementation ideas:**
- `NetworkRenderer` sending diffs over socket
- Client-side viewer process

**Estimated complexity:** High — networking + protocol design

**Expected impact:** Low — specialized use case

### 13. Sixel/Kitty graphics renderer
**Title:** Actual renderer for graphics formats

**Description:** Sequences are defined in `Ansi` but no actual renderer for Sixel/Kitty graphics.

**Source:** `docs/repo_map/sugarcraft_candy-core.md` — noted in feature inventory

**Implementation ideas:**
- `GraphicsRenderer` interface
- Sixel and Kitty AGM implementations

**Estimated complexity:** High — complex protocol implementation

**Expected impact:** Low — specialized use case

---

# Algorithm / Performance Opportunities

## Current approach vs external approach

### Cell-Based vs String-Based Diffing

**Current (candy-core):** Token-aware string LCP (longest common prefix) for partial line repaint. Tokenizes both lines via `Parser`, walks tokens to find LCP, emits `cursorTo + eraseToLineEnd + active_SGR + suffix`.

**External (ultraviolet/ratatui):** True cell-based buffer where each cell has (rune, style, link, width). `RenderBuffer.TouchLine()` marks dirty cells. Renderer computes minimal ANSI sequences for delta.

**Why external is better:**
- Per-cell tracking enables precise partial repaints at cell granularity
- Style diffing (`Style.Diff()`) computes minimal SGR transition, avoiding full `reset + new style` when cheaper
- Cursor position can be diffed together with content
- Wide character handling is symmetric (placeholder cells for emoji trailers)

**Tradeoffs:**
- Requires new `Buffer`/`Cell` value object hierarchy
- `Model::view()` must receive a `Buffer` to write into (breaking change to `view(): string`)
- More complex implementation than string diffing

**Applicability:** High — core rendering is fundamental to all candy-core apps

### Cassowary Constraint Solver

**Current:** No layout system; manual string padding in `view()`

**External (ultraviolet/ratatui):** Cassowary algorithm resolves competing layout constraints with priorities

**Why external is better:**
- Declarative constraint specification rather than imperative position calculation
- Automatic adaptation to terminal resize
- Handles complex multi-element layouts correctly

**Tradeoffs:**
- Algorithm complexity; needs careful port/testing
- May be overkill for simple layouts

**Applicability:** High — layout is used in virtually every non-trivial TUI

### Subscription Timer Polling

**Current:** Key subscriptions use `addPeriodicTimer(0.1, ...)` polling model

**External (textual):** Event-driven with spatial map for O(log n) hit detection

**Why external is better:**
- No polling overhead for idle key states
- Spatial indexing enables efficient mouse event routing

**Tradeoffs:**
- Terminal async key events not universally available
- Spatial map only useful with widget hierarchy

**Applicability:** Low-Medium — works acceptably for most use cases

### High-Resolution Timing

**Current:** `microtime(true)` for tick scheduling

**External (alecrabbit/php-console-spinner):** `hrtime(true)` for sub-millisecond precision

**Why external is better:**
- Monotonic clock unaffected by system time changes
- Nanosecond precision for frame timing

**Tradeoffs:**
- `hrtime()` is not universally available on all platforms
- Microsecond precision is usually sufficient

**Applicability:** Low — `microtime(true)` is adequate for frame timing

---

# Architecture Improvements

## 1. Extract `RendererInterface`

Currently `Renderer` is concrete. Extract interface to allow:
- Cell-based renderer (future)
- Test renderer (captures output without terminal I/O)
- Network renderer (sends diffs over wire)

```php
interface RendererInterface {
    public function render(string|Buffer $frame): void;
    public function reset(): void;
}
```

## 2. Separate InputReader parsing from dispatch

Currently input loop tightly coupled to `Program::dispatch()`. Extract:
```php
interface InputSource {
    public function attach(LoopInterface $loop, callable(Msg) $callback): void;
    public function detach(): void;
}
```

## 3. View side-effect emission ordering

`applyViewSideEffects()` emits sequences outside the `syncBegin/syncEnd` boundary. Move side effects inside sync boundary for correct synchronized output mode behavior.

## 4. ProgramOptions builder

16 constructor parameters is unwieldy:
```php
$options = ProgramOptions::create()
    ->withAltScreen(true)
    ->withMouseMode(MouseMode::CellMotion)
    ->withCellDiffRenderer(true)
    ->build();
```

## 5. Extract `Subscription` source abstraction

`Subscription` kinds (Tick/Key/Signal/Custom) are fixed. Allow custom subscription sources for specialized I/O.

---

# API / Developer Experience Improvements

## Current pain points

1. **`update()` return type** — `array{0: Model, 1: ?Closure}` requires destructuring; no IDE autocomplete for Msg subclasses
2. **`WorkerPool` serialization** — closures silently fail; named functions required
3. **Debugging** — stdout is renderer's domain; `error_log()` is only option
4. **`Ansi` constants** — public but not documented with escape sequence meanings
5. **`SgrState`** — internal but critical to cell-diff algorithm; no public documentation

## Improvements needed

1. **Add `Program::withLogger()`** wrapping PSR-3 interface
2. **Add `Program::withExceptionHandler()`**
3. **Expose `$lastFrameDuration`** after render for adaptive framerate
4. **Document extension points** in README: filter, subscriptions, termios
5. **Add `RendererInterface`** for testability
6. **Expose `Ansi` constant documentation** — docblocks explaining escape sequences
7. **Document SGR state tracking** in `Renderer` — inline comments explaining algorithm

---

# Documentation / Cookbook Opportunities

## Missing documentation

1. **Cell-diff renderer** — buried in ProgramOptions; many users don't know it exists
2. **Extension points** — filter, subscriptions, termios injection not well documented
3. **SIGTSTP/SIGCONT pattern** — suspend/resume example in `examples/suspend.php` but pattern not well-documented
4. **`view(): string` vs `view(): View`** — when to use each, tradeoffs unclear
5. **WorkerPool limits** — closure serialization limitation not clearly documented

## Cookbook opportunities

1. **"Building a text input"** — full example with validation, cursor movement
2. **"Async operations with cancellation"** — `Cmd::promise()` patterns
3. **"Custom subscription sources"** — for specialized I/O
4. **"Testing TUIs without screenshots"** — Pilot-like pattern for PHP
5. **"Porting bubbletea apps to candy-core"** — migration guide

---

# UX / TUI Improvements

## Current UX issues

1. **Frame timing hidden** — no way to know if render is fast/slow
2. **No adaptive framerate** — fixed FPS regardless of terminal capability
3. **Mouse routing is model's job** — coordinates come in but no hit-testing utility
4. **No built-in command palette** — textual has extensive `command.py`; candy-kit is partial

## Potential improvements

1. **Expose frame timing** — `$program->getLastFrameDuration()` for adaptive framerate
2. **Spatial map for hit testing** — O(log n) widget lookup by screen position (from textual)
3. **Command palette** — extend `candy-kit` with fuzzy search
4. **Animation support** — smooth transitions via `honey-bounce` integration

---

# Testing / Reliability Improvements

## Current test coverage

40+ test files covering:
- Renderer diffing (line-diff and cell-diff)
- Input parsing (key, mouse, paste, focus)
- Subscriptions (tick, key, signal, custom)
- Component lifecycle (mount/unmount)
- Worker pool
- SGR state
- Color profile detection

## Testing gaps

1. **No `app.run_test()` equivalent** — textual's Pilot pattern for automated UI testing without screenshots
2. **No frame timing tests** — FPS accuracy not tested
3. **No integration tests for full program lifecycle** — only unit tests for components
4. **`SgrState` edge cases** — underline color (58), overline (53), 256→truecolor downgrade not fully tested

## Improvement opportunities

1. **Add `ProgramTest` helper** — `runTest()` with `press("q")`, `click("#button")`, `pause(0.5)`, `resizeTerminal(80, 24)`
2. **Add frame timing assertions** — ensure renders complete within deadline
3. **Expand SgrState coverage** — full attribute set + color conversions
4. **Add rendering snapshot tests** — for View side-effect ordering

---

# Ecosystem / Integration Opportunities

## From other repos

### From ultraviolet

1. **Border primitives** — `NormalBorder()`, `RoundedBorder()`, `BlockBorder()`, `DoubleBorder()`, etc. as string constants
2. **Window/View hierarchy** — `NewWindow`/`NewView` for modal dialogs that don't clobber underlying frame
3. **Style diff** — extend `SgrState` to handle underline color, overline, all attributes

### From ratatui

1. **Stylize trait** — `"hello".red().on_blue().bold()` → `Span` fluent shorthands
2. **StatefulWidget + `*State` types** — persistent scroll position, selection

### From textual

1. **Message bubbling** — messages bubble up through DOM-like tree
2. **CSS-based styling (TCSS)** — web-developer familiarity
3. **Pilot testing** — `app.run_test()` pattern

### From php-tui

1. **Extension system** — `DisplayExtension` interface for optional widget sets
2. **Visitor pattern for widget rendering** — `WidgetRenderer::render(self, Widget, Buffer, Area)`

### From whisp

1. **FFI-based PTY management** — cross-platform FFI approach for PTY constants
2. **Process per connection model** — fork-based concurrency pattern for SSH

---

# Notable PRs / Issues / Discussions

## From charmbracelet/bubbletea

**Relevant PRs:**
- `bubblezone` integration for mouse zone tracking
- `lipgloss` v2 for styling improvements
- `bubbletea` v2 for cell-based renderer

**Lessons learned:** The Elm architecture scales well but requires discipline. Goroutine-based concurrency is superior to PHP callbacks.

## From charmbracelet/ultraviolet

**Notable implementation:**
- `Style.Diff()` algorithm (cell.go:L258-L408) — minimal SGR transition computation
- Touched-line tracking (`RenderBuffer.TouchLine()`) — foundation of efficient diffing
- Cassowary layout solver — same algorithm as Ratatui

**Lessons learned:** Cell-based model enables optimizations impossible with string-based rendering.

## From textualize/textual

**Notable implementation:**
- `_spatial_map.SpatialMap` — R-tree-like spatial indexing for O(log n) hit detection
- Message bubbling with handled/unhandled states
- Reactive state descriptors with auto-watcher injection

**Lessons learned:** CSS styling brings web developers; message hierarchy enables modular apps.

## From ratatui/ratatui

**Notable implementation:**
- `Widget` / `StatefulWidget` trait pattern
- Cassowary constraint solver
- Immediate-mode rendering with buffer diffing

**Lessons learned:** Widget traits enable ecosystem extension without modifying core.

## From php-tui/php-tui

**Notable implementation:**
- `Backend` interface abstraction
- `DisplayExtension` plugin system
- Visitor pattern for widget rendering

**Lessons learned:** Backend abstraction critical for testing; extension system enables lean core.

---

# Recommended Roadmap

## Immediate wins (0-3 months)

1. **Builder for ProgramOptions** — 16 params is unwieldy; fluent builder improves DX
2. **`Program::withLogger()` / `Program::withExceptionHandler()`** — production debugging
3. **Make cell-diff the default** — now that it's validated, enable by default
4. **Document cell-diff renderer in README** — buried in ProgramOptions, users don't know
5. **Expose `$lastFrameDuration`** for adaptive framerate
6. **Document extension points** — filter, subscriptions, termios

## Medium-term improvements (3-6 months)

1. **Extract `RendererInterface`** — enables test renderer, network renderer
2. **`Buffer` / `Cell` value objects** — foundation for cell-based rendering
3. **Add `Layout` class with `Constraint` enum** — Cassowary solver for layouts
4. **`Widget` interface** — enables reusable drawing primitives
5. **Backend abstraction** — `Backend` interface for testing and remote display

## Major architectural upgrades (6-12 months)

1. **Cell-based renderer** — replace string diffing with `Buffer`/`Cell` diffing
2. **Reactive state descriptors** — `#[Reactive]` attribute with auto-watchers
3. **Message bubbling** — for hierarchical component trees
4. **CancellationToken** — for async command cancellation

## Experimental ideas (12+ months)

1. **Streaming renderer** — for 200+ row terminals
2. **Network renderer** — distributed TUI over SSH
3. **CSS-like stylesheet** — `Stylesheet` parser + cascading
4. **Animation engine** — `Animator` + `honey-bounce` integration
5. **Hot reload** — watch source files, recompile without restart

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|---|---|---|---|---|
| Builder for ProgramOptions | Medium | Low | Low | Immediate |
| Program::withLogger() | Medium | Low | Low | Immediate |
| Make cell-diff default | Medium | Low | Low | Immediate |
| Document extension points | Medium | Low | Low | Immediate |
| RendererInterface | High | Medium | Low | Medium-term |
| Buffer/Cell value objects | High | High | Medium | Medium-term |
| Layout with Constraint | High | Medium-High | Medium | Medium-term |
| Widget interface | High | Medium | Low | Medium-term |
| Backend abstraction | Medium | Medium | Low | Medium-term |
| Cell-based renderer | High | High | High | Major |
| Reactive state | High | Medium | Medium | Major |
| Message bubbling | Medium | Medium | Medium | Major |
| CancellationToken | Medium | Medium | Low | Medium-term |
| Spatial map (hit testing) | Medium | Medium | Low | Medium-term |
| Streaming renderer | Low | High | Medium | Experimental |
| Network renderer | Low | High | High | Experimental |
| Animation engine | Low-Medium | High | Medium | Experimental |

---

# Final Strategic Assessment

candy-core is a well-engineered PHP port of the Elm Architecture for TUIs. It correctly implements the core TEA pattern (Model/Update/View) with comprehensive input handling (keyboard, mouse, focus, paste, resize), a working cell-diff renderer, and ReactPHP-based concurrency. Its main architectural gaps relative to upstream Go implementations (ultraviolet/ratatui) are:

1. **Lack of cell-based buffer model** — string diffing vs cell diffing limits optimization potential
2. **Absence of constraint-based layout** — manual string composition vs Cassowary constraint solver
3. **No widget trait system** — core is runtime-only vs ratatui's extensible Widget interface
4. **Reactive state is manual** — immutable models + `update()` returns vs textual's auto-watcher descriptors

The most impactful immediate improvements are:
- **ProgramOptions builder** (low effort, high DX impact)
- **Logger/exception handler hooks** (low effort, production debugging)
- **Make cell-diff the default** (low effort, performance improvement)
- **Extract RendererInterface** (medium effort, unlocks testing and future renderers)
- **Layout system** (medium effort, transforms how views are composed)

The medium-term evolution toward a **Buffer/Cell model** would bring candy-core closer to ultraviolet/ratatui's rendering sophistication, enabling true per-cell diffing and cursor position tracking. This is the most significant architectural investment but yields the highest return.

The extension points (filter, subscriptions, termios injection) are clean but under-documented. Improving documentation and adding higher-level APIs (logger, exception handler, frame timing) would significantly improve the developer experience without risking architectural complexity.

Overall, candy-core is in a healthy state at Phase 3 (v1 parity with Bubble Tea v2 core runtime). The foundation is solid; the next phase should focus on DX improvements, documentation, and selective architectural enhancements (Buffer/Cell model, Layout system) that maintain backward compatibility while enabling future capability growth.
