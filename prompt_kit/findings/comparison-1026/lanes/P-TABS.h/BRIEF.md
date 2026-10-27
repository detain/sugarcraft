### P-TABS.h — Web: `+` new-session tab and unseen badges
- item: P-SESSION-TABS (sources: GAP-UI-N6 web half; owner pick)
- kind: feature · effort S
- approach:
  - The web tab strip (`components/grid/SessionTabs.vue`) already streams every session and badges asks, but has no `+`.
  - Add a trailing `+` tab → `useNewSessionStore().start()` (picker-aware), then route to the new session.
  - Add an unseen-finished badge: `layout.ts` records a non-active session whose status goes busy→idle, and clears it on select.
- files.create: none
- files.modify: `candy-code-web/src-web/components/grid/SessionTabs.vue`, `candy-code-web/src-web/stores/layout.ts`
- tests: create `candy-code-web/src-web/components/grid/__tests__/sessionTabsNew.spec.ts` (`+` starts a session; unseen set and cleared); modify `candy-code-web/src-web/stores/__tests__/layout.spec.ts`
- docs/guards: web unit tests
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

