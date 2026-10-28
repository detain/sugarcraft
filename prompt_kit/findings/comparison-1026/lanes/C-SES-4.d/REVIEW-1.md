# C-SES-4.d — REVIEW-1 (r1, fresh reviewer)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR. Weldable with carries.

## Gate re-derivation at 9d59bb960 (worktree, linked vendor, plain pipe cwd=candy-code -c phpunit.xml)
- tests/Host/SessionHostOtelFoldTest.php tests/Observability → **OK (74 tests, 290 assertions)** — exact vs claim.
- SessionHostTest+SessionHubTest+ServedRuntimeNoticeDrainTest+NonInteractiveOtelExportTest → **OK (27 tests, 135 assertions)** — exact.
- EnvRosterDrift+DocFigureProseDrift+StderrEmitterCensus → **OK (224 tests, 64,379 assertions)** — exact. Zero new emitters, zero roster flips owed (§1.8 satisfied in-step: ENVIRONMENT.md row is a wording flip of an already-rostered var).
- Author/committer detain both greps, zero trailers; parent da284586a; 7 files +716/−16, all within brief's declared file set + the two test files.

## Core judgments
1. **Dark-state byte-identity: PASS.** No OTEL env → workspace arm never registers (literal `if ($otelConfig->exportsEvents())` guard, behavioral pin testADarkLaunch… assertNull on real Bootstrap::workspace()); SessionHost::otelSink() resolves null service → NullEventSink (consume no-op); buildFactory returns EventSinkFactory::dark() before the builder closure runs (no Guzzle client, no register_shutdown_function); dark() forSession allocates a bare NullEventSink and arms nothing. Pins non-vacuous by reading: M2-unguard reddens the strpos-guard leg (assertIsInt fail-closed, not assertGreaterThan(false,·)) + the behavior leg; M3 reddens the $ran-flag + a real TypeError.
2. **Fold ordering vs C-SES-4.c: same law, different position — closeout note only.** SessionHost feeds the sink FIRST (before the three early-return arms); NonInteractive consumes after two local bookkeeping checks but before the emitter. The invariant both hold: the sink sees every event before anything downstream can skip. Headless could in principle starve the sink if $observeRefusals threw; that is C-SES-4.c's pre-existing shape, not this lane's drift. No precedence-law divergence.
3. **Release flush: PASS.** Behavioral pin (batch 64, zero bodies mid-flight, release → 1 body keyed 'beta'); double-flush impossible: BatchEventSink::flush early-returns on $closed (set on first flush); consume-after-release deaf by the same guard. The tests' tearDown re-release of already-released hosts walks the double-flush path in-suite for real.
4. **§6.1 ruling: PASS.** plan_comparison.md:4120 row "Serve telemetry | `serve` exports OTel spans when `OTEL_EXPORTER_OTLP_ENDPOINT` is set. | C-SES-4.d" — REPORT quotes exact; ENVIRONMENT.md flipped row states the three-path truth with the lit-when-endpoint-set + per-session-encoder shape and cites §6.1; brief's §6.3 mis-cite confirmed (§6.3 holds no C-SES-4 entry) and judgmently irrelevant. C-SES-4.c carry (non-memoised sink seam flagged to this review, WELD.md:28) matches: non-memoisation is deliberate and correct for hub close/reopen; the accumulation worry is bounded — one shutdown hook per factory, WeakReference live list pruned at flush, host-lifetime caching in SessionHost.
5. **Bootstrap confinement: PASS.** Exactly 2 hunks: buildChat comment (region :1333) + workspace arm (region :2109, between BackgroundShellRegistry and the EventLog tail). In-flight C-TUI-5.d touches Bootstrap ~L3339/L3473 (first-run provider) — disjoint region, pick-friendly; welder note, not a finding.

## Other categories
- Conventions: final, strict_types, one type/file, docblocks say why, mirrors buildSink shape. Lang::t n/a (zero user strings). §1.9 clean (no glob/Refused-shaped literals; exec rm-rf sandbox cleanup is the house test pattern, 10+ prior files). §1.10 n/a (no proc_open sites). E646 clean (consume non-blocking, flush bounded by existing per-request timeout, no timers added).
- phpstan claim (SessionHost ?->name nullsafe.neverNull pre-existing): phpstan not in reviewer bash allow-map — accepted via arithmetic proof instead: base error :1662 + the lane's three prior hunks' shift (+3 import, +13 property, +5 release) = :1683 tip. Self-consistent; builder's blob-swap evidence credible.
- Regression-test quality: the collision pin (two forSession sinks, identical StepStarted(1,4)) is the exact trap the step exists for; shared-encoder mutation would collapse it (assertCount(2 bodies) + per-session span index + distinct traceIds).

## NITs (non-blocking)
- N1: OtelConfig::sinkFactoryFromEnvironment + EventSinkFactory::forExport have test-only callers (Bootstrap inlines the builder closure because it holds its own $otelConfig for the registration guard). The shim docblock's "the one call shape Bootstrap's workspace arm uses" is true of shape, not call. Fine as seam; consider a dated note at closeout.
- N2: testReleaseFlushes… message says "one event never reaches a batch of 64" while the fixture folds two step events. Cosmetics.

## Welder carries
- durations.tsv row for NEW candy-code/tests/Host/SessionHostOtelFoldTest.php; suite-figure+README staleness pair re-pin +8T (closeout duty, correctly absent from lane).
- C-TUI-5.d Bootstrap region adjacency (disjoint hunks; pick order irrelevant but expect clean auto-merge).
