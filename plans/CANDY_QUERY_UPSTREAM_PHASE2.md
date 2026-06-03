# candy-query upstream-extraction — PHASE 2 (execution plan)

Self-contained worklist for a fresh session. Phase 1 (foundation + cleanups) is
DONE and committed; this file covers everything that remains. Read the original
analysis in `plans/CANDY_QUERY_UPSTREAM.md` for the "why".

## Progress log

- **DONE (this session, 8 commits on the branch, all suites green):**
  - STEP 0 — candy-forms dep + local path-repo closure (candy-buffer/forms/fuzzy/pty).
  - B2 — `Dash\Components\Card\DefinitionList` (+12 tests).
  - B6 — `Badge::bool()` / `Badge::tristate()` (+Badge tests).
  - A1 — query editor → `Forms\TextArea` (App.php; history-recall via Up/Down retired —
    Up/Down now move the editor cursor; `queryHistory` still recorded).
  - A2 — tables list → `Forms\ItemList` (dropped hand-rolled window + `↑ N–M of T ↑`).
    NOTE: used ItemList ALONE (it self-windows); a Viewport+Scrollbar would double-window
    and risk the exact-fill frame invariant.
  - A4 — pane titles → `Border::rounded()->withTitle()` (frame() + adminPane()).
  - sugar-table — added `Table::withSelectedIndex()` (+tests) for the rows cursor.
  - A3 — rows pane: table-browse → `sugar-table` (withSelectedIndex highlight); **ResultTable
    KEPT and wired** for executed-query results (App `resultTable` state, h/l + ←/→ scroll,
    loadTable clears it). New shared `CellValue` helper (display()+sanitize()) for binary/ANSI
    safety in BOTH grids. Fixed latent ResultTable numeric-column-name bug.
