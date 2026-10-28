# C-AGT-5.h REVIEW-1 (r1) — tip ecda4ca36

VERDICT: FINDINGS (5×MINOR step-local, all guard-hardening; shipped artifact is doctrine-compliant)

## Doctrine (R13 answered shape) — PASS
- Event issue_comment/created; gate triple = `github.event.issue.pull_request` && '@candy-code' mention && author_association ∈ {OWNER,MEMBER,COLLABORATOR}, all in `if:`, compared against GitHub-computed (unforgeable) field. Matches plan §6 line "the issue-comment PR bot (association-gated)".
- Own grep: all 7 `${{` sites are in env:/outputs: (lines 54,58,59,66,86,87,88); zero inside run bodies. Job1 explicit read scopes under workflow `permissions: {}`; job2 sole `pull-requests: write`, posts only, with untrusted-Markdown WARNING banner. No checkout, no pull_request_target. plan mode + max-cost "1.00" wired to real action inputs; `result` output real (action.yml:66-69). action.yml untouched. Diff = exactly the 4 briefed files; c8e8a1f42 is master-ancestor → clean pick.
- Anti-recursion mechanism = GitHub's documented GITHUB_TOKEN comment non-trigger, stated in header + CI.md; both jobs bind secrets.GITHUB_TOKEN. (Defense-in-depth gap pinied under M-3.)
- CI.md gate excerpt matches YAML body verbatim (indent aside); README examples row truthful.

## Gates re-run (exact)
- PrCommentBotExampleTest: OK (8 tests, 47 assertions). tools/tests dir: OK (497 tests, 2627 assertions) — guard joins standalone run. candy-code --filter 'BootstrapLaunchFormatConstants|DocFigureProseDrift': Tests: 110, Assertions: 4327, OK (+2 base-era deprecations, disclosed).
- Welder carries confirmed: 0 candy-code/tests files, durations.tsv has zero tools/tests rows (class claim true), no figure carry.

## Findings (all MINOR, step-local, tools/tests/PrCommentBotExampleTest.php)
- M-1 :238 gate arm is substring-only: flipping && to || between mention and association legs keeps all six needles → green. Fix: also assert `'@candy-code')\n    && (' . 'github.event.comment.author_association'`-style connective, or pin the folded gate verbatim.
- M-2 :287 write-solitariness blind to other write spellings: adding `permissions: write-all` (or flow `{pull-requests: write}`) to job1 passes everything. Fix: assertStringNotContainsString('write-all') + count `/:\s*write$/m === 1`.
- M-3 anti-recursion premise unpinned: guard never checks GH_TOKEN == secrets.GITHUB_TOKEN in the post step; a PAT swap makes the bot self-loop (comment re-triggers, MEMBER association). Fix: pin `/GH_TOKEN:\s*\$\{\{\s*secrets\.GITHUB_TOKEN\s*\}\}/` count==2 (or assert post-step binding).
- M-4 :120 bodiesForKey misses multi-line quoted (flow) scalars: `run: "echo` + continuation line `${{ … }}` folds to an executable interpolation and evades the headline arm. Fix: if $rest opens with a quote and does not close on the line, consume until close. (Folded |/> handling is sound — raw-line contiguity of `${{` is a necessary condition for evaluation.)
- M-5 :78 example hedge: post's `if: needs.review.outputs.result != ''` relies on null-vs-'' coercion when review is SKIPPED; robust idiom is `needs.review.result == 'success' && needs.review.outputs.result != ''` (else worst case: banner-only empty comment posted on every unmatched PR comment). Docs + guard should carry the success-check.

## Non-findings
Interpolation-in-if/with/outputs is native (not shell) — correctly unpoliced. timeout-minutes on CI jobs is not an E646 blanket LLM timeout. Deviation (candy-code/vendor runner vs brief's candy-core path): accepted, identical form, matches reviewer harness.
Folded-line `${{` evasion checked and cleared for block scalars: split-across-lines also fails to evaluate runner-side.
