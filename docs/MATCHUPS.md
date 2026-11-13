# SugarCraft library & app status

The authoritative index of every package in the SugarCraft project —
**42 libraries and 22 apps (64 packages total)** — with its status at a
glance. The final section,
[Inspirational reference projects](#inspirational-reference-projects),
maps each package back to its Go upstream.

SugarCraft is a set of PHP ports of the Charmbracelet TUI ecosystem, reimplemented class-for-class where the idiom fits (the upstream is cited in every `Mirrors charmbracelet/<repo>.<Method>` docblock); where no upstream exists the library is original work.

> When this file changes, the matching row in the root
> [`README.md`](../README.md) and the catalog card on
> [`docs/libraries.html`](./libraries.html) / [`docs/apps.html`](./apps.html)
> must track it too. The per-package pages under [`docs/lib/`](./lib/) are
> generated from [`docs/_data/`](./_data/) (`<slug>.json` +
> `<slug>.body.html`) by `php tools/gen-docs.php` — never hand-edit them.
> The naming rulebook lives in
> [`PROJECT_NAMES.md`](../PROJECT_NAMES.md), and the contributor playbook
> in [`AGENTS.md`](../AGENTS.md) walks through the full add-a-package
> flow.

**Status legend**

- 🟢 ready — public API, tests, docs, and demo shipped
- 🟡 in progress — some surface shipped; remaining gaps listed in Notes
- 🔴 planning — entry exists but no code yet

Each row shows the package's subdir; the Composer name is always
`sugarcraft/<subdir>` and the namespace takes the descriptive suffix
(`sugar-style/` → `sugarcraft/sugar-style` → `SugarCraft\Style\`; the
runtime's `sugar-core/` → `SugarCraft\Core\` follows the same rule).
One namespace intentionally doesn't read off its slug — `candy-starter/`
ships as `App\` — called out in the table below.

---

## Libraries

| Library | Status | Notes |
|---|:---:|---|
| **SugarAsync** (`sugar-async/`) | 🟢 ready | Shared async vocabulary — CancellationToken, Subscriptions, AsyncOps (withTimeout, withDeadline, cancellable, retry, debounce, throttle, all/race, onPending, singleFlight), TTL async cache. Dependents: sugar-forms, sugar-vterm, candy-sql, candy-mail, candy-git-server, candy-codetime. |
| **SugarCraft** (`sugar-core/`) | 🟢 ready | MVC-style Model–Update–View runtime — Model / Msg / Cmd / Program. Namespace follows the suffix rule (`sugar-core` → `SugarCraft\Core\`). |
| **SugarStyle** (`sugar-style/`) | 🟢 ready | Declarative styling + layout. |
| **SugarSpring** (`sugar-spring/`) | 🟢 ready | Damped spring physics + Newtonian projectile simulation. |
| **SugarMouse** (`sugar-mouse/`) | 🟢 ready | Self-contained mouse hit-testing (tag the output, scan the frame, look up the id) + ZoneClickTracker for press/release deduplication, plus the absorbed bubblezone-style `Zones` facade and the `Zones\Manager` layer (hover / drag / multi-click trackers) under `SugarCraft\Mouse\Zones` — the mouse foundation its TUI consumers build on. Pointer-driven; keyboard paths are consumer-owned — see sugar-mouse README Accessibility. |
| **SugarWidgets** (`sugar-widgets/`) | 🟡 in progress | First-party components — Tabs, Tree, Table, Progress, Help, Key, Paginator, Stopwatch, Timer, Input\TextEdit, Menu\OptionRow — plus render helpers; the 8 alias families (Cursor, FilePicker, ItemList, Scrollbar, Spinner, TextArea, TextInput, Viewport) are deprecated re-exports of sugar-forms. Unported bubbles components, each RULED not overlooked: Button and Rule — SugarCraft originals shipped in this lib (W-29; upstream bubbles has no Button or Ruler, the antecedent is tview's `AddButton`/separators): `Button\Button` (focusable `[ Label ]`, Enter/Space/click → `ButtonPressedMsg`) and `Rule\Rule` (horizontal/vertical divider; `Rule`, not `Ruler`, names it); Caret — role covered by the Input\TextEdit grapheme caret of this lib, no standalone port; Checkbox — semantics carried by sugar-forms Field\Confirm/Field\MultiSelect, no sugar-checkbox slug; Group — composition handled by sugar-flexbox + per-component containment. OPEN ruling 1 (owner-flippable): bubbles Delegate vocabulary — (A) adopt a shared delegate-chain architecture vs (B) permanent per-component routing (today B ships via Key\KeyMap + the README msg-routing cheat-sheet); status OPEN. OPEN ruling 2: the three components above (Caret, Checkbox, Group) are ruled-out today; a future-feature filing may flip any one. |
| **SugarCharts** (`sugar-charts/`) | 🟢 ready | Sparkline / Bar / Line / Heatmap / Scatter / TimeSeries / Streamline / Waveline / OHLC / Picture + Aggregation toolbox. OHLC volume pane and Scatter per-point styles pending. |
| **SugarForms** (`sugar-forms/`) | 🟢 ready | Form library — Note / Input / Confirm / Select / MultiSelect / Text / FilePicker / Date / Slider / Color / NumberField / RadioPills. |
| **SugarMarkdown** (`sugar-markdown/`) | 🟢 ready | Markdown → ANSI renderer (themes, syntax, OSC 8 hyperlinks). |
| **SugarCli** (`sugar-cli/`) | 🟢 ready | CLI presentation helpers (StatusLine / Banner / Section / Stage / HelpText / Frame / Logo / themes). |
| **SugarSshServer** (`sugar-ssh-server/`) | 🟢 ready | SSH-server middleware framework (leans on host `sshd`); pluggable Transport: InProcessTransport (default — sugar-pty supervisor + Spawn middleware) or HostSshdTransport (legacy, opt-in — inline middleware, including running SugarCraft apps in SSH sessions). |
| **SugarMetrics** (`sugar-metrics/`) | 🟢 ready | Telemetry primitives + SugarSshServer session middleware. |
| **SugarLog** (`sugar-log/`) | 🟢 ready | Minimal, colorful logging library. |
| **SugarColorprofile** (`sugar-colorprofile/`) | 🟢 ready | Terminal color profile detection and color degradation: one-way ladder TrueColor→Ansi256→Ansi240→Ansi→Ansi8→Fgbg→Ascii→NoTTY (detection itself emits five levels: TrueColor/Ansi256/Ansi/Ascii/NoTTY), plus an infocmp capability probe (reads TERM/COLORTERM/NO_COLOR…, may exec infocmp). |
| **SugarImage** (`sugar-image/`) | 🟢 ready | Image-to-cell renderer (Sixel, Kitty, iTerm2, Unicode half-block, coverage-class symbol map: braille/sextant/octant/block glyphs via Hamming-distance candidate search — the chafa symbol engine ported); Mosaic facade (the ratatui-image 'Picker' counterpart); ext-gd. |
| **SugarAnimation** (`sugar-animation/`) | 🟢 ready | ANSI/GIF animation toolkit — GIF decode + frame playback (antecedent: gifterm) and `.ansi` art playback (`AnsiArt` / `ArtLibrary` / `ArtLoop`); solid + density render presets; ext-gd for GIFs. The viewer CLI lives in candy-gifview. |
| **SugarVideo** (`sugar-video/`) | 🟢 ready | Terminal video player (mp4/webm/avi/gif/webp(animated) → ascii/ansi/half-block/quarterblock/sixel/kitty/iTerm2) — ffmpeg pipe + pure-PHP GIF and animated-WebP decode (no ffmpeg child for those routes), delta repaint, seek, speed, audio companion. |
| **SugarVterm** (`sugar-vterm/`) | 🟢 ready | In-memory virtual terminal emulator; ANSI byte stream → cell grid + cursor + mode state. The byte stream is modelled as hostile input: parser caps are enforced on ingest, and consumers that print cells carry their own sanitize duties. Done as of 2026-11-05: fully typed `src/`, 68-file suite (fuzzer and differential tests, golden fixtures), PHPStan at max, `.vhs` demo wired into the render matrix, a generated in-sync site page, and a production consumer in candy-vcr; zero TODO/FIXME markers. |
| **SugarAnsiParser** (`sugar-ansi-parser/`) | 🟢 ready | ANSI escape-sequence parser and state machine (SGR, cursor, erase, DEC modes); feeds into SugarVterm for cell-grid rendering. |
| **SugarCellbuffer** (`sugar-cellbuffer/`) | 🟢 ready | Cell-grid value objects — Buffer (2-D cell grid) and Cell (rune/style/link/width); shared foundation for all rendering, with minimal-repaint diffs via `Buffer::diff()`. |
| **SugarPty** (`sugar-pty/`) | 🟢 ready | PTY primitive (Linux + macOS + Windows) — contract interfaces (`PtySystem` / `MasterPty` / `SlavePty` / `Child` / `Process` / `Pump` / `Termios`) + POSIX implementations over FFI to libc, with sanctioned `stty(1)` shell-out fallbacks on macOS (winsize read/write both directions) and a `posix_kill`-preferring kill path; opt-in controlling-terminal support. Legacy facades (`Pty`, `Child`) remain @deprecated through v1.x; `Spawn` is @internal live plumbing. Windows ConPTY landed (`Windows\ConPtySystem` over FFI primitives: Windows 10 1809+ / PHP 8.4+; no ctty/signals/fd — POSIX keeps those). |
| **SugarFocus** (`sugar-focus/`) | 🟢 ready | Dependency-free focus ring — ordered focusable regions with a single focused member + wrap-around Tab/Shift-Tab traversal. |
| **SugarGallery** (`sugar-gallery/`) | 🟢 ready | Poster grids & rails for media TUIs — a 2-D virtualized, sparse, absolute-indexed `PosterGrid` with owner-driven `need-range` paging (+ optional sugar-mouse), a horizontal `Rail` carousel, and a renderer-agnostic `PosterCard` tile. |
| **SugarFuzzy** (`sugar-fuzzy/`) | 🟢 ready | Fuzzy string matching with scored matched indices — Smith–Waterman + Sahilm algorithms. Unblocks filter-highlighting UI across the ecosystem. Extracted from sugar-forms. |
| **SugarConstraints** (`sugar-constraints/`) | 🟢 ready | Foundation: greedy constraint solver (`GreedySolver`) for terminal layout — `CassowarySolver` is a deprecated shim over it. Plus `DockLayout` pane geometry. |
| **SugarTesting** (`sugar-testing/`) | 🟢 ready | Test harness for SugarCraft programs — scripted input, golden snapshots, cell-grid assertions, program simulation, VHS tape building, and graphics-stream decoding. |
| **SugarInput** (`sugar-input/`) | 🟢 ready | Terminal escape sequence decoder — legacy keys (incl. xterm modified forms `CSI number;mods ~`), Kitty keyboard protocol event frames `CSI code;mods u` (the `CSI ?…u` shape is the flags reply family), SGR 1006/1016 + urxvt 1015 + X10 mouse, focus events, bracketed paste. Unblocks sugar-readline production use. |
| **SugarTreelist** (`sugar-treelist/`) | 🟢 ready | List view with box-drawing prefixes, cursor navigation, word-wrap, and live filtering (host-driven). |
| **SugarPanels** (`sugar-panels/`) | 🟢 ready | H/V panels with flex/natural sizing, borders, nesting, and stateful delta rendering. |
| **SugarOverlay** (`sugar-overlay/`) | 🟢 ready | Terminal overlay compositor — composites Veil overlays with backdrop dimming, z-stack ordering, positioning, and click-outside hit-testing; opt-in `Veil::withUntrustedContent()` at the veil boundary. |
| **SugarNavstack** (`sugar-navstack/`) | 🟢 ready | Navigation breadcrumbs — mutable NavStack with explicit push/pop, width-aware breadcrumbs, and substring filter. |
| **SugarDashboard** (`sugar-dashboard/`) | 🟢 ready | Dashboard TUI library — column grid, constraint layout, ratio grid, data grid, tree, modal, status bar, tabs, plot/canvas, and a module/plugin system. |
| **SugarQuickpick** (`sugar-quickpick/`) | 🟢 ready | Fuzzy finder / quick-pick overlay — embeds into your app via the package-local Model contract: your loop decodes keys and forwards them (type()/cursorUp()/Down(); Enter reads selected()); renders over your app's view string while the background keeps updating. |
| **SugarTable** (`sugar-table/`) | 🟢 ready | Customizable data table — column definitions, StyledCell ANSI formatting, pagination, frozen columns. Keyboard-driven; wire sugar-input/sugar-mouse events yourself. |
| **SugarReadline** (`sugar-readline/`) | 🟢 ready | Interactive prompts — Text, Confirm, Selection, MultiSelect, Textarea. State-machine model, no external readline dependency. |
| **SugarDatepicker** (`sugar-datepicker/`) | 🟢 ready | Interactive month-grid date picker — keyboard navigation, min/max date constraints, locale-ready day names (English ships today), ANSI rendering; weeks start Sunday (firstDayOfWeek is not configurable). |
| **SugarToast** (`sugar-toast/`) | 🟢 ready | Floating notification overlays — Error / Warning / Info / Success types, configurable position and host-driven auto-dismiss. |
| **SugarFlexbox** (`sugar-flexbox/`) | 🟢 ready | FlexBox layout engine + delta-rendering Table (sort/filter/cursor) + sticky Viewport + Scrollbar — ratio-based sizing, gap, align, per-column styling. |
| **SugarDiff** (`sugar-diff/`) | 🟢 ready | Unified-diff engine — LCS line diff, context hunks, GNU `diff -u` writer + line-number scanner. Extracted from candy-code (BuildsUnifiedDiff; candy-code's DiffGutter delegates its scan half here). |
| **SugarMCPClient** (`sugar-mcp-client/`) | 🟢 ready | MCP client core — JSON-RPC 2.0 codec + envelopes, stdio transport (newline framing, bounded lifecycle via sugar-core BoundedShutdown), initialize/tools handshake, deny-before-allow SERVER narrowing (tool rosters follow servers). Extracted from candy-code MCP stack; protocol per Model Context Protocol spec. |
| **SugarReleases** (`sugar-releases/`) | 🟢 ready | Phar self-update engine + launch-time update notice — GitHub release selection (stable + rolling latest channel), sha256-sidecar + Ed25519 verification before the swap, one-shot `.old` rollback, Windows relaunch-dance swap helper. Extracted from candy-code's `SelfUpdate`/`UpdateCheck`/`WindowsSwap` (owner decision G-10=A); candy-top is the second consumer. |
| **SugarIgnore** (`sugar-ignore/`) | 🟢 ready | gitignore-style ignore matching over injected file contents (no IO) — negation, dir-only, anchoring, `**`, escapes, nested ignore files, `.git/info/exclude` precedence, backtrack-free matcher that fails closed on hostile patterns. Extracted from candy-code's `IgnoreRules` (owner decision G-10=A); candy-git, candy-files and candy-git-server are the intended second consumers. |

## Apps

| App | Status | Notes |
|---|:---:|---|
| **CandyStarter** (`candy-starter/`) | 🟢 ready | `composer create-project` skeleton — counter Model + bin + tests. Namespace is `App\` (exception to the naming rule). |
| **CandyScriptui** (`candy-scriptui/`) | 🟢 ready | Composer-installable CLI of 13 presentation subcommands. |
| **CandyCodeshot** (`candy-codeshot/`) | 🟢 ready | Code → SVG screenshot (+ optional PNG via ext-gd). |
| **CandyMdview** (`candy-mdview/`) | 🟢 ready | Markdown CLI viewer / pager (consumes SugarMarkdown). |
| **CandyAnsiInspect** (`candy-ansi-inspect/`) | 🟢 ready | ANSI escape-sequence inspector. |
| **CandySshLauncher** (`candy-ssh-launcher/`) | 🟢 ready | SSH endpoint launcher (YAML / JSON endpoints). |
| **CandyKv** (`candy-kv/`) | 🟢 ready | Personal key/value store — one SQLite file per database, glob + fuzzy listing, TTL, binary-safe values, JSON/YAML import/export. |
| **CandyMail** (`candy-mail/`) | 🟢 ready | Email library + CLI — SMTP + Resend API transports, attachments, HTML + plain-text multipart, fluent interface. |
| **CandyGitServer** (`candy-git-server/`) | 🟡 in progress | Self-hostable Git server — smart-HTTP clone/fetch/push via `serve --http`; SSH delegates real git under host-sshd `ForceCommand`; git:// daemon advertisement-only. Users, repos, access control, optional LFS. |
| **CandyCode** (`candy-code/`) | 🟢 ready | TUI AI coding agent — eleven provider type keys over nine engines, 38 built-in tools per launch, skills, hooks, agents, MCP, SQLite sessions. |
| **CandyCodeWeb** (`candy-code-web/`) | 🟡 in progress | Browser UI for candy-code's WebSocket server mode — multi-session dashboard, approvals, settings; Vite + Vue 3, committed `dist/` + one-class PHP shim. Multi-session live: twelve tabs + a tiled grid of up to nine sessions, twelve dockable panels, approvals drawer, settings form, command palette. |
| **CandyVcr** (`candy-vcr/`) | 🟢 ready | Record + replay sugar-core sessions — JSONL/YAML cassettes, Recorder hook (`setRecorder`/`withRecorder`), Player + Byte/Screen assertions, `.tape`→GIF renderer (themes, GD/imagick, ffmpeg/php), CLI. |
| **CandyTetris** (`candy-tetris/`) | 🟢 ready | Tetris clone — SRS / 7-bag / NES base + Guideline bonuses. |
| **CandyFiles** (`candy-files/`) | 🟢 ready | Dual-pane file manager. |
| **CandyGit** (`candy-git/`) | 🟢 ready | Terminal Git client — a TUI for diff/hunk staging, branching, log, stash, worktrees and rebase; shells out to `git`. |
| **CandySql** (`candy-sql/`) | 🟢 ready | SQLite/MySQL/PostgreSQL client TUI — schema introspection, query editor, EXPLAIN plans, server status, alerting, status history + in-session query log, and the admin suite: Workbench-style status dashboard, Variables editor (SET GLOBAL/PERSIST), connections/processlist with KILL, Performance Schema easy-setup, catalog-driven Reports. |
| **CandyCodetime** (`candy-codetime/`) | 🟢 ready | Privacy-first coding-time tracker — JSONL day-files by default, optional SQLite backend (`--backend`). |
| **CandyMinesweeper** (`candy-minesweeper/`) | 🟢 ready | Minesweeper — first-click safety / flood-fill. |
| **CandyGifView** (`candy-gifview/`) | 🟢 ready | Terminal GIF viewer CLI (ext-gd via sugar-animation) — sugar-animation's viewer; antecedent: gifterm. |
| **CandyImage** (`candy-image/`) | 🟡 in progress | Terminal image viewer CLI — chafa-independent front end on sugar-image: the full format ladder (auto, sixel/kitty/iTerm2 protocols, half/quarter block tiers, ascii/symbols, ansi16/ansi256/truecolor), cell sizing with the six-arm `--scale`, `--canvas`/`--work` dials, `--animate` (GIF), `--passthrough`, `--dump-detect`; ships bin, `lang/` i18n and a rolling latest phar. Unreleased: Packagist dev-master only (no tag/branch-alias); install via `:@dev` or the rolling latest phar — see candy-image README Install (2026-11-05). Demo GIF pending owner disposition. |
| **CandyTop** (`candy-top/`) | 🟢 ready | Terminal system monitor — cpu / mem+disks / net / process boxes with braille, block and sextant graphs, up to six multi-vendor GPU/NPU boxes, an ipmi BMC band and a VM dashboard, battery readouts, menus, presets, 43 themes (42 ported from btop plus candy-top's own pastel), btop-compatible config with save/reload; Linux `/proc`+`/sys` and FreeBSD collectors; container/VM tags; adopted btop upstream PRs. |
| **CandyFlappy** (`candy-flappy/`) | 🟢 ready | Flappy Bird clone — the bird is a SugarSpring projectile. |

<!-- Windows ConPTY backend for sugar-pty: landed (sugar-pty/src/Windows). -->


---

## Channel bundles (outside the package matrix)

Two further directories ship channel plugins for `candy-code serve` rather
than packages in the 42 + 22 matrix above: [`candy-discord/`](../candy-discord/)
(Discord bot conversations become served sessions) and
[`candy-msteams/`](../candy-msteams/) (Teams chats and channels do the same).
Each publishes as its own `sugarcraft/*` composer library; neither is a
require of the root aggregate, a row in the status tables above, or a tile
in the site catalog — their READMEs are the authority for how they work.
---

## Naming conventions (cheat sheet)

Two prefixes, chosen by role (2026-10 law) — suffixes are short, technical,
and describe what the package does.

| Prefix | Meaning | Example uses |
|---|---|---|
| **Candy-** | runnable apps, CLI tools, games | coding agent (CandyCode), git TUI (CandyGit), file manager (CandyFiles) |
| **Sugar-** | libraries — components, data, engines, primitives | forms (SugarForms), charts (SugarCharts), runtime (SugarCraft) |

History: the `Honey-` math/physics family was retired with the 2026-10
rename. <!-- @rename-keep -->

Don't mint new prefixes without a discussion in
[`PROJECT_NAMES.md`](../PROJECT_NAMES.md). `candy-top` is the law's own
example — an app carrying `candy-`; its pre-law owner ruling is recorded in
[`PROJECT_NAMES.md`](../PROJECT_NAMES.md).

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

The upstreams are the Go terminal ecosystem — notably
[Bubble Tea](https://github.com/charmbracelet/bubbletea) and the wider
[Charm](https://github.com/charmbracelet) family of libraries; the table
maps each package to its upstream.

| SugarCraft package | Upstream |
|---|---|
| CandyAnsiInspect (candy-ansi-inspect/) | [sequin](https://github.com/charmbracelet/sequin) |
| CandyCodeWeb (candy-code-web/) | [opencode](https://github.com/sst/opencode) web UI · OpenClaw Control UI (loosely) |
| CandyCode (candy-code/) | [Crush](https://github.com/charmbracelet/crush) |
| CandyCodeshot (candy-codeshot/) | [freeze](https://github.com/charmbracelet/freeze) |
| CandyCodetime (candy-codetime/) | [TakaTime](https://github.com/Rtarun3606k/TakaTime) |
| CandyFiles (candy-files/) | [superfile](https://github.com/yorukot/superfile) |
| CandyFlappy (candy-flappy/) | [flapioca](https://github.com/kbrgl/flapioca) |
| CandyGifView (candy-gifview/) | [gifterm](https://github.com/namzug16/gifterm) |
| CandyGitServer (candy-git-server/) | [soft-serve](https://github.com/charmbracelet/soft-serve) |
| CandyGit (candy-git/) | [lazygit](https://github.com/jesseduffield/lazygit) |
| CandyImage (candy-image/) | [chafa](https://github.com/hpjansson/chafa) (CLI surface idea, not a port) |
| CandyKv (candy-kv/) | [skate](https://github.com/charmbracelet/skate) |
| CandyMail (candy-mail/) | [pop](https://github.com/charmbracelet/pop) |
| CandyMdview (candy-mdview/) | [glow](https://github.com/charmbracelet/glow) |
| CandyMinesweeper (candy-minesweeper/) | [go-sweep](https://github.com/maxpaulus43/go-sweep) |
| CandyScriptui (candy-scriptui/) | [gum](https://github.com/charmbracelet/gum) |
| CandySql (candy-sql/) | [lazysql](https://github.com/jorgerojas26/lazysql) |
| CandySshLauncher (candy-ssh-launcher/) | [wishlist](https://github.com/charmbracelet/wishlist) |
| CandyStarter (candy-starter/) | none — first-party (bootstrap template patterned on [bubbletea](https://github.com/charmbracelet/bubbletea) starter examples; analogy only) |
| CandyTetris (candy-tetris/) | [tetrigo](https://github.com/Broderick-Westrope/tetrigo) |
| CandyTop (candy-top/) | [btop](https://github.com/aristocratos/btop) |
| CandyVcr (candy-vcr/) | [x/vcr](https://github.com/charmbracelet/x/tree/main/vcr) |
| SugarAnimation (sugar-animation/) | [gifterm](https://github.com/namzug16/gifterm) |
| SugarAnsiParser (sugar-ansi-parser/) | [x/ansi](https://github.com/charmbracelet/x/tree/main/ansi) |
| SugarAsync (sugar-async/) | none — first-party |
| SugarCellbuffer (sugar-cellbuffer/) | [vte](https://github.com/charmbracelet/vte) |
| SugarCharts (sugar-charts/) | [ntcharts](https://github.com/NimbleMarkets/ntcharts) |
| SugarCli (sugar-cli/) | [fang](https://github.com/charmbracelet/fang) (output shape only — no argv/cobra layer) |
| SugarColorprofile (sugar-colorprofile/) | [colorprofile](https://github.com/charmbracelet/colorprofile) |
| SugarConstraints (sugar-constraints/) | [ratatui](https://github.com/ratatui/ratatui) |
| SugarCraft (sugar-core/) | [Bubble Tea](https://github.com/charmbracelet/bubbletea) |
| SugarDashboard (sugar-dashboard/) | [bubble-grid](https://github.com/shahar3/bubble-grid) · [btop](https://github.com/aristocratos/btop) · [termui](https://github.com/gizak/termui) (multi-antecedent) |
| SugarDatepicker (sugar-datepicker/) | [bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker) |
| SugarDiff (sugar-diff/) | none — first-party |
| SugarFlexbox (sugar-flexbox/) | [stickers](https://github.com/76creates/stickers) |
| SugarFocus (sugar-focus/) | none — first-party (plays the role of [bubbles](https://github.com/charmbracelet/bubbles) focus handling; sugar-dashboard `FocusManager` analog; analogy only) |
| SugarForms (sugar-forms/) | [huh](https://github.com/charmbracelet/huh) |
| SugarFuzzy (sugar-fuzzy/) | [fuzzy](https://github.com/sahilm/fuzzy) (+ internal algorithms) |
| SugarGallery (sugar-gallery/) | [bubbles](https://github.com/charmbracelet/bubbles) (list/viewport) · phlix web media grid (loosely) |
| SugarIgnore (sugar-ignore/) | [git](https://git-scm.com/docs/gitignore) gitignore(5) semantics — first-party |
| SugarImage (sugar-image/) | [x/mosaic](https://github.com/charmbracelet/x/tree/main/mosaic) |
| SugarInput (sugar-input/) | none — first-party (semantics follow charmbracelet/bubbletea) |
| SugarLog (sugar-log/) | [log](https://github.com/charmbracelet/log) |
| SugarMarkdown (sugar-markdown/) | [glamour](https://github.com/charmbracelet/glamour) |
| SugarMCPClient (sugar-mcp-client/) | none — first-party |
| SugarMetrics (sugar-metrics/) | [promwish](https://github.com/charmbracelet/promwish) |
| SugarMouse (sugar-mouse/) | [bubblezone](https://github.com/lrstanley/bubblezone) |
| SugarNavstack (sugar-navstack/) | [bubbleo](https://github.com/KevM/bubbleo) |
| SugarOverlay (sugar-overlay/) | [bubbletea-overlay](https://github.com/rmhubbert/bubbletea-overlay) |
| SugarPanels (sugar-panels/) | [bubbleboxer](https://github.com/treilik/bubbleboxer) |
| SugarPty (sugar-pty/) | [x/xpty](https://github.com/charmbracelet/x/tree/main/xpty) |
| SugarQuickpick (sugar-quickpick/) | [theHermit](https://github.com/Genekkion/theHermit) |
| SugarReadline (sugar-readline/) | [promptkit](https://github.com/erikgeiser/promptkit) |
| SugarReleases (sugar-releases/) | none — first-party |
| SugarSpring (sugar-spring/) | [harmonica](https://github.com/charmbracelet/harmonica) |
| SugarSshServer (sugar-ssh-server/) | [wish](https://github.com/charmbracelet/wish) |
| SugarStyle (sugar-style/) | [lipgloss](https://github.com/charmbracelet/lipgloss) |
| SugarTable (sugar-table/) | [bubble-table](https://github.com/Evertras/bubble-table) (no mouse integration; upstream's WithHorizontalFreezeColumnCount column-prefix freeze IS ported here as withFrozenCols — upstream has no row-freeze concept) |
| SugarTesting (sugar-testing/) | [x/exp/golden](https://github.com/charmbracelet/x-exp-golden) + [teatest](https://github.com/charmbracelet/teatest) (nearest prior art — first-party hardened; tape dialect charmbracelet/vhs) |
| SugarToast (sugar-toast/) | [bubbleup](https://github.com/DaltonSW/bubbleup) |
| SugarTreelist (sugar-treelist/) | [bubblelister](https://github.com/treilik/bubblelister) |
| SugarVideo (sugar-video/) | [tplay](https://github.com/maxcurzi/tplay) · [glyph](https://github.com/seatedro/glyph) · [video-to-ascii](https://github.com/joelibaceta/video-to-ascii) |
| SugarVterm (sugar-vterm/) | [x/vt](https://github.com/charmbracelet/x/tree/main/vt) |
| SugarWidgets (sugar-widgets/) | [bubbles](https://github.com/charmbracelet/bubbles) |

The table above lists every package in the two status tables — 42
libraries and 22 apps, 64 rows total. Several projects outside Charm's
orbit inspired individual packages; each package's README carries its own
attribution.
