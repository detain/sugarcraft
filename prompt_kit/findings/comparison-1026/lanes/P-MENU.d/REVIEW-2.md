# P-MENU.d — REVIEW-2 (fix commit 5c46ca73e atop 6c7cf7d1b)

VERDICT: CLEAN

## Fix-scope verification

1. **Scope:** `git show --numstat 5c46ca73e` = `candy-code/tests/Tui/MenuReachabilityTest.php` only (+35/−9). ZERO production src rides the fix; no suite-figure.json, no durations.tsv, no dist. Old method name grep-verified dead tree-wide (rename leg lived entirely inside this file; MenuKeyboardOwnershipTest correctly untouched).
2. **MAJOR cured as prescribed:** `testTheMediaFormIsReachableThroughTheFold` calls `repaint(80)` FIRST, then pins the painted fold observables (title zones exactly menu:1..3; `tree()->menus` count 3; root 3 = `MORE_MENU_ID`), then F10→Right→Right (activeMenu 1→2→3 asserted per hop; node under `cursor()->absolutePath()` id `menu:tools` — the shed submenu, not Tools directly), Enter #1 (cmd NULL = enters, not chooses; menu stays 3; `cursor()->rows` depth 2; node under cursor `cmd:generate-form`), Enter #2 (menu closes 0; `Closure` cmd), `update($cmd())` → mediaForm non-null. Every asserted state is a distinct production observable.
3. **Mutation claims consistent by reasoning:** M1 (delete repaint) → scanner is cleared in setUp, so the titles assertSame fails with `[]` — red at the first fold observable, exactly as REPORT states. M2 (delete the entry-Enter block) → the surviving lone Enter lands on the submenu row and ENTERS, so `assertSame(0, getActiveMenu(), 'choosing closes the menu')` reddens at 3≠0 — as stated; the depth pin `assertCount(2, rows)` independently catches enter-vs-choose conflation in the retain-asserts deletion form. Non-vacuous both directions.
4. **NITs:** width-free rename shipped; REPORT Round-2 section truthful (all claimed figures reproduced below); "+~51T-class" looseness replaced with enumerable counts. MINOR fold-budget non-monotonicity correctly NOT touched — documented P-MENU.e handoff carry per r1 ruling.

## Gates re-run by reviewer (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

| Gate | Builder claim | Reviewer run |
|---|---|---|
| --filter 'Menu' | 261T/13,920A | 261T/13,920A OK (+9A vs base, +0T) ✓ exact |
| 'MenuReachability\|MenuKeyboardOwnership' | — | 19T/10,197A OK ✓ |
| 'Renderer\|KitchenSink' | 1197T/152,281A | 1197T/152,281A OK ✓ goldens UNMOVED |
| 'KeyBinding' | 278T/3,332A | 278T/3,332A OK ✓ exact |
| five-guard | 133T, staleness pair only | 133T, exactly ReadmeSuiteFigureDrift pair (25,537 pinned vs 26,124 live — unchanged by this round) ✓ |

## Axes re-checked at new tip

- Range master...HEAD = exactly 20 files, tree clean; fix touches nothing outside the r1-approved file set. Bug-4 red-at-base property unaffected (fix touches neither src nor the ownership test).
- Author law BOTH commits: %ae and %ce read `detain@interserver.net` directly; `git log --format='%B' master..HEAD | grep -ci co-authored` = 0.
- README generation-form door wording (line 328) untouched by the fix and still true at wide widths; the 80-col two-Enter reality is what the test now pins — no doc/code divergence introduced.

Weld note (report-only): staleness pair is base-era drift; closeout re-pin owns it. Fold-budget non-monotonicity MUST land in P-MENU.e's brief as an explicit follow-up line (r1 MINOR carry, already recorded in REPORT Round-2).
