# TUI Runtime Architecture Research: candy-core

**Date:** 2026-05-13
**Upstream:** charmbracelet/bubbletea (Go)
**Sources:** Context7 documentation, Elm Guide, framework source analysis

---

## 1. Current candy-core Architecture

### 1.1 Core Components

```
┌─────────────────────────────────────────────────────────────┐
│                         Program                              │
│  (Elm-architecture runtime, ReactPHP event loop)            │
├─────────────────────────────────────────────────────────────┤
│  Model Interface                                            │
│    - init(): ?Closure          → initial Cmd                │
│    - update(Msg): [Model, ?Closure] → next state + Cmd      │
│    - view(): string|View       → rendered output            │
├─────────────────────────────────────────────────────────────┤
│  Msg Interface (marker for all events)                      │
│    - KeyMsg, MouseMsg, WindowSizeMsg, TickRequest, etc.    │
├─────────────────────────────────────────────────────────────┤
│  Cmd (Closure: ?Msg) — one-shot async effects               │
│    - Cmd::tick($seconds, $produce)                          │
│    - Cmd::every($seconds, $produce)  ← wall-clock aligned   │
│    - Cmd::promise($factory)         ← ReactPHP promises     │
│    - Cmd::batch(...$cmds)           ← concurrent execution  │
│    - Cmd::sequence(...$cmds)        ← sequential execution  │
│    - Cmd::exec($cmd, $capture, $onComplete)                 │
├─────────────────────────────────────────────────────────────┤
│  View (per-frame control)                                   │
│    - body, cursor, windowTitle, progressBar, mouseMode      │
└─────────────────────────────────────────────────────────────┘
```

### 1.2 Event Loop Integration

- Uses **ReactPHP EventLoop** for async I/O
- Input reading via `stream_set_blocking($input, false)` + `addReadStream`
- Render tick via `addPeriodicTimer` at configured framerate (default 60fps)
- Dirty-flag rendering: only repaints when `Model` changes via `update()`

### 1.3 Current Effect System

**Strengths:**
- Clean Elm-style `Model → update(Msg) → [Model, ?Cmd]` pattern
- First-class async via `AsyncCmd` wrapping `React\Promise\PromiseInterface`
- `Cmd::batch()` for concurrent commands, `Cmd::sequence()` for ordered execution
- `TickRequest` enables recurring timers via `Cmd::every()` / `Cmd::tick()`

**Gaps (see Section 3):**
- No Elm-style **Subscriptions** for model-driven continuous event streams
- No **Screen Stack** (modal dialogs, sub-screens)
- No **Component Composition** framework beyond interface contract

---

## 2. Cross-Language Pattern Comparison

### 2.1 Update Loop Patterns

| Framework | Loop Type | Concurrency | Batch Render |
|-----------|-----------|-------------|--------------|
| **Bubble Tea (Go)** | External (synced to input events + timer) | Goroutines via `tea.Batch` | Yes, on every `Update` |
| **Textual (Python)** | Async event loop (asyncio) | Workers + async/await | Automatic via reactive |
| **Dioxus (Rust)** | Virtual DOM + signals | `tokio` async | Fine-grained reactive |
| **Leptos (Rust)** | Signals + suspense | `tokio` async | Fine-grained reactive |
| **candy-core (PHP)** | ReactPHP EventLoop | Promise-based | Dirty-flag at 60fps |

### 2.2 Message Passing

| Framework | Pattern | Special Messages |
|-----------|---------|------------------|
| **Bubble Tea** | `Update(Msg) → (Model, Cmd)` | `BatchMsg`, `SequenceMsg`, `TickMsg` |
| **Textual** | `on_key`, `on_mount`, message handlers | `Screen`, `Worker` |
| **Elm** | `update(Msg) → (Model, Cmd)` | Continuous `Sub` (separate from `Cmd`) |
| **candy-core** | `update(Msg) → [Model, ?Closure]` | `BatchMsg`, `SequenceMsg`, `TickRequest`, `AsyncCmd` |

