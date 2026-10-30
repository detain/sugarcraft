# C-SES-7.d FIX-1 — resume-cost guard inert without an engine backend

## What (review round 1, CRITICAL cure)
1. **src/Chat.php `resumeCostHold()`** — early-return null unless
   `$this->backend instanceof Backend\EngineBackend`. Before: non-engine
   chats passed `$costPer1k = null` into the estimator, whose unpriced branch
   fires on ANY cold resume, and plain SessionStore answers lastActivity=null
   (cold) — so every test Chat in the derived-armed shape (non-empty history,
   real currentSessionId, no sessionMint) held its first Enter. Base's 18-file
   Chat cluster: 243T/1610A/F:1 (foreign) → branch was F:11+E:1 (11 casualties
   incl. testTheDispatchedParkedTurnSavesACheckpoint). The unpriced branch now
   keeps its real audience: engine-backed chats whose model has no rate on
   file (production --resume onto a custom/unknown model). Docblock updated.
2. **MINOR folded (in-region, cheap):** ctor param `?bool $resumeCostGuardArmed`
   moved from mid-list (after $sessionMint) to the constructor parameter-list
   TAIL — positional shape of every earlier parameter structurally untouched
   (mutate() spreads named args; verified).
3. **NIT taken:** /branch-armed sentence added to that docblock — the
   SwitchSession effect route names a fresh id + clears sessionMint without
   naming the flag, so mutate()'s session-identity choke re-derives armed for
   the fork's copied transcript.
4. **NIT skipped (priced-notice e2e pin):** the price DB
   (model_prices_and_context_window.json) is network-cached, absent on this
   host — costPer1kTokens() answers NULL for every id here (measured), so a
   hermetic priced e2e pin is impossible at the Chat level; the priced `≈`
   shape is already pinned in ResumeCostEstimateTest with an injected price.

## Re-pin: tests/Chat/ResumeCostGuardTest.php
- `resumedChat()` helper → `new EngineBackend(new EchoProvider(), 'echo')`
  (engine-backed, model `echo` has no rate on file → unpriced `≥` shape,
  notice assertions unchanged); docblock + comments truth-flipped.
- `testCommandsStayReachableWhileTheHoldStands` → EngineBackend (its tail
  expects the guard to actually fire on the real prompt).
- NEW `testANonEngineChatNeverHolds` — the exact casualty shape (heavy
  history, real id, plain store = cold+unpriceable) on EchoBackend: armed,
  first Enter SENDS, no notice row. This is the KEEP-pin the cure demanded.
- Fresh-launch and picker-arm tests stay EchoBackend (they pin arm-derivation,
  which is backend-independent).

## Gate results (cwd=candy-code, env -u LINES -u COLUMNS -c phpunit.xml, plain pipe)
- ResumeCostEstimateTest|ResumeCostGuardTest: **21T/71A OK** (was 20T/65A;
  +1T = inertness pin, +6A).
- 6-file casualty+guard cluster: **97T/685A/F:1** — the F is the foreign
  base-red AutomaticCompactionModelSummaryTest::testClearingTheTranscriptUnarmsTheBreaker
  (base shape restored; 11 casualties all green).
- Full tests/Chat + tests/Host superset: 1931T/F:7; the 6 non-foreign reds
  (ApplySettings x2, ChatHostLangCatalogue, ColdResumeFinishedAgent,
  PaletteSupersetDispatch, RouteTraitParity) are IDENTICAL pre-fix/post-fix
  (git-stash A/B: 33T/475A/F:6 both ways) and name nothing resume-cost —
  weld-era roster/lang drift, not this step's; the 7th is the foreign F above.
- Adjacent guards: MutateCarryCensus+SessionLazyMint+UiOnlyHistory+4 casualty
  files: 69T/334A OK.
- Mutations (cp/md5-restored): M1 gate→pre-fix ternary → exactly
  testANonEngineChatNeverHolds red; M2 estimator TTL-arm neuter → exactly
  testAWarmResumeSendsStraightOut red (EngineBackend-based, now non-vacuous).

## Files
candy-code/src/Chat.php, candy-code/tests/Chat/ResumeCostGuardTest.php.
Not touched: durations.tsv, suite-figure.json (weld carry).
