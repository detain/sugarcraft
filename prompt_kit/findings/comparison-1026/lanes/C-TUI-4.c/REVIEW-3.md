# C-TUI-4.c REVIEW-3 (round 3, confirming reviewer) — WRITTEN IN MAIN TREE (worktree edit map refused); welder copies to the lane dir on the branch

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 2 NIT. Weld-ready.

Tip chain verified on disk: de0f3a81b (builder) → 33d93aca9 (fix) → 431ba1eff (evidence);
tree clean; fix commit = 5 files (+186/−9), whole lane = design's 7 code/test files +
REPORT.md + FIX-1.md only. No scope creep; lang/en.php untouched by the fix (builder's
value-only flip stands, r1 passed it).

## MAJOR-1 — CURED (verified, not trusted)
- `StatusLineSession::$totalCostUsd` is `?float` (StatusLineSession.php:85); the
  "WHY SPEND IS NULLABLE" paragraph names `Renderer::spendIndicator()`.
- Gather gate (HandlesRunControl.php:361): `$reported = $this->hasReportedSpend() || $turnUsage !== null;`
  — byte-identical in meaning to the bar's `$reported = $chat->hasReportedSpend() || $live !== null`
  with `$live = liveUsage()?->turnUsage` (Renderer.php:3085-3089). Exact match. The bar's extra
  `&& $spentUsd > 0` suppression is column-earning, not honesty (cap-set bars paint $0.0000),
  correctly not copied.
- Null path serializes as JSON `null` (json_encode of null; pinned both decoded and raw bytes
  with the chr(123)/chr(125) despell). A computed `0.0` leaks only for a provider that REPORTS
  usage on an unpriced model — and there `hasReportedSpend()` is true by the flag's own
  documented semantics, and the bar itself shows `$0.0000` in the same state. Not the fabricated-
  zero class r1 named; disclosed as NIT-A (wording: SETTINGS.md "billed anything" ≈ "reported").
- SETTINGS.md flips are real and in the SAME commit (33d93aca9): example `"total_cost_usd": null`
  at ~:799 + the truth sentence at ~:804. I located every generated marker myself:
  settings:layered 190-333, settings 1217-1404, settings:apply 1520-1559, settings:env-split
  1810-1854 — the edited region is OUTSIDE all of them (fixer's ":1213" cites its pre-edit base;
  tip value is 1217, net +4 lines from its own edit — benign citation drift). NOT a MAJOR.
  `gen-settings-doc --check` rc=0: fixer-reported, NOT run by me (my map denies bare php) —
  SettingsSchemaDocDriftTest green is the corroborating evidence; welder runs it at pick anyway.

## MAJOR-2 — CURED (genuine end-to-end, not tautological)
- `testTheTickDeliversTheSessionDocumentToAReaderCommand`: real Chat + EchoBackend, real
  StatusLineTickMsg, armed Cmd run through Loop to StatusLineResultMsg (20 s leash TEST-side only),
  settled, then reads `StatusLineCommand::line()` — the forked `cat`'s own stdout through the real
  settle path. It does not read its own constant. Pins 7-key top-level order, ['total_cost_usd'],
  cwd = configured root, null live spend.
- `testTheTickArmPassesTheGatherIntoTheArmedCommand`: arm slice asserts
  `StatusLineCommand::cmd($this->statusLineStdin())` == 1 and bare `cmd()` == 0 (no substring
  collision: `cmd()` is not a substring of the call with the argument); gather slice pins the gate,
  the `$reported ?` ternary and the NIT-1 `?: null`. Rename-fragile BY DESIGN (source-slice idiom):
  a rename/hoist to a variable fails LOUD with a named message, never silently green.
- `grep -rn statusLineStdin candy-code/tests/` was empty at de0f3a81b; at tip both files name it.
- tearDown calls `StatusLineCommand::reset()` every test — no static-state bleed into the broad run.

