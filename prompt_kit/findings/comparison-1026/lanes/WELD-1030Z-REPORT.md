# WELD-1030Z — C-CTX-5.d weld + C-CTX-6.e closeout (2026-10-30)

## Preflight + dispatch proof (disk-verified)
- master == d0aa881a6 "weld: C-TUI-5.c rider — absorption proofs, prune, REPORT.md loss disclosure" (1030U rider, fold landed 278==len asserted in registry).
- Proof 1: lanes/C-CTX-5.d/REVIEW-3.md = 10590B on disk, header "VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR".
- Proof 2: registry welders map holds only historical batch-13/batch-14 records; no other welder live; weld_pending = [C-CTX-5.d, C-CTX-6.e prune, CI-1030 provenance note].
- Branch chain: cc/C-CTX-5.d tip 35ba713a8; b503bb054..35ba713a8 = EXACTLY 3 (git rev-list --count 3): eb5108c9c builder -> 7179a0f58 fix -> 35ba713a8 evidence; all cat-file commit, parent chain exact, %ae/%ce detain@interserver.net, b503bb054 ancestor of master (67fc3e987-era weld base).
- Main-tree dirt: foreign per brief (lead agent file, lane files, AGENT_LAW_SNIPPET/LEAD_LAWS) — never staged.

## STEP 0 — evidence collect onto branch
- REVIEW-3.md (10590B, disk bytes) NOT on branch (fatal probe at 35ba713a8 = absent) -> to be added.
- FIX-1.md ALREADY on branch (cat-file -s 35ba713a8:..FIX-1.md = 4098B); disk 6160B is a later rewrite of the same fix round — per dispatch "if on-branch already, skip / never overwrite". SKIPPED, recorded here. Landed as a235ea37f (REVIEW-3.md only, +54 lines, %ae/%ce detain separate greps, 0 Co-Authored).

## STEP 1 — pick map (all cherry-picks, zero manual conflict resolutions)
| lane | master | notes |
|---|---|---|
| eb5108c9c builder 13f +1176/−59 | 839331749 | auto-merge x5 shared files, no markers |
| 7179a0f58 fix 4 files | 80fd89337 | auto-merge HandlesRunControl+SessionHost, no markers |
| 35ba713a8 evidence x4 | 4f2a9b224 | blocked once by UNTRACKED main-tree clones (sha256: REVIEW-1/2 identical, FIX-1 differs 6160B-newer, REPORT.md absent on disk); moved aside to /tmp/opencode/weld1030z-lane-files, picked, restored FIX-1(lead's newer, stays lanes/ dirt unstaged)+REVIEW-3(identical, clean) |
| a235ea37f evidence REVIEW-3 | 37f4ef6e7 | content == disk bytes (615227fded3e prefix match verified pre-move) |
Master drift since b503bb054 = 38 commits (CI-1030 x2 touched HandlesRunControl 4 lines; C-CTX-6.e 9c59bc8d1 touched SessionHost +17). Lane hunks are add-only and disjoint — auto-merge preserved both sides; collision list: NONE required manual resolution.

## STEP 1g — merged-tree gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; 2 PHPUnit Deprecations = base shape everywhere)
- tests/Host/KeepWarmTest.php direct: OK 17T/68A (dispatch expect 17T/68A EXACT)
- --filter 'KeepWarm': 19T/80A (2 extra tree-wide substring matches, benign)
- --filter 'KeepWarm|CacheHealthWatch': OK 40T/173A (dispatch expect EXACT)
- --filter 'IdleAutoCompaction|CacheTtlIdleFold|SessionHostIdleAuto|KeepWarm|CacheHealthWatch': 91T/356A GREEN (dispatch 71T/289A = narrower composition; actuals reported per welder law)
- --filter 'DocFigureProseDrift': 92T/4178A GREEN (expect ~92T/4177A; +1A merged-tree drift benign)
- --filter 'DuplicatedTestHelperDrift|SymbolCitationDrift|DuplicatedDocBlockLine|GlobDialect|EnvRosterDrift': 78T/59617A GREEN (lane era 77T/59568A, +1 derived provider row from new test file; dispatch 106T/4380A = different guard set, green here)
- protocol trio --filter 'ProtocolSchemaDrift|ServerProtocolDocumentationDrift|SettingsSchemaDocDrift': 32T/554A GREEN
- gen-command-docs / gen-tool-docs / gen-settings-doc: all 'up to date' rc0; gen-protocol-schema + web npm chain SKIPPED (0 src/Protocol, 0 candy-code-web files in lane diff)

## STEP 1h — DUR rows (scripts/parallel-tests-durations.tsv; junit-sum median-of-3, only the two lanes-file rows touched)
- NEW row candy-code/tests/Host/KeepWarmTest.php 8.947 17 (runs 8.947/9.053/8.825, 17 cases each; inserted at descending-duration band after ServeStatusStopTest 8.974)
- RETIMED candy-code/tests/Backend/CacheHealthWatchTest.php 0.004/16 -> 0.06/20 (runs 0.060/0.059/0.066, 20 cases; row previously did NOT reflect lane's +4 cases)
- diff proof: 2 insertions 1 deletion, no other rows touched. §7 suite-figure stays stale-by-design (+~18T) — closeout duty, mark carries it.
