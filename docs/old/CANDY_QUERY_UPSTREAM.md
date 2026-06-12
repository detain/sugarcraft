# candy-query → upstream extraction plan

**Goal:** move the reusable TUI logic candy-query grew (pane framing, scroll
windows, tab bars, list/tree navigation, key-value cards, badges, gauges,
loading/empty states, number formatting, title/status chrome) into the
foundation libs, so any SugarCraft app can build a rich text UI without
re-rolling it — and so candy-query shrinks to *just* the database stuff.

## The core finding

candy-query reimplements ~90% of these primitives **by hand with raw `\x1b[…m`
escapes**, even though the foundation libs already ship them. The root cause is
mechanical: `candy-query/composer.json` requires only `candy-core`,
`candy-sprinkles`, `sugar-charts`, `sugar-dash`, `sugar-table`, `sugar-toast` —
**it does not depend on `candy-forms`**, where the interaction widgets live
(`sugar-bits` is now just deprecated `class_alias` shims pointing at
`SugarCraft\Forms\*`).

So this is **two workstreams**:

- **(A) ADOPT** — delete hand-rolled code and use the widget that already
  exists upstream. This is the bulk of the win and needs *no* upstream changes,
  only `composer require sugarcraft/candy-forms` + rewrites.
- **(B) EXTRACT/ADD** — a smaller set of genuinely-novel reusable pieces that
  upstream is missing; build them in the right lib so the next app gets them
  for free.

Verified upstream inventory (what already exists):

| Need | Already in | Class |
|---|---|---|
| Single-line text entry (+ history, suggestions, validation) | candy-forms | `Forms\TextInput\TextInput` |
| Multi-line editor | candy-forms | `Forms\TextArea\TextArea` |
| Scrolling viewport **with built-in scrollbar**, mouse wheel, pageUp/Down | candy-forms | `Forms\Viewport\Viewport` |
| Standalone scrollbar | candy-forms | `Forms\Scrollbar\Scrollbar` |
| Selectable list w/ cursor + scroll window | candy-forms | `Forms\ItemList\ItemList` |
| Loading spinner | candy-forms | `Forms\Spinner\Spinner` |
| Tab bar (Tab/Shift-Tab/1-9, mouse zones, overflow ellipsis) | candy-forms (alias sugar-bits) | `Bits\Tabs\Tabs` |
| Interactive tree (cursor nav, scroll) | sugar-bits/candy-forms | `Bits\Tree\Tree` |
| Pagination state + dots view | sugar-bits | `Bits\Paginator\Paginator` |
| Short/long help (keybar) | sugar-bits | `Bits\Help\Help` + `Key\Binding`/`KeyMap` |
| Data grid (selectable, zebra, footer, align, maxwidth) | sugar-table | `Table\Table` |
| Card / panel (titled box) | sugar-dash | `Dash\…\Card\Card` |
| Badge (success/warn/error/info) | sugar-dash | `Dash\…\Card\Badge` |
| Divider / separator (h/v, labelled) | sugar-dash | `Dash\…\Card\Divider` |
| Stat / metric (label+value, number/percent/currency) | sugar-dash | `Dash\…\Card\Stat`, `Metric` |
| Kbd hint chip | sugar-dash | `Dash\…\Card\Kbd` |
| Header / Footer chrome | sugar-dash | `Dash\…\Card\Header`/`Footer` |
| Empty state, loading text | sugar-dash | `Dash\…\Feedback\EmptyState`/`LoadingText` |
| Horizontal/vertical gauges, circular gauge, vertical meter | sugar-dash | `Dash\Plot\Chart\Gauge`/`GaugeCircle`/`Meter` |
| Streaming sparkline / line chart / timeseries | sugar-charts | `Charts\LineChart\Streamline`, `Sparkline`, `TimeSeries` |
| Titled bordered box | candy-sprinkles | `Sprinkles\Border::withTitle()` + `Style::border()` |
| Join blocks side-by-side / place in box | candy-sprinkles | `Sprinkles\Layout::joinHorizontal/place` |
| Z-ordered overlay/compositing | candy-sprinkles | `Sprinkles\Canvas` + `Layer` |
| Color theme / palette | candy-sprinkles | `Sprinkles\Theme`/`Palette` |
| Constraint layout solver | candy-layout | `Layout\GreedySolver` + `Constraint` |
| Toast notifications | sugar-toast | `Toast\Toast` (already used by `AlertNotifier`) |
| Status line, banner, sectioned chrome | candy-kit | `Kit\StatusLine`, `Section`, `Stage`, `Banner` |

