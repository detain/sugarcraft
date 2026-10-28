# C-SES-4.d — Serve exports spans (per-session encoders) — CLOSED

- Branch/worktree: `cc/C-SES-4.d` @ `/home/sites/cc-cmp-C-SES-4.d`, base `da284586a`.
- Commit: `9d59bb96007b7656331bb4ef497ac273e6a2e97e` — `C-SES-4.d: serve exports spans via per-session sink factory`.
  Author/committer `%ae`/`%ce` both `detain@interserver.net` (separate greps ==1), zero Co-Authored trailers. Never pushed.

## Ruling honored
§6.1 (decided table, 2026-10-26): “Serve telemetry | `serve` exports OTel spans when `OTEL_EXPORTER_OTLP_ENDPOINT` is set. | C-SES-4.d” → YES, wire it.
Deviation-in-brief note: the lane brief pointed at “§6.3 rulings block”, but §6.3 carries **no** C-SES-4 entry; the answer lives in the §6.1 decided table. Design block is consistent with it — no contradiction, nothing overridden.

## What shipped
- **`src/Observability/EventSinkFactory.php` (new, 166 ln)** — the per-session-encoder factory, the trap the step exists for: `OtlpEncoder` keys open turn spans by `$event->step`, and served sessions march over identical step numbers, so one shared encoder would splice one session's usage onto another's span. `forSession(string): EventSink` mints a `BatchEventSink` over `OtelSink::withTransports(...)` with its OWN `OtlpEncoder(['session.id' => $sessionId])` while both transport legs (sync closure + shared `AsyncOtlpTransport`, Browser is loop-bound) stay shared — built once via the `OtelSink::fromConfig` template so the wire shape has zero second copy. `dark()` answers a shared `NullEventSink` and never registers anything. The FIRST lit sink arms exactly ONE `register_shutdown_function`; the flush-all is PID-guarded (`getmypid() !== ownerPid` returns — fork safety) and prunes dead `WeakReference` entries. `forSession` is deliberately NOT memoised: `SessionHub::close()` releases a host (whose sink self-guards after flush) and a reopen must get a live sink; each host caches its own.
- **`OtelConfig.php`** — `buildFactory(callable): EventSinkFactory` gate mirroring `buildSink` (dark returns `EventSinkFactory::dark()` BEFORE the builder runs; lit `$factoryFactory($this) ?? dark()`) + `sinkFactoryFromEnvironment()` shim, so the dark decision stays single-copied in config.
- **`Bootstrap.php` (hub — 2 minimal hunks only)** — (a) `workspace()`: guarded arm registering `withService(EventSinkFactory::class, $otelConfig->buildFactory(...))` ONLY when `exportsEvents()`, placed between the BackgroundShellRegistry chain and the EventLog tail; (b) the stale `buildChat()` OBS-02 comment rewritten: serve now threads telemetry through the workspace factory (owner ruling §6.1); the “serve exports NOTHING / owner-gated” sentence is gone.
- **`SessionHost.php`** — `?EventSink $otelSink` property; lazy `otelSink()` resolving the workspace service (`instanceof EventSinkFactory ? forSession : NullEventSink`); `fold()` feeds `$this->otelSink()->consume($event);` as its FIRST statement — fold is the single served-turn choke (both `pump()` and `settleWith()` route through it, so sub-agent/background hosts each get their own per-session sink too); `release()` flushes beside the transcript via `$this->otelSink?->flush();` (consults the property — a host that folded nothing never mints a sink to flush). Consume is non-blocking (C-SES-4.b law: `BatchEventSink::consume` never throws, POSTs ride the async leg or the batch boundary; sync export only at flush).
- **`docs/ENVIRONMENT.md`** — the `OTEL_EXPORTER_OTLP_ENDPOINT` row's serve half flipped in-step: three wired paths (chat TUI, headless `-p`, serve host), the per-session-encoder rationale, non-blocking fold + release/shutdown flush shape.
- **`tests/Observability/ObservabilityWiringTest.php`** — class docblock + `:56` message flipped to the three-path truth; new `testTheServePathFoldsThroughAPerSessionSink` structural pins (fold-first consume, release property-only flush, workspace arm INSIDE the lit guard with fail-closed `assertIsInt` legs — see mutations).

## Tests (new): `tests/Host/SessionHostOtelFoldTest.php` — 8 tests / 34 assertions
1. `testTwoSessionsWithIdenticalStepNumbersExportDistinctSpans` — THE collision pin, factory level: two `forSession` sinks, identical `StepStarted(1,4)`+`UsageUpdated(1,…)` → two bodies, each keyed by its own `session.id` resource attribute, exactly one `chat.turn` span each, different traceIds. (A shared encoder would collapse the pair/splice — the pin is the span-count + session-keyed index.)
2. `testAServedTurnFoldsThroughTheWorkspaceFactory` — real host: submit → pump → batch-2 body lands with host session id.
3. `testReleaseFlushesTheTurnTailTheBatchNeverCarried` — batch 64: pump leaves zero bodies; `release()` exports the tail.
4. `testADarkWorkspaceRegistersNoFactoryAndExportsNothing` — host over a service-less workspace folds silently (null sink, no throw).
5. `testADarkLaunchRegistersNoFactoryServiceOnTheRealWorkspace` — REAL `Bootstrap::workspace()` launch, env dark → `service(EventSinkFactory::class) === null` (“guarded, not merely fed”).
6. `testALitLaunchRegistersTheFactoryServiceOnTheRealWorkspace` — lit env → service is an `EventSinkFactory`.
7. `testTheDarkGateNeverRunsTheFactoryBuilder` — `$ran` flag proves the builder is never invoked under `buildFactory` dark.
8. `testTheEnvironmentShimLandsDarkWhenNoEndpointIsSet` — `sinkFactoryFromEnvironment()->forSession` is a `NullEventSink`.
Fixture note (discovered): step/usage frames reach a served host only through the backend declaring `InteractiveTurn` — `TurnRunner` asks for that structurally and hands the plain `$onEvent` observer a narrower typed union (StepStarted through it is a TypeError); the fixture fires both frames on the dedicated `$onStep` channel (roadmap 1.C-4), matching EngineBackend's real dispatch.

