### C-SES-4.c — Headless `-p` exports spans
- item: C-SES-4 (sources: GAP-OBS-02 (`-p` half), p2-miss: serve + headless OTel sink threading)
- kind: gap · effort S
- approach:
  - `NonInteractive::run()` obtains `Bootstrap::observabilitySink()`, a static call, so `Bootstrap.php` is not edited. It is a null sink while dark.
  - The `$onEvent` observer (`src/Cli/NonInteractive.php:576-579`) also calls `$sink->consume($event)`. The shutdown flush is already registered by `observabilitySink()`.
  - Flip the "`-p` exports nothing" half of the ENVIRONMENT.md `OTEL_EXPORTER_OTLP_ENDPOINT` row, and the `ObservabilityWiringTest` pin.
- files.create: none
- files.modify: `candy-code/src/Cli/NonInteractive.php` (run(): the `$onEvent` observer), `candy-code/docs/ENVIRONMENT.md` (the OTLP endpoint row), `candy-code/tests/Observability/ObservabilityWiringTest.php`
- tests: create `candy-code/tests/Cli/NonInteractiveOtelExportTest.php`: with a lit config and a stub transport, a `-p` run exports one `chat.turn` span with the turn's tokens; dark costs no transport call.
- docs/guards: the ENVIRONMENT.md row and `ObservabilityWiringTest` flip in-step
- serialized: [headless] [docs:ENVIRONMENT.md]
- deps: C-SES-4.a
- phase: normal
- ruling: none
- risks: The `Bootstrap.php:1250-1256` comment ("the ONLY sink wiring") stays false until C-SES-4.d corrects it.

