# C-WEB-4.d — Elapsed turn timer in the status bar — CLOSED

Commit: 416fee321 on cc/C-WEB-4.d (base 24d746501), never pushed. Tree clean; author/committer detain@interserver.net verified on separate greps; zero Co-Authored trailers.

## Shipped
- `stores/reducer.ts` (+15 diff lines): `turnStartedAt: number | null` on SessionState (docblock), `createState` init (mechanically required by TS), stamped `= envelope.ts` in the `turn.started` arm, cleared in the `turn.completed` arm. Exactly the two arms + one field (+init). `applySnapshot` untouched — a fresh state + snapshot resume therefore leaves the stamp null, which IS the "hidden, not guessed" contract; pinned in both specs.
- `components/StatusBar.vue`: 1 s `setInterval` armed by a watch on `status === 'busy' && turnStartedAt !== null` (immediate), cleared when the gate flips off and via `onScopeDispose`; `statusText` busy branch now joins `working`, elapsed `m:ss` (clamped ≥0 for server-clock skew), and the existing `step n/m`.
- New specs: `stores/__tests__/turnTimer.spec.ts` (7 tests), `components/__tests__/statusBarTimer.spec.ts` (7, fake timers).
- README status-bar bullet flipped in the same commit (soft doc, no guard owns it).
- dist rebuilt and staged in the same commit (asset renames + index.html + manifest.json).

## Gates (all serial, --no-file-parallelism)
- New + neighbors (turnTimer, reducer, statusBarTimer, statusBar, statusBarTrustBadge, session): 80/80 pass.
- FULL web suite: 77 files / 601 tests pass.
- `npx vue-tsc --noEmit` rc=0.
- Mutation M1: stamp arm neutered (`= envelope.ts` → `= null`) → exactly 8 reds across both new specs; cp-restore → 14/14 green (backup at /tmp/opencode/C-WEB-4d/).

## Notes / deviations
- Timer tracking in the spec uses a spy filtered to 1000 ms handles: the Wire-backed connection store arms background housekeeping timers, so global `vi.getTimerCount()` is noisy (observed and worked around).
- `turn.completed` does not itself clear busy — status drops on the following `session.status` frame (reducer.spec precedent at :210). Between the frames the bar reads plain `working` with the timer correctly stopped; pinned that way.
- Skew law documented in the field docblock + pinned (future-ts clamps to 0:00).
- Never reset `turnStartedAt` in `applySnapshot` per the serialized-lane law (2 arms + field only): on a resync the previously-observed start of the SAME in-flight turn stays honest; a cold reload starts null.
- No PHP touched; no suite-figure.json / durations.tsv staged. No base-reds observed.
