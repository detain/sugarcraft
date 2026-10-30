VERDICT: CLEAN

# W32 wave review r1 — C-TUI-5.c (Settings hot reload + `/settings reload`)

Master tip under review: 740c29868. Wave = single step, welded.

## Weld SHAs re-read from git (phantom law) — all real, all ancestors
- Mark `#### ✅ 4f3e6a977 C-TUI-5.c` (plan_comparison.md §4 W32, body at :3548) cites picks
  cb1950382/c9aed0031/4f3e6a977 onto 3568d3397. `git merge-base --is-ancestor <s> master` = YES 3/3.
- `git log b77a18838..6b8fee691` = EXACTLY 3 (0e8c488cc, 49072ea07, 6b8fee691) — EXCL-start law (j) holds.
- `git cherry master 6b8fee691 b77a18838` → `- - -` : 3/3 absorbed (claim "3/3 patch-id SAME" corroborated).
- Authors: 3/3 `%ae %ce` = detain@interserver.net; `git log --format=%b` of picks grep -ci co-authored = 0.
- run-state.json cross-read agrees (KEEPER line: "picks landed cb1950382/c9aed0031/4f3e6a977"; weld+mark ae009262d, evidence a12ce3931, rider d0aa881a6 — all on master, mark flip + DUR rows path-scoped in ae009262d: only plan_comparison.md + durations.tsv).

## Claims vs landing — landed-blob proof on git show 4f3e6a977:<path> (and live HEAD)
- `src/Config/SettingsFileWatch.php` present: seedAtLaunch() :145-147, changedKeys() :88, watchedFiles() :163; zero proc_open/timer/Loop/sleep occurrences (pure watch — E646 clean, no child discipline engaged).
- `Chat::SETTINGS_DOOR_ONLY = ['provider','layout']` :7838; routing arm `in_array(...) → $restart; continue` :7949 placed AFTER the `mode !== Live` gate and BEFORE ENGINE_SETTINGS/catch-all — MAJOR-1 cure exactly as marked (live master HEAD :7838 too).
- `Bootstrap::chat()` calls `SettingsFileWatch::seedAtLaunch();` at :1287 after buildChat (scope extension disclosed in mark) — live HEAD confirms.
- Settle arm HandlesTurnCompletion.php: changedKeys() :311 → applySettings(..., 'chat.settings.reload.disk') :314, $reloadCmd joined into the emitted batch :407 — wired, not dormant.
- lang: cb1950382 adds cmd.settings.hint `[search|reload]` + reload.disk/unchanged; reload.one/.other "/keys reload" lines predate the base (3568d3397) — belong to TUI-4a, not this wave (checked: no stray wave edit of them).
- README:1268 + docs/SETTINGS.md:1587/:1622-1624 door-exception prose present on HEAD (r3 cited :1258/:1616 lane-tree numbers — merged-line drift only). docs/COMMANDS.md row `[search\|reload]` :372.
- Tests on HEAD: SettingsFileWatchTest 12 `function test`, SettingsReloadTest 8 — matches r3 TRUE figures and DUR rows.

## Step-loop record (stat'd on disk before trusting)
FIX-1.md 3420B, REVIEW-1.md 9803B, REVIEW-2.md 8998B, REVIEW-3.md 9666B; chain honest: r1 2MAJOR → r2 re-confirmed 2MAJOR (first fixer died, work lost — disclosed) → fix 6b8fee691 → r3 CLEAN 0C/0M. The t323 fixer-phantom (claimed 6e8f31437): `git cat-file -t 6e8f31437` fails — repair lineage verified on disk; final state truthful.

## Merged-tree gates re-run on master (candy-code tree CLEAN at 740c29868 — gates not dirty-laundered)
- `php tools/gen-settings-doc.php --check` rc0 "settings docs: up to date"; `gen-command-docs.php --check` rc0; `gen-tool-docs.php --check` rc0; `php scripts/gen-protocol-schema.php --check` rc0. Protocol trio + generated.ts: wave touched none (pick file lists clean) — no half-regen hazard.
- `--filter 'SettingsFileWatchTest|SettingsReloadTest'` → **OK, Tests: 20, Assertions: 71** (2 standing deprecations), PtyRun filter-exclusion N/A (not matched).
- Guard family `CommandDocsDrift|SettingsSchemaDocDrift|ReadmeRosterDrift|ReadmeSettingsTierClaim|LangParity|ReadPathCensus` → **OK 67T/4382A** (lane-tree 4347A; merged-tree +35A wobble, green — per-exact-class lesson 1030S honored).
- `ReadPathCensusTest|DuplicatedTestHelperDrift|DuplicatedDocBlockLine|DocFigureProseDrift` → **OK 129T/5133A**; foreign-row cures landed (rows `Server/ServerConfig.php|file_get_contents` :959, `Session/Import/RemoteSessionPush.php|file_get_contents` :972 present in tree and introduced by pick cb1950382's +28); own rows `Config/SettingsFileWatch.php|file_get_contents` live (design said `hash_file` — r2 content-hash rewrite changed the spelling; row matches real code, census green).
- `ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest` → **OK 13T/362A**.

## Hygiene
- No `cc/C-TUI-5.c` branch, no `/home/sites/cc-cmp-C-TUI-5.c` worktree (worktree list = other live lanes only). Lane REPORT.md loss disclosed in rider d0aa881a6 (run-state:219). Evidence commit a12ce3931 carries REVIEW-1..3 + FIX-1 + WELD-1030U log on-branch per r3 carry list.

## §7 disclosures (not wave findings)
- Suite movement for the rider list: **+20T/+71A** live enumeration (SettingsFileWatchTest 12 + SettingsReloadTest 8; new test FILES, both DUR-minted median-of-3: 0.059/12, 0.130/8). suite-figure/README staleness pair = §7 closeout duty; mark says NO re-pin/NO battery — correct per law.

## Findings
- NIT prompt_kit/findings/comparison-1026/lanes/run-state.json (mark §4:3548): weld commit ae009262d also minted/re-timed TWO durations rows outside this wave — `tests/Diagnostics/RuntimeNoticeSinkTest.php` 0.068/30→0.128/35 and `tests/Chat/RecallNoticeRowTest.php` 0.059/9 (both C-CTX-6.e's §7 obligations, files already on master). Rows are needed and correct (absent rows run zero shards); only the mark's DUR line under-discloses them. Fix if wanted: one-line note on the C-CTX-6.e mark or §7 rider list attributing the two rows to the 1030U weld.

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT.
