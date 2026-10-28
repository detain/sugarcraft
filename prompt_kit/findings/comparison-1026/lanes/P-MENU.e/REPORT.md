# P-MENU.e — LANE REPORT (fixer session, 2026-10-27)

## Verdict

REAL base-commit chain established: `8eab506d9` (weld: P-MENU.d) → `c905705e7`
(P-MENU.e: pane toggles move to View ▸ Panes; indicator door; hover while
open). ONE commit, tree `ae1f7c2a2`, author/committer both
Joe Huss <detain@interserver.net>, zero Co-Authored trailers, worktree clean,
NEVER pushed. This REPORT supersedes the prior phantom-pair record (: an
earlier 'r2 CLEAN' verdict reviewed commits that never existed): the
design-record's `60b8f997a` (build) + `621536881` (r2 fold-budget cure) are
PHANTOMS — see Forensics.

## Shape shipped (vs BRIEF.md — all landed)

- `compose()` pane-tab strip + three densities DELETED from `MenuBar.php`;
  right end keeps only the `Currently:` indicator: full ≥61, terse (bare pane
  label) 50–60, gone <50 (`INDICATOR_FULL_FROM`/`INDICATOR_TERSE_FROM`).
- Indicator is the click door: zone `MenuBar::PANES_DOOR_ZONE` (`panesmenu`),
  `dispatchChromeClick()` arm → `MenuBar::openPanesMenu()` (View ▸ Panes with
  the Panes row lit + submenu previewed; re-press re-affirms).
- One list: `PANE_TABS` const gone; the Panes rows derive from
  `Pane::tabCycle()`; `PaneReverseCycleTest` pin flipped to rows==tabCycle.
- `panetab:<pane>` zone MOVED from bar to rows: `ShellMenu::paneRows()` sets
  `zone: MenuBar::PANE_TAB_ZONE_PREFIX . $pane->value`; the existing click arm
  and `dragTargetPane()` press-drag carry work off the row UNCHANGED.
  R-MENU-3: toggle (key Enter or row click, `pane:` action) keeps the submenu
  open, check flips in place; commands still close.
- Hover: ONE transition check in `App::update()` compares
  `MenuBar::activeMenu() > 0` before/after every message → `enableMouseAllMotion()`
  on open, `enableMouseCellMotion()` on EVERY close path; skipped when
  `Chat::mouseMode() === Off`. `handleShellMouse()` consumes motion while a
  menu is open: over `menuitem:`/`panetab:` rows → `MenuBar::hover()`
  (highlight; submenu rows preview); anywhere else swallowed. Press/release
  fall through; a press outside chrome while open dismisses (click-away close
  path); `advancePaneDrag()` closes the menu exactly on the unarmed→armed
  transition (stationary press stays a toggle per R-MENU-3).
- Docs in-step: README L353-357/L372-386 rewritten (door, rows, terse/gone
  widths, drag-from-row); ARCHITECTURE.md zone map + bullets corrected (stale
  pane list fixed to the tab-cycle list). No generated roster moved;
  KeyBindingDrift untouched (278T green).

## Fold-budget decision + boundaries

