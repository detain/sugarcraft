### C-WEB-1.a — Served `/model` and `/rename` reach the session summary
- item: C-WEB-1 (sources: WEB-2-P1, p2-miss: web model switch)
- kind: fix · MAJOR · S
- approach:
  - **Root cause, model.** The `SetModel` arm (`candy-code/src/Host/SessionHost.php:1348-1353`) only swaps `$sessionBackend`, while `ServerContext::summary()` (`src/Protocol/ServerContext.php:320-340`) reads `provider`/`model` from the session row alone.
  - **Root cause, broadcast.** The only `session.updated` announcers are the `session.rename`/`session.setMode` RPCs (`SessionMethods.php:179,335`). A served `/model` (and a served `/rename`, whose `RenameSession` effect is a bare `break` at `SessionHost.php:1361-1364`) therefore changes nothing the web hears.
  - **Fix, SessionHost.** `applyCommandResult()` records `{provider, model}` beside the backend: a new `modelOverride(): ?array` accessor. It fires a new `onSummaryChanged(?\Closure)` hook after a `SetModel` or `RenameSession` effect. Both paths (`command.exec` via `runCommand()`, typed text via `admit()`→`command()`) pass through `applyCommandResult`.
  - **Fix, ServerContext.** `summary()` overlays `$host->modelOverride()` when the host is open. The provider axis stays unpersisted, as `tests/Host/ModelHostPersistenceTest.php` documents. `feedFor()` binds the hook to broadcast `session.updated` with `summaryOf()`.
  - **Fix, wire text.** `EventType::CATALOGUE[SESSION_UPDATED]` gains "or its model changed". The gen chain then moves.
  - **What the user sees:** after picking a model in the web, the select, the title and the other tabs show the new provider/model, and picking the old one switches back.
- files.create: `candy-code/tests/Protocol/SessionSummaryModelOverlayTest.php`
- files.modify: `candy-code/src/Host/SessionHost.php` (applyCommandResult SetModel/RenameSession arms; new `modelOverride()`, `onSummaryChanged()`), `candy-code/src/Protocol/ServerContext.php` (`summary()`, `feedFor()`), `candy-code/src/Protocol/EventType.php` (SESSION_UPDATED description), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen event table), `candy-code-web/src-web/protocol/generated.ts` (regen)
- tests: create `candy-code/tests/Protocol/SessionSummaryModelOverlayTest.php`, which pins:
  - after `command.exec /model openai gpt-x`, `summaryOf($id)` names openai/gpt-x and one `session.updated` was broadcast;
  - the same holds for typed `session.send "/model …"`;
  - a served `/rename` broadcasts too;
  - a closed host reports the row.

  Modify `candy-code/tests/Host/WebTwoHostCommandsTest.php` (the host keeps the override across a second `/model`).
- docs/guards:
  - `php candy-code/scripts/gen-protocol-schema.php --check` (`tests/Protocol/ProtocolSchemaDriftTest.php`);
  - `npm run gen:protocol -- --check`;
  - `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php`;
  - no child-lifetime row.
- serialized: [protocol] (EventType description only — no MethodSchemas change) [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php]
- deps: none
- phase: normal
- ruling: none
- risks: The hook must be re-bound when `feedFor()` replaces a feed for a re-opened host, or a recycled host goes silent again.

