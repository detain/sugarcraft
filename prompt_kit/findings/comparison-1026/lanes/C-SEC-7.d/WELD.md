# C-SEC-7.d — WELD (2026-10-28, welder ses (this bundle), lane-1 of W16 pair)

## Pick
- master pre-pick tip: `a8307781182e76e4aa6ec815fee568d6e8a19254` (live re-read).
- `git cherry-pick 75beffe3ec81a1b3859e378a7915cda221885c58` → **`fecec0d995ce520f8f131a2984d1f417b5051806`** — CLEAN, only auto-merge was `candy-code/lang/en.php` (soft-append zone, both sides kept). Patch-id **0c8e38cf55dcc88aaed26d0267a86705bab8f63c IDENTICAL** lane↔master.
- Author law on the pick: `%ae`/`%ce` separate greps = detain@interserver.net 1/1; zero Co-Authored/Caliber trailers.
- Lane set: ONE commit, 14 files +740/−104 on base `e4a705244` (master advanced 16 commits since the lane cut — zero file overlap except en.php).

## Regen riders: NONE owed
Verified `git show --name-only 75beffe3e` contains ZERO `src/Protocol/**` paths and ZERO `candy-code-web/**` paths — no schema/SERVER.md/generated.ts/npm legs. No `weld: … regenerate` commit.

## Gates on the merged tree (plain pipe, env -u LINES -u COLUMNS, cwd candy-code, -c phpunit.xml; PtyRunToolTest never in any filter)
- `--filter 'AutoReviewScopeTest|UnflaggedShellReviewTest|ExecReviewerTest'` → **OK 43T/136A** EXACT (r2 figure; +1A by design vs r1's 43T/135A at `59fa0d4ec` — the amend's reflection tools-leg pin in `testTheModelOverrideBuildsItsOwnToollessBackend`).
  - Trio figure erratum RESOLVED: the builder REPORT states 43T/135A because it measured the PRE-amend tip; REVIEW-2 measured 136A at the final tip 75beffe3e. The weld gate confirms 136A on master. (The brief's loose filter `AutoReviewScope|UnflaggedShellReview|ExecReviewer` matches one extra `BinCandyCodeWiringTest` data-set row — measured 44T/142A rc0, green; filter artifact, not lane dirt.)
- `--filter StderrEmitterCensus` → **OK 95T/21466A**. Census flip provenance: Bootstrap emitters **40→43**, prefixed-once **8→11**, prose FORTY-THREE — all in-step in the pick (r1 REVIEW-1 walked it; BootstrapLaunchFormatConstantsTest green per r1). Assertion delta vs r2's 21412A is tree-scan re-count from master drift since the lane cut: `a83077811` (C-CTX-2.d) itself amended StderrEmitterCensusTest emitters. The 95T count EXACT and rc0 is the gate truth.
- doc-guard set `--filter 'TrustKey|DocFigure|ReadmeRoster|EnvRoster|SettingsSchema|CliLang|LangParity'` → **OK 237T**/49018A (237T EXACT vs both review rounds; A wobble same tree-scan shape).
- `--filter PermissionsIntroDriftTest` → 5T, **1 failure** = `testThePatternToolRosterIsTheWholeCatalog` — ONE foreign red (missing Schedule roster row, C-AGT-5.a era). NOT attributed: proven inherited twice (r1: zero-line diffs in ToolCatalog.php + the PERMISSIONS.md roster paragraph; r2 at tip: same + this pick touched neither input).

## NIT-A/B notes (r1's two step-local MINORs, cured in the amend 59fa0d4ec→75beffe3e; src byte-identical across the amend)
- NIT-A: docs over-claimed `writes` shows WebFetch "with the capped pending diff" — AskDiffPreview has no WebFetch arm. Cured: both README.md and PERMISSIONS.md sentences now carry the lang-key qualifier (WebFetch rides its url head + arguments). Guard greps proved the sentence unpinned (no DocFigure/ReadmeRoster arm).
- NIT-B: "tool-less" reviewer backend rode EngineBackend ctor defaults unpinned (audit-15b-12 law, first time on a USER-chosen model). Cured: reflection assert `tools === []` in AutoReviewScopeTest:172-177; non-vacuity derived structurally by r2 (assertInstanceOf precedes; private readonly prop read; model-name leg pins the override instance).

## Foreign list — untouched
Root strays `escalated` (0-byte) and stale root `run-state.json` (2026-10-27 copy) left exactly as found; authoritative run state is `lanes/run-state.json` (this commit carries it: inflight drops C-SEC-7.d, welded 154→155, len==count holds).

## Durations
NEW test file `candy-code/tests/Permissions/AutoReviewScopeTest.php` (A-status, sole new file; ExecReviewerTest/UnflaggedShellReviewTest are appends) → one tsv row `0.050 12` (junit per-testcase sum, /tmp/opencode/cc-cmp-junit-sec7d.xml, 12 testcases), desc-invariant insert at line 815 (inside the 0.050/0.05 tie band). Set-equality live recount: tsv **1529→1530** VERIFIED (+1, grep-count 1). suite-figure.json NEVER touched (§1.5; +12T rides the standing staleness for §7 closeout re-pin).

## Closeout carries
- 1 tsv row (landed, scripts commit named in weld history).
- suite-figure.json staleness pair: +12T by this lane (gate duty, not weld).
- PermissionsIntroDriftTest Schedule-row standing red (foreign owner C-AGT-5.a era).
- StderrEmitterCensus A-figure at weld tip: 21466A (for any future reviewer comparing against lane-era 21412A).
