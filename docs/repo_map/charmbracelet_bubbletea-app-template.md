# charmbracelet/bubbletea-app-template

## Metadata
- **URL:** https://github.com/charmbracelet/bubbletea-app-template
- **Language:** Go (1.24.2)
- **Stars:** 248
- **License:** MIT (Copyright (c) 2022-2023 Charmbracelet, Inc)
- **Description:** A template repository to create Bubble Tea apps, providing a starter project that includes all core Charmbracelet TUI dependencies with CI/CD workflows already configured.

## Feature List
- **Starter TUI App** — A minimal "loading forever" sample app using Bubble Tea's `Model`/`Update`/`View` pattern, demonstrating the full TEA (Tool/Equipment/Agent or Tree/Enter/Agent) architecture.
- **Bubble Tea Integration** — The core TUI framework (`github.com/charmbracelet/bubbletea v1.3.10`) with the canonical `tea.Model` interface (`Init() tea.Cmd`, `Update(tea.Msg) (tea.Model, tea.Cmd)`, `View() string`).
- **Bubbles Library** — Pre-integrated `github.com/charmbracelet/bubbles v1.0.0` components, demonstrated with a `spinner.Model` that ticks independently.
- **Lip Gloss Styling** — `github.com/charmbracelet/lipgloss v1.1.0` for ANSI-compatible rich text styling (color, attributes) applied via `lipgloss.NewStyle().Foreground(lipgloss.Color("205"))`.
- **Keyboard Handling** — Multi-key binding via `key.NewBinding(key.WithKeys("q", "esc", "ctrl+c"))` with `key.Matches()` checking and `quitKeys.Help().Desc` for auto-generated help text.
- **GoReleaser** — `.goreleaser.yaml` configured for cross-platform binary builds with CGO disabled, `trimpath`, changelog grouping by conventional commits (`feat:*`, `fix:*`), and GitHub release footer.
- **golangci-lint** — `.golangci.yml` enabling `thelper`, `gofumpt`, `tparallel`, `unconvert`, `unparam`, `wastedassign`.
- **Dependabot** — `.github/dependabot.yml` managing weekly updates for `gomod`, `github-actions`, and `docker` ecosystems with grouped all-pattern merges; excludes Bubble Tea v2.0.0-beta1.
- **CI/CD Pipelines** — GitHub Actions for build (with race detection + atomic coverage), lint (golangci-lint), release (GoReleaser on tag push), and Dependabot auto-merge.
- **Codecov** — Coverage upload via `codecov/codecov-action@v6` with atomic covermode.

## Key Classes and Methods

- **`model` struct** — The sole application state type, holding a `spinner.Model`, a `quitting bool`, and an `err error`.
  - `Init() tea.Cmd` — Returns `m.spinner.Tick` to start the spinner timer immediately.
  - `Update(tea.Msg) (tea.Model, tea.Cmd)` — Message dispatcher: handles `tea.KeyMsg` (quit key matching → returns `tea.Quit`), `errMsg` (captures error), and the default case delegates to `m.spinner.Update(msg)`.
  - `View() string` — Renders the TUI output: spinner view + help description; appends newline when quitting.
  - `main()` — `tea.NewProgram(initialModel()).Run()` entry point with error handling and `os.Exit(1)`.
- **`initialModel() model`** — Factory that constructs the initial model with a `spinner.New()`, sets `Spinner = spinner.Dot`, and applies `lipgloss.Color("205")` style.
- **`errMsg` type** — A bare `error` type alias used as a custom message type for error propagation in the TEA loop.

## Notable Algorithms / Named Patterns

- **TEA Pattern (The Elm Architecture)** — Bubble Tea's adaptation of Elm's architecture: `Model` (immutable state) → `Update` (pure state transitions driven by messages) → `View` (declarative rendering). This is the core design pattern of the entire Charmbracelet ecosystem.
- **Key Binding Pattern** — `key.NewBinding(...).WithKeys(...).WithHelp(...)` for declarative keyboard shortcut registration, with `key.Matches()` for ergonomic filtering.
- **Independent Tick Commands** — `m.spinner.Tick` as a `tea.Cmd` returned from `Init`, demonstrating how to run concurrent background processes (timers, network requests) within the TEA loop.
- **Error Message Type Pattern** — Defining a private `type errMsg error` as a named message type for typed error routing in `Update`.
- **Conventional Commits Changelog** — GoReleaser configured with regex groups for `^.*feat[(\w)]*:+.*$` and `^.*fix[(\w)]*:+.*$` to auto-categorize release notes.