---

## Part A — ADOPT existing upstream widgets (delete hand-rolled code)

Prereq for all of these: **add the dependency**
`composer require sugarcraft/candy-forms` (and add the path-repo to
`candy-query/composer.json` `repositories[]` per the monorepo rule), since the
interaction widgets are the biggest chunk.

### A1. Query editor → `candy-forms` TextArea/TextInput
- **Now:** `App.php` `editQuery()` (L298-333) accumulates SQL char-by-char,
  `historyUp()/historyDown()` (L614-725) re-implement history scrollback with a
  `savedBuf` sentinel, `favoriteQuery()/unfavoriteQuery()` (L727-773),
  `dropLast()` UTF-8 backspace (L775-783); `Renderer::queryPane()` draws a
  `▮` cursor by hand.
- **Change:** hold a `Forms\TextArea\TextArea` (multi-line SQL) — it already has
  cursor, UTF-8 backspace, and `TextInput` already ships `withHistory()` /
  `addToHistory()` / Up-Down history. Favorites stay as candy-query domain
  state but bind to the widget's value. Deletes ~250 lines of `App.php`.

### A2. Tables list + scroll window → `candy-forms` Viewport + ItemList
- **Now:** `Renderer::tablesPane()`/`renderTableList()` (L166-276) compute a
  centered scroll window, render `↑ N–M of T ↑` indicators, clamp the cursor,
  slice the array — all by hand. Every admin list re-does this as
  "…and N more" (PerfSchemaPage L900-902, threads L1036-1038).
- **Change:** put the list in a `Forms\ItemList\ItemList` inside a
  `Forms\Viewport\Viewport` (`withScrollbar()`); Viewport owns the window,
  scroll indicators become a real scrollbar gutter. This is the **"resizing
  panes with scrollbar"** the task mentions — done once, reused everywhere.

### A3. Rows pane manual table → `sugar-table` Table
- **Now:** `Renderer::rowsPane()` (L283-377) does 3-phase column measuring,
  proportional expansion, width-aware truncation/padding by hand. `ResultTable`
  (461 lines) is a *second* hand-rolled grid with horizontal scroll.
- **Change:** render rows through `sugar-table` `Table` (already used by
  ConnectionsPage/ReportsPage/VariablesPage) — it does width, align, maxwidth,
  zebra, footer. Retire `ResultTable` or reduce it to a thin adapter.

### A4. Per-pane frame → `Sprinkles\Border::withTitle()`
- **Now:** `Renderer::frame()` (L505-513) and `adminPane()` (L451-457) put a
  bold title line *inside* a bordered Style by hand.
- **Change:** `Style::new()->border(Border::rounded()->withTitle(' tables '))`
  — the **"window/pane title bar"** is a first-class border feature already.

### A5. Tab bars → `Bits\Tabs\Tabs`
- **Now:** three hand-rolled tab bars: `VariablesPage::renderTabBar()`
  (L431-453), `PerfSchemaPage::renderTabBar()` (L800-816) + its
  `withNextTab/withPrevTab` modulo state machine (L226-250),
  `PerfSchemaRenderer::tabBar()` (L188-203).
- **Change:** one `Bits\Tabs\Tabs` per page — it already handles Tab/Shift-Tab,
  1-9 jump, active styling, mouse zones, and overflow ellipsis.

