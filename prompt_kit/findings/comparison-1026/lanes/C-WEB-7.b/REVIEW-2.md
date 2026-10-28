# REVIEW r2 — C-WEB-7.b (cc/C-WEB-7.b @ f9d81da62, rebased onto master 4b771634d) — reviewer: fresh, read-only

VERDICT: CLEAN (0C/0M/0 step-local MINOR; 1 cosmetic NIT)

## Rebase + adaptation correctness
- `git diff 06364f419 f9d81da62` restricted to the 9 lane files shows ONLY the questions.ts trust block: `pendingTrust` → `pendingTrusts` watch, per-row `status==='asked'` loop, `announcedTrustAskIds: Set<string>` latch. Sentence template byte-unchanged (verified in the cross-tip diff). Docblock truth-flipped to the plural shelf; commit message names C-SEC-5.b. The other 8 files (debounce, cross-tab clear, palette action, README, all specs) are byte-identical to the round-1 CLEAN content, and master drifted ZERO between 581ac6ede and 4b771634d on the 9 files (`git diff 581ac6ede 4b771634d -- <9>` empty) — round-1 verification carries.
- Store contract read at tip (contextSources.ts:52/149/161-168, read-only, unmodified as briefed): first door and second door both `[...rows, row]` fresh arrays → watch fires → each asked row announced once; settled-status re-delivery `.slice()` fresh array → watch fires → Set-continue suppresses; late-asked-after-settle returns SAME array (ref no-notify). Single-prompt row behaves exactly as the round-1 singular watch.
- Re-delivery pin is NON-VACUOUS: merge of an already-asked row allocates a new array, so only the Set latch keeps it quiet — latch removal reddens `toHaveLength(1)`. Second-door leg traced correct but UNPINNED (brief only demanded "announced once") → NIT, optional weld/closeout strengthening.
- Planted-old-watch discrimination re-reasoned (no edits): `context.pendingTrust` is undefined on the master surface → watcher never fires → trust test's first assertion fails; approvals-repeat, composer and palette specs never touch the trust path → exactly 1F, matching FIX-1's ran proof and the welder's recorded 1F/5P failure verbatim.

## Scope/ceremony
- `git diff master...HEAD --name-only` == the 9 declared files; zero dist, zero figure/durations files, zero other paths. Single commit; %ae==%ce==detain@interserver.net (separate greps); 0 Co-Authored/Signed-off/Change-Id. HEAD~1 == master tip == 4b771634d.

## Gates (reviewer-run, serial --no-file-parallelism, main-tree node_modules)
- vitest 4 touched specs: Test Files 4 passed, Tests 28 passed (28/28, 0 failed) — matches FIX-1 and the "5th/36 did not materialize" reconciliation in SKIP-CONFLICT.
- `npm run typecheck` on branch: 19 errors — contextPanel.spec x8, contextSources x4, statusBar.spec x2, reducer.spec x4, contextTrustRouting x1; ZERO in lane files. Same command on the main tree AT master (clean candy-code-web): identical 19, same file breakdown → branch adds nothing.
- `git status --porcelain` in the lane worktree after gates: empty.

## Laws
E646/ProcessContainment/one-type/Lang N/A (client TS); fixture literals dodge glob- and Refused-shapes (unchanged from r1); no generated surfaces touched; §6 carries no ruling for this lane, and the design's own risk line ("if C-SEC-4.b names its field something other than pendingTrust, read that name") is exactly what the re-target exercised.

## NIT (cosmetic)
1. Trust-test docblock phrase "the open trust prompt" (singular) vs the now-listed shelf; and a second-door two-row announce pin would close the only traced-but-unasserted leg. Both weld-may-cure-or-record; no action required.
