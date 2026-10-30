# C-SES-8.a — REVIEW-1 (round 1, fresh re-anchor)

VERDICT: FINDINGS — 1 MAJOR (honest-zero: step entirely unimplemented). No fabrication found this round.

## Re-anchor (disk, individual rev-parses, 2026-10-29)
- worktree /home/sites/cc-cmp-C-SES-8.a, branch `cc/C-SES-8.a` = `574e69f1d89415b37124062260e438a0942ac533` == master (`574e69f1d`).
- `git log --oneline master..cc/C-SES-8.a` → EMPTY. `git diff master...HEAD --stat` → EMPTY. `git status --short --untracked-files=all` → clean.
- Lane dir prompt_kit/findings/comparison-1026/lanes/C-SES-8.a/ contained NO files (no BRIEF.md, no REPORT.md) before this review.
- Prior-generation check: master's batch-18r ledger (574e69f1d subject) records pruning the old cc/C-SES-8.a dead-loop branch "was d83e4eed4"; verified `git cat-file -t d83e4eed4` = commit and `git merge-base --is-ancestor d83e4eed4 master` = true, i.e. the prune destroyed ZERO unique work. Honest.

## Findings
- **MAJOR — entire declared deliverable absent (step NOT-FIXED, gap open).** plan_comparison.md §4 `#### C-SES-8.a` (correctly un-✅'d) + design-ses.md:812 declare 4 creates + 6 modifies. All absent on both master and the lane branch:
  - `candy-code/src/Protocol/Methods/UsageMethods.php` — ABSENT
  - `candy-code/tests/Protocol/UsageMethodsTest.php` — ABSENT (targeted gate: `vendor/bin/phpunit -c phpunit.xml --filter UsageMethods` → "No tests executed!")
  - `candy-code-web/src-web/components/panels/UsagePanel.vue` + `__tests__/usagePanel.spec.ts` — ABSENT
  - `usage.rollup` / `UsageMethods` / `UsagePanel` grep across candy-code src+docs+tests and candy-code-web/src-web: **0 hits** (Dispatcher/MethodSchemas/schema.json/SERVER.md/generated.ts/panels.ts/SidePanels.vue all unregistered/unmounted).
  - Cure: re-dispatch the lane fresh from base 574e69f1d per design-ses.md §C-SES-8.a. The C-SES-9.a dep is satisfied — `UsageHostCommand::rollupFacts()` lives at candy-code/src/Host/Commands/UsageHostCommand.php:82 and is already consumed by landed sibling C-SES-8.c (InsightsHostCommand.php:72), so nothing blocks the build. Remember lane-close duties: protocol regen chain (schema.json → SERVER.md → generated.ts), `generated.spec.ts` scope pin, new test file needs a scripts/parallel-tests-durations.tsv row, and suite-figure staleness pair re-pin is weld/closeout-owned.

## Categories cleared
- (a) files == declared set: FAIL as above — lane diff empty, no out-of-set edits either.
- (b) law end-to-end/reachable: N/A-fail — no code, nothing to trace (captured in the MAJOR).
- (c) pin vacuity: no pins exist; nothing vacuous, nothing load-bearing.
- (d) E646: N/A (no LLM call sites added).
- (e) §1.8 doc/roster/census in-steps: N/A — protocol trio and COMMANDS/SERVER docs byte-untouched, matching zero code (no half-landed drift; guard law not tripped).
- (f) gates: step's targeted filter runs zero tests (quoted above); master Protocol baseline not perturbed by this lane (empty diff).
- (g) authors: N/A — zero commits to inspect.
- (h) forbidden files: suite-figure.json, parallel-tests-durations.tsv, candy-code-web/dist all untouched (empty diff).
- (i) staleness pair (25,537-pinned vs ~28.4k live): standing red, NOT charged to this lane.
- (j) trust/SSRF/path-safety: no surfaces touched.
