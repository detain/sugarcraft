# C-SES-8.a — FIX-3 (round 3, 2026-10-30)

Verdict: MINOR-1 cured (discriminating); MINOR-2 recorded as weld-carry (not touched).
Pre-fix tip: 06352783a. New fix commit: 4a1f4643d8ccdb7ffff6596d25b520030f63db5d
(cat-file-verified this object; evidence commit lands atop it as the branch tip).

## MINOR-1 (step-local, vacuous days-forwarding leg) — CURED

Finding: UsageMethodsTest.php testTheWindowIsSevenOrThirtyAndAnythingElseIsRefused seeded
only today's rows, so UsageMethods.php rollup() forwarding `null` instead of `$windowDays`
into UsageHostCommand::rollupFacts answered identically for days=7/30 — both legs stayed
green under the neuter (calls==1 either way).

Cure (tests-only, per the review's first option — behavior-level, mirroring the
InsightsHostCommandTest raw-PDO backdate idiom):
1. Seed a SECOND usage row for s1 (400 tokens / 1.50 / model 'older-model'), then
   backdate its `ts` 40 days via direct PDO UPDATE — `appendUsageRecord` stamps
   time() with no public knob. The helper pins the single-row match with
   assertTrue(execute) + assertSame(1, rowCount) (a zero-hit UPDATE would
   silently re-vacuate the legs; this pin caught the execute()-returns-bool
   shape live on the first run — first draft asserted the rowCount there).
2. NEW no-window leg BEFORE the loop: `usage.rollup` with scope session and no
   `days` must answer totals == {calls 2, totalTokens 520, costUsd 2.0} — the
   backdated row is IN the whole-ledger answer.
3. Windowed legs tightened from calls-only to full totals == {1, 120, 0.5} —
   "a whole-UTC-day window still counts today — and drops the forty-day-old row".

40 days is strictly outside both windows for any wall clock: the cutoff is
midnight-today minus (windowDays-1)*86400, so the row cannot re-enter at edge
hours; the model name keys the UPDATE deterministically.

## Mutation proof (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS, --filter UsageMethods)

- Baseline (fix committed, source pristine md5 9c07706b744b9ad8cadb15004112db71):
  OK — Tests: 9, Assertions: 54, Deprecations: 2 (pre-existing config-level family).
- M1 NEUTER  — UsageMethods.php rollupFacts call: `$windowDays` -> `null`
  (the exact neuter the finding names): FAILURES — Tests: 9, Failures: 1,
  at UsageMethodsTest.php:169 (the windowed foreach legs fold 2 != 1).
  The whole-ledger leg stays green — the red is the forwarding, not the seed.
- M2 DIRECTION-PAIR — same call -> `7` (window always forwarded, days-absent ignored):
  FAILURES — Tests: 9, Failures: 1, at UsageMethodsTest.php:165 (the no-window
  leg: whole ledger answers 1 != 2). Proves the added leg pins the null-side.
- RESTORE: cp from pre-mutation backup (never git-checkout on a dirty tree —
  lane-ab law), md5sum -c OK, re-run OK 9T/54A. Worktree after: only
  UsageMethodsTest.php modified at fix time; src byte-untouched in the commit
  (git show --stat: 1 file).

Adjacent guards: --filter 'GlobDialect|DuplicatedTestHelperDrift' OK 28T/568A
(no glob-shaped literals added — the SQL uses ? placeholders only; backdateUsage
is name-unique tree-wide, grep proven).

## MINOR-2 (generated.spec.ts table pin) — WELD-CARRY, NOT TOUCHED

Lands at weld with the protocol trio regen (schema.json / SERVER.md /
generated.ts are regen-verify-RESTORED in-lane per §1.8, never committed by
this lane). The web spec file has zero edits from this round; no vitest run
needed. WELD BRIEF DUTY: after trio-regen, pin the Usage panel table shape in
candy-code-web generated.spec.ts per REVIEW-2 MINOR-2.

## Hygiene

- Author/committer: detain greps 1 and 1 (separate formats); body trailer grep
  (co-authored|caliber) = 0. No caliber refresh, no push.
- Forbidden files untouched: durations.tsv, suite-figure.json, dist, generated
  trio. Suite-figure staleness pair is standing §7 weld duty (live tests 9,
  unchanged; assertions ride the welder's re-pin).
- rev-list master..HEAD = 3 (b95a0e181, 06352783a, 4a1f4643d) + evidence commit.
