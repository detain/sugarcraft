### C-TOOL-3.e — Web Processes section + `shells.*` methods
- item: C-TOOL-3 (sources: CLAW-new#6, p2-miss: SHL no web/serve shell list)
- kind: gap · effort M
- approach: Structured protocol methods: `shells.list` (Read scope), `shells.output {shellId, cursor}` (Read) and `shells.stop {shellId}` (Write, idempotent). They resolve the session's workspace registry like the other per-session methods and filter by owner (C-TOOL-3.c). The web gets a Processes section inside `BackgroundPanel.vue`, as a new child component (no new SidePanels tab, which keeps the hot `SidePanels.vue` and `stores/agents/panels.ts` untouched). It shows rows with state, exit code and recent output, plus a targeted Stop, polling `shells.list` while the panel is open (no new event type).
- files.create: `candy-code/src/Protocol/Methods/ShellsMethods.php`, `candy-code/tests/Protocol/ShellsMethodsTest.php`, `candy-code-web/src-web/stores/agents/shells.ts`, `candy-code-web/src-web/components/panels/ProcessesSection.vue`, `candy-code-web/src-web/components/panels/__tests__/processesSection.spec.ts`
- files.modify: `candy-code/src/Protocol/Dispatcher.php` (register, beside BgMethods :138), `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/Schema/Definitions.php` (background-shell row def), `candy-code/docs/protocol/candy-code.v1.schema.json` + `candy-code/docs/SERVER.md` (via `php scripts/gen-protocol-schema.php --write`), `candy-code-web/src-web/protocol/generated.ts` (via `candy-code-web/scripts/gen-protocol.mjs`), `candy-code-web/src-web/components/panels/BackgroundPanel.vue` (mount the section)
- tests: create the PHP and spec files above (owner filter, stop idempotency, scope enforcement; section renders rows, Stop calls `shells.stop`, write-scope gating)
- docs/guards: `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest` (regen in-step)
- serialized: [protocol]
- deps: C-TOOL-3.c
- phase: normal
- ruling: none
- risks: polling cost is bounded by the open panel; stop polling on close.

