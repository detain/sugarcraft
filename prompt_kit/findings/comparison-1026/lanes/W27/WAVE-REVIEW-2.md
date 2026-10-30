# W27 wave-loop REVIEW round 2 — plan_comparison.md @ master 52315bef8

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT. The two r1 MAJORs are CLOSED with independent re-derivation. Loop is closed on non-blocking grounds: a welder may flip §3 ✅W27 + waves_closed (welder duty, not this reviewer's), carrying NIT-1 as an optional one-liner and the untracked-evidence sweep already assigned to welder-5.

## NIT-1 (non-blocking, fix-now-if-trivial)
- candy-code/tests/Chat/ChatHostLangCatalogueTest.php:252 — the calls-leg key regex `/^(?:chat|host)\.[a-z0-9_.]+$/` (strict lowercase) vs the widened ask-leg class `[a-zA-Z0-9_.-]` (includes A-Z) is an intentional asymmetry (camelCase key `chat.serviceTier.launch_unshaped` is ask-discoverable but its params stay uncensused), disclosed in lanes/W27/FIX-m2.md and the lead m2_note — but NOT in any in-file comment (grep camel|serviceTier|asymmetr = zero hits in the test file). Fix: one-line comment above :252 noting the camelCase carve-out lives in FIX-m2.md. The evidence file is currently untracked; once the welder sweep commits it the disclosure is durable, so this is a pointer nicety, not a hazard.

## Duty 1 — r1 Step1-3 unregressed at 52315bef8: CONFIRMED
- 10/10 §4 wave marks anchored `^#### ✅`: C-MCP-4.c eea5615e3 (:3255), C-SEC-8.b (:3266), C-SES-7.c (:3278), C-SES-8.g (:3289), C-TUI-3.b (:3300), C-TUI-3.e (:3311), C-TUI-4.b (:3322), C-TUI-4.c (:3333), C-WEB-2.c (:3345), C-WEB-2.f (:3356). All cited SHAs ancestors of 52315bef8 (merge-base --is-ancestor 10/10).
- Authorship: all 30 wave-cited master SHAs (28 mark-cited incl 71db7da0c + 2 loop fixes 1ff9f5013/52315bef8) verify `%ae %ce = detain@interserver.net detain@interserver.net`; zero Co-Authored-By trailers across the 29-commit wave+fix scan (git show -s per-commit loop). Attribution: the 2 fixes are loop commits, not lane steps — r1's 27 lane-commit figure stands as its subset count; no history rewrite (every SHA identical to r1 citations).
- E646: both fix diffs carry zero timers/wall-clock kills (EffortDial diff is a pure call-arg split; LayeredSettings diff is test-literal only).

## Duty 2 — MAJOR-2 cure (ChatHostLangCatalogueTest): CONFIRMED substantive
- Gate: `--filter ChatHostLangCatalogueTest` = 3T/9A/0F (2 pre-existing PHPUnit Deprecations, house baseline).
- sources() widened with the 8 ask-home files (EffortDial, Renderer, Theme, Diagnostics/RuntimeNoticeSink, Support/StartupSummary, Attachments/FileMentions, Permissions/SavedRules, Cli/LaunchContext); docblock :151-166 per-lane attribution names 75d2d19f5 (C-WEB-2.f), 83353667a (C-TUI-4.a), 9c59bc8d1 (C-CTX-6.e), 16266186d (C-CTX-6.c), C-SES-8.g/Cli-LaunchContext pair — all 4 external SHAs cat-file EXIST with subjects matching the lane claims.
- Vacuity analysis (mutation law, by reasoning): (a) ask-leg still bites — keys in lang/en.php roster minus keys found-asked across sources() feed assertSame([], $unused); a genuinely NEW stranded key (asked nowhere in the scan set, no quoted mention anywhere) still reddens; if sources() ever matched zero files the arm floods red, so widening cannot silently no-op. The widened A-Z class only removes FALSE strandings (the camelCase key that was unmatchable pre-fix); it introduces no new blind spot beyond the pre-existing raw-text (comment-inclusive) shape, which governed lowercase keys identically before. (b) calls-leg unchanged: tokenizer skips T_COMMENT/T_DOC_COMMENT (:202-206), strict class at :252, $seen>100 positive control intact — so real Lang::ask CALL sites are still census-enforced with their params, and the only asymmetry is the disclosed camelCase key's param leg (see NIT-1). Safe.

## Duty 3 — EffortDial.php:110 split: CONFIRMED behavior-neutral
- Gate: `--filter 'EffortDial|EffortHostCommand'` = 25T/91A/0F EXACT.
- Diff read: ternary split into two explicit Lang::ask calls — unsupported_model passes provider+model, unsupported passes provider only — byte-matching lang/en.php :2291/:2292 placeholder spellings (the r1 param-flood cure); in-file W27-m2 comment documents the split.

## Duty 4 — guard batch + adjacents: GREEN
- `--filter 'DocFigureProseDrift|DuplicatedTestHelperDrift|GlobDialectDifferential|ReadmeRosterDrift|CommandDocsDrift'` (exact classes, staleness family deliberately excluded) = 135T/4840A/0F — matches brief expectation exactly.
- LayeredSettingsTest = 99T/405A/0F (MAJOR-1 cure holds at tip).
- Adjacent sweep ScriptHook family = 149T/685A/0F green.
- All runs: cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe; PtyRun excluded-class never touched; no full suite run.

## Duty 5 — fix-commit hygiene: CONFIRMED
- git show --name-only 52315bef8 = src/Commands/EffortDial.php + tests/Chat/ChatHostLangCatalogueTest.php ONLY.
- git show --name-only 1ff9f5013 = tests/Config/LayeredSettingsTest.php + prompt_kit/.../W27/FIX-m1.md ONLY.
- Zero touches to suite-figure.json, durations.tsv, dist/, generated trio, COMPARISON.md, plan §4. No hand-edited generated files; no weld-only file committed by a lane; no cc/* W27 branch or worktree remains (worktree list + branch --list cc/* show only the foreign in-flight C-TUI-5.e and owner-stopped C-TOOL-6.a lanes).

## Duty 6 — §7-carry current state (all dispositioned, none regressed)
- heading-twins (C-SES-8.e/C-TOOL-2.f): W26-r3 §7 bookkeeping carry, untouched — correct per instruction.
- --worktree:1890 standing: r1 measured red at :1890, base-inherited via 237224a7a — unchanged §7 standing item.
- MCP-4.c 97A→93A erratum: r1 NIT-1; plan:3255 mark still reads "McpLoginServed 10T/97A" — uncorrected, lead run-state already carries "MCP-4.c 97A->93A §7". Erratum, not regression.
- ThemeFileTest retime: landed in run-state batch1030r (DUR 0.034/9 after testcase-sum correction). DONE-recorded.
- UsageMethodsTest DUR: W25-n2 §7 figure carry documented; file exists, filter green (run-state). Standing carry.
- suite-figure/README staleness pair: NOT chased per exact-class-filter law; the fixes added zero test methods (99T and 3T unchanged) so they contribute no new drift; closeout re-pin duty stands.
- Untracked evidence awaiting welder sweep: lanes/W27/WAVE-REVIEW-1.md, lanes/W27/FIX-m2.md, lanes/C-TUI-5.e/{FIX-2,REVIEW-1,REVIEW-3}.md (git status ??). FIX-m1.md already tracked (rode 1ff9f5013). Sweep duty confirmed in run-state t235.

## Closed-loop statement
r1 MAJOR-1: cured at 1ff9f5013, re-verified green at tip (99T/405A). r1 MAJOR-2: cured at 52315bef8, re-verified green at tip (3T/9A + 25T/91A + 135T guard batch), vacuity-reasoned sound, attribution SHAs real. Wave W27 is REVIEW-CLOSED at round 2: 0C/0M/0m/1N (NIT optional, welder may fold the one-line comment or leave it — neither blocks). Welder may now flip §3 ✅W27, advance waves_closed += 27, and run the evidence sweep.
