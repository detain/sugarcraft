# C-WEB-4.f — Branch badge (SessionSummary.branch) — LANDED

Commit: `8d39a7ca0` on `cc/C-WEB-4.f` (base 978ff2889), tree clean, never pushed.
Author/committer both detain@interserver.net (%ae==1, %ce==1, zero Co-Authored).

## Shipped
- `candy-code/src/Protocol/Schema/Definitions.php`: SESSION_SUMMARY gains nullable
  `branch` (after `snippet`), comment states the branch-at-session-start semantics; required list unchanged.
- `candy-code/src/Protocol/ServerContext.php` `summary()`: `'branch' => $row['git_branch'] ?? null` —
  pure row read, no git on the serve path (GAP-GIT-01 risk honored).
- `candy-code-web/src-web/components/StatusBar.vue`: `⎇ <branch>` chip after the model item,
  `v-if="summary?.branch"`, title "Branch at session start: <branch>".
- `candy-code-web/src-web/components/SessionSidebar.vue`: meta segment `⎇ <branch>` (data-testid
  session-branch) between spend and root chips, same presence door and tooltip.

## Tests
- NEW `candy-code/tests/Protocol/SessionSummaryBranchTest.php` — 4T/8A OK (ProtocolFixture:
  row fill, null leg, session.list wire leg, open-host no-fabrication leg).
- NEW `candy-code-web/src-web/components/__tests__/branchBadge.spec.ts` — 5 passed (vitest serial).
- Mutation proofs: (1) ServerContext fill line deleted → 3F (restore md5-verified c98f1ec4…);
  (2) both components forced always-render → exactly 3 spec reds (chip-only-when-present pins;
  restore md5 d52e5fdb/4e2c26be).
- Gates: tests/Protocol dir 131T OK; ProtocolSchemaDriftTest 7T OK; ServerProtocolDocumentationDriftTest 6T OK;
  statusBar/sidebar component specs 52 passed; generated.spec.ts + dormantRoster.spec.ts 6 passed.
- tests/Server dir: 386T with 3 failures = AttachPagingTest x2 + WorkspaceHostTest gateway leg —
  BASE-REPRODUCED (lane work reverted, identical 3F), disclosed not ours.
- vue-tsc --build --force: 32 errors measured at the lane tip as committed (the committed generated.ts was stale — the 12 step-file TS2339s are exactly the missing branch field); after the welder regenerates the protocol trio on the merged tree the error set returns to the standing 20, delta-empty vs the base family, none in this step's files (weld-verified 2026-10-28).

## §1.8 protocol hygiene
`gen-protocol-schema.php --write` + `npm run gen:protocol` run locally (schema.json +6 lines,
generated.ts +1 line, SERVER.md untouched); guards run against them; the 2 changed generated
files then RESTORED — not committed. Welder regenerates on the merged tree. dist not built/committed.

## Owed
- DUR rows: `candy-code/tests/Protocol/SessionSummaryBranchTest.php` (new phpunit file; branchBadge.spec.ts
  needs no TSV row — vitest, not a candy-code *Test.php).
- Generated-trio regen at weld; COMPARISON C-WEB-4 cell flip at closeout (§7).
- Docs: candy-code-web/README.md carries no SessionSummary field list — no soft doc row owed (verified).
