# C-WEB-3.a REVIEW-1 (round 1, reviewer fresh)

VERDICT: FINDINGS — 1 MINOR (step-local), everything else clean.

- Scope: diff == exactly the four declared files (memory.ts, MemoryPanel.vue, NEW memoryHistory.spec.ts, README bullet); no dist/, no generated.ts, no dormantRoster touch. Chain verified: single 0077bb9b0 on 86f43fae2, tree clean, branch cc/C-WEB-3.a.
- Author law: %ae=%ce=detain@interserver.net (separate greps), zero Co-Authored trailers.
- RPC honesty: log(limit?)/undo(revision?) match generated.ts schemas (params :774, :800); memory.undo ∈ IDEMPOTENT_METHODS and client.ts:162-166 auto-injects the 32-hex key — store passes none, identical to siblings memory.add/edit/delete. Undo reload is real (store.undo -> reload(), query-aware).
- Panel: history tab in the tablist; rows are plain {{ }} interpolation (grep v-html: zero); loads on click (openHistory), onMounted loads only notes; undo v-if=canWrite + window.confirm; error/notice <p> moved above the tab branch so history failures surface (in-file, justified).
- Pins: wire.sent reads the serialized frame (post-injection), test 3's rows==2 arm prevents vacuity; mutation-reason confirmed for mount-load(:43), list(:49-53), confirm(:65), params(:66-68), reload(:72), scope-hide(:86). Vitest REPRODUCED on this tree: `npx vitest run --no-file-parallelism` memoryHistory+stores.spec+panels.spec -> 3 files/23 tests passed.
- vue-tsc DENIED in this sandbox; claim structurally supported — lane diff touches neither statusBar.spec nor reducer.spec, so the 6 TS2554 set cannot be lane-introduced.
- README bullet truthful to shipped behavior. DormantRoster seam disclosed correctly in REPORT (file absent at base; C-WEB-3.g owns it; §1.8 row-drop lands with that lane).

[MINOR step-local] MemoryPanel.vue:55 — confirm text "Every note is replaced by that commit's state." overstates: memory.undo restores the HOME store only (schema :799, store docblock :87); repo-store notes in the project tab are untouched. Fix: "...the home store's notes are replaced by that commit's state."
