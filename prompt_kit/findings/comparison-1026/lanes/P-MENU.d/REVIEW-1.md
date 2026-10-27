# P-MENU.d — REVIEW-1

Commit 6c7cf7d1b on cc/P-MENU.d (base 86f43fae2), 20 files +1251/−493, tree clean.

VERDICT: FINDINGS — 1 MAJOR, 1 MINOR, 2 NIT. Everything else verified clean.

## Gates re-run by reviewer (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

| Gate | Builder claim | Reviewer run |
|---|---|---|
| --filter 'Menu' | 261T/13,911A | 261T/13,911A OK (2 house deprecations) ✓ exact |
| 'Renderer|KitchenSink' | 1197T/152,281A | 1197T/152,281A OK ✓ exact — goldens/contrast clean, allowed-red list N/A |
| 'KeyBinding' | 278T/3,332A | 278T/3,332A OK ✓ exact (combined 'KeyBinding|KeyHelp' = 342T green) |
| five-guard | staleness pair only | 133T: exactly ReadmeSuiteFigureDrift pair (25,537 pinned vs 26,124 live), rest green ✓ |
| check-one-type-per-file.php | — | tool invocation refused by reviewer shell map; grep-verified instead: every touched PHP file declares exactly one top-level type (MenuSelectedMsg is `final readonly class`) ✓ |

DUR rows: MenuReachabilityTest = 14T, MenuKeyboardOwnershipTest + MenuActionDispatchTest = 14T combined (5+9) — exact ✓. Touched legacy files net method-count zero except MenuBarTest 13→15 (+2, disclosed as restructured line 31 — honest). Staleness residual is base-era foreign drift; weld re-pin owns it.

## Author law / meta

%ae=detain (1), %ce=detain (1) — separate greps; zero Co-Authored; single commit; tree clean ✓.

## Axis findings

- **[MAJOR] False disclosure + brief DoD clause unpinned as written.** REPORT line 25 claims the 80-col media-form test goes THROUGH the FOLD with TWO Enters ("first Enter enters the submenu, second chooses cmd:generate-form"). The test on disk — `tests/Tui/MenuReachabilityTest.php::testTheMediaFormOpensThroughF10AtEightyColumns` (:223-252) — performs NO repaint, so no fold is composed (its own comment :228 admits "an update chain composes no fold, so the ring is the full five-menu tree"), and it walks F10→Right→Right→**single Enter** over the structural tree. The test name claims 80 cols; 80 cols is never painted. At a real 80-col terminal the painted bar is "Session Agent »" (per the REPORT's own fold table), and the advertised README keystroke hits the '»' submenu first — exactly the two-Enter walk the REPORT describes but no test executes. Fold-level reachability is pinned at the MenuBar kit level (key sweep + click-zone sweep run over the effective tree at every width incl. 80), so production behavior is very likely correct — but the brief's explicit DoD ("AT 80 COLS F10→→ENTER OPENS MEDIA FORM") is not pinned end-to-end, and the disclosure misrepresents the test.
  **Fix:** in the 80-col test, call `$this->repaint(80)` before the update chain, then drive F10 → Right → Right → Enter (enters the » submenu, chosen null) → Enter (chooses cmd:generate-form) with asserts at each hop; keep REPORT line 25, which then becomes true. (Or rename the test and correct REPORT — but the fold case is what the brief asked for.)
- **[MINOR, step-local] Fold budget non-monotonic:** REPORT's measured table shows 80 → "Session Agent »" but 120 → "Session »" (widening sheds more titles). Disclosed honestly ("Non-monotonic, live-paint only"); no correctness break (contiguity + reachability pinned at every width), but a user-visible oddity. Root likely in fold() budget inputs (band/tab-strip chrome widths at 120). Hand to P-MENU.e (which moves the tab strip and recomputes the budget) as an explicit follow-up line in its brief.
- **[NIT] `testTheMediaFormOpensThroughF10AtEightyColumns` name-vs-content mismatch** — cured by the MAJOR fix.
- **[NIT] REPORT line 21 "+~51T-class"** is a loose estimate where the exact lane contribution is enumerable (new methods 30 + provider expansion); welder will trust the green run anyway. No action.

## Axes verified clean

1. **Scope == brief:** exactly the 20 files, all in declared hubs ([lang][docs:README.md][docs:MEDIA.md][hot:App.php = src/App/App.php][hot:MenuBar.php][hot:KeyboardHandler.php][hot:KeyBindingRegistry.php]). `src/Tui/Renderer.php` UNTOUCHED (panels() got optional `?int $rows` — Renderer call sites at :527/:2343 already iterate multiple panels, verified). No dist/, no protocol JSON, no composer, no suite-figure.json/durations.tsv. MenuBar.php hunks are the component itself; App regions are dispatchMenuSelection + menuitem/panetab arms — as declared.
2. **Dispatch-by-name:** MenuActionDispatchTest pins cmd:sessions running '/sessions' despite a mismatched label (renamed-command survival at App level); kit ShellMenuTest placement↔registry both-way pin survives (kit untouched). pane:≡panetab: single door: reflection over dispatchChromeClick, dock/pane/status state IDENTICAL both toggles, both directions.
3. **Bug 4:** open-menu block moved above the dashboard arm and RETURNS when the cursor answers; declined keys fall through (ownership tests pin dashboard index untouched, skill-picker index untouched, 'c' still reaches CancelAgentCmd; red-at-base by code order — old dashboard arm first consumed ↑/↓; builder's swap-to-base proof consistent with the mechanism). Esc pops one level per press; closeMenu clears cursor AND fold (process-static law: every touched test resets via closeMenu in setUp/tearDown — spot-checked all 3 new files + KeyboardHandlerTest/AbandonedPalette).
4. **Fold/80-col sweep:** sweep iterates [30,40,60,80,120,200], repaints per leaf so tree()/pathOf()/openMenu() speak the width's effective shape; click zones scanned from REAL renderView passes; over-width law pinned post-strip (MenuOverlayFrameTest :96 Width::string <= cols across 5 frame paths; zone=contiguous-left-block law).
5. **Forced-by-swap disclosures truthful:** KeyboardHandlerTest (reflection→closeMenu; statics census renamed $cursor/$effective; 'z'-pin re-armed to Down with visible spend assert — old behavior's replacement, fall-through of declined keys, IS pinned in MenuKeyboardOwnershipTest); KeyHelpTest (counts 180→181/155→156/185→186 = menu.submenu row, minimal); MenuOverlayFrameTest (separator skip + 1-based zones + ShellMenu helpers — necessary); KeyBindingRegistryTest (152); AbandonedPalette (reset only). Green-UNCHANGED disclosures (MenuOpenTest/MenuDropdownTest/ShellContrastTest) match diffstat.
6. **DUR/§1.5:** 3 new files listed in REPORT with exact counts ✓ (verified above).
7. **§6.1:** R-MENU-1 tree intact (ShellMenu.php untouched → no re-shuffle); Generate-image entry is Tools row 0 and is the /generate door per README/MEDIA; R-MENU-2/3 belong to P-MENU.e — nothing landed here contradicts them.
8. **§1.9:** no glob-shaped or Refusal-shaped literals (GlobDialect green in five-guard; menu_no_command wording "No command matches menu item '{item}'.").

KeyBindingRegistry menu() keeps menu.switch, adds menu.submenu driven BOTH ways per the two-effect rule (KeyBindingDriftTest openMenuAt(4) depth 1→2 / 2→1). README mouse paragraph + MEDIA doors updated same commit.
