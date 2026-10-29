# W21 — wave loop review 2 (2026-10-29, reviewer; tips reviewed: d63163d87 fixes, 7342d0e95 re-verify)

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR — r1 cures confirmed, wave close-ready)

## r1 enumerated reds — all green at tip
Re-ran myself, cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe:
`--filter 'SettingsWriterCensus|PaletteSupersetDispatch|ShellMenu|MenuBarLabelDerivation'`
→ **35 tests / 5824 assertions OK** (exit 0; the 2 "PHPUnit Deprecations" are config-level
baseline — constant across every run shape incl. single-class, not suite content).
Re-verified at 7342d0e95 (weld-18o C-AGT-4.d landed mid-run, touches none of the cure files)
— identical 35/5824 OK. Also broadened: `--filter 'Menu'` 275T OK, `--filter 'KeyBinding'` 301T OK.
1. SettingsWriterCensusTest:145 — 77072a840 landed both roster rows with per-row verdict
   comments (persistFast=1 /effort-sibling one-key; stampBundleRecommendations=1 AtomicJsonFile
   0600 notice-stamp, not a settings key). Green.
2. PaletteSupersetDispatchTest:200 — f8f9e6a18 carried the 'fast' survivor row; the roster is
   derived-vs-judged, test live-derives the survivor set. Green.
3. ShellMenuTest placement (both arms) + 4. MenuBarLabelDerivationTest:92 — f8f9e6a18. Green.

## Deviations adjudicated — both ACCEPTED
A. "fast label row already landed via 0da9b5071": verified — that commit (C-PRV-3.b lane itself)
   already carries MenuBarLabelDerivationTest `'fast' => 'Read or set the fast'` at :33; the tip-red was the
   instructions+commit rows and the fixer carried exactly those, no duplicate row added. Correct.
B. 3 ShellMenu placements (fast, commit, instructions) instead of just fast: MenuBarLabelDerivation
   is assertSame against CommandRegistry::all() live derivation (:92-102), so the instructions/commit
   rows ARE re-derived, not hand-authored; ShellMenu EveryRegistryRowIsPlacedExactlyOnce +
   NoMoreSubmenu + label-collision arms green ⇒ placements complete, each command once, adjacencies
   per registry positions (fast beside effort; commit before pr; instructions between memory/skills).
   Carrying W20/other-family rows in the W21 loop matches the r1-stated attribution precedent
   (reds rode the shared batch-18 welds; W15 /pr cure 8dab423f0 family).

## Whole-wave cross-check (10 steps, 18 merged commits)
- All 18 step commits (d54bd76e0…d159b2cfd per §4 W21) + 3 cure commits: ancestors of master,
  %ae=%ce=detain@interserver.net 21/21, zero Co-Authored trailers.
- Generated surfaces at tip: gen-protocol-schema, gen-command-docs, gen-settings-doc,
  gen-tool-docs — ALL "up to date", rc0.
- durations.tsv: exactly the 14 new W21 *Test.php files, all 14 rows present (lines 64–946),
  figures match the §4 per-lane DUR claims (e.g. ContextInstructionWriteTest 11T/1.536,
  CommandKeybindingDispatchTest 7T/0.660, AppendSystemPromptTest 18T/0.52).
- §6 rulings honored: R-EXT-2 announce-and-undo landed (SKILLS.md 6 mentions, SkillsHistoryUndo
  suite); `-p --append-system-prompt` fenced (PROMPT_ENGINEERING.md:530).
- Cross-lane: Chat.php skillInvocationDraft (C-WEB-2.b) and palette regions (C-EXT-5.b) coexist;
  Bootstrap.php launch-drain rows (C-EXT-4.d) census-classified; no new proc_open/fork/timeout
  lines in any cure diff (E646/child-lifetimes uncharged).
- Hygiene: no cc-cmp worktree or cc/* branch left for any W21 lane (live cc/* = other in-flight
  lanes + weld-18p program-side). No weld-only file committed by a lane; cures touch only the
  four enumerated test/src-menu files + FIX-MASTER-1.md map.
- Standing reds NOT charged (r1 roster unchanged by the cures): ReadmeSuiteFigureDrift staleness
  pair (§7 duty; 18o batch log itself notes 25537-vs-28379), ContainedPathInventory x2,
  ReadPathCensus RemoteSessionPush (W25), ForkedChildReaper x1, MediaSettings x1,
  BootstrapLaunchFormatConstants doc-roster arm x1.

W21 is close-ready: §3 row flip + push are the orchestrator's wave-close duties.