## Gates (all plain-pipe, cwd=candy-code, `env -u LINES -u COLUMNS php vendor/bin/phpunit -c phpunit.xml …`)
- `tests/Host/SessionHostOtelFoldTest.php` → **OK (6→8 tests, 27→34 assertions)** across the fix rounds; post-commit `tests/Host/SessionHostOtelFoldTest.php tests/Observability` → **OK (74 tests, 290 assertions)**.
- `tests/Observability` (dir) → **OK (66 tests, 258 assertions)** incl. flipped ObservabilityWiringTest, OtelConfigTest, BatchEventSinkTest, ChatTelemetryRetryTickTest.
- `tests/Host/SessionHostTest.php tests/Host/SessionHubTest.php tests/Host/ServedRuntimeNoticeDrainTest.php tests/Cli/NonInteractiveOtelExportTest.php` → **OK (27 tests, 135 assertions)**.
- `tests/Config/EnvRosterDriftTest.php tests/Config/DocFigureProseDriftTest.php tests/Cli/StderrEmitterCensusTest.php` → **OK (224 tests, 64,379 assertions)** (zero new emitters; doc roster untouched by wording).
- combined rerun incl. Observability dir + fold + hub + NonInteractive + EnvRoster → **OK (132 tests, 39,206 assertions)**.
- `tests/Config/GlobFigureDriftTest.php tests/Config/ReadmeSuiteFigureDriftTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/DuplicatedDocBlockLineTest.php` → 84 tests, **3 failures, all accounted**: (1,2) the expected ReadmeSuiteFigureDrift staleness pair (+8 live tests vs pinned figure — RE-PIN OWED BY WELDER, laws forbid me touching `suite-figure.json`/`durations.tsv`); (3) `DuplicatedTestHelperDrift::testNoDuplicatedConstIsUnrecordedInEitherPolity` — `NOW` const pair between `Context/CacheTtlIdleFoldTest.php:41` and `Providers/ModelMetadataTest.php:28`, BOTH committed at base (the fold test arrived via foreign weld `878047472` C-CTX-5.c), zero working-tree dirt on either, zero `const` in any lane file → PROVEN not-lane.
- `php -l` clean on all 7 files; **phpstan L5** (`vendor/bin/phpstan analyse` on the 3 touched src files): 1 error — `SessionHost.php:1683 nullsafe.neverNull` on `$parsed?->name` — PROVEN not-lane: swapped-in pristine `da284586a` file yields the identical error at its line 1662 (cp-backup restore md5-verified; stash never used). My new sources contribute zero errors.

## Mutations (cp-backups /tmp/opencode/cc-ses4d/mut/, md5-verified restores; no git-checkout on dirty files)
- M1 neuter `fold()`'s consume line → fold test **2F** (turn-fold + release-tail). Restore clean.
- M2 unguard the workspace arm (`if (true)`) → **2F**: dark-launch behavior test (:222) + structural guard leg (:143). Restore clean. (First M2 round exposed a vacuity in my own pin: `assertGreaterThan(false, pos)` passes silently; fixed with fail-closed `assertIsInt` legs + the real-`workspace()` behavior pair — the pin now genuinely discriminates.)
- M3 neuter `buildFactory`'s dark gate → **1E + 1F**: TypeError `rtrim(null)` (dark builder reaching transport construction) + the `$ran` flag test. Restore clean.

## Deviations disclosed
1. Brief's “§6.3” → ruling actually in §6.1 (quoted above); no design conflict.
2. Design said `SessionHost::close()` — no such method exists; the close-path choke is `release()` (`SessionHub::close()` calls it), which is where the flush landed, beside `transcripts->flush()`.
3. `forSession` non-memoised (per-call fresh sink) — required by hub close/reopen (a flushed `BatchEventSink` is deaf by design); shutdown registration is once-per-factory, PID-guarded, WeakReference-pruned.
4. Dark gate shape: the `if (exportsEvents())` guard at the workspace arm AND the `buildFactory` config gate both stand (belt for “registers nothing when dark”, braces for “gate never runs the builder”) — each mutation-proven separately.
5. Test fixture must declare `InteractiveTurn` to emit step frames (see Tests) — discovered by the TypeError at `TurnRunner.php:466`; the fixture's `completeAsync` stays silent so dispatch is forced through the real 1.C-4 channel.
6. ENVIRONMENT.md wording kept structurally compatible: guard greps run BEFORE editing (no DocFigure/EnvRoster arm pins the retired “two places” prose).

## Welder notes
- NEW test file: `candy-code/tests/Host/SessionHostOtelFoldTest.php` (8 tests) → needs a `scripts/parallel-tests-durations.tsv` row; `suite-figure.json` + README staleness pair re-pin owed (live enum +8T).
- Lane files (7): src/Observability/EventSinkFactory.php · src/Observability/OtelConfig.php · src/Cli/Bootstrap.php (workspace arm + buildChat comment only) · src/Host/SessionHost.php · docs/ENVIRONMENT.md · tests/Observability/ObservabilityWiringTest.php · tests/Host/SessionHostOtelFoldTest.php.
- Bootstrap hunks are minimal and region-local (workspace(): 18 lines in the tail region between BackgroundShellRegistry and the EventLog return; buildChat: comment block only) — pick-friendly against sibling C-TUI lanes.
