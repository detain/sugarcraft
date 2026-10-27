# C-AGT-2.a round 1 — VERDICT: FINDINGS (0C/0M/2 MINOR step-local/3 NIT)
Scope: diff ff10efc43..8a5693d14 = brief's 6 declared files + docs/COMMANDS.md (declared deviation 1).
Chat.php single hunk inside reviewTurn docblock+body (:11774-11794); projectRoot()/ctor param pre-exist (:7979/:1110);
isDiffRef sole prod caller is refError; reviewTurn shared by both commands (:11762/:11773).
Correctness: trimmed() char-walk keeps trailing } iff prefix holds unmatched @{ (traced: '@diff:HEAD@{1},' -> '@diff:HEAD@{1'; extra braces still trim; plain 'ref}' still trims -> round trip refuses); isDiffRef = empty/'..'/pattern + balanced-@{ + trim round trip (table ReviewCommandTest:276-289 matches trace); refError = empty-allow, scanner gate, GitRunner::available() abstain, bounded rev-parse --verify <ref>^{tree} @ DIFF_TIMEOUT_SECONDS=5.0 through escapeshellarg'd GitRunner (no new spawn site, no option injection — dashes/spaces refused at gate 1); E646 n/a (git not LLM).
Deviation 1 VERIFIED MANDATED: 2840-review.php:20 argumentHint=Lang::t('cmd.review.hint'); CommandsTableTakesColumnDriftTest re-derives the Takes cell from CommandRegistry::all(); hint change without row edit reddens the guard in-step; §1.8 moves the doc with the change; row is in the commands:table generated region and CommandDocGenerator:118 regenerates the identical cell — byte-convergent, accepted.
Deviation 2 VERIFIED LOAD-BEARING: dangling 'HEAD@{1' passes the trim round trip; without the count check a git-less machine bills a turn whose mention the scanner never attaches (the defect class); pinned (isDiffRef table + ContextMentionsTest notice arm); accepted.
No fabricated paths: attachment tests assert real diff bytes from real temp repos.
Non-vacuity: 6F pre-fix consistent with hand-trace (pattern-only isDiffRef + rtrim ate '}' + no gate 2).
Findings: [MINOR sl] REPORT.md:62-65 security-review "old validation path" claim false (validation is shared; residual = hint/doc wording) — reword, code needs nothing. [MINOR sl] ReviewCommandTest git helpers lack requireGit skip (ContextMentionsTest:308 idiom) — add available() skip to the four temp-repo arms. [NIT] BUDGET_SPENT misattributed as bad-ref (fail-closed, rare). [NIT] class docblock ':12' stale '[ref]'. [NIT] bad-ref row says "Review:" on security path (pre-existing).
Seam: /security-review hint row NOT absorbed (COMMANDS.md:389 = '[<ref>]', guard-consistent) — reported as required. Tree-wide grep: no stale '..HEAD' advertising.
Commit law: single commit, author+committer detain@interserver.net (separate greps), zero trailers, clean tree; no suite-figure/durations/dist/protocol/generated-schema touches; §1.8 satisfied in-commit.
Honest gap: reviewer shell denies php — targeted filter + guards four-name filter MUST be re-run on the merged tree at weld (§2 weld step 3).

Additional reviewer notes (round 1):
- [MINOR step-local] REPORT.md:62 claim "/security-review keeps its own old validation path" is false — reviewTurn is shared; sibling now runs refError's gates too; residual seam is only cmd.security-review.hint `[<ref>]` + its doc row — reword report/weld note.
- [MINOR step-local] candy-code/tests/Chat/ReviewCommandTest.php:98-141 — new temp-repo arms gained a hard git dependency without the house requireGit skip (cf. ContextMentionsTest:308); red instead of skipped on git-less runners.
- [NIT] refError maps a spent GitRunner deadline (capture BUDGET_SPENT, exitCode 124) to "not a git revision" — misnamed cause, fail-closed.
- [NIT] ReviewCommand.php:12 docblock still says `/review [ref]` vs new `[<tree-ish>]`.
- Gates NOT re-run by reviewer: php execution denied in reviewer environment; builder figures (pre-fix 6F, post 23T/149A, guards 108T/5015A) taken on trust + hand-traced. Weld must re-run `--filter 'ReviewCommandTest|ContextMentionsTest'` + four-guard filter on master.
- Everything else CLEAN: single commit 8a5693d14, %ae/%ce detain 1/1 separately, zero trailers, tree clean; Chat edit confined to [chat:reviewTurn]; no option-injection surface; no census/suite-figure/durations/dist/protocol touches.
