### C-WEB-4.d — Elapsed turn timer in the status bar
- item: C-WEB-4 (sources: GAP-UI-31)
- kind: gap · S
- approach: Client-only.
  - **Reducer.** `stores/reducer.ts` records `turnStartedAt = envelope.ts` on `turn.started` (:416-425) and clears it on `turn.completed`.
  - **Status bar.** `StatusBar.vue` shows `working · 0:42` (with `· step n/m` when known), ticking on a 1 s interval that runs only while busy and is cleared on idle or unmount.
  - **Snapshot resume.** A resumed snapshot mid-turn has no start, so the timer stays hidden rather than guessing.
- files.create: `candy-code-web/src-web/stores/__tests__/turnTimer.spec.ts`, `candy-code-web/src-web/components/__tests__/statusBarTimer.spec.ts`
- files.modify: `candy-code-web/src-web/stores/reducer.ts` (`turn.started`/`turn.completed` arms, SessionState field), `candy-code-web/src-web/components/StatusBar.vue` (`statusText`)
- tests:
  - Create `candy-code-web/src-web/stores/__tests__/turnTimer.spec.ts`: start and clear on the right events; a snapshot resume leaves it null.
  - Create `candy-code-web/src-web/components/__tests__/statusBarTimer.spec.ts` (fake timers): it ticks only while busy.
- docs/guards: `candy-code-web/README.md` status-bar bullet (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/stores/reducer.ts]
- deps: none
- phase: normal
- ruling: none
- risks: reducer.ts is a cross-area hot file (P-AGENT-ATTACH, C-WEB-7), so keep the edit to two arms and one field.

