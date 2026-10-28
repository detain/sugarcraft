# REVIEW-1 — C-TOOL-7.e (bounded resample of all-malformed tool-call replies)

Reviewer: fresh r1, read-only. Worktree /home/sites/cc-cmp-C-TOOL-7.e, branch cc/C-TOOL-7.e, single commit 153675eb9 off b985fbe51.

## VERDICT: CLEAN (0 critical / 0 major / 1 minor step-local / 2 nit)

## 1. Traceability and scope
Diff = exactly 3 files, numstat 498/15/229 + 176 deletions — matches claims. Runtime.php is ONE-lane per §2. `tests/Cli/StderrEmitterCensusTest.php` is not in the block's files list, but §1.8 in-step guard law mandates it: the lane adds exactly two `RuntimeNoticeSink::warn()` call sites, so the channel-6 roster moves in the same commit. Legally required, not creep. Builder summary verified line-by-line against the diff; no phantom claims.

## 2. Completeness
No stubs/TODOs. Const (MAX=2, NOTICE_FORMAT), helper replyIsAllMalformed(), both arms (runBatch + runStreaming), per-discard notice, usage summation legs, docblock truth paragraphs — all present as claimed.

## 3. Correctness (hand-traced)
- **E646:** count bound only — the outer `for(;;)` breaks at `$malformedResamples >= 2`. Zero new timers: every `TransientFailure::backoff(...)` line in the diff is moved (identical -/+ re-indent) context, not new. No wall-clock/blanket LLM timeout anywhere.
- **Door:** fires iff ≥1 call AND every call carries argumentsError() AND no user-visible text. Streaming: `$buffer !== ''` blocks (buffer accumulates every content chunk — superset of onToken emission), `$carried !== null` blocks forever-after a dropped-attempt continuation (correct: carried text is append-only, can't be un-emitted). Batch: `$response->content !== ''` blocks. Partial-malformed-with-any-runnable-call → helper false → today's A11-mixed path preserved (gate comment says so, flipped A11 test pins the stands case). Empty reply → helper false → no resample. Error replies throw BEFORE the gate (both arms) — an error reply is never discarded. Reasoning-only+all-malformed resamples: consistent with the pre-existing $emitted exemption of reasoning.
- **"SAME request":** $request is reassigned only inside $resume/$refusedPrefill, both of which set/require $carried !== null — and $carried!==null permanently blocks the door. A discard therefore always re-issues $baseRequest. Structurally proven.
- **Billing:** runStreaming `Usage::sum([...$discardedUsages, ...$usages])` — empty-spread byte-identical to the old `Usage::sum($usages)`. runBatch ternary keeps the single-fold path exact, sums on discards. Usage::sum (src/Usage.php:495) skips nulls — safe for the `list<?Usage>` carrier. Discards are appended at the discard site, before re-issue. (Residual: usage of a discarded reply followed by a later sequence THROW is unbilled — mirrors the pre-existing throw-discards-$usages shape; nit, not a lane regression.)

## 4. Guards (in-step)
StderrEmitterCensusTest: roster `'src/Runtime.php' => 2` with routing adjudication (seam, Retry-After precedent) — matches the 2 real call sites (:1924, :2065; the :286 docblock mention is excluded by the token scan). Cardinality prose FORTY-ONE→FORTY-THREE, SEVENTEEN→EIGHTEEN consistent (+2 sites from one new file). testTheRuntimeNoticeSeamRosterIsUnchanged (:1000) compares the roster to the derived census — exercised green at tip.

## 5. Test honesty
A11 flip: all 11 original assertions preserved verbatim (toolResults count/id/isError/content×2/executions-empty/events pair×4), plus calls==1+MAX and both notice lines; `willReturn`→`willReturnCallback` is a mechanical fresh-Response-per-read necessity, not a weakening. stubProvider's last-row-repeat makes runaway loops fail on the counter, never hang. No glob-shaped, no denial-shaped literals (checked: 'Sure, reading it now. ', A11_ERROR, TRUNCATED arms).

## 6. Gates run at tip (cwd worktree candy-code, plain pipe, -u LINES -u COLUMNS)
- `--filter RuntimeMalformedToolArgumentsTest` → **OK, Tests: 7, Assertions: 49, PHPUnit Deprecations: 2** (5.2s; matches REPORT exactly).
- `--filter 'Retry|TransientFailure|UsageFold|LengthStop'` → **OK, Tests: 260, Assertions: 768, Deprecations: 2** (30.7s).
- `--filter StderrEmitterCensusTest` → **Tests: 95, Assertions: 20687, Failures: 3** — failures are exactly the 3 foreign base-inherited shapes (NonInteractive fwrite 14→15; shapes ArgvParser 35→36 + NonInteractive 10→11; derived inherited arm). NO Runtime-row red: the lane's census flip is correct. Matches REPORT's base-inherited disclosure.

## 7. Laws
Commit metadata: Joe Huss|detain@interserver.net for BOTH %ae and %ce (separate fields, verified); zero Co-Authored/Signed-off trailers; single cohesive commit. suite-figure.json / durations.tsv / dist untouched (3-file diff proves it). No NEW test file (+4 methods ride existing files; census file predates lane) — REPORT lists none; no DUR row owed. E646 honored. One type per file unchanged.

## 8. Re-verification limits (harness, not lane)
My reviewer permission map refuses cp/mkdir/redirect — the /tmp cp-swap red-before and the 2 mutation re-runs could NOT be executed on disk (consecutive rejections documented in-session). Compensated by static derivation, both internally consistent with the builder's claims: base Runtime.php has no outer loop, so the provider is called once → the 1-vs-2 / 1-vs-3 / 10-vs-25 pins cannot pass (red-before follows from the diff shape); MAX→0 zeroes all resampling → exactly the four bound/notice/usage pins red (2 notices + calls + A11-flip + usage legs); text-door neuter → only testAReplyWithStreamedTextIsNotResampled's fixture is text-bearing-all-malformed → exactly 1 red. Builder's md5 restore claims (9093bf91…) not independently re-runnable here.

## Findings
- MINOR (step-local): discarded-reply usage is lost if a LATER sequence in the same method throws (errorChunk/rethrow paths never see $discardedUsages) — a pre-existing shape (dropped-attempt $usages already dies on throw) so arguably out of remedy; consider a one-line docblock note at the throw sites or fold-into-exception later. Not weld-blocking.
- NIT: two PHPUnit @param-style deprecations in the touched test file (pre-existing family, REPORT disclosed).
- NIT: runStreaming docblock paragraph uses ASCII hyphen "- no timer" where the file's prose family uses em-dashes; cosmetic.

Recommendation: WELD. Closeout carries: +4 tests in the suite-figure re-pin; the 3 StderrEmitterCensus foreign reds + TreeWideGuardRoster 2F remain someone's roster debt (correctly NOT fixed here — foreign files, collision-hazardous).
