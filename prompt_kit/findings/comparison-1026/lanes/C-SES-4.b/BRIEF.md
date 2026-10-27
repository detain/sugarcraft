### C-SES-4.b — Non-blocking export + wired `tick()` retry
- item: C-SES-4 (sources: OBS-1-P2, OBS-1-P3)
- kind: fix · MINOR · effort M
- approach:
  - **Root cause.** POSTs are synchronous Guzzle calls, up to `CANDY_CODE_OTEL_TIMEOUT` each (`OtelSink.php:48-58`), made inside `consume()` on the TUI `update()` path. The `tick()` retry arm (`BatchEventSink.php:77-86`) has no production caller.
  - **Async transport.** Add `AsyncOtlpTransport`, a `React\Http\Browser` on `Loop::get()` (`react/http` is already a dependency), with connect and request timeouts. This is telemetry, not an LLM call, so E646 does not apply.
    - `OtelSink::fromConfig()` builds both transports.
    - `BatchEventSink::send()` posts asynchronously; a rejection carries the body to `$unsent`.
    - `flush()` stays synchronous. It re-posts any still in-flight body through the Guzzle transport, which covers `-p` and shutdown, where the loop may not run.
  - **Retry tick.**
    - Add a `RetryingEventSink` interface (`tick()`, `hasCarryOver()`), implemented by `BatchEventSink`.
    - `Chat::subscriptions()` declares an `OTEL_RETRY_SUBSCRIPTION` tick every 15 s, only while the sink has carry-over.
    - The `TelemetryRetryTickMsg` arm calls `tick()`.
  - **Docs.** Make the `docs/ENVIRONMENT.md` "span-retry path" text true.
- files.create: `candy-code/src/Observability/RetryingEventSink.php`, `candy-code/src/Observability/AsyncOtlpTransport.php`, `candy-code/src/App/TelemetryRetryTickMsg.php`
- files.modify: `candy-code/src/Observability/BatchEventSink.php` (send, flush, implements RetryingEventSink), `candy-code/src/Observability/OtelSink.php` (fromConfig, transports), `candy-code/src/Chat.php` (subscriptions()), `candy-code/src/Chat/Handlers/HandlesSessionSurface.php` (the TelemetryRetryTickMsg arm), `candy-code/docs/ENVIRONMENT.md` (the `CANDY_CODE_OTEL_BATCH_SIZE` and `OTEL_EXPORTER_OTLP_ENDPOINT` rows)
- tests: create:
  - `candy-code/tests/Observability/AsyncOtlpTransportTest.php`: a loopback `react/socket` collector; `consume()` returns before the response; a refusal carries the body; flush with no running loop delivers in-flight bodies synchronously;
  - `candy-code/tests/Observability/ChatTelemetryRetryTickTest.php`: the subscription is declared only while there is carry-over; a failing-then-healthy transport delivers on the next tick.
  
  Modify `candy-code/tests/Observability/BatchEventSinkTest.php`.
- docs/guards: the `docs/ENVIRONMENT.md` rows are prose only, with no new variable (`EnvRosterDriftTest` is unaffected)
- serialized: [chat:subscriptions] [hot:candy-code/src/Chat/Handlers/HandlesSessionSurface.php] [docs:ENVIRONMENT.md]
- deps: C-SES-4.a
- phase: normal
- ruling: none
- risks: In-flight reconciliation at flush can duplicate a span whose response was lost. That is acceptable for telemetry; document it. Confirm `LoopPin` hygiene in the new test, since it arms timers on `Loop::get()`.

