### C-CTX-5.c — Idle fold timed to the prompt-cache TTL
- item: C-CTX-5 (sources: GAP-CTX-N2, CC-new#10, p1-miss: CTX-5 short cache-warm idle tier, CLAW-new#5 (bounded-history view, n/a))
- kind: competitor-feature · effort M
- approach:
  - `compaction.idleAuto` becomes an enum: `off | hour | cacheTtl`. The bool keeps reading as `hour`/`off`.
  - `cacheTtl` folds a session idle just short of its provider's cache TTL, from `MarksPromptCache` (5 min for most, 1 h when marked), when the history is over a floor below the 85% tier.
  - The reason: the next prompt would otherwise prefill the whole conversation uncached.
  - `IdleCompactionPolicy` owns the decision. The TUI tick (`HandlesRunControl.php:289`, 60 s cadence from `App.php:4691-4697`) and the served alarm (fixed in C-CTX-6.a) consult it.
  - It ships opt-in. `/settings` already renders the key, which is Claude Code's settings toggle.
  - It replaces C-CTX-6.c's "not shipped" sentence.
- files.create: `candy-code/tests/Context/CacheTtlIdleFoldTest.php`
- files.modify: `candy-code/src/Context/IdleCompactionPolicy.php`, `candy-code/src/Context/CompactorConfig.php` (mode parse), `candy-code/src/Chat/Handlers/HandlesRunControl.php` (tick verdict), `candy-code/src/Host/SessionHost.php` (`armIdleAutoCompaction()` delay from the policy), `candy-code/src/Config/Settings/Definitions/ContextSettings.php`, `candy-code/src/Config/LayeredSettings.php` (gen), `candy-code/docs/SETTINGS.md` (gen), `candy-code/docs/CONTEXT.md`
- tests: CacheTtlIdleFoldTest (policy table: TTL boundary, floor, uncached provider never folds); modify `candy-code/tests/Chat/IdleAutoCompactionTest.php`, `candy-code/tests/Host/SessionHostIdleAutoTest.php`, `candy-code/tests/Context/IdleCompactionPolicyTest.php`
- docs/guards: settings gen + `SettingsSchemaDocDriftTest`
- serialized: [docs:CONTEXT.md] [docs:SETTINGS.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] [hot:candy-code/src/Config/Settings/Definitions/ContextSettings.php] [hot:candy-code/src/Config/LayeredSettings.php]
- deps: C-CTX-6.a
- phase: normal
- ruling: none (ships opt-in; a default-on flip for caching providers is queued in Notes)
- risks: a 60 s tick against a 5 min TTL folds at about 4 min idle. That is acceptable and documented.

