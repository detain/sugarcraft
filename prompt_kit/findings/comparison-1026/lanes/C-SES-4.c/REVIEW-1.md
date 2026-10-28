# C-SES-4.c — REVIEW-1 (fresh reviewer r1)

VERDICT: CLEAN — 0 critical / 0 major / 2 minor (both step-local prose/disclosure) / 0 nit.

**Scope.** Commit ebe47fb27 (numstat) touches exactly 4 files — all named in the brief (NonInteractive.php, ENVIRONMENT.md, ObservabilityWiringTest.php, new NonInteractiveOtelExportTest.php). The "17 files" figure in my dispatch prompt did NOT reproduce on disk. Hub discipline held: NonInteractive edit is the run() $onEvent observer region only. Author %ae/%ce both detain@interserver.net, no trailers.

**Claims re-derived.** Fold = `$eventSink->consume($event)` inside the observer (NonInteractive.php:614), dial once at :597, Bootstrap.php untouched. Structural arm genuinely pins position: slices the observer by its 8-space `};` terminator and requires the consume inside that slice, plus consume-before-`$backend->complete(`. I reasoned both bypass mutations (consume moved after the closure; consume inside but dial dropped) redden it. Lit/dark pair: batch=2 closes the chat.turn in one POST; dark leg is same-harness endpoint-unset with 0-body assert — known-positive pairing holds. ENVIRONMENT.md row truth: two wiring sites, serve correctly left as the remaining dark OBS-02 host (owner-gated, matches plan §6 Serve-telemetry ruling and C-SES-4.d's claim row). ObservabilityWiringTest de-falsification honest (message "ONLY wiring site" → two-site story; docblock flipped). Bootstrap.php:1336 "ONLY sink wiring" comment stays stale per plan — §4 assigns it to C-SES-4.d (`chat(comment only)`) — leave justified. Non-memoised sink (fresh BatchEventSink + shutdown arm per dial) disclosed honestly in REPORT seams; harmless for one-shot -p; C-SES-4.d review item. New test file flagged for the durations gate.

**Gates (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS).**
- `--filter NonInteractiveOtelExportTest`: `Tests: 2, Assertions: 9` OK — matches.
- `--filter 'ObservabilityWiringTest|NonInteractiveOtelExportTest|OtelConfigTest|BatchEventSinkTest|OtelSinkTest|EnvRosterDriftTest'`: `Tests: 72, Assertions: 38885` — exact.
- guards `NonInteractiveStopTrailersTest|NonInteractiveRefusalDocumentTest|DuplicatedTestHelperDriftTest|GlobDialectDifferentialTest|TreeWideGuardRosterTest|SymbolCitationDriftTest`: `Tests: 76, Assertions: 21480` — exact. Deprecations: 2 = pre-existing baseline (same on base twin runs).

**MINORs (step-local, non-blocking).**
1. NonInteractive.php:611 comment says "Chat's two doors"; the wiring-test docblock counts THREE consume points (settle queue, pump head, coalesced run). Pick one story.
2. The fold rides before `$emitter?->onEvent` — correct order, but the arm's "no event path can bypass it" phrasing would deserve a serve-side twin when C-SES-4.d lands (already its duty).

Ready for weld. No fix round required; minors may ride the weld or stay open for C-SES-4.d.
