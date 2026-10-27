# C-SES-4.a — DONE, committed

Commit d0b082067 on cc/C-SES-4.a (parent 2e1ef6430 = brief's "2e1efc430", digit transposition in prompt; chain verified). Author/committer Joe Huss <detain@interserver.net> (separate %ae/%ce greps = 1 each), zero Co-Authored-By, never pushed. 6 files, +302/−25.

## Files
- src/Observability/BatchEventSink.php — consume() stores [$event, unix-nanos] pairs stamped at consume time via hrtime-anchored clock (OBS-1-P1); ctor captures ownerPid via injectable $currentPid seam (OBS-1-P4); flush() no-ops in foreign pid BEFORE the closed-set, so a later owner flush still lands; exportBuffer hands pairs to encodeAll.
- src/Observability/OtelSink.php — encodeAll(array<array{object,?int}>) → encoder->encodeMany(), then AT MOST ONE json_encode(exportBody()) per batch (OBS-1-P2); bare consume passes null stamp → encoder wall-clock fallback.
- src/Observability/OtlpEncoder.php — encode($event,?int $nanos=null) unchanged shape via new private absorb(); public encodeMany() returns raw closed spans; exportBody promoted to public instance method.
- tests: BatchEventSinkTest +3 (consume-gap duration at batch sizes 2 and 64; 3 closed spans = 1 transport call; foreign-pid flush sends nothing), OtelSinkTest +1 (encodeAll one-body), OtlpEncoderTest +2 (encodeMany spans + no-close case). No NEW test files.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- --filter 'BatchEventSink|OtelSink|OtlpEncoder|Observability': Tests: 57, Assertions: 227, Deprecations: 2 — 0F/0E.
- five-guard: Tests: 133, Assertions: 15333, Failures: 2 — ONLY staleness pair (live 25,602 vs pinned 25,537; +7 mine, re-pin is weld duty).
- Regression proof: with base src restored the 3 new batch tests fail 3F; green with fix.

## Deviations/seams
encodeMany returns raw spans wrapped once via now-public exportBody (brief's literal reading); pid seam defaults to getmypid(), house SseMcpServer shape. No seams outside step files; suite-figure.json/durations.tsv/README untouched.
