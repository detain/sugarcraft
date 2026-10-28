# C-AGT-6.b — REVIEW-2 (round 2 cure verification)

VERDICT: CLEAN

Tip dbb4a129d (amended from 3abfb69ef), base bb8d741c2, single commit.
1. Cure scope: `git diff 3abfb69ef..dbb4a129d` = ContextMentions.php (docblock + exactly 3 argv tails gaining `'--'` at :415/:438/:441) + ContextMentionsTest.php (+1 method testAPathSideOfARangeRefusesInsteadOfAttachingAWorkingTreeDiff, 19→20 methods). Nothing outside the lane's two files.
2. Non-vacuity (proven against live git, not by claim): `git diff HEAD README.md` → rc 0 (pre-fix path side SUCCEEDS, dirty app.php would attach working-tree diff → the pin's assertSame([],attachments) reddens); `git diff HEAD README.md --` → "fatal: bad revision", rc 128 → existing exit-ladder emits 'git could not diff against <ref>' (same shape as the pinned no-such-branch notice). Structural substr_count legs (2×$from/$to + 1×$ancestor/$to with `'--'`) red deterministically under tail-deletion. Live worktree mutation impossible from this lane (edit/cp denied by map) — semantics proven at the git layer instead.
3. Merge-base semantics untouched: `--merge-base` flag preserved, `--` sits behind both revs (empty path list); full ContextMentions filter (includes r1-verified A...B range tests) green.
4. Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS): --filter ContextMentions → OK, Tests: 21, Assertions: 194 (brief "~20T" = method count; provider adds 1 — cosmetic); ReviewCommandTest → OK 17T/122A exact; 'CommandDocs|CommandRegistry|DocFigure' → OK 116T/4208A green (broader than brief's 26T/2889A — filter-coverage artifact).
5. Laws: %ae and %ce separately = detain@interserver.net; message body zero trailers; git status --porcelain empty; 2 PHPUnit Deprecations pre-existing env noise (all three runs).
6. Counts: branch carries no REPORT.md (lane docs live main-tree untracked); that file already reads +5 tests / live 26,518 — r1 NIT satisfied.
NIT (note only, non-blocking): new test's comment attributes the pre-fix attach failure to both separators; the three-dot `--merge-base` leg already refused path sides pre-fix (needs revs), so its `'--'` is defense-in-depth like the ancestor leg. Comment wording, not behavior.
r1 carried checks not re-litigated per brief.
