# Startup prompt — paste into a fresh session (cwd: /home/sites/sugarcraft)

---

We're finishing **candy-query: stop hand-rolling TUI primitives, adopt the SugarCraft
foundation libs**, plus moving genuinely-reusable bits up into those libs. Phase 1
(foundation + cleanups), the **Phase 2 browser milestone**, and **all 6 admin pages
(A5–A10)** are done and committed. Only the **tail items remain: B5 (chart autoscale →
sugar-charts), B1 (`BorderFrame` → `Kit\Frame`, the big/risky one), C4 (size detection,
low priority)**. **Start at B5.**

**Before doing anything, read these in full — they are the spec:**
1. `plans/CANDY_QUERY_UPSTREAM_PHASE2.md` ← detailed execution plan + a **Progress log**
   at the top recording exactly what's done (start here).
2. `plans/CANDY_QUERY_UPSTREAM.md` ← the original analysis (the "why"); see §B5 and §B1.
3. Skim recent commits: `git log --oneline master..HEAD`.

**Working context:**
- Branch is already `ai/candy-query-upstream-extraction` (off `master`). Stay on it.
  `master` is the "before" for `git diff master`. Confirm with `git branch --show-current`.
- **Green baselines (run before touching a lib):** candy-core **637**, candy-query **1092**
  (1 skipped), sugar-table **169**, sugar-charts **369**, candy-kit **43**, sugar-dash
  Badge/DefinitionList green.
- sugar-dash has **38 PRE-EXISTING `GoldenSnapshotTest` failures** on master — ignore them,
  don't "fix" unrelated goldens.
- candy-query tests are mostly **substring** assertions (not byte-exact goldens), so widget
  adoption is low-risk — but run the full lib suite after every step.
- **Test gotcha:** `tests/Admin/Variables/VariableEditorTest` fails (~7) when that *subdir* is
  run alone (FakeDatabase ordering artifact) — GREEN in the full suite. Trust the full
  `vendor/bin/phpunit` run, not a subdir run.
- **Stale vendor:** `vendor/` is gitignored and goes stale — `composer update sugarcraft/*`
  in the lib before trusting a local red.

**What's already DONE (do NOT redo — see the plan's Progress log for detail):**
- STEP 0 (candy-forms dep), B2 (`Dash\Card\DefinitionList`), B6 (`Badge::bool/tristate`),
  B3 (`Dash\Foundation\Threshold`), C1 (App → `Mutable` trait), C2 (deleted dead
  `PerfSchemaRenderer`), B4/B7 (`Core\Util\Format` + `Width::truncateMiddle`).
- A1–A4 (3-pane browser): query editor → `Forms\TextArea`, tables list → `Forms\ItemList`,
  rows → `sugar-table`, pane titles → `Border::withTitle()`. **`ResultTable` is KEPT and
  wired** for executed-query results (NOT deleted). Binary/ANSI safety unified in
  `candy-query/src/CellValue.php` (use it).
- **All 6 admin pages (A5–A10) render via upstream widgets — 0 `\x1b` literals in any page:**
  A5 ServerStatus → `Card`+`DefinitionList`+`Badge::bool`; A6 Variables → `Bits\Tabs`
  +`Forms\TextInput`/`ItemList`+`joinHorizontal`; A7 Reports → `Bits\Tree`+`joinHorizontal`
  +`Forms\Spinner`; A8 PerfSchema → `Bits\Tabs`+`Forms\ItemList`+`Badge::tristate`+`sugar-table`
  (52 literals gone; **fixed a latent single-quote `'\x1b…'` bug** that printed garbage);
  A9 Dashboard → `joinHorizontal`+`Forms\Spinner` + **C3 done**; A10 Connections → cyan header
  + `Sprinkles\Style` counters bar.

