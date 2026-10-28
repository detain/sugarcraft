# C-WEB-4.c — REVIEW-1 (round r1) — VERDICT: CLEAN

Lane cc/C-WEB-4.c @ 22ddc7c65 (parent eed6630d3). Author %ae/%ce both detain@interserver.net, zero Co-Authored trailers, commit object real, tree clean.

## Scope / file set — PASS
Exactly the brief set: created transcriptView.ts + transcriptToggles.spec.ts; modified Transcript/ReasoningFold/ToolCard + README bullet (soft), dist (step docs/guards exception), components.spec.ts (declared §1.8 in-step flip: top-level beforeEach setActivePinia, imports pre-existing, no assertions changed; StatusBar describe's own beforeEach at :205 left redundant — harmless). Generated trio (generated.ts, schema, SERVER.md) + suite-figure/durations NOT in diff.

## Virtualisation core judgment — PASS
ToolCard: `open = ref(settledOpenByDefault || view.expandTools)` at setup + `watch(view.expandTools)` re-applying while `toggledByViewer` false; head click sets stickiness then flips. Transcript rows key `String(row.key)` (virtual-item key, append-only transcript) → recycling unmounts/remounts → setup re-reads the CURRENT store; live instances follow via the watch. No stale one-time-init copy: the init is per-mount and the overlay is a local flag, exactly the required shape. ReasoningFold binds `live || view.showThinking` directly — reactive, no copy at all.

## Pins — non-vacuous (read + reasoned, no edits)
- Flip-every: real store transitions with nextTick both directions.
- Error/denied-open regardless: expandTools is false at that leg, so dropping settledOpenByDefault reddens it — discriminating.
- Manual-sticky: both flip directions pinned, untouched card follows, hand-toggled card holds.
- Persistence: Transcript click → unmount → FRESH pinia → fresh ReasoningFold/ToolCard/Transcript read localStorage and open; exercises the storage round-trip of the same components (not a different fixture kind). NIT: the same-pinia recycle leg is structurally trivial (setup re-reads the live store) and so is unproven by an actual Transcript-row recycle — acceptable given the builder's documented happy-dom 0px-measure env limit (rows vanish on any re-render; direct-mount spec shape forced).
- Components.spec cross-bleed: no transcriptView writes before reads in that file; vitest per-file isolation; new spec clears localStorage per test. Store uses storage.ts wrapper only (namespaced, guarded, strict-true read). PASS.

## Gates (re-verified by reviewer)
- Targeted 2-file serial: 31/31 pass. FULL serial `npx vitest run --no-file-parallelism`: 76 files / 593 tests passed, rc=0 (matches builder).
- vue-tsc base-red proof CONFIRMED: /tmp/opencode/vue-tsc-CWEB4c-{base,tip}.txt both 54 lines, sorted 'error TS' sets byte-identical (40 errors), zero in lane-touched files.

## Carries (not lane findings)
- WELDER FLAG: lane dist hunks were built at base eed6630d3; master tip carries da284586a's dist+trio regen → do NOT trust this pick's dist/** hunks — resolve by `npm run build` on the merged tree, then re-check the trio stays untouched by this pick (it adds no src changes).
- CLOSEOUT CARRY: §7 step 3 owns the generated.ts protocol-chain desync (gen:protocol + byte-clean) which covers the shells.*/session.contexts TS2305/2345/2339 families; the remaining TS2532/TS2554 spec-arity families are pre-existing and owned by NO plan leg (no vue-tsc leg in §7) — surface to owner at closeout.

VERDICT: CLEAN — 0 critical / 0 major / 0 minor / 1 nit (recycle-leg pin shape, env-forced, disclosed). Weld after dist rebuild on merged tree.