## Mutations — LINE-REASONED, not executed (I am read-only; fixer measured 4/4)
- M2 (arg deleted): legacy unwritten-EOF branch stays green (that is why r1's 58T survived); the
  behavioral pin reddens on `assertIsArray` of a blanked line, and BOTH structural legs flip their
  counts (1→0 and 0→1). Exactly the 2 new pins. Claim holds on reasoning.
- M3 (gate → `0.0`): unit fixtures pass cost explicitly, gate-blind; only the production path can
  distinguish → exactly 1 red (assertNull in the wiring pin). The structural `totalCostUsd: $reported ?`
  leg intentionally stays green (it pins gate PRESENCE, the null branch is behavioral's job).
  Claim holds on reasoning.
- M1 (float non-nullable): null args → TypeError in the two null-leg tests + production gather in
  the wiring test = 3 reds, consistent. M4: structural leg red; fixer honestly disclosed M3
  carry-over in that run.

## NIT-1 applied (gather `projectRoot() ?: null`, structurally pinned); NIT-2 untouched per ruling.

## r1 PASSED-list re-check on the fix diff
Zero `sleep|usleep|stream_select|Timer|proc_open|fopen|file_get_contents` in added src lines
(counted 0 on the fix commit's src hunks); deadline line untouched (StatusLineCommand.php not in
the fix diff at all); no new sinks → still no child-lifetime/descriptor/ReadPathCensus row owed.

## Gates run (cwd candy-code, env -u LINES -u COLUMNS, plain pipe; PtyRun never matched)
- `--filter 'StatusLineSession|StatusLineCommand'` → **Tests: 61, Assertions: 194** — fixer's figure EXACT.
- `StatusLineSessionTest.php` alone → **9 tests, 33 assertions**; `StatusLineCommandTest.php` alone
  → **50 tests, 149 assertions** (fixer's table EXACT; 42 declared methods, providers expand to 50 —
  durations wants the 50/9 case counts).
- `--filter StatusLine` broad → **101T/4385A** — fixer's figure EXACT.
- `--filter 'SettingsSchemaDocDrift|EnvRosterDrift|GlobDialect|ReadmeRosterDrift'` →
  **80T/40,914A** — byte-identical to r1 AND fixer. 2 base-tree deprecations ride every window (r1-documented).

## NITs (non-blocking)
- NIT-B (step-local, cosmetic): StatusLineCommandTest.php:923-928 — the pre-existing
  "The command runs in the directory it was configured with…" docblock was orphaned: the fix
  inserted its new docblock+method BETWEEN that docblock and its own
  `testTheConfiguredWorkingDirectoryIsWhereTheCommandRuns` (now doc-less, double `*/`→`/**` adjacency).
  Cure: move the wiring method+docblock below the cwd test. Zero behavioral/guard impact.
- NIT-A: doc wording "before a provider has billed anything" vs the flag's "reported" semantics
  (see MAJOR-1). One-word flip candidate for the welder, not required.

## §4 WELD CARRIES (confirmed from this tree)
- NEW durations row: `candy-code/tests/Config/StatusLineSessionTest.php` — **9** test cases.
- RE-TIME `scripts/parallel-tests-durations.tsv:43` `candy-code/tests/Config/StatusLineCommandTest.php`
  (`6.432` / 41 → **50** cases; spawn-heavy, expect a raise).
- Whole-lane figure delta: **+18T/+104A** (builder +15T/+85A ⊕ fix +3T/+19A; my 61T/194A vs r1's
  58T/175A on the identical filter re-proves the fix delta exactly). Supersedes the earlier +15T/+85A.
- No roster rows owed (child-lifetimes / descriptor / ReadPathCensus / StderrEmitter / env / help).
  trio + dist untouched. SETTINGS.md is hand-region only → cherry-pick text-merges beside
  C-SEC-7.a / C-CTX-4.e regens. HandlesRunControl carries the [hot:] serialization stamp.
