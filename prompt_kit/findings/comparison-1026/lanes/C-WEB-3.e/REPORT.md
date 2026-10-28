# C-WEB-3.e — Share link / Revoke on the session row

VERDICT: DONE. Dormant `session.share`/`session.unshare` now have web callers: row menu "Share page…"
(dialog shows origin+token path, UTC expiry, clipboard copy — plain interpolation, zero v-html),
per-live-link revoke item fed by the server's cross-session `session.shares` listing (prefix shown,
full token sent), all write-scoped items hidden without the `write` scope. Token never touches
localStorage/sessionStorage — in-memory listing is the record; delete needs no client revoke.

Files (worktree /home/sites/cc-cmp-C-WEB-3.e, branch cc/C-WEB-3.e, ONE commit):
- candy-code-web/src-web/stores/sessions.ts — sharesBySession map, loadShares (hello +
  session.deleted + fire-and-forget after share/unshare), share(sessionId, days?), unshare(token); exports SessionShareRow.
- candy-code-web/src-web/components/SessionSidebar.vue — canWrite gate, share/unshare menu items,
  share dialog (data-testids share-dialog/share-url/share-expiry/share-copy/share-close), Esc closes dialog.
- candy-code-web/src-web/components/__tests__/sidebarShare.spec.ts — NEW, 4 tests.
- candy-code-web/README.md — Sessions sidebar bullet extended.

Figures (vitest serial --no-file-parallelism, env -u LINES -u COLUMNS, cwd=candy-code-web):
- sidebarShare.spec.ts: 4/4 pass; cp-harness at base 23fa8ab8e: 4/4 FAIL (failing-before proven).
- Neighbor window (sidebar/sidebarActions/sidebarCloseRoot/picker/panels): 29/29 pass.
- vue-tsc: zero errors in my files; 2 PRE-EXISTING errors in src-web/stores/contextSources.ts
  (foreign lane f90523394 base dirt, untouched, disclosed).
- eslint on the 4 paths: clean. Protocol/schema surfaces untouched; no dist, no timers added (E646).

Notes: idempotencyKey rides the wire from the protocol client's auto-retry layer, not the store
(specs assert via toMatchObject accordingly). New spec file needs a durations row at GATE (web side).