### A6. Search box → `candy-forms` TextInput
- **Now:** `VariablesPage` tracks `searchQuery`/`searchFocused`, appends chars,
  backspaces, draws a `_` cursor (L166-174, L276-290, L444-452).
- **Change:** a focused `Forms\TextInput\TextInput`.

### A7. Loading / empty states → `Forms\Spinner` + `Dash\Feedback\*`
- **Now:** hand-rolled `◌ Loading…` in `PageBase::loadingScreen()` (L67-70),
  `DashboardPage::renderLoadingScreen()` (L89-100), `ReportsPage` (L344-348);
  `(no tables)`/`(empty)`/`(no data)` literals scattered.
- **Change:** `Forms\Spinner\Spinner` + `Dash\…\Feedback\LoadingText` /
  `EmptyState` (`::noData()`, `::noResults()`).

### A8. Headers / footers / separators / keybars → `sugar-dash` + `Bits\Help`
- **Now:** every page hand-rolls `renderHeader()` (raw `\x1b[1;36m…`),
  `renderSeparator()` (`──` + 20×`─`, duplicated verbatim ≥5×: ServerInfoCard
  L76-79, ServerStatusPage L256-259, PerfSchemaRenderer L63-66, PerfSchemaPage
  L1090-1093), and `renderFooter()` keybars (every page + PerfSchemaRenderer
  L212-225).
- **Change:** `Dash\…\Card\Header`/`Footer`/`Divider` for chrome; the keyboard
  hint line is `Bits\Help\Help` driven by `Key\Binding`/`KeyMap`, or
  `Dash\…\Card\Kbd` chips. Define each page's keymap once.

### A9. Cards / key-value panels → `sugar-dash` Card (+ new DefinitionList, B2)
- **Now:** `ServerInfoCard` (152 lines) and `ServerStatusPage`'s six panels
  (Features/Directories/SSL/Replication/Firewall, L120-249) are all
  "title + separator + `%-20s label : value` rows + Unknown/— placeholder".
- **Change:** wrap each panel in `Dash\…\Card\Card`; rows via the new
  `DefinitionList` (B2). Badges (Yes/No/Unknown) via new `Badge::bool()` (B6).

### A10. Side-by-side layout → `Sprinkles\Layout::joinHorizontal`
- **Now:** four hand-rolled column-joiners with `str_pad`:
  `VariablesPage::renderSideBySide()` (L558-577),
  `ReportsPage::renderSideBySide()` (L424-443),
  `DashboardPage::assembleLayout()` (L292-324),
  `Renderer::adminPane()` already uses `Layout::joinHorizontal` (L448) — so the
  others are needless re-rolls.
- **Change:** `Sprinkles\Layout::joinHorizontal(Position::TOP, …)` everywhere
  (handles ragged heights and ANSI-aware width).

### A11. Selectable list rows → `candy-forms` ItemList / `Bits\Tree`
- **Now:** `>`/`▶`/reverse-video selection markers re-rolled in
  `Renderer::renderTableList` / `adminPane`, `PerfSchemaPage` (instruments
  L880-898, consumers/actors/objects/threads), `ReportsPage::renderCategoryTree`
  (L314-336, master-detail with `>`/`*`), `VariablesPage::renderCategoryTree`
  (L466-493), `PerfSchemaRenderer::selectedRow` (L84-91).
- **Change:** `Forms\ItemList\ItemList` (flat) / `Bits\Tree\Tree` (master-detail
  category→report). Both own cursor + selection styling + scroll.

### A12. Pagination → `Bits\Paginator` ; hardcoded colors → `Sprinkles\Theme`
- `ResultPager` (150 lines) duplicates `Bits\Paginator\Paginator`
  (`sliceBounds()`, next/prev, totalPages). Adopt or delete.
- The `#7d6e98`/`#fde68a`/`#6ee7b7`/`#00ffaa`/`#ff66aa` hexes hardcoded across
  `Renderer`, `BorderFrame`, every page → a single `Sprinkles\Theme`/`Palette`
  so apps can restyle. (BorderFrame even hardcodes SGR like `\x1b[1;36m`.)

