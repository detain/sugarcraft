# weld: C-TOOL-3.d (W14 — `/shells` list/output/stop)

- Master tip at weld base: 9fbb73173 (W13 wave-loop guard cures).
- Pick: 1de8e9266c09c7ed0c8ed98d9b138594d5f7f200 -> 734f97dda76b02e912933a996f62368877349cdc (`git cherry-pick`, author law: %ae/%ce separate greps 1/1 detain, zero Co-Authored/Co-developed trailers).
- File-set: lane 12 files +576/-4 -> pick 11 files +571/-4. The 5-line DocFigureProseDriftTest 'thirty-seven' word-map hunk is content-identical to what master already carries from cure commit 9fbb73173 (provenance of that word-map lag re-attributed to C-AGT-5.a 5cd68af7e). KEEP-ONCE: the auto-merge dropped the duplicate hunk; `git show 734f97dda -- candy-code/tests/Config/DocFigureProseDriftTest.php` is empty and master's file holds the line exactly once (grep 'thirty-seven' count unchanged at 3 = master's own pre-pick occurrences).
- Review state: r1 1 MAJOR (registry() helper tripping DuplicatedTestHelperDrift) cured via shellsRegistry() rename (live in pick: ShellsCommandTest.php:91/:103) + thirty-seven provenance re-attribution (DocFigureProseDriftTest.php:5425). dup-r1's ReadOnly-window pin MINOR was NOT adopted (r2 CLEAN governs — lanes/C-TOOL-3.d/REVIEW-2.md 0C/0M).
- Durations: 1 row scripts/parallel-tests-durations.tsv (candy-code/tests/Host/Commands/ShellsCommandTest.php 0.943 9; junit per-file sum at merged tip — the 10th testcase in the --filter run is a derived BinCandyCodeWiringTest dataset row, correctly excluded). tsv 1511 -> 1512, set-equality +1, descending order held (0.960 > 0.943 > 0.940). Scripts commit: 54167db3e.
- Gates (plain pipe, cwd candy-code, env -u LINES -u COLUMNS):
  - --filter ShellsCommand: Tests 10, Assertions 51 — EXACT vs brief (2 ambient deprecations = standing AccessorDeprecationTest pair).
  - guards 'DocFigureProseDrift|GlobDialectDifferential|DuplicatedTestHelperDrift|ChildWallClockBudget': 129T/16205A 0F (brief predicted 130T/16141A; -1T/+64A re-derivation wobble, zero failures — report-only, not attributed).
  - 'MenuBarLabel|PaletteSuperset': 17T/368A EXACT.
  - php tools/gen-command-docs.php --check: "command docs: up to date" rc0 (COMMANDS.md regen rode the lane commit).
- Regen: none beyond the --check above (no src/Protocol/**, no candy-code-web paths in the pick).
- suite-figure.json: untouched (standing ReadmeSuiteFigureDrift staleness pair carries; +10T-era ride is closeout re-pin duty).
- Absorption proof: `git cherry master cc/C-TOOL-3.d` after weld commit (see orchestrator report); worktree /home/sites/cc-cmp-C-TOOL-3.d removed, branch cc/C-TOOL-3.d deleted.
- run-state: inflight minus C-TOOL-3.d; welded_count 139 -> 140.

## cure note: ShellMenuTest x2 standing red (2026-10-28, W14 follow-up)

- The P-TABS.f weld 6638d6415 recorded this gate owed to C-TOOL-3.d: the `shells` row (pick 734f97dda) joined the registry but `ShellMenu::PLACEMENT` never named it, reddening `ShellMenuTest::testEveryRegistryRowIsPlacedExactlyOnceAndNoPlacementIsABluff` and `testTheShippedTreeHasNoMoreSubmenuBecauseNothingIsUnplaced` (deterministic, reproduced at base cbe74fb5a and at tip cc341ce87).
- Cured: `'shells'` placed in Agent ▸ Automate ▸ between `bg` and `schedules` — mirrors the registry file numbering (2900-bg / 2905-shells / 2910-schedules) and the `/schedules`-sibling note in design-tool.md §C-TOOL-3.d; no new submenu invented (design-menu.md never names `shells`; R-MENU-1 Automate is the bg/schedules/workflow home).
- Gates after fix (plain pipe, cwd candy-code, env -u LINES -u COLUMNS): ShellMenuTest 11T/374A 0F; ShellMenu|ShellsCommand|MenuBar|MenuReachability|PanesSubmenu|MenuOverlayFrame 122T/12565A 1F — the one red is `MenuBarTest::testGetMenuItemsReturnsThePlacementRowsForAValidMenu`, a SEPARATE foreign standing red (its hand-copied Session-menu expected list lacks the P-TABS.f 'Close tab' row; proven identical at cc341ce87 with ShellMenu.php reverted, and unrelated to the shells row — Gate miss recorded for P-TABS.f, palette 'Close tab' mid-turn refusal `PaletteClickTest` dataset "mid-turn" likewise red at base).
- No docs touched; ReadmeRosterDrift/DocFigureProseDrift not re-armed (src-only hunk). suite-figure/durations untouched (no new test file, no test count change).
