### C-SES-3.c — `session.shares` read RPC (for C-WEB-3's menu)
- item: C-SES-3 (sources: p2-miss: `session.shares` RPC + web share UI, SES-3-P4)
- kind: fix · MAJOR · effort S
- approach: Add `session.shares {sessionId?}`, Scope Read. It returns the live shares of one session, or of every session when `sessionId` is absent. Each row carries a token prefix, the full token (the method is token-scoped, the same as `session.share`'s answer), path, expiry, format and session name. C-WEB-3's Share/Revoke menu consumes it alongside the existing `session.share` and `session.unshare`.
- files.create: none
- files.modify: `candy-code/src/Protocol/Methods/SessionMethods.php` (register + shares()), `candy-code/src/Protocol/Schema/MethodSchemas.php` ('session.shares'), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/protocol/__tests__/generated.spec.ts` (scope pin)
- tests: modify `candy-code/tests/Protocol/ShareMethodsTest.php`: per-session and all-sessions listings, and a revoked share leaves the list.
- docs/guards: the generation chain; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts`
- serialized: [protocol] [docs:SERVER.md (regen only)]
- deps: C-SES-3.a
- phase: normal
- ruling: none
- risks: This adds a web-uncalled method until C-WEB-3 lands. Record it in C-WEB-3's dormancy roster.

