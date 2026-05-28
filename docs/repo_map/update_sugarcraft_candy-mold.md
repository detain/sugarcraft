# Ecosystem Comparison Update Report: candy-mold

## Overview

**candy-mold** is the `composer create-project` bootstrap scaffold for the SugarCraft monorepo — a PHP port of the Go `charmbracelet/bubbletea-app-template` ecosystem. It is **not a library** but a starter template that developers fork to bootstrap new SugarCraft TUI applications.

**Biggest opportunities:**
- Add sub-component composition demonstration (Spinner from sugar-bits embedded in Counter) to mirror Go template's `spinner.Model` pattern
- Add a comprehensive testing infrastructure pattern (Simulator, Snapshot testing)
- Demonstrate multi-screen navigation (ScreenStack)
- Add `init()` command demo (e.g., ticking clock) to show startup commands

**Biggest missing capabilities:**
- No `init()` command demonstration (returns null)
- No multi-screen/multi-page navigation
- No external I/O (Cmd::exec(), Cmd::http(), Cmd::promise())
- No error handling path
- No composition of sugar-bits components within the demo model

---

## Internal Capability Summary

### Current Architecture

The scaffold is 5 files implementing a minimal counter:

```
my-app/
├── composer.json     # requires candy-core + candy-sprinkles
├── phpunit.xml      # bootstrap="vendor/autoload.php", colors, .phpunit.cache/
├── bin/start        # 3-line entry point: autoload → Program(new Counter()) → run()
├── src/Counter.php # 72-line demo Model (immutable, readonly int $n)
└── tests/CounterTest.php  # 7 deterministic unit tests
```

**Entry point (`bin/start`):**
```php
#!/usr/bin/env php
<?php
require $path;  // locate vendor/autoload.php
use App\Counter;
use SugarCraft\Core\Program;
(new Program(new Counter()))->run();
```

**Counter Model:**
```php
final class Counter implements Model {
    public function __construct(public readonly int $n = 0) {}
    public function init(): ?\Closure { return null; }
    public function update(Msg $msg): array { /* ↑/↓/q handling */ }
    public function view(): string { return Style::new()->border(Border::rounded())->padding(1, 2)->render($body); }
    public function subscriptions(): ?Subscriptions { return null; }
}
```

### Strengths

1. **Minimal surface area** — 5 files, 72-line Model. Readable in 10 minutes.
2. **Self-contained demonstration** — Entry point is literally 3 lines. Framework is invisible.
3. **Deterministic testing** — No event loop, no TTY, no mocking. `CounterTest` calls `->update($msg)` and asserts returned `[Model, ?Cmd]` tuple.
4. **Docblock-first teaching** — 20-line docblock in Counter.php explains Elm architecture, immutability, `->with*()` convention.
5. **`composer create-project` lifecycle** — Proper `type: project` with `bin: bin/start`, installable via Composer.
6. **Path-repo closure** — `repositories[]` pointing at sibling directories for monorepo development without Packagist.
7. **VHS demo** — `.vhs/start.tape` renders a GIF showing counter being driven with ↑/↓/q.

### Weaknesses

1. **No sub-component composition** — Single flat Model. Go template shows `spinner.Model` as a field, showing composition.
2. **No `init()` command** — Returns `null`. Real apps use `init()` to return startup commands (timers, HTTP calls).
3. **No multi-screen demonstration** — No `ScreenStack` or navigation pattern shown.
4. **No error handling** — Go template has custom `errMsg error` type. Counter has no error path.
5. **No external I/O** — `Cmd::exec()`, `Cmd::promise()`, `Cmd::http()` not demonstrated.
6. **No `Cmd::batch()`/`Cmd::sequence()`** — Only `Cmd::quit()` shown.
7. **Minimal bin entry point** — No explicit error handling with `fmt.Println(err); os.Exit(1)` equivalent.
8. **No sugar-bits components used** — README mentions Spinner/TextInput but scaffold uses only raw `Style`.
9. **Tests don't use data providers** — 7 explicit test methods rather than `@dataProvider` for key-type matrix.
10. **CI is monorepo-only** — No own `.github/workflows/`. Can only be tested within monorepo context.

---

## Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|--------------------------|----------|
| `charmbracelet/bubbletea-app-template` | Primary upstream | Sub-component composition (spinner.Model in parent), complete CI/CD | P0 |
| `charmbracelet/bubbletea` | Core framework | TEA pattern, Cmd/Batch/Sequence, v2 declarative Views | P0 |
| `textualize/textual` | Sister framework | Component-based widget system, CSS styling, async support | P1 |
| `php-tui/php-tui` | PHP ecosystem | Widget system, Cassowary layout solver, double-buffering | P1 |
| `charmbracelet/gum` | Shell toolkit | Command composition, style system, interactive prompts | P2 |
| `c9s/CLIFramework` | PHP CLI | Hierarchical commands, shell completion, interactive prompts | P2 |

**Source citations:**
- `docs/repo_map/charmbracelet_bubbletea-app-template.md`
- `docs/repo_map/charmbretea.md`
- `docs/repo_map/textualize_textual.md`
- `docs/repo_map/php-tui_php-tui.md`
- `docs/repo_map/c9s_CLIFramework.md`
- `docs/repo_map/charmbracelet_gum.md`

---

## Feature Gap Analysis

### Critical

**1. No Sub-Component Composition Demonstration**
- **Description:** The counter is a single flat Model. The Go template demonstrates `spinner.Model` as a field of the parent `model` struct, showing composition. This is essential for building larger apps from smaller TEA components.
- **Why it matters:** Without this, developers have no pattern for combining multiple stateful components. They must discover composition by trial and error.
- **Source:** `docs/repo_map/charmbracelet_bubbletea-app-template.md` — "The use of a `spinner.Model` as a sub-component within the parent `model` struct shows the compositional pattern central to Charmbracelet TUIs"
- **Implementation:** Add a `SpinnerModel` sub-component to Counter, demonstrating that sub-components implement `tea.Model` directly and delegate `Init()`/`Update()`/`View()` to them.
- **Complexity:** Medium — requires understanding model composition pattern.
- **Impact:** High — teaches the core pattern for building complex apps.

**2. No Testing Infrastructure Pattern**
- **Description:** No `Program::withInput()` / `Program::withOutput()` for I/O redirection, no headless testing, no snapshot testing utilities.
- **Why it matters:** Bubble Tea's most requested feature is a first-class testing framework. sugar-bits/sugar-bits provides `candy-vcr` for session recording but no deterministic model testing pattern.
- **Source:** `docs/repo_map/pr_charmbracelet_bubbletea.md` — "Issue #1654: Proposal: Testing Framework" and "Testing Infrastructure Gap" (Section 7.1)
- **Implementation:** Add a `Simulator` class and snapshot testing utilities to candy-core, then update CounterTest to demonstrate them.
- **Complexity:** High — requires designing testing abstractions in candy-core.
- **Impact:** Critical — testing is a known gap across the entire TUI ecosystem.

### High

**3. No `init()` Command Demonstration**
- **Description:** `init()` returns `null`. A real scaffold might return `Cmd::tick(1.0, fn() => new TickMsg())` to show the startup command pattern.
- **Why it matters:** The `init()` method is where real apps do startup I/O (config loading, HTTP calls, timer setup). Developers seeing `null` have no pattern for this.
- **Source:** `docs/repo_map/charmbracelet_bubbletea-app-template.md` — "Returns `m.spinner.Tick` to start the spinner timer immediately"
- **Implementation:** Change Counter to start a ticking clock via `init()`, showing `Cmd::tick()` pattern.
- **Complexity:** Low — just returning a different command from `init()`.
- **Impact:** High — shows the full TEA lifecycle including startup commands.

**4. No Multi-Screen Navigation**
- **Description:** All apps start simple. The scaffold could demonstrate two-screen app (counter + settings screen) using `ScreenStack`.
- **Why it matters:** Most real apps have multiple screens/views. The scaffold gives no hint about navigation patterns.
- **Source:** `docs/repo_map/charmbracelet_bubbletea.md` — Screen management in bubbletea v2
- **Implementation:** Add a `SettingsScreen` that Counter navigates to via a key binding.
- **Complexity:** Medium — requires understanding ScreenStack and navigation state.
- **Impact:** High — essential for real app development.