### A13. List-nav / tab-cycle state machines → owned by the widgets
- The repeated `withNavigateDown/Up` clamp (VariablesPage L257-274, ReportsPage
  L216-243, PerfSchemaPage L290-307) and `Pane::next()` / `AdminPane::next()` /
  tab modulo all vanish once ItemList/Tabs own their own cursor.

---

## Part B — EXTRACT / ADD genuinely-missing reusable pieces

These are the things worth *moving up* because upstream truly lacks them.

### B1. Full-screen app chrome → **new `Kit\Frame`** (candy-kit)
- **Source:** `Terminal/BorderFrame.php` (327 lines). It does something no
  upstream widget does: pad/truncate content to **exactly** terminal height,
  draw a `╔═ title ═╗` bar + `╠═╣` dividers + status bar, keep a **constant
  line count** so candy-core's frame-diff renderer never desyncs (the off-by-one
  + hard-truncate logic at L75-108), with ANSI-width-aware `padRight`/`padCenter`
  (L277-326).
- **Extract to** `candy-kit` as `Kit\Frame` (it already owns `StatusLine`,
  `Section`, `Banner`): `Frame::new()->title(…)->status(…)->render($body,$cols,$rows)`.
  The title/status *content builders* (`buildTitleBar`/`buildStatusBar`,
  candy-query-specific: dsn, version, table count) stay in candy-query and feed
  the generic Frame. This is the **"window / title bar"** the task calls out.

### B2. Definition list / key-value panel → **new `Dash\…\Card\DefinitionList`**
- **Source:** the `%-20s label : value` row pattern in ServerInfoCard /
  ServerStatusPage / VariablesPage. sugar-dash has `Stat` (single) and `Card`
  (box) but no aligned label/value list with a "missing" placeholder.
- **Add** `Dash\…\Card\DefinitionList` (auto-aligns label column, styles value,
  renders `—`/`Unknown` for null). Composes inside `Card`.

### B3. Threshold coloring → **`withThresholds()` on Gauge/Meter + `Palette` helper**
- **Source:** green/yellow/red-by-ratio duplicated in
  `SidebarGauge::thresholdColor` (L95-111), `PerfSchemaRenderer::formatPercentage`
  (L303-312), DashboardPage. 
- **Add** a `Threshold`/stops helper in sugar-dash `Foundation` and
  `Gauge/GaugeCircle/Meter::withThresholds([0.6=>green,0.8=>yellow,1.0=>red])`
  so the gauge colors itself. Removes the per-call color math.

### B4. Number / byte / duration / picosecond formatting → **move `Format` to candy-core**
- **Source:** `Admin/Format.php` (`scaleValue` K/M/G/T, `siBytes`, `duration`,
  `picoseconds`) — fully generic, and **re-duplicated** in
  `PerfSchemaRenderer::formatDuration` (L281-296) and CounterCell.
- **Move to** `SugarCraft\Core\Util\Format` (or candy-metrics) so charts/dash/
  metrics/any app share it. Delete the duplicate.

### B5. Chart axis auto-scale ("nice ceiling") → **into sugar-charts**
- **Source:** `TimeSeriesCell::niceCeiling()` (L187-201) rounds a max up to a
  round ceiling for the sparkline axis.
- **Move to** sugar-charts as axis autoscaling on `Streamline`/`Chart`
  (`->withAutoScale()`), so every chart user gets nice axes.

### B6. Boolean / tri-state badge → **`Badge::bool()` / `Badge::tristate()`** (sugar-dash)
- **Source:** `ServerStatusPage::tristate` (Yes/No/Unknown, L271-282),
  `PerfSchemaRenderer::tristate`/`enabled`/`stateLabel` (L24-58),
  `PerfSchemaPage::renderTristate` (`[x]`/`[ ]`/`[~]`, L1080-1088).
