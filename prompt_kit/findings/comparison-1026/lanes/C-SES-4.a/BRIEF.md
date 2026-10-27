### C-SES-4.a — Consume-time span stamps, one OTLP body per batch, PID-guarded flush
- item: C-SES-4 (sources: OBS-1-P1, OBS-1-P4, OBS-1-P2 (one-body half))
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause (timestamps).** `BatchEventSink::consume()` buffers raw events (`src/Observability/BatchEventSink.php:62-71`), and `OtlpEncoder::encode()` stamps `wallClockNanos()` at ENCODE time (`src/Observability/OtlpEncoder.php:78-80`), which happens at batch fill. Every batched span is therefore about 0 ms long and stamped at the wrong instant.
  - **Fix (timestamps).** `consume()` stores `[$event, hrtime-anchored unix nanos]` pairs. `OtelSink::encodeAll()` takes those pairs and passes each nano value to `encode($event, $nanos)`.
  - **OBS-1-P2, the one-body half.** Each closed span is currently its own POST body (`OtlpEncoder.php:102-106`). Add an `OtlpEncoder::encodeMany()` that returns the closed spans, so one `exportBody()` per batch carries them all, and one transport call goes out per batch.
  - **OBS-1-P4.** `BatchEventSink` captures `getmypid()` at construction. In any other pid, `flush()` is a no-op, so a forked child that leaves through `exit()` cannot re-flush the parent's copied batch. This replaces a Bootstrap-side guard and keeps the step free of serialized files.
- files.create: none
- files.modify: `candy-code/src/Observability/BatchEventSink.php` (consume, exportBuffer, flush, ctor), `candy-code/src/Observability/OtelSink.php` (encodeAll signature), `candy-code/src/Observability/OtlpEncoder.php` (encodeMany, exportBody visibility)
- tests: modify:
  - `candy-code/tests/Observability/BatchEventSinkTest.php`: with an injected clock, `StepStarted@t0` then `UsageUpdated@t0+N` export a span of duration N whatever the batch size; K closed spans produce one transport call; `flush()` from a foreign pid sends nothing (via an owner-pid seam);
  - `candy-code/tests/Observability/OtelSinkTest.php` and `candy-code/tests/Observability/OtlpEncoderTest.php`: the signatures.
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: `encodeAll()` is public. Check for test doubles before changing its parameter shape.

