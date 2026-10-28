
# FIX-1 — round-2 (MAJOR: bag keyed by full deriveKey)

VERDICT: FIXED. Gate results at tip: 'StartupSummary' 24T/475A OK (23+1 new pin); 'StartupSummaryGatherTest|RouteTraitParity|AppModel' 71T/820A OK (assertions +18 vs review's 802 — structural keystone roster gained the deriveGatherKey slice, tests unchanged); 4-way union 'StartupSummary|Gather|RouteTraitParity|AppModel' 90T/1029A OK.

## Change
- src/Support/StartupSummary.php: NEW private deriveGatherKey(Chat) = sessionId . "\0" . projectRoot. render() ($expensive lookup) and store() now key self::$gathered by it; the $cache frame memo keeps the full deriveKey (mode/provider/model repaint correctly). store() invalidation widened from one full-key unset to every memoized frame under $bagKey . "\0" — a re-gathered fact stales ALL frames of the session+root, not just the current one (this is the bag-key scope completed at the invalidation site, not a refactor). Docblock truths flipped: $gathered property ("keyed exactly like $cache" → session+root), store(), render() ("bag for this key" → "session+root").
- src/Chat/Handlers/HandlesRunControl.php: comment-only — "(store() also drops this key's memoized frame)" → frames of session+root.
- tests/Chat/StartupSummaryGatherTest.php: paint-path keystone $paintSlices roster += deriveGatherKey (new method stays clean of all 16 forbidden tokens).
- tests/Support/StartupSummaryTest.php: NEW pin testTheGatheredBagSurvivesAPermissionModeFlipWithoutReGather — gated Chat (PlanModeToggleTest's PermissionGate/HookManager/EngineBackend pair), land StartupSummaryGatheredMsg(branch=delta) → full rows under default; PermissionModeToggledMsg(Plan) with NO re-gather → header repaints "· plan" AND place row keeps "· delta" (the MAJOR); then land bag(epsilon) while in Plan, flip back to Default → "· epsilon" and NOT "· delta" (prefix-invalidation leg).

## Mutation proofs (cp+md5 restore, bf24f37f…)
- M1 render lookup reverted to full $key → 4 reds (new pin + land/render pair) — bag/frame key mismatch loudly caught.
- M3 store keeps bag-key write but reverts to single current-full-key unset → exactly 1 red: the new pin's stale-frame leg. Both fix halves individually pinned.

## Weld notes (UNCHANGED from REVIEW-1, recorded for the welder)
- Cherry conflict vs master 27757dd94 (C-PRV-5.c) on tests/Chat/RouteTraitParityTest TelemetryRetryTickMsg hunk IS CONFIRMED. Union resolution: keep MASTER's ModelCatalogReadyMsg + master's TelemetryRetryTickMsg line; add the lane's StartupSummaryGatheredMsg entry after StatusLineTickMsg. Do NOT re-resolve here — welder does. (This fix commit did not touch RouteTraitParityTest; conflict shape identical.)
- StartupSummaryGatherTest.php durations.tsv row remains OWED at weld (lane forbids the file — untouched).

## Laws check
Author split-var detain both greps, zero trailers; no stash; suite-figure/durations/dist untouched; E646: zero timers; no new spawn/emitter/sprintf (censuses unaffected — RouteTraitParity + AppModel re-run green); GlobDialect: no glob-shaped test literals (uniqid paths, no wildcards).
