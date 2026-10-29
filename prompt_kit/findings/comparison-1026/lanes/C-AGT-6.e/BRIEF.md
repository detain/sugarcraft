### C-AGT-6.e — PR status chips in the web
- item: C-AGT-6 (sources: p2-miss: WEB-8 PR chips)
- kind: gap · effort M
- approach:
  - For a session whose branch C-WEB-4 already exposes, the server reads `gh pr view <branch> --json number,state,url,statusCheckRollup` through `GhRunner`. It is cached per branch for 60 s and refreshed lazily on summary build, never on the turn path.
  - The result goes into a nullable `SessionSummary.pr {number, state, url, checks}`.
  - `StatusBar.vue` and the `SessionSidebar.vue` row render a chip linking to the PR, with a pass/fail/pending dot.
  - Gated on R13.
- files.create: `candy-code/src/Workspace/PrStatusCache.php`
- files.modify: `candy-code/src/Protocol/Schema/Definitions.php` (SessionSummary `pr`), `candy-code/src/Protocol/ServerContext.php` (`summary`), `candy-code/docs/protocol/candy-code.v1.schema.json` + `candy-code/docs/SERVER.md` + `candy-code-web/src-web/protocol/generated.ts` (generated), `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/components/SessionSidebar.vue`
- tests: create `candy-code/tests/Workspace/PrStatusCacheTest.php` (fake `gh`: cached within TTL; failure → null, never throws); modify `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`, `candy-code-web/src-web/components/__tests__/sidebar.spec.ts`
- docs/guards: protocol gen chain checks + `ServerProtocolDocumentationDriftTest`; rebuild `candy-code-web/dist/` (weld)
- serialized: [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/components/SessionSidebar.vue] [docs:SERVER.md]
- deps: C-AGT-6.d, C-WEB-4 (its GAP-GIT-01 `branch` on SessionSummary)
- phase: normal
- ruling: R13 (as C-AGT-6.d, plus showing CI status pulled with the user's GitHub token in a browser client)
- risks: the summary build must stay non-blocking. The `gh` call runs off the summary path (cached, refreshed on a timer), or a slow `gh` stalls `session.list`.

---

## C-AGT-7 — Automation minor/nit cleanup