## Strengths
- **Zero-Config Start** — Developer can fork/clone and immediately run a working TUI with all dependencies declared, no boilerplate to delete.
- **Complete CI/CD** — Build, lint, test, release, and dependency update pipelines are all wired up and ready; a production-grade template, not just a hello-world.
- **Canonical Bubble Tea** — Demonstrates the idiomatic `model` struct + `Init`/`Update`/`View` triad correctly, including `tea.Quit` return and error handling patterns.
- **Bubble Tea + Bubbles + Lip Gloss** — Covers all three layers of the Charmbracelet stack (framework, components, styling) in a single file.
- **Modern Go Practices** — Uses Go 1.24.2, race detector, atomic coverage, `trimpath`, CGO-free builds; passes golangci-lint with a meaningful rule set.
- **Well-Maintained Dependencies** — Dependabot keeps all three ecosystems (gomod, GitHub Actions, Docker) up-to-date weekly.

## Weaknesses
- **No Sub-Commands / Navigation** — The sample app is a single-screen spinner with only a quit interaction; does not demonstrate routing between multiple screens or concurrent models.
- **No Component Examples** — Does not showcase other Bubbles components (text input, viewport, table, filtered list, etc.), leaving the developer to explore those independently.
- **No Persistent State** — No file I/O, database, or flag parsing demonstrated; purely an in-memory, single-session model.
- **Minimal `View()`** — The view is a single `fmt.Sprintf` call; does not demonstrate layout composition (e.g., `lipgloss.Join`) or re-rendering optimization.
- **No Websocket/HTTP Demo** — Unlike some templates, it does not demonstrate integrating `tea.Cmd` with async I/O like HTTP requests or subprocess execution.
- **Opinionated Stack** — Hardcodes Charmbracelet's specific tooling (golangci-lint rules, GoReleaser, Dependabot workflows), making it less useful as a general Go template.

## SugarCraft Mapping

| charmbracelet/bubbletea-app-template | SugarCraft Lib | Rationale |
|---|---|---|
| `tea.Model` interface (`Init`/`Update`/`View`) | `SugarCraft\Core\Model` (candy-core) | Both define the TEA (The Elm Architecture) pattern with an immutable `Model`, message-based `Update`, and string `View` return. SugarCraft's `Model::init()`/`update()`/`view()` contract mirrors Bubble Tea's `tea.Cmd`-based TEA loop. |
| `spinner.Model` from bubbles | `SugarCraft\Bits\Spinner` (sugar-bits) | The `spinner.Model` is a stateful TUI component for animated waiting indicators. SugarCraft's `SugarCraft\Bits\Spinner` maps to the same concept in the PHP TUI realm. |
| `lipgloss.Style` styling | `SugarCraft\Shine\Style` (candy-shine) | Lip Gloss provides ANSI-compatible rich text styling (colors, bold, underline, etc.) as composable Style objects. SugarCraft's `candy-shine` provides the same PSR-4 `SugarCraft\Shine\Style` with fluent `Attribute()` setters and `Render()` output. |
| `key.NewBinding` + `key.Matches` | `SugarCraft\Bits\KeyBinding` (sugar-bits) | Declarative keyboard shortcut registration with key-set matching. SugarCraft has an equivalent `KeyBinding` abstraction for mapping key combos to actions in the TUI event loop. |
| `tea.NewProgram(...).Run()` entry point | `SugarCraft\Core\Program::run()` (candy-core) | Bubble Tea's `tea.NewProgram(initialModel()).Run()` bootstraps the TUI runtime. SugarCraft has an analogous `Program` class that initializes the terminal, runs the model loop, and tears down cleanly. |
| `bubbles` component library | `sugar-bits` (multiple leaf components) | The `bubbles` package is a collection of reusable TUI components (spinner, textinput, viewport, etc.). SugarCraft's `sugar-bits` is the equivalent leaf library containing `TextField`, `Spinner`, `ProgressBar`, `Table`, etc. |
| `tea.Quit` command | `SugarCraft\Core\Cmd::quit()` (candy-core) | Returning `tea.Quit` from `Update` signals the program to exit gracefully. SugarCraft has a `Cmd::quit()` factory that produces the equivalent exit signal. |
| `tea.KeyMsg` message type | `SugarCraft\Core\Msg\KeyMsg` (candy-core) | Key events are wrapped as typed `tea.Msg` values and dispatched to `Update`. SugarCraft models the same flow with a `KeyMsg` class that carries the key rune/stream information. |
| GoReleaser cross-platform builds | N/A (CI concern) | Release tooling; not a direct SugarCraft mapping, though SugarCraft uses its own CI pipeline for multi-lib rendering. |
| Dependabot weekly update schedule | `sugarcraft/sugarcraft` root `composer.json` | Dependency management cadence; SugarCraft uses Composer instead of Go modules but the same "keep deps updated" philosophy applies. |

