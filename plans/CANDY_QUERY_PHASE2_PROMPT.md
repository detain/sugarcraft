# Startup prompt — paste into a fresh session (cwd: /home/sites/sugarcraft)

---

We're mid-way through making **candy-query stop hand-rolling TUI primitives and
adopt the SugarCraft foundation libs instead**, plus moving genuinely-reusable
bits up into those libs. Phase 1 (foundation + cleanups), the **Phase 2 browser
milestone**, and the **first two admin pages (A5 ServerStatus, A6 Variables)**
are already done and committed. You're continuing **Phase 2: the remaining admin
pages (A7–A11) and the tail items**.

**Before doing anything, read these three files in full — they are the spec:**
1. `plans/CANDY_QUERY_UPSTREAM_PHASE2.md` ← the detailed execution plan + a
   **Progress log** at the top recording exactly what's done (start here).
2. `plans/CANDY_QUERY_UPSTREAM.md` ← the original analysis (the "why").
3. Skim the recent commits: `git log --oneline master..HEAD`.

**Working context:**
- Branch is already `ai/candy-query-upstream-extraction` (off `master`). Stay on it.
  `master` is the "before" for `git diff master`. Confirm with `git branch --show-current`.
- **Baselines (still green after A5/A6):** candy-core 637, candy-query **1092**,
  sugar-table 169, sugar-dash Badge/DefinitionList suites green.
  sugar-dash has 38 PRE-EXISTING `GoldenSnapshotTest` failures on master — ignore them,
  don't "fix" unrelated goldens.
- candy-query tests are mostly **substring** assertions (not byte-exact goldens), so
  widget adoption is low-risk — but run the full lib suite after every step.
- **Test gotcha:** `tests/Admin/Variables/VariableEditorTest` fails (~7) when that
  *subdir* is run alone — a FakeDatabase test-ordering artifact, GREEN in the full
  suite and on master. Trust the **full `vendor/bin/phpunit`** run, not a subdir run.

**What's already DONE (do NOT redo — see the plan's Progress log for detail):**
- STEP 0 (candy-forms dep), B2 (`Dash\Card\DefinitionList`), B6 (`Badge::bool/tristate`).
- A1 (query editor → `Forms\TextArea`), A2 (tables list → `Forms\ItemList`),
  A4 (pane titles → `Border::withTitle()`).
- sugar-table gained `Table::withSelectedIndex()`.
- A3 (rows pane → `sugar-table`). **Key decision: `ResultTable` is KEPT and wired up**
  for executed-query results (App `resultTable` state, `h/l`+`←/→` scroll), NOT deleted.
  Binary/ANSI safety is unified in the new `candy-query/src/CellValue.php` (use it).
- **A5 (ServerStatusPage)** — `ServerInfoCard` + the 6 panels → `Card`+`DefinitionList`;
  `tristate()` delegates to `Badge::bool()`. No test edits needed (substring assertions
  on Yes/No/Unknown still hold). Zero ANSI literals left in the two files.
