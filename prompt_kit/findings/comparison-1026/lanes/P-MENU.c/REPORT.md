# P-MENU.c — menu overlay seam: every frame paints, clamps and scans the open dropdown

**Verdict: COMPLETE.** Commit `2616bb560` on `cc/P-MENU.c` (base `75ba82ddb`, worktree `/home/sites/cc-cmp-P-MENU.c`, never pushed). Author/committer Joe Huss <detain@interserver.net> (%ae/%ce separate greps 1/1, zero Co-Authored).

## What landed
- `MenuBar::panels()/panelsMarked()` (new) → `MenuPanel` geometry for the open dropdown: anchor resolved PAINT-AWARE (compose() sheds menu titles when `cols-Width(tabs)-1` runs out; `activeMenuColumn()` counted the shed names anyway — the over-wide anchor), `col = max(1, min(activeMenuColumn()+1, cols-width+1))`; byte-identical to the old splice while the title is on screen. `nodes` stays `[]` — click-routing fills them later (today rows resolve via `menuitem:<row>` zones); disclosed seam for P-MENU.d.
- `Renderer::overlayDropdown()` → `overlayMenu(App, frame, cols, top)`: splices every panel from `panels()`, each spliced row `Width::truncateAnsi(…, $cols)` (clamp backstop). `dropdownOverflowLine` unchanged.
- `scanChrome()`'s `bool $withDropdown` → `?int $panelTop`; panel pass over `panelsMarked()` mirroring the overlay's bottom clip EXACTLY (budget−1 rows + the `…` row marks nothing): paint ⇔ mark row-for-row.
- All five frame paths run both seams: renderView, renderAgentDashboard (F10/title-click opened a menu that did not exist on screen — now splices), renderMediaForm, renderSettingsEditor, renderVideoPlayer.
- `renderDropdown()`/`renderDropdownMarked()` stay thin forwarders (pinned by test).

## Deviation (reported, §6 law followed)
The video path's GRAPHICS leg (Sixel/Kitty/Iterm2 — `clipsLines()===false`, protocol bytes the splice would corrupt, per the screen's own doc) refuses BOTH the splice and the `panelTop` (null) — one refusal, so zones never precede paint. The menu over a graphics picture stays keyboard-only until the App-side refusal lands (seam for the orchestrator, not fabricated). Text-mode video (incl. the preparing placeholder) splices and marks normally.

## RED-first evidence (base 75ba82ddb, pre-impl)
`Tests: 28, Assertions: 1175, Errors: 2, Failures: 4` — all three regressions behaviorally red: agents dashboard + video table cases "item New session must be painted"; MenuBarMouseTest dashboard "frame does not contain New session…"; width test "menu 8 open must not widen … 86 is equal to 80 or less". 2 errors = `MenuBar::panels()` not yet defined (contract tests). RED run also pinned the honest clip semantics: the dashboard band is 4 lines, so only the border+first label rows are paintable — the suite pins paint ⇔ mark, not "all items always visible".

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'MenuOverlayFrameTest|MenuDropdownTest|MenuBarMouseTest'`: 28T/1087A green.
- `--filter 'Menu'`: 217T green.
- `--filter 'Renderer|Frame|Overlay|MediaFormPopupSize'`: 1610T/158138A green — P-IMG.b's "menu bar stays clickable" (MediaFormPopupSizeTest:205) KEPT GREEN.
- ShellContrast: 1F PROVEN BASE-RED (`git stash` + rerun at pristine 75ba82ddb: same 1F; offenders `AgentColor.php:93`/`WindowStrip.php:362` — files this lane never touched; foreign residue on the base).
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`): ONLY the staleness pair red (2F, artifact 25537 vs live 25950 — carry-over drift from prior welds, this step's live delta +8T: 7 new MenuOverlayFrameTest methods + 1 forwarder method).
- "PHPUnit Deprecations: 2" in every run: pre-existing `AccessorDeprecationTest` non-public providers, present at base.

## DURATIONS duty (gate/closeout)
NEW test file needing a `scripts/parallel-tests-durations.tsv` row: `candy-code/tests/Tui/MenuOverlayFrameTest.php`. Modified files need no row. Live-enum +8T → README/suite-figure re-pin at next closeout.

## Files
- candy-code/src/Tui/Renderer.php (overlayMenu, scanChrome panelTop, five tails, docblocks truth-flipped incl. the video-path 'no dropdown rides' lie)
- candy-code/src/Tui/Components/MenuBar.php (panels/panelsMarked/panelsFrom + activeMenuColumn docblock note)
- candy-code/tests/Tui/MenuOverlayFrameTest.php (NEW)
- candy-code/tests/Tui/MenuDropdownTest.php (forwarder pin)
- candy-code/tests/Tui/MenuBarMouseTest.php (dashboard leg records the painted row zone)

## Cure (post-weld, 2026-10-27)

REVIEW-1.md MINOR (step-local) fixed on branch cc/cure-pmenuc (worktree
/home/sites/cc-cmp-cure-pmenuc, cut from master 9d890b49c). Tests-only:
candy-code/tests/Tui/MenuOverlayFrameTest.php gains
testTheGraphicsLegRefusesToPaintAndMarkTogether + graphicsScreens() helper.

Shape deviation (disclosed): the literal "sixth framePaths provider case"
would invert the shared paint⇔mark assertion (the graphics leg must NOT
splice), and the probe showed a single Kitty case cannot pin both guards —
the READY blob frame is 2 physical lines, so the splice budget buries every
marked row and the panelTop ternary is only observable at a taller non-clipping
frame. The leg instead pins the three live graphics states (preparing
placeholder / ready kitty blob via the scripted child-free decoder, mirroring
VideoPlayerScreenTest / failed card): paint guard observable on all three
(`┌` on preparing/failed, `│ …` overflow on ready), mark guard observable on
the four-row failed card. Plus a text-mode control (`┌` splices) so the
refusals name the mode, not a dead harness.

Mutation proofs (Renderer.php cp-backed, md5 aadf5280fa3597cead8df2606edfe679
restored, verified): M1 paint guard only → new test 1F; M2 BOTH guards (the
review's scenario) → new test 1F; M3 ternary only → new test 1F; all other
7 cases green under every mutation (discrimination is the new leg alone).

Gates (worktree, plain pipe, -u LINES -u COLUMNS): --filter MenuOverlayFrame
8T/1012A OK (baseline 7T; the brief's "13T-class" expectation did not match
disk — the file at 9d890b49c carries 7 cases); --filter Menu 230T/4126A OK.
No candy-code src touched, no suite-figure/durations change. SEAM RECORDED
(outside this cure, pre-existing at 9d890b49c): scripts/parallel-tests-durations.tsv
carries NO row for MenuOverlayFrameTest.php at all — the file runs in zero
shards until a closeout regenerates it (REVIEW-1.md already owed this to the
weld; still open).
