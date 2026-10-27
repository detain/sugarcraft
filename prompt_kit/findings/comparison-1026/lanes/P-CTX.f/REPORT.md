# P-CTX.f — Protocol: `context.sources` categories — LANDED

- Commit: `827e37702` on `cc/P-CTX.f` (base b24e81154), author/committer detain@interserver.net, no trailers, not pushed.
- Files: `candy-code/src/Host/ContextSources.php` (+36), `candy-code/src/Protocol/Schema/Definitions.php` (+23), `candy-code/tests/Protocol/ContextSourcesTest.php` (+117/−1). 175 insertions total.

## What landed
- `serialize()` adds `categories` (one row per `ContextCategory` in case order:
  `key` = case value, `label` = `Lang` label, `tokens` nullable, `share` =
  whole percent of `window` (null exactly when tokens is; window≤0 → null),
  `sub` rows carrying label/tokens/bytes/personal/note — the P-CTX.a data
  serialized faithfully, one `ContextCategories::from()` call, no re-measure),
  `buffer` = `ContextCategories::bufferTokens()` (0 off / null no-config), and
  `mcpServers` = the breakdown's per-server rows (null when the tool list is
  unknown). `answer()` untouched (C-MCP-4 seam honored).
- Definitions.php `breakdown` gains all three as OPTIONAL members (required
  list unchanged) — older clients still validate; schema objects are open.
- Tests: pinned key list of the real-session breakdown flipped in-step (+buffer/
  categories/mcpServers); `testTheRealSessionCategoriesRideTheEnumVocabularyIn
  Order` (order/keys/labels/null-honesty/summing-law off the live wire);
  `testTheCategoriesSerializationPricesServersFilesAndNotes` (deterministic:
  share=3 on 30/1000, doc sub-rows bytes+personal, mcp sub = servers +
  `mcp_list/mcp_call` + note row, buffer 0 under MODE_OFF, mcpServers
  pass-through).

## Generated trio (§1.8)
`php scripts/gen-protocol-schema.php --write` + `--check` green;
`npm ci` + `npm run gen:protocol` wrote generated.ts (categories/buffer/
mcpServers optional members present); vitest `generated.spec.ts` 3/3 serial.
All three restored pre-commit — weld regenerates. SERVER.md content was
byte-unchanged by the generator (breakdown inner fields are outside its
generated tables).

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'ContextSources|ProtocolSchemaDrift|ServerProtocolDocumentationDrift'`:
  Tests: 22, Assertions: 443 — 0F (2 PHPUnit deprecations pre-existing).
- Five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`):
  Tests: 133, Failures: 2 — ONLY the ReadmeSuiteFigureDrift staleness pair
  (artifact 25,537 vs live 25,956; lane adds +2). Allowed red per brief.

## New test files: none (modify-only, per brief) — no durations rows owed.

## Notes
- Test literal hazards checked: no glob-shaped or denial-vocabulary literals
  (GlobDialect stayed green in-gate).
- Seam for weld: figure re-pin is the gate's; the trio regeneration carries
  this step's schema additions.
