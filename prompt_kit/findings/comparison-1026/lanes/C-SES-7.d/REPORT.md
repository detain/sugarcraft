# C-SES-7.d — Resume-cost guard (lane report)

Branch cc/C-SES-7.d. Base master 574e69f1d. NEVER push; welder merges.

## Milestones (commit-per-milestone, cat-file'd)

- M1 SKELETON 9195b8bcc — `candy-code/src/Host/ResumeCostEstimate.php` (119 lines, pure function; `CACHE_TTL_SECONDS=300`, `DEFAULT_WARN_USD=0.25`; unknown last-activity = cold; unpriced = fire-with-`≥`; warnUsd<=0 disables; injectable `$now` for TTL edge). `%ae`/`%ce` = detain@interserver.net, zero Co-Authored.

## History hazard (recorded honestly)

Two earlier incarnations blank-returned; the lead then ACCIDENTALLY `reset --hard` + `clean -fd` the worktree mid-session, destroying a later (uncommitted, near-complete) rebuild — including a discovered latent defect the first dirty tree carried: it had added `'resumeCostGuardArmed' => ...` to `mutate()`'s `$constructorProps` map (which splats `new self(...)`) WITHOUT a matching constructor parameter — every clone would have thrown "unknown named parameter". This rebuild fixes that at the root: constructor gains `?bool $resumeCostGuardArmed = null` (null = derive from launch shape: `$history !== [] && $currentSessionId !== null && $sessionMint === null`), and the mutate() session-identity choke (P-A4, where titleSource/titleEditor/pendingQuickAdd already reset on id change) re-derives the arm on identity change unless the route names it (switchToSession names true) — so a `/new` or landed handoff never inherits a previous session's arm.

## Remaining plan (this turn onward)

- M2 wiring: Chat.php 8 hunks (field docblock + ctor param + ctor derive + switchToSession arm + accessor + mutate carry + submit gate after spendCapRefusal + dispatchTurn consume + resumeCostHold() private method), InterfaceSettings.php `resumeCostWarnUsd` def (Float, Tuning, layered, project-settable, Live, range 0-100, readerSymbol Chat::resumeCostHold), lang/en.php 5 keys.
- M3 tests: tests/Host/ResumeCostEstimateTest.php (TTL edge, threshold 0, unpriced `≥`) + tests/Chat/ResumeCostGuardTest.php (fresh resume no hold; stale resume hold once then second Enter sends; /compact clears the hold — hold disarms by design; commands never consume the arm since the gate sits after dispatchCommand()).
- M4 docs: gen-settings-doc.php regen of docs/SETTINGS.md + guards (SettingsSchemaDocDrift?/DocFigureProseDrift settings count, ReadmeRosterDrift if Interface roster lists keys — verify in-step).

## Gate notes

- Staleness pair (ReadmeSuiteFigureDrift) expected-red, closeout re-pin carry: 2 new test files, figure owned by gate.
- DUR: two new test files need durations.tsv rows at closeout (never touched in-lane).
- CO-TENANT compliance: Chat.php hunks touch resume/submit/ctor/mutate/dispatchTurn regions only — NOT palette/insights (C-SES-8.d), NOT incognito (C-SES-7.c), NOT session-import arm (C-SES-7.b). lang/en.php rows are new keys only. SETTINGS.md regen is my step's declared file.

## M3 tests — commit 3a7b8c511 (tree clean, cat-file'd)
- tests/Host/ResumeCostEstimateTest.php: 13T — fixed epoch clock (idle 299 silent / 300 fires TTL edge), priced ≈ math (84_010 tok, $0.005/k → ~$0.42, '84k'), unpriced ≥ shape, warnUsd<=0 disables both ways, empty history silent, unknown-activity cold-vs-priced-light pairing, calibration multiplies before threshold (0.1 knob: 37510 silent / 75020 fires).
- tests/Chat/ResumeCostGuardTest.php: 7T through real update(KeyMsg Enter) — stale resume holds ONCE (notice UI-only, '(≥$0.25)', draft kept, armed disarms), second Enter sends (notice exactly 1, history[$before]='hello'), enhanced-store stale holds + warm(-90s) sends straight (proves meta read both polarities), fresh launch never holds, /help dispatches without spending the arm (gate after dispatchCommand proven: arm survives command, real 'hi' draft then holds), switchToSession arms via reflection + /new-clone re-derives to false (identity choke).
- 20T/65A green in one run; filter 'ResumeCost' = 21T (incl unrelated dataset row).
- Deviation (honest): the priced `≈` notice leg is NOT Chat-wiring-test-reachable on this box — ModelMetadata has no built-in price for bare model ids and the guard calls ModelMetadata::new() (config/cache-driven). ≈ arithmetic pinned at estimator level instead; wiring pins the unpriced ≥ path. EchoBackend deliberately = the unpriced shape.
- Latent-fatal repair re-proof: mutate() clone tests green (the $constructorProps splat needs the ctor param this lane added — MutateCarryCensusTest 194T sweep green).

## M4 gates — final
- gen-settings-doc --check: up to date.
- Filters green: SettingsSchemaTest 46T; combined 'MutateCarryCensus|SettingsSchemaDocDrift|DocFigureProseDrift|ConfigWriteProducerDocDrift|TrustKeyDocDrift|SettingsSchema|SessionSwitch' 194T/7496A; resume-adjacent 2T; own 20T. 2 PHPUnit deprecations pre-existing at base.
- Carries (closeout, never in-lane): DUR rows for the 2 new test files; suite-figure staleness pair re-pin (+20T expected-red); protocol trio untouched; no web legs (no npm/dist).