**Reusable lessons from this session (use these — they'll save you debugging):**
- **width-0 trick:** both `Bits\Tree` and `Bits\Tabs` truncate `view()` on **ANSI-inclusive**
  length when `width > 0` (Tree via the ANSI-*stripping* `Width::truncate`; Tabs via a final
  `mb_strlen` guard). Build them with **width 0** to keep styled labels intact when you don't
  need truncation. `Forms\ItemList::view()` does NOT truncate — styled labels are always safe
  there; the selected row gets `cursorPrefix` + REVERSE (`select($idx)` sets cursor + scrolls).
- **deps already wired** in `candy-query/composer.json`: candy-core, candy-forms, candy-sprinkles,
  sugar-bits, sugar-charts, sugar-dash, sugar-table, sugar-toast. **candy-kit is NOT a dep yet**
  — B1 adds it (by hand; exact wiring below).
- **C3 coupling:** `DashboardPage::build()` reads `Renderer::getTerminalSize()` and mirrors
  `Renderer::adminPane()`'s width budget (sidebar = `cols/4`, frame+gap ≈ 8) — duplicated on
  purpose; keep in sync (relevant to C4). NB `DashboardPage::renderPanel` only uses region
  *height*, not width.
- **A10 footnote (if you revisit it):** the counters bar uses `Sprinkles\Style`, not
  `Dash\Stat`/`Metric`. `Stat::padOrTruncate` has an **inverted HAlign** (Left/Center
  right-justify; only Right left-justifies) and `Metric` is float-only. For metric *cards*,
  fix `Stat`'s align in sugar-dash first (Stat is unused in prod; its test doesn't assert
  output — low-risk) + add an output test.
- **Verify the chrome:** candy-query tests do NOT assert rendered widget chrome, so each step
  this session was sanity-checked with a tiny isolated `php -r`/heredoc render preview
  (Tree ANSI-preservation, the 7-tab fit at 78 cols, ItemList selection, joinHorizontal
  separator alignment). Do the same for any visible B1 output.

---

## START HERE — remaining work, in order (one logical change per commit)

### 1. B5 — chart axis auto-scale → sugar-charts  (LOW RISK, pure math; DO FIRST)

- **Source to move:** `candy-query/src/Admin/Dashboard/TimeSeriesCell.php:187`
  `private function niceCeiling(float $max): float`. Algorithm: `$max <= 0` → `100.0`; else
  stringify `(int)$max`, **increment the first digit** (cap at 10), **zero the remaining
  digits**, then `max($scale, 100)` (floor 100). e.g. `4500 → 5000`, `45 → 100` (floor),
  `95000 → 100000`, `9.5 → 100`.
- **Consumers in the cell:** `ingest()` L84 (`$this->ceiling = $this->niceCeiling($this->maxSeen)`),
  `view()` L168 (`->withMax($this->ceiling)`), `reset()` L116 (`ceiling = 100`). The cell drives
  `SugarCraft\Charts\LineChart\Streamline` (`::new($w,$h)`, `push(int|float)`, `pushAll`,
  `clear()`, `withMin(?float)`, `withMax(?float)`, cast-to-string).
- **Target (sugar-charts):** add a reusable static helper — suggest
  `SugarCraft\Charts\Chart\NiceScale::ceiling(float $max): float` (the `src/Chart/` dir already
  exists with namespace `SugarCraft\Charts\Chart`). Alternative the plan also allows:
  `Streamline::withAutoScale()` that computes the ceiling from its pushed window. Prefer the
  standalone `NiceScale` helper (reusable across BarChart/Sparkline/etc., trivially testable).
- **Test (sugar-charts):** assert `ceiling(0)==100`, `ceiling(45)==100`, `ceiling(4500)==5000`,
  `ceiling(95000)==100000`, `ceiling(9.5)==100`, plus a 1-digit and a 9-leading case
  (`ceiling(9000)==10000`). Pure math, deterministic.
- **Adopt:** delete `TimeSeriesCell::niceCeiling()` and call `NiceScale::ceiling(...)` at L84.
- **No composer change** (sugar-charts already a candy-query dep). Run sugar-charts (369) +
  candy-query (1092). **1–2 commits** (sugar-charts add+test, then candy-query adopt).

### 2. B1 — extract `BorderFrame` → new `Kit\Frame` (candy-kit)  (THE BIG ONE — go carefully)

