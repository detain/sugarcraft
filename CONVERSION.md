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
| 3 | CandyCore (runtime) | 🟢 | 95% | **ReactPHP/event-loop chosen.** `Model`, `Msg`, `Cmd`, `KeyType` (now incl. F1-F12), `Program`, `ProgramOptions`, `Renderer` (line-diff), `InputReader`. Built-in messages: `KeyMsg`, `MouseMsg`, `FocusMsg`, `BlurMsg`, `WindowSizeMsg`, `QuitMsg`, **`PasteMsg`**. Input parsing covers ASCII, ctrl, alt-prefix, arrows, Home/End/Delete/PgUp/PgDn, SGR mouse, focus in/out, **F1-F12 (SS3 + CSI~ encodings), bracketed paste (CSI 200~/201~ envelope, split-across-reads safe)**. Only Kitty keyboard protocol remains as a niche extension. |
| 4 | CandyZone | 🟢 | 100% | `Manager` (newGlobal/mark/scan/get/clear/all) + `Zone` (inBounds/pos/width/height). APC-based zero-width markers, ANSI/OSC pass-through, multi-byte + CJK width handling, multi-row spans. |
| 5 | SugarBits | 🟢 | 100% | All 14 components landed: `Key`, `Help`, `Spinner` (+ 7 styles), `Progress`, `Timer`, `Stopwatch`, `Cursor`, `TextInput`, `TextArea`, `Viewport`, `Paginator`, `ItemList` (filterable selection list with `Item` interface + `StringItem`), `Table` (interactive selectable, scrolling, header underline), `FilePicker` (cwd nav, hidden filter, allowed extensions, dir/file gates). |
| 6 | SugarCharts | 🟡 | 50% | MVP landed: `Canvas\Canvas` (cell grid with optional Sprinkles styling), `Sparkline\Sparkline` (8-glyph Unicode bar chart, sliding window over data), `BarChart\BarChart` (labelled vertical bars with auto width distribution + label truncation), `LineChart\LineChart` (single-series plot with point + connector strokes). Remaining: heatmap, scatter, OHLC, streamline, time series, picture (Sixel/Kitty). |
| 7 | SugarPrompt | 🟢 | 100% | All 7 field types landed: `Note` (skippable), `Input` (TextInput wrap + validator), `Confirm` (y/n with custom labels), `Select` (ItemList wrap, filter consumes Enter/Esc), `MultiSelect` (checkbox grid with min/max), `Text` (TextArea wrap, consumes Enter for newlines), `FilePicker` (wraps Bits FilePicker, consumes Enter/Backspace). `Form` container with Tab nav, Enter-submit, Esc/Ctrl-C abort, `Field::consumes(Msg)` for inner-key ownership, `init()` propagating first focused field's Cmd. Remaining (post-v1): `Group` for multi-page forms, theming. |
| 8 | CandyShell | 🟢 | 100% | Bin script + Symfony Console application + all 13 subcommands: `style`, `choose`, `input`, `confirm`, `join`, `log`, `table`, `filter`, `write`, `file`, `pager`, `spin`, **`format`** (Markdown → ANSI via CandyShine, `--theme ansi|plain`). |
| 9 | CandyShine (glamour) | 🟡 | 60% | New library `candycore/candy-shine` (`CandyCore\Shine`). `Theme` (15 per-element Style slots; `ansi()` / `plain()` factories) + `Renderer` walking `league/commonmark` AST. Supports headings 1-6, paragraphs, strong/em, inline code, fenced + indented code blocks, bulleted + ordered lists, links (with URL appended), block quotes (▎-prefixed), horizontal rules. Remaining: tables, task lists, custom themes JSON loader, syntax highlighting in fenced code. |

---

## How to contribute / extend this roadmap

- When a library moves to `🟡 in progress`, fill in its checklist in the
  per-library section.
- When it hits `🟢 v1 ready`, schedule the repo split (Phase: Split-out).
- New cross-cutting concerns get their own bullet under that section, not
  buried inside a single library.
- Any architectural decision that overrides one in this file goes in a new
  "Amendments" section with a date stamp — don't silently rewrite history.

---

## Future libraries (Phase 9+)

A second wave of Charmbracelet (and adjacent) projects to consider once
phases 0–8 are at v1. Names follow the same `Candy*` / `Sugar*` /
`Honey*` + technical-suffix convention from
[`PROJECT_NAMES.md`](./PROJECT_NAMES.md).

These are **planning entries only** — no code yet. Each row captures
the source URL, a one-line role, the proposed PHP package + namespace,
and the dependencies on phases 0–8.

