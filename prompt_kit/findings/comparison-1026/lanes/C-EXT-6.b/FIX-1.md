# C-EXT-6.b FIX-1 (round 1 fixer) — vacuous-pin cures, tests-only

VERDICT: both MAJORs fixed on tip `a2a483550` (follow-up commit, base picks 2bf5d2f18 + 14f326305 NOT amended; picks=3, porcelain 0, cat-file'd in the committing shell; %ae/%ce separate greps = 1/1 detain, zero Co-Authored).

## Finding intake (quoted from REVIEW-1.md)
- `VERDICT: **FINDINGS** — C=0 M=2 MINOR=0 NIT=0`.
- MAJOR-1: command session-filter absence needle `'session-beta'` "can never appear in `$text`" — filter+wiring end-to-end UNPINNED. CONFIRMED against live tree.
- MAJOR-2: straddle arm "passes VERBATIM under cut-then-redact" (3-byte `Zk9` fragment under 8-char needle). CONFIRMED against live tree.
- Nuance on MAJOR-1's sweep sentence ("removing the filter at :186 … leaves the whole suite green"): overstated — `HookLogTest::testTheSessionFilterKeepsOnlyItsOwnRows` (:530-541) is a genuine mixed-session UNIT pin and reddens under the :186 neuter (observed in my M-1 run). The genuinely unpinned half is the COMMAND wiring (`HooksHostCommand::runLog` `$context->sessionId` → tail). Cure targets exactly that, so the finding stands; no re-scope needed.

## MAJOR-1 cure — HooksLogCommandTest::testASessionFilterHidesOtherSessionsRows
- Added absence legs `'be1'`/`'be2'` (tokens that DO render, no trailing space so a truncated cell still bites); kept `'al1 '/'al2 '` presence; demoted `'session-beta'` to a labeled ANTI-ECHO leg (honest comment: proves nothing about filtering); added mirrored positive control (`/hooks log` under `session-beta` → `be1 `/`be2 ` present, `al1` absent) so the absence needles cannot be vacuous in the other direction.
- Proof (mutations via cp+md5 restore, never git checkout): M-1 = neuter the tail filter leg (`HookLog.php` :186 condition → `if ($entry === null)`, session var unreferenced).
  - Red-BEFORE (vacuity): shipped test, mutated src → `OK (1 test, 2 assertions)` — the old pin survives the fix's removal.
  - Red-AFTER: fixed test, same mutation → `Tests: 11, Failures: 1` naming exactly `testASessionFilterHidesOtherSessionsRows` (all other command legs green = mutation-specific).
  - Restore md5 `6c174c2c46cf8162f11314cd878ddf2a` verified → green.

## MAJOR-2 cure — HookLogTest::testRedactionPrecedesTheByteCut
- Added `assertStringNotContainsString(substr(LEAK_CANARY_TOKEN,0,3))` (= `Zk9`) with comment naming the physics (cut stops at `189 pad + " Bearer " + "Zk9"` = 200 B, fragment below `{16,}`); rewrote the false builder comment ("the fragment-absence pin above is the discriminating one" — head-8 is NOT order-discriminating) and kept the accurate mid-marker note. Docblock above the method was already accurate per reviewer — untouched.
- Proof: M-2 = restructure `HookLogEntry::forRun` to cut-then-redact (first-line → mb_strcut(cap) → secretFree), the exact forbidden order.
  - Red-BEFORE (vacuity): shipped tests, mutated src → whole `HookLogTest` `OK (23 tests, 97 assertions)` — suite survives deleting its own order law.
  - Red-AFTER: fixed test, same mutation → `Tests: 23, Failures: 1` naming `testRedactionPrecedesTheByteCut`.
  - Restore md5 `dd90685bb27b31c6308e575e6cbc97e5` verified → `OK (23 tests, 98 assertions)`.
- Production ordering confirmed CORRECT as shipped (redact→first-line→cut); no src change made or warranted — both findings were tests-only, matching the reviewer's fix summary.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored exact-class filters, PtyRunToolTest never matched, no full suite, no refresh-deps/composer/npm)
- Family `/(HookLogTest|HookLogConcurrentAppendTest|HooksLogCommandTest|HooksCommandTest)::/`: baseline 52T/203A → tip **52T/210A OK** (2 pre-existing Deprecation riders). Delta +7A EXACT: HookLogTest +1 (Zk9 leg), HooksLogCommandTest +6 (al2/be1/be2 + 3 control legs); +0T everywhere.
- In-step guard law: no helpers/consts renamed or added → DuplicatedTestHelperDrift not engaged (its 2 lane-tree reds are the adjudicate-only foreign pair, cured on master at e78d5dc14 which this branch predates — EXPECTED, not touched). No glob-shaped literals added (GlobDialect safe); no denial-vocabulary literals; no docs touched (no DocFigure rider); src byte-untouched in the commit (censuses cannot move).

## §7 figures for the weld (updated, tests-only; NO new *Test.php files)
- tests/Hooks/HookLogTest.php: 23T/98A
- tests/Host/Commands/HooksLogCommandTest.php: 11T/39A
- tests/Hooks/HookLogConcurrentAppendTest.php: 6T/31A (unchanged)
- HooksCommandTest 12T/42A (unchanged). durations rows unchanged (same files); figure re-pin remains GATE duty.
- gen-command-docs --check NOT re-run (test-only commit, no command surface moved).

## Files touched (commit a2a483550, exactly these two)
- candy-code/tests/Hooks/HookLogTest.php (+12/−6)
- candy-code/tests/Host/Commands/HooksLogCommandTest.php (+25/−1)

## Forbidden-set confirmation
suite-figure.json / durations.tsv / candy-code-web/dist / protocol trio / docs/SERVER.md / generated.ts / root run-state.json / escalated / junk-file / AGENT_LAW_SNIPPET.md / LEAD_LAWS.md / .opencode/* / plan_comparison.md: ZERO touched (git diff --name-only = the two test files). No git command ever run against /home/sites/sugarcraft. Never caliber refresh. Mutations cp-backed and md5-restored before staging; both src files byte-identical to pre-mutation at commit time.
