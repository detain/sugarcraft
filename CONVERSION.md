# CandyCore — Charmbracelet → PHP Conversion Roadmap

CandyCore is the umbrella project for porting the [Charmbracelet](https://charm.sh)
Go TUI ecosystem (plus `bubblezone` and `ntcharts`) to modern PHP. Each Go library
becomes its own PHP library — eventually its own repository — but during the
porting phase they live as subdirectories of this repo and are wired together
with Composer path repositories.

This document is the **canonical roadmap**: name mapping, architectural
decisions, dependency-aware port order, per-library scope, risks, and progress
tracking. Update it as ports advance.

---

## Naming convention

Names follow the pattern **[cute prefix] + [technical/function suffix]**
established in [`PROJECT_NAMES.md`](./PROJECT_NAMES.md): `Candy*` for foundation
and styling, `Sugar*` for components / data / forms, `Honey*` for math/physics.

## Library mapping

| # | Source (Go) | PHP port | Subdir / Composer pkg | PSR-4 namespace | Role |
|---|---|---|---|---|---|
| 1 | [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss) | **CandySprinkles** | `candy-sprinkles/` → `candycore/candy-sprinkles` | `CandyCore\Sprinkles` | Styling, layout, borders, tables/lists/trees |
| 2 | [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica) | **HoneyBounce** | `honey-bounce/` → `candycore/honey-bounce` | `CandyCore\Bounce` | Spring-physics animation |
| 3 | [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea) | **CandyCore** | `candy-core/` → `candycore/candy-core` | `CandyCore\Core` | Elm-architecture TUI runtime |
| 4 | [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) | **CandyZone** | `candy-zone/` → `candycore/candy-zone` | `CandyCore\Zone` | Mouse-zone tracker |
| 5 | [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) | **SugarBits** | `sugar-bits/` → `candycore/sugar-bits` | `CandyCore\Bits` | Pre-built components |
| 6 | [NimbleMarkets/ntcharts](https://github.com/NimbleMarkets/ntcharts) | **SugarCharts** | `sugar-charts/` → `candycore/sugar-charts` | `CandyCore\Charts` | Line / bar / sparkline / heatmap |
| 7 | [charmbracelet/huh](https://github.com/charmbracelet/huh) | **SugarPrompt** | `sugar-prompt/` → `candycore/sugar-prompt` | `CandyCore\Prompt` | Form library |
| 8 | [charmbracelet/gum](https://github.com/charmbracelet/gum) | **CandyShell** | `candy-shell/` → `candycore/candy-shell` | `CandyCore\Shell` | CLI tool (composer bin) |

> Note: "CandyCore" is both the umbrella project / repo name **and** the PHP
> port of `bubbletea` (the foundation library), in keeping with PROJECT_NAMES.md.

---

## Architectural decisions

| Topic | Decision |
|---|---|
| **Runtime model** | ReactPHP / Amp async event loop. Mirrors goroutine semantics for input reading, signal handling, command execution, and the render tick. |
| **Minimum PHP** | **8.1+** (Fibers, readonly properties, enums, intersection types). |
| **Concurrency primitive** | Promises/Futures from the chosen async lib + Fibers for cooperative blocking. |
| **Composer layout** | Monorepo with one `composer.json` per subdir; root `composer.json` uses `repositories: [{type: path, url: ...}]` for local development. |
| **Repo split** | When a library hits **v1.0**, its subdir is extracted into its own repo with full git history (`git filter-repo`) and published on Packagist. Until then, all live here. |
| **Strict types** | `declare(strict_types=1)` everywhere. |
| **Style** | PSR-12 + readonly DTOs; immutable `Style`/`Model` objects with `with*()` returning a new instance (matches lipgloss/bubbletea idioms). |
| **Testing** | PHPUnit 10. Snapshot ANSI rendering tests for CandySprinkles; scripted-input event tests for CandyCore. |
| **TTY layer** | PHP FFI to libc termios where available; `stty` shell-out fallback for portability. Windows support via VT processing on Win10+ only. |
| **Unicode width** | `symfony/string` grapheme handling + a small width table port from `clipperhouse/displaywidth`. |
| **Color** | Port of `colorprofile` for capability detection; downsample TrueColor → 256 → 16 → mono as needed. |

---

## Dependency-aware port order

Bottom-up — never block on a missing dep. The user asked to start with
`bubbletea`, but it depends on rendering primitives (color profile, width
calc, ANSI builder); we therefore stand up Phase 0 + CandySprinkles in parallel
with CandyCore so neither blocks the other.

```
Phase 0  Foundation utilities  (lives under candy-core/src/Util)
         · ANSI builder + parser           (replaces charmbracelet/x/ansi)
         · Color profile detection         (replaces colorprofile)
         · Unicode width / grapheme        (symfony/string + width table)
         · Termios / raw-mode wrapper      (PHP FFI or `stty` fallback)

Phase 1  CandySprinkles   (lipgloss)           — pure rendering, deps Phase 0
Phase 2  HoneyBounce  (harmonica)          — pure math, no deps
Phase 3  CandyCore    (bubbletea)          — Phase 0 + ReactPHP/Amp
Phase 4  CandyZone    (bubblezone)         — Phase 1 + Phase 3
Phase 5  SugarBits    (bubbles)            — Phases 1 + 2 + 3
Phase 6  SugarCharts  (ntcharts)           — Phases 1 + 3 + 4
Phase 7  SugarPrompt  (huh)                — Phases 1 + 3 + 5
Phase 8  CandyShell   (gum)                — bin script consuming all
```

---

## Per-library plans

Each library tracks the same checklist:

```
[ ] Foundation deps ready
[ ] Skeleton (composer.json, namespace, CI)
[ ] Core API (parity with Go public surface)
[ ] Examples
[ ] Tests
[ ] Docs
[ ] Split-out (own repo, Packagist publish)
```

### 1. CandySprinkles  ←  lipgloss

- **Source:** https://github.com/charmbracelet/lipgloss
- **Subdir:** `candy-sprinkles/`  ·  **Package:** `candycore/candy-sprinkles`  ·  **NS:** `CandyCore\Sprinkles`
- **Scope:** Declarative styled text, padding/margins/borders, alignment,
  color, gradients, joins. Sub-namespaces `Sprinkles\Listing`, `Sprinkles\Table`, `Sprinkles\Tree`.
- **Public surface to cover:**
  `Style` (immutable, ~40 `with*()` methods), `NewStyle()`, `Render(string)`,
  `Inherit(Style)`, `Copy()`; `Table`, `List`, `Tree` builders.
- **PHP risks:** Unicode width (graphemes, East-Asian wide, emoji ZWJ); color
  downsampling correctness; preserving Go's fluent immutability in PHP.
- **Status:** `[ ] [ ] [ ] [ ] [ ] [ ] [ ]`

### 2. HoneyBounce  ←  harmonica

- **Source:** https://github.com/charmbracelet/harmonica
- **Subdir:** `honey-bounce/`  ·  **Package:** `candycore/honey-bounce`  ·  **NS:** `CandyCore\Bounce`
- **Scope:** Damped simple-harmonic-oscillator spring physics for animation.
- **Public surface:** `Spring(dt, frequency, dampingRatio)`, `update($pos, $vel, $target): array{0:float,1:float}`, `fps(int): float`.
- **PHP risks:** Floating-point parity with the Go reference (test against
  fixture vectors). Otherwise trivial — language-agnostic math.
- **Status:** `[ ] [ ] [ ] [ ] [ ] [ ] [ ]`

### 3. CandyCore  ←  bubbletea

- **Source:** https://github.com/charmbracelet/bubbletea
- **Subdir:** `candy-core/`  ·  **Package:** `candycore/candy-core`  ·  **NS:** `CandyCore\Core`
- **Scope:** Elm-architecture runtime. Reads input, dispatches `Msg`s to the
  user `Model`'s `update()`, runs returned `Cmd`s, renders `view()` to the
  terminal at 60 FPS.
- **Public surface:** `Model` interface (`init(): Cmd|null`, `update(Msg): array{0:Model,1:Cmd|null}`, `view(): string`), `Program` (`run()`, `send()`, `quit()`, `kill()`, `releaseTerminal()`, `restoreTerminal()`, `println()`), `Cmd` (callable returning `Msg`), `Msg` markers (`KeyMsg`, `MouseMsg`, `WindowSizeMsg`, `QuitMsg`, …).
- **PHP risks (HIGH):**
  - Goroutines + channels → ReactPHP/Amp event loop + Fibers.
  - Signal handling (`SIGINT`, `SIGWINCH`) via `pcntl` extension.
  - Non-blocking stdin via `stream_set_blocking(STDIN, false)` + the loop's
    readable-stream watcher.
  - Frame-rate-limited renderer — periodic timer, double-buffer diff.
  - Cancel-reader pattern for clean teardown.
- **Status:** `[ ] [ ] [ ] [ ] [ ] [ ] [ ]`

### 4. CandyZone  ←  bubblezone

- **Source:** https://github.com/lrstanley/bubblezone
- **Subdir:** `candy-zone/`  ·  **Package:** `candycore/candy-zone`  ·  **NS:** `CandyCore\Zone`
- **Scope:** Wraps rendered chunks with zero-width ANSI markers so mouse
  events can be mapped back to logical UI elements.
- **Public surface:** `Manager::newGlobal()`, `mark(string $id, string $content)`,
  `scan(string $output)`, `get(string $id)->inBounds(MouseMsg): bool`, `pos(): array`.
- **PHP risks:** Marker insertion must not break Lipgloss width math (CandySprinkles
  needs to know about marker pass-through); multibyte-safe string scanning.
- **Status:** `[ ] [ ] [ ] [ ] [ ] [ ] [ ]`

### 5. SugarBits  ←  bubbles

- **Source:** https://github.com/charmbracelet/bubbles
- **Subdir:** `sugar-bits/`  ·  **Package:** `candycore/sugar-bits`  ·  **NS:** `CandyCore\Bits`
- **Scope:** 14 ready-made components, each its own sub-namespace:
  `cursor`, `filepicker`, `help`, `key`, `list`, `paginator`, `progress`,
  `spinner`, `stopwatch`, `table`, `textarea`, `textinput`, `timer`, `viewport`.
- **Public surface (per component):** `new(): Model`, `focus()`, `blur()`,
  `update(Msg): [Model, Cmd|null]`, `view(): string`, plus component-specific
  setters (`setValue`, `setItems`, `setRows`, …).
- **PHP risks:** Largest line-count of any port; complex state machines for
  `list` (filtering/pagination/delegates) and `table` (selection/scroll);
  fuzzy-matching dep (replace `sahilm/fuzzy` with a small PHP port).
- **Sub-deps to vendor or replace:** clipboard (`atotto/clipboard` → PHP via
  OSC52 escape), heredoc text (native PHP nowdoc).
- **Status:** `[ ] [ ] [ ] [ ] [ ] [ ] [ ]`

### 6. SugarCharts  ←  ntcharts

- **Source:** https://github.com/NimbleMarkets/ntcharts
- **Subdir:** `sugar-charts/`  ·  **Package:** `candycore/sugar-charts`  ·  **NS:** `CandyCore\Charts`
- **Scope:** Canvas + 9 chart types: `barchart`, `linechart` (regular, scatter,
  streamline, time-series, waveline), `heatmap`, `sparkline`, `picture`.
- **Public surface:** `Canvas` (`new()`, `setCell($x, $y, $rune, $style)`,
  `view()`); per-chart `new()` + `add(dataset)` + `update()` + `view()`.
- **PHP risks:** Largest scope; canvas vs Cartesian coordinate translation;
  optional Perlin-noise demo dep (vendor or skip); image rendering (`picture`)
  needs Kitty/Sixel detection — defer to v2.
- **MVP slice:** Canvas + barchart + sparkline + linechart-basic.
- **Status:** `[ ] [ ] [ ] [ ] [ ] [ ] [ ]`

### 7. SugarPrompt  ←  huh

- **Source:** https://github.com/charmbracelet/huh
- **Subdir:** `sugar-prompt/`  ·  **Package:** `candycore/sugar-prompt`  ·  **NS:** `CandyCore\Prompt`
- **Scope:** High-level form builder over CandyCore + SugarBits. Groups,
  pages, conditional visibility, validation, themes.
- **Public surface:** `Form::new()`, `Group::new()`, field constructors
  (`Input`, `Text`, `Select`, `MultiSelect`, `Confirm`, `FilePicker`),
  fluent `->title()` / `->description()` / `->validate()` / `->run()`.
- **PHP risks:** Theme (Catppuccin) port; conditional show/hide closures;
  spinner integration (depends on SugarBits being ready).
- **Status:** `[ ] [ ] [ ] [ ] [ ] [ ] [ ]`

### 8. CandyShell  ←  gum

- **Source:** https://github.com/charmbracelet/gum
- **Subdir:** `candy-shell/`  ·  **Package:** `candycore/candy-shell`  ·  **NS:** `CandyCore\Shell`
- **Scope:** Composer-installable bin (`vendor/bin/candyshell`) wrapping the
  TUI primitives for shell scripts. 13 subcommands: `choose`, `confirm`,
  `file`, `filter`, `format`, `input`, `join`, `log`, `pager`, `spin`,
  `style`, `table`, `write`.
- **CLI parser:** `symfony/console` (replaces `alecthomas/kong`).
- **MVP slice:** `choose`, `input`, `confirm`, `spin`, `filter`, `style`.
- **Defer to v2:** `format` (markdown via glamour-equivalent), `pager`, `table`.
- **PHP risks:** Markdown rendering (no glamour-equivalent yet — consider
  `league/commonmark` + ANSI extension or shell out to `glow`).
- **Status:** `[ ] [ ] [ ] [ ] [ ] [ ] [ ]`

---

## Cross-cutting concerns

- **TTY handling.** Single shared abstraction in `CandyCore\Core\Tty` so every
  library that needs raw mode / size queries / cursor control goes through it.
  FFI-based termios with an `stty` shell-out fallback. Windows: require
  Win10+ VT processing.
- **ANSI compatibility.** Centralize escape-sequence emission in
  `CandyCore\Core\Ansi`. CandySprinkles depends on it; never hand-roll escapes
  inside individual components.
- **Input parsing.** A single ANSI/CSI parser (with bracketed-paste, mouse
  SGR, focus-in/out, Kitty keyboard protocol where available) lives in
  CandyCore and emits typed `Msg` objects.
- **Testing.**
  - CandySprinkles: PHPUnit snapshot tests of `Render()` output (raw bytes).
  - CandyCore: scripted input feeder + assertion on emitted `view()` frames.
  - SugarBits/SugarCharts/SugarPrompt: integration tests built on the above.
- **CI.** GitHub Actions matrix (PHP 8.1 / 8.2 / 8.3 / 8.4) running `phpstan`
  (level 8), `phpunit`, and `php-cs-fixer --dry-run`.
- **Docs.** Each library gets a `README.md` (overview + minimal example) and
  a generated API reference (phpDocumentor).

---

## Progress tracker

Update this table as work proceeds. Status legend:
🔴 not started · 🟡 in progress · 🟢 v1 ready · 🚀 split into own repo.

| Phase | Library | Status | % | Notes |
|------:|---|:---:|---:|---|
| 0 | Foundation utilities (ansi / color / width / tty) | 🟢 | 100% | `Ansi`, `Color`, `ColorProfile`, `Width`, `Tty` under `candy-core/src/Util`. Stable. |
| 1 | CandySprinkles | 🟢 | 100% | `Style` (attrs, fg/bg, padding, margin, width/height, horizontal + **vertical** align, **`inherit()` with propsSet tracking**, profile-aware downsampling) + `Border` (with middle runes for tables) + `Table` + `ItemList` + `Tree`. Public surface complete for v1. |
| 2 | HoneyBounce | 🟢 | 100% | `Spring` (under-/critically-/over-damped) + `Spring::fps()`. Pure math, ready for downstream use. |
| 3 | CandyCore (runtime) | 🟡 | 65% | **ReactPHP/event-loop chosen.** `Model`, `Msg`, `Cmd`, `KeyType`, `Program`, `ProgramOptions`, `Renderer` (line-diff), `InputReader`. Built-in messages: `KeyMsg`, `MouseMsg`, `FocusMsg`, `BlurMsg`, `WindowSizeMsg`, `QuitMsg`. Input parsing covers ASCII, ctrl, alt-prefix, arrows, Home/End/Delete/PgUp/PgDn, SGR mouse, focus in/out. TBD: bracketed paste, OSC, function keys F1‒F12, Kitty keyboard protocol. |
| 4 | CandyZone | 🟢 | 100% | `Manager` (newGlobal/mark/scan/get/clear/all) + `Zone` (inBounds/pos/width/height). APC-based zero-width markers, ANSI/OSC pass-through, multi-byte + CJK width handling, multi-row spans. |
| 5 | SugarBits | 🟡 | 25% | First slice landed: `Key\Binding`/`Help`/`KeyMap`, `Help\Help` (short + full views), `Spinner\Spinner` + 7 styles, `Progress\Progress`. Drives spinner animation through new `Cmd::tick` + `TickRequest` runtime hook. Remaining: `Timer`, `Stopwatch`, `Cursor`, `TextInput`, `TextArea`, `Viewport`, `Paginator`, `List`, `Table`, `FilePicker`. |
| 6 | SugarCharts | 🔴 | 0% | MVP: canvas + bar + sparkline + line |
| 7 | SugarPrompt | 🔴 | 0% | |
| 8 | CandyShell | 🔴 | 0% | MVP: 6 of 13 subcommands |

---

## How to contribute / extend this roadmap

- When a library moves to `🟡 in progress`, fill in its checklist in the
  per-library section.
- When it hits `🟢 v1 ready`, schedule the repo split (Phase: Split-out).
- New cross-cutting concerns get their own bullet under that section, not
  buried inside a single library.
- Any architectural decision that overrides one in this file goes in a new
  "Amendments" section with a date stamp — don't silently rewrite history.