- **Add** to sugar-dash `Badge`: `Badge::bool(?bool, yes:'Yes',no:'No')` and a
  tri-state/checkbox glyph variant. (sugar-dash already has Badge
  success/warn/error.)

### B7. Middle-ellipsis truncation → **`Width::truncateMiddle()`** (candy-core)
- **Source:** path/instrument shortening (`…`+tail) in
  `PerfSchemaRenderer::instrumentName` (L99-112), `ServerStatusPage::resolveDir`
  (L322-334), PerfSchemaPage L885-887/L1021-1023. candy-core `Util\Width` has
  end-truncate but not middle.
- **Add** `Width::truncateMiddle($s, $cols)` (display-width aware).

### B8. (Optional) Master-detail split scaffold → candy-forms helper
- ReportsPage + VariablesPage are both "tree on the left, grid on the right,
  j/k drives the active side". Once B/A parts land they're just
  `Layout::joinHorizontal(Tree, Table)`; if the pattern recurs, add a small
  `Forms\Split\MasterDetail` convenience. Low priority.

---

## Part C — candy-query internal cleanups (not upstream, but unblock the above)

- **C1. `App.php` immutability boilerplate.** ~930 lines, dominated by repeated
  full `new self(…)` constructions in every `with*()`/`handle*()`. Convert to
  the canonical `mutate()` helper / `candy-core\Concerns\Mutable` trait
  (`.claude/rules/model-pattern.md`). Mechanical but large; do it before A1/A2
  rewrites so the diffs are sane.
- **C2. Delete dead `PerfSchemaRenderer`.** It's a full helper file that
  `PerfSchemaPage` ignores (re-rolls every helper inline). After B6/A5/A8 it has
  no reason to exist.
- **C3. DashboardPage ignores real size.** It hardcodes `width=80,height=24`
  (L106-107) instead of consuming the `WindowSizeMsg` size that `Renderer`
  already tracks. Thread the real dimensions through (it already uses
  `candy-layout` `GreedySolver`, so just feed it a real `Region`).
- **C4. Size detection.** `Renderer::getTerminalSize()` (FFI→env→stty→default,
  L60-103) duplicates logic that belongs to candy-core's `PosixBackend` (already
  used). Keep `WindowSizeMsg` as the single source of truth; drop the static
  cache fallback ladder or push it into candy-core `Tty`.

---

## Suggested sequencing (dependency order)

1. **C1** (App.php → mutate trait) — makes every later diff readable.
2. **B4** (Format→candy-core) + **B7** (truncateMiddle) — tiny, unblock dedup.
3. **Add `candy-forms` dependency**, then **A1/A2/A3/A4** (editor, list+viewport,
   rows table, titled borders) — the visible 3-pane browser, biggest payoff.
4. **A7/A8/A10/A11** + **B2/B6** — admin page chrome (cards, badges, lists,
   side-by-side, headers/footers). Do one page at a time
   (ServerStatus → Variables → Reports → PerfSchema → Dashboard → Connections).
5. **A5/A6** (tabs, search), **B3** (gauge thresholds), **B5** (chart autoscale).
6. **B1** (`Kit\Frame`) — extract BorderFrame last; it's the outer shell and
   needs the inner panes stable first.
7. **C2/C3/C4** cleanups.

## Risk notes
- Each upstream add (B1-B7) is a normal SugarCraft lib change: new class + test +
  README/MATCHUPS rows, shipped as its own small PR per the ship-as-you-go rule
  (bundle B4+B7, B2+B6, etc.).
- The frame-diff invariants matter: `Kit\Frame` must preserve "constant line
  count, no over-wide lines, no mid-frame `\x1b[2J`" (see
  `BorderFrame::wrap` comments and the TUI render-invariants memory) or SSH/tmux
  sessions desync. Port the existing tests with it.
- Adopting `Forms\Viewport` for scrolling is the highest-value, highest-surface
  change — gate it behind golden-file tests of a few representative pane sizes.
