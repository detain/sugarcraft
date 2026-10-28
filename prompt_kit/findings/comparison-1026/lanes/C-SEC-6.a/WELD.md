# C-SEC-6.a — WELD (2026-10-28, welder lane-2 of W16 pair)

## Pick
- Pre-pick live tip: `a051953165eb53314c39d67c464a41acaf436da5` (post lane-1 weld).
- `git cherry-pick 675241a0484a4f987a761cddeb5a6b2ac3342013` → **`be226b2b03a94ae22db8d8145410c45b54a7e3a0`** — CLEAN: the only auto-merges were the three soft-append/union zones (`docs/COMMANDS.md`, `docs/PERMISSIONS.md`, `lang/en.php`) — as the slot law predicted (5.b/6.a union zones distinct). Patch-id **9c215d8e340622affb6c1b6fed5872a178e6f422 IDENTICAL** lane↔master. Author `%ae`/`%ce` separate greps = detain@interserver.net 1/1, zero Co-Authored/Caliber trailers.
- Lane set: ONE commit, 9 files +944/−62 (SavedRules service + `/permissions saved|forget`; new files src/Permissions/SavedRules.php, tests/Host/Commands/PermissionsSavedRulesCommandTest.php, tests/Permissions/SavedRulesTest.php; SettingsWriterCensusTest MODIFIED in-step).

## Regen riders: NONE owed
Zero `src/Protocol/**` and zero `candy-code-web/**` paths in the pick. COMMANDS.md hint row was regenerated INSIDE the pick → `CommandDocsDrift|SettingsWriterCensus` standalone OK 11T (no `weld: … regenerate` commit).

## Gates on the merged tree (plain pipe, env -u LINES -u COLUMNS, cwd candy-code, -c phpunit.xml; PtyRunToolTest never in any filter)
- `--filter 'SavedRulesTest|PermissionsSavedRulesCommandTest'` → **OK 24T/100A EXACT** (r2's claim); junit per-file split 14T + 10T matches the FIX-1/r2 breakdown 14T/38A + 10T/62A (assertions land in the 100A sum).
- Guard battery `GlobDialect|EnvRosterDrift|TrustKey|ConfigWriteProducer|ReadPathCensus|StderrEmitterCensus|CommandDocsDrift|SettingsWriterCensus|PermissionFsmExtractionParity|KeyBindingDrift` → **OK 447T**/68393A (T EXACT vs r2's 447T/68244A; +149A = tree-scan drift since the lane base — same documented shape as lane-1's StderrEmitterCensus +54A note).
- r2's combined-family 352T/13727A (sole failure foreign PermissionsIntroDrift): reproduced IN SUBSTANCE by the exact pair run + battery + intro-drift run above; REVIEW-2 did not record the combined filter string itself, so the 352T count was not re-derived at weld.
- `--filter PermissionsIntroDriftTest` → 5T, **1 failure** = `testThePatternToolRosterIsTheWholeCatalog` — the ONE foreign standing red (missing Schedule roster row, C-AGT-5.a era). Re-proven at weld: the failure set is identical pre-pick (lane-1 gate ran it at a05195316), post-pick, and post-fold; the pick's PERMISSIONS.md hunk (@@911 +6) does not touch the tested roster paragraph (r2 byte-identity proof base↔tip).
- Doc-guard superset `TrustKey|DocFigure|ReadmeRoster|EnvRoster|SettingsSchema|CliLang|LangParity` → **OK 237T**/49039A post-fold (237T EXACT; A wobble from re-wrapped paragraph rows). Known standing reds for closeout: ReadmeSuiteFigureDrift staleness pair (this lane +24T; NEVER touched here) + the foreign PermissionsIntroDrift arm.

## Weld-fold NIT — APPLIED
r2's one non-blocking NEW NIT: `docs/PERMISSIONS.md:925` said `/permissions saved` "numbers every stored entry" — post-MINOR-fix only LOADABLE rows are listed/numbered (shape-junk dropped), self-corrected on-screen by the "(N)" header, so NIT-grade. Unpinned proof: greps for "numbers every"/"every stored entry" hit ONLY that doc line (the one other hit, src/CommandParser.php:110, is unrelated "renumber every later one" prose); no DocFigureProseDriftTest/ReadmeRosterDriftTest/PermissionsIntroDriftTest arm carries the sentence. Fold applied: → "numbers the rows the launch would load" (brief wording; r2's alternative "every loadable" is the same correction), paragraph re-wrapped at house width. Post-fold re-runs: PermissionsIntroDrift unchanged 5T/1F-sole-foreign, superset 237T OK → zero guard flips. The fold rides this weld commit as a weld cure.

## sec6a_selfreview_anomaly — RESOLVED (run-state anomalies_ledger)
The lead accidentally resumed the fixer session (ses_e7cefc13e) with the r2 reviewer brief. Authoritative verdict = fresh reviewer ses_e7ce37aa2 (REVIEW-2.md CLEAN 0C/0M/0m, + independent r2b appendix re-verify EXACT). Disk settlement at weld: `git log master..cc/C-SEC-6.a` showed exactly ONE commit (675241a04) — no fixer commits landed on top; r2's phantom-gate (tip + porcelain clean) means the audited code content == the picked content; master patch-id identity re-proves it. REVIEW-1 + FIX-1 kept in the evidence set for provenance.

## Durations
Two NEW rows (scripts commit `454e41176`): `candy-code/tests/Host/Commands/PermissionsSavedRulesCommandTest.php 0.033 10` @881 (0.033 tie band) and `candy-code/tests/Permissions/SavedRulesTest.php 0.032 14` @884 (0.032 tie band); sums from ONE pair-filter `--log-junit /tmp/opencode/cc-cmp-junit-sec6a.xml` per-testcase accumulation. Set-equality: tsv **1530→1532 VERIFIED** (+2, grep-count 2), desc-invariant (asc-violations 4==4). SettingsWriterCensusTest is MODIFIED → no row owed. suite-figure.json NEVER touched (§1.5 law; +24T rides closeout re-pin).

## Foreign list — untouched
Root strays `escalated` (0-byte) + stale root `run-state.json` left as found; authoritative run state is `lanes/run-state.json` (this commit carries it: inflight drops C-SEC-6.a, welded 155→156, len==count holds).

## Closeout carries
- suite-figure.json staleness pair: +24T by this lane (+12T by C-SEC-7.d on top).
- PermissionsIntroDriftTest Schedule-row standing red (foreign, C-AGT-5.a owner).
- Battery A-figures at weld tip (68393A, 49039A, 21466A) — for reviewers comparing against lane-era figures (68244A, 48953A, 21412A).
