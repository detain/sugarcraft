# Upstream → SugarCraft matchup

The canonical map from each upstream Go (or other-language) project to its
SugarCraft port. Source-of-truth for **library identity**: when adding a
new port, link the upstream repo here first, then thread the same row
into [PROJECT_NAMES.md](./PROJECT_NAMES.md) and the website
([`docs/index.html`](./docs/index.html)).

> When this file changes, the corresponding row in
> [`docs/index.html`](./docs/index.html) (homepage lib / app grids) and
> the per-lib detail page under [`docs/lib/`](./docs/lib/) must update
> too. The contributor playbook in [`AGENTS.md`](./AGENTS.md) walks
> through the full add-a-lib flow.

Status legend:

- 🟢 v1 ready (public API + tests + docs + demo)
- 🟡 in progress (some surface shipped, gaps tracked in the audit)
- 🔴 planning (entry exists but no code yet)
- 🚀 split into its own repo (lives under `github.com/sugarcraft/<name>`)

---

## Charmbracelet libraries

| Upstream | SugarCraft port | Subdir | Composer pkg | Namespace | Status | Role |
|---|---|---|---|---|:---:|---|
| — (pioneering) | **CandyAsync** | `candy-async/` | `sugarcraft/candy-async` | `SugarCraft\Async` | 🚀 | Shared async vocabulary — CancellationToken, Subscriptions, AsyncOps (withTimeout, retry, debounce, throttle). No upstream parallel — shared foundation for ReactPHP usage across the monorepo. |
| [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea) | **SugarCraft** | `candy-core/` | `sugarcraft/candy-core` | `SugarCraft\Core` | 🟢 | Elm-architecture TUI runtime |
| [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss) | **CandySprinkles** | `candy-sprinkles/` | `sugarcraft/candy-sprinkles` | `SugarCraft\Sprinkles` | 🟢 | Declarative styling + layout |
| [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica) | **HoneyBounce** | `honey-bounce/` | `sugarcraft/honey-bounce` | `SugarCraft\Bounce` | 🟢 | Spring physics + Newtonian projectile sim |
| [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) | **CandyMouse** | `candy-mouse/` | `sugarcraft/candy-mouse` | `SugarCraft\Mouse` | 🟢 | Self-contained Mark/Scan/Get mouse hit-testing + ZoneClickTracker (bubblezone issue #10 fix) |
| [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) | **CandyZone** | `candy-zone/` | `sugarcraft/candy-zone` | `SugarCraft\Zone` | 🟢 | Mouse-zone tracker |
| [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) | **SugarBits** | `sugar-bits/` | `sugarcraft/sugar-bits` | `SugarCraft\Bits` | 🟡 | 14 prebuilt components (TextInput, ItemList, Table, …) |
| [NimbleMarkets/ntcharts](https://github.com/NimbleMarkets/ntcharts) | **SugarCharts** | `sugar-charts/` | `sugarcraft/sugar-charts` | `SugarCraft\Charts` | 🟡 | Sparkline / Bar / Line / Heatmap / Scatter / TimeSeries / OHLC / picture |
| [charmbracelet/huh](https://github.com/charmbracelet/huh) | **SugarPrompt** | `sugar-prompt/` | `sugarcraft/sugar-prompt` | `SugarCraft\Prompt` | 🟢 | Form library — Note / Input / Confirm / Select / MultiSelect / Text / FilePicker |
| [charmbracelet/glamour](https://github.com/charmbracelet/glamour) | **CandyShine** | `candy-shine/` | `sugarcraft/candy-shine` | `SugarCraft\Shine` | 🟡 | Markdown → ANSI renderer (themes, syntax, OSC 8 hyperlinks) |
| [charmbracelet/fang](https://github.com/charmbracelet/fang) | **CandyKit** | `candy-kit/` | `sugarcraft/candy-kit` | `SugarCraft\Kit` | 🟢 | CLI presentation helpers (StatusLine / Banner / Section / Stage / HelpText) |
| [charmbracelet/wish](https://github.com/charmbracelet/wish) | **CandyWish** | `candy-wish/` | `sugarcraft/candy-wish` | `SugarCraft\Wish` | 🟢 | SSH-server middleware framework (leans on host `sshd`); pluggable Transport: InProcessTransport (default — candy-pty supervisor + Spawn middleware) or HostSshdTransport (legacy, opt-in — inline middleware + BubbleTea) |
| [charmbracelet/promwish](https://github.com/charmbracelet/promwish) | **CandyMetrics** | `candy-metrics/` | `sugarcraft/candy-metrics` | `SugarCraft\Metrics` | 🟢 | Telemetry primitives + CandyWish session middleware |
| [charmbracelet/log](https://github.com/charmbracelet/log) | **CandyLog** | `candy-log/` | `sugarcraft/candy-log` | `SugarCraft\Log` | 🟢 | Minimal, colorful logging library |
| [charmbracelet/colorprofile](https://github.com/charmbracelet/colorprofile) | **CandyPalette** | `candy-palette/` | `sugarcraft/candy-palette` | `SugarCraft\Palette` | 🟢 | Terminal color detection + ICC profile handling |
| [charmbracelet/x/mosaic](https://github.com/charmbracelet/x/tree/main/mosaic) | **CandyMosaic** | `candy-mosaic/` | `sugarcraft/candy-mosaic` | `SugarCraft\Mosaic` | 🟢 | Image-to-cell renderer (Sixel, Kitty, iTerm2, Unicode half-block); Picker facade; ext-gd |
| [charmbracelet/x/vt](https://github.com/charmbracelet/x/tree/main/vt) | **CandyVt** | `candy-vt/` | `sugarcraft/candy-vt` | `SugarCraft\Vt` | 🟡 | In-memory virtual terminal emulator; ANSI byte stream → cell grid + cursor + mode state |
| [charmbracelet/x/ansi](https://github.com/charmbracelet/x/tree/main/ansi) | **CandyAnsi** | `candy-ansi/` | `sugarcraft/candy-ansi` | `SugarCraft\Ansi` | 🟢 | ANSI escape-sequence parser and state machine (SGR, cursor, erase, DEC modes); feeds into CandyVt for cell-grid rendering |
| [charmbracelet/vte](https://github.com/charmbracelet/vte) | **CandyBuffer** | `candy-buffer/` | `sugarcraft/candy-buffer` | `SugarCraft\Buffer` | 🟢 | Cell-grid value objects — Buffer (2-D cell grid) and Cell (rune/style/link/width); shared foundation for all rendering; Buffer::diff() ships in step-26 |
| [charmbracelet/x/vcr](https://github.com/charmbracelet/x/tree/main/vcr) | **CandyVcr** | `candy-vcr/` | `sugarcraft/candy-vcr` | `SugarCraft\Vcr` | 🟢 | Record + replay candy-core sessions — JSONL/YAML cassettes, Recorder hook, Player + Byte/Screen assertions, CLI |
| [charmbracelet/x/xpty](https://github.com/charmbracelet/x/tree/main/xpty) | **CandyPty** | `candy-pty/` | `sugarcraft/candy-pty` | `SugarCraft\Pty` | 🟢 | PTY primitive (Linux + macOS) — contract interfaces (`PtySystem` / `MasterPty` / `SlavePty` / `Child` / `Process` / `Pump` / `Termios`) + `PosixPtySystem`, `PosixPtyPair`, `PosixMasterPty`, `PosixSlavePty`, `PosixChild`, `PosixProcess` (non-PTY spawn), `PosixPump` (byte pump w/ EOF grace + SIGWINCH + keepalive callbacks), `ChildPollTrait` (shared `proc_get_status` lifecycle), `PosixTermios` (FFI) + `SttyTermios` (fallback), `TermiosFactory`, `SizeIoctl`, `SignalForwarder`, `PtySystemFactory` (DI-friendly resolution; `UnsupportedPlatformException` on Windows). Legacy facades (`Pty`, `Spawn`, `Child`) remain @deprecated through v1.x. Opt-in TIOCSCTTY via `controllingTerminal: true` (Ctrl+C → SIGINT) using `bin/pty-shim.php`; all syscalls via FFI to libc. |
| — (extracted from sugar-bits + sugar-prompt) | **CandyForms** | `candy-forms/` | `sugarcraft/candy-forms` | `SugarCraft\Forms` | 🟡 | Foundation: form primitives (TextInput, TextArea, ItemList, Viewport, FilePicker, Field interface, Confirm, Form) — extraction in progress |
| [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) (focus) — original | **CandyFocus** | `candy-focus/` | `sugarcraft/candy-focus` | `SugarCraft\Focus` | 🟢 | Dependency-free focus ring — ordered focusable regions with a single focused member + wrap-around Tab/Shift-Tab traversal. No 1:1 upstream; original SugarCraft component inspired by bubbles' focus handling and sugar-dash's FocusManager. |
| [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) (list/viewport) — original | **SugarGallery** | `sugar-gallery/` | `sugarcraft/sugar-gallery` | `SugarCraft\Gallery` | 🟢 | Poster grids & rails for media TUIs — a 2-D virtualized, sparse, absolute-indexed `PosterGrid` with owner-driven `need-range` paging (+ optional candy-zone mouse), a horizontal `Rail` carousel, and a renderer-agnostic `PosterCard` tile. No 1:1 upstream; original SugarCraft component inspired by bubbles' list/viewport and the phlix web media grid. |
| [sahilm/fuzzy](https://github.com/sahilm/fuzzy) (Go) + internal | **CandyFuzzy** | `candy-fuzzy/` | `sugarcraft/candy-fuzzy` | `SugarCraft\Fuzzy` | 🟢 | Fuzzy string matching with scored matched indices — Smith-Waterman + Sahilm algorithms. Unblocks filter-highlighting UI across the ecosystem. Extracted from candy-forms. |
| [ratatui/ratatui](https://github.com/ratatui/ratatui) | **CandyLayout** | `candy-layout/` | `sugarcraft/candy-layout` | `SugarCraft\Layout` | 🟡 | Foundation: Cassowary simplex + greedy constraint solvers for terminal layout |
| — (pioneering) | **CandyTesting** | `candy-testing/` | `sugarcraft/candy-testing` | `SugarCraft\Testing` | 🚀 | Test harness for TEA programs — ProgramSimulator, golden-file assertions, snapshot helpers. No upstream parallel — pioneering what bubble-tea issue #1654 never shipped. |
| — (pioneering) | **CandyInput** | `candy-input/` | `sugarcraft/candy-input` | `SugarCraft\Input` | 🚀 | Terminal escape sequence decoder — legacy keys, Kitty keyboard protocol (CSI ?u), SGR 1006 mouse, focus events, bracketed paste. No upstream parallel — the missing input layer for PHP TUI; unblocks sugar-readline production use. |
| [treilik/bubblelister](https://github.com/treilik/bubblelister) | **CandyLister** | `candy-lister/` | `sugarcraft/candy-lister` | `SugarCraft\Lister` | 🟢 |
| [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer) | **SugarBoxer** | `sugar-boxer/` | `sugarcraft/sugar-boxer` | `SugarCraft\Boxer` | 🟢 |
| [rmhubbert/bubbletea-overlay](https://github.com/rmhubbert/bubbletea-overlay) | **SugarVeil** | `sugar-veil/` | `sugarcraft/sugar-veil` | `SugarCraft\Veil` | 🟢 |
| [KevM/bubbleo](https://github.com/KevM/bubbleo) | **SugarCrumbs** | `sugar-crumbs/` | `sugarcraft/sugar-crumbs` | `SugarCraft\Crumbs` | 🟢 |
| [charmbracelet/bubble-grid](https://github.com/charmbracelet/bubble-grid) | **SugarDash** | `sugar-dash/` | `sugarcraft/sugar-dash` | `SugarCraft\Dash` | 🟢 |
| [Genekkion/theHermit](https://github.com/Genekkion/theHermit) | **CandyHermit** | `candy-hermit/` | `sugarcraft/candy-hermit` | `SugarCraft\Hermit` | 🟢 |
| [Evertras/bubble-table](https://github.com/Evertras/bubble-table) | **SugarTable** | `sugar-table/` | `sugarcraft/sugar-table` | `SugarCraft\Table` | 🟢 |
| [erikgeiser/promptkit](https://github.com/erikgeiser/promptkit) | **SugarReadline** | `sugar-readline/` | `sugarcraft/sugar-readline` | `SugarCraft\Readline` | 🟢 |
| [EthanEFung/bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker) | **SugarCalendar** | `sugar-calendar/` | `sugarcraft/sugar-calendar` | `SugarCraft\Calendar` | 🟢 |
| [DaltonSW/bubbleup](https://github.com/daltonsw/bubbleup) | **SugarToast** | `sugar-toast/` | `sugarcraft/sugar-toast` | `SugarCraft\Toast` | 🟢 |
| [76creates/stickers](https://github.com/76creates/stickers) | **SugarStickers** | `sugar-stickers/` | `sugarcraft/sugar-stickers` | `SugarCraft\Stickers` | 🟢 |

## Apps

| Upstream | SugarCraft port | Subdir | Composer pkg | Namespace | Status | Role |
|---|---|---|---|---|:---:|---|
| _starter scaffold_ | **CandyMold** | `candy-mold/` | `sugarcraft/candy-mold` | `App\` | 🟢 | `composer create-project` skeleton — counter Model + bin + tests |
| [charmbracelet/gum](https://github.com/charmbracelet/gum) | **CandyShell** | `candy-shell/` | `sugarcraft/candy-shell` | `SugarCraft\Shell` | 🟡 | Composer-installable CLI of 13 subcommands |
| [charmbracelet/freeze](https://github.com/charmbracelet/freeze) | **CandyFreeze** | `candy-freeze/` | `sugarcraft/candy-freeze` | `SugarCraft\Freeze` | 🟢 | Code → SVG screenshot (no GD / Imagick required) |
| [charmbracelet/glow](https://github.com/charmbracelet/glow) | **SugarGlow** | `sugar-glow/` | `sugarcraft/sugar-glow` | `SugarCraft\Glow` | 🟢 | Markdown CLI viewer / pager (consumes CandyShine) |
| [charmbracelet/sequin](https://github.com/charmbracelet/sequin) | **SugarSpark** | `sugar-spark/` | `sugarcraft/sugar-spark` | `SugarCraft\Spark` | 🟢 | ANSI escape-sequence inspector |
| [charmbracelet/wishlist](https://github.com/charmbracelet/wishlist) | **SugarWishlist** | `sugar-wishlist/` | `sugarcraft/sugar-wishlist` | `SugarCraft\Wishlist` | 🟢 | SSH endpoint launcher (YAML / JSON shortcuts directory) |
| [charmbracelet/skate](https://github.com/charmbracelet/skate) | **SugarSkate** | `sugar-skate/` | `sugarcraft/sugar-skate` | `SugarCraft\Skate` | 🟢 | Personal key/value store |
| [charmbracelet/pop](https://github.com/charmbracelet/pop) | **SugarPost** | `sugar-post/` | `sugarcraft/sugar-post` | `SugarCraft\Post` | 🟢 |
| [charmbracelet/soft-serve](https://github.com/charmbracelet/soft-serve) | **CandyServe** | `candy-serve/` | `sugarcraft/candy-serve` | `SugarCraft\Serve` | 🟢 |
| [charmbracelet/crush](https://github.com/charmbracelet/crush) | **SugarCrush** | `sugar-crush/` | `sugarcraft/sugar-crush` | `SugarCraft\Crush` | 🟢 | TUI AI coding agent — 7 providers, tools, skills, hooks, agents, MCP, SQLite sessions |
| [Broderick-Westrope/tetrigo](https://github.com/Broderick-Westrope/tetrigo) | **CandyTetris** | `candy-tetris/` | `sugarcraft/candy-tetris` | `SugarCraft\Tetris` | 🟢 | Tetris clone — SRS / 7-bag / NES scoring |
| [yorukot/superfile](https://github.com/yorukot/superfile) | **CandyFiles** | `candy-files/` | `sugarcraft/candy-files` | `SugarCraft\Files` | 🟢 | Dual-pane file manager |
| [jesseduffield/lazygit](https://github.com/jesseduffield/lazygit) | **SugarStash** | `sugar-stash/` | `sugarcraft/sugar-stash` | `SugarCraft\Stash` | 🟢 | Three-pane git TUI — shells out to `git` |
| [jorgerojas26/lazysql](https://github.com/jorgerojas26/lazysql) | **CandyQuery** | `candy-query/` | `sugarcraft/candy-query` | `SugarCraft\Query` | 🟢 | SQLite/MySQL/PostgreSQL browser TUI — schema introspection, query editor, EXPLAIN plans, server status, alerting, query history |
| [Rtarun3606k/TakaTime](https://github.com/Rtarun3606k/TakaTime) | **SugarTick** | `sugar-tick/` | `sugarcraft/sugar-tick` | `SugarCraft\Tick` | 🟢 | Privacy-first coding-time tracker — JSONL on disk |
| [maxpaulus43/go-sweep](https://github.com/maxpaulus43/go-sweep) | **CandyMines** | `candy-mines/` | `sugarcraft/candy-mines` | `SugarCraft\Mines` | 🟢 | Minesweeper — first-click safety / flood-fill |
| [namzug16/gifterm](https://github.com/namzug16/gifterm) | **CandyFlip** | `candy-flip/` | `sugarcraft/candy-flip` | `SugarCraft\Flip` | 🟢 | ASCII GIF viewer (ext-gd) |
| [maxcurzi/tplay](https://github.com/maxcurzi/tplay) · [seatedro/glyph](https://github.com/seatedro/glyph) · [joelibaceta/video-to-ascii](https://github.com/joelibaceta/video-to-ascii) | **SugarReel** | `sugar-reel/` | `sugarcraft/sugar-reel` | `SugarCraft\Reel` | 🟢 | Terminal video player (mp4 → ascii/ansi/half-block/sixel/kitty); no single upstream — ffmpeg pipe + pure-PHP GIF fallback, delta repaint, seek, speed, audio companion |
| [kbrgl/flapioca](https://github.com/kbrgl/flapioca) | **HoneyFlap** | `honey-flap/` | `sugarcraft/honey-flap` | `SugarCraft\Flap` | 🟢 | Flappy Bird clone — bird is a HoneyBounce projectile |

<!--
TODO(leftover-rollout step 01.01): When a Windows ConPTY row is added
to this table, uncomment the row below and point it at the Windows plan.
| TBD (ConPTY backend) | **CandyPty.Windows** | `candy-pty/` | `sugarcraft/candy-pty` | `SugarCraft\Pty\Windows` | 🔴 | Windows ConPTY backend — tracked in plans/x-windows.md |
-->

---

## Naming conventions (cheat sheet)

The SugarCraft brand has three prefixes — pick one when you add a new
port. Suffixes are short, technical, and describe the role.

| Prefix | Meaning | Example uses |
|---|---|---|
| **Candy-** | foundation / system / framework | runtime (SugarCraft), shell (CandyShell), markdown (CandyShine) |
| **Sugar-** | components / data / forms / apps | components (SugarBits), forms (SugarPrompt), charts (SugarCharts) |
| **Honey-** | math / physics / motion | spring physics (HoneyBounce), Flappy clone (HoneyFlap) |

`Candy-` (Files) is the file manager naming. Don't mint new prefixes without a discussion in
[`PROJECT_NAMES.md`](./PROJECT_NAMES.md).

---

## How to add a new row

1. Pick the upstream repo and the SugarCraft prefix + suffix following
   the cheat sheet above.
2. Add a row to the matching table here (libraries vs apps).
3. Add the same name + prefix discussion to
   [`PROJECT_NAMES.md`](./PROJECT_NAMES.md) — this is the canonical
   place for naming-decision history.
4. Follow the contributor playbook in [`AGENTS.md`](./AGENTS.md) for
   the rest of the integration (composer.json, examples, tests, docs,
   website tile, VHS demo).
