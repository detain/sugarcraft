# C-TOOL-7.e — Bounded resample of all-malformed tool-call replies — DONE

## What landed
`candy-code/src/Runtime.php` (exclusive to this lane):
- `public const MAX_MALFORMED_RESAMPLES = 2` + `public const MALFORMED_RESAMPLE_NOTICE_FORMAT` (count bound, NOT a timeout — E646-clean; zero timers armed).
- `private static replyIsAllMalformed(?array $toolCalls): bool` (≥1 call, EVERY call carries `argumentsError()`; null/empty/mixed => false).
- `runBatch()`: outer sequence loop; a clean reply with `content === ''` and all-malformed calls is discarded and the SAME request re-issued, ≤2×, notice per discard through the `TransientFailure::announceRateLimit` channel (`RuntimeNoticeSink::warn`); after the bound today's A11 `failure()` path runs unchanged. Discarded replies' `foldUsage` summed into the yielded `AssistantMessage` (billing honest).
- `runStreaming()`: same, gated additionally on `$carried === null && $buffer === ''` (nothing user-visible streamed — text already on screen is append-only, never thrown away); discarded sequences' `$usages` concatenated into the final `Usage::sum([...$discardedUsages, ...$usages])` — byte-identical when no discards.
- Both method docblocks carry a C-TOOL-7.e truth paragraph.

Guards in-step: `tests/Cli/StderrEmitterCensusTest.php` — channel-6 seam roster row `'src/Runtime.php' => 2` with routing adjudication (seam, like the Retry-After row; not transcript, not stderr-only, not debug); prose cardinalities FORTY-ONE→FORTY-THREE, SEVENTEEN→EIGHTEEN files (selfCountAnchors arm).

## Tests (modify, no new files)
`tests/RuntimeMalformedToolArgumentsTest.php` (3→7 tests):
- `testAnAllMalformedReplyIsResampledAndTheGoodReplyRuns` (streaming, 2 calls, notice pinned, discarded call never becomes an error result)
- `testAfterTwoResamplesTheA11ErrorResultStands` (batch, provider asked exactly 1+2, A11 result stands, 2 notice lines)
- `testAReplyWithStreamedTextIsNotResampled` (text door, 1 call, no notice)
- `testUsageFromDiscardedRepliesIsSummedIntoTheStep` (10+10+5=25 tok, 0.04 USD on the final AssistantMessage)
- original `testATruncatedStreamedPayload...` flipped in-step: it is now the bound-then-stands scenario (fresh Response per re-issue, calls==3, both notices asserted; all original A11 assertions kept).
- setUp/tearDown arm/reset `RuntimeNoticeSink` (house pattern from Providers\RuntimeRetryAfterProductionPathTest); new `stubProvider` scripted-reply anonymous ProviderInterface with call counter, last-row repeat (a runaway loop fails on time, never hangs).

Regression proven red-before-green: before the src change the three behavior tests failed 1-vs-2/1-vs-3/10-vs-25.

## Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter RuntimeMalformedToolArgumentsTest` → OK, 7T/49A (2 pre-existing-style deprecations: @param doc-comments).
- combined `--filter 'RuntimeMalformedToolArguments|RuntimeRetryAfterProductionPath|TransientFailure|RuntimeUsageFold|ProviderLengthStopParse|DuplicatedTestHelperDrift|GlobDialect|DocFigureProseDrift|StderrEmitterCensus|SymbolCitationDrift|ChildWallClockBudget'` → 345T/53463A, only the 3 BASE-INHERITED StderrEmitterCensusTest reds (below).
- `--filter TreeWideGuardRosterTest` → 2F, base-inherited (proved by checkout-bisect: identical 2 reds at pristine b985fbe51; they name `Cli/JsonSchemaEnvelopeTest.php` + `Context/MemoryRecallBlockTest.php`, neither touched here).
- Mutations 2/2: MAX 2→0 → 4 reds (all bound/notice/usage arms); streaming text-door neuter → exactly 1 red naming `testAReplyWithStreamedTextIsNotResampled`. Both cp+md5-restored (9093bf91444ed463fa45c64a1d53236f).

## BASE-INHERITED REDS (not this lane; report for weld/closeout)
At master b985fbe51 `StderrEmitterCensusTest` already runs 3F — roster drift in files foreign to this lane: `src/Cli/NonInteractive.php` (fwrite 14→15, shapes 10→11) and `src/Cli/ArgvParser.php` (shapes 35→36) unrostered, plus the derived inherited-census arm; and `TreeWideGuardRosterTest` 2F (two walker call-sites in foreign test files unclassified). Someone's earlier lane landed emitters/walkers without the in-step flips. Not fixed here (out of declared regions; fixing foreign rosters would collide with any other lane claiming them).

## Not done / seams
- Docs/guards: none declared; no README/doc rows minted (notice text is src-const only, pinned by the test).
- suite-figure.json / durations.tsv untouched per law — +4 tests land on the closeout re-pin.
