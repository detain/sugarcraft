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
| 6 | SugarCharts | 🟡 | 75% | Six chart types: `Canvas\Canvas` (cell grid with optional Sprinkles styling), `Sparkline\Sparkline` (8-glyph Unicode bar chart, sliding window), `BarChart\BarChart` (labelled vertical bars), `LineChart\LineChart` (single-series + connectors), **`Heatmap\Heatmap`** (2D grid, linear RGB interpolation between cold and hot colors, configurable rune), **`Scatter\Scatter`** (auto-ranged X/Y plot, no connectors). Remaining: OHLC, streamline, time series, picture (Sixel/Kitty). |
| 7 | SugarPrompt | 🟢 | 100% | All 7 field types landed: `Note` (skippable), `Input` (TextInput wrap + validator), `Confirm` (y/n with custom labels), `Select` (ItemList wrap, filter consumes Enter/Esc), `MultiSelect` (checkbox grid with min/max), `Text` (TextArea wrap, consumes Enter for newlines), `FilePicker` (wraps Bits FilePicker, consumes Enter/Backspace). `Form` container with Tab nav, Enter-submit, Esc/Ctrl-C abort, `Field::consumes(Msg)` for inner-key ownership, `init()` propagating first focused field's Cmd. Remaining (post-v1): `Group` for multi-page forms, theming. |
| 8 | CandyShell | 🟢 | 100% | Bin script + Symfony Console application + all 13 subcommands: `style`, `choose`, `input`, `confirm`, `join`, `log`, `table`, `filter`, `write`, `file`, `pager`, `spin`, **`format`** (Markdown → ANSI via CandyShine, `--theme ansi|plain`). |
| 9 | CandyShine (glamour) | 🟡 | 80% | New library `candycore/candy-shine` (`CandyCore\Shine`). `Theme` (15 per-element Style slots; `ansi()` / `plain()` factories) + `Renderer` walking `league/commonmark` AST. Supports headings 1-6, paragraphs, strong/em, inline code, fenced + indented code blocks, bulleted + ordered lists, links (with URL appended), block quotes (▎-prefixed), horizontal rules, **GFM tables (rendered via Sprinkles\Table with rounded border)**, **task list checkboxes (☑ / ☐)**. Remaining: custom themes JSON loader, syntax highlighting in fenced code. |

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
| 10 | [charmbracelet/glow](https://github.com/charmbracelet/glow) | **SugarGlow** ✅ | `sugar-glow/` → `candycore/sugar-glow` | `CandyCore\Glow` | Markdown CLI viewer / pager. Library + `bin/sugarglow` CLI shipped — single Symfony Console default command renders Markdown via CandyShine and either prints to stdout (default) or opens a fullscreen `Viewport` pager (`-p` / `--pager`) sized to the terminal. `--theme ansi\|plain` selects the CandyShine theme. Reads from a file argument or stdin. | 1, 3, 5, 9 |
| 11 | [charmbracelet/freeze](https://github.com/charmbracelet/freeze) | **CandyFreeze** | `candy-freeze/` → `candycore/candy-freeze` | `CandyCore\Freeze` | Code → SVG / PNG image generator (with optional terminal "screenshot"). | 1 + ext-gd or imagick |
| 12 | [charmbracelet/sequin](https://github.com/charmbracelet/sequin) | **SugarSpark** ✅ | `sugar-spark/` → `candycore/sugar-spark` | `CandyCore\Spark` | Inspect / pretty-print ANSI escape sequences. Library + `bin/sugarspark` CLI shipped — labels SGR (foreground / background / 256-color / truecolor / attributes), CSI cursor moves, erase, DEC private mode toggles (mouse, focus, alt screen, bracketed paste), CSI ~ keys (Home/End/Delete/PgUp/PgDn/F1-F12), SS3, OSC (window title, hyperlink, OSC 52), and 2-byte ESC. Unrecognised sequences fall back to a generic descriptor so nothing is silently swallowed. | 0 |
| 13 | [charmbracelet/fang](https://github.com/charmbracelet/fang) | **CandyKit** ✅ | `candy-kit/` → `candycore/candy-kit` | `CandyCore\Kit` | Opinionated CLI presentation helpers shipped: `Theme` (success/error/warn/info/prompt/accent/muted Style palette, ansi + plain factories), `StatusLine` (✓/✗/⚠/ℹ/? glyph + message), `Banner::title($title, $subtitle)` (rounded-bordered title block with optional subtitle, custom Border supported). Library-only — no Symfony Console requirement so any Composer project can drop it in. | 1 |
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

---

## Phase 10 — Polish & public launch (SugarBuzz)

Runs **after** every functional phase (0–9 + Phase 9+ ports) is at v1.
This phase is presentation and distribution: turn the library set
into a real product.

### Brand + home

- **Org name:** **SugarBuzz** — every library moves under
  `github.com/sugarbuzz/<lib>` (current `detain/CandyCore` monorepo is
  the dev incubator; production repos split out at v1.0 per the
  existing "Repo split" architecture decision).
- **Website:** `sugarbuzz.dev` (or similar — TBD). Visual inspiration:
  [charm.land](https://charm.land/#enterprise) — big, bubbly, colourful,
  rounded corners, cheerful gradients. Powerful but playful.
- **Tone:** every library README opens with a one-line tagline + a
  prominent VHS-recorded GIF demo. Emojis used freely (🍬 🌟 ✨ 🎨 🍭
  🎀 🧁 🍰 🌈 🎈) but never to the point of clutter.

### Per-library polish checklist

Each shipped library gets the same treatment:

- [ ] **README.md** rewritten to mirror the original Go counterpart's
      structure, with PHP-specific install + usage:
      `composer require sugarbuzz/<package>` ➜ minimal example ➜
      feature list ➜ links to advanced docs.
- [ ] **VHS demo** at the top of the README (animated GIF). Recorded
      with [charmbracelet/vhs](https://github.com/charmbracelet/vhs)
      via [charmbracelet/vhs-action](https://github.com/charmbracelet/vhs-action)
      so it regenerates automatically on PR. One demo per major
      feature (e.g. SugarBits ships 14 mini-demos, one per component;
      CandyShell ships 13, one per subcommand).
- [ ] **`composer.json`** filled out with:
      - `description` — playful, descriptive sentence.
      - `keywords` — generous tag list (`tui`, `cli`, `terminal`,
        `bubble-tea`, `php8`, plus library-specific tags).
      - `homepage` — sugarbuzz.dev/<lib>.
      - `support` (issues, source, docs URLs).
      - `funding` block (if applicable).
      - `authors` — Joe Huss + contributors.
- [ ] **`examples/`** directory with runnable scripts that map 1:1 to
      the original repo's examples folder.
- [ ] **`docs/`** directory: usage guide, API reference (phpDocumentor
      generated), upgrade notes.
- [ ] **GitHub repo polish:**
      - Description matches the composer.json description.
      - Topics tagged generously.
      - Social preview image (CandyCore-themed).
      - Issue + PR templates.
      - `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`.
      - Releases tagged with semver from v1.0.0 onward.
      - Discussions enabled.
- [ ] **Packagist publish** (after split-out from monorepo): all libs
      under the `sugarbuzz/` vendor namespace once available, otherwise
      `candycore/` until then.

### Website — sugarbuzz.dev

- [ ] **Hero**: big animated banner showing CandyShell + SugarBits +
      SugarPrompt running side-by-side via VHS recordings.
- [ ] **Library grid**: one tile per library (CandyCore, CandySprinkles,
      SugarBits, etc.) with the package's signature glyph, one-line
      description, and "view on GitHub" / "view docs" / "view demo"
      links. Hover effect: rounded card lifts and glows.
- [ ] **Quickstart panel**: copy-pasteable `composer require` lines
      that drop into a working `Counter` model in 10 lines.
- [ ] **Showcase**: example apps using the stack — SugarCrush demo,
      CandyTetris playable embed, SuperCandy file manager screenshot.
- [ ] **Why CandyCore?**: feature/comparison page (vs. plain
      Symfony Console, vs. raw `readline`, vs. Go bubbletea — for
      the curious).
- [ ] **Docs portal**: a single search-friendly site stitching every
      library's `docs/` together. CandyShine itself can render the
      Markdown sources, dogfooding the stack.
- [ ] **Style**: Tailwind-ish, rounded everything, candy palette
      (the same `#ff5f87` accent used throughout the libs, plus
      pastels). CSS-only animations on hero glyphs (subtle bobbing,
      sparkles).

### VHS demo workflow

- [ ] Each library repo gets a `.vhs/` directory with one `.tape`
      script per demo (pure-text recordings).
- [ ] GitHub Actions workflow uses
      [`charmbracelet/vhs-action`](https://github.com/charmbracelet/vhs-action)
      to render `.tape` → `.gif`/`.webm` on every push that touches
      the demo or its underlying source.
- [ ] Demos are published into the repo's `docs/demos/` folder so
      relative links in the README stay stable.
- [ ] The website pulls the same GIFs by URL so a single regeneration
      updates docs + site.

### Sequencing inside Phase 10

1. Pick the visual palette + a SugarBuzz logo (one-shot design pass).
2. Build the website skeleton (static, deployable to any CDN — GitHub
   Pages, Netlify, Vercel; whichever needs least ops).
3. Wire up the VHS workflow on **CandyCore** first (most-watched repo;
   sets the precedent for the rest).
4. Roll the polish checklist library-by-library, easiest first
   (CandySprinkles, HoneyBounce — both pure-PHP, no I/O).
5. Move the production repos under the SugarBuzz org once at least
   half the libs are polished. Detain remains the dev / incubator
   namespace.
6. Announce: blog post, charm community Discord/Slack, /r/PHP,
   Reddit /r/programming, HN.

---

## Phase 11 — v2 parity sweep (Bubble Tea / Lipgloss / Bubbles)

Charmbracelet shipped a coordinated v2 of Bubble Tea + Lipgloss + Bubbles
in February 2026. The headline pitch (per the [v2 blog post](https://charm.land/blog/v2.md)):

> The heart of v2 is the **Cursed Renderer**. Modeled on the ncurses
> rendering algorithm, it improves rendering speed and efficiency by
> orders of magnitude — meaningful locally and **monetarily
> quantifiable for applications running over SSH**.
>
> v2 also reaches deeper into what emerging terminals can actually do:
> richer keyboard support, **inline images**, **synchronized
> rendering**, **clipboard transfer over SSH**, and many more small,
> meticulous details. There's a reason Bubble Tea supports
> **inline mode as a first-class use case**.
>
> The v2 branch has been powering [Crush][crush] (the AI coding agent
> we're tracking as Phase 9+ entry #17 → SugarCrush) in production
> from the start.

Source-of-truth references:

- [Bubble Tea v2 — What's New](https://github.com/charmbracelet/bubbletea/discussions/1374)
  · [Upgrade Guide](https://github.com/charmbracelet/bubbletea/blob/main/UPGRADE_GUIDE_V2.md)
- [Lip Gloss v2 — What's New](https://github.com/charmbracelet/lipgloss/discussions/506)
  · [Upgrade Guide](https://github.com/charmbracelet/lipgloss/blob/main/UPGRADE_GUIDE_V2.md)
- [Bubbles v2 — Upgrade Guide](https://github.com/charmbracelet/bubbles/blob/main/UPGRADE_GUIDE_V2.md)
- [Blog post: v2](https://charm.land/blog/v2.md)

[crush]: https://github.com/charmbracelet/crush

Most of the v2 surface was about *splitting* and *clarifying* APIs;
this phase pulls the same moves into our libs so we don't drift.

Status legend per feature:
- ✅ **already have** (or close enough)
- 🟡 **partial / needs upgrade**
- 🔴 **missing — port**
- ⚪ **N/A or skip** (with reason)

### CandyCore (← Bubble Tea v2)

#### Runtime + IO

| v2 feature | Status | Notes |
|---|---|---|
| Synchronized updates (DEC mode 2026) — wraps each frame in `CSI ? 2026 h … l` | ✅ | `Renderer` wraps both the first frame and every diff payload in `Ansi::syncBegin()` / `syncEnd()`. |
| Unicode mode (DEC mode 2027) — proper wide-char width queries | ✅ | `ProgramOptions::$unicodeMode` defaults to true; `Program::setupTerminal()` emits `CSI ?2027h`, teardown emits `CSI ?2027l`. |
| "Cursed" ncurses-style renderer — diff scoped to changed cells, not lines | 🟡 | We have a line-diff renderer (`candy-core/src/Renderer.php`); cell-diff is a v1.1 enhancement. Would meaningfully cut SSH bandwidth for CandyWish. |
| `WithInput` / `WithOutput` / `WithEnvironment` / `WithWindowSize` / `WithColorProfile` Program options | 🟡 | We have `input` / `output` / `loop` already. Add `environment` (test injection), `windowSize` (force), `colorProfile` (override auto-detect). |
| `OpenTTY()` — open `/dev/tty` directly when stdin is piped | 🔴 | Useful for `candyshell choose < some.txt`-style usage where stdin is data, not the TTY. |
| `tea.Println` / `tea.Printf` — write text *above* the program's region | ✅ | `Cmd::println(string)` returns a `PrintMsg` sentinel; `Program::dispatch()` writes the line + a newline and resets the renderer so the next frame repaints cleanly. |
| `tea.Raw(escape)` — send raw escape sequences | ✅ | `Cmd::raw(string)` returns a `RawMsg`; the Program writes the bytes verbatim without disturbing renderer diff state. |
| **Inline mode** as a first-class use case (no alt screen, no full takeover) | 🟡 | Our `useAltScreen=false` already runs inline-ish, but v2 formalises it: cursor stays in the user's prompt, output flows above the program region. Pair with `tea.Println` and a smaller `Renderer` that only owns the rows below the prompt. **Important for `candyshell input` / `confirm` / `spin` ergonomics.** |
| **Advanced compositing** — layered rendering / pop-overs / floating panes | 🔴 | Stacks multiple "layers" so a modal can render above the main view without the model rebuilding the world. Schedule with the View struct rework — they're paired in v2. |
| **Inline image protocols** (Sixel / Kitty / iTerm2) | 🔴 | Detect the active protocol via terminal-version query; encode an image (PNG / GIF / SVG via librsvg fallback) into the appropriate escape stream. Lives next to `Charts\Picture` once that ships in Phase 6. |

#### View shape

| v2 feature | Status | Notes |
|---|---|---|
| `View()` returns `tea.View` struct (not `string`) | 🔴 | **Architectural shift.** Move per-frame terminal config (alt screen / mouse mode / focus / cursor / window title / progress bar / colour profile) out of `ProgressOptions` and into a `View` value-object the model returns each tick. Bigger BC break — schedule for a 2.0 of CandyCore. |
| `Cursor` struct (position, shape, blink, colour, nullable to hide) | 🔴 | Pairs with the new View shape. Today we just expose `hideCursor` boolean. |
| `WindowTitle` field — set via OSC 0/2 each frame | 🔴 | Terminals that support it; OSC 0/2 emission already in `Ansi`. |
| Declarative `BackgroundColor` / `ForegroundColor` per frame | 🔴 | Use OSC 10/11. |
| `MouseMode` declared on the View instead of one-shot setup flag | 🟡 | We toggle in setup/teardown. Move into per-View when we adopt the View struct. |
| `ProgressBar` field — terminal native progress (OSC 9;4) | 🔴 | iTerm2 / WezTerm taskbar progress. Light-touch addition. |

#### Keys

| v2 feature | Status | Notes |
|---|---|---|
| Split `KeyMsg` into `KeyPressMsg` / `KeyReleaseMsg` (both still match `KeyMsg` interface) | 🟡 | Add `KeyPressMsg` + `KeyReleaseMsg` extending the existing `KeyMsg`. Default behaviour unchanged unless the runtime is in Kitty mode. |
| `Key::Code` (logical key) + `Key::Text` (typed text) — replaces `rune` | ✅ | `KeyMsg::text()` aliases `$rune` (empty for named keys); `KeyMsg::code()` aliases `$type`. `BaseCode` is unnecessary in PHP since named keys already use the enum and printable text uses Char. |
| `Key::Mod` unified bitfield instead of separate `alt` / `ctrl` booleans | ✅ | `KeyMsg::modifiers()` returns a `Modifiers` value object with `shift`/`alt`/`ctrl` plus `toBitfield()` (`SHIFT`/`ALT`/`CTRL` bit constants). The original `alt`/`ctrl` booleans remain for back-compat; `shift` is now also a constructor field. `Modifiers::fromXtermMod(int)` decodes the standard `1 + (1·shift + 2·alt + 4·ctrl)` byte. |
| `IsRepeat` flag | 🔴 | Auto-repeat detection — needs the Kitty keyboard protocol to surface reliably. |
| `Key::Keystroke()` — string like `"ctrl+shift+a"` | ✅ | We already ship `KeyMsg::string()`. |
| Space returns `"space"` (not `" "`) from `Keystroke()` | ✅ | Already does. |
| Kitty progressive keyboard protocol — disambiguates `ctrl+m` vs Enter, etc. | 🔴 | Niche but increasingly common. New `KeyboardEnhancementsMsg` + `View.KeyboardEnhancements` field gates it. Gates `IsRepeat` and `KeyReleaseMsg`. |

#### Mouse + paste

| v2 feature | Status | Notes |
|---|---|---|
| Split `MouseMsg` into `MouseClickMsg` / `MouseReleaseMsg` / `MouseWheelMsg` / `MouseMotionMsg` | ✅ | `MouseMsg` is no longer `final`; four empty marker subclasses live under `Msg/`. `InputReader::decodeSgrMouse()` instantiates the right one from the SGR byte. The `action` enum stays for callers that prefer enum-based dispatch. |
| `PasteMsg::content` (we already match) | ✅ | Done. |
| `PasteStartMsg` / `PasteEndMsg` for *streaming* paste rendering | 🔴 | Useful for very large pastes where you want a progress indicator. |

#### Terminal queries

| v2 feature | Status | Notes |
|---|---|---|
| `RequestCursorPosition` + `CursorPositionMsg` | ✅ | `Cmd::requestCursorPosition()` emits `CSI 6n` via a `RawMsg`; `InputReader` parses the `CSI <row>;<col>R` reply into `CursorPositionMsg`. |
| `RequestTerminalVersion` + `TerminalVersionMsg` | ✅ | `Cmd::requestTerminalVersion()` emits `CSI > 0 q` (XTVERSION). The input reader parses the DCS reply (`ESC P > | <text> ESC \`) into `TerminalVersionMsg`; DCS detection is narrowly gated on the `>` marker so `Alt-P` keypresses are unaffected. |
| `RequestCapability(name)` + `ModeReportMsg` | ✅ | `Cmd::requestMode($mode, private: true)` emits DECRQM bytes (`CSI [?] <mode> $ p`); the input reader parses the DECRPM reply (`CSI [?] <mode> ; <state> $ y`) into `ModeReportMsg` carrying the `ModeState` enum (`Set` / `Reset` / `PermanentlySet` / `PermanentlyReset` / `NotRecognized`) plus an `isActive()` shortcut. |
| `RequestForegroundColor` / `RequestBackgroundColor` / `RequestCursorColor` | ✅ | All three shipped — `Cmd::requestForegroundColor()` / `requestBackgroundColor()` / `requestCursorColor()` emit OSC 10/11/12 `?` queries; input reader parses `rgb:RRRR/GGGG/BBBB` replies into `ForegroundColorMsg` / `BackgroundColorMsg` / `CursorColorMsg`. Each colour Msg exposes `hex()`; fg/bg additionally expose `isDark()` for theme picking. |
| Auto `EnvMsg` on startup, with `Getenv()` helper for SSH contexts | ✅ | `Program::run()` snapshots `getenv()` and dispatches an `EnvMsg` to the model. `EnvMsg::get(key, default)` provides the convenience accessor. |
| Auto `ColorProfileMsg` on startup | ✅ | `Program::run()` detects via `ColorProfile::detect()` and dispatches a `ColorProfileMsg` right after `EnvMsg`. |

#### Clipboard

| v2 feature | Status | Notes |
|---|---|---|
| `SetClipboard(text)` / `ReadClipboard()` (OSC 52) | 🔴 | Add `Cmd::setClipboard`/`readClipboard`; pair with `ClipboardMsg`. Already partially present in CandyShell hints (clipboard description in OSC inspector). |
| `SetPrimaryClipboard(text)` (X11/Wayland primary selection) | 🔴 | OSC 52 with selection char `p`. |

#### Import path

- ⚪ Vanity domain (`charm.land/bubbletea/v2`) — not applicable to PHP / Composer.

---

### CandySprinkles (← Lipgloss v2)

| v2 feature | Status | Notes |
|---|---|---|
| Lipgloss is now pure (no I/O) — Bubble Tea owns all I/O | ✅ | We always made `Style::render()` pure; no I/O at all. |
| `lipgloss.Color()` returns `color.Color` interface | ⚪ | We use `Color` value object directly; no migration. |
| `lipgloss.Println` / `Printf` / `Sprint` / `Fprint` writers | 🟡 | Could add `Sprinkles\Style::println($content)` writing to `STDOUT` for non-TUI scripts. Optional. |
| `HasDarkBackground(stdin, stdout)` | ✅ | `BackgroundColorMsg::isDark()` (relative-luminance Y < 0.5) on the OSC 11 reply parsed by CandyCore. Models call `Cmd::requestBackgroundColor()` from `init()` and check the reply. |
| `LightDark(isDark)` helper returning the right colour | ✅ | `Sprinkles\LightDark::pick(isDark, light, dark)` and `LightDark::picker(isDark)` (curried). Plus `Sprinkles\AdaptiveColor` value object and `Style::foregroundAdaptive()` / `backgroundAdaptive()` that resolve via `Style::resolveAdaptive(bool)` — explicit `foreground()` always wins, matching lipgloss precedence. |
| `Complete(profile)` colour completion | ✅ | `Sprinkles\CompleteColor` value object holding a TrueColor / ANSI256 / ANSI triple with `pick(ColorProfile)`. `Style::foregroundComplete()` / `backgroundComplete()` setters store it; `Style::resolveProfile()` collapses to concrete fg/bg using the live `colorProfile()`. Explicit colours win, mirroring lipgloss precedence. |
| `compat.AdaptiveColor` / `CompleteColor` / `CompleteAdaptiveColor` | 🟡 | Add an `AdaptiveColor` value object that picks light vs dark based on detected background. |
| `EnableLegacyWindowsANSI()` | ⚪ | PHP doesn't ship a Windows console wrapper; fall through to Win10+ VT mode (which our `Tty` already assumes). |
| Determinism: same input → same output, regardless of detected terminal capabilities | ✅ | We already pass profile explicitly. |

---

### Bubbles v2 (← SugarBits)

The Bubbles v2 changes mostly tracked Bubble Tea's split. We get most
of it for free once the runtime adopts the v2 message types. Specific
items to revisit per component once the runtime upgrade lands:

- `TextInput` / `TextArea` — react to `KeyPressMsg` (with `IsRepeat`)
  to support held-down arrow keys correctly.
- `List` / `ItemList` — surface `MouseClickMsg` to enable click-to-pick.
- `Spinner` — unaffected.
- `Cursor` — adopt the new View `Cursor` field for native cursor shape
  / colour / blink instead of our reverse-video glyph.

### Sequencing

The v2 parity work is **medium-term**, not urgent. Recommended order:

1. **Cheap wins first** (no architectural changes): ✅ synchronized
   updates, ✅ unicode mode, ✅ `Println` / `Printf` Cmds, ✅ `Raw`
   escape hatch, ✅ mouse subtype markers, ✅ terminal queries (cursor
   pos, fg/bg/cursor colour, terminal version, mode report),
   ✅ `AdaptiveColor` + `LightDark`, ✅ `CompleteColor`, ✅ `EnvMsg`
   + `ColorProfileMsg` on startup — **all cheap wins shipped.** Next
   up: inline-mode polish (step 2), modifier alignment (step 3), and
   the larger pieces (Cursed renderer, View struct, Kitty keyboard
   protocol).
2. **Inline mode polish**: shrink the `Renderer` so non-alt-screen
   programs only own their own rows, leaving everything above
   intact. Pair with `Cmd::println` so messages can flow above the
   program region. **Direct ergonomic win for CandyShell's
   `input` / `confirm` / `spin` subcommands.**
3. ~~**Modifier alignment**: rename `KeyMsg::rune`/`type` to `text`/`code`
   and add `BaseCode` + `Modifiers`.~~ ✅ Shipped — `KeyMsg::text()` /
   `code()` aliases, `Modifiers` value object with bitfield constants,
   `KeyMsg::modifiers()` accessor, plus a new `shift` constructor field.
   Existing `alt` / `ctrl` booleans kept for back-compat. Modified-CSI
   sequences (`CSI 1;<mod>X` and `CSI <num>;<mod>~`) now decode into
   the new fields.
4. ~~**Mouse subtype split**: introduce concrete `MouseClickMsg` /
   `MouseReleaseMsg` / `MouseWheelMsg` / `MouseMotionMsg` extending
   `MouseMsg`.~~ ✅ Shipped — `MouseMsg` is non-final, four marker
   subclasses live under `candy-core/src/Msg/`, and `InputReader`
   instantiates the right subclass per SGR action. Existing
   `instanceof MouseMsg` checks keep working unchanged.
5. **Cursed renderer** (cell-diff): meaningful only once we have
   real-world SSH usage — defer until CandyWish ships. The blog post
   explicitly calls out the SSH cost savings.
6. **Inline image protocols**: schedule with the `Charts\Picture`
   slot in Phase 6. Sixel first (widest support), Kitty + iTerm2 as
   capability detection improves.
7. **View struct + advanced compositing (the big one)**: schedule for
   a CandyCore 2.0. Coordinate with a v2 of every consumer (SugarBits
   / SugarPrompt / CandyShell / SugarGlow / SugarCharts) since the
   `Model::view()` return type changes. Until then, keep the v1
   `string` shape.
8. **Kitty keyboard protocol**: nice-to-have. Ship after the View
   struct so `KeyboardEnhancements` lives on the View where v2 puts
   it.

This phase is itself a candidate for incremental PRs — most of the
"cheap wins" are independent and can land one by one. **SugarCrush
(Phase 9+ #17) is a natural milestone**: targeting v2-equivalent parity
makes the AI-coding-agent port land on top of an already-modern
runtime.
