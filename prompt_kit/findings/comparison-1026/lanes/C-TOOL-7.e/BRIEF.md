# BRIEF — C-TOOL-7.e: Bounded resample of all-malformed tool-call replies

- area: tool
- src: design-tool.md
- item: C-TOOL-7 (sources: CC-new#12 unparseable tool-call retry)
- kind: fix · NIT · effort S
- phase: normal
- ruling: none
- wave: None | late: False
- deps: none
- serialized (claims): [runtime:runBatch,runStreaming]

## Files to create
none

## Files to modify
`candy-code/src/Runtime.php` (runBatch, runStreaming, new const + helper)

## Declared regions
{"candy-code/src/Runtime.php": ["runBatch", "runStreaming", "const", "helper"]}

## Tests
modify `candy-code/tests/RuntimeMalformedToolArgumentsTest.php` (`testAnAllMalformedReplyIsResampledAndTheGoodReplyRuns`, `testAfterTwoResamplesTheA11ErrorResultStands`, `testAReplyWithStreamedTextIsNotResampled`, usage summed across attempts)

## Docs/guards
none

## Approach (verbatim)

Today a call whose arguments did not decode becomes an A11 error result (`Runtime::malformedArguments()` :4183, `failure()`), costing a whole step. Mirror Claude Code: in `Runtime::runBatch()` (:1899) and `runStreaming()`, when a reply has ≥1 tool call, every call carries `argumentsError()`, and no user-visible text was streamed, discard the reply and re-issue the SAME request, at most `MAX_MALFORMED_RESAMPLES = 2` times. The usage of discarded attempts is still summed into the step (billing honest). A one-line notice goes through the channel `TransientFailure::announceRateLimit` uses. After the bound, today's A11 path runs unchanged. This is a count bound, not a timeout (E646-clean).

## Risks
a deterministic model at temperature 0 repeats the same broken call; the bound caps the waste at two requests.
