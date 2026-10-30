# C-CTX-5.d FIX-1 — fresh fixer (phantom predecessor replaced)

VERDICT: all findings cured and committed. Landed SHA **7179a0f587c849cf232fd4c48dd531ef598e54b3**
(`git cat-file -t` → commit; parent = eb5108c9c, the claimed lane tip; author+committer detain@interserver.net
verified by separate %ae/%ce greps; zero Co-Authored-By; exactly 4 files via git show --name-only).
The predecessor's 1d86c3a6e remains a phantom (cat-file FAILS) — this commit supersedes that record.

## Cures (file:line at 7179a0f58)

1. **MAJOR-1 (r2): spend cap binds the keep-warm tick.**
   `candy-code/src/Chat/Handlers/HandlesRunControl.php:501` — inside `handleKeepWarmTick()`, after the
   watch door and before the session/anchor gates: `if ($this->spendCapReached()) { return [$this, null]; }`
   with a WHY comment. The served twin needs no gate: `SessionHost::onIdleAutoCompaction` already refuses
   past cap before keep-warm is consulted (SessionHost.php:1001 — verified). This is exactly REVIEW-2's
   required cure shape, gated BEFORE alarm/ping so no ping is armed and none sent.
2. **MAJOR-2: provider-key mismatch — PREMISE REFUTED, real defect cured.**
   `buildPromptCacheWireHistory` has ZERO matches in candy-code (`grep -rn` rc=1; verified twice, incl.
   the committed blobs). The live twin-derivation defect was the idle anchor: `handleKeepWarmTick` passed
   `idleAnchorTs: $this->lastActivityAt?->getTimestamp()` to `shouldPing` while `sendKeepWarmPing`
   RE-COMPUTED `$anchorTs` with a `?? \time()` fallback — verdict and latch could count different stretches.
   Cure: ONE anchor producer per tick, threaded into both. `HandlesRunControl.php:531` (hoist + null
   early-return) and `:591` (`sendKeepWarmPing(..., int $anchorTs)` — dead fallback deleted). Served twin
   same shape: `candy-code/src/Host/SessionHost.php:1097` (hoist before `shouldPing`, second derivation
   before `noteWarmPing` deleted). Behavior-identical on every reachable path (the `??`-leg was
   unreachable: shouldPing already gates a null anchor to false) — the defect class, not an incident,
   is what dies here.
3. **MAJOR-1 (r1): warm-registry bleed.** The session-keyed registry claim VERIFIED genuine on the
   eb5108c9c blob (every CacheHealthWatch accessor takes a key; $warm is per-session). Per REVIEW-1's
   accepted deviation, the cure is the missing PIN + docblock truth, not re-keying.
   `candy-code/src/Backend/CacheHealthWatch.php:277-` docblock now states the partition is the BUDGET,
   not merely the display. Bleed test added (below) covering the budget level REVIEW-1's NIT-1 said was
   unpinned (existing pin covered tallies only).
4. **Docs.** `lang/en.php:3883` "spend cap still binds" prose is now TRUE by construction — no doc flip
   needed or made; DocFigureProseDrift green in-step.

## New tests (candy-code/tests/Host/KeepWarmTest.php, 14T→17T)

- `:320 testTheSpendCapBindsTheTuiTickNotJustTheServedAlarm` — drives the REAL handler: fresh Chat via
  `tuiChat()` helper (:298) with pre-billed TokenTracker (0.20 spent, cap 0.10), history below the fold
  floor, keepWarm 2. `update(new IdleCompactTickMsg())` → same instance, NULL cmd, 0 warm pings globally,
  latch not armed, 0 stretch pings for the anchor. (Not a helper walk — REVIEW-2 demanded the real tick.)
- `:336 testATickUnderTheCapBuysThePingAndBillsItsLanding` — cap clear: cmd non-null, `warmPingInFlight('s')`
  true, `warmStretchPings('s', $stamp->getTimestamp())===1` (single-anchor threading pin), invoking the
  cmd runs the factory, and `update(new KeepWarmPingedMsg('s', Usage::new(100, 0.0004)))` lands the billed
  hit (warmPings('s')===1, latch down, spentUsd ≥ 0.0004). Absorbs REVIEW-1 MINOR-1 (production
  handler→ping→landing chain).
- `:365 testASpentSessionSpendsNoOtherSessionsBudget` — BLEED test: session A earns its one cap-1 ping
  then refuses ("the cap, not the clock"); session B with the SAME anchor/config still earns
  ("a spent session warms no OTHER session's cap shut"); A's in-flight latch never blocks C; totals
  per key independent. Helpers gained `string $key` params (default 's' keeps all 14 legs byte-stable).

## Mutation proofs (all cp+md5 restore-clean; backups /tmp/opencode/cc-cmp-C-CTX-5.d/mut-backup/)

| # | Mutation | Result |
|---|----------|--------|
| M1 | neuter cap gate (`if (false && $this->spendCapReached())`) | exactly 1F = testTheSpendCapBindsTheTuiTick (isolated re-run confirmed identity) |
| M2 | re-derive anchor at latch (`noteWarmPing($key, \time())`) | exactly 1F = stretch-threading assertion in testATickUnderTheCap |
| M3 | leak warmStretchPings across keys for same anchor | exactly 1F = bleed test (REVIEW-1's "green under removed guard" weakness flipped red) |

## Gates (cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS, plain pipe)

- KeepWarmTest alone: **OK 17T/68A**
- Union idle-cache family (KeepWarmTest + CacheHealthWatchTest + IdleAutoCompactionTest + CacheTtlIdleFoldTest): **OK 71T/289A**
- DuplicatedTestHelperDriftTest + DocFigureProseDriftTest: **OK 106T/4380A**
- `--filter 'DuplicatedDocBlockLine|SymbolCitationDrift'`: 13T/18420A **OK** with 2 PHPUnit runner
  deprecations — disclosed observation: they surface from guard files this lane never touched
  (commit touches exactly the 4 paths above), i.e. pre-existing at base; not chased further.

## Deviations / duties for weld+closeout

- No new test FILES → no DUR mint owed; the KeepWarmTest durations.tsv row re-time (14T→17T) is
  WELD duty (lane-forbidden file).
- suite-figure.json untouched: +3T live-enum drift is the §7 closeout re-pin pair, per law.
- REVIEW-1's deviation (b) (static session-keyed registry) stands — cure is pin+docblock as adjudicated,
  not instance-ization.
- Item 3 as literally briefed (buildPromptCacheWireHistory key mismatch) does not exist in the tree;
  the cure above addresses the real same-family defect (anchor double-derivation). If the reviewer's
  concern was wire-key alignment between fold-time and ping-time, note ping-vs-fold identity rides
  KeepWarmPolicy/shouldPing on the SAME history+backend per fire, already pinned green in-file.
