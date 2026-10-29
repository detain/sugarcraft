### C-AGT-4.c — Web agent select + `SessionSummary.agent`
- item: C-AGT-4 (sources: GAP-AGT-02w)
- kind: gap · effort M
- approach:
  - Add a nullable `agent` to `SessionSummary` (`src/Protocol/Schema/Definitions.php`), filled by `ServerContext::summary()` (:320) from `SessionHost::primaryAgent()`. A switch announces `session.updated`.
  - Add a `mode` field to `agents.list` rows (`AgentsMethods.php:79`).
  - `StatusBar.vue` gets an agent `<select>` beside the model select. It reads `summary.agent` and the primary-capable roster from the agents store, and sends `command.exec /agent use <name>`, never a second source of truth (the model-select pattern, `StatusBar.vue:19-55`).
  - Regenerate the gen chain.
- files.create: none
- files.modify: `candy-code/src/Protocol/Schema/Definitions.php` (SessionSummary), `candy-code/src/Protocol/ServerContext.php` (`summary`), `candy-code/src/Protocol/Methods/AgentsMethods.php` (`list` row `mode`), `candy-code/docs/protocol/candy-code.v1.schema.json` (generated), `candy-code/docs/SERVER.md` (generated tables), `candy-code-web/src-web/protocol/generated.ts` (generated), `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/stores/agents/` (roster `mode`)
- tests: modify `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php` (fixture if it pins the field set), `candy-code-web/src-web/components/__tests__/statusBar.spec.ts` (select renders, sends `/agent use`, follows `summary.agent`)
- docs/guards: `scripts/gen-protocol-schema.php --check`, `npm run gen:protocol -- --check`, `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php`; rebuild `candy-code-web/dist/` (weld)
- serialized: [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/StatusBar.vue] [docs:SERVER.md]
- deps: C-AGT-4.b
- phase: normal
- ruling: none
- risks: `StatusBar.vue` is also edited by C-WEB-4 (branch badge) and C-AGT-6.e (PR chip). Serialize all three.

