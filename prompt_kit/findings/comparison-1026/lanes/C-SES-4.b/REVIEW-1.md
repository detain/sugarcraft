# C-SES-4.b — REVIEW-1 (round 1, read-only; reviewer ran gates in sandbox; lead persisted verbatim @1399@)

VERDICT: CLEAN

Scope: 11 files == brief list exactly; Chat.php hunks only at :18355-18371/:18601-18616 (subscriptions region); no composer touch — react/http+socket+promise pre-exist (composer.json:40-42), used legitimately.
E646: bounded connect `min(1.0,timeout)` + request `CANDY_CODE_OTEL_TIMEOUT` (AsyncOtlpTransport.php:67-72) on telemetry only; brief-exact; no wall-clock kill, LLM paths untouched.
Retry semantics: carry-over capped one batch in BOTH arms (BatchEventSink.php:294/:322 `array_slice(...,-batchSize)`); settleFlight unsets before queuing, flush clears inFlight before sync re-post (late settles inert via key check :308) — no double-export except the documented, accepted shutdown lost-response trade (class docblock + ENVIRONMENT.md).
Coexistence: C-AGT-7.a's `code.schedule-watch` (30.0s, Chat.php:650/:18434) already in base; `code.otel-retry` 15.0s distinct id, appends independently — no collision.
Gating §6.1 unchanged: dark unless `OTEL_EXPORTER_OTLP_ENDPOINT` (Bootstrap.php:1178-1201 + OtelConfigTest:84-110 dark pins; new dark-arm-arms-nothing pin ChatTelemetryRetryTickTest:45-46).
Pins non-vacuous: new tests reference lane-only classes/methods; logic-level reds confirmed by reading assertions (e.g. delivered-count after update(TelemetryRetryTickMsg)).
Gates run in sandbox (plain pipe, cwd worktree candy-code): filter 'Observability|Otel|BatchEventSink|TelemetryRetry' → 82T/353A OK (base 66T/292A, same 2 pre-existing deprecations); EnvRosterDrift → 37T/37488A OK; five-guard → 133T, ONLY ReadmeSuiteFigureDrift staleness pair red (25,537 vs live 26,109 — weld re-pin + 2 DUR rows for AsyncOtlpTransportTest/ChatTelemetryRetryTickTest owed).
Author law: A=C=detain@interserver.net (separate fields), zero Co-Authored trailers, single commit 0f0ffd928, tree clean.
NIT step-local: ENVIRONMENT.md batch-size row "The retry runs — a 15-second tick, declared only while..., re-posts it" — dash-parenthetical grammar rough; content true.
NIT step-local: Chat.php:18377 "FOUR THINGS" correction paragraph was already undercounting before this lane; adding a fifth conditional arm without a word is within region but could get one.

(Lead note: main-tree write was permission-denied for this reviewer; text above is its verbatim deliverable. Weld duties: plain pick 0f0ffd928, NO regen expected (no Protocol/web), gates 'Observability|Otel|BatchEventSink|TelemetryRetry' 82T/353A + EnvRosterDrift 37T + five-guard pair-only; DUR rows owed at gate: AsyncOtlpTransportTest.php + ChatTelemetryRetryTickTest.php; §4 unstaged; evidence 3 files.)
