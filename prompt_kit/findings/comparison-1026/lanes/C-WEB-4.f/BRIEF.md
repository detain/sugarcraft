### C-WEB-4.f — Branch badge (`SessionSummary.branch`)
- item: C-WEB-4 (sources: GAP-GIT-01)
- kind: gap · S
- approach:
  - **Gap.** The `sessions` row already stores `git_branch` (`candy-code/src/Session/SessionStore.php:271,311,776`), but `SessionSummary` (`src/Protocol/Schema/Definitions.php:44-60`) does not carry it.
  - **Server.** Add a nullable `branch`, filled by `ServerContext::summary()` from `$row['git_branch']`. That is the branch the session started on; there is no live `git` call on the serve loop.
  - **Client.** The status bar shows a branch chip, and the sidebar row meta shows `⎇ branch`.
- files.create: `candy-code/tests/Protocol/SessionSummaryBranchTest.php`, `candy-code-web/src-web/components/__tests__/branchBadge.spec.ts`
- files.modify: `candy-code/src/Protocol/Schema/Definitions.php` (SESSION_SUMMARY), `candy-code/src/Protocol/ServerContext.php` (`summary()`), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/components/SessionSidebar.vue`
- tests: create `candy-code/tests/Protocol/SessionSummaryBranchTest.php`: the summary carries the row's branch, and null when unset. Create `candy-code-web/src-web/components/__tests__/branchBadge.spec.ts`: the chip appears only when branch is non-null, and as plain text.
- docs/guards: the protocol gates; `candy-code-web/README.md` (soft).
- serialized: [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/components/SessionSidebar.vue]
- deps: none
- phase: normal
- ruling: none
- risks: The tooltip must say "branch at session start", or it reads as live state after an agent `git checkout`.

## C-WEB-5 — Remote serve: TLS & push (LATE)

No non-LATE sliver qualifies:
- C-WEB-5.g is S and client-only, but it flips a deliberate-dormant decision, which needs a ruling.
- C-WEB-5.f is M.
- Every other step is M and under R13.

