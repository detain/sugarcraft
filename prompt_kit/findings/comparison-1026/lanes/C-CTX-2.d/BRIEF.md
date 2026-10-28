# BRIEF — C-CTX-2.d (wave 16)

## Served prompt suggestions and a `context.changed` event (protocol lane)
- area: prv-ctx · kind: gap · effort M · effort:  · phase: normal
- item: C-CTX-2 (sources: GAP-UI-34, GAP-INS-08w (event half), k1-dorm#6)
- deps: C-CTX-2.b, C-CTX-2.c
- serialized: [protocol] [docs:SERVER.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/stores/contextSources.ts]
- ruling: none

## Files to create
`candy-code/tests/Host/ServedPromptSuggestionTest.php`, `candy-code-web/src-web/components/__tests__/composerSuggestion.spec.ts`, `candy-code-web/src-web/stores/__tests__/contextChanged.spec.ts`

## Files to modify

- `candy-code/src/Host/SessionEvent.php` (two constants)
- `candy-code/src/Protocol/EventType.php`
- `candy-code/src/Protocol/Schema/EventSchemas.php`
- `candy-code/docs/protocol/candy-code.v1.schema.json` (gen)
- `candy-code/src/Host/SessionHost.php` (`settleWith()` suggestion lander; `applyCommandResult()` and `typedCompact()` announce `context.changed`)
- `candy-code-web/src-web/protocol/generated.ts` (gen)
- `candy-code-web/src-web/stores/reducer.ts` (suggestion state)
- `candy-code-web/src-web/components/Composer.vue` (ghost text and accept)
- `candy-code-web/src-web/stores/contextSources.ts` (debounced reload)

## Tests

- ServedPromptSuggestionTest pins:
- one `prompt.suggested` per settle;
- a stale suggestion is billed and not announced;
- `CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS` suppresses it;
- `context.changed` fires after a settle and after a `/rules` toggle.
- modify `candy-code/tests/Protocol/EventTypeTest.php`.
- web: the two specs above.

## Docs / guards

- `candy-code/docs/SERVER.md` (### Events), guarded by `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php`;
- `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php`;
- both gen `--check`s.

## Approach
`ContextMethods::instructionWrite` also announces `context.changed {reason: instructions}` (C-CTX-3.c relies on it). Two new ephemeral events.
- `prompt.suggested {text, generation}`:
- On settle, the suggestion closure from `TurnSettleWork` runs: `TitleService::suggestionCall()` on the title model.
- If it lands still current (same generation, same history length, nothing in flight), SessionHost bills it and announces the `TitleService::sanitizeSuggestion()` line. It is never persisted.
- The web reducer holds the suggestion and clears it on `turn.started` or on typing. `Composer.vue` shows it as grey placeholder text that → or Tab accepts.
- `context.changed {reason}`:
- SessionHost announces it after a settle, a compaction, and any command result that changes context (rules toggle, memory write, `/init`).
- `stores/contextSources.ts` re-fetches `context.sources` while loaded, debounced to at least 500 ms.
- This is the live reload P-CTX-BREAKDOWN consumes.
- Gen chain: `scripts/gen-protocol-schema.php` → schema JSON and `docs/SERVER.md`, then `candy-code-web/scripts/gen-protocol.mjs` → `generated.ts`.

## Risks
other web steps also edit Composer.vue (C-CTX-3.c, C-WEB-4's PDF upload). Keep this change to one placeholder slot and one key handler.