| # | Source (Go) | Proposed PHP port | Subdir / Composer pkg | PSR-4 namespace | Role | Depends on |
|---|---|---|---|---|---|---|
|  9 | [charmbracelet/glamour](https://github.com/charmbracelet/glamour) | **CandyShine** | `candy-shine/` → `candycore/candy-shine` | `CandyCore\Shine` | Markdown → ANSI renderer (table-driven styles, syntax highlighting). Unblocks Phase 8's `format` subcommand. | 0, 1 |
| 10 | [charmbracelet/glow](https://github.com/charmbracelet/glow) | **SugarGlow** | `sugar-glow/` → `candycore/sugar-glow` | `CandyCore\Glow` | Markdown CLI viewer / pager. Composes Phase 7's `Viewport` with **CandyShine**. | 1, 3, 5, 9 |
| 11 | [charmbracelet/freeze](https://github.com/charmbracelet/freeze) | **CandyFreeze** | `candy-freeze/` → `candycore/candy-freeze` | `CandyCore\Freeze` | Code → SVG / PNG image generator (with optional terminal "screenshot"). | 1 + ext-gd or imagick |
| 12 | [charmbracelet/sequin](https://github.com/charmbracelet/sequin) | **SugarSpark** | `sugar-spark/` → `candycore/sugar-spark` | `CandyCore\Spark` | Inspect / pretty-print ANSI escape sequences. Useful debugging tool. | 0 |
| 13 | [charmbracelet/fang](https://github.com/charmbracelet/fang) | **CandyKit** | `candy-kit/` → `candycore/candy-kit` | `CandyCore\Kit` | Opinionated CLI starter (themes, help, completion). Composes Phase 1 + 5 + 8. | 1, 5, 8 |
| 14 | [charmbracelet/wish](https://github.com/charmbracelet/wish) (via this entry) | **CandyWish** | `candy-wish/` → `candycore/candy-wish` | `CandyCore\Wish` | SSH-server framework that pipes a `Program` onto an SSH session. | 3 + ext-ssh2 / pure-PHP SSH |
| 15 | [charmbracelet/wishlist](https://github.com/charmbracelet/wishlist) | **SugarWishlist** | `sugar-wishlist/` → `candycore/sugar-wishlist` | `CandyCore\Wishlist` | SSH directory / launcher. Composes **CandyWish** + Phase 5 selection list. | 5, 14 |
| 16 | [charmbracelet/promwish](https://github.com/charmbracelet/promwish) | **CandyMetrics** | `candy-metrics/` → `candycore/candy-metrics` | `CandyCore\Metrics` | Prometheus metrics middleware for **CandyWish** sessions. | 14 |
| 17 | [charmbracelet/crush](https://github.com/charmbracelet/crush) | **SugarCrush** | `sugar-crush/` → `candycore/sugar-crush` | `CandyCore\Crush` | AI coding-assistant TUI app. Demonstrates CandyCore + every phase together. | 0–8 |
| 18 | [charmbracelet/bubbletea-app-template](https://github.com/charmbracelet/bubbletea-app-template) | **CandyTemplate** | `candy-template/` → `candycore/candy-template` (Composer create-project) | `CandyCore\Template` | Skeleton repo for users bootstrapping a CandyCore app. | 0, 3 |
| 19 | [Broderick-Westrope/tetrigo](https://github.com/Broderick-Westrope/tetrigo) | **CandyTetris** | `candy-tetris/` → `candycore/candy-tetris` | `CandyCore\Tetris` | Tetris clone. Pure example app for the runtime. Optional. | 1, 3, 5 |
| 20 | [yorukot/superfile](https://github.com/yorukot/superfile) | **SuperCandy** | `super-candy/` → `candycore/super-candy` | `CandyCore\SuperFile` | Dual-pane file manager. Stress-test for `FilePicker`, `Viewport`, mouse zones. | 1, 3, 4, 5 |

### Sequencing notes

- **CandyShine** is the highest-leverage entry: it fills the gap that's
  blocking CandyShell's `format` subcommand and underpins glow + the
  table-styled output in fang.
- **CandyWish** is the only entry that needs a substantial new
  dependency (PHP SSH stack) and may be worth holding until either
  `ext-ssh2` or `phpseclib/phpseclib` is settled on. CandyMetrics and
  SugarWishlist queue behind it.
- The three "app" entries (**SugarCrush**, **CandyTemplate**,
  **CandyTetris**, **SuperCandy**) live in their own repos from day 1
  rather than in the monorepo, since they consume the libraries rather
  than extend them.

### Open naming questions

- `crush` / `glow` / `freeze` — should the PHP ports adopt the original
  one-word brand or stay strictly inside the Candy/Sugar/Honey vocab?
  Current proposal keeps the Candy/Sugar prefix; revisit before the
  first port lands.
- `glamour` → `CandyShine` reads like a styling library; alternatives
  worth considering: `CandyMarkup`, `SugarPress`, `CandyGloss` (already
  taken historically by CandyGloss → CandySprinkles, so probably skip).