- **DONE (next session, 2 commits, all suites green):**
  - **A5 — ServerStatusPage** (`95a521db`): `ServerInfoCard` + the 6 panels → `Card` +
    `DefinitionList` (`->withPlaceholder('Unknown')`); `tristate()` delegates to
    `Badge::bool()`. No `ServerStatusPageTest` edits needed after all — its assertions are
    substring-based (Yes/No/Unknown) and `Badge::bool()` still emits those words. Header/
    footer → `Sprinkles\Style`; zero ANSI literals left. −90 net LOC.
  - **A6 — VariablesPage** (`58d64641`): tab bar → `Bits\Tabs` (labels keep `[Status]`/
    `[System]` bracket form; wide track to avoid the widget's ANSI-length truncation guard),
    search box → `Forms\TextInput` (`[search]` placeholder, driven from page state — no
    `update()` rewrite), category tree → `Forms\ItemList`, side-by-side →
    `Layout::joinHorizontal`, grid stays sugar-table via `Table::withSelectedIndex()`
    (dead `renderTableSimple` removed). **Wired sugar-bits as a candy-query dep** (require +
    `../sugar-bits` path-repo, added by hand — `--strict-closure` is too broad). −30 net LOC.
- **DONE (this session, 4 commits, all suites green — completes the 6 admin pages):**
  - **A7 — ReportsPage** (`6043f86a`): master-detail `renderCategoryTree` → `Bits\Tree`
    (built with `withSize(0,0)` so `Tree::view()`'s ANSI-stripping `Width::truncate`
    leaves the styled labels intact; the tree is display-only/unfocused, selection baked
    into labels); `renderSideBySide` → `Layout::joinHorizontal`; loading `◌` →
    `Forms\Spinner`; all `\x1b` literals → `Sprinkles\Style`. Grid stays sugar-table.
  - **A8 — PerfSchemaPage** (`84788be8`): the worst offender, 52 `\x1b` literals gone.
    7-tab bar → `Bits\Tabs` (`width 0` to dodge the ANSI-byte truncation guard; unbracketed
    labels + `│` divider = 78 visible cells, fits 80); the 4 toggle lists → one
    `renderToggleList()` over `Forms\ItemList` + `Badge::tristate()`; threads/timers tables →
    `sugar-table`; header/easy-setup state → `Badge`/`Style`; separators → `Dash\Divider`;
    names → `Width::truncateMiddle`. **Fixed a latent bug**: READ-ONLY/Pending indicators
    were single-quoted `'\x1b…'` literals (backslash-x text, not escapes) printing garbage.
  - **A9 — DashboardPage + C3** (`ebb510c7`): `assembleLayout` per-row concat + raw `│` →
    `Layout::joinHorizontal` with a full-height separator (dividers now form a straight rule);
    loading → `Forms\Spinner`; titles/widget truecolor/footer → `Style`+`Color`. **C3**:
    hardcoded `width=80,height=24` → `Renderer::getTerminalSize()` (the WindowSizeMsg via
    `setSize`), mirroring `Renderer::adminPane()`'s budget so it tracks resizes and the
    footer stays visible on small terminals. (NB `renderPanel` only uses region height.)
  - **A10 — ConnectionsPage** (`5794054b`): already sugar-table + zero ANSI; light polish —
    cyan header title + a `Sprinkles\Style` counters line (Usage colours red/green at the
    critical threshold). `Dash\Stat`/`Metric` skipped: Stat's HAlign is inverted and Metric
    is float-only, so neither renders the mixed int+`%` bar cleanly.
- **Baselines after A5/A6 (unchanged through A7–A10):** candy-core 637, candy-query **1092**,
  sugar-table 169, sugar-dash Badge/DefinitionList green.
  - ⚠️ Test gotcha: `tests/Admin/Variables/VariableEditorTest` fails (~7) when that *subdir*
    is run in isolation (FakeDatabase ordering artifact) — GREEN in the full suite. Trust the
    full `vendor/bin/phpunit` run.
- **DONE (this session, 2 commits, all suites green):**
  - **B5 — chart axis auto-scale** (`dde3d5c7` + `3e12358f`): new
    `SugarCraft\Charts\Chart\NiceScale::ceiling(float): float` static helper in sugar-charts
    (leading-digit-increment + zero-rest, 9xxx carries to 10xxx, floor 100; +12 tests →
    sugar-charts 381). `TimeSeriesCell` deletes its private `niceCeiling()` and calls
    `NiceScale::ceiling()`; the two `100.0` floor literals now ref `NiceScale::FLOOR`.
    candy-query 1092 green. No composer change (sugar-charts already a dep; symlinked
    path-repo picked up the new class with no `composer update`).
- **DONE (this session, 2 commits, all suites green):**
  - **B1 — `Kit\Frame` extraction** (`a72cfe9f` + `e18352aa`): new `SugarCraft\Kit\Frame`
    in candy-kit holds the generic full-screen chrome — double-line box, exact-height
    normalisation, ANSI-width-aware `padRight`/`padCenter`, constant line count, no
    `\x1b[2J`; slate box border via `Sprinkles\Style` (overridable `withBorderStyle()`),
    app-specific title/status passed in pre-rendered. `Frame::new()->withTitle()
    ->withStatus()->render($body,$cols,$rows)`. +17 tests → candy-kit 60. candy-query's
    `BorderFrame` is now a thin adapter (measure terminal → build title/status →
    delegate); Renderer call sites unchanged; dead `terminalWidth/Height()` + ported
    box/pad helpers deleted (~230 LOC); title/sep/info colours → `Sprinkles\Style` so
    **zero raw `\x1b` remains in `Terminal/`**. candy-kit wired by hand (require +
    path-repo; runtime closure candy-core+candy-sprinkles already present). Frame-diff
    invariants verified live: full Renderer render is exactly rows×cols. candy-query 1092.
- **DONE (this session, 1 commit, all suites green):**
  - **C4 — size detection thinned** (`ebacb2a7`): `Renderer::getTerminalSize()` re-rolled
    the FFI→env→stty→default ladder, but candy-core `PosixBackend::size()` already runs a
    fuller ladder and never returns a non-positive size — so the env/stty/default rungs
    were unreachable dead code. Now delegates to the `Tty` façade (picks Posix/Windows
    backend; identical to the old `PosixBackend(STDOUT)` path on Linux). WindowSizeMsg via
    `setSize()` stays the authoritative source of truth; hard default guards façade
    unavailability. −18 LOC. candy-query 1092. (Did NOT do the optional DashboardPage
    admin-content-width helper — left the C3 budget duplication as-is, intentionally.)
- **REMAINING:** none — B5, B1, C4 all complete. The 3-pane browser + all 6 admin pages
  render via upstream widgets (0 `\x1b` literals in any page); chart autoscale lives in
  sugar-charts (`Chart\NiceScale`); `BorderFrame` lives in candy-kit as `Kit\Frame`; size
  detection delegates to candy-core `Tty`. All suites green; `git diff master` net-smaller.
- **FOLLOW-UP (post-merge audit, branch `ai/candy-query-ansi-pager`):** the "0 `\x1b`
  literals" claim had 4 stragglers OUTSIDE the 6 page classes — `Admin/PageBase.php`
  (no-data/loading helpers) and `Admin/ServerStatus/SidebarGaugeSet.php` (header/divider) —
  now route through `Sprinkles\Style`. NB `Color::ansi()` emits **truecolor, not 4-bit**:
  map raw SGR fg `30–37 → ansi(c−30)`, `90–97 → ansi(c−82)` (so `33→ansi(3)`, `90→ansi(8)`,
  `36→ansi(6)`); `1;` prefix → `->bold()`. Zero raw `\x1b` now remains in `src/` outside
  `CellValue.php`. Also adopted the optional B-item: `ResultPager` delegates page arithmetic
  (count/bounds/clamp/next-prev) to `Bits\Paginator`; public surface kept, but paging past
  the last page is now a page-aligned no-op (was: slid to the final row) — +1 regression
  test. candy-query **1093** green.
- **FOLLOW-UP (C4 width-helper, branch `ai/candy-query-dash-width`):** did the optional
  C4 dedup after all — extracted `Renderer::adminContentWidth(int $cols): int` as the single
  source of truth for the admin page's content column (inner `cols−6` − sidebar
  `max(20, cols/4)` − 2-col gap, floor 10). `DashboardPage::build()` now calls it instead of
  re-deriving the budget; `adminPane()`'s `$sidebarWidth`/`$contentWidth` were
  computed-but-unused dead code and are deleted (only `$innerWidth` is consumed). Output is
  byte-identical (helper == old inline formula for all widths). +1 test
  (`testAdminContentWidthBudget`); frame-fill invariants stay green. candy-query **1094**.

