# C-WEB-3.e — Share link / Revoke on the session row
- area: web | kind: dormant-wire · S | phase: normal
- item: C-WEB-3 (sources: k1-dorm#5, SES-3-P4, WEB-1-P1, p2-miss: cross-session web share UI)
- deps: {st.get('deps','')}
- serialized: {st.get('serialized','')}
- ruling: none
- approach:
{st.get('approach','')}
- files.create: `candy-code-web/src-web/components/__tests__/sidebarShare.spec.ts`
- files.modify: `candy-code-web/src-web/stores/sessions.ts` (`share`, `unshare`, listing via C-SES-3's method), `candy-code-web/src-web/components/SessionSidebar.vue` (row menu + share dialog)
- tests: create `candy-code-web/src-web/components/__tests__/sidebarShare.spec.ts`. It pins: share shows the URL; revoke sends the token; the items are hidden without write scope; no token reaches storage.
- docs/guards: `candy-code-web/README.md` sessions-sidebar bullet (soft); vitest; dist rebuild.
- risks: If C-SES-3 ships no listing RPC, Revoke can only offer the link minted in this tab. Re-scope then instead of persisting tokens client-side.

(Extracted verbatim from schedule.json by lead — full design text in the referenced design file.)