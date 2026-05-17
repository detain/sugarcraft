---
status: open
phase: post-PTY-consolidation
updated: 2026-05-17
goal: Consolidated library-feature, single-source-of-truth, and sugar-dash leftovers
parent_plans:
  - plans/leftover_updates.md
  - docs/research/libraries/*.md
  - plans/dash.md
  - plans/dash_update_claude.md
  - plans/dash_update_opencoder_openagent.md
---

# SugarCraft — library + single-source-of-truth + sugar-dash leftovers

Consolidated from a re-audit of `docs/research/libraries/*.md` (44 files,
written **before** the PTY consolidation landed) and a deep audit of
`sugar-dash`. The PTY-specific gaps live in `plans/leftover_updates.md`;
this file is everything else.

The audit categories:

1. **Cross-cutting: single source of truth** — duplicated primitives
   (Buffer / Cell / Color / Style / Theme / Rect / Viewport / Border)
   across libs. The PTY consolidation proved the model works; apply it
   to the rest of the foundation surface.
2. **Cross-cutting: i18n via `Lang::t()`** — the canonical pattern from
   `sugar-wishlist/src/Lang.php`. Several libs still miss it.
3. **Cross-cutting: ColorProfile + env detection** — should live in
   `candy-palette`, not be re-rolled in each lib.
4. **Per-library leftover features** — verified gaps per research doc.
5. **Sugar-dash detailed plan** — finish the Phase-0 reorg, fix the
   broken `ExternalModule`, wire a real interactive dashboard, kill the
   chart duplication.
6. **Strategic-decision items** — sugar-post (email vs social), candy-serve
   TUI scope, candy-vt P3 graphics protocols.
7. **Sequencing recommendation** — order of operations so SSoT cleanup
   does not collide with feature work.

Each item is sized to ~1 PR unless flagged `[multi-PR]`.

---

## 1. Single-source-of-truth cleanup (cross-cutting)

The PTY consolidation produced this principle: **one namespace owns each
primitive; everyone else imports.** The audits surfaced a long tail of
violations. Sequence is important — break duplications in dependency
order so the canonical home is settled before consumers refactor.

### SSOT-01 — Pick canonical Style / Color / Rect / Buffer / Cell / Theme

Today:

| Primitive | Canonical (intended) | Duplicates found |
|---|---|---|
| `Style` | `candy-sprinkles/src/Style.php` (Lipgloss-style) | `sugar-dash/src/Foundation/Style.php` (~100 LOC bespoke) |
| `Color` | `candy-core/src/Util/Color.php` | `sugar-dash/src/Foundation/Color.php` (thin alias that re-exposes every method) |
| `Rect` | `candy-core/src/Rect.php` | `sugar-dash/src/Foundation/Rect.php` (62 LOC bespoke) |
| `Buffer` | `candy-vt/src/Buffer/Buffer.php` | `sugar-dash/src/Foundation/Buffer.php` (288 LOC) + `sugar-dash/src/Grid/Buffer.php` (legacy) |
| `Cell` | `candy-vt/src/Cell/Cell.php` | `sugar-dash/src/Foundation/Cell.php` |
| `Theme` | (none yet — gap) | `sugar-dash/src/Foundation/Theme.php` (349 LOC) |
| `Border` / `BorderTitle` | `candy-sprinkles/src/Border/*` | sugar-boxer plans to add its own; sugar-dash has `Components/Modal` border re-rolls |
| `Viewport` / `Scrollbar` | `sugar-bits/src/Viewport/`, `sugar-bits/src/Scrollbar/` | `sugar-stickers` research plans to add these from scratch |
| `StyleParser` (`[text](fg:red,bg:blue)`) | `sugar-dash/src/Foundation/StyleParser.php` (251 LOC) | `candy-sprinkles` will likely want it too; pick one home before second consumer ships |

**Decision points:**

- **`Theme` lives in `candy-sprinkles`.** It already owns Style and Border
  in the Lipgloss ecosystem; promoting Theme there gives every lib a
  shared theme surface (`Theme::dracula()`, `Theme::tokyoNight()`,
  `Theme::oneDark()`, `Theme::adaptive()`).
- **`Buffer` + `Cell` live in `candy-vt`.** It is the lowest-level lib
  that needs cells (VT state machine). Promoting Theme out of vt would
  invert dependency direction.
- **`StyleParser` lives in `candy-sprinkles`.** It belongs with Style.

**Actions (each ≤1 PR):**

- SSOT-01a — Move `Theme.php` from sugar-dash Foundation to
  candy-sprinkles. Update sugar-dash to import. Delete duplicate.
- SSOT-01b — Delete `sugar-dash/src/Foundation/Style.php`; replace usages
  with `\SugarCraft\Sprinkles\Style`.
- SSOT-01c — Delete `sugar-dash/src/Foundation/Color.php`; replace
  type-hints with `\SugarCraft\Core\Util\Color`.
- SSOT-01d — Delete `sugar-dash/src/Foundation/Rect.php`; replace with
  `\SugarCraft\Core\Rect`.
- SSOT-01e — Replace `sugar-dash/src/Foundation/Buffer.php` and
  `sugar-dash/src/Foundation/Cell.php` with re-export aliases of
  `\SugarCraft\Vt\Buffer\Buffer` and `\SugarCraft\Vt\Cell\Cell`.
- SSOT-01f — Delete `sugar-dash/src/Grid/Buffer.php` (legacy
  pre-reorg duplicate).
- SSOT-01g — Move `sugar-dash/src/Foundation/StyleParser.php` to
  `candy-sprinkles/src/StyleParser.php` (no logic change). Add a one-line
  alias in sugar-dash if call-site count is high.
- SSOT-01h — Add `candy-sprinkles` to `sugar-dash/composer.json` `require`
  (it already requires `candy-core` + `candy-sprinkles` per audit, so
  verify; if missing, add).

**Acceptance:** `grep -rn "class Style\|class Color\|class Theme\|class Rect\|class Buffer\|class Cell\|class StyleParser" /home/sites/sugarcraft/*/src` shows exactly one canonical file per primitive; sugar-dash test suite green; `composer validate` clean per lib.

### SSOT-02 — sugar-boxer composes, doesn't reinvent

sugar-boxer research lists `BoxStyle` enum (Single/Double/Round/Bold/Classic/Hidden/Block), text alignment, box titles, colors, margin as missing features. **All five live in candy-sprinkles.** sugar-boxer should compose `\SugarCraft\Sprinkles\Style` + `\SugarCraft\Sprinkles\Border\Border` instead of rolling its own.

**Action:**
- SSOT-02 — Refactor sugar-boxer to accept `Style` and `Border` from candy-sprinkles. Delete any internal `Border*`. Adds candy-sprinkles to require (transitive via candy-core OK; verify path-repo per `tools/check-path-repos.php`).

### SSOT-03 — sugar-stickers composes sugar-bits

sugar-stickers research's Priority 1 is `Viewport`, Priority 3 is `Scrollbar`. **Both already exist** in `sugar-bits/src/Viewport/Viewport.php` and `sugar-bits/src/Scrollbar/`. The sticker-specific behaviour (sticky positioning, scroll sync) should layer **on top** of sugar-bits, not replace.

**Action:**
- SSOT-03 — sugar-stickers' `Viewport` becomes a `final class` extending or composing `\SugarCraft\Bits\Viewport\Viewport` with the sticky-positioning extras. Add sugar-bits to require.

### SSOT-04 — sugar-crumbs click regions use candy-zone

sugar-crumbs research P4 calls for "Click-region rendering". `candy-zone` is the canonical zone-tracking lib (APC marker + scan algorithm). sugar-crumbs should emit zone markers via candy-zone, not embed click coordinates.

**Action:**
- SSOT-04 — When implementing click regions in sugar-crumbs, use `\SugarCraft\Zone\Manager::mark()` for each crumb. Acceptance: `grep -n "zoneMark\|APC" sugar-crumbs/src` returns hits to candy-zone, not inline copies.

### SSOT-05 — Chart duplication: sugar-dash depends on sugar-charts

`sugar-dash/src/Plot/Chart/{Bar,Heatmap,HeatMapChart,OHLC,Sparkline,SparklineArea,SparklineBar,SparkArea,Chart}` overlaps with `sugar-charts/src/{BarChart,Heatmap,OHLC,Sparkline,Chart,LineChart}`. Two parallel chart libraries.

**Decision:** sugar-charts is the focused chart library — keep it canonical. sugar-dash's Plot/Chart should delegate.

**Action:**
- SSOT-05a — Audit each `sugar-dash/src/Plot/Chart/*.php` against `sugar-charts/src/*.php`. For each direct duplicate, delete the dash version + replace with re-export.
- SSOT-05b — sugar-dash variants that add value beyond sugar-charts (e.g. `GaugeWithDetail`, `WordCloud`, `Treemap`) stay in dash; document the rule "leaf chart families = sugar-charts; visualizations + dashboard widgets = sugar-dash."
- SSOT-05c — Add `sugarcraft/sugar-charts` to `sugar-dash/composer.json` `require`.

### SSOT-06 — `posix_isatty` direct calls

Found in `candy-mosaic/src/Detect.php:236-237`. Canonical: `\SugarCraft\Pty\Contract\Termios::isAtty()` (also in `plans/leftover_updates.md` as CC-LO-06).

**Action:** see `plans/leftover_updates.md` CC-LO-06.

### SSOT-07 — `proc_open` direct calls in candy-core utilities

`candy-core/src/Util/Editor.php` and `candy-core/src/Util/Open.php` shell out directly. Canonical: `\SugarCraft\Pty\Posix\PosixProcess`.

**Action:** see `plans/leftover_updates.md` CC-LO-07.

### SSOT-08 — ColorProfile + env detection consolidate in candy-palette

`candy-log` research items M5/M6 (`NO_COLOR`/`FORCE_COLOR` detection + downsampling) and `candy-palette` itself need `CLICOLOR_FORCE`/`CLICOLOR`/`COLORTERM` precedence, tmux/screen detection, `WT_SESSION`, `GOOGLE_CLOUD_SHELL`, terminfo `Tc`/`RGB` parsing. All these env probes belong in **one** place: `candy-palette`.

**Actions:**
- SSOT-08a — Pull all env-var probes into `candy-palette/src/Probe.php` (new class). Single API: `Probe::colorProfile(): ColorProfile`.
- SSOT-08b — `candy-log`, `candy-mosaic`, `candy-freeze` (themes), `candy-vt` (truecolor accept) all consume it.
- SSOT-08c — Implement the missing candy-palette items (H1–H5, M1–M3, terminfo Phase 2) inside `Probe`. Tests cover each env-var precedence rule.

### SSOT-09 — `Module\Module` contract aligns with candy-core `Model`

sugar-dash's `Module/Module.php` uses `array<string,mixed>` state. candy-core's `\SugarCraft\Core\Model` is the established Elm-style contract (`update(Msg): [Model, ?Cmd]`). Two incompatible contracts in one monorepo.

**Action:**
- SSOT-09 — Redefine `Module\Module::update()` to return `array{0:Module,1:?Cmd}` aligned with `Core\Model`. Built-in modules (Clock, System, Greeting, Uptime, Generic) rewritten. Old array-state contract kept as `LegacyModule` interface for one release.

---

## 2. i18n via `Lang::t()` (cross-cutting)

Canonical pattern: `sugar-wishlist/src/Lang.php` wraps `\SugarCraft\Core\I18n\T` with a per-lib namespace; lookups go `exact-locale → base-language → en → raw-key`. Each lib has a `lang/en.php` (+ optional locale variants).

**Libs verified missing `Lang.php` / `lang/`:**

- `sugar-calendar` — only ships `DatePicker.php`; user-facing date strings hardcoded.
- `sugar-table` — header / pagination / empty-state strings hardcoded.
- `sugar-toast` — alert level labels (Info/Warning/Error) hardcoded.
- `sugar-stickers` — when feature work lands, must include Lang.
- `sugar-boxer` — alignment-mode labels in examples.
- `sugar-crumbs` — separator + ellipsis labels.
- `super-candy` — file-manager prompts.
- `sugar-stash` — many user-facing strings (commit prompts, errors).

**Action template per lib (single PR each):**

1. Add `lang/en.php` returning a flat assoc array of `key => string`.
2. Add `src/Lang.php` matching `sugar-wishlist/src/Lang.php` shape.
3. Replace inline English strings in `src/` with `Lang::t('key', ['param' => $v])`.
4. Test: a `LangCoverageTest` verifies every key used in code exists in `lang/en.php`.

**Sequencing:** Bundle into one combined "i18n pass" PR per lib (1 commit
covers the lib's complete conversion). Spread across ~8 PRs.

---

## 3. Per-library leftover features

Distilled from the research-doc audit. Items already shipped are stripped;
PTY-related items go to `plans/leftover_updates.md`. Each lib's row is a
short bullet list; treat them as backlog items, not sequenced steps.

### candy-core

- Elm-style **Subscriptions** with reconciliation (research §3.1).
- **Screen Stack** primitive (§3.2) — modals/sub-screens need a canonical home.
- **Component composition** with `on_mount`/`on_unmount` lifecycle (§3.3).
- Worker pool for CPU-bound tasks (§3.4).

### candy-shell

- Auto-discovery command attribute scanner (§5 P1).
- `FlagSpec` attribute trait for declarative options (§P2).
- `ValueEnum`-style constrained-option enums (§P3).
- Enhanced help: examples, aliases, typo suggestions (§P4–P6).
- Shell completions, version-from-composer.json, env-var fallbacks (§7–9).

### candy-wish

- **Context** propagation (§4.1 P1).
- **ChannelHandler** layer: pty-req / window-change / shell / exec / signal / env / break (§4.2 P1).
- Expanded auth middleware: `PasswordAuth`, `CertificateAuth`, `AuthMethods`, `KeyboardInteractive` (§4.3 P2).
- **Subsystem** middleware (sftp/rsync handlers, §4.4 P2).
- SSH-protocol metadata on `Session`: `sessionId`, `authMethod`, `keyFingerprint`, `clientVersion`, `serverVersion` (§4.5 P2).
- Async middleware (§4.7).

### candy-vcr

- Relative-timestamp mode (M1).
- Idle-time trimming for `SPEED_REALTIME` (L3).
- `FocusLostMsg` coverage check in `BuiltinSerializer` (H4).
- (asciinema .cast format conversion lives in `plans/leftover_updates.md` P6.5-LO-05.)

### candy-vt

- **P0.1 DECSTBM scroll margins** (`Handler/ScrollHandler.php:14-16` TODO).
- **P0.2 Auto-wrap with DECAWM** (`Handler/ScreenHandler.php:101` ref).
- **P0.3 Subparameter parsing** for `CSI 38:2:…` (`Parser/Parser.php`).
- **P1.1 Scrollback buffer** (`Screen/Screen.php` currently drops rows).
- **P1.2 SGR underline styles 4:1–4:5**.
- DECOM origin mode (P1.3), DECSCUSR cursor shape (P1.4), focus events 1004 (P1.5).
- BCE (P2.2), combining-char composition (P2.3), synchronized output 2026 (P2.4).
- Kitty graphics + Sixel decode (P3) — strategic, see §6.

### candy-pty

(See `plans/leftover_updates.md` for the full list; pulled out here too
because research adds three items the PTY plan did not call out:)

- `openpty()` FFI binding (Darwin) — research §4.1.1.
- `waitpid()` FFI binding — drops the 10 ms `proc_get_status` poll in `Posix/ChildPollTrait.php`.
- `/dev/tty` size-forwarding variant of `SignalForwarder::attachSigwinch` — current variant writes only to PTY master.
- Public `Pty::setControllingTerminal(int $fd)` extraction — currently embedded in `bin/pty-shim.php`.

### candy-zone

- `ZoneHoverTracker` + `MsgZoneEnter` / `MsgZoneExit` (Priority 1).
- `DragTracker` + `MsgZoneDragStart/Move/End` (Priority 2).
- `ClickCounter` for double/triple-click (Priority 3).
- `Manager::setMotionTracking(bool)` (Priority 4).

### candy-mosaic

- **Quarter-block Unicode renderer** (P0 #3).
- **`Renderer::delete()`** API (P0 #2).
- Kitty virtual-image support `a=p` (P1 #4).
- Kitty `f=1` zlib compression (P1 #5).
- Transparent background for `HalfBlockRenderer` (P1 #6).
- 256-color Sixel fallback (P1 #7).
- WezTerm detection bug — currently classified as both Kitty *and* iTerm2 candidate; restrict.
- Animation/GIF support (P2 #8) — overlap with candy-flip; pick one home (see SSOT note below).

### candy-serve

- **Interactive SSH TUI** (research §3.1, HIGH) — soft-serve's marquee feature, untouched. May need its own milestone plan (`plans/candy-serve-tui.md`).
- HTTP smart protocol server (§3.2).
- `git://` daemon (§3.3).
- Real daemon mode (§3.4).
- OSC 52 clipboard — wire through candy-vt OSC handler once added.

### sugar-crush

- **Session persistence** JSONL save/restore (H1) — no `SessionManager`.
- **Real-time streaming UI** (H2) — `StreamingCommandBackend` exists but Chat/Renderer don't render incremental tokens.
- **Context compaction** (H3).
- Built-in file/bash tools (H4).
- Syntax highlighting in markdown blocks (M5).
- Slash commands `/help /clear /compact /model` (M6).
- MCP client (M7).
- Load `CLAUDE.md` on startup (M8).

### sugar-skate

- Import/export JSON/YAML (P1).
- Levenshtein typo suggestions on db-not-found error (P2).
- TTL/expiration `Store::setWithTtl()` (P4).
- STDIN value input (P5).
- Atomic `Database::transaction(callable)` wrapping SQLite tx (P6).

### sugar-veil

- Backdrop/dimming effect (§4.1).
- Animation system slide/fade/scale (§4.2).
- Z-index/stacking (§4.3).
- Click-outside-to-dismiss (§4.4).
- Auto-sizing/content-aware dimensions (§4.5).
- Border chrome (§4.6) — must compose `candy-sprinkles` Border.

### sugar-prompt

- Built-in validators: Required, Email, MinLength, MaxLength, Pattern (§3.1 P1).
- Fuzzy autocomplete `withFuzzySuggestions()` (§3.2 P2).
- Async debounced suggestions (§3.3).
- MultiSelect vim keys (§3.4).
- Form-level cross-field `validateAll()` (§3.5).
- Select with enum/index mode (§3.6).
- `Theme::$errorSummary` separate from `Theme::$error` (§2.4).

### sugar-bits

- TextInput `validate_on` timing enum (Phase 1, HIGH).
- TextInput `restrict` regex pattern (Phase 1, HIGH).
- Table sorting `sortBy` / `thenSortBy` / `clearSort` (Phase 2, HIGH).
- Table filtering `withFilterable` (Phase 2, HIGH).
- Table pagination `withPageSize` + nav (Phase 3, MEDIUM).
- Paginator `pageFirst()`, `pageLast()`, `withPage()` (Phase 4, MEDIUM).
- TextInput multiple validators (long-term).

### sugar-charts

- `niceNumbers()` axis labeling on `Canvas/Graph.php` (§3.1.1).
- LineChart sliding-window `push()` (§3.1.2) — verify if `Streamline::push()` covers it.
- LineChart `withFill()` area fill (§3.1.3).
- MarkLine (Min/Max/Average) (§3.2.2).
- Data aggregation: `bucketByTime`, `movingAverage`, `resample` (§3.2.3).
- Optional `BrailleCanvas` rendering mode (§3.2.1) — should consume `sugar-dash/src/Plot/Braille` (or move braille to candy-sprinkles).
- Named color themes (Dracula, OneDark, Solarized, TokyoNight) — pulled from candy-sprinkles Theme once SSOT-01a lands.

### candy-sprinkles

- **Theme class** (High #1) — gating SSOT-01a above; build it here.
- String color parsing `Color::parse("cyan")` (#2).
- Spacing param in Layout joins (#3).
- HSL color space (#4).
- BorderGradientBlend 5-color API alignment with lipgloss v2 (#5).
- Markup parser (`[bold red]…[/]`) (#6).
- `Style::patch()` incremental modification (#7).
- Rapid blink variant (#8).

### candy-freeze

- Per-segment background color (`Segment.php:13` TODO).
- Ligature `font-variant-ligatures` flag (§4.2.2).
- Language detection (§4.1.1).
- VS Code / chroma JSON theme compatibility (§4.1.3).
- Font embedding in SVG via base64 TTF (§4.2.1).
- Line highlighting (§4.3.2).
- Interactive TUI customization (§4.3.3) — depends on sugar-prompt features.

### candy-flip

- Replace temp-file frame extraction with `imagecreatefromstring()` (P1).
- Per-frame timing from Graphic Control Extension (P2).
- Area-averaged downsampling (P3).
- Floyd-Steinberg dithering (P4).
- Local color tables (P5).
- Transparency / disposal-method handling (P6).
- Adaptive cell size from `tput cols lines` (P7) — must use `\SugarCraft\Pty\SizeIoctl::query(STDOUT)`, not shell-out.
- Kitty/WezTerm graphics-protocol output path (P10) — overlap with candy-mosaic; pick one home. **Recommendation:** Kitty/WezTerm rendering belongs in candy-mosaic; candy-flip emits frame bytes only.
- Frame cache (P12).

### candy-hermit

- `Item` interface + numbered items (§3.1 P1).
- Filter function injection `setFilterFn()` (§3.2 P2).
- Persistent history support (§3.3 P3).
- Border/Style struct (§3.4 P4) — compose candy-sprinkles, don't reinvent.
- Window auto-resize on SIGWINCH (§3.5 P5) — route through `\SugarCraft\Pty\SignalForwarder`.
- Help/status bar (§3.6 P6).

### candy-mines

- **Chord clicks** (HIGH).
- Timer precision: `microtime(true)` (HIGH).
- Difficulty stats persistence (HIGH).
- O(1) win detection via revealed-count counter (MEDIUM).
- Board serialization (MEDIUM).
- Custom-difficulty UI (MEDIUM).

### candy-tetris

- Full SRS rotation with official kick tables (HIGH).
- T-Spin detection (MEDIUM).
- Back-to-Back bonus, combo system (MEDIUM).
- DAS/ARR keyboard timing (LOW).
- Perfect-clear detection (LOW).

### candy-mold

(Scaffold-template lib; most items are repo-level concerns already
handled by the monorepo.) Skip until someone uses it as a template.

### candy-lister

- Filter interface (Priority 1) — `withFilterFn()`.
- `FuzzyMatch` class with Smith-Waterman scoring (Priority 2).
- Filter states/transitions (unfiltered/filtering/filtered).

### candy-log

- `NO_COLOR` / `FORCE_COLOR` env detection — consume `candy-palette/Probe`.
- `PadLevelText` for level-label alignment.
- Level numeric values aligned with upstream (-4/0/4/8/12).
- Per-field key styles `Styles::Keys[key]`.
- ColorProfile downsampling — via `candy-palette/Probe`.
- CallerFormatter, slog/PSR-3 handler bridge, hook system, configurable `PartsOrder`.

### candy-metrics

- **Classic histogram buckets** (§3.1, CRITICAL).
- Metric descriptor / registration with help text (§3.2, CRITICAL).
- Label-cardinality control / `DeleteLabelValues` (§3.3).
- UpDownCounter, Async Counter, Async Gauge instrument kinds.
- Native histograms (low priority).

### candy-palette

(See SSOT-08 — most of the work happens here.) Specifically the
research's H1-H5, M1-M3, and Phase-2 terminfo `infocmp` items.

### candy-query

- Schema browser via PRAGMA queries (P1).
- Pagination (P2).
- Cell-level editing (P3).
- Saved queries / snippets (P4).
- Query explain plan (P5).
- Horizontal scrolling (P6).
- JSON/NULL formatting (P7).

### honey-bounce

- `SpringPreset` enum (Gentle/Wobbly/Stiff/Slow/Molasses) (§4.1 P1).
- `SpringConfig` translating tension/friction/mass → angularFreq/dampingRatio (P1).
- `CubicBezier` easing with CSS-standard easings (§4.2 P1).
- `SpringChain` for sequencing (§4.3 P2).
- Reduced-motion support (§1.2) — read env / `Probe::reducedMotion()`.
- Gesture-driven springs (P3 deferred).

### honey-flap

- Variable pipe-gap height (§5.2).

### super-candy

- Copy / move / rename ops (HIGH).
- Bulk rename, progress UI (MEDIUM).
- Preview pane (MEDIUM) — image preview delegates to candy-mosaic.
- Async file ops (LOW; needs ReactPHP).

### sugar-stash

- Phase 1: context-sensitive help, branch checkout, commit, stage-all.
- Phase 2: diff viewer, discard, amend, hunk staging, create branch.
- Phase 3: undo/redo, line-level staging, branch delete, merge, rebase.
- Phase 4: interactive rebase, stash mgmt, cherry-pick, worktrees, syntax highlighting.

### sugar-toast

- `MiddleLeft` / `MiddleRight` / `MiddleCenter` positions (P1).
- `withAllowEscToClose()` + `hasActiveAlert()` (P1).
- Stack Y-offset fix (P1).
- Persistent toasts (P2).
- `withMaxConcurrent()` + overflow enum (P2).
- Custom alert types via factory (P2).
- Progress toasts, action buttons, history log, animations (P3-P4).

### sugar-table

- Viewport virtualization (large-dataset scroll).
- Dynamic / percentage / content-based column widths.
- Text wrapping inside cells, multi-line row support.
- Multiple border styles — consume candy-sprinkles.

### sugar-stickers

- See SSOT-03 (Viewport / Scrollbar consume sugar-bits).
- Sticky positioning via FlexBox extension (Priority 2).
- Scroll synchronization (Priority 4, low).

### sugar-readline

- History persistence (`FileHistory`, `InMemoryHistory`) (HIGH).
- History navigation ↑/↓ (HIGH).
- Vi mode, Emacs mode (MEDIUM).
- Auto-suggest from history (MEDIUM).
- Undo/redo (MEDIUM).
- Syntax highlighting (LOW).

### sugar-spark

- C0/C1 control-code descriptions in Inspector output (§5 P1).
- SGR underline-style variants 4:1–4:5 (§5 P2).
- SOS/PM sequence handling (P3).
- JSON output format `reportAsJson()` (P4).
- Incremental/streaming parser `StreamingInspector` (P5).

### sugar-tick

- CSV/JSON export (HIGH).
- Tags on Heartbeat (HIGH).
- `.sugartrackignore` file (HIGH).
- Gaps detection / untracked-period reports (HIGH).
- SQLite backend, milestones, iCal export, auto-backups, theme system (MEDIUM).

### sugar-calendar

- Date range selection (§3.1, HIGH).
- Focus-based keyboard navigation (Phase 2).
- EventStore architecture.
- i18n (needs Lang.php; see §2).

### sugar-crumbs

- Implement missing `pushDirectory` / `view` / `filter` referenced by `examples/navigation.php` (P1).
- `Closable` + lifecycle hooks `onEnter` / `onLeave` (P2).
- URL/path derivation methods on `NavStack` (P3).
- Click-region rendering (P4) — see SSOT-04 (consume candy-zone).
- Separator escaping in titles (P5).
- aria-current / semantic HTML rendering (P6).

### sugar-boxer

- See SSOT-02. After consuming candy-sprinkles, the surface that's
  actually leftover: text alignment Left/Center/Right; margin (outer
  spacing).

### sugar-post

(Strategic. See §6.)

### sugar-glow

- Pygments/Scrutiny full syntax highlighting (P0).
- Streaming render for pager (P0).
- Glamour-style theme JSON (block_prefix/suffix, indent, margin, chroma block) (P1).
- File watching in pager (P1).
- CJK/emoji width handling via `mb_strwidth`/UnicodeString (P1).
- Additional stock themes (P2) — Solarized, Monokai, GitHub.

### sugar-wishlist

- `proxy_jump` field on `Endpoint` + `-J` flag (P1.1).
- `identity_files` array with fallback (P1.3).
- `description` rendering in `Picker::draw()` (P1.2) — property already exists.
- Import from `~/.ssh/config` (P2.1).

---

## 4. Sugar-dash — thorough plan

The audit found that the Phase-0 reorg from `plans/dash_update_claude.md`
**stalled mid-move**. The legacy `src/Grid/` namespace still holds 74
files (chart variants, layout enums, event plumbing, state) while
parallel modern subdirs (`Foundation/`, `Layout/`, `Components/`,
`Plot/`, etc.) hold partial copies. Every dashboard example imports the
legacy namespace.

Additionally, the **"dashboard" headline feature does not exist as a
runnable interactive program** — all 16 `examples/dashboard-*.php`
files are static `echo $grid->render(); exit;` mockups. There is no
event loop, no key wiring, no module-registered runtime. `dashboard-interactive.php`
is misnamed.

And the **`Plugin/ExternalModule.php` plugin runner is broken at the
language level** — calls `proc_get_status($this->process)['pipes']`, a
key that doesn't exist on the return shape of `proc_get_status()`. Any
external-process plugin invocation fails on the first request.

This is a multi-PR program. The recommended order:

### Dash-01 — Finish the Phase-0 reorg

**Why first:** Until `src/Grid/` is gone, "shipped"/"unshipped" claims
about features built in the new namespaces (`Layout/Tile/Resolver`,
`Layout/Boxer/Boxer`, `Plot/Braille/BrailleCanvas`, etc.) are unfalsifiable
— anyone can point at the parallel legacy file and say "we still have
this." The 74 stale files must be moved or deleted before any further
feature work.

**Scope:**

1. Inventory the 74 remaining `src/Grid/*.php` files. Bucket each:
   - **Move-only** (already has a modern home): delete the Grid version,
     update consumers. ~60% of the list.
   - **Move + rename** (modern home didn't exist): create under the
     right subnamespace, delete Grid version. ~30%.
   - **Delete** (legacy, no consumer): drop. ~10%.

2. Walk each file's reverse-deps with `grep -rn "use SugarCraft\\Dash\\Grid\\<Name>"`
   and update. **All 16 examples** are dependents — bulk rewrite under
   `examples/`.

3. Add `class Grid_Foo extends \SugarCraft\Dash\<NewNs>\Foo {}` aliases
   ONLY when grep finds a public extern call site (unlikely pre-1.0).
   Otherwise just delete.

4. Tests: `tests/Grid/` directory entirely removed; replaced with
   per-subnamespace tests. PHPUnit count stays the same or grows.

**Acceptance:**

- `find sugar-dash/src/Grid -type f` returns zero files (or only a single
  `Grid.php` BC stub if grep found an extern caller).
- `vendor/bin/phpunit` green.
- `examples/*.php` all run cleanly.
- `MATCHUPS.md` row for sugar-dash unchanged.

**Size:** [multi-PR, ~3]. Split as: Foundation primitives + layout
enums (one), chart family (one), event plumbing + state (one).

### Dash-02 — Fix `Plugin/ExternalModule.php`

**Bug:** `sugar-dash/src/Plugin/ExternalModule.php:139,153` reads
`proc_get_status($this->process)['pipes']`. `proc_get_status()` does
not include a `pipes` key — those come from the `&$pipes` byref param
to `proc_open()` and must be stored separately.

**Fix:**

1. Store the pipes array as `private array $pipes` in the class state.
2. Migrate the entire spawn to `\SugarCraft\Pty\Posix\PosixProcess` —
   the canonical process API from the PTY consolidation. PosixProcess
   gives us zombie-reaper safety, exit-code propagation, stderr capture,
   all for free.
3. Add `tests/fixtures/echo-plugin.sh` (or `.php`) — a 20-line program
   reading JSON from stdin, echoing back. The existing
   `tests/Plugin/ExternalModuleTest.php` must round-trip against this
   fixture (not against mocks).
4. Add `sugarcraft/candy-pty` to `sugar-dash/composer.json` `require`
   + path-repo.

**Acceptance:** integration test `ExternalModuleRoundTripTest` boots a
fixture process, sends `init`, `update`, `view` requests, asserts
correct response shape on each. No mocks.

**Size:** 1 PR.

### Dash-03 — SSOT pass on Foundation

Apply SSOT-01a..h from §1. After this, sugar-dash imports `Style`,
`Color`, `Buffer`, `Cell`, `Rect`, `Theme`, `StyleParser` from canonical
homes. The `src/Foundation/` directory shrinks to just `Drawable.php`,
`Item.php`, `Sizer.php` (sugar-dash-specific interfaces).

**Acceptance:** `wc -l sugar-dash/src/Foundation/*.php` shows < 200 LOC
total. Composer require lists `candy-vt` + `candy-sprinkles` + `candy-core`.

**Size:** 1 PR (combines a–h since they touch the same lib).

### Dash-04 — `Module\Module` aligns with candy-core `Model`

Apply SSOT-09 from §1. Rewrite the five built-in modules
(Clock/System/Greeting/Uptime/Generic) to return `[Module, ?Cmd]` from
`update()`. Keep `LegacyModule` as a one-release shim.

**Acceptance:** new module interface compatible with `Program::run($module)`
(after Dash-05 wires Program). `ModuleSpecConformanceTest` verifies every
built-in conforms.

**Size:** 1 PR.

### Dash-05 — Build the canonical interactive dashboard

**The headline feature.** A single example,
`examples/dashboard-live.php`, that demonstrates the full pipeline:

```php
// pseudo-code shape
$registry = (new Registry())
    ->register('clock',   fn() => new ClockModule())
    ->register('system',  fn() => new SystemModule())
    ->register('weather', fn() => new WeatherModule());

$layout = Boxer::create()
    ->createLeaf('header',  $registry->get('clock'))
    ->createLeaf('main',    $registry->get('system'))
    ->createLeaf('sidebar', $registry->get('weather'))
    ->withTree(Node::horizontal([
        Node::leaf('header')->sized(Size::fixed(3)),
        Node::vertical([
            Node::leaf('main')->sized(Size::weight(0.7)),
            Node::leaf('sidebar')->sized(Size::weight(0.3)),
        ])->sized(Size::weight(1.0)),
    ]));

$program = new Program(
    new DashboardModel($layout, new FocusManager(['header','main','sidebar'])),
    new ProgramOptions(altScreen: true, mouseMode: MouseMode::All),
);
$program->run(); // SugarCraft\Core\Program drives the loop
```

What it exercises:

- `\SugarCraft\Core\Program` event loop — proves modules mate with Core's
  Model contract (Dash-04).
- `\SugarCraft\Pty\TermiosFactory` + `\SugarCraft\Pty\SignalForwarder` —
  raw mode + SIGWINCH (zero direct `stty` shell-outs).
- `\SugarCraft\Dash\Layout\Boxer\Boxer` — bubbleboxer-style address tree
  + ModelMap (independent per-leaf updates).
- `\SugarCraft\Dash\Layout\Tile\Resolver` — 5-phase constraint resolver.
- `\SugarCraft\Dash\Layout\FocusManager` — tab/arrow focus rotation.
- 1Hz `TickMsg` driving Clock + System refresh; Weather refresh on a
  longer interval via the Module's `interval()` declaration.
- `q` / `Ctrl-C` for quit; `tab` / `arrows` for focus.

**Acceptance:**

- `php examples/dashboard-live.php` produces a working interactive
  dashboard on a real terminal.
- `tests/Examples/DashboardLiveTest.php` boots the program against
  a `candy-vcr`-recorded fixture session and replays a scripted
  sequence — quits cleanly with exit 0.
- VHS: `.vhs/dashboard-live.tape` produces a GIF of the running
  dashboard; `.github/workflows/vhs.yml` `all=(...)` matrix updated.

**Size:** 1 PR — but a meaty one; ~400 LOC across example + tests + tape
+ doc.

### Dash-06 — Add the `Weather` built-in module

Last missing module from the original plan. `src/Modules/Weather/WeatherModule.php`
hits `wttr.in` (or open-meteo); cache to disk; fall back on network
failure. Wraps `interval=1800` for half-hour refresh.

**Acceptance:** `tests/Modules/WeatherModuleTest.php` mocks the HTTP
client and asserts cache hit/miss behaviour. `examples/dashboard-live.php`
includes the weather panel.

**Size:** 1 PR.

### Dash-07 — `NotificationQueue` (Homedash dual-ring)

Apply the research's dual-ring pattern: `items[max 20]` (active,
dismissable) + `history[max 50]` (append-only). `current()` returns
head of items; `recent(n)` returns last n from history newest-first.
Replaces single-shot `Components/Toast/Notification.php`.

Wires into `dashboard-status.php` and `dashboard-live.php` so toasts
actually accumulate.

**Size:** 1 PR.

### Dash-08 — Responsive breakpoint helper

`Layout/Breakpoint.php` static helper:
`Breakpoint::narrow(int $width, int $threshold = 90): bool`. Wire into
`StackedGrid` (or its successor) so multi-column layouts collapse to
single-column under width 90 (per Homedash pattern).

**Acceptance:** `dashboard-live.php` correctly collapses to 1-column at
`COLUMNS=80`. Snapshot test at 80 and at 120.

**Size:** 1 PR.

### Dash-09 — `Plot/Plot.php::draw(Buffer)` writes cells directly

Currently `Plot.php::draw()` falls back to `render()` + `setString()` —
the slow path that defeats the Drawable contract. Rewrite to write
braille cells (`mb_chr(0x2800 + $bits)`) directly into the Buffer via
`setCell()`.

**Acceptance:** byte-level snapshot of a known braille line geometry
matches the expected sequence; `BrailleCanvasIntoBufferTest` passes.

**Size:** 1 PR.

### Dash-10 — `State/State.php` split

Two problems in one file:

1. PSR-4 violation — `State.php` declares `enum TransitionType`,
   `class StateNode`, `class StateTransition`, AND `class State`.
2. Conceptual misplacement — `State/State.php` is a **UML state-machine
   diagram widget** (boxes-and-arrows). The plan intended `src/State/`
   to hold **dashboard layout persistence**.

**Fix:**

- Move the UML widget into `Components/Tree/StateMachine.php` (split each
  class to its own file).
- Replace `src/State/State.php` with `src/State/Persistence.php` that
  implements Homedash atomic-save:
  ```php
  file_put_contents($tmp = $path . '.tmp', $json);
  rename($tmp, $path); // atomic on POSIX
  ```
- Wire `Boxer` / `FocusManager` / `StackedGrid` to save/load collapsed
  panel state + active tab.

**Acceptance:** PSR-4 violations gone. Persistence round-trips across
process restart in a test.

**Size:** 1 PR.

### Dash-11 — Chart duplication cleanup (SSOT-05)

Apply SSOT-05a-c. `sugar-dash/src/Plot/Chart/*` files that duplicate
sugar-charts get deleted; dash declares `sugarcraft/sugar-charts`
dependency.

**Acceptance:** `grep` shows no duplicate class names. sugar-dash test
suite green using imported sugar-charts versions.

**Size:** 1 PR.

### Dash-12 — Inline TD fixes

The CALIBER-flagged inline TDs (TD-1 readonly+wither, TD-3 chart `?? 20`
clip, TD-4 Treemap overflow, TD-5 `str_pad` ANSI byte-count, TD-7 OHLC
O(n²), TD-8 Y-label inversion) — knock these out one-per-PR or
combined; they accumulate visual regressions otherwise.

**Size:** 1–2 PRs depending on bundling.

### Dash-13 — VHS demos + golden snapshots

The plan's Phase 7 calls for snapshots at 80×24 and 120×40 for every
widget, plus VHS tapes. Today we have neither at scale.

**Approach:** Generate goldens in bulk via a `tools/generate-goldens.php`
helper that walks `examples/` and snapshots each. Hand-curated VHS for
the headline demos only (dashboard-live, plot-braille, gridtable,
boxer).

**Size:** [multi-PR, ~2].

### Dash-14 — Theme propagation

No widget receives a Theme today — colors are hardcoded hex literals in
each component's render. Add `Drawable::withTheme(Theme): self` (default
impl: pass-through). Layout containers fan the theme down.

Once landed, sugar-dash dashboards can switch dark/light at runtime via
a Msg.

**Size:** 1 PR (touches many widgets — bulk find/replace).

### Dash-15 — Cleanup: delete one-shot migration scripts

`sugar-dash/` root contains:
- `delete_grid_files.{php,py,sh}`
- `delete-moved-files.sh`
- `migrate.sh`
- `update-example-namespaces.php`
- `generate-tapes.php`

Once Dash-01 is done, these are dead. Move to `scripts/legacy/` (or
delete). Also rename `dashboard-interactive.php` →
`dashboard-accordion-timeline.php` so the name stops promising
interactivity (the *real* interactive demo lives at `dashboard-live.php`
post Dash-05).

**Size:** 1 PR.

---

## 5. Cross-cutting performance (defer until profiler says so)

Listed once here so the items don't get lost; do NOT block on these
until a real profiler trace flags the cost.

- `SplFixedArray` for Buffer cells (3MB vs 12MB at 200×60).
- Style flyweight: intern Styles by `(fg, bg, modifier)` triple.
- Render diffing: dirty-rect tracking per widget.
- Indexed `RingBuffer` (O(1) push) — already in `sugar-dash/src/Plot/RingBuffer.php`; verify it's indexed, not slice-shift.

---

## 6. Strategic-decision items (need a product call before any code)

- **sugar-post identity** — research describes a social-media TUI; the
  shipped lib is an email client. Pick one before adding features.
- **candy-serve TUI scope** — soft-serve's marquee feature is the
  interactive SSH-served repo browser. That is a substantial TUI app on
  its own; merits a `plans/candy-serve-tui.md` milestone doc.
- **candy-vt P3 graphics protocols** — Kitty image, Sixel decode. Each
  is months of work. Defer post-1.0 unless a specific app needs it.
- **candy-flip vs candy-mosaic image-output split** — animation rendering
  (P10) overlaps both libs. Recommendation: candy-flip emits frame
  bytes; candy-mosaic owns Kitty/iTerm2/WezTerm protocol output and
  consumes candy-flip's frames.

---

## 7. Sequencing — recommended PR order

Numbered groups can ship in parallel; items within a group must ship
serially (concurrent writes to `MATCHUPS.md` / `README.md` collide per
CLAUDE.md gotcha).

**Group A — SSOT foundation (sequence: 1 → 2 → 3 → 4 → 5):**

1. SSOT-01a — Move Theme into candy-sprinkles (new class).
2. SSOT-01g — Move StyleParser into candy-sprinkles.
3. SSOT-08a/b/c — `candy-palette::Probe` consolidation.
4. SSOT-09 — `Module\Module` aligns with `Model` (depends on SSOT-01a
   being available so Modules can use Theme).
5. Dash-03 (sugar-dash imports the new canonical primitives).

**Group B — sugar-dash core (sequence: D1 → D2 → D3=A5 → D4 → D5 → … D15):**

The Dash-01..15 list above, mostly serial. Dash-01 is multi-PR; the rest
are 1 PR each.

**Group C — Per-lib feature work (parallel; each lib own PR series):**

- candy-pty leftover items (see `plans/leftover_updates.md`).
- candy-sprinkles features (Color string parsing, HSL, markup parser, etc.).
- candy-bits Table sort/filter/paginate (Phase 2/3).
- candy-prompt validators + fuzzy autocomplete.
- candy-vt P0 items (DECSTBM, DECAWM, subparams).
- candy-zone hover/drag/click-counter.
- candy-mosaic quarter-block renderer + delete API.
- … (each lib's bullet list above is its own backlog.)

**Group D — i18n pass (parallel one-per-lib):**

One PR per lib introducing `Lang.php` + `lang/en.php` + replacing
inline strings. Approx 8 PRs.

**Group E — Strategic-decision items (blocked):**

Do not ship until product call:
- sugar-post identity
- candy-serve TUI scope plan
- candy-vt P3 graphics

---

## 8. Out of scope for this file

- **PTY plan items** — see `plans/leftover_updates.md`.
- **Documentation rewrites** of the research docs themselves. They are
  archival; mark items "✅ shipped 2026-MM-DD" inline as the plan lands.
- **Upgrade-guide / credit-upstream-author entries** — skipped per the
  user's persisted feedback. Pre-1.0; not the time.

---

## Tracking and review cadence

This file is the single backlog index for non-PTY post-consolidation
work. When picking up work:

1. Open the relevant lib's research doc (`docs/research/libraries/<lib>-research.md`)
   to re-read the original rationale before implementing.
2. Find the corresponding bullet in this file's §3.
3. Open one PR per bullet (or bundle 2–4 related bullets per the
   ship-as-you-go cadence).
4. Mark the bullet `✅ shipped (PR #NNN)` inline here when merged; do
   not delete — keeps the audit trail readable.

When a lib's bullet list goes to zero, mark the lib `**Status:** done`
at the top of §3 and consider whether the research doc itself wants a
"complete" footer.