**Many-to-Many Summary:**
- **bubbletea** ↔ **candy-core** — TEA architecture, Program runner, message loop
- **bubbles** ↔ **sugar-bits** — Reusable TUI component library
- **lipgloss** ↔ **candy-shine** — Declarative terminal styling
- **key binding** ↔ **sugar-bits** (KeyBinding sub-component)

## Analysis

The `bubbletea-app-template` is not a library itself but a **production-grade starter project** for the Bubble Tea TUI framework. It is minimal by design — the `main.go` is a single 75-line file implementing a spinning "Loading forever..." indicator that exits on `q`/`esc`/`ctrl+c` — yet it wires together every piece of infrastructure a real Charmbracelet-based CLI needs: dependency management with `gomod`, static analysis with `golangci-lint`, test coverage with race detection, release builds via `goreleaser`, and automated dependency updates via `dependabot`. The template demonstrates the canonical **TEA (The Elm Architecture)** pattern where `Update` is a pure function mapping `(Model, Msg) → (Model, Cmd)`, and the `View` function renders the model as an ANSI string on every tick. The use of a `spinner.Model` as a sub-component within the parent `model` struct shows the compositional pattern central to Charmbracelet TUIs: larger apps are built by composing smaller stateful components, each with their own `Init`/`Update`/`View`, into a root model that coordinates them via the shared message bus.

The most significant architectural lesson from this template is the **command-based concurrency model**: rather than spawning threads or async tasks directly, the TEA loop is driven entirely by `tea.Cmd` return values from `Update` and `Init`. A `tea.Cmd` is a closure that returns `() (tea.Model, tea.Cmd)`, allowing any function (HTTP calls, timers, subprocesses) to participate in the update loop by returning the next model state and the next command to execute. The spinner demonstrates this via `m.spinner.Tick` — a command that fires a `spinner.TickMsg` on a timer, which the spinner's own `Update` intercepts to advance its animation frame. This decoupled, functional concurrency model is what makes Bubble Tea testable (commands are easy to mock) and composable (components are unaware of each other's internals).

For SugarCraft, this template maps most directly onto **candy-core** (the TEA runner and model contracts) and **sugar-bits** (the Bubbles-equivalent component library). The key conceptual difference is that SugarCraft is a PHP port following PSR-4 / PSR-12 with immutable, fluent `with*()`-based models, whereas Bubble Tea uses Go's native struct mutation within the TEA loop. The Lip Gloss → `candy-shine` mapping is also strong, as both provide declarative, composable style objects that output ANSI escape sequences. The template serves as an excellent reference for what a SugarCraft "hello world" should look like: a single file demonstrating `Program::run()` with a `Model` implementing `init()`/`update()`/`view()`, a `KeyBinding` for quit handling, and a `Spinner` component styled via `candy-shine`.