- **Source:** `candy-query/src/Terminal/BorderFrame.php` (327 lines). One public entry
  `BorderFrame::wrap(App $a, string $content): string`, called from **`Renderer.php:136`**
  (admin-pane branch) and **`Renderer.php:166`** (standard 3-pane branch).
- **GENERIC chrome → move into `Kit\Frame`:**
  - the double-line box: `topBorder`/`bottomBorder`/`divider` (`╔═╗ ║ ╚═╝ ╠═╣`),
  - `padCenter`/`padRight` — **ANSI-width-aware** (measure by display CELLS via
    `Width::string`; truncate with `Width::truncate` + `…` + `Ansi::reset`; then re-pad because
    truncate can stop short of a wide glyph),
  - the **exact-height normalisation**: `frameOverhead = 6` (top + title + 2 dividers + status +
    bottom), `availableContentHeight = max(0, rows - 6)`, hard-truncate content taller than that
    and blank-pad shorter content → the whole frame is **always exactly `rows` lines**
    (constant line count the candy-core frame-diff renderer relies on).
  - **No `\x1b[2J`** anywhere (deliberate — see the L63-68 comment; the Program's diff renderer
    owns the screen).
- **candy-query-SPECIFIC → STAYS in candy-query, feeds the Frame:** `buildTitleBar($a,$width)`
  (`SugarSQL │ Tables:N │ dsn │ version`), `buildStatusBar($a,$width)` (keybar from `$a->pane`/
  `$a->adminPane`/`$a->paused`), `serverVersion($a)`. These take `App`, so they can't move.
- **Suggested API:** `Kit\Frame::new()->title(string $titleBar)->status(string $statusBar)
  ->render(string $body, int $cols, int $rows): string`. candy-query keeps a thin
  `BorderFrame::wrap()` (or inlines in Renderer) that builds the title/status strings and the
  cols/rows from `Renderer::getTerminalSize()`, then delegates to `Kit\Frame`.
- **Colors:** `BorderFrame::const C` are raw `\x1b` (border/title/sep/info/reset). Move the **box
  border** colour into `Kit\Frame` (use `Sprinkles\Style`/`Theme` — candy-kit already requires
  candy-sprinkles). Convert the **title/sep/info** colours used by buildTitleBar/buildStatusBar
  to `Sprinkles\Style` too while you're in there — that removes the LAST raw `\x1b` from
  candy-query's `Terminal/`.
- **DEAD CODE to delete during extraction:** `BorderFrame::terminalWidth()` (L207) and
  `terminalHeight()` (L240) are unused — `wrap()` uses `Renderer::getTerminalSize()` directly.
  (Also dedups vs C4.)
- **Wire candy-kit into candy-query BY HAND** (do NOT run `check-path-repos --fix
  --strict-closure` — it rewrites all ~56 libs):
  - `candy-query/composer.json` → add `"sugarcraft/candy-kit": "dev-master"` to `require`, and
    `{ "type": "path", "url": "../candy-kit", "options": { "symlink": true } }` to
    `repositories`.
  - candy-kit's only **runtime** deps are `candy-core` + `candy-sprinkles` — **both already in
    candy-query's require + repos**, so no other path-repos are needed.
  - Then `cd candy-query && composer update sugarcraft/* --quiet` and `composer validate` (drop
    `--strict` — it flags every `@dev`, expected).
