# SugarCraft library & app status

The authoritative index of every package in the SugarCraft project —
**41 libraries and 21 apps (62 packages total)** — with its status at a
glance. The final section,
[Inspirational reference projects](#inspirational-reference-projects),
maps each package back to the Go projects that sparked its design.

> When this file changes, the corresponding tile in
> [`docs/index.html`](./index.html) (homepage lib / app grids) and the
> per-package detail page under [`docs/lib/`](./lib/) must update too.
> The naming rulebook lives in
> [`PROJECT_NAMES.md`](../PROJECT_NAMES.md), and the contributor playbook
> in [`AGENTS.md`](../AGENTS.md) walks through the full add-a-package
> flow.

**Status legend**

- 🟢 ready — public API, tests, docs, and demo shipped
- 🟡 in progress — some surface shipped; remaining gaps listed in Notes
- 🔴 planning — entry exists but no code yet

Each row shows the package's subdir; the Composer name is always
`sugarcraft/<subdir>` and the namespace follows the naming rulebook
(`candy-sprinkles/` → `sugarcraft/candy-sprinkles` → `SugarCraft\Sprinkles\`).
Two namespaces intentionally don't read off their slug — `candy-core/`
ships as `SugarCraft\Core\`, and `candy-starter/` ships as `App\` — both are
called out in the table below.

---

## Libraries

| Library | Status | Notes |
|---|:---:|---|
| **SugarAsync** (`sugar-async/`) | 🟢 ready | Shared async vocabulary — CancellationToken, Subscriptions, AsyncOps (withTimeout, retry, debounce, throttle). The foundation for ReactPHP usage across the monorepo. |
| **SugarCraft** (`candy-core/`) | 🟢 ready | MVC-style Model–Update–View runtime — Model / Msg / Cmd / Program. Namespace is `SugarCraft\Core\` (exception to the naming rule). |
| **CandySprinkles** (`candy-sprinkles/`) | 🟢 ready | Declarative styling + layout. |
| **SugarSpring** (`sugar-spring/`) | 🟢 ready | Spring physics + Newtonian projectile sim. |
| **SugarMouse** (`sugar-mouse/`) | 🟢 ready | Self-contained Mark/Scan/Get mouse hit-testing + ZoneClickTracker for press/release deduplication, plus the bubblezone-style `Zones` Manager (hover / drag / multi-click trackers) under `SugarCraft\Mouse\Zones` — the mouse foundation the rest of the stack builds on. |
| **SugarWidgets** (`sugar-widgets/`) | 🟡 in progress | Seventeen prebuilt components (TextInput, ItemList, Table, …). |
| **SugarCharts** (`sugar-charts/`) | 🟡 in progress | Sparkline / Bar / Line / Heatmap / Scatter / TimeSeries / OHLC / picture. |
| **SugarForms** (`sugar-forms/`) | 🟢 ready | Form library — Note / Input / Confirm / Select / MultiSelect / Text / FilePicker / Date / Slider / Color. |
| **SugarMarkdown** (`sugar-markdown/`) | 🟡 in progress | Markdown → ANSI renderer (themes, syntax, OSC 8 hyperlinks). |
| **SugarCli** (`sugar-cli/`) | 🟢 ready | CLI presentation helpers (StatusLine / Banner / Section / Stage / HelpText). |
| **CandyWish** (`candy-wish/`) | 🟢 ready | SSH-server middleware framework (leans on host `sshd`); pluggable Transport: InProcessTransport (default — candy-pty supervisor + Spawn middleware) or HostSshdTransport (legacy, opt-in — inline middleware, including running SugarCraft apps in SSH sessions). |
| **SugarMetrics** (`sugar-metrics/`) | 🟢 ready | Telemetry primitives + CandyWish session middleware. |
| **SugarLog** (`sugar-log/`) | 🟢 ready | Minimal, colorful logging library. |
| **SugarColorprofile** (`sugar-colorprofile/`) | 🟢 ready | Terminal color detection + ICC profile handling. |
| **SugarImage** (`sugar-image/`) | 🟢 ready | Image-to-cell renderer (Sixel, Kitty, iTerm2, Unicode half-block); Picker facade; ext-gd. |
| **SugarAnimation** (`sugar-animation/`) | 🟢 ready | ANSI/GIF animation toolkit — GIF decode + frame playback (antecedent: gifterm) and `.ansi` art playback (`AnsiArt` / `ArtLibrary` / `ArtLoop`); solid + density render presets; ext-gd for GIFs. The viewer CLI lives in candy-gifview. |
| **SugarVterm** (`sugar-vterm/`) | 🟡 in progress | In-memory virtual terminal emulator; ANSI byte stream → cell grid + cursor + mode state. |
| **SugarAnsiParser** (`sugar-ansi-parser/`) | 🟢 ready | ANSI escape-sequence parser and state machine (SGR, cursor, erase, DEC modes); feeds into SugarVterm for cell-grid rendering. |
| **SugarCellbuffer** (`sugar-cellbuffer/`) | 🟢 ready | Cell-grid value objects — Buffer (2-D cell grid) and Cell (rune/style/link/width); shared foundation for all rendering, with minimal-repaint diffs via `Buffer::diff()`. |
| **CandyVcr** (`candy-vcr/`) | 🟢 ready | Record + replay candy-core sessions — JSONL/YAML cassettes, Recorder hook, Player + Byte/Screen assertions, CLI. |
| **CandyPty** (`candy-pty/`) | 🟢 ready | PTY primitive (Linux + macOS) — contract interfaces (`PtySystem` / `MasterPty` / `SlavePty` / `Child` / `Process` / `Pump` / `Termios`) + POSIX implementations, all syscalls via FFI to libc; opt-in controlling-terminal support. Legacy facades (`Pty`, `Spawn`, `Child`) remain @deprecated through v1.x. Windows ConPTY planned. |
| **SugarFocus** (`sugar-focus/`) | 🟢 ready | Dependency-free focus ring — ordered focusable regions with a single focused member + wrap-around Tab/Shift-Tab traversal. |
| **SugarGallery** (`sugar-gallery/`) | 🟢 ready | Poster grids & rails for media TUIs — a 2-D virtualized, sparse, absolute-indexed `PosterGrid` with owner-driven `need-range` paging (+ optional sugar-mouse mouse), a horizontal `Rail` carousel, and a renderer-agnostic `PosterCard` tile. |
| **SugarFuzzy** (`sugar-fuzzy/`) | 🟢 ready | Fuzzy string matching with scored matched indices — Smith–Waterman + Sahilm algorithms. Unblocks filter-highlighting UI across the ecosystem. Extracted from sugar-forms. |
| **CandyLayout** (`candy-layout/`) | 🟡 in progress | Foundation: Cassowary simplex + greedy constraint solvers for terminal layout. |
| **SugarTesting** (`sugar-testing/`) | 🟢 ready | Test harness for SugarCraft apps — ProgramSimulator, golden-file assertions, snapshot helpers. |
| **SugarInput** (`sugar-input/`) | 🟢 ready | Terminal escape sequence decoder — legacy keys, Kitty keyboard protocol (CSI ?u), SGR 1006 mouse, focus events, bracketed paste. Unblocks sugar-readline production use. |
| **SugarLister** (`sugar-treelist/`) | 🟢 ready | Tree/list view with box-drawing prefixes, cursor navigation, word-wrap, and filter-as-you-type. |
| **SugarPanels** (`sugar-panels/`) | 🟢 ready | Box-drawing layout engine — H/V panel composition with weighted sizing, borders, and nested grids. |
| **SugarOverlay** (`sugar-overlay/`) | 🟢 ready | Terminal overlay compositor — push/pop overlay views with z-ordering, positioning, and per-overlay teardown. |
| **SugarNavstack** (`sugar-navstack/`) | 🟢 ready | Navigation breadcrumbs — immutable NavStack with push/pop, shell-change detection, and type-ahead filter. |
| **SugarDashboard** (`sugar-dashboard/`) | 🟢 ready | Dashboard TUI library — column grid layout, framed panels, status bar, tabs, and more. |
| **SugarQuickpick** (`sugar-quickpick/`) | 🟢 ready | Fuzzy finder overlay — renders over your app's view string while the background keeps updating; type to filter, arrow keys to select, Enter to confirm. |
| **SugarTable** (`sugar-table/`) | 🟢 ready | Full-featured interactive data table — column definitions, StyledCell ANSI formatting, pagination, frozen rows/cols. |
| **SugarReadline** (`sugar-readline/`) | 🟢 ready | Interactive prompts — Text, Confirm, Selection, MultiSelect, Textarea. State-machine model, no external readline dependency. |
| **SugarDatepicker** (`sugar-datepicker/`) | 🟢 ready | Interactive month-grid date picker — keyboard navigation, min/max date constraints, locale day names, ANSI rendering. |
| **SugarToast** (`sugar-toast/`) | 🟢 ready | Floating notification overlays — Info / Success / Warning / Error types, configurable position and auto-dismiss. |
| **SugarFlexbox** (`sugar-flexbox/`) | 🟢 ready | FlexBox layout engine + simple sort/filter table — ratio-based sizing, gap, justify, align, per-column styling. |
| **SugarDiff** (`sugar-diff/`) | 🟢 ready | Unified-diff engine — LCS line diff, context hunks, GNU `diff -u` writer + line-number scanner. Extracted from sugar-crush (BuildsUnifiedDiff + DiffGutter region model). |
| **Sugar MCP Client** (`sugar-mcp-client/`) | 🟢 ready | MCP client core — JSON-RPC 2.0 codec + envelopes, stdio transport (newline framing, bounded lifecycle via candy-core BoundedShutdown), initialize/tools handshake, deny-before-allow tool narrowing. Extracted from sugar-crush MCP stack; protocol per Model Context Protocol spec. |

## Apps

| App | Status | Notes |
|---|:---:|---|
| **CandyStarter** (`candy-starter/`) | 🟢 ready | `composer create-project` skeleton — counter Model + bin + tests. Namespace is `App\` (exception to the naming rule). |
| **CandyScriptui** (`candy-scriptui/`) | 🟡 in progress | Composer-installable CLI of 13 one-shot subcommands. |
| **CandyCodeshot** (`candy-codeshot/`) | 🟢 ready | Code → SVG screenshot (no GD / Imagick required). |
| **CandyMdview** (`candy-mdview/`) | 🟢 ready | Markdown CLI viewer / pager (consumes SugarMarkdown). |
| **CandyAnsiInspect** (`candy-ansi-inspect/`) | 🟢 ready | ANSI escape-sequence inspector. |
| **CandySshLauncher** (`candy-ssh-launcher/`) | 🟢 ready | SSH endpoint launcher (YAML / JSON shortcuts directory). |
| **CandyKv** (`candy-kv/`) | 🟢 ready | Personal key/value store — one SQLite database per store file, glob listing, TTL, JSON/YAML import/export. |
| **CandyMail** (`candy-mail/`) | 🟢 ready | Email sending — SMTP + Resend API transports, attachments, HTML + plain-text multipart, fluent interface. |
| **CandyGitServer** (`candy-git-server/`) | 🟢 ready | Self-hostable Git server over SSH (authorized keys), Git daemon, and HTTP — users, repos, access control, optional LFS. |
| **SugarCrush** (`sugar-crush/`) | 🟢 ready | TUI AI coding agent — 7 providers, tools, skills, hooks, agents, MCP, SQLite sessions. |
| **SugarCrushWeb** (`sugar-crush-web/`) | 🟡 in progress | Browser UI for sugar-crush's WebSocket server mode — multi-session dashboard, approvals, settings; Vite + Vue 3, committed `dist/` + one-class PHP shim. MVP today (one session: transcript, tool cards, diffs, approvals, composer). |
| **CandyTetris** (`candy-tetris/`) | 🟢 ready | Tetris clone — SRS / 7-bag / NES scoring. |
| **CandyFiles** (`candy-files/`) | 🟢 ready | Dual-pane file manager. |
| **CandyGit** (`candy-git/`) | 🟢 ready | Three-pane git TUI — shells out to `git`. |
| **CandySql** (`candy-sql/`) | 🟢 ready | SQLite/MySQL/PostgreSQL client TUI — schema introspection, query editor, EXPLAIN plans, server status, alerting, query history. |
| **CandyCodetime** (`candy-codetime/`) | 🟢 ready | Privacy-first coding-time tracker — JSONL on disk. |
| **CandyMinesweeper** (`candy-minesweeper/`) | 🟢 ready | Minesweeper — first-click safety / flood-fill. |
| **CandyGifView** (`candy-gifview/`) | 🟢 ready | Terminal GIF + .ansi-art viewer CLI (ext-gd via sugar-animation) — port of gifterm. |
| **SugarVideo** (`sugar-video/`) | 🟢 ready | Terminal video player (mp4 → ascii/ansi/half-block/sixel/kitty) — ffmpeg pipe + pure-PHP GIF fallback, delta repaint, seek, speed, audio companion. |
| **CandyTop** (`candy-top/`) | 🟢 ready | Terminal system monitor — cpu / mem+disks / net / process boxes, battery + NVIDIA GPU readouts, braille, block and sextant graphs, menus, presets, 43 themes, btop-compatible config with save/reload; Linux `/proc`+`/sys` and FreeBSD collectors; container/VM tags; adopted btop upstream PRs. |
| **CandyFlappy** (`candy-flappy/`) | 🟢 ready | Flappy Bird clone — the bird is a SugarSpring projectile. |

<!-- Windows ConPTY backend for candy-pty: tracked as a future row. -->

---

## Naming conventions (cheat sheet)

The SugarCraft brand has three prefixes — pick one when you add a new
package. Suffixes are short, technical, and describe the role.

| Prefix | Meaning | Example uses |
|---|---|---|
| **Candy-** | foundation / system / framework | runtime (SugarCraft), shell (CandyScriptui), markdown (SugarMarkdown) |
| **Sugar-** | components / data / forms / apps | components (SugarWidgets), charts (SugarCharts) |
| **Honey-** | math / physics / motion | spring physics (SugarSpring), Flappy clone (CandyFlappy) |

`Candy-` (Files) is the file manager naming. Don't mint new prefixes without a discussion in
[`PROJECT_NAMES.md`](../PROJECT_NAMES.md).

`Candy-` (Top) is a recorded naming exception: a system-monitor app would read `Sugar-` by the
apps heuristic, but the owner chose `candy-top` (a system monitor is "system" in the `Candy-`
sense). The ruling and both sides of the argument live in [`PROJECT_NAMES.md`](../PROJECT_NAMES.md).

---

## How to add a new row

1. Pick the SugarCraft prefix + suffix following the cheat sheet above,
   and the capability prose for its Notes cell.
2. Add a row to the matching table here (libraries vs apps).
3. Add the same name + prefix discussion to
   [`PROJECT_NAMES.md`](../PROJECT_NAMES.md) — this is the canonical
   place for naming-decision history.
4. If an external project sparked the idea, add (or extend) its row in
   the Inspirational reference projects table below.
5. Follow the contributor playbook in [`AGENTS.md`](../AGENTS.md) for
   the rest of the integration (composer.json, examples, tests, docs,
   website tile, VHS demo).

---

## Inspirational reference projects

SugarCraft is a PHP-native project. Its original design inspiration came
from the Go terminal ecosystem — notably
[Bubble Tea](https://github.com/charmbracelet/bubbletea) and the wider
[Charm](https://github.com/charmbracelet) family of libraries —
reimagined here for PHP 8.3+. The table is an idea map only: the
reference projects supplied the ideas, not the code.

| SugarCraft package | Ideas sparked by |
|---|---|
| SugarAnsiParser (sugar-ansi-parser/) | [x/ansi](https://github.com/charmbracelet/x/tree/main/ansi) |
| SugarAsync (sugar-async/) | none — first-party |
| SugarCellbuffer (sugar-cellbuffer/) | [vte](https://github.com/charmbracelet/vte) |
| CandyFiles (candy-files/) | [superfile](https://github.com/yorukot/superfile) |
| CandyGifView (candy-gifview/) | [gifterm](https://github.com/namzug16/gifterm) |
| SugarFocus (sugar-focus/) | [bubbles](https://github.com/charmbracelet/bubbles) (focus behavior) |
| CandyCodeshot (candy-codeshot/) | [freeze](https://github.com/charmbracelet/freeze) |
| SugarFuzzy (sugar-fuzzy/) | [fuzzy](https://github.com/sahilm/fuzzy) (+ internal algorithms) |
| SugarQuickpick (sugar-quickpick/) | [theHermit](https://github.com/Genekkion/theHermit) |
| SugarInput (sugar-input/) | none — first-party |
| SugarCli (sugar-cli/) | [fang](https://github.com/charmbracelet/fang) |
| CandyLayout (candy-layout/) | [ratatui](https://github.com/ratatui/ratatui) |
| SugarTreelist (sugar-treelist/) | [bubblelister](https://github.com/treilik/bubblelister) |
| SugarLog (sugar-log/) | [log](https://github.com/charmbracelet/log) |
| SugarMetrics (sugar-metrics/) | [promwish](https://github.com/charmbracelet/promwish) |
| CandyMinesweeper (candy-minesweeper/) | [go-sweep](https://github.com/maxpaulus43/go-sweep) |
| CandyStarter (candy-starter/) | none — first-party |
| SugarMouse (sugar-mouse/) | [bubblezone](https://github.com/lrstanley/bubblezone) |
| SugarImage (sugar-image/) | [x/mosaic](https://github.com/charmbracelet/x/tree/main/mosaic) |
| SugarColorprofile (sugar-colorprofile/) | [colorprofile](https://github.com/charmbracelet/colorprofile) |
| CandyPty (candy-pty/) | [x/xpty](https://github.com/charmbracelet/x/tree/main/xpty) |
| CandySql (candy-sql/) | [lazysql](https://github.com/jorgerojas26/lazysql) |
| CandyGitServer (candy-git-server/) | [soft-serve](https://github.com/charmbracelet/soft-serve) |
| CandyScriptui (candy-scriptui/) | [gum](https://github.com/charmbracelet/gum) |
| SugarMarkdown (sugar-markdown/) | [glamour](https://github.com/charmbracelet/glamour) |
| CandySprinkles (candy-sprinkles/) | [lipgloss](https://github.com/charmbracelet/lipgloss) |
| SugarTesting (sugar-testing/) | none — first-party |
| CandyTetris (candy-tetris/) | [tetrigo](https://github.com/Broderick-Westrope/tetrigo) |
| CandyTop (candy-top/) | [btop](https://github.com/aristocratos/btop) |
| CandyVcr (candy-vcr/) | [x/vcr](https://github.com/charmbracelet/x/tree/main/vcr) |
| SugarVterm (sugar-vterm/) | [x/vt](https://github.com/charmbracelet/x/tree/main/vt) |
| CandyWish (candy-wish/) | [wish](https://github.com/charmbracelet/wish) |
| SugarSpring (sugar-spring/) | [harmonica](https://github.com/charmbracelet/harmonica) |
| CandyFlappy (candy-flappy/) | [flapioca](https://github.com/kbrgl/flapioca) |
| SugarWidgets (sugar-widgets/) | [bubbles](https://github.com/charmbracelet/bubbles) |
| SugarPanels (sugar-panels/) | [bubbleboxer](https://github.com/treilik/bubbleboxer) |
| SugarDatepicker (sugar-datepicker/) | [bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker) |
| SugarCharts (sugar-charts/) | [ntcharts](https://github.com/NimbleMarkets/ntcharts) |
| SugarCraft (candy-core/) | [Bubble Tea](https://github.com/charmbracelet/bubbletea) |
| SugarCrush (sugar-crush/) | [Crush](https://github.com/charmbracelet/crush) |
| SugarCrushWeb (sugar-crush-web/) | [opencode](https://github.com/sst/opencode) web UI · OpenClaw Control UI (loosely) |
| SugarNavstack (sugar-navstack/) | [bubbleo](https://github.com/KevM/bubbleo) |
| SugarDashboard (sugar-dashboard/) | [bubble-grid](https://github.com/charmbracelet/bubble-grid) |
| SugarDiff (sugar-diff/) | none — first-party |
| SugarGallery (sugar-gallery/) | [bubbles](https://github.com/charmbracelet/bubbles) (list/viewport) · phlix web media grid (loosely) |
| CandyMdview (candy-mdview/) | [glow](https://github.com/charmbracelet/glow) |
| Sugar MCP Client (sugar-mcp-client/) | none — first-party |
| CandyMail (candy-mail/) | [pop](https://github.com/charmbracelet/pop) |
| SugarForms (sugar-forms/) | [huh](https://github.com/charmbracelet/huh) |
| SugarReadline (sugar-readline/) | [promptkit](https://github.com/erikgeiser/promptkit) |
| SugarVideo (sugar-video/) | [tplay](https://github.com/maxcurzi/tplay) · [glyph](https://github.com/seatedro/glyph) · [video-to-ascii](https://github.com/joelibaceta/video-to-ascii) |
| CandyKv (candy-kv/) | [skate](https://github.com/charmbracelet/skate) |
| CandyAnsiInspect (candy-ansi-inspect/) | [sequin](https://github.com/charmbracelet/sequin) |
| CandyGit (candy-git/) | [lazygit](https://github.com/jesseduffield/lazygit) |
| SugarFlexbox (sugar-flexbox/) | [stickers](https://github.com/76creates/stickers) |
| SugarTable (sugar-table/) | [bubble-table](https://github.com/Evertras/bubble-table) |
| CandyCodetime (candy-codetime/) | [TakaTime](https://github.com/Rtarun3606k/TakaTime) |
| SugarToast (sugar-toast/) | [bubbleup](https://github.com/DaltonSW/bubbleup) |
| SugarOverlay (sugar-overlay/) | [bubbletea-overlay](https://github.com/rmhubbert/bubbletea-overlay) |
| CandySshLauncher (candy-ssh-launcher/) | [wishlist](https://github.com/charmbracelet/wishlist) |

The table above lists every package in the two status tables — 41
libraries and 21 apps, 62 rows total. Several projects outside Charm's
orbit inspired individual packages; each package's README carries its own
attribution.