- **A6 (VariablesPage)** — tab bar → `Bits\Tabs` (labels keep `[Status]`/`[System]`
  bracket form; passed a wide track to dodge the widget's ANSI-length truncation guard),
  search box → `Forms\TextInput` (`[search]` placeholder), category tree → `Forms\ItemList`,
  side-by-side → `Layout::joinHorizontal`, grid stays sugar-table via
  `Table::withSelectedIndex()`. **sugar-bits is now a candy-query dep** (require +
  `../sugar-bits` path-repo, added BY HAND — see gotcha below).

**START HERE — remaining work, in order (one logical change per commit):**
1. **A7–A11 — the remaining 4 admin pages, one page per commit, simple→complex:**
   Reports → PerfSchema → Dashboard → Connections.
   Replace per-page hand-rolled chrome with: `Bits\Tabs`, `Forms\ItemList`/`Bits\Tree`,
   `Dash\Card\Header`/`Footer`/`Divider`/`Badge`/`Kbd`/`EmptyState`/`LoadingText` +
   `DefinitionList`, `Sprinkles\Layout::joinHorizontal` (NOT `str_pad`), `Forms\Spinner`.
   Fold in **C3** (DashboardPage: feed real WindowSizeMsg dims, not `width=80,height=24`).
   - **A7 ReportsPage next:** `renderCategoryTree` (master-detail) → `Bits\Tree`,
     `renderSideBySide` → `Layout::joinHorizontal`, loading → `Spinner`/`LoadingText`,
     grid stays sugar-table. Watch the known `withExport` no-op stub and the prior
     sugar-table API drift flagged in memory `project_candy_query_admin_async`.
   - **Established A5/A6 patterns to reuse:** each panel = `Card::titled($defList, 'Title')`
     (titles ride in the border, satisfy substring tests); booleans → `Badge::bool()`;
     `Forms\ItemList` built on the fly mirrors the A2 Renderer block
     (`withCursorPrefix('')`, status bar/help/filter off, `select($idx)`); footers/headers
     → `Sprinkles\Style`. `Bits\Tabs::view()` does NOT pad to width and truncates on
     *ANSI-inclusive* length — pass a wide track (e.g. 200) so styled labels aren't clipped.
2. **B5** — move `TimeSeriesCell::niceCeiling()` into sugar-charts as axis auto-scale; adopt it.
3. **B1** — extract `Terminal/BorderFrame.php` → new `Kit\Frame` (candy-kit). DO LAST of the
   visible work. **MUST preserve the frame-diff invariants** (see below); port BorderFrame's
   tests with it. candy-kit isn't in candy-query's path-repos yet — wire it (`check-path-repos`).
4. **C4** — thin `Renderer::getTerminalSize()` (WindowSizeMsg stays the source of truth). Low priority.

**How to work:**
- **One logical change per commit**, each with the touched lib's `vendor/bin/phpunit`
  green first. `vendor/` is gitignored and goes stale — run `composer update sugarcraft/*`
  in the lib before trusting a local failure. `php -l` touched files.
- Don't open PRs or push yet unless I ask — just commit to the branch so I can diff
  before/after. Author commits as `Joe Huss <detain@interserver.net>` and end messages with:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **Skip Caliber on this machine** — never run `caliber refresh`; if a hook stages
  Caliber-managed files, unstage them before committing. (Commits in A5/A6 used
  `git -c core.hooksPath=/dev/null commit …` to bypass the Caliber pre-commit hook
  cleanly while staging only the source files.)
- **Path-repo gotcha (learned in A6):** when a page needs a NEW sugar-* / candy-* lib
  (e.g. `Bits\Tree` from sugar-bits — already wired), add the `require` line AND the
  single `{type:path,url:"../<lib>"}` repo to `candy-query/composer.json` **by hand**.
  Do NOT run `php tools/check-path-repos.php --fix --strict-closure` — it rewrites the
  path-repos of ALL ~56 libs monorepo-wide (blast radius far beyond candy-query). Then
  `cd candy-query && composer update sugarcraft/* --quiet` and `composer validate`.
- **Frame-diff invariants** for any full-screen output (esp. B1): constant total line
  count per frame, no line wider than the terminal, no mid-frame `\x1b[2J`. The strict
  guards live in `candy-query/tests/RendererTest.php`
  (`testFrameFillsTerminalExactly`, `testNoRenderedLineExceedsTerminalWidth`,
  `testRenderSanitizesControlBytesFromBlobData`) — keep them green.
- New `\x1b[…m` literals in page code are the smell you're removing — prefer
  `Sprinkles\Style`/`Theme` and the Dash/Forms widgets.

**Definition of done** (updated): the 3-pane browser (done) + all 6 admin pages render via
upstream widgets, no hand-rolled tabBar/list/scroll/card/badge/separator/side-by-side, no raw
`\x1b[…m` literals in page code, `BorderFrame`→`Kit\Frame`. **`ResultTable` is retained and
wired (per decision), not retired**; `ResultPager` may still be adopted onto `Bits\Paginator`
or left as-is. All suites green (modulo the 38 pre-existing sugar-dash golden fails).

Start by reading the plan files + recent commits, confirm the branch and the 1092 candy-query
baseline, then do **A7 (ReportsPage)** and report back before continuing.
