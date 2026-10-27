# C-SES-3.c — DONE · commit 37235db0c on cc/C-SES-3.c (base 2e1ef6430; tree clean, one commit)

`session.shares {sessionId?}` registered (Scope Read, no side effects) over ShareStore::liveAll();
per-session = filtered listing, absent id = all sessions. Rows: token (full, token-scoped like
session.share's answer), tokenPrefix (const SHARE_TOKEN_PREFIX_CHARS=8), path, format, expiresAt,
sessionId, sessionName. Unknown sessionId → empty list (documented: listing states the shares
table; deleted sessions' shares are revoked with them).

Files: src/Protocol/Methods/SessionMethods.php (register + shares() + const + class-doc flip
"serve, list and revoke"), src/Protocol/Schema/MethodSchemas.php (row + SessionMethods import),
tests/Protocol/ShareMethodsTest.php (+4 tests, seedSession gains ?name), candy-code-web
src-web/protocol/__tests__/generated.spec.ts (scope pin).

Regen run: php scripts/gen-protocol-schema.php --write → docs/protocol/candy-code.v1.schema.json
+ docs/SERVER.md; npm run gen:protocol → src-web/protocol/generated.ts. All three RESTORED
before committing per §1.8 — weld regenerates.

Gates: --filter 'ShareMethods|ProtocolSchemaDrift|ServerProtocolDocumentationDrift' on the
regenerated tree: OK 21T/376A (2 pre-existing AccessorDeprecationTest deprecations). Web
`npx vitest run --no-file-parallelism src-web/protocol` regenerated: 4 files, 38/38. Five-guard
filter: 133T, only-red = ReadmeSuiteFigureDrift staleness pair (live 25,600 vs pinned 25,537;
base already stale, lane adds +4T). HookWebhooks sweep green. Post-restore expected reds:
ProtocolSchemaDrift, ServerProtocolDocDrift, new scope pin — judged on welded tree (§1.8).
Regression proof: dropping the registration → exactly the 4 new tests error.

Deviations: brief's base sha "2e1efc430" is a typo for 2e1ef6430. Spawn note said §1.8 MAY
commit generated files; §1.8 verbatim says restore them — restored.
Dormancy: session.shares has no browser caller until C-WEB-3; dormantRoster.spec.ts does not
exist yet (C-WEB-3.g unlanded) — record it in C-WEB-3's roster when that file is created.
