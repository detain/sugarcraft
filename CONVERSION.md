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
| 0 | Foundation utilities (ansi / color / width / tty) | 🟢 | 100% | `Util\Ansi` covers SGR / cursor / erase / scroll region (DECSTBM) / scroll up-down (CSI S/T) / insert+delete line/char / OSC 7 / 8 hyperlinks / 9;4 progress / 10/11/12/52 colour & clipboard / DCS XTVERSION / Kitty keyboard / DECRQM / tab stops / SCO save-restore. `Util\Width` adds `wrap` / `wrapAnsi` / `pad{Left,Right,Center}`. `Util\Color` adds HSL/HSV / lighten / darken / alpha / blend / blend1d / blend2d / complementary / luminance / isDark. `Util\ColorProfile` consults TTY + FORCE_COLOR + TERM_PROGRAM (iTerm/WezTerm/etc.) + WT_SESSION + CI/GitHub-Actions/etc. NEW `Util\Parser` + `Util\Token` — tokenising state machine for terminal byte streams (CSI / OSC / DCS / APC / SOS / PM). `Util\Tty::onResize` + `drainSignals`. (PR #50) |
| 1 | CandySprinkles | 🟢 | 100% | `Style` (every lipgloss prop including `MaxWidth`/`MaxHeight`/`Inline`/`Transform`/`MarginBackground`/`TabWidth`/`ColorWhitespace`, per-side border colours, 21 getters, 15 `Unset*` resetters, `copy()`). NEW `Sprinkles\Layout` ports lipgloss's package-level helpers: `Place`/`PlaceHorizontal`/`PlaceVertical`/`JoinHorizontal`/`JoinVertical`/`Width`/`Height`/`Size` + `Position::TOP/CENTER/RIGHT/BOTTOM`. `Listing\Enumerator` adds `roman`/`romanUpper`/`decimal`; `ItemList` accepts nested sublists + `itemStyle`/`itemStyleFunc`/`enumeratorStyle`. `Tree\Enumerator` (default/rounded/ascii) + `Tree::indenter`/`rootStyle`/`itemStyle`/`enumeratorStyle`/`hide`. `Table::styleFunc` for per-cell styling, plus per-side border + offset + width-cap. (PR #51) |
| 2 | HoneyBounce | 🟢 | 100% | `Spring` (under/critically/over-damped + `fps()`). NEW `Vector` + `Point` value objects, `Projectile` Newtonian simulator with `update()` returning a fresh instance, `GRAVITY` + `TERMINAL_GRAVITY` constants and `gravity()`/`terminalGravity()` factory vectors. (PR #52) |
| 3 | CandyCore (runtime) | 🟢 | 100% | All Bubble Tea v2 Cmds shipped: `Suspend`/`Interrupt`/`Resume` (with SIGTSTP/SIGCONT handlers), `Exec`/`ExecProcess` (TTY release + restore around external command), `Sequence`, `Every` (wall-clock-aligned), `Printf`, plus stateful helpers (`enterAltScreen`/`exitAltScreen`/`clearScreen`/`show`/`hideCursor`/mouse-mode toggles/focus + bracketed-paste toggles/scroll up-down/colour set+reset). NEW Msgs: `Suspend`/`Resume`/`Interrupt`/`Exec`. ProgramOptions adds `catchPanics`, `withoutRenderer`, `filter` (Msg pre-processor closure). (PR #53) |
| 4 | CandyZone | 🟢 | 100% | `Manager::newPrefix()` namespaces zones across composed components via an atomic counter (or explicit prefix). `Manager::setEnabled(false)` makes `mark()` a pass-through and `scan()` an identity for non-interactive output. `prefix()` accessor. (PR #54) |
| 5 | SugarBits | 🟢 | 95% | TextInput: autocomplete (`setSuggestions`/`showSuggestions`/`matchedSuggestions`/`currentSuggestion`/`next/prevSuggestion`/`acceptSuggestion`) + validators (`withValidator`/`err`) + cursor accessors + `paste`. TextArea: `showLineNumbers`/`withEndOfBufferCharacter`/`withPrompt`/`withMaxWidth`/`withMaxHeight`/`withValidator`/`setCursor`/`setCursorColumn`/`insertString`/`lineInfo`. ItemList: `newStatusMessage`+`withStatusMessageLifetime`/`status()`/`withShowStatusBar`/`withShowHelp`/`withShowFilter`/`withInfiniteScrolling`. Spinner: jump/moon/monkey/hamburger/ellipsis (now matches Bubbles' 12-style library). Progress: `incrPercent`/`decrPercent`/`withGradient`/`withSolidFill`/`withDefaultGradient`/`withPercentFormat`/`viewAs`. Table: `Column` value object + `setColumns`/`columns` + `gotoTop`/`gotoBottom`/`moveUp`/`moveDown`. FilePicker: `Entry::icon()`/`formatSize()`, `withShowIcons`/`withShowSize`/`withSortMode`+`SortMode` enum, `error()` accessor. Viewport: `MouseWheelMsg` handler + `withMouseWheelEnabled`/`withMouseWheelDelta`, `setYOffset`/`yOffset`, `withScrollbar`+`withScrollbarRunes`. (PR #56) |
| 6 | SugarCharts | 🟢 | 95% | NEW `Canvas\Graph` ports the entire ntcharts canvas/graph primitive set: `drawHLine`/`VLine`/`XYAxis`/`XYAxisLabel` (last X label right-anchored to avoid clipping), `drawString`, `drawLine` (Bresenham), `drawLinePoints` (slope-glyph connectors), `fillRect`, `drawColumn`, `getCirclePoints` + `LINE_THIN/THICK/DOUBLE` rune presets. LineChart: `withAxes` + `withXLabels`/`withYLabels` + `withDataset`/`withDatasetPoint` (multi-series on shared axes). BarChart: `withHorizontal` (one-row-per-bar mode) + `withShowAxis` (the README's previously-fictional axis). NEW `LineChart\TimeSeries` accepts `[DateTimeImmutable, value]` tuples with PHP `date()` formatted X labels. Heatmap: `withPalette` (multi-stop colour scale) + `withLegend` (gradient strip with min/max labels). (PR #57) |
| 7 | SugarPrompt | 🟢 | 100% | NEW `Theme` with Style slots + 6 stock presets (`ansi`/`plain`/`charm`/`dracula`/`catppuccin`/`base16`/`base`). NEW `Group` for multi-page forms with title/description/`withHideFunc`. NEW `HasHideFunc` trait providing `withHideFunc` + `isHidden(values)` for every Field. Form: `Form::groups(...)` factory; Tab past last field → next visible group; `nextGroup`/`prevGroup`/`activeGroupIndex`/`totalGroups`/`activeGroup`/`activeFields`. `withTheme(Theme)` + `withAccessible(bool)` plain-text fallback for screen readers. NEW `Prompt\Spinner` blocking helper (forks a child to run an action, animates Bits Spinner on STDERR, joins). Single-page `Form::new(Field ...)` API preserved. (PR #58) |
| 8 | CandyShell | 🟢 | 95% | All 13 subcommands present, with greatly expanded flag coverage: `choose` & `filter` now multi-select (`--limit`/`--no-limit`/`--ordered`/`--selected`/`--select-if-one`/`--output-delimiter`); `input`/`write` add `--header`/`--prompt`/`--value`/`--char-limit`/`--width`/`--max-lines`/`--show-line-numbers`; `confirm` adds `--affirmative`/`--negative`/`--default=yes/no`/`--show-output`; `spin` adds `--show-output`/`--show-error` + 5 new spinner styles (jump/moon/monkey/hamburger/ellipsis) + `--spinner` alias; `log` adds `--min-level`/`--prefix`/`--time`/`--file`/`--format`/`--formatter (text/json/logfmt)`/`--structured`; `style` adds `--border` + `--border-foreground/background`/`--height`/`--trim`. (PR #59) |
| 9 | CandyShine (glamour) | 🟢 | 100% | `Renderer::withWordWrap(int)` wraps paragraphs + blockquotes via `Util\Width::wrapAnsi`. `Renderer::withHyperlinks(bool)` emits OSC 8 link envelopes (default on) with `(url)` fallback. Strikethrough (`~~foo~~`) now renders via `theme->strike` — StrikethroughExtension loaded. AutolinkExtension loaded so bare URLs are links. Image / HtmlBlock / HtmlInline handlers. Theme gains 9 new slots (`strike`/`linkText`/`image`/`htmlBlock`/`htmlSpan`/`definitionTerm`/`definitionDescription`/`text`/`autolink`) + 5 new presets (`dark`/`light`/`dracula`/`tokyoNight`/`pink`/`notty`). (PR #55) |

---

## Phase audit (2026-05-02)

> **Status (2026-05-02 close-out + follow-up wave + Phase 9+ wave):** every
> phase below has had its "Top priorities" — and every deferred item
> from the original audit — closed in the following PRs:
> #50 (Phase 0), #51 (Phase 1), #52 (Phase 2), #53 (Phase 3),
> #54 (Phase 4), #56 (Phase 5), #57 (Phase 6), #58 (Phase 7),
> #59 (Phase 8), #55 (Phase 9). Plus the deferred-item follow-ups:
> #61 (SugarCharts Streamline / Waveline / OHLC), #62
> (AnimatedProgress spring-physics), #63 (CandyShell `--style`
> sub-flag parser), #64 (NEW CandyFreeze code→SVG library), #65
> (CandyKit Section / Stage / HelpText + 4 themes), #66
> (SugarSpark DCS / APC + 14 new sequence labellers), #67
> (SugarGlow full theme set + word-wrap + OSC-8 toggle). Phase 10
> and 11 polish: #69-73 (composer metadata, READMEs, community
> files, examples, VHS .tape recordings), #74 (Phase 11a — cursed
> cell-diff renderer for SSH bandwidth), #75 (Phase 11b — Sixel /
> Kitty / iTerm2 image rendering), #76 (Phase 11c — Canvas
> multi-layer compositor for popovers). And the Phase 9+ port
> wave: #77 (CandyWish), #78 (SugarWishlist), #79 (CandyMetrics).
> The Progress tracker above reflects the new state. The
> original audit text below is preserved as a record of what
> the gaps were, not as a current backlog.

A line-by-line comparison of every PHP port against its Go counterpart. Each
phase that was previously listed as 🟢 100% turned out to have material gaps
once compared against the upstream source. This section was the authoritative
backlog — every "Missing" item below has now landed unless explicitly marked
`[defer]`.

Format: per phase, a `Done` paragraph (what genuinely works), a `Missing`
list (what's absent or stubbed), and a `Top priorities` list (the 2-3 items
to close first to actually claim parity). Items marked `[defer]` are
intentionally out of v1 scope.

### Phase 0 — Foundation utilities (`candy-core/src/Util/`)

**Done.** SGR (16/256/RGB), CSI cursor moves + DECSCUSR, erase line/screen,
alt-screen 1049, sync 2026, unicode 2027, bracketed paste 2004, mouse
1000/1002/1003 + SGR 1006, focus 1004, OSC 7/10/11/12/52(read+write)/2/9;4,
DCS XTVERSION, DECRQM, kitty keyboard push/pop/request, `Ansi::strip()`.
ColorProfile detects `NO_COLOR`, `CLICOLOR_FORCE`, `TERM`, `COLORTERM`. Color
covers RGB/hex/ansi/ansi256 with profile-aware downsampling and squared-RGB
nearest-neighbour. Width handles graphemes (with `grapheme_str_split`
fallback), strips ANSI before measuring, basic East-Asian-wide ranges,
truncation. Tty: `isTty`, `openTty(/dev/tty)`, `size()` via
COLUMNS/LINES/`stty size`, raw mode via `stty -g` + `-icanon -echo`.

**Missing.**
- **Input parser (no `Util/Parser.php`).** `Ansi.php` emits OSC/DCS query
  sequences (cursor pos, fg/bg/cursor color, clipboard read, kitty
  keyboard, mode reports) but nothing centralises parsing the replies
  back. `InputReader` rolls its own ad-hoc parsing inline. Factor out a
  proper tokenising parser so every consumer reads the same byte stream.
- **OSC 8 hyperlinks** (`\x1b]8;;URL\x1b\\`). Required by CandyShine.
- **Scrolling region** (DECSTBM `CSI r`), scroll up/down (`CSI S`/`T`),
  insert/delete line/character (`CSI L`/`M`/`@`/`P`), tab stops
  (`HTS`/`TBC`). Required for any pager/log-tailer.
- **OSC 4** palette set/query, **DECSET save/restore** (`CSI ?…s`/`r`).
- `ColorProfile::detect()` doesn't consult `Tty::isTty()`, so the `NoTty`
  enum case is unreachable when stdout is piped. Fix: gate to `NoTty`
  before consulting `TERM` if `!isTty(STDOUT)`.
- `ColorProfile`: no `CI`/`FORCE_COLOR`/`TERM_PROGRAM`/`WT_SESSION` checks,
  no Windows ConHost/ANSICON detection, no terminfo lookup.
- `Color`: no HSL/HSV constructors, no perceptual distance (CIE Lab/Oklab),
  no `Lighten`/`Darken`/`Alpha`/`Complementary`/`Blend1D`/`Blend2D`.
- `Width`: no `wrap()` (lipgloss-style word/grapheme wrap), no
  `padLeft`/`padRight`/`pad`, incomplete Unicode 15+ ZWJ-sequence
  collapsing (a flag-of-Scotland or family ZWJ sequence will count each
  cluster's width naively), no Variation Selector-16 narrow→wide
  promotion, no ambiguous-width East-Asian toggle.
- `Tty`: no SIGWINCH handler / resize event hook, no Windows VT-mode
  toggle (defer), no FFI termios fast path.

**Top priorities.**
1. `Util\Parser` — tokenising input parser. Unblocks reliable consumption
   of every reply CandyCore queries for.
2. `Ansi::hyperlink()` (OSC 8) + scroll region / scroll / insert-delete
   line. Unblocks CandyShine + a real Viewport / Pager.
3. `Width::wrap()` — needed by CandyShine and any text-heavy SugarBits
   component.

### Phase 1 — CandySprinkles (`candy-sprinkles/`)

**Done.** `Style` covers all SGR attributes, fg/bg with Adaptive / Complete /
CompleteAdaptive resolution, padding + margin (1/2/4-arg shorthand + per-side),
width/height with horizontal + vertical alignment, border with per-side bools
+ borderForeground/borderBackground, `colorProfile()`, `inherit()` with
explicit-set tracking, `render()`/`sprint()`/`printfSprint()`/`print()`/
`println()`/`fprint()`/`__invoke()`. Width-truncation preserves inline ANSI.
`Border` ships rounded / normal / thick / double / hidden / block + per-side
middle runes for Table. `Listing` (dash / bullet / asterisk / arabic /
spreadsheet-alphabet / none enumerators). `Tree` recursive nesting with
last-branch space prefix. `Table` with header/body, per-row align, jagged
row padding, +2 cell padding, separator row.

**Missing.**
- **Top-level layout helpers (high impact).** `Place()` /
  `PlaceHorizontal()` / `PlaceVertical()`, `JoinHorizontal()` /
  `JoinVertical()`, `Width()` / `Height()` / `Size()` measurement helpers,
  compositor / `NewCanvas` / layered rendering. Status bars, two-column
  splits, dashboards all need these.
- **Color helpers.** `Darken` / `Lighten` / `Alpha` / `Complementary` /
  `Blend1D` / `Blend2D` / `HasDarkBackground`. Position constants
  (Top/Bottom/Center/Left/Right) as float positioning.
- **`Style` gaps.** `MaxWidth` / `MaxHeight`, `Inline()`, `TabWidth()`,
  `Transform()` (callback rewrite of rendered string), `MarginBackground()`,
  per-side `BorderTopForeground` / `BorderRightForeground` / etc.,
  `ColorWhitespace()`, `Copy()`, `String()` / `SetString()` / `Value()`,
  `Unset*()` resetters for every prop, `GetForeground()` and other getters,
  no `noStyle` short-circuit when nothing is set.
- **Listing.** No roman / decimal-dotted enumerators, no
  `ItemStyle`/`EnumeratorStyle` style hooks, no nested sublists (an
  ItemList can't contain another ItemList), no `Filter`.
- **Tree.** No `Enumerator` swap (rounded vs. ascii box-draw), no
  `RootStyle`/`ItemStyle`/`EnumeratorStyle`, no `Indenter` override, no
  `Hide()` of root.
- **Table.** `StyleFunc(row, col) => Style` for per-cell style, `Filter`,
  `Border()` per-side toggles, individual `BorderTop`/`BorderHeader`/
  `BorderRow`/`BorderColumn` flags, `Wrap()` overflow mode, `Width()`
  constraint, `Offset`, `Data` interface for dynamic row fetch.

**Top priorities.**
1. `JoinHorizontal` / `JoinVertical` / `Place` — every nontrivial lipgloss
   layout uses these.
2. `Style::inline()` + `MaxWidth/MaxHeight` + `Transform()` +
   `MarginBackground()` — heavily used by lipgloss's own list/table.
3. `Table::styleFunc()` — without it, the canonical lipgloss table demo
   (header in a different colour than body) cannot be reproduced.

### Phase 2 — HoneyBounce (`honey-bounce/`)

**Done.** `Spring` constructor, `update($pos, $vel, $target): [pos, vel]`,
`Spring::fps(int)`. All three damping branches faithful to Ryan-Juckett's
algorithm. Tests cover identity, at-target, critical, under-damped overshoot,
over-damped, negative-damping clamp, and a frame-30 fixture matching Go.

**Missing.**
- **Entire `projectile` package.** `Projectile`, `Point`, `Vector`,
  `NewProjectile`, `Update`/`Position`/`Velocity`/`Acceleration`, `Gravity`
  + `TerminalGravity` constants. ~150 LOC + a fixture test brings the port
  to genuine 100%.

**Top priority.** Add `Projectile.php` + `Point.php`/`Vector.php` + the two
gravity constants + a `ProjectileTest` against Go reference values. One
sitting.

### Phase 3 — CandyCore runtime (`candy-core/`)

**Done.** Options: `WithAltScreen`, `WithoutSignalHandler`, `WithFPS`,
`WithReportFocus`, `WithBracketedPaste`, `WithInput`, `WithOutput`,
`WithEnvironment`, `WithWindowSize`, `WithColorProfile`, `OpenTTY`,
`InlineMode`, mouse modes via `MouseMode` enum. Cmds: `Quit`, `Batch`,
`Tick`, `Raw`, `Println`, the terminal-query family
(`RequestCursorPosition`/`RequestForegroundColor`/`RequestBackgroundColor`/
`RequestCursorColor`/`RequestTerminalVersion`/`RequestMode`),
`SetClipboard`/`ReadClipboard`, `SetWindowTitle`, `SetWorkingDirectory`,
`SetProgressBar`, kitty-keyboard push/pop/request. Built-in Msgs:
`KeyMsg`/`KeyPressMsg`/`KeyReleaseMsg`/`KeyRepeatMsg`,
`MouseMsg`+`MouseClick`/`Release`/`Motion`/`Wheel` markers,
`WindowSizeMsg`, `Focus`/`BlurMsg`, `Paste`/`PasteStart`/`PasteEndMsg`,
`QuitMsg`, `EnvMsg`, `ColorProfileMsg`, `KeyboardEnhancementsMsg`,
`CursorPositionMsg`, `TerminalVersionMsg`, `ModeReportMsg`,
`Foreground`/`Background`/`CursorColorMsg`, `ClipboardMsg`, `BatchMsg`.
`View` struct with body/cursor/windowTitle/progressBar/fg+bg colour/
mouseMode/reportFocus/bracketedPaste.

**Missing.**
- **Suspend / Interrupt / Resume** Cmd + Msg + SIGTSTP/SIGCONT handler.
  Required for any real-world app (Ctrl-Z to background editor). The
  Program currently only catches SIGINT and SIGWINCH.
- **`Exec` / `ExecProcess` + `ExecMsg`.** No `$EDITOR` integration. Several
  Bubbles components (TextArea, List delegate actions) rely on this.
  Implementation: `proc_open` with TTY save/restore around the child.
- **`Sequence`.** Ordering between Cmds is sometimes load-bearing
  (`Batch` runs concurrently; `Sequence` runs one-at-a-time, gating each
  Msg dispatch on the previous Cmd completing).
- **`Every`.** Wall-clock-aligned tick (vs. `Tick`'s independent clock).
- **`Printf`.** Companion to `Println` — emit formatted text above the
  program region.
- **Stateful Cmd helpers.** `EnterAltScreen` / `ExitAltScreen` /
  `ClearScreen` / `ShowCursor` / `HideCursor` /
  `EnableMouseCellMotion`/`EnableMouseAllMotion`/`DisableMouse` /
  `EnableReportFocus`/`DisableReportFocus` /
  `EnableBracketedPaste`/`DisableBracketedPaste` /
  `ScrollSync`/`ScrollUp`/`ScrollDown`. Some are reachable via `View`
  (mouseMode/reportFocus/bracketedPaste/altScreen) but library users
  expect Cmd factories.
- **`SetForegroundColor`/`SetBackgroundColor` /
  `ResetForegroundColor`/`ResetBackgroundColor`** Cmds (one-shot OSC
  10/11 emission). The `View` field handles per-frame setting; these
  cover the imperative path.
- **Options:** `WithoutCatchPanics`, `WithFilter` (Msg pre-processor),
  `WithContext` (cancellation handle), `WithoutRenderer` (headless).

**Top priorities.**
1. **Suspend/Interrupt/Resume + SIGTSTP/SIGCONT.** Biggest functional hole
   for real-world apps.
2. **`Exec`/`ExecProcess`.** Required by Bubbles components and any
   `$EDITOR` workflow.
3. **`Sequence` + `Every` + the stateful Cmd helpers.** Each is small but
   collectively block sample programs from porting verbatim.

### Phase 4 — CandyZone (`candy-zone/`)

**Done.** `Manager::newGlobal()`, `mark()`, `scan()`, `get()`, `clear()`,
`all()`. `Zone::inBounds()`, `pos()` (returns `[col, row]` 0-based),
`width()`, `height()`. Multi-line zones, ANSI/CSI/OSC pass-through, wide
CJK (2 cells), zero-width graphemes. APC marker scheme
(`ESC _ candyzone:S/E:<id> ESC \`) — terminals ignore it cleanly. 14 tests
including ANSI styling, CJK, ZWSP, dangling end-marker, rescan-replaces.

**Missing.**
- **`Manager::newPrefix()`** — composability blocker. Without it, two
  CandyZone-aware components in one program can collide on id `"item-0"`.
  Go uses an atomic counter to namespace zones across composed
  components.
- **`Manager::setEnabled(bool)`** — gates `mark()` / `scan()` to no-op in
  non-interactive output. Trivial.
- **`AnyInBounds` / `AnyInBoundsAndUpdate`** helper Cmds (minor).
- **Custom marker prefix** (cosmetic).

**Top priority.** `newPrefix()` + `setEnabled()` — both are <30 LOC and
unblock proper composition in larger programs.

### Phase 5 — SugarBits (`sugar-bits/`) — most affected by audit

The 14 components are present as skeletons but the **consumer-visible polish
surface is largely absent.** Real coverage is closer to **50-60%** of the Go
public API by line-count. The headline gaps below are the ones that show
up immediately when porting any Bubbles tutorial.

**TextInput.**
- Missing: autocomplete (`ShowSuggestions`/`SetSuggestions`/
  `AvailableSuggestions`/`MatchedSuggestions`/`CurrentSuggestion`/
  `CurrentSuggestionIndex`), validator + `Err` field, `SetCursor`/
  `Position`/`CursorStart`/`CursorEnd`, `Paste()` Cmd helper, configurable
  `KeyMap`, `Styles`/`SetStyles`.

**TextArea.**
- Missing: `ShowLineNumbers`, `MaxHeight`/`MaxWidth`, `EndOfBufferCharacter`,
  `Prompt`, `LineInfo`, `Word`, `InsertString`/`InsertRune`,
  `SetCursorColumn`, `KeyMap`, `Err`, soft-wrap.

**ItemList (List).**
- Missing: `NewStatusMessage` toast + `StatusMessageLifetime`, embedded
  `Paginator`, dedicated `FilterInput` (PHP rolls its own raw string),
  `ShowStatusBar`/`ShowPagination`/`ShowHelp`/`ShowFilter` toggles,
  `AdditionalShortHelpKeys`/`AdditionalFullHelpKeys`, `InfiniteScrolling`,
  `Styles` struct, `DefaultDelegate` rendering pattern, spinner
  integration, custom `Filter` function. Description rendering hardcoded.

**Spinner.**
- Only 7 of 12 named styles. Missing: **Jump, Moon, Monkey, Hamburger,
  Ellipsis**.

**Progress.**
- Missing: animated transitions (`SetPercent`/`IncrPercent`/`DecrPercent`
  returning Cmds), `IsAnimating`, `SetSpringOptions`, gradient fill
  (`WithGradient`/`WithSolidFill`/`WithDefaultGradient`/`WithScaledGradient`),
  `PercentFormat`, `PercentageStyle`, `ViewAs`. Source even self-flags:
  "Spring-physics interpolation … lands in a follow-up."

**Table.**
- Missing: `Column{Title, Width}` struct (PHP uses bare `list<string>` so
  per-column widths can't be set), `Styles{Header, Cell, Selected}`,
  `GotoTop`/`GotoBottom`/`MoveUp`/`MoveDown`, `KeyMap`, `HelpView`.

**FilePicker.**
- Missing: error messages, file-type icons, file-size column, sort
  (name/size/modtime), `Styles`, `AllowedTypes`, `KeyMap`. Currently
  hardcoded "directories first, alpha within group" + a single
  `Ansi::REVERSE` highlight.

**Viewport.**
- Missing: mouse-wheel scroll handler, rendered scrollbar, `SetYOffset`,
  `MouseWheelEnabled`, `KeyMap`, `Style`, soft-wrap, high-performance
  rendering hooks. `gotoTop`/`gotoBottom`/`scrollPercent` present.

**Paginator.**
- Missing: `KeyMap`, `UsePgUpPgDownKeys`/`UseLeftRightKeys`,
  `ItemsOnPage(totalItems)`, `SetTotalPages`.

**Help / Cursor / Key / Timer / Stopwatch.**
- Help: no `Width` truncation, no `ShowAll` toggle, no configurable
  `Styles`. Cursor: no `Style`/`TextStyle` (only REVERSE highlight). Key:
  lacks `SetKeys`/`SetHelp`/`Keys()` accessor; `KeyMap` is a 2-method
  interface vs Go's richer pattern. Stopwatch: `StartStopMsg.php` exists
  but `update()` doesn't dispatch on it.

**Top priorities.**
1. **List polish: status messages, status bar, configurable styles +
   delegate.** ItemList is the most-used Bubbles component; without these
   it's only half the original.
2. **TextInput suggestions + validator.** Required by SugarPrompt's `Input`
   and any login/search flow.
3. **Progress animation + Spinner styles.** Two visible-on-screen polish
   items the user notices first.
4. **Table `Column` struct + `Styles`.** Required to render anything that
   looks like the lipgloss table demo.

### Phase 6 — SugarCharts (`sugar-charts/`)

**Done.** `Canvas` (cell grid + Sprinkles styling), `Sparkline` (8-glyph
sliding window), `BarChart` (vertical bars + labels + auto/manual range +
gap auto-sizing), `LineChart` (single series + connectors), `Heatmap`
(2D RGB lerp), `Scatter` (auto-ranged X/Y, no connectors). Six tests.

**Missing.**
- **Entire `canvas/graph` package.** `drawHLine` / `drawVLine` /
  `drawXYAxis` / `drawXYAxisLabel` / `drawLine` / `drawBraille` /
  `drawColumns` / `drawCandlestick` / `getLinePoints` / `getCirclePoints`.
  Every other ntcharts chart leans on these. **Nothing else in this list
  can be properly closed without these primitives.**
- **Canvas API.** `setLines` / `setLinesWithStyle` / `setString` /
  `setRunes`, `fill` / `fillLine`, `shiftUp`/`Down`/`Left`/`Right`,
  `resize`, `cursor`, `setStyle` bulk apply.
- **BarChart.** `setHorizontal` (the README example shows a `┤` axis the
  code doesn't draw), `setShowAxis`, `setBarWidth`, `setBarGap`,
  `Push`/`PushAll` streaming API, multi-value `BarData` (stacked/grouped
  bars).
- **LineChart.** X/Y axis rendering + labels, X/Y step config, named
  datasets / multi-series, `viewRange` vs data-range, line-style enum
  (thin/thick/double — Go `runes.LineStyle`), Braille high-resolution
  drawing, `autoAdjustRange`. No `DrawXYAxisAndLabel()` equivalent.
- **Heatmap.** Legend / scale bar, multi-stop named gradient palette
  (`SetDefaultColorScale`), `Push(HeatPoint)` streaming, `SetXYRange`,
  axis rendering.
- **Sparkline.** `Style` / foreground colour (Go has `Style lipgloss.Style`),
  `Push`/`PushAll` streaming, `setMax`, Braille variant, height>1
  multi-row sparkline.
- **Missing chart types.** `TimeSeries` LineChart, `Streamline` LineChart,
  `Waveline` LineChart, `OHLC` / Candlestick. `Picture` (Sixel/Kitty)
  remains explicitly deferred.

**Top priorities.**
1. **`Canvas\Graph`** — port the graph primitives. Unblocks every other
   chart's axis rendering.
2. **LineChart axes + multi-series.** Without axes, LineChart is half what
   users expect.
3. **BarChart `withHorizontal()` + `withShowAxis()`** — trivial scope, high
   user-visible value.
4. **TimeSeriesLineChart.** Most-requested ntcharts variant; small surface
   once LineChart axes exist.

### Phase 7 — SugarPrompt (`sugar-prompt/`)

**Done.** All 7 huh field types: `Note` (skippable), `Input` (TextInput +
validator), `Confirm` (custom labels), `Select` (ItemList wrap, filter),
`MultiSelect` (checkbox grid + min/max), `Text` (TextArea wrap), `FilePicker`
(wraps Bits FilePicker). `Form` container with Tab/Down/Up/Shift+Tab nav,
Enter-submit, Esc/Ctrl-C abort, focus delegation, `Field::consumes(Msg)` for
inner-key ownership, `init()` Cmd propagation, `values()` collector.

**Missing.**
- **`Group` class entirely.** No multi-page navigation, no per-group
  titles/descriptions, no `Form::nextGroup()`/`prevGroup()`, no
  `Group::withHide()`. `Form::new` takes flat `Field ...$fields`. This is
  huh's headline composition primitive.
- **`Theme` system entirely.** No `Theme` class, no Charm/Dracula/
  Catppuccin/Base16/Base/ANSI presets, no `Form::withTheme()`. Rendering
  uses ad-hoc inline `Ansi::sgr(Ansi::REVERSE)` calls.
- **`withHideFunc(\Closure(): bool)`** runtime visibility predicate on
  every field. `skippable()` is a static boolean (only `Note` returns
  true), not a runtime predicate.
- **`Form::withAccessible(bool)`** — no screen-reader fallback. huh
  degrades to plain stdin/readline-style prompts when set.
- **Standalone prompt `Spinner` runner.** No
  `Prompt\Spinner::new()->title()->action(\Closure)->run()` (huh's
  `huh.NewSpinner()` blocking helper). Bits `Spinner` exists but is
  Cmd-driven only.
- **Per-field option gaps.**
  - `Input`: `withEchoMode` (password masking with `*`), `withSuggestions`.
  - `Text`: `withEditor` (external `$EDITOR` shell-out — depends on
    CandyCore `Exec`).
  - `Select`/`MultiSelect`: `withValue`, `withTitleFunc`, `withOptionsFunc`,
    `withFiltering` toggle (filter is always-on).
  - `MultiSelect`: `withLimit` (combined cap), `withHeight`.
- Validators currently only on `Input`/`Text`. No hooks on `Select`,
  `Confirm`, `FilePicker`.

**Top priorities.**
1. **`Group` class + multi-page `Form`.** Biggest functional gap. Already
   acknowledged as post-v1 in the per-library section.
2. **`Theme` system.** `Prompt\Theme` with Style slots + factories
   (`charm()`/`dracula()`/`catppuccin()`/`base16()`/`base()`/`ansi()`),
   `Form::withTheme()`, per-field `theme()` accessor. Replace inline
   Ansi calls.
3. **`withHideFunc` + accessibility + Spinner runner.**

### Phase 8 — CandyShell (`candy-shell/`)

**Done.** Bin script + Symfony Console application + all 13 subcommands:
`style`, `choose`, `input`, `confirm`, `join`, `log`, `table`, `filter`,
`write`, `file`, `pager`, `spin`, `format`. Subcommand presence is 100%.

**Missing — flag coverage averages 25-40% per command.** A `gum` script
will not port verbatim. Per-command headline gaps:

| Subcommand | Missing flags (most user-visible only) |
|---|---|
| **choose**   | `--limit`, `--no-limit`, `--ordered`, `--cursor`, `--header`, `--cursor-prefix`, `--selected-prefix`, `--unselected-prefix`, `--selected`, `--select-if-one`, `--input-delimiter`, `--output-delimiter`, `--label-delimiter`, `--strip-ansi`, `--timeout`, `--show-help`, `--padding` |
| **confirm**  | `--show-output`, `--affirmative`, `--negative`, `--prompt`, `--timeout`, `--show-help`, `--padding` (+ surface mismatch: PHP uses `--default-yes`, gum uses `--default=yes/no`) |
| **file**     | `-c/--cursor`, `-a/--all`, `-p/--permissions`, `-s/--size`, `--file`, `--directory`, `--header`, `--timeout`, `--show-help`, `--padding` |
| **filter**   | `--indicator`, `--limit`, `--no-limit`, `--select-if-one`, `--selected`, `--strict`, `--selected-prefix`, `--unselected-prefix`, `--header`, `--placeholder`, `--prompt`, `--width`, `--value`, `--reverse`, `--fuzzy`, `--fuzzy-sort`, `--timeout`, `--input-delimiter`, `--output-delimiter`, `--strip-ansi`, `--show-help`, `--padding` |
| **format**   | `--language/-l`, `--strip-ansi`, `--type/-t` (markdown/template/code/emoji), env-var support |
| **input**    | `--prompt`, `--value`, `--char-limit`, `--width`, `--header`, `--cursor.mode`, `--timeout`, `--strip-ansi`, `--show-help`, `--padding` |
| **join**     | `--align` (left/center/right/top/middle/bottom). Note: `--separator` is a candy-shell extension — gum has none. |
| **log**      | `-o/--file`, `-f/--format` (printf), `--formatter` (json/logfmt/text), `--prefix`, `-s/--structured`, `-t/--time`, `--min-level` |
| **pager**    | `--show-line-numbers`, `--line-number-foreground`, `--soft-wrap`, `--match-foreground`, `--match-bold`, `--match-highlight-foreground`, `--match-highlight-background`, `--match-highlight-bold`, `--timeout` |
| **spin**     | `--show-output`, `--show-error`, `--show-stdout`, `--show-stderr`, `--align/-a`, `--timeout`, `--padding`. PHP names the flag `--style`, gum uses `--spinner/-s`. Missing styles: `jump`, `moon`, `monkey`, `hamburger`. |
| **style**    | `--border`, `--border-foreground`, `--border-background`, `--height`, `--trim`, `--strip-ansi` |
| **table**    | `-c/--columns`, `-w/--widths`, `--height`, `-p/--print`, `--hide-count`, `--lazy-quotes`, `--fields-per-record`, `-r/--return-column`, `--timeout`, `--padding`, `--show-help`. Semantic mismatch: gum's `--header` doesn't exist as a bool — it auto-uses `--columns`; PHP `--header` (bool) treats first row as header. |
| **write**    | `--header`, `--prompt`, `--show-cursor-line`, `--show-line-numbers`, `--value`, `--char-limit`, `--max-lines`, `--cursor.mode`, `--timeout`, `--strip-ansi`, `--show-help`, `--padding` |

**Globally absent** across every command: per-element style sub-flags
(`--cursor.foreground`, `--header.bold`, `--match.background`,
`--prompt.italic`, …), `--timeout`, `--show-help`, `--padding`,
`--strip-ansi`, environment-variable defaults (`GUM_*`).

**Top priorities.**
1. **Multi-select on `choose`/`filter`** — `--limit`/`--no-limit`/
   `--ordered`/`--selected`. Headline gum feature; scripts that read
   multiple selections silently break (PHP returns one line).
2. **`--header`/`--prompt`/`--value`** across input/write/filter/choose
   — nearly universal in real gum scripts (`gum input --header "Email"
   --value "$LAST"`).
3. **Structured `log` output** — `--structured`/`--formatter`/`--prefix`/
   `--time`/`--file`. Plus `spin --show-output`/`--show-error` for CI
   scripts.
4. **Per-element style sub-flags.** Big surface, but the most common ones
   (`--header.foreground`, `--cursor.foreground`, `--prompt.foreground`)
   are reachable via a small generic plumbing helper rather than 200
   one-off options.

### Phase 9 — CandyShine (`candy-shine/`)

**Done.** AST walk via `league/commonmark` for: headings 1-6, paragraph,
strong/em, inline code, fenced + indented code (with regex syntax
highlighting for php / js / ts / json / python / go / bash / sql),
blockquote (`▎`), bullet/ordered lists (with nested indent), GFM tables
(rounded `Sprinkles\Table`), thematic break, links, **task lists**
(`☑`/`☐`). `Theme` with 19 slots + `ansi()`/`plain()` factories +
`fromJson` / `fromJsonString` (hex / `ansi:N` / `ansi256:N` colour, all
SGR flags, partial overrides fall through to `Style::new()`).

**Missing.**
- **Word-wrap.** No `WithWordWrap(int $cols)`, no width tracking, no
  `BlockPrefix`/`BlockSuffix`. Long paragraphs render unwrapped at
  terminal width. Every glamour theme assumes wrap.
- **Stock theme presets beyond `ansi`/`plain`.** Glamour ships `Dark`,
  `Light`, `ASCII`/`Notty`, `Pink`, `Dracula`, `TokyoNight`, `Auto`. PHP
  has only `ansi` + `plain`. `Notty` is special — glamour auto-selects it
  when stdout isn't a TTY.
- **OSC 8 hyperlinks.** `renderLink()` only emits styled text + ` (url)`.
  No `\x1b]8;;URL\x1b\\` wrapping, no FNV-32 link IDs, no
  terminal-capability gate. Depends on Phase 0 `Ansi::hyperlink()`.
- **Strikethrough silently dropped.** `StrikethroughExtension` isn't even
  loaded on the parser, so `~~foo~~` parses as plain text.
- **Element selectors.** Missing `link_text` (separate from `link`),
  `image`, `image_text`, `definition_list` / `definition_term` /
  `definition_description`, `html_block`, `html_span`, `strikethrough`
  (Style + AST handler), `text` (Pink theme has it), `auto_link`,
  `emoji`, `code_block.theme`/`chroma` block. Glamour exposes 28 elements;
  PHP exposes 15.
- **AST coverage.** Strikethrough, definition lists, front-matter
  (YAML/TOML), autolinks-as-distinct, footnotes, emoji shortcodes — none
  handled. The default branch silently calls `renderChildren()`.
- **Public API.** No `WithEnvironmentConfig` / `GLAMOUR_STYLE` env-var,
  no `ChromaConfig`, no `WithStyles($json)` on the renderer, no
  `WithStandardStyle('dark')` selector.

**Top priorities.**
1. **Word-wrap.** Wrap paragraph/blockquote/list bodies on `WithWordWrap`.
2. **Stock theme presets.** Ship `dark()` / `light()` / `notty()` at
   minimum (notty auto-selects on non-TTY) — ideally `dracula()` /
   `tokyoNight()` / `pink()` too.
3. **OSC 8 hyperlinks + strikethrough + missing element slots.**
   Strikethrough is a one-liner (load extension, add Theme slot, add
   handler). OSC 8 is one helper. Element slots are ~10 readonly Style
   properties.

---

## Cross-cutting follow-ups surfaced by the audit

These don't belong to any single phase; they showed up across multiple.

- **Input parser as a shared lib (`Util\Parser`).** CandyCore's
  `InputReader` rolls its own parsing; CandyShine and any future pager
  need OSC reply parsing. Promote to a single tokeniser in
  `candy-core/src/Util/Parser.php` and have everyone consume it.
- **`Util\Width::wrap()` and `Util\Width::pad()`** are needed by
  CandyShine (word-wrap), every SugarBits text component (soft-wrap),
  and CandySprinkles (`MaxWidth`).
- **OSC 8 hyperlinks** are needed by CandyShine *and* any SugarBits log
  / list / textarea that ought to render clickable URLs.
- **`Exec`/`ExecProcess` in CandyCore** is the foundation that
  `SugarPrompt::withEditor` and several SugarBits actions depend on.
  Schedule before SugarPrompt theming.
- **Theme abstraction.** Both CandyShine and SugarPrompt want a Theme
  system; CandyShell wants per-element style overrides. Worth
  considering a single `CandyCore\Theme` value object with named
  Style slots that all three consume, instead of three independent
  parallel implementations.

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
| 10 | [charmbracelet/glow](https://github.com/charmbracelet/glow) | **SugarGlow** ✅ | `sugar-glow/` → `candycore/sugar-glow` | `CandyCore\Glow` | Markdown CLI viewer / pager. Library + `bin/sugarglow` CLI shipped — single Symfony Console default command renders Markdown via CandyShine and either prints to stdout (default) or opens a fullscreen `Viewport` pager (`-p` / `--pager`) sized to the terminal. `--theme ansi\|plain\|dark\|light\|notty\|dracula\|tokyo-night\|pink` (full CandyShine theme set), `--style/-s` alias, `--theme-config` for custom JSON themes, `--width/-w` word-wrap, `--no-hyperlinks` to disable OSC 8. Reads from a file argument or stdin. | 1, 3, 5, 9 |
| 11 | [charmbracelet/freeze](https://github.com/charmbracelet/freeze) | **CandyFreeze** ✅ | `candy-freeze/` → `candycore/candy-freeze` | `CandyCore\Freeze` | Code → SVG screenshot. SVG-only first cut (no `ext-gd`/Imagick required); supports macOS-style traffic-light window controls, drop shadow, rounded-corner frame, optional gutter line numbers, and inline ANSI SGR colour parsing (16-color / 256-color / 24-bit truecolor + bold/italic/underline). 5 stock themes (`dark`/`light`/`dracula`/`tokyoNight`/`nord`). Library + `bin/candyfreeze` CLI shipped. | 1 |
| 12 | [charmbracelet/sequin](https://github.com/charmbracelet/sequin) | **SugarSpark** ✅ | `sugar-spark/` → `candycore/sugar-spark` | `CandyCore\Spark` | Inspect / pretty-print ANSI escape sequences. Library + `bin/sugarspark` CLI shipped — labels SGR (foreground / background / 256-color / truecolor / attributes), CSI cursor moves, erase, DEC private mode toggles (mouse, focus, alt screen, bracketed paste, **synchronized output 2026, unicode mode 2027**), CSI ~ keys (Home/End/Delete/PgUp/PgDn/F1-F12), **scroll region (DECSTBM), scroll up/down, tab forward/backward, insert chars, DECSCUSR cursor shape, DECRQM/DECRPM mode query/reply, kitty keyboard query/push/pop, XTVERSION request, cursor position request**, SS3, OSC (title / icon / cwd / hyperlink / OSC 52 / **palette / progress / colour set + reset**), 2-byte ESC, **DCS** (XTVERSION reply, DECRPSS, sixel), **APC** (CandyZone markers, kitty graphics). Unrecognised sequences fall back to a generic descriptor so nothing is silently swallowed. | 0 |
| 13 | [charmbracelet/fang](https://github.com/charmbracelet/fang) | **CandyKit** ✅ | `candy-kit/` → `candycore/candy-kit` | `CandyCore\Kit` | Opinionated CLI presentation helpers shipped: `Theme` (success/error/warn/info/prompt/accent/muted Style palette, **ansi / plain / charm / dracula / nord / catppuccin** factories), `StatusLine` (✓/✗/⚠/ℹ/? glyph + message), `Banner::title($title, $subtitle)` (rounded-bordered title block, custom Border supported), **`Section::header / rule`** (`── LABEL ────`), **`Stage::step / subStep`** (numbered build-script lines + tree-style sub-steps), **`HelpText::render`** (fang-style `--help` page from a `usage + sections` map). Library-only — no Symfony Console requirement so any Composer project can drop it in. | 1 |
| 14 | [charmbracelet/wish](https://github.com/charmbracelet/wish) | **CandyWish** ✅ | `candy-wish/` → `candycore/candy-wish` | `CandyCore\Wish` | SSH-server middleware framework. Lean on host `sshd` via `ForceCommand`; PHP entry script runs the middleware chain (`Logger` / `Auth` / `RateLimit` / `BubbleTea`) per connection. Trade-off avoids re-implementing the SSH wire protocol — battle-tested cipher / host-key / fail2ban handling stays with OpenSSH. (PR #77) | 3 |
| 15 | [charmbracelet/wishlist](https://github.com/charmbracelet/wishlist) | **SugarWishlist** ✅ | `sugar-wishlist/` → `candycore/sugar-wishlist` | `CandyCore\Wishlist` | SSH endpoint launcher. YAML / JSON directory of `ssh user@host` shortcuts, interactive picker (j/k or arrows, type-to-filter), then `pcntl_exec` into the chosen `ssh` so host-key prompts / agent forwarding / MOTD all flow through unchanged. Library + `bin/wishlist` CLI shipped. (PR #78) | — |
| 16 | [charmbracelet/promwish](https://github.com/charmbracelet/promwish) | **CandyMetrics** ✅ | `candy-metrics/` → `candycore/candy-metrics` | `CandyCore\Metrics` | Telemetry primitives + CandyWish session middleware. `Registry` facade with `counter` / `gauge` / `histogram` / `time` / `withTags`; four backends (`InMemoryBackend`, `JsonStreamBackend`, `StatsdBackend` (etsy + DogStatsD), `PrometheusFileBackend` (atomic textfile-collector rewrite), `MultiBackend` for fanout). `SessionMetrics` middleware emits `wish.session.{connect,duration,error}` with user / term tags. (PR #79) | 14 |
| 17 | [charmbracelet/crush](https://github.com/charmbracelet/crush) | **SugarCrush** | `sugar-crush/` → `candycore/sugar-crush` | `CandyCore\Crush` | AI coding-assistant TUI app. Demonstrates CandyCore + every phase together. | 0–8 |
| 18 | [charmbracelet/bubbletea-app-template](https://github.com/charmbracelet/bubbletea-app-template) | **CandyTemplate** | `candy-template/` → `candycore/candy-template` (Composer create-project) | `CandyCore\Template` | Skeleton repo for users bootstrapping a CandyCore app. | 0, 3 |
| 19 | [Broderick-Westrope/tetrigo](https://github.com/Broderick-Westrope/tetrigo) | **CandyTetris** | `candy-tetris/` → `candycore/candy-tetris` | `CandyCore\Tetris` | Tetris clone. Pure example app for the runtime. Optional. | 1, 3, 5 |
| 20 | [yorukot/superfile](https://github.com/yorukot/superfile) | **SuperCandy** | `super-candy/` → `candycore/super-candy` | `CandyCore\SuperFile` | Dual-pane file manager. Stress-test for `FilePicker`, `Viewport`, mouse zones. | 1, 3, 4, 5 |

### Sequencing notes

- **CandyShine** is the highest-leverage entry: it fills the gap that's
  blocking CandyShell's `format` subcommand and underpins glow + the
  table-styled output in fang.
- **CandyWish** sidesteps the "PHP SSH stack" question entirely by
  delegating SSH-protocol handling to OpenSSH (`ForceCommand`) and
  shaping the middleware chain around per-connection PHP processes.
  This unblocks both **SugarWishlist** (`pcntl_exec`s into the system
  `ssh` client) and **CandyMetrics** (a pluggable `Backend` /
  `SessionMetrics` middleware) without requiring `ext-ssh2`. ext-ssh2
  is still a useful optional dependency for *outbound* SSH from inside
  a session.
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
| `WithInput` / `WithOutput` / `WithEnvironment` / `WithWindowSize` / `WithColorProfile` Program options | ✅ | All five available on `ProgramOptions`: `input` / `output` (existing), plus `environment` (replaces `getenv()` snapshot for the startup `EnvMsg`), `windowSize` (replaces TTY size query for the startup `WindowSizeMsg`), and `colorProfile` (replaces `ColorProfile::detect()` for the startup `ColorProfileMsg`). All three overrides are nullable — leave them at `null` to let the runtime auto-detect. |
| `OpenTTY()` — open `/dev/tty` directly when stdin is piped | ✅ | `Util\Tty::openTty()` returns `[input, output]` for `/dev/tty` (or `null` on Windows / when unavailable). `ProgramOptions::$openTty` (default false) flips the runtime to use it instead of STDIN/STDOUT — opt-in so existing programs aren't affected. |
| `tea.Println` / `tea.Printf` — write text *above* the program's region | ✅ | `Cmd::println(string)` returns a `PrintMsg` sentinel; `Program::dispatch()` writes the line + a newline and resets the renderer so the next frame repaints cleanly. |
| `tea.Raw(escape)` — send raw escape sequences | ✅ | `Cmd::raw(string)` returns a `RawMsg`; the Program writes the bytes verbatim without disturbing renderer diff state. |
| **Inline mode** as a first-class use case (no alt screen, no full takeover) | ✅ | `ProgramOptions::$inlineMode` (default false) flips `Renderer` into inline mode: first frame saves the cursor (`ESC 7`) at its current row instead of homing to (1, 1); subsequent frames restore + erase to end + repaint. Scrollback above the program region stays intact. Pair with `useAltScreen=false`. |
| **Advanced compositing** — layered rendering / pop-overs / floating panes | 🔴 | Stacks multiple "layers" so a modal can render above the main view without the model rebuilding the world. Schedule with the View struct rework — they're paired in v2. |
| **Inline image protocols** (Sixel / Kitty / iTerm2) | 🔴 | Detect the active protocol via terminal-version query; encode an image (PNG / GIF / SVG via librsvg fallback) into the appropriate escape stream. Lives next to `Charts\Picture` once that ships in Phase 6. |

#### View shape

| v2 feature | Status | Notes |
|---|---|---|
| `View()` returns `tea.View` struct (not `string`) | 🟢 | `Model::view()` returns `string\|View`. The `View` value object carries `body` + `cursor` + `windowTitle` + `progressBar` + `foregroundColor` + `backgroundColor` + `mouseMode` + `reportFocus` + `bracketedPaste`. Existing models that return `string` keep working unchanged (covariance). Alt-screen stays on `ProgramOptions` (it's a startup decision, not a per-frame choice). |
| `Cursor` struct (position, shape, blink, colour, nullable to hide) | ✅ | `Core\Cursor(row, col, shape, blink, color)` carried on the `View`. `Core\CursorShape` enum (`Block` / `Underline` / `Bar`); `Ansi::cursorShape()` emits DECSCUSR. A null `View::$cursor` hides the cursor; switching back from null re-shows it. |
| `WindowTitle` field — set via OSC 0/2 each frame | ✅ | `View::$windowTitle` — emitted via OSC 2 only when it changes between frames. `Cmd::setWindowTitle()` is still available for the imperative path. |
| Declarative `BackgroundColor` / `ForegroundColor` per frame | ✅ | `View::$foregroundColor` / `View::$backgroundColor` (`Color`) — emitted as `OSC 10` / `OSC 11` only when the value differs from the previously-emitted one. |
| `MouseMode` declared on the View instead of one-shot setup flag | ✅ | `View::$mouseMode` (`MouseMode` enum). Runtime emits the matching DEC private-mode pair only when the value differs from what's currently active; teardown disables whatever was last active rather than what `ProgramOptions` declared. `View::$reportFocus` and `View::$bracketedPaste` shipped alongside with the same semantics. |
| `ProgressBar` field — terminal native progress (OSC 9;4) | ✅ | `View::$progressBar` (`Progress` value object with `state` + `percent`) — emitted only when state-or-percent change between frames. The imperative `Cmd::setProgressBar()` is still available for non-View flows. |

#### Keys

| v2 feature | Status | Notes |
|---|---|---|
| Split `KeyMsg` into `KeyPressMsg` / `KeyReleaseMsg` (both still match `KeyMsg` interface) | ✅ | `KeyMsg` is no longer `final`; `KeyPressMsg`, `KeyReleaseMsg`, `KeyRepeatMsg` are empty marker subclasses. The Kitty per-key parser dispatches by event type (1 = press, 2 = repeat, 3 = release). Legacy CSI / SS3 keys still come through as plain `KeyMsg` so existing handlers are unchanged. |
| `Key::Code` (logical key) + `Key::Text` (typed text) — replaces `rune` | ✅ | `KeyMsg::text()` aliases `$rune` (empty for named keys); `KeyMsg::code()` aliases `$type`. `BaseCode` is unnecessary in PHP since named keys already use the enum and printable text uses Char. |
| `Key::Mod` unified bitfield instead of separate `alt` / `ctrl` booleans | ✅ | `KeyMsg::modifiers()` returns a `Modifiers` value object with `shift`/`alt`/`ctrl` plus `toBitfield()` (`SHIFT`/`ALT`/`CTRL` bit constants). The original `alt`/`ctrl` booleans remain for back-compat; `shift` is now also a constructor field. `Modifiers::fromXtermMod(int)` decodes the standard `1 + (1·shift + 2·alt + 4·ctrl)` byte. |
| `IsRepeat` flag | ✅ | Surfaced as `KeyRepeatMsg` (extending `KeyMsg`) when the Kitty parser sees event type 2. Match via `instanceof KeyRepeatMsg`. |
| `Key::Keystroke()` — string like `"ctrl+shift+a"` | ✅ | We already ship `KeyMsg::string()`. |
| Space returns `"space"` (not `" "`) from `Keystroke()` | ✅ | Already does. |
| Kitty progressive keyboard protocol — disambiguates `ctrl+m` vs Enter, etc. | ✅ | Handshake: `Cmd::pushKittyKeyboard($flags)` / `popKittyKeyboard($n=1)` / `requestKittyKeyboard()`. Reply `CSI ? <flags> u` → `KeyboardEnhancementsMsg` (with `DISAMBIGUATE` / `REPORT_EVENT_TYPES` / `REPORT_ALTERNATES` / `REPORT_ALL_AS_ESC` / `REPORT_ASSOCIATED` constants). Per-key event format `CSI <code>[;<mod>[:<event>]][;<text>] u` parses into `KeyPressMsg` / `KeyRepeatMsg` / `KeyReleaseMsg` with `Modifiers` populated and the typed-text leg used as the rune when present. |

#### Mouse + paste

| v2 feature | Status | Notes |
|---|---|---|
| Split `MouseMsg` into `MouseClickMsg` / `MouseReleaseMsg` / `MouseWheelMsg` / `MouseMotionMsg` | ✅ | `MouseMsg` is no longer `final`; four empty marker subclasses live under `Msg/`. `InputReader::decodeSgrMouse()` instantiates the right one from the SGR byte. The `action` enum stays for callers that prefer enum-based dispatch. |
| `PasteMsg::content` (we already match) | ✅ | Done. |
| `PasteStartMsg` / `PasteEndMsg` for *streaming* paste rendering | ✅ | `InputReader` emits `PasteStartMsg` as soon as `CSI 200 ~` is seen and `PasteEndMsg` immediately before the buffered `PasteMsg`. Models can flip a "paste in progress" flag, throttle validation, etc. without losing the existing single-shot `PasteMsg` API. |

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
| `SetClipboard(text)` / `ReadClipboard()` (OSC 52) | ✅ | `Cmd::setClipboard($text, $selection = 'c')` base64-encodes and emits `OSC 52 ; <sel> ; <b64> BEL` via a `RawMsg`. `Cmd::readClipboard($selection = 'c')` emits the `?` query; the input reader parses the OSC 52 reply (decoding base64) into `ClipboardMsg`. |
| `SetPrimaryClipboard(text)` (X11/Wayland primary selection) | ✅ | Same `Cmd::setClipboard` / `readClipboard` API — pass `'p'` for X11 primary, `'s'` for secondary, `0`–`7` for cut buffers. |

#### Import path

- ⚪ Vanity domain (`charm.land/bubbletea/v2`) — not applicable to PHP / Composer.

---

### CandySprinkles (← Lipgloss v2)

| v2 feature | Status | Notes |
|---|---|---|
| Lipgloss is now pure (no I/O) — Bubble Tea owns all I/O | ✅ | We always made `Style::render()` pure; no I/O at all. |
| `lipgloss.Color()` returns `color.Color` interface | ⚪ | We use `Color` value object directly; no migration. |
| `lipgloss.Println` / `Printf` / `Sprint` / `Fprint` writers | ✅ | All four shipped on `Sprinkles\Style`: `sprint(...)` (concatenates with spaces and renders), `printfSprint($fmt, ...)` (sprintf wrapper), `println(...)` / `print(...)` (write rendered output to STDOUT), `fprint($stream, ...)` (caller-supplied stream). |
| `HasDarkBackground(stdin, stdout)` | ✅ | `BackgroundColorMsg::isDark()` (relative-luminance Y < 0.5) on the OSC 11 reply parsed by CandyCore. Models call `Cmd::requestBackgroundColor()` from `init()` and check the reply. |
| `LightDark(isDark)` helper returning the right colour | ✅ | `Sprinkles\LightDark::pick(isDark, light, dark)` and `LightDark::picker(isDark)` (curried). Plus `Sprinkles\AdaptiveColor` value object and `Style::foregroundAdaptive()` / `backgroundAdaptive()` that resolve via `Style::resolveAdaptive(bool)` — explicit `foreground()` always wins, matching lipgloss precedence. |
| `Complete(profile)` colour completion | ✅ | `Sprinkles\CompleteColor` value object holding a TrueColor / ANSI256 / ANSI triple with `pick(ColorProfile)`. `Style::foregroundComplete()` / `backgroundComplete()` setters store it; `Style::resolveProfile()` collapses to concrete fg/bg using the live `colorProfile()`. Explicit colours win, mirroring lipgloss precedence. |
| `compat.AdaptiveColor` / `CompleteColor` / `CompleteAdaptiveColor` | ✅ | All three value objects ship in `Sprinkles\`: `AdaptiveColor` (light/dark pair, picks by `isDark` bool), `CompleteColor` (TrueColor/ANSI256/ANSI triple, picks by `ColorProfile`), and `CompleteAdaptiveColor` (combines both — picks the dark-bg or light-bg triple, then the right tier within it). |
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
2. ~~**Inline mode polish**: shrink the `Renderer` so non-alt-screen
   programs only own their own rows.~~ ✅ Shipped — `ProgramOptions::$inlineMode`
   flips the Renderer into save-cursor / restore-cursor mode so the
   user's scrollback stays intact. Pair with `useAltScreen=false`
   plus `Cmd::println` for CandyShell-style prompts.
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
7. **View struct + advanced compositing (the big one)**: 🟡 in progress.
   `Model::view()` now accepts `string\|View` (covariant — existing
   models keep returning string unchanged). Initial `View` carries
   `body` + `cursor` + `windowTitle` and the runtime only emits
   side-effect escapes on change. Remaining per-frame fields
   (alt screen / mouse mode / focus / progress bar / colour profile)
   migrate one-by-one in follow-ups. Advanced compositing (layered
   pop-overs) still scheduled for a CandyCore 2.0.
8. **Kitty keyboard protocol**: nice-to-have. Ship after the View
   struct so `KeyboardEnhancements` lives on the View where v2 puts
   it.

This phase is itself a candidate for incremental PRs — most of the
"cheap wins" are independent and can land one by one. **SugarCrush
(Phase 9+ #17) is a natural milestone**: targeting v2-equivalent parity
makes the AI-coding-agent port land on top of an already-modern
runtime.