### 2.3 Component Composition

| Framework | Approach |
|-----------|----------|
| **Bubble Tea** | Model embedding (compose models manually) |
| **Textual** | Widget hierarchy + CSS + `compose()` |
| **Dioxus** | Props + signals + context API |
| **candy-core** | Model interface contract only — no composition framework |

### 2.4 Screen Stack Management

| Framework | Approach |
|-----------|----------|
| **Bubble Tea** | Manual via model composition; no native stack |
| **Textual** | **Native screen stack** — `push_screen()`, `pop_screen()`, `switch_screen()`, `screen_stack` property |
| **Dioxus** | Router pattern (external) |
| **candy-core** | None — handled via model composition only |

### 2.5 Effect Handling

#### Commands (one-shot effects)

| Framework | Factory | Cancellation |
|-----------|---------|--------------|
| **Bubble Tea** | `tea.Batch()`, `tea.Sequence()`, `tea.Every()`, `tea.Exec()` | Via `CancelFunc` |
| **Elm** | `Cmd.none`, `Cmd.map`, `Task.perform` | Immutable, always runs to completion |
| **Textual** | `@work` decorator, `run_worker()` | Via `worker.cancel()` |
| **candy-core** | `Cmd::batch()`, `Cmd::sequence()`, `Cmd::promise()`, `Cmd::every()` | Promise rejection path only |

#### Subscriptions (continuous effects) — KEY GAP

| Framework | Pattern | Dynamic Enabling |
|-----------|---------|------------------|
| **Bubble Tea** | **No native subscriptions** — use `Every` + return cmd in `Update` | Manual via model state |
| **Elm** | `subscriptions: Model → Sub Msg` — returns `Sub.none` or active subscriptions | Yes, based on model |
| **Textual** | Workers with `@work(thread=True)` + groups | Yes, via worker groups |
| **candy-core** | `Cmd::every()` — but requires re-issuing each tick | Partial (via TickRequest re-subscription) |

---

## 3. Specific Improvements for candy-core

### 3.1 HIGH PRIORITY: Elm-Style Subscriptions

**Problem:** candy-core lacks the Elm `subscriptions` pattern. Currently, continuous events like timers must be manually re-subscribed in each `update()` cycle:

```php
// CURRENT: Manual re-subscription pattern
public function update(Msg $msg): array
{
    $this->ticks += 1;
    if ($this->ticks % 60 === 0) {
        return [$this, Cmd::every(1.0, fn() => new TickMsg())];
    }
    return [$this, null];
}
```

**Solution:** Add a `Subscription` system where the `Model` declares active subscriptions and the runtime auto-manages lifecycle:

```php
// PROPOSED: Elm-style subscriptions
interface Model {
    public function subscriptions(): \SugarCraft\Core\Subscriptions;
}

final class Subscriptions {
    public static function none(): self;
    public static function every(float $seconds, \Closure $produce): self;
    public static function batch(Subscription ...$subs): self;
}
```

**Runtime changes:**
- `Program` adds `addTimer` for each active subscription
- Re-evaluates `model->subscriptions()` after each `update()` dispatch
- Reconciles subscription set delta (cancel removed, add new)
- Enables dynamic enabling/disabling based on model state (e.g., disable timer when paused)

**Source references:**
- Elm subscriptions: https://guide.elm-lang.org/effects/time
- Bubbletea workaround: Uses `Every` + re-subscription in `Update` (no native subscription reconciliation)

**Effort:** Medium (3-5 sessions)
- Add `Subscription` class + `Subscriptions` container
- Modify `Program::dispatch()` to track and reconcile subscriptions
- Add tests for subscription lifecycle

---

### 3.2 HIGH PRIORITY: Screen Stack

**Problem:** Modal dialogs, confirmations, and sub-screens require manual model composition. Bubbletea has no native screen stack; Textual leads here.

**Solution:** Add a `Screen` model interface + `ScreenStack` in `Program`:

```php
interface Screen {
    public function init(): ?\Closure;
    public function update(Msg $msg): array;  // [Screen, ?Cmd]
    public function view(): string|View;
}

final class Program {
    private array $screenStack = [];

    public function pushScreen(Screen $screen): void;
    public function popScreen(): Screen;
    public function switchScreen(Screen $screen): void;  // replace top
}
```

**Runtime changes:**
- `update()` dispatches to top screen only
- Screen can call `Program::pushScreen()` / `Program::popScreen()` via special `Msg`
- Track `screen_stack` property for introspection

**Source references:**
- Textual API: `push_screen()`, `pop_screen()`, `switch_screen()`, `screen_stack: list[Screen[Any]]`
- Textual `stack_updates: Reactive[int]` — reactive screen change tracking

**Effort:** Medium (4-6 sessions)
- Define `Screen` interface mirroring `Model`
- Add `ScreenStack` class managing `array<Screen>`
- Modify `Program` dispatch loop to route to active screen
- Add `ScreenChangedMsg` for reactive tracking

---

### 3.3 MEDIUM PRIORITY: Component Composition Framework

**Problem:** No standard pattern for composing TUI components (header, sidebar, content, footer). Each library must invent its own.

**Solution:** Add a `Component` abstract class with lifecycle:

```php
abstract class Component {
    abstract public function init(): ?\Closure;
    abstract public function update(Msg $msg): array;
    abstract public function view(): string|View;

    public function mount(Component $parent): void {}  // hook for setup
    public function unmount(): void {}                  // cleanup hook
}
```

**With support for:**
- Child components via `compose()` method
- `on_mount()` / `on_unmount()` lifecycle hooks (Textual pattern)
- Context passing via `provide_context()` / `use_context()`

**Source references:**
- Textual: `on_mount()`, `on_unmount()` event handlers
- Textual `compose()` returns widget tree

**Effort:** Medium (3-4 sessions)
- Define `Component` abstract class
- Add lifecycle hooks
- Add example in `sugar-bits` tests

---

### 3.4 MEDIUM PRIORITY: Worker Pool for CPU-Bound Tasks

**Problem:** PHP is single-threaded. Long computations block the event loop even with async promises.

**Solution:** Add `Worker` class wrapping `React\Promise` with optional `php` threading for CPU-bound work:

```php
final class Worker {
    public static function spawn(\Closure $task): \React\Promise\PromiseInterface;
    public static function group(string $group): WorkerGroup;
}

final class WorkerGroup {
    public function cancel(): void;  // cancel all in group
}
```

**Use case:** Background data processing, file I/O without blocking render loop.

**Effort:** Low-Medium (2-3 sessions)

---

### 3.5 LOW PRIORITY: Error Boundary / Suspense

**Problem:** Unhandled exceptions in `update()` crash the program. No recovery mechanism.

**Solution:** Add `ErrorBoundary` component + `Suspense` for async rendering:

```php
final class ErrorBoundary extends Component {
    public function __construct(
        public readonly \Closure $children,
        public readonly \Closure $fallback,  // (Throwable) → View
    ) {}
}

// Usage in Model::view():
if ($this->loading) {
    return new View(new Suspense(
        fallback: fn() => new View("Loading..."),
        children: fn() => $this->fetchData(),
    ));
}
```

**Source references:**
- Leptos `Suspense` component with `fallback` ViewFnOnce
- Dioxus `GlobalSignal::global()` + error boundaries

**Effort:** Medium (3-4 sessions)

---

## 4. Prioritized Recommendations

| Priority | Improvement | Effort | Impact |
|----------|-------------|--------|--------|
| **P1** | Elm-Style Subscriptions | 3-5 sessions | High — enables reactive UI patterns |
| **P1** | Screen Stack | 4-6 sessions | High — simplifies modal/sub-screen composition |
| **P2** | Component Composition Framework | 3-4 sessions | Medium — standardizes library patterns |
| **P2** | Worker Pool | 2-3 sessions | Medium — unblocks event loop for CPU work |
| **P3** | Error Boundary / Suspense | 3-4 sessions | Medium — improves resilience |

