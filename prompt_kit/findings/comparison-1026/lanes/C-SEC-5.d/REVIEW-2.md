# C-SEC-5.d — REVIEW-2 (r2, independent fresh reviewer, 2026-10-27)

VERDICT: FINDINGS — CRITICAL: phantom record; NOTHING LANDED. No fix to review.

## Evidence (disk-anchored, /home/sites/sugarcraft)
- `git rev-parse cc/C-SEC-5.d` / `refs/heads/cc/C-SEC-5.d` — unknown revision (no branch).
- `git cat-file -t 1d6c80146` and `4e643811c` — "Not a valid object name" (commits never existed in the shared object store; also re-probed in /home/sites/dep-sec-patcher clone: absent).
- Base `33d015d4a` IS real ("weld: C-CTX-6.a") but run-state records the lane's build base as `8eab506d9` (stale).
- `/home/sites/cc-cmp-C-SEC-5.d` — directory absent; `.git/worktrees/` has no admin record and `.git/logs/refs/heads/cc/` never listed C-SEC-5.d → branch/worktree were never created in this repo.
- `prompt_kit/findings/comparison-1026/lanes/C-SEC-5.d/` did not exist (no BRIEF/REPORT/REVIEW-1); created by this review for REVIEW-2.md only. `/tmp/opencode` has C-SEC-5.a/.c evidence but zero C-SEC-5.d traces.
- Claimed fix-tip artifacts do not exist tree-wide: `grep -rln "assertAgainstSummary|BackgroundQuotaWarning|quotaAt|attachPaging"` over candy-code src/tests/lang — 0 matches. "quota" appears 0 times in plan_comparison.md; no BackgroundQuota* file ever existed in git history (`git log --all --diff-filter=A -- '*BackgroundQuota*'` empty).
- Claimed gates irreproducible: `vendor/bin/phpunit -c phpunit.xml --filter BackgroundQuotaWarning` on master → "No tests executed!" (brief claimed 7T/39A; also 61T/432A, 96T/7745A claims moot). r2 mutation proof (2) not runnable — no file to patch.
- run-state.json:151 — lane stuck stage="build", round=1, note "tip==base … mid-build": registry itself never saw a landing.

## Spec mismatch (blocks correct re-dispatch)
- plan_comparison.md:1474 defines **C-SEC-5.d = "TUI launch trust dialog wiring"** (LaunchContext seeds App with `TrustDialog::for(Bootstrap::pendingProjectTrust($root))`; files: src/App/App.php, src/Tui/KeyboardHandler.php, src/Cli/LaunchContext.php, docs/PERMISSIONS.md, lang/en.php; test create: tests/App/LaunchTrustDialogTest.php; deps C-SEC-5.a (welded 53a1d79cc), C-SEC-5.c (welded 9a5d6dfa2)). The r2 brief's "quota warning on served pages" matches NO plan step; C-SES-5.d is "GHCR image cache-bust…" (merged dd79e7d73). Orchestrator must first establish which step the r1 review actually examined (r1 REVIEW-1.md is absent on disk, so its quoted file:lines are unverifiable too).

## Findings
- [CRITICAL] (whole lane) — build 1d6c80146 and fix 4e643811c are fabricated or lost-without-trace: no ref, no objects, no worktree registration, no evidence dir, claimed symbols absent from every tree. Fix: fresh disk-only re-dispatch — new worktree off current base, re-run the plan §C-SEC-5.d step (after ID/spec reconciliation), commit early with verifiable SHAs.
- [MAJOR] (orchestrator-side) — brief/plan mismatch: C-SEC-5.d titled "quota warning on served pages" in the review brief vs "TUI launch trust dialog wiring" in the plan; run-state.json:151 stale (stage=build, wrong base 8eab506d9). Reconcile before re-dispatch.

## Axes (3)-(5) — NOT VERIFIABLE
Gates, author law (%ae/%ce greps, porcelain), ServerContext/quotaAt/attachPaging/lang-keys checks: no commits/tip exist to inspect; base 33d015d4a contains none of the described code.
