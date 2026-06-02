# Startup prompt — paste into a fresh session (cwd: /home/sites/sugarcraft)

---

We're mid-way through making **candy-query stop hand-rolling TUI primitives and
adopt the SugarCraft foundation libs instead**, plus moving genuinely-reusable
bits up into those libs. Phase 1 (foundation + cleanups) and the **Phase 2
browser milestone** are already done and committed. You're continuing **Phase 2:
the candy-forms UI adoption — now the admin pages (A5–A11) and the tail items**.

**Before doing anything, read these three files in full — they are the spec:**
1. `plans/CANDY_QUERY_UPSTREAM_PHASE2.md` ← the detailed execution plan + a
   **Progress log** at the top recording exactly what's done (start here).
2. `plans/CANDY_QUERY_UPSTREAM.md` ← the original analysis (the "why").
3. Skim the recent commits: `git log --oneline master..HEAD`.

**Working context:**
- Branch is already `ai/candy-query-upstream-extraction` (off `master`). Stay on it.
  `master` is the "before" for `git diff master`. Confirm with `git branch --show-current`.
- **Baselines (already green after the browser milestone):** candy-core 637,
  candy-query **1092**, sugar-table 169, sugar-dash Badge/DefinitionList suites green.
  sugar-dash has 38 PRE-EXISTING `GoldenSnapshotTest` failures on master — ignore them,
  don't "fix" unrelated goldens.
- candy-query tests are mostly **substring** assertions (not byte-exact goldens), so
  widget adoption is low-risk — but run the full lib suite after every step.

**What's already DONE (do NOT redo — see the plan's Progress log for detail):**
- STEP 0 (candy-forms dep), B2 (`Dash\Card\DefinitionList`), B6 (`Badge::bool/tristate`).
- A1 (query editor → `Forms\TextArea`), A2 (tables list → `Forms\ItemList`),
  A4 (pane titles → `Border::withTitle()`).
- sugar-table gained `Table::withSelectedIndex()`.
- A3 (rows pane → `sugar-table`). **Key decision: `ResultTable` is KEPT and wired up**
  for executed-query results (App `resultTable` state, `h/l`+`←/→` scroll), NOT deleted.
  Binary/ANSI safety is unified in the new `candy-query/src/CellValue.php` (use it).

**START HERE — remaining work, in order (one logical change per commit):**
1. **A5–A11 — the 6 admin pages, one page per commit, simple→complex:**
   ServerStatus → Variables → Reports → PerfSchema → Dashboard → Connections.
   Replace per-page hand-rolled chrome with: `Bits\Tabs`, `Forms\ItemList`/`Bits\Tree`,
   `Dash\Card\Header`/`Footer`/`Divider`/`Badge`/`Kbd`/`EmptyState`/`LoadingText` +
   `DefinitionList`, `Sprinkles\Layout::joinHorizontal` (NOT `str_pad`), `Forms\Spinner`.
   Fold in **C3** (DashboardPage: feed real WindowSizeMsg dims, not `width=80,height=24`).
   - **ServerStatus first** (best first win): `ServerInfoCard` + the 6 panels → `Card` +
     `DefinitionList` (`->withPlaceholder('Unknown')`); the `tristate`/Yes/No → `Badge::bool`.
     ⚠️ `ServerStatusPage::tristate(bool|string|null): string` is a **public, directly-tested**
     method — adopting `Badge::bool()` changes its byte output, so **update
     `ServerStatusPageTest`** to match (it's substring-based; keep Yes/No/Unknown present).
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
  Caliber-managed files, unstage them before committing.
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
baseline, then do **A5 (ServerStatusPage)** and report back before continuing.
