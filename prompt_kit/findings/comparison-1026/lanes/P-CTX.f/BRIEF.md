### P-CTX.f — Protocol: `context.sources` categories
- item: P-CTX-BREAKDOWN (sources: owner pick; PRM-12 web)
- kind: feature · effort S
- approach:
  - `ContextSources::serialize()` (`src/Host/ContextSources.php:217-233`) adds `categories: [{key, label, tokens|null, share, sub:[{label, tokens}]}]` plus `buffer` and `mcpServers`.
  - The `context.sources` breakdown schema (`src/Protocol/Schema/Definitions.php:230-278`) gains them as optional members, so older clients still validate.
  - Regenerate the schema JSON, SERVER.md and `generated.ts`.
- files.create: none
- files.modify: `candy-code/src/Host/ContextSources.php` (`serialize()`), `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/docs/protocol/candy-code.v1.schema.json` (gen), `candy-code/docs/SERVER.md` (gen), `candy-code-web/src-web/protocol/generated.ts` (gen)
- tests: modify `candy-code/tests/Protocol/ContextSourcesTest.php` (categories shape from a real session fixture)
- docs/guards: `tools/gen-protocol-schema.php --check`, `npm run gen:protocol`, `tests/Config/ServerProtocolDocumentationDriftTest.php`, `tests/Protocol/ProtocolSchemaDriftTest.php`
- serialized: [protocol] [docs:SERVER.md]
- deps: P-CTX.a
- phase: normal
- ruling: none
- risks: C-MCP-4 (user-tier MCP rows) edits `ContextSources::answer()` in the same file. Keep this to `serialize()`.