### Immediate Next Steps (P1)

1. **Subscriptions Proof-of-Concept** (1 session)
   - Add `Subscription` interface + `Subscriptions::every()` factory
   - Modify `Program` to track active subscriptions
   - Test with `Cmd::every()` migration

2. **Screen Stack API Design** (1 session)
   - Design `Screen` interface
   - Design `ScreenStack` class
   - Write integration test skeleton

---

## 5. Architecture Diagram: Proposed Subscriptions

```
┌──────────────────────────────────────────────────────────────┐
│                        Program                                │
├──────────────────────────────────────────────────────────────┤
│  ┌─────────────┐     ┌─────────────┐     ┌────────────────┐ │
│  │ Subscriptions│     │  Dispatch   │     │    Renderer    │ │
│  │   Manager   │────▶│    Loop     │────▶│   (View())     │ │
│  └─────────────┘     └─────────────┘     └────────────────┘ │
│         │                   │                               │
│         │           ┌───────┴───────┐                       │
│         │           │               │                       │
│         ▼           ▼               ▼                       │
│  ┌─────────────────────────────────────────────┐           │
│  │                 Model                         │           │
│  │  ├─ init(): Cmd                              │           │
│  │  ├─ update(Msg): [Model, Cmd]                │           │
│  │  ├─ view(): View                             │           │
│  │  └─ subscriptions(): Subscriptions  ◄── NEW  │           │
│  └─────────────────────────────────────────────┘           │
└──────────────────────────────────────────────────────────────┘
```

---

## 6. Key Source References

### Bubble Tea (Go - upstream)
- **Model interface:** `type Model interface { Init() Cmd; Update(Msg) (Model, Cmd); View() string }`
- **Batch/Sequence:** `tea.Batch()`, `tea.Sequence()` for concurrent/sequential commands
- **No subscriptions:** Uses `Every()` + re-subscription pattern
- **Source:** https://pkg.go.dev/github.com/charmbracelet/bubbletea

### Textual (Python)
- **Screen stack:** `push_screen()`, `pop_screen()`, `switch_screen()`, `screen_stack`
- **Workers:** `@work(thread=True)` for background tasks
- **Reactive:** `@reactive` decorator + `data_bind()`
- **Lifecycle:** `on_mount()`, `on_unmount()`
- **Source:** https://textual.textualize.io/api/app

### Elm (original)
- **Subscriptions:** `subscriptions: Model → Sub Msg` — separate from commands
- **Time subscription:** `Time.every 1000 Tick`
- **Source:** https://guide.elm-lang.org/effects/time

### Dioxus (Rust)
- **Global signals:** `GlobalSignal::global()` for app-wide state
- **Memos:** `Memo::global()` for derived state
- **Source:** https://dioxuslabs.github.io/dioxus/

### candy-core (PHP - current)
- **Program:** `/home/sites/sugarcraft/candy-core/src/Program.php`
- **Model:** `/home/sites/sugarcraft/candy-core/src/Model.php`
- **Cmd:** `/home/sites/sugarcraft/candy-core/src/Cmd.php`
- **TickRequest:** `/home/sites/sugarcraft/candy-core/src/TickRequest.php` (closest to subscription)
- **ProgramOptions:** `/home/sites/sugarcraft/candy-core/src/ProgramOptions.php`

---

## 7. Conclusion

candy-core is a well-architected Elm-architecture port that closely mirrors Bubble Tea. The main gaps compared to modern TUI frameworks are:

1. **No native subscriptions** — continuous events must be manually re-subscribed per tick
2. **No screen stack** — modal/sub-screen composition requires manual model nesting
3. **No component composition framework** — libraries invent their own patterns

The proposed additions (Subscriptions, ScreenStack, Component base class) would bring candy-core closer to Textual's feature set while maintaining its clean Elm architecture. These are additive changes that won't break existing code.

**Recommended first action:** Implement Elm-style Subscriptions as a POC — it addresses the most common pain point (timer management) and validates the reconciliation pattern before tackling screen stack.
