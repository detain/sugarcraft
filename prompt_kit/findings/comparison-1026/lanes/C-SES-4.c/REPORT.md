# C-SES-4.c — headless `-p` exports OTel spans — LANDED

Commit: ebe47fb27 on cc/C-SES-4.c (base cf0cfd0be, not pushed, tree clean).
Author/committer verified separately: `%ae`=detain@interserver.net, `%ce`=detain@interserver.net, `%an`/`%cn`=Joe Huss, 0 Co-Authored-By lines.

## What shipped
- `candy-code/src/Cli/NonInteractive.php` (run(), observer region only): `$eventSink = Bootstrap::observabilitySink();` dialled once per run beside the observer setup; the `$onEvent` closure gained `$eventSink` in its `use` list and `$eventSink->consume($event);` after `$observeRefusals($event);` — every event the backend emits folds into the sink before the emitter sees it. No Bootstrap.php edit; dark stays one `getenv()` + NullEventSink; the seam's existing bounded shutdown flush carries the tail batch (E646-clean, no new timers).
- `candy-code/docs/ENVIRONMENT.md`: OTLP section preamble + the `OTEL_EXPORTER_OTLP_ENDPOINT` row flipped from "Chat-path only … builds no Chat either" to the truthful TWO wiring sites (chat() + headless run() dialing the same seam); `serve` explicitly named as the remaining dark host / OBS-02 half owner-gated for C-SES-4.d. No new env vars → EnvRosterDrift rode the same commit green.
- `candy-code/tests/Observability/ObservabilityWiringTest.php`: docblock flipped to the two-site story; line-50 message de-falsified ("the ONLY wiring site"); NEW structural arm `testTheHeadlessPathDialsTheSameSeamAndFoldsItsObserver` — the dial sits once in run(), consume() is INSIDE the observer closure (sliced by the closure's 8-space `};` terminator — `methodBody`'s `\n    }` pattern cannot cut a closure), and the dial precedes the `$backend->complete(` call that feeds it.
- NEW `candy-code/tests/Cli/NonInteractiveOtelExportTest.php` (2T): behavioural pair on a loopback OTLP collector + the seam dialed by env. Lit (endpoint set, batch=2 so the StepStarted+UsageUpdated pair closes in ONE export — the encoder only POSTs closed spans): exactly one body, one `chat.turn` span, `gen_ai.usage.input_tokens`/`output`/`total` = 100/40/140 (OTLP/JSON int64-as-string cast noted). Dark (endpoint unset, batch knob still dialed): zero transport calls — known-positive pairing is the lit leg. Helpers deliberately renamed/reshaped vs the AsyncOtlpTransportTest twins (DuplicatedTestHelperDrift green).

## Gates (targeted only, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'NonInteractiveOtelExportTest'`: OK, 2T/9A.
- `--filter 'ObservabilityWiringTest|NonInteractiveOtelExportTest|OtelConfigTest|BatchEventSinkTest|OtelSinkTest|EnvRosterDriftTest'`: OK, 72T/38885A.
- Guards: `NonInteractiveStopTrailersTest|NonInteractiveRefusalDocumentTest|DuplicatedTestHelperDriftTest|GlobDialectDifferentialTest|TreeWideGuardRosterTest|SymbolCitationDriftTest`: OK, 76T/21480A.
- Fail-before-fix PROVEN: with base `NonInteractive.php` restored over the tip copy (cp backup, md5-verified restore), exactly 2 reds — the lit behavioural test (zero bodies) and the structural arm (no dial in source); dark leg green both ways. "PHPUnit Deprecations: 2" is the suite's pre-existing baseline count (reproduced on untouched twin files).

## New test files (for the durations gate)
- `candy-code/tests/Cli/NonInteractiveOtelExportTest.php` (+2T; suite-figure/durations re-pin = welder duty, not committed here)

## Notes / seams
- The seam is NOT memoised: each lit run builds a fresh BatchEventSink and registers its own shutdown flush — many accumulate in a long process; per the OtelSink contract each flush is inert once its batch settled, and my lit test drains its inFlight via the pump so no shutdown POST leaks. Worth a look when C-SES-4.d touches serve (out of scope here).
- Bootstrap.php:1250-1256 ("the ONLY sink wiring") left untouched per brief — flips with C-SES-4.d.
- Nothing committed: suite-figure.json, durations.tsv, dist/, protocol files (none touched).
