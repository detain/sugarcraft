# C-AGT-6.b — /review ranges A..B / A...B — LANDED

Commit: 3abfb69ef on cc/C-AGT-6.b (base bb8d741c2). Author/committer Joe Huss <detain@interserver.net> (separate greps 1/1), zero Co-Authored/Co-developed trailers, tree clean.

Files (6, +438/−48):
- candy-code/src/Attachments/ContextMentions.php (diff()+isDiffRef range legs, new diffRangeSides/isRefSide/diffRange/attachDiff, RANGE_DIFF_FLAGS)
- candy-code/src/Commands/ReviewCommand.php (refError pre-resolves each side; docblocks)
- candy-code/lang/en.php (cmd.review.bad-ref + cmd.review.hint list accepted forms again)
- candy-code/docs/COMMANDS.md (regenerated via tools/gen-command-docs.php --write, in-step)
- candy-code/tests/Attachments/ContextMentionsTest.php (+5 tests, helper repoWithTwoBranches)
- candy-code/tests/Chat/ReviewCommandTest.php (range-refuse arm flipped to attach, +2 refuse arms, isDiffRef range block, +diffRangeSides test; helper reviewRepoWithBaseBranch)

Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml):
- --filter ContextMentionsTest: OK 19T/172A (2 pre-existing deprecations, present at base too)
- --filter ReviewCommandTest: OK 17/17
- --filter CommandDocsDriftTest|LangParityTest|LangLaunchLocaleTest: OK 26T/2889A
- --filter DocFigureProseDriftTest|ReadmeSuiteFigureDriftTest: 95T, only the pre-existing staleness pair red (README 25,537 vs live 26,518 — fleet carry, weld re-pin; not touched)
- Red-before proven via /tmp cp-harness (base src swapped): CM 5F, RCT 1E+2F; green-after as above. NEVER git stash.

Design conformance: two-dot `git diff --no-ext-diff --no-textconv --no-color -M --patch A B`; three-dot `--merge-base` with exit-129 fallback to `merge-base`+`diff` (sha-validated); all spawns through the bounded GitRunner::capture (no new proc_open site); hostile diff.external test proves porcelain flags hold; malformed ranges refuse at the scanner choke with the existing 'is not a git revision' notice; refError fails closed on any side's timeout/nonzero exit.

Deviations (2, both trim-law truths found while pinning): '@diff:base..' and '@diff:....' cannot reach the range arm through the scanner — TRAILING_PUNCTUATION trims them to '@diff:base' / '@diff' before isDiffRef sees them; pinned that honest behavior instead of forcing a notice. isDiffRef/diff unit paths still refuse both shapes directly (unit arms kept).

New test files: NONE (both files pre-existing) → no durations rows owed; suite-figure drift is added methods only → ReadmeSuiteFigureDrift pair already red at base, weld-side re-pin carries it.

Note: removed an untracked byte-identical base-era copy of ReviewCommand.php that an early edit mis-wrote to src/Attachments/ (never staged, verified diff-empty vs HEAD:Commands path before rm).