## Where we are

- **Branch:** `ai/candy-query-upstream-extraction` (off `master`). `master` is the
  "before" for diffing. Keep working on this branch.
- **Phase 1 — DONE (6 commits, all green):**
  - `Core\Util\Format` (byte/duration/scale/picosecond) + `Width::truncateMiddle()` in candy-core.
  - `candy-query Admin\Format` now delegates to core; latent `0 !== 0.0` duration bug fixed.
  - `Dash\Foundation\Threshold` health-ramp in sugar-dash; `SidebarGauge::thresholdColor` adopts it.
  - `App.php` converted to the `Mutable` trait (932→679 lines).
  - dead `PerfSchemaRenderer` deleted.
- **Baseline green counts:** candy-core 637, candy-query 1091, sugar-dash Threshold 6/6.
  (sugar-dash `GoldenSnapshotTest` has 38 PRE-EXISTING failures on master — stale
  fixtures, ignore them; do not "fix" by editing unrelated goldens.)

## Key facts that change the risk picture

- **candy-query does NOT depend on `candy-forms`** — that is why it hand-rolls
  TextInput/TextArea/Viewport/Scrollbar/ItemList/Spinner. Step 0 below adds it.
- **candy-query tests are mostly SUBSTRING assertions**, not byte-exact goldens.
  `RendererTest` asserts `assertStringContainsString('SugarSQL' / 'users' /
  'alice' / 'switch pane' …)`. So adopting widgets rarely breaks a byte snapshot —
  it breaks only if a substring disappears. This makes the Renderer/admin-page
  rewrites far safer than a golden-locked codebase. Still run the full suite per step.