**5. No Error Handling Path**
- **Description:** Go template has custom `errMsg error` type for typed error routing. Counter has no error path.
- **Why it matters:** Real apps have error conditions (file not found, network failure). Without an example, developers don't know how to handle errors in the TEA pattern.
- **Source:** `docs/repo_map/charmbracelet_bubbletea-app-template.md` — "`errMsg` type — A bare `error` type alias used as a custom message type for error propagation"
- **Implementation:** Add a `LoadConfigCmd` that could fail, with `errMsg` routing in `update()`.
- **Complexity:** Low — just adding a message type and handling branch.
- **Impact:** High — error handling is essential for production apps.

### Medium

**6. No External I/O Demonstration**
- **Description:** `Cmd::exec()`, `Cmd::promise()`, `Cmd::http()` not shown. A version that shells out to `git status` or fetches an API would demonstrate async commands.
- **Why it matters:** Most interesting TUIs interact with external systems. Without a pattern, developers must discover async commands independently.
- **Source:** `docs/repo_map/charmbracelet_bubbletea.md` — "Cmd Pattern for Async I/O" section
- **Implementation:** Add an example that runs `git status` via `Cmd::exec()` and displays the result.
- **Complexity:** Medium — requires understanding of async command execution.
- **Impact:** Medium — common use case but not foundational.

**7. No `Cmd::batch()`/`Cmd::sequence()` Demonstration**
- **Description:** Counter returns `Cmd::quit()` directly. Showing `Cmd::batch()` to run multiple concurrent commands would demonstrate concurrency.
- **Why it matters:** `Batch` and `Sequence` are how parallel operations are coordinated in TEA. Knowing when to use which is key.
- **Source:** `docs/repo_map/charmbracelet_bubbletea.md` — "BatchMsg & SequenceMsg" section
- **Implementation:** Add a variant that uses `Cmd::batch()` for multiple background operations.
- **Complexity:** Low — just different command combinators.
- **Impact:** Medium — useful but not foundational.

**8. Minimal Bin Entry Point**
- **Description:** Go template's `main.go` includes explicit error handling with `fmt.Println(err); os.Exit(1)`. candy-mold's `bin/start` delegates all error handling silently.
- **Why it matters:** Silent failures make debugging harder. Explicit error handling sets a better example.
- **Source:** `docs/repo_map/charmbracelet_bubbletea-app-template.md` — "main() — tea.NewProgram(initialModel()).Run() entry point with error handling"
- **Implementation:** Add try/catch in `bin/start` with styled error output.
- **Complexity:** Low — just adding error handling boilerplate.
- **Impact:** Medium — improves developer experience.

### Low

**9. No sugar-bits Components Used**
- **Description:** README's "common next steps" table mentions `sugar-bits` Spinner, `sugar-prompt` Group, etc., but scaffold uses only raw `Style` from `candy-sprinkles`.
- **Why it matters:** Developers might not realize how to integrate components from other packages.
- **Source:** `docs/repo_map/charmbracelet_bubbletea-app-template.md` — Shows bubbles components being used
- **Implementation:** Embed a `Spinner` from `sugar-bits` in the Counter to show cross-package composition.
- **Complexity:** Low — just importing and using a component.
- **Impact:** Low — more illustrative than essential.

**10. Tests Don't Use Data Providers**
- **Description:** 7 explicit test methods. Using `@dataProvider` for the key-type → expected-count matrix would reduce boilerplate.
- **Why it matters:** Idiomatic PHPUnit uses data providers for parametrized tests.
- **Source:** `docs/repo_map/sugarcraft_candy-mold.md` — "Tests do not use data providers"
- **Implementation:** Refactor CounterTest to use `@dataProvider` for key handling tests.
- **Complexity:** Low — PHPUnit convention.
- **Impact:** Low — code style improvement.

**11. CI is Monorepo-Only**
- **Description:** No own `.github/workflows/`. Can only be tested within monorepo. Standalone `composer create-project` users need their own CI.
- **Why it matters:** Developers who fork/clone the scaffold don't have CI infrastructure.
- **Source:** `docs/repo_map/charmbracelet_bubbletea-app-template.md` — "CI/CD Pipelines — GoReleaser, golangci-lint, Dependabot, codecov"
- **Implementation:** Add a minimal `.github/workflows/` directory with CI setup that works for standalone usage.
- **Complexity:** Medium — CI configuration.
- **Impact:** Low — most developers use monorepo CI.

---

## Algorithm / Performance Opportunities

### Current vs External Approach

