# C-WEB-4.d REVIEW-1 (r1) — verdict: FINDINGS (1 MINOR step-local)

Lane cc/C-WEB-4.d @ 416fee321 (parent 24d746501). Reviewer cwd=candy-code-web, vitest --no-file-parallelism.

## Gates reproduced
- vue-tsc --noEmit: rc=0.
- Targeted (turnTimer.spec + statusBarTimer.spec + reducer.spec): 44/44 pass.
- FULL serial vitest: 601 passed (601) — builder claim exact.

## Checks
- File set == brief: reducer.ts, StatusBar.vue, 2 new specs, README status-bar bullet. dist/** also committed (index js/css rename, index.html, manifest) — plan §2 makes dist weld/gate-only ("A lane never commits them"); per run protocol this is WELDER-CARRY (weld rebuilds once per wave; the committed dist matches the lane src — turnStartedAt present in bundle), NOT filed as a finding.
- Reducer: diff touches ONLY the field docblock + createState init + the turn.started/turn.completed arms (risk line honored). `turnStartedAt = envelope.ts` matches EventEnvelope.ts truth (generated.ts:209 "Milliseconds since the epoch"). Out-of-order reasoning: completed-after-completed = null no-op; started-after-completed = re-stamp for the new turn (pinned, test 5); mid-turn frames never move the stamp (pinned). applySnapshot untouched → page-reload resume hides (brief contract), reconnect-mid-same-turn keeps counting (stale-stamp-across-turn-flip reads inflated for one turn — reachable only when the turn changes during a disconnect gap; disclosed deviation, defensible design, NIT note only).
- StatusBar: interval arms ONLY under busy∧start via watch(immediate); watch-driven stopTick on false-edges; onScopeDispose(stopTick) covers unmount-while-busy — both leak paths pinned by dedicated tests (liveTicks tracked through a 1000ms-handle spy — Wire-store housekeeping-timer flake avoided, deviation (1) sound). m:ss math: 65s→"1:05" pinned (59→60 leg proven); minutes unpadded so 9:59→10:00 is trivially the same floor/mod — acceptable un-pinned.
- Vacuity: neutering the stamp arm ⇒ reducer tests 2/5/6 + StatusBar tests 2/3/4/5/7 = 8 reds (computed, matches builder's mutation claim); removing the watch ⇒ arm/unmount/step pins all fail. completed-test's session.status follow-up frame is honest wire reality (reducer never drops status itself), disclosed (2).
- Author law: %ae/%ce detain@interserver.net, zero Co-Authored. No glob-shaped/refused-shaped test literals (§1.9 clean). §1.8: no PHP guard touched; README bullet soft-doc matches change.

## MINOR-1 (step-local) — comment polarity inverted (both sites)
reducer.ts field docblock: "A server clock BEHIND ours reads as 0:00 until real time catches up (the display clamps)" and StatusBar.vue elapsedLabel comment: "Server clock behind ours (skew): clamp at 0:00 until real time catches up". The clamp `Math.max(0, tickNow − start)` only engages when start > tickNow, i.e. the server ts is in OUR future = server clock AHEAD. A server clock behind makes elapsed read INFLATED immediately (never 0:00). The spec's own test title has it right: "clamps a server clock ahead of ours". Fix (both files, comment-only): "A server clock ahead of ours (stamp in our future) reads as 0:00 until real time catches up (the display clamps); behind reads inflated by the skew." §1.6: step-local minor must land before weld.
