### C-SES-7.e — Protocol: `session.import`, ephemeral `session.create`, archive/unarchive + list filter
- item: C-SES-7 (sources: GAP-SES-14 (server half), GAP-SES-N1 (serve half), OC-new#8, GAP-SES-10)
- kind: gap · effort M
- approach: One protocol lane carries three server surfaces, plus the web side.
  - **(1) `session.import {format, content, name?}`** (Write, idempotent). It runs the C-SES-7.a importer in-process and answers the new summary. It is bounded by the server's inbound frame cap, with a typed refusal beyond it.
  - **(2) `session.create {ephemeral: true}`.**
    - `SessionHub::create()` gains `bool $ephemeral`.
    - An ephemeral `SessionHost` skips `TranscriptStore` saves, the usage-record sink, and the title and memory passes.
    - The hub drops it on close.
  - **(3) `session.archive` and `session.unarchive {sessionId}`** wrap the existing `EnhancedSessionStore::archive()`/`unarchive()` (`:215-223`), and `session.list {archived?: bool}` filters on them. `SessionSummary` gains `archived` and `ephemeral`.
  - **Web.** The sidebar gains an Archive/Restore row action, a "Show archived" toggle, and an "Incognito" checkbox in the new-session flow.
- files.create: `candy-code/tests/Server/SessionImportMethodTest.php`, `candy-code/tests/Server/SessionArchiveMethodsTest.php`
- files.modify: `candy-code/src/Protocol/Methods/SessionMethods.php` (register; import(), archive(), unarchive(); list(), create() params), `candy-code/src/Protocol/Schema/MethodSchemas.php` (new and changed methods), `candy-code/src/Protocol/Schema/Definitions.php` (SESSION_SUMMARY archived/ephemeral), `candy-code/src/Protocol/ServerContext.php` (summary()), `candy-code/src/Host/SessionHub.php` (create(), list()), `candy-code/src/Host/SessionHost.php` (the ephemeral persistence gate), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/stores/sessions.ts` (archive, unarchive, create ephemeral), `candy-code-web/src-web/stores/newSession.ts` (incognito option), `candy-code-web/src-web/components/SessionSidebar.vue` (row action, toggle), `candy-code-web/src-web/protocol/__tests__/generated.spec.ts` (scope pins)
- tests: create:
  - `candy-code/tests/Server/SessionImportMethodTest.php`: an import becomes listable and searchable; oversize is refused;
  - `candy-code/tests/Server/SessionArchiveMethodsTest.php`: archive hides the row from the default list; `archived: true` shows it; unarchive restores it.
  
  Modify:
  - `candy-code/tests/Host/SessionHubTest.php`: an ephemeral session persists nothing;
  - `candy-code-web/src-web/components/__tests__/sidebarActions.spec.ts`: archive/restore;
  - `candy-code-web/src-web/stores/__tests__/sessionsRowActions.spec.ts`.
- docs/guards: the generation chain; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts`
- serialized: [protocol] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/SessionHub.php] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/SessionSidebar.vue] [docs:SERVER.md (regen only)]
- deps: C-SES-7.a
- phase: normal
- ruling: none
- risks: The inbound WS frame cap is not verified (the outbox watermark is 16 MiB, `src/Server/Ws/Outbox.php:40`). Check the inbound limit in `src/Server/Ws/` before setting the import bound. C-WEB-7 has the matching 4 MiB export problem.

