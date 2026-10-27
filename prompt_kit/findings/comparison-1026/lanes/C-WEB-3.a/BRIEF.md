### C-WEB-3.a — Memory History & Undo (`memory.log`/`memory.undo`)
- item: C-WEB-3 (sources: k1-dorm#4, k3-dorm#3, WEB-1-P1, p2-miss: WEB-1 residual dormant methods)
- kind: dormant-wire · S
- approach:
  - **Gap.** `memory.log` and `memory.undo` (`candy-code/src/Protocol/MethodSchemas`/`MemoryMethods.php:70-71`) have no browser caller.
  - **Store.** `stores/agents/memory.ts` gains `log(limit)` and `undo(revision?)`.
  - **Panel.** `MemoryPanel.vue` gains a History tab beside the scope tabs, listing `shortSha · committedAt · subject` as plain text spans. Undo to a revision is shown only with `write` scope, behind a confirm. After an undo, the current scope list reloads.
- files.create: `candy-code-web/src-web/components/panels/__tests__/memoryHistory.spec.ts`
- files.modify: `candy-code-web/src-web/stores/agents/memory.ts`, `candy-code-web/src-web/components/panels/MemoryPanel.vue`
- tests: create `candy-code-web/src-web/components/panels/__tests__/memoryHistory.spec.ts`. It pins three things:
  - History lists `memory.log` rows;
  - Undo sends `memory.undo {revision}` and reloads;
  - Undo is hidden without write scope.
- docs/guards: `candy-code-web/README.md` side-panel bullet (soft, see Notes); vitest; dist rebuild.
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: `memory.log` commits pending changes first (its schema says so). The tab should load on open, not on every panel mount.