**1. Immutability Enforcement**
- **Current:** PHP `readonly` properties enforce nominal immutability. `new self($this->n + 1)` returns a new instance.
- **External (bubbletea):** Go structs are mutable. The TEA loop doesn't hold references to old models after `Update()` returns.
- **Why PHP is better:** Compile-time prevention of accidental mutation. Go relies on developer discipline and the fact that the loop drops the old reference.
- **Tradeoffs:** PHP's `readonly` is shallow (object properties inside readonly objects can still be mutated). Go's approach is more explicit but riskier.
- **Applicability:** The current approach is sound for value types. Document the caveat about object properties.

**2. Tuple Simulation**
- **Current:** `[Model, Cmd]` as PHP `array` — convention only, no compile-time enforcement.
- **External (bubbletea):** Native Go tuples with compile-time type checking.
- **Why external is better:** Type safety prevents errors like returning `[null, $model]` (reversed).
- **Tradeoffs:** PHP has no native tuples. Could use named tuples via custom array class, but that adds complexity.
- **Applicability:** The current convention is acceptable. Consider a `@psalm-return` annotation for static analysis.

**3. Event Loop Concurrency**
- **Current:** ReactPHP single-threaded event loop. `Cmd` is a `Closure(): ?Msg`.
- **External (bubbletea):** Go goroutines with channels. Each `Cmd` can spawn a goroutine.
- **Why external is better:** True parallelism for CPU-bound work. Goroutines are cheap and can run concurrently.
- **Tradeoffs:** PHP's single-threaded model eliminates goroutine race conditions entirely. No data races between concurrent commands.
- **Applicability:** PHP model is simpler and sufficient for I/O-bound TUI work. CPU-bound work is not a typical TUI concern.

**4. Terminal Capability Detection**
- **Current:** Not detailed in scaffold.
- **External (bubbletea v2):** Async queries for mode 2026, mode 2027 during init. Can leak escape sequences on short-lived programs.
- **Why current could be better:** If capability queries are handled synchronously or opt-out is provided, the leak is avoided.
- **Tradeoffs:** Async queries allow graceful degradation but complicate the lifecycle.
- **Applicability:** Must be handled in candy-core Program, not in scaffold. But scaffold should document this.

**5. Renderer Architecture**
- **Current:** Not implemented in scaffold (delegated to candy-core).
- **External (bubbletea v2):** Cursed Renderer with cell-based delta updates and synchronized output mode (ANSI 2026).
- **Why external is better:** Minimizes terminal I/O. Flicker-free rendering on modern terminals.
- **Tradeoffs:** Complex to implement. The scaffold doesn't need to show this — it's infrastructure.
- **Applicability:** candy-core concern, not scaffold.

---

## Architecture Improvements

### Immediate (v1.x)

