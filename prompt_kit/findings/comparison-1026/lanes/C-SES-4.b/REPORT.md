# C-SES-4.b — OTel retry + async transport — LANDED

- Commit: `0f0ffd928` on `cc/C-SES-4.b` (worktree /home/sites/cc-cmp-C-SES-4.b, base 86f43fae2) — 11 files, +915/-11. Author+committer Joe Huss <detain@interserver.net> (verified `%ae`/`%ce` separate greps = 1 each), zero Co-Authored trailers.
- Tree clean of lane paths; never touched suite-figure.json / durations.tsv / dist / protocol files.

## Files
- NEW `candy-code/src/Observability/RetryingEventSink.php` — interface tick()/hasCarryOver().
- NEW `candy-code/src/Observability/AsyncOtlpTransport.php` — React\Http Browser on Loop::get(); connect timeout via Connector(['timeout'=>min(1.0,…)]) + request timeout via withTimeout(exportTimeoutSeconds); ctor pins withRejectErrorResponse(false) so the status boundary is owned (post() maps status<300→bool); fromConfig(OtelConfig).
- NEW `candy-code/src/App/TelemetryRetryTickMsg.php`.
- MOD `OtelSink.php` — async leg (?Closure ctor, withTransports(), fromConfig builds both legs, hasAsyncTransport()/postAsync()).
- MOD `BatchEventSink.php` — implements RetryingEventSink; send() async branch + inFlight ledger; flush() reconciles still-in-flight bodies through the sync leg (duplicate-span risk documented in class docblock as accepted trade); settleFlight() late-settle-inert law; tick() re-posts carry-over.
- MOD `Chat.php` — OTEL_RETRY_SUBSCRIPTION/TICK_SECONDS consts + subscriptions() tail conditional tick (15s, declared only while hasCarryOver); in-region FQNs, no import edits.
- MOD `Chat/Handlers/HandlesSessionSurface.php` — TelemetryRetryTickMsg arm calls tick().
- MOD `docs/ENVIRONMENT.md` — CANDY_CODE_OTEL_BATCH_SIZE + OTEL_EXPORTER_OTLP_ENDPOINT rows now describe async post / 15s retry tick / synchronous shutdown flush (in-step; no new env names → EnvRosterDrift unaffected).
- MOD `tests/Observability/BatchEventSinkTest.php` — +1 async-shape flush test; 15 original tests byte-green.

## Gates (cwd worktree candy-code, plain pipe, -u LINES -u COLUMNS)
- `--filter 'Observability|Otel|BatchEventSink|TelemetryRetry'` → **OK: Tests 82, Assertions 353** (2 PHPUnit Deprecations = pre-existing foreign AccessorDeprecationTest private providers, present in every run).
- `--filter EnvRosterDrift` → **OK: Tests 37, Assertions 37,488**.
- Five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift` → **133 tests, ONLY the ReadmeSuiteFigureDrift staleness pair red** (live 26,109 vs pinned 25,537 — base tree already drifted + other lanes; weld re-pin duty, lane forbidden to touch). DocFigure/Glob/Helper-drift all green (no glob-shaped literals added; ENVIRONMENT.md prose kept every pinned token).
- sugar-async suite figure: **OK (117 tests, 259 assertions)** (main-tree linked vendor; my lane never touches sugar-async).

## NEW test files for gate DUR (durations.tsv rows owed by gate)
- `candy-code/tests/Observability/AsyncOtlpTransportTest.php` (8 tests: loopback react collector consume-before-answer, refusal carry law, flush-no-running-loop sync delivery, connect-refusal reject, settled-outcome sync law, fromConfig both legs, sync-only shape, status boundary).
- `candy-code/tests/Observability/ChatTelemetryRetryTickTest.php` (4 tests: tick declared only while carry-over, tick delivers+drops timer, still-refused keeps armed, foreign sink inert).

## Regression discipline
- Pre-fix proof: reverted the 4 modified src files to base + removed the 3 new src files, kept tests → ChatTelemetryRetryTickTest 4/4 RED (2 class-missing errors + 2 assertion failures), AsyncOtlpTransportTest flush test ERROR. Restored from cp backups, md5sum -c OK, gate re-run 82/353 green identical.

## Notes
- E646 honored: bounded connect+request timeouts on TELEMETRY posts only (not LLM calls); tick is a UI-cadence no-op answer (C-AGT-7.a pattern), subscription cost zero when dark.
- Loop hygiene: all timers cancelled in finally (a left-arm deadline timer was a real found-and-fixed cross-test hazard); bootstrap-pinned StreamSelectLoop makes timers honest (LoopPin contract noted in test docblock).
- ObservabilityWiringTest consume-site census unchanged (added no consume() calls).