The lost "r2 cure" boundaries (<59/<74/≥74/≥93 + `moreRows()`) belonged to the
OLD bar arithmetic where the tab strip competed with the fold. P-MENU.e makes
the contradiction MOOT by construction: the strip is gone, the indicator is
what folds away FIRST, and it only ever appears at widths where all five
titles already fit (five titles spend 40 cells; widest indicator costs 9
terse/20 full beside them ⇒ budgets 40/41 at the 50/61 boundaries). `fold()`
drops titles from the right as width shrinks — never the reverse — so widening
cannot take a title away. Pinned by
`MenuBarCompactStripTest::testWideningNeverTakesATitleAwayAndTheIndicatorNeverCostsOne`
(monotonic non-decreasing painted-title count across cols 1–200 + "indicator
appeared while a title missing" ⇒ 5 painted) and the marked-twin byte-parity
leg at 120/50/40 (`testTheMarkedTwinMatchesThePlainBarAndCarriesTheTitlesPlusTheDoor`
+ `testTheDoorIsHitWhereTheIndicatorIsPainted`). 80 vs 120: both paint all five
titles (no fold at either) — the era's `80→'Session Agent »' vs
120→'Session »'` shape is unreachable.

## Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'MenuHover|PanesSubmenu|MenuBar'` → 86T/1524A OK (re-run post-fix).
- `--filter 'Menu'` → 274T/13890A OK.
- `--filter 'Renderer|KitchenSink'` → 1197T/152284A OK — goldens CLEAN,
  no UPDATE_GOLDENS run needed.
- `--filter 'KeyBinding'` → 278T/3332A OK.
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`)
  → 133T, FAILURES = ONLY the staleness pair (README headline +
  suite-figure.json claim 25,537 vs live 26,283 — fleet-wide figure drift +
  this lane's +2 files; weld re-pin duty, NOT lane-domain).
- modified test classes: `--filter 'DockDragBytePath|ComponentTest|SettingsPaneTest|TodoPaneTest|PaneReverseCycle|MousePaneJump|MenuReachability'` → 110T/10149A OK.
- Pre-existing noise disclosed: every phpunit invocation in this tree AND in
  the untouched main tree reports "PHPUnit Deprecations: 2" (config-level, not
  lane-introduced).

## DUR rows owed (weld)

NEW test files in candy-code — durations.tsv rows + suite-figure re-pin:
- `candy-code/tests/Tui/MenuHoverTest.php` (8T)
- `candy-code/tests/App/PanesSubmenuToggleTest.php` (5T)
- `candy-code/tests/Support/CarriesStandardFrameTrait.php` is NOT a *Test.php
  file — no durations row (per SourceFileWalkTrait/RunsWallClockBoundedChildTrait precedent).

## Deviations

1. Dispatch described the dirty tree as 656+/280−; actual reconciled build is
   1422+/366− across 18 paths (dispatch figures stale).
2. Two modified test files beyond the BRIEF's 8-test list — `RendererTest.php`
   (its `testRenderOutputContainsMenuBarTabs` pinned the retired strip →
   reshaped to `testThePanesRowsPaintOnlyWhileTheMenuIsOpen`) and
   `MenuReachabilityTest.php` (its swept "empty-bar regime at narrow widths"
   was tab-strip arithmetic → re-derived for indicator-first fold). Both are
   direct casualties of the strip removal; minimal edits.
3. `DuplicatedTestHelperDrift` REDDENS on the new build (FRAME_COLS/FRAME_ROWS
   byte-identical triplet across MenuBarPaneTabClickTest + the two new files).
   Fixed at root per the guard's prescribed motion: consolidated into NEW
   `tests/Support/CarriesStandardFrameTrait.php` (lane-gf precedent), NOT
   rostered. The staleness pair alone remains.
4. The commit initially landed at 21:30 as `d794eade9` ("…View ▸ Panes owns the
   pane toggles") while this session was mid-gates (server-restart window; no
   foreign paths entered it). Amended to the dispatch-mandated subject as
   `c905705e7`; tree object byte-identical across the amend (`ae1f7c2a2`).

## Forensics (STEP 1)

- `git reflog show cc/P-MENU.e` (pre-fix): ONLY "branch: Created from master"
  at 8eab506d9 — no commit ever recorded on the branch.
- `git cat-file -t 60b8f997a` / `621536881`: BOTH "Not a valid object name" —
  the abbreviations match nothing in the object store (re-verified this
  session; prior era's probe result CONFIRMED, not stale).
- `git fsck --no-reflogs --dangling` full sweep + all 548
  `.git/lost-found/commit/*`: subjects grepped for `P-MENU.e` → ZERO hits.
  (P-MENU.a/b/c/d-era danglings exist — absorbed welds — no P-MENU.e object.)
- `git log --all | grep P-MENU`: welds up to `8eab506d9 weld: P-MENU.d` only.
- `stash@{0} "On master: local-wip-before-merge"`: foreign era-wide WIP
  (skills/docs/gifs/composer.lock across many libs); `stash show --stat |
  grep candy-code` → EMPTY. NOT the lost build. LEFT UNTOUCHED, not staged.
- Conclusion: the branch-reset corruption class here is a PURE PHANTOM — the
  claimed commits never existed on disk (or were fully pruned); the
  working-tree build was the lane's only reality. STEP 3 landed it honestly.