1. **Add Spinner Sub-Component** — Embed `SugarCraft\Bits\Spinner` in Counter, demonstrating component composition pattern. Show that sub-components implement `Model` directly (Issue #371 in bubbletea: "Components should implement tea.Model").

2. **Add `init()` Command** — Return `Cmd::tick(1.0, fn() => new TickMsg())` from `init()` to demonstrate startup command pattern. Show the clock ticking on screen.

3. **Add Multi-Screen** — Add a `SettingsScreen` with navigation via a key binding, demonstrating `ScreenStack` or equivalent navigation.

4. **Add Error Handling** — Add a `LoadConfigCmd` that could fail, with `errMsg` type and error routing in `update()`.

### Medium-Term (v2.0)

5. **Testing Infrastructure** — Design and implement `Program::withInput()` / `Program::withOutput()` and `Snapshot` testing utilities in candy-core, then update CounterTest to demonstrate them.

6. **Add External I/O** — Add example using `Cmd::exec()` to run `git status` and display output, demonstrating async command pattern.

7. **Add `Cmd::batch()`** — Demonstrate running multiple concurrent commands with `Cmd::batch()`.

8. **Add Minimal CI** — Include `.github/workflows/` for standalone usage with `composer create-project`.

---

## API / Developer Experience Improvements

1. **Improve `bin/start` Error Handling** — Add try/catch with styled error output to match Go template's `fmt.Println(err); os.Exit(1)`.

2. **Add Data Providers to Tests** — Refactor CounterTest to use `@dataProvider` for key handling tests, reducing boilerplate.

3. **Document Immutability Caveats** — Add docblock noting that `readonly` is shallow for object properties.

4. **Add `@psalm-return` Annotations** — For tuple simulation like `@return array{0: Model, 1: ?Closure}` to enable static analysis.

5. **Add "Common Next Steps" to Counter** — Inline comments showing which methods to modify to add specific features (text input, spinner, etc.).

---

## Documentation / Cookbook Opportunities

1. **Expand README "Anatomy of a SugarCraft Model"** — Add more detail about `init()`, `subscriptions()`, and the command pattern.

2. **Add Troubleshooting Section** — Common errors and how to fix them (e.g., "I changed the model but nothing happened").

3. **Add "Porting from bubbletea" Guide** — Mapping between Go TEA concepts and PHP SugarCraft concepts for developers coming from Go.

4. **Add Example Variants** — Branch examples showing: counter with spinner, counter with multi-screen, counter with error handling.

5. **Document Upgrade Path** — Clear table mapping developer intents ("I want to add X") to specific SugarCraft libraries and the steps to integrate them.

---

## UX / TUI Improvements

1. **Better Entry Point Feedback** — Show a brief loading animation or message while the program initializes.

2. **Improved Help Text** — Show available keybindings with `?` key in addition to the static help in `view()`.

3. **Keyboard Shortcut Hints** — Add `key.NewBinding` equivalent pattern for declarative key bindings with auto-generated help.

4. **Visual Feedback on State Change** — Show subtle animation when counter increments/decrements (e.g., brief color flash).

---

## Testing / Reliability Improvements

1. **Add Snapshot Tests** — For `view()` output, asserting raw ANSI bytes against golden files.

2. **Add Message Injection Tests** — Demonstrate testing `update()` with various message types beyond KeyMsg.

3. **Add Immutability Tests** — Explicit assertions that `update()` returns a new instance, not mutated `$this`.

4. **Add Integration Test Skeleton** — Demonstrate testing the full program with captured I/O (requires candy-core `withInput()`/`withOutput()`).

5. **Document Test Patterns** — Add PHPDoc to CounterTest showing what each test category (behavior, snapshot, coercion) validates.

---

## Ecosystem / Integration Opportunities

1. **Embed sugar-bits Components** — Demonstrate Spinner, TextInput, ProgressBar from sugar-bits within Counter or sibling models.

2. **Demonstrate Cross-Library Composition** — Show `SugarCraft\Shine\Renderer` rendering Markdown help text alongside the counter.

3. **Add sugar-prompt Example** — Demonstrate interactive prompts using sugar-prompt's `Group` component.

4. **Show sugar-charts Integration** — Add a variant that plots counter history as a sparkline using sugar-charts.

5. **Document sugar-wish SSH Integration** — Add note about running the app over SSH using sugar-wish.

---

## Notable PRs / Issues / Discussions

### From bubbletea (Primary Upstream)

**Issue #1654: Testing Framework Proposal** (`docs/repo_map/pr_charmbracelet_bubbletea.md`)
- Community proposed `Simulator` class that drives Model synchronously without goroutines
- `SendKey()`, `Type()`, `Resize()` for input simulation
- Snapshot testing with golden files
- **Lesson:** Testing infrastructure is the #1 requested feature after 6+ years. Design it early.

**Issue #1655: DevTools Inspector Proposal**
- F12-toggleable inspector showing message log, state viewer, component tree
- Mirrors React DevTools
- **Lesson:** Consider web-based inspector as differentiator for SugarCraft.

**Issue #1522: Nested Model Fails to Exit/Switch State**
- Complex nested model architecture has reliability issues with sub-models signaling parent
- Root cause: switch statement handling when >12-15 sub-models involved
- **Lesson:** Multi-model composition is hard. Provide clear patterns and document them.

**Issue #371: Components Should Implement tea.Model**
- Bubbles components don't implement `tea.Model` interface, requiring wrapper types
- **Lesson:** All sugar-bits components MUST implement `SugarCraft\Core\Model` directly. No wrappers required.

**Issue #958: Sequence/Empty Commands Fix**
- `tea.Sequence` caused infinite loops with 100% CPU when commands are all-nil
- **Lesson:** Both `Batch` and `Sequence` must handle: `[]`, `[null]`, `[null, null]`, `[cmd, null, cmd]`.

### From bubbletea-app-template

**Strengths to Emulate:**
- Complete CI/CD with GoReleaser, golangci-lint, Dependabot, codecov
- Sub-component composition (spinner.Model in parent)
- Production-grade starter, not just hello-world
- Conventional commits changelog grouping

**Gaps to Fill:**
- No tests in Go template → candy-mold's 7 tests are BETTER
- No error handling → add this
- No multi-screen → add this
- No external I/O → add this

---

## Recommended Roadmap

### Immediate Wins (v1.1)

1. **Add `init()` command** — Return `Cmd::tick()` to show startup timer
2. **Add error handling** — `errMsg` type and error routing in `update()`
3. **Add `@psalm-return` annotations** — For tuple simulation static analysis
4. **Improve bin/start error handling** — Add try/catch with styled output
5. **Refactor tests to use data providers** — `@dataProvider` for key handling

### Medium-Term (v1.2)

6. **Add Spinner sub-component** — Embed `SugarCraft\Bits\Spinner` in Counter
7. **Add multi-screen navigation** — Counter + SettingsScreen with ScreenStack
8. **Add external I/O demo** — `Cmd::exec()` running `git status`
9. **Add `Cmd::batch()` demo** — Multiple concurrent background commands
10. **Add minimal CI workflow** — `.github/workflows/` for standalone usage

### Major Upgrades (v2.0)

11. **Design testing infrastructure** — `Program::withInput()`/`withOutput()`, `Snapshot`, `Simulator` in candy-core
12. **Update scaffold to use testing infra** — Demonstrate deterministic model testing
13. **Add web-based DevTools** — Inspector showing message flow and state
14. **Add example variants** — Branch examples for different use cases
15. **Document upgrade path comprehensively** — Full "Porting from bubbletea" guide

### Experimental

16. **AI agent integration** — Consider AI coding agent use cases for TUI frameworks
17. **Text selection component** — tmux-like selection when mouse enabled
18. **LRU memoization utilities** — Bundle for text width calculations

---

## Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|--------------|--------|-------------|------|----------|
| Add `init()` command demo | High | Low | None | P0 |
| Add Spinner sub-component | High | Medium | Low | P0 |
| Add testing infrastructure | Critical | High | Medium | P0 |
| Add error handling | High | Low | None | P1 |
| Add multi-screen navigation | High | Medium | Low | P1 |
| Add `@psalm-return` annotations | Medium | Low | None | P1 |
| Improve bin/start error handling | Medium | Low | None | P1 |
| Add external I/O demo | Medium | Medium | Low | P2 |
| Add `Cmd::batch()` demo | Medium | Low | None | P2 |
| Add minimal CI workflow | Low | Medium | None | P2 |
| Refactor tests with data providers | Low | Low | None | P3 |
| Add web-based DevTools | Medium | High | High | P3 |

---

## Final Strategic Assessment

candy-mold is a **solid, minimal scaffold** that correctly demonstrates the Elm Architecture in PHP. Its 72-line `Counter` Model shows immutability, message dispatch, command scheduling, and declarative styling. The 7-unit test suite demonstrates deterministic model testing without event loops or mocking. The `composer create-project` lifecycle makes it the correct entry point for PHP developers new to SugarCraft.

The **three most critical improvements** are:

1. **Add a Spinner sub-component** to mirror the Go template's demonstration of `spinner.Model` as a field of the parent. This teaches the composition pattern essential for building larger apps.

2. **Design testing infrastructure early** in candy-core (`Program::withInput()`/`withOutput()`, `Snapshot`, `Simulator`). The bubbletea community has requested testing tools for 6+ years without a first-class solution. This is SugarCraft's opportunity to differentiate.

3. **Add `init()` command and multi-screen navigation** to demonstrate the full TEA lifecycle and common app patterns beyond a single flat Model.

The scaffold succeeds at its job: **get a developer from zero to a running SugarCraft TUI app in under a minute**. With these improvements, it can also teach them how to build production-quality applications with proper error handling, component composition, testing, and multi-screen navigation.

**Key insight from ecosystem analysis:** The TUI ecosystem's biggest gap is testing infrastructure. Bubble Tea's most-requested feature for 6+ years is a testing framework. By designing testing-first in SugarCraft (with headless mode, snapshot testing, and message injection), we can differentiate on a pain point that the upstream Go community still hasn't solved.
