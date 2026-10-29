# C-EXT-3.e REVIEW-2 (r2) — tip b7f980576 (delta f18b8cd86..b7f980576)

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 1 NIT → closeout). r1's step-local MINOR (unchecked head==1 removals) is genuinely cured and pinned.

## (a) c607a755c — hygiene-only, verified
- SkillWorkshop.php change is comment-only; respell REQUIRED: SkillsHostCommandTest:221 censuses raw file text (docblocks included) over src/Tools/** for 'ProposedSkills' — tip now has zero hits (grep). Production behavior: none.
- 8 refusal pins → capture-then-assert: every needle assert preserved; fail()-inside-catch(\RuntimeException) vacuity hazard mechanically removed (assertNotNull names the refusal). Stranded-case asserts moved after the finally chmod-restore — strength preserved, cleanup guaranteed. SwallowingCatchCensus green for the file in my runs.

## (b) b7f980576 — check semantics PASS
- `@unlink === false && file_exists` idiom: EACCES-with-survivor → raise; ENOENT (retry / user-deleted) → treated done, correct for the desired end state. No-x-dir corner (file_exists blind) unreachable: revisionNumbers must traverse the same dir to get here.
- Revision-leg refusal preserves history/1.md bytes (failed unlink = untouched file) and message says retry; retry path walked: live leg ENOENT-tolerant → revision leg completes — pin proves it end-to-end (assertFileExists(1.md) → unseal → action removed, revision 0, dir gone).
- rmdir legs: history unchecked-@rmdir + dir-rmdir residue arm is honest — by then both recorded files are gone; a survivor is residue-named, never a false "removed".
- Root polarity: no markTestSkipped (grep 0); both legs assert honest success under euid 0; no skips, per house precedent.
- New literals not glob-shaped / not **Refused:**-shaped (§1.9); GlobDialect+DuplicatedTestHelperDrift in my battery run: only the 2 known reds. SKILLS.md sentence landed same commit; doc guards green.

## (c) Gates re-run at tip (cwd candy-code, -c phpunit.xml, plain pipe)
- Trio: "Tests: 33, Assertions: 216" (0F) — per-file 18/8/7 methods re-derived (grep). Guard filter set: tests/Skills 616T OK + 166T/43912A OK (≈ builder's 777T/46250A combined). Census battery: "Tests: 206, Assertions: 56710, Failures: 2" — same names/messages as claimed. tests/Config: "Tests: 679, Assertions: 61336, Failures: 9" — staleness pair (#7/#8, welder +33T re-pin) + 7 reds in domains the delta never touches (foreign, builder base-proved via probe worktree).
- R-EXT-2: plan §6:4129 "go live immediately with an announce-and-undo notice" — live-not-draft semantics untouched by r2 delta. Exact.

## (d) Spot mutation — RUN-BLOCKED, by-construction substituted
My permission map denies cp/md5sum shell and edit on src (same block r1 documented). Analysis: M1 (live-leg neuter) → leg-1 scenario returns 'removed'+residue, assertNotNull(liveStuck) :244 is the first failing assert → exactly 1 red (test aborts there). M2 (revision-leg neuter) → leg-1 arm untouched, leg-2 assertNotNull :277 → exactly 1 red. One-red discrimination holds; builder's cp+md5 evidence stands in REPORT.md.

## Findings
- NIT (closeout): commit message says "leg-1's 11 assertions stay green"; leg-1 non-root block carries 8 asserts (9 in leg-2, 17 total — arithmetic of the pin itself is right, only the prose count drifts).

## Weld carries (final)
- DUR rows ×3: LearnedSkillsTest 18T, SkillWorkshopTest 8T, SkillsHistoryUndoTest 7T.
- suite-figure re-pin: trio era +33T (216A), from b7f980576.
- §4 cite final tip b7f980576. Foreign routing from r1 stands (ChatHostLangCatalogue, ContainedPathInventory, KeyHelp 5F + 49-col re-measure owed to the C-WEB-4.e-era repair — lane lengthens the /skills hint further).
- Reviewer-side reds at tip outside the pair: the 9-name tests/Config set + 2-name census set listed above, all foreign/pre-base.
