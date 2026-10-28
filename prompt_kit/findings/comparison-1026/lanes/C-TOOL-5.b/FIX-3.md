# FIX-3 — C-TOOL-5.b round-3 grounding (2026-10-28): the 5 mandated cures are phantom artifacts — dispositions, zero code change, tip held at 2fecd5f26

Disk grounding performed INDEPENDENTLY of both the fixer brief's characterization and FIX-2's prose.
Verdict: all five "step-local cures" name artifacts that do not exist in any ref, or whose real-file
substance is already true/pinned on disk. The real REVIEW-2.md (this dir, 03:11) reads
"VERDICT: CLEAN (0C/0M/0 step-local minors)" — it does NOT carry the brief's claimed
"0C/1MAJOR/4MINOR/4NIT" list; no file under prompt_kit/findings/comparison-1026/ or /tmp/opencode/
contains that list (repo-wide greps below). The lane tip 2fecd5f26 (tree byte-identical to
fb441bbf7) already embeds the round-2 disposition in its message and was ratified CLEAN by r2.
NO amend: adding a "(round 2: review cures)" line would claim cures that do not exist.

## Findings as briefed (verbatim) → disposition, with blob evidence (all greps on `git show HEAD:./<path>` at 2fecd5f26, cwd candy-code)

1. MAJOR "candy-code/tests/Hooks/HooksDefinitionTest.php — add 4 pins (a) 'format' rides the
   hooks() definition shape, (b) format.autoTable key present+wired, (c) format.width int positive,
   (d) absent-when-disabled" → FILE ABSENT in worktree, master (6561776f8), every cc/* branch,
   prompt_kit, /tmp/opencode (find + `git cat-file` loop 0 hits). design-tool.md C-TOOL-5.b names
   no such file and no format.autoTable/format.width keys (`git grep -i 'autoTable'` → 35 hits, ALL
   the FormatAutoTable class-name; the config keys are `formatAuto`/`formatCommands`). Substance
   pinned without holes: (a) DocFigureProseDriftTest:2292 hooks() bodyExcerpt window (lane's own
   round-1 cure, green in the 157T gate); (b) HooksMcpSettings.php:73-74 definition + Bootstrap.php
   blob format-wiring tokens ×5 (formatAuto/withAuto/AUTO_SETTINGS_KEY) + LayeredSettings userTier
   roster pin; (c) unpinnable as written — no width knob exists; nearest real arm is
   FormatRunnerTest::testWithTimeoutFloorsAndRejectsNonsense (green); (d) pinned THREE ways:
   PostEditFormatHookTest::testTheHookStaysOutOfTheChainWhenNoCommandIsConfigured (assertNull on
   real Bootstrap::hooks()), ::testTheLaunchRefusesTheArmedTableForAnUntrustedProject,
   FormatRunnerTest::testTheArmNeedsTheSettingsBitAndTheTrustTogether.
2. MINOR "hooks.php:96-106 'format' help lies script-only" → `candy-code/hooks.php` exists NOWHERE
   (only `builtin-commands/1390-hooks.php`, 21 lines, 0 'format' tokens); `HooksDefinition.php`
   exists nowhere (the real definition file is HooksMcpSettings.php). The actual help strings
   (lang blob:3281/3282) carry ZERO 'script' substrings — grep -ci 'script' on both format helps = 0;
   :3282 documents the auto table truthfully. No lie exists to rewrite.
3. MINOR "README.md:940-943 repeats the script-only lie" → blob lines 940-943 are the
   context-pruning/`Prune/Compress/Recall` prose (quoted in this lane's gate session, verbatim:
   "keeps every output and every row. All of it — and the agent's own `Prune`..."). Format docs live
   at README:1293-1294/:2413 and are guard-checked. False line-cite.
4. MINOR "FormatRunner.php catch folds child failure getMessage() (currently generic)" → blob
   `grep -c 'catch\|getMessage'` = 0 (whole file read: zero try/catch). Child failure is already
   folded two ways: $output = trim(stderr+"\n"+stdout) rides every FormatReport, and the restore
   reason is SPECIFIC ('the command exited %d after already rewriting the file'). Pinned by
   testTheRestoreNoteNamesTheReasonAndKeepsTheFormattersOutput +
   testAFailedRunThatAlreadyRewroteTheFileIsUndone (green).
5. MINOR "docs/SETTINGS.md formatAuto roster row missing" → blob `grep -c 'formatAuto'` = 4
   (:306 producer row with Bootstrap::hooks()→withAuto wiring, :1248 tier table, :1402 restart
   class, :1680 user-tier list); SettingsSchemaDocDriftTest green inside the 157T gate battery.

## substr_count ledger (brief asked 0→1 per cure)
No token was added — every demanded token is either ABSENT-by-design (HooksDefinition*, hooks.php,
'format.autoTable', 'format.width', catch/getMessage in FormatRunner, 'script' in the format helps)
or ALREADY PRESENT (formatAuto×4 in SETTINGS, format-wiring×5 in Bootstrap, null-hook pin in
PostEditFormatHookTest). A 0→1 flip would mean fabricating a defect to cure.

## Mutation outcomes (no new mutations — nothing shipped; recorded from disk-verified reviews)
r2 REVIEW-2 §4 (this dir, the CLEAN verdict): "Neuter of the commandFor auto loop, the fold, or the
register door each reddens named pins" — the pins re-ran green here as proof the arms are live.

## Gates re-run at tip 2fecd5f26 (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, no PtyRun; all exit-OK)
- FormatAutoTable|FormatRunner|PostEditFormat: 53T/278A (HooksDefinition leg: 0 tests — file absent)
- ReadmeRosterDrift|EnvRosterDrift|SettingsSchemaDocDrift|DocFigureProseDrift: 157T/42031A
- LayeredSettings: 109T/427A (exact vs the brief's mandated figure)
- git worktree clean after all runs. C-TUI-7.a: no branch/worktree (0 hits in worktree list; the
  brief's own cite ea1f22bf7/f19c5cdb1 are C-TUI-7.B commits) — the round-2 "collision" excuse is
  indeed dead, but the collision never existed and the findings the brief attributes to REVIEW-2
  are the phantoms, exactly as FIX-2 (03:15, written against the 5-item channel text) concluded.

## Author law (tip unchanged, re-verified)
%ae and %ce greps SEPARATE: both detain@interserver.net, 1 each; zero Co-Authored-By/Signed-off
trailers. suite-figure.json / durations.tsv / dist absent from the lane's 14-file numstat.
