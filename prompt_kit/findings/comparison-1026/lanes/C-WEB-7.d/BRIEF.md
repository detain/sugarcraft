# C-WEB-7.d: Typed AskUser `choices` on the ask payload

- wave ? · area web7 · kind fix · MINOR · effort S · effort ? · phase normal
- item: C-WEB-7 (sources: p2-miss: WEB-5 structured AskUser `options[]`)
- deps: none
- serialized: [protocol] [hot:candy-code/src/Protocol/SessionFeed.php] [hot:candy-code/src/Host/TranscriptProjector.php] [hot:candy-code-web/src-web/components/PermissionCard.vue]
- ruling: none
- risks: 
- C-SEC-2.b adds a `diff` field to the same `askSummary`/`askData` arrays, `PENDING_ASK` and `PermissionCard.vue`, and C-SEC-2.a/6.c also edit `PermissionCard.vue`. Both changes are additive keys, so whichever lands second rebases trivially. Keep them out of one wave, which the shared paths already force.

## Files
- create: `candy-code/tests/Protocol/AskUserChoicesWireTest.php`
- modify: `candy-code/src/Protocol/SessionFeed.php` (`askSummary`), `candy-code/src/Host/TranscriptProjector.php` (`askData`), `candy-code/src/Protocol/Schema/Definitions.php` (`PENDING_ASK`), `candy-code-web/src-web/components/PermissionCard.vue` (`choices` computed + its docblock), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen)
- tests: 
- Create `candy-code/tests/Protocol/AskUserChoicesWireTest.php`. It uses `ProtocolFixture` and emits its own `PermissionAsked` with a `PendingAsk::fromFrame` of source `tool:AskUser`; `ScriptedTurnBackend::emit()` is public, so the fixture is not edited. It pins:
- the permission.requested event, the server-scope permission.asked broadcast, the permission.pending items and the snapshot `pendingAsks` each carry `choices` equal to the options, and each fits its schema;
- a `hook:x` ask about an AskUser call, and a gate ask on `Bash`, carry no `choices` key.
- Modify `candy-code-web/src-web/components/__tests__/permissionCardExtras.spec.ts` (:56-92):
- fixtures move from `arguments.options` to `choices`;
- the "too many choices" case becomes "renders every typed choice";
- "ignores option lists on non-AskUser tools" becomes "an ask without `choices` shows no choice buttons, even with `arguments.options`".
- docs/guards: the protocol gen chain (`gen-protocol-schema.php --check` / `ProtocolSchemaDriftTest`, `npm run gen:protocol -- --check` / `generated.spec.ts`, `ServerProtocolDocumentationDriftTest`; Definitions do not reach the SERVER.md tables, so SERVER.md is not edited); vitest; dist rebuilt at weld

## Approach

- **Root cause.** The web reads AskUser's choices by parsing the untyped `arguments.options` (`PermissionCard.vue:39-53`). It silently drops to free text above 8 choices. Nothing in the protocol schema states the contract.
- **The TUI already has the typed accessor.** `PendingAsk::choices()` (`candy-code/src/Backend/PendingAsk.php:195-215`) is gated on `question() === AskUser`, so a hook's ask about an AskUser call stays a permission question, and is capped at `AskUserTool::MAX_OPTIONS` (6). Both `Chat.php:3162` and `Renderer.php:7054` use it. Only the wire never carries it.
- **Name.** The field is `choices`, not the `options` the p2-miss suggested. `PendingAsk.options` is already the once/always/reject verdict list (`Definitions.php:69`), and `choices` matches the accessor.
- **Fix, server.** Add `'choices' => $ask->choices()`, only when non-empty, so every other ask stays byte-identical:
- in `SessionFeed::askSummary()` (`src/Protocol/SessionFeed.php:363-376`), which feeds `permission.pending` and snapshot `pendingAsks`;
- in `TranscriptProjector::askData()` (`src/Host/TranscriptProjector.php:472-486`), which feeds `permission.requested`, re-broadcast as `permission.asked` at `ServerContext.php:363`.
- **Fix, schema.** `Definitions::PENDING_ASK` gains `choices: arrayOf(string(MAX_OPTION_CHARS), MAX_OPTIONS)`, described as "AskUser's own choices, in order, the first recommended; answer one with `permission.respond` reply `once` and the choice as the note".
- **Not touched: background relays.** Background-session relays never carry AskUser, because a bg daemon has no interactive user (`BackgroundSessionRunner.php:1047`). `BackgroundEvents.php` is left alone.
- **Fix, client.** `PermissionCard.vue`'s `choices` computed reads `ask.choices` and drops the `arguments` parse and the `> 8` cap.
- The bundle is served by the same daemon (`candy-code serve` ships `dist/`), so no older-server fallback is needed.
- The free-text note stays the "something else" path.
