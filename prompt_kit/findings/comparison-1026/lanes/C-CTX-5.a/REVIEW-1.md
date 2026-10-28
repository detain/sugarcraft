# C-CTX-5.a review r1 — VERDICT: FINDINGS (1 MAJOR / 3 MINOR / 1 NIT)

Lane cc/C-CTX-5.a @ 2f867a969 (parent ca69cb333). Scope: 5 files, exactly the brief's
set (2 src modify, 2 tests create, docs/CONTEXT.md). Author detain 1/1, no trailers.

## Gate re-derivation (tip, plain pipe, cwd=candy-code)
- `--filter 'CacheReusingSummaryBackendTest|CompactionFallbackParityTest'` →
  **Tests: 14, Assertions: 102** OK (7+7 as claimed). "PHPUnit Deprecations: 2" is
  ambient repo-wide (an unrelated existing class shows the same 2).
- Builder's claimed family **151T/579A** and doc-guards **102T/4137A** are NOT
  reproducible: no filter string is recorded anywhere (REPORT.md missing — see NIT).
  Nearest supersets: `CacheReusing|Compaction|Compact|Summary` = 775T/5435A with 1 red;
  `Drift|Roster|DocFigure|Census|Readme` = 1339T/112593A with 9 red;
  `DocFigureProse|ReadmeRoster|EnvRoster|TrustKey|ConfigWriteProducer|KeyBinding|GlobDialect`
  = **403T/45869A green**.
- ALL 9 reds are INHERITED, not lane-caused: main-tree master 35bf072e7 (which does not
  contain this diff) is red on the same list, incl.
  `AutomaticCompactionModelSummaryTest::testClearingTheTranscriptUnarmsTheBreaker`
  ("/clear starts no turn", size 2 vs 1, :1591) — red there too, isolated.
  ReadmeSuiteFigureDrift staleness ×2 is by-design (§1.5) — welder re-pins.

## MAJOR
1. `candy-code/tests/Backend/CacheReusingSummaryBackendTest.php:110` + `:123` — the two
   no-retry polarity tests are VACUOUS for the fallback guard. Both drive
   `RejectingEngine` (`:322`), which is `SummarisesWithCache` but NOT an `EngineBackend`;
   `retrySummaryOnce()`'s second condition is
   `$this->engine instanceof EngineBackend && $this->engine->summaryModel() !== null &&
   !TransientFailure::isTransient($error)`, so the `instanceof` clause alone blocks the
   retry in those fixtures. Mutation-reason: delete `!TransientFailure::isTransient($error)`
   → suite green (429 still not retried, by the wrong reason). Delete
   `summaryModel() !== null` → suite green (an engine already on the session model would
   re-send byte-identical bytes and burn the one billed call). Tree-wide nothing else
   pins either clause: `tests/Chat/CacheReusingSummaryRouteTest.php` contains no
   transient/overflow wording, and the real-engine test at `:138` sets an override and a
   NON-transient refusal, so it pins only the positive leg. The design's test row demands
   "transient errors not retried", and the cap law's premise is that the retry only
   answers failures the session model can fix.
   FIX: add two real-`EngineBackend` + `ScriptedProvider` cases in the pattern of `:138-160`
   (provider counts are the ground truth): (a) `withSummaryModel('small')` and a refusal
   carrying `getStatusCode(): 429` → assert exactly ONE provider call;
   (b) no override (bare `EngineBackend::new($provider,'m')`), non-transient refusal →
   assert exactly ONE provider call. Both must be red if their clause is deleted.

## MINOR
2. ruling-carry — DEVIATION #2 judged ACCEPTABLE scope-honest seam, quote of the design
   block (`design-prv-ctx.md:1066`): "Fix, inside
   `CacheReusingSummaryBackend::summariseAsync()`, which serves both hosts with no Chat or
   SessionHost edit". The premise is refuted by live code: `src/Host/SessionHost.php:754`
   hands `buildSummarizationRequest()` the raw `$this->backend()`, and
   `EngineBackend` itself `implements … SummarisesWithCache` (`src/Backend/EngineBackend.php:76`),
   so `CompactionService.php:1227` answers on the engine and the wrapper never sees the
   served call; `CompactionService.php:1221`'s `flushMemory` arm likewise unwraps via
   `$backend->engine()`. The lane could not fix that in-§2 (SessionHost is serialized-hot
   for C-CTX-5.b/c/d and outside its file set), and the parity double honestly models the
   production shape (`CompactionFallbackParityTest.php:107-111`), so the served pin is NOT
   vacuous — it asserts calls==1 and the fold landing on the route production actually uses.
   CARRY for the welder/owner: served ahead-of-need + flushMemory halves ride a one-line
   `summaryBackend:` change in C-CTX-5.b's SessionHost edit.
3. step-local — `docs/CONTEXT.md:175-190` promises the retry to sessions at large with no
   scope sentence (finding 2 makes the served half untrue today, and the flushMemory half
   on the TUI too). Add one sentence naming the pair-only scope.
4. step-local — the overflow leg retries unconditionally: when `clipOlderToolOutputs()`
   returns the input untouched (`CompactionService.php` guard `$cut <= 0`, and every case
   where no older tool row exceeds head+tail), the "rescue" re-sends byte-identical bytes
   and spends the one billed call on a guaranteed re-refusal. Fix: in `retrySummaryOnce()`
   compare `$clipped === $history` and rethrow instead of re-asking.

## NIT
5. `prompt_kit/findings/comparison-1026/lanes/C-CTX-5.a/REPORT.md` does not exist (§1.6/§1.12
   evidence duty; the spawn's deviation ledger cites it). Land it with the fix round, incl.
   the two gate filter strings so the figures are re-derivable.

## Clean categories
- Cap law (task 1): handler is attached to the FIRST rejection only
  (`->then(null, …)` on the engine promise; `retrySummaryOnce()` returns the retry promise
  unwrapped) — `testTheOverflowRetryIsCappedAtOneExtraCall` counts 2 and asserts the retry's
  failure reaches the fold unchanged; a re-attached handler reads 3 → red. Non-vacuous.
- Cancellation leg: non-vacuous (without the `isCancelled()` door the overflow leg fires and
  the count reads 2, asserted 1).
- Clip pins (task 3): newest rows asserted by OBJECT IDENTITY (`assertSame($history[11], …)`),
  plain rows excluded from candidacy, fields survive via `messageWithContent()` (`id`,
  `toolResults` asserted), short-output and small-history no-op cases pinned.
  Kept-row byte-identity for the cache prefix is therefore pinned in the strongest form;
  the cached arm is the engine's system/tool/memo prefix, which the clip never touches.
- E646: zero timers/timeout knobs in src (the test's 60 s watchdog timer is test-side).
- §1.9: no glob-shaped and no `**Refused:**`-shaped literals; GlobDialect green.
- Conventions: strict_types first line, `final` classes, bare `summaryModel()` accessor,
  `withSummaryModel(null)` really clears (`mutate(['summaryModel' => null])`, no XSet
  sentinel on that field, so the fallback is live, not dead code). In-file test doubles are
  exempt from `tools/check-one-type-per-file.php` (it reads `autoload.psr-4` only, line 142;
  `tests/` is autoload-dev) with existing precedent.

## Welder carries
- `scripts/parallel-tests-durations.tsv`: rows for `tests/Backend/CacheReusingSummaryBackendTest.php`
  and `tests/Host/CompactionFallbackParityTest.php`; suite-figure + README +14T.
- Finding 2 as a ruling-carry note; findings 1,3,4 as the r2 fix set.