- **candy-kit conventions:** namespace `SugarCraft\Kit\`; `src/` already has `Banner`,
  `HelpText`, `Logo`, `Section`, `Stage`, `StatusLine`, `Theme` — match them (final class,
  `::new()`, `render()`). Add `candy-kit/src/Frame.php` + `candy-kit/tests/FrameTest.php`
  (namespace `SugarCraft\Kit\Tests`) + a README mention. Internal primitive — likely no
  MATCHUPS row.
- **MUST preserve frame-diff invariants:** constant total line count per frame (= `rows`),
  **no line wider than the terminal** (padRight/padCenter measure by display CELLS), no mid-frame
  `\x1b[2J`. BorderFrame has **no dedicated test** — its behaviour is covered by
  `candy-query/tests/RendererTest.php`: `testFrameFillsTerminalExactly` (L150),
  `testNoRenderedLineExceedsTerminalWidth` (L100), `testRenderSanitizesControlBytesFromBlobData`
  (L80) — **keep these green**. Write **fresh `Kit\FrameTest`**: exact-height fill at rows ∈
  {24,48}, constant line count, over-tall body hard-truncates, over-wide line truncates with
  `…`, padRight/padCenter ANSI-width correctness (feed a styled + a wide-CJK string).
- **Commits:** candy-kit add (Frame + test + README + wiring) → candy-query adopt (BorderFrame
  delegates to Kit\Frame, Renderer unchanged at the call sites). Run candy-kit (43→) +
  candy-query (1092). **CHECK IN before AND after B1.**

### 3. C4 — thin `Renderer::getTerminalSize()`  (LOW PRIORITY; do last)

The FFI→env→stty→default ladder in `Renderer::getTerminalSize()` duplicates candy-core
`PosixBackend`. Keep `WindowSizeMsg` (via `Renderer::setSize`) as the single source of truth;
thin the fallback ladder or push it into candy-core `Tty`. (B1 already deletes the duplicate
ladder inside BorderFrame.) Mind the **C3 coupling** (DashboardPage reads `getTerminalSize()`);
optionally expose a clean admin content-width so DashboardPage stops duplicating the budget.

---

## How to work
- **One logical change per commit**, each with the touched lib's `vendor/bin/phpunit` green
  first. `php -l` touched files.
- Don't open PRs or push yet unless I ask — just commit to the branch so I can diff before/after.
- **Skip Caliber on this machine** — never run `caliber refresh`; if a hook stages
  Caliber-managed files, unstage them. This session bypassed the pre-commit hook cleanly with
  `git -c core.hooksPath=/dev/null commit …` while staging only the source files.
- **Path-repo gotcha:** new sugar-*/candy-* dep → add the `require` line AND the single
  `{type:path,url:"../<lib>"}` repo **by hand**; do NOT use `--fix --strict-closure`. Then
  `composer update sugarcraft/* --quiet` + `composer validate` (drop `--strict`).
- New `\x1b[…m` literals in code are the smell you're removing — prefer `Sprinkles\Style`/`Theme`
  and the Dash/Forms/Bits widgets.

## Commands cheat-sheet
```sh
git branch --show-current                      # expect ai/candy-query-upstream-extraction
git log --oneline master..HEAD                 # what's landed

# baselines (composer install once if a lib's vendor/ is missing):
(cd candy-query  && vendor/bin/phpunit)         # 1092 (1 skipped)
(cd sugar-charts && vendor/bin/phpunit)         # 369   ← B5
(cd candy-kit    && vendor/bin/phpunit)         # 43    ← B1
(cd candy-core   && vendor/bin/phpunit)         # 637
(cd sugar-table  && vendor/bin/phpunit)         # 169

# stale vendor → false red? update path-repo deps first:
(cd <lib> && composer update 'sugarcraft/*' --quiet && vendor/bin/phpunit)

php -l <file>                                   # lint touched files

# commit (bypass Caliber hook, stage only source):
git add <files>
git -c core.hooksPath=/dev/null commit --author="Joe Huss <detain@interserver.net>" -m "…
…
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

## Definition of done
3-pane browser + all 6 admin pages render via upstream widgets (**DONE** — 0 `\x1b` literals in
any page); chart autoscale lives in sugar-charts (B5); `BorderFrame` lives in candy-kit as
`Kit\Frame` (B1). `ResultTable` retained + wired (per decision), not retired; `ResultPager` may
still be adopted onto `Bits\Paginator` or left as-is. All suites green (modulo the 38 pre-existing
sugar-dash golden fails). `git diff master --stat` shows candy-query net-smaller.

**Begin:** read the plan files + recent commits, confirm the branch and the 1092 candy-query
baseline, then do **B5** and report back. **Check in before AND after B1** — it's the risky
frame extraction (new-lib wiring + frame-diff invariants).
