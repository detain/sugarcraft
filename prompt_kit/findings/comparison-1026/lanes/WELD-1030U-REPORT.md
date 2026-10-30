# WELD-1030U — C-TUI-5.c (settings file-watch + /settings reload)
Master at dispatch: 3568d3397 (origin in sync). NO PUSH.

## Step 0 (re-verify) — DONE
- §4 mark: grep '^#### .*C-TUI-5.c' = 1 hit = the bare heading (line 3547); '^#### ✅ .*C-TUI-5.c' = 0 → NOT welded. Brief's expect-0 was the ✅-form.
- cc/C-TUI-5.c exists; worktree /home/sites/cc-cmp-C-TUI-5.c porcelain clean (only untracked lanes/C-TUI-5.c/ + plan_comparison.md seed).
- b77a18838..cc/C-TUI-5.c = EXACTLY 3: 0e8c488cc, 49072ea07, 6b8fee691 (rev-list=3, order verified).
- REVIEW-3.md 9666B on disk at main-tree lanes/C-TUI-5.c/ (file exists, r3 claim stat-proven).
- run-state live read: welded_count=276, len(welded)=276, C-TUI-5.c NOT in welded, inflight has C-TUI-5.c + stale C-CTX-6.e + C-CTX-5.d.
- 14:37:35 STEP 1 cherry-pick 0e8c488cc (watch at turn boundary):
- 14:37:38   -> CLEAN cb1950382 (auto-merge COMMANDS.md/en.php/Chat.php/HTC).
- 14:37:38 STEP 2 cherry-pick 49072ea07 (/settings reload + hot-reload):
- 14:37:41   -> CLEAN c9aed0031 (README/SETTINGS.md auto-merge held).
- 14:37:41 STEP 3 cherry-pick 6b8fee691 (review fixes round 1):
- 14:37:49   -> CLEAN 4f3e6a977. ALL THREE PICKS ZERO CONFLICTS.
- 14:38:00 STEP gates (cwd=candy-code, plain pipe, -u LINES -u COLUMNS):
- 14:42:46   SettingsFileWatchTest: 12T/21A OK (=r3 TRUE count, NOT fixer 11/17). SettingsReloadTest: 8T/50A OK. 'PHPUnit Deprecations: 2' proven tree-wide standing (unrelated KeyBindingDriftTest filter shows same 2).
- 14:52:38   Guard-family: reviewer's '67T/4347A' roster NOT reproducible on merged tree (per-exact-class lesson 1030S). All constituent guards GREEN per-class AND in union 119T/41515A (lane family + watch/reload). DocFigure 92T/4176A (brief 4165A: +11A merged-tree drift, all green — disclosed).
- 14:54:04   gen-settings-doc/gen-command-docs/gen-tool-docs --check ALL rc0 'up to date' — zero regen needed, generated regions byte-exact on merged tree.
- 15:13:55   DUR rows minted (junit-testcase-sum, median-of-3): SettingsFileWatchTest 0.059/12 (row 872), SettingsReloadTest 0.130/8 (row 701), descending-duration order proven.
- 15:13:59 STEP §4 mark inserted (✅ 4f3e6a977, disclosures inline: SETTINGS_DOOR_ONLY restart-routing / boot-seed onto Bootstrap.php:1287 (r3 minimum fix) / W25-m1 fix_queue satisfied via ReadPathCensus cures).
- 15:14:03 STEP commit: author law split-var, staged 4 lane reviews + weld report; -o carries plan_comparison.md + durations rows.