- **Frame-diff invariants (candy-core renderer) — DO NOT VIOLATE** when touching
  `BorderFrame`/full-screen output: constant total line count per frame, no line
  wider than the terminal, no mid-frame `\x1b[2J`. See the comments in
  `candy-query/src/Terminal/BorderFrame.php::wrap()`.

## Upstream widget catalog (verified APIs — use these, don't re-roll)

**candy-forms** (`SugarCraft\Forms\*`; requires candy-async/buffer/fuzzy/layout/sprinkles/core):
- `TextArea\TextArea`: `init/update/view/focus/blur/setValue/value/reset/withPlaceholder/
  withWidth/withHeight/withCharLimit/showLineNumbers/withPrompt/cursorUp/cursorDown/
  insertRune/insertString/lineCount/focused/cursor/...`
- `TextInput\TextInput`: `setValue/value/focus/blur/withPlaceholder/withPrompt/
  withHistory/addToHistory/withCharLimit/withWidth/withEchoMode/withValidator/
  withSuggestions/position/...` (built-in Up/Down history — use for the search box AND
  can back the SQL editor's history).
- `Viewport\Viewport`: `setContent/withSize/setWidth/setHeight/setYOffset/withScrollbar/
  withVerticalScrollbar/withMouseWheelEnabled/lineUp/lineDown/halfPageUp/halfPageDown/
  pageUp/pageDown/gotoTop/gotoBottom/scrollPercent/atTop/atBottom/...` (this is the
  "resizing panes with scrollbar").
- `Scrollbar\Scrollbar`: `withTrackChar/withThumbChar/withArrows/view`.
- `ItemList\ItemList`: `init/update/view/selectedItem/visibleItems/index/focus/blur/
  setItems/items/cursorUp/cursorDown/goToStart/goToEnd/prevPage/nextPage/select/
  withTitle/withShowStatusBar/withShowHelp/withShowFilter/withInfiniteScrolling/...`
- `Spinner\Spinner`: `view/tick/frame/withStyle`.

**sugar-bits** (`SugarCraft\Bits\*`; thin layer over candy-forms + own widgets):
- `Tabs\Tabs::new(array $labels, int $width=80)` → `update/view/active/withActive/withActiveStyle`.
- `Tree\Tree` (interactive master-detail tree), `Paginator\Paginator`, `Help\Help`
  (`shortView($keymap)`/`fullView`), `Key\Binding`/`KeyMap`.

**sugar-dash** (`SugarCraft\Dash\*`):
- `Components\Card\Card::new()/titled($c,$title)/withTitle/setSize/render`.
- `Components\Card\Badge::new/success/warning/error/info` + **TO ADD: `bool()`/`tristate()` (B6)**.
- `Components\Card\Divider::new/h/v($label)/render`; `Header::new/centered/hero`; `Footer`;
  `Kbd::new/single/combo`; `Stat::new/number/percent/currency`; `Metric`.
- `Components\Feedback\EmptyState::new/noResults/noData/error`; `LoadingText::new`.
- `Plot\Chart\Gauge`/`GaugeCircle`/`Meter` (already used) + `Foundation\Threshold` (added in P1).

**candy-sprinkles** (`SugarCraft\Sprinkles\*`):
- `Border::rounded()/normal()/double()` + `->withTitle(string, ?TitleAnchor)` / `->withTitles(map)`.
- `Style::new()->border(Border)->borderForeground(Color)->padding()->width()` (already used).
- `Layout::joinHorizontal(float $pos, ...$blocks)` / `joinVertical` / `place` (ANSI-width aware).
- `Canvas`+`Layer` (z-overlay), `Theme`/`Palette` (replace hardcoded hex).

**sugar-table** (`SugarCraft\Table\*`): `Table::withColumns([Column])->withRows([Row])->
  withSelectable()->withZebra()->withShowFooter(bool)->View()`; `Column::new(key,label,width)->
  withAlignLeft()->withFilterable()->withMaxWidth()`; `Row::new(RowData::from([...]))->withStyle('7')`.
  (Already used by ConnectionsPage/ReportsPage/VariablesPage.)

**candy-kit** (`SugarCraft\Kit\*`): `StatusLine`, `Section`, `Stage`, `Banner` — home for the
  new `Frame` (B1).

---

## STEP 0 — add the candy-forms dependency (do FIRST)

1. `candy-query/composer.json`: add `"sugarcraft/candy-forms": "@dev"` to `require`.
2. Add the path-repo closure. candy-forms pulls candy-async/candy-buffer/candy-fuzzy/
   candy-layout (candy-core/candy-sprinkles already present). Easiest:
   `php tools/check-path-repos.php --fix` from repo root, then eyeball the diff.
3. `cd candy-query && composer update sugarcraft/* --quiet` (local vendor is gitignored &
   goes stale — always update before trusting a local phpunit failure).
4. Verify: `vendor/bin/phpunit` still 1091 green (no usage yet, just wiring).
5. Commit: "candy-query: depend on candy-forms (wire path-repos)".

---

## A — adopt widgets in the 3-pane browser (Renderer + App)

Do these as 1 commit each; run `cd candy-query && vendor/bin/phpunit` after each.

### A1 — query editor → `Forms\TextArea`
- **Files:** `src/App.php` (`editQuery` L~298, `historyUp/historyDown` L~470-580,
  `favoriteQuery/unfavoriteQuery`, `dropLast`, `queryBuf`/`historyIndex`/`savedBuf` fields),
  `src/Renderer.php` (`queryPane` L~379 draws the `▮` cursor by hand), `tests/AppTest.php`.
- **Do:** replace the `queryBuf` string + manual char accumulation + history scrollback
  with a `Forms\TextArea` (or `TextInput` with `withHistory()` for single-line). Keep
  `queryFavorites` as domain state bound to the widget value. `editQuery` becomes
  "route KeyMsg to the widget, intercept Ctrl+R/Ctrl+E and favorite keys". Delete
  `historyUp/historyDown/savedBuf/dropLast`.
- **Tests:** AppTest asserts editQuery behavior (typing builds the buffer, Ctrl+R runs,
  Up/Down history). Rewrite those to drive the widget and assert `value()`/run effects.

### A2 — tables list + scroll window → `Forms\Viewport` + `Forms\ItemList`
- **Files:** `src/Renderer.php` (`tablesPane` L~166, `renderTableList` L~235 — the
  `↑ N–M of T ↑` indicators, centered window, slice), `tests/RendererTest.php`.
- **Do:** render the tables list via `ItemList` (owns cursor + selection styling) inside a
  `Viewport`(`withScrollbar()`) for the scroll window. Drop the hand-rolled indicators.
- **Tests:** substring-based — keep emitting table names + a selection marker so
  `assertStringContainsString('users' / selected styling)` still holds.

### A3 — rows pane → `sugar-table\Table`; retire `ResultTable`
- **Files:** `src/Renderer.php` (`rowsPane` L~283 — 3-phase column sizing), `src/ResultTable.php`
  (461 lines, standalone, NOT wired into the app, has `tests/ResultTableTest.php`),
  `cellString()` (keep — the BLOB/ANSI sanitizer at L~473 is genuinely needed; consider
  moving it to a small helper).
- **Do:** build a `sugar-table\Table` from `$a->rows` (mirror ConnectionsPage::buildTable).
  Delete `ResultTable` + its test (it duplicates sugar-table and isn't used by `App`), OR
  reduce it to a thin adapter if you want to keep the public class — prefer deletion.
- **Keep `cellString()`** for binary/ANSI safety; feed sanitized cells into the table.

### A4 — per-pane frame → `Border::withTitle()`
- **Files:** `src/Renderer.php` (`frame` L~505, `adminPane` frame L~451 — both put a bold
  title line INSIDE the border by hand).
- **Do:** `Style::new()->border(Border::rounded()->withTitle(' tables '))->borderForeground(...)`.
- **Tests:** substring — keep the title text present.

---

## B — additive upstream pieces still needed

### B2 — `Dash\Components\Card\DefinitionList` (NEW in sugar-dash)
- Aligned `label : value` rows with a `—`/`Unknown` placeholder for null. Composes inside `Card`.
- Add class + test + a `docs/lib` mention is not needed (component, not a lib).
- **Consumers:** `ServerInfoCard`, `ServerStatusPage` panels, `VariablesPage` header rows.

### B6 — `Badge::bool()` / `Badge::tristate()` (sugar-dash)
- Add to `Components\Card\Badge`: `bool(?bool, yes:'Yes', no:'No', unknown:'Unknown')` mapping
  true→success / false→error / null→subtle; and a `[x]/[ ]/[~]` glyph variant for PS toggles.
- Add tests. **Consumers:** `ServerStatusPage::tristate`, PerfSchema toggles.

### B5 — chart axis auto-scale into sugar-charts
- Move `TimeSeriesCell::niceCeiling()` (candy-query) into sugar-charts as auto-scale on
  `Streamline`/`Chart` (`->withAutoScale()` or a `NiceScale` helper). Adopt in TimeSeriesCell.
- Add test. Low risk (pure math).

### B1 — extract `BorderFrame` → `Kit\Frame` (candy-kit) — DO LAST of the visible work
- Move the full-screen chrome (title bar + dividers + status bar + pad/truncate to EXACT
  terminal height, constant line count) from `candy-query/src/Terminal/BorderFrame.php` into
  a new `SugarCraft\Kit\Frame`. The candy-query-specific content builders
  (`buildTitleBar`/`buildStatusBar`: dsn, version, table count) stay in candy-query and feed
  the generic Frame. Port `padRight`/`padCenter` (ANSI-width aware) too.
- **MUST preserve frame-diff invariants** (see top). Port BorderFrame's tests with it.
- New lib class ⇒ follow the add-to-existing-lib flow: src + test + README mention; update
  `MATCHUPS.md` only if it's a new upstream mapping (it's an internal primitive, likely not).

---

## A5–A11 — rewrite the 6 admin pages (one page per commit)

Each page hand-rolls header / tabBar / footer-keybar / separator / tristate / selectable list /
side-by-side. Replace with: `Bits\Tabs`, `Forms\ItemList`/`Bits\Tree`, `Dash\Card\Header`/
`Footer`/`Divider`/`Badge`/`Kbd`/`EmptyState`/`LoadingText` + new `DefinitionList`,
`Sprinkles\Layout::joinHorizontal` (NOT `str_pad`), `Forms\Spinner`. Suggested order
(simple→complex), each with its `tests/Admin/...PageTest.php` kept green:

1. **ServerStatusPage** (`src/Admin/ServerStatus/ServerStatusPage.php` + `ServerInfoCard.php`)
   — 6 panels → `Card`+`DefinitionList`; `tristate` → `Badge::bool`; separators/header/footer
   → Dash. Most repetitive, best first win.
2. **VariablesPage** (`.../Variables/VariablesPage.php`) — `renderTabBar`→`Bits\Tabs`,
   `renderCategoryTree`→`ItemList`/`Tree`, search box→`Forms\TextInput`,
   `renderSideBySide`→`Layout::joinHorizontal`, grid stays `sugar-table`.
3. **ReportsPage** (`.../Reports/ReportsPage.php`) — `renderCategoryTree` (master-detail)→
   `Bits\Tree`, `renderSideBySide`→`Layout::joinHorizontal`, loading→`Spinner`/`LoadingText`,
   grid stays `sugar-table`. (Note: known `withExport` no-op stub; also the ReportsPage had
   prior sugar-table API drift — see memory `project_candy_query_admin_async`.)
4. **PerfSchemaPage** (`.../PerfSchema/PerfSchemaPage.php`) — the worst offender: tabBar→
   `Bits\Tabs`, all the `[x]/[ ]` toggle lists→`ItemList`+`Badge::tristate`, manual `%-Ns`
   tables (threads/timers)→`sugar-table`, header/footer/separator→Dash, `…and N more`/middle-
   ellipsis→`Width::truncateMiddle` (already in core).
5. **DashboardPage** (`.../Dashboard/DashboardPage.php`) — keep `candy-layout` GreedySolver but
   (C3) feed it the REAL size instead of hardcoded `width=80,height=24`; `assembleLayout`
   str-join → `Layout::joinHorizontal`; loading screen → `Spinner`. Cells already use
   sugar-charts/sugar-dash.
6. **ConnectionsPage** (`.../Connections/ConnectionsPage.php`) — already uses sugar-table;
   smallest. Counters bar → `Dash\Stat`/`Metric`; tidy header/footer.

After all six: delete any now-unused helpers; re-check `Renderer::adminPane` sidebar →
`ItemList` grouped (or keep, it already uses `Layout::joinHorizontal`).

---

## C — remaining cleanups

- **C3 — DashboardPage real size:** done as part of A/DashboardPage above (thread
  WindowSizeMsg dims through instead of `width=80,height=24`).
- **C4 — size detection:** `Renderer::getTerminalSize()` (FFI→env→stty→default, L~60-103)
  duplicates candy-core `PosixBackend`. Keep `WindowSizeMsg` (forwarded via
  `Renderer::setSize`) as the single source of truth; thin the fallback ladder or push it
  into candy-core `Tty`. Low priority; do after the visible work.

---

## Sequencing (recommended)

0. STEP 0 (candy-forms dep) → 1 commit.
1. B2 (DefinitionList) + B6 (Badge bool/tristate) in sugar-dash → 1-2 commits (needed by pages).
2. A1, A2, A3, A4 (Renderer/App browser) → 1 commit each.
3. A5–A11 admin pages, ServerStatus→…→Connections → 1 commit each (folds in C3).
4. B5 (chart autoscale) → 1 commit.
5. B1 (Kit\Frame) → 1 commit. C4 → 1 commit.

## Verification (run per touched lib; vendor is gitignored & stale — `composer update sugarcraft/*` first)

```sh
cd candy-core    && vendor/bin/phpunit      # 637 baseline
cd candy-query   && vendor/bin/phpunit      # 1091 baseline
cd sugar-dash    && vendor/bin/phpunit --filter 'Threshold|Badge|DefinitionList'   # ignore 38 pre-existing GoldenSnapshot fails
cd candy-forms   && vendor/bin/phpunit
```
Also lint touched files: `php -l <file>`.

## Gotchas / house rules

- **Stale vendor → false failures:** `composer update sugarcraft/*` in the lib before
  trusting a local phpunit red.
- **Path-repo closure:** any new transitive `@dev` dep needs its path-repo in EVERY consuming
  `repositories[]`; `php tools/check-path-repos.php --fix`. `composer validate --strict` flags
  `@dev` — EXPECTED, drop `--strict`.
- **Don't touch the 38 pre-existing sugar-dash GoldenSnapshot failures** — they're on master too.
- **Frame-diff invariants** for any full-screen output (B1) — constant line count, no
  over-wide lines, no mid-frame `\x1b[2J`.
- **Ship-as-you-go:** bundle 2-4 related commits per PR; branch `ai/<slug>-<short>`; author
  `Joe Huss <detain@interserver.net>`; `unset GITHUB_TOKEN && gh ...`; end commit msgs with the
  `Co-Authored-By: Claude ...` trailer. **Skip Caliber on this machine** (do not run
  `caliber refresh`; if a hook stages Caliber files, unstage them).
- **One sub-agent at a time** if delegating — concurrent writes to MATCHUPS.md/README collide.

## Definition of done

candy-query renders the 3-pane browser + all 6 admin pages using upstream widgets, with no
hand-rolled tabBar/list/scroll/card/badge/separator/side-by-side and no raw `\x1b[…m` literals
in page code (use `Sprinkles\Style`/`Theme`). `ResultTable`/`ResultPager` retired or adapter-only.
`BorderFrame` lives in candy-kit as `Frame`. All lib suites green (modulo the 38 pre-existing
sugar-dash golden fails). `git diff master --stat` shows candy-query net-smaller.
