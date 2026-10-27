# C-SES-2.c round 1 — VERDICT: FINDINGS (1 MINOR step-local)
- MINOR step-local — candy-code/src/Host/SessionHub.php:186-193: old docblock left stacked above the new C-SES-2.c block on list(). Fix: delete lines 186-193.
Gates (lane tree 74dafe7c3, plain pipe):
- PHP filter SessionMethods|SessionHub|ServerContext|ProtocolSchemaDrift|ServerProtocolDocumentationDrift: 21T/384A, 1F = only testTheSchemaFileAndTheServerDocTablesAreCurrent staleness arm; its diff shows exactly the three declared additions → §1.8-compliant, judged on welded tree.
- New tests/Server/SessionListBodySearchTest.php: 4T/17A OK (body-only match = true regression discriminator vs old str arm).
- Five-guard: 133T/15348A, 2F = staleness pair only (live 25,658 vs pinned 25,537 — closeout re-pin duty, not the lane's).
- vitest serial src-web/stores + src-web/components/__tests__: 219 passed / 28 files.
Verified: ≥ latch never un-latches, no silent zero; same-txn cost cache → honest closed-session spend; query max 256 + matchExpression/LIKE-degrade fail-safe (no throw from remote input); toArray carries cost/preview so both hub paths summarize alike; cursor law pinned; dist/ and generated trio NOT committed; scope = brief file list exactly; author Joe Huss detain@interserver.net (%ae==%ce), zero Co-Authored; tree clean.
