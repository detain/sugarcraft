# design-web7 — C-WEB-7 (Web minor/nit cleanup)

Design pass per `BRIEF-design.md`. Every MODIFY path below was checked to exist, and every CREATE path was checked to be absent, on master at `5e9195687` (2026-10-26). Line numbers are from that tree.

**Shape.** The bundle splits into four lanes:
- one server-only lane with no protocol (a);
- one web client-only lane (b);
- two `[protocol]` lanes, (c) and (d), each with its own client half.

`candy-code-web/dist/` is weld-only and is not listed anywhere.

**Source → step map:**
- WEB-4-P1 and WEB-6-P3 → a.
- WEB-5-P3 (client debounce), WEB-6-P1, WEB-6-P2 and WEB-7-P1 → b.
- WEB-1-P2, plus WEB-5-P3's server walk memo → c.
- p2-miss "WEB-5 structured AskUser `options[]`" → d.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-WEB-7.a | Shell tail trailing-edge flush + `discard()` docblock truth | S | [hot:candy-code/src/Tools/BuiltIn/Bash.php] | none |
| C-WEB-7.b | Web client nits: `@` debounce, cross-tab history clear + palette action, re-announced questions | M | [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/stores/promptHistory.ts] [hot:candy-code-web/src-web/components/panels/CommandPalette.vue] | C-SEC-4.b |
| C-WEB-7.c | Paged `session.export` + memoized non-git `fs.search` walk | M | [protocol] [hot:candy-code/src/Protocol/Methods/SessionMethods.php] [hot:candy-code-web/src-web/stores/sessions.ts] [docs:SERVER.md] | none |
| C-WEB-7.d | Typed AskUser `choices` on the ask payload | S | [protocol] [hot:candy-code/src/Protocol/SessionFeed.php] [hot:candy-code/src/Host/TranscriptProjector.php] [hot:candy-code-web/src-web/components/PermissionCard.vue] | none |

Totals: 4 steps, all `phase: normal`. 2 S + 2 M + 0 L. Two steps (c, d) touch `[protocol]`.

---

### C-WEB-7.a — Shell tail trailing-edge flush + `discard()` docblock truth
- item: C-WEB-7 (sources: WEB-4-P1, WEB-6-P3)
- kind: fix · MINOR · effort S
- approach:
  - **Root cause, the tail.** `ShellTailRing::onChunk()` (`candy-code/src/Tools/ShellTailRing.php:55-67`) only sets `dirty` for a chunk that arrives within `frameIntervalSeconds` (0.5 s, `Bash.php:708`) of the last frame. Only `onChunk()` and `finish()` (:70-76) push frames.
    - Scenario: a build prints its last lines just after a frame, then goes quiet for minutes. The web ToolCard tail is missing those lines until exit.
    - The class docblock (:19-24) promises "the next frame's tail carries them", which is false when no next chunk comes.
  - **Key finding: no new loop seam is needed.** The review assumed "the onChunk seam must grow an idle callback". In fact the capture loops already call `$onWait` on every pass:
    - `CapturesProcessOutput::runCaptured()` calls it once per ≤200 ms select slice (:243-245), and `awaitExitUntil()` (:351-365) calls it too;
    - `runCapturedInteractive()` calls it every 50 ms read pass (:545-547).
    - Bash simply passes `$heartbeat` there today (`Bash.php:590-592`), and that is null on a plain `execute()`.
  - **Fix, the ring.** Add `ShellTailRing::tick()`. It pushes when `dirty` and the interval since the last frame has elapsed; otherwise it does nothing.
  - **Fix, Bash.** `Bash::runCommand()` (:529-543) builds `$onWait`:
    - when the ring exists: a closure that calls `$progress->tick()` and then `$heartbeat` if it is non-null;
    - otherwise: `$heartbeat` unchanged.
    - Both `runCaptured`/`runCapturedInteractive` calls receive `$onWait`. The trait is unchanged, and `finish()` stays the exit flush.
  - Rewrite the class docblock's "not lost" sentence to name `tick()`.
  - **WEB-6-P3.** `SessionFileStore::discard()`'s docblock (`candy-code/src/Server/Auth/SessionFileStore.php:191-198`) says live sessions "ride over the restart unsigned-out". The opposite is true: with no file, every live session is signed OUT at restart, as `CookieSessions.php:154-160` states. Reword it to "every live session is signed out at the restart — the conservative direction". Prose only.
  - **What the user sees:** a quiet build's last lines appear in the web tool card within about 0.7 s instead of at exit.
- files.create: none
- files.modify: `candy-code/src/Tools/ShellTailRing.php` (new `tick()`, class docblock), `candy-code/src/Tools/BuiltIn/Bash.php` (`runCommand` — ring/`$onWait` composition and the two capture calls only), `candy-code/src/Server/Auth/SessionFileStore.php` (`discard()` docblock)
- tests:
  - Modify `candy-code/tests/Tools/ShellTailRingTest.php`, a cadence pin with the clock seam:
    - a chunk at t=0 → frame 1;
    - a chunk at t=0.1 → no frame;
    - `tick()` at t=0.3 → no frame;
    - `tick()` at t=0.6 → frame 2 carries both chunks;
    - `tick()` at t=0.9 → nothing (not dirty);
    - `finish()` afterwards → nothing.
  - Modify `candy-code/tests/Tools/BashToolProgressTest.php`. Run `printf a; sleep 0.05; printf b; sleep 1.2` with a timestamping emitter, then assert:
    - exactly 2 frames;
    - the second carries `ab`;
    - the second was emitted at least 0.4 s before `execute()` returned.
  - Also pin that a run with no emitter still beats a supplied heartbeat (`executeWithHeartbeat`).
- docs/guards: none (no roster, no doc page); no child-lifetime row (no new spawn)
- serialized: [hot:candy-code/src/Tools/BuiltIn/Bash.php]
- deps: none
- phase: normal
- ruling: none
- risks:
  - `Bash.php` is also edited by design-tool steps (BackgroundShellRegistry rows, `sandboxRefusal()` extraction, sandbox types), so the scheduler serializes them by path. The edit here is confined to the `$progress`/`$onChunk` block and the two capture-call arguments.
  - The Bash integration pin is timing-based. The margins are wide (a 1.2 s sleep against an emit at about 0.5–0.7 s).

### C-WEB-7.b — Web client nits: `@` debounce, cross-tab history clear + palette action, re-announced questions
- item: C-WEB-7 (sources: WEB-5-P3 (client half), WEB-6-P1, WEB-6-P2, WEB-7-P1)
- kind: fix · MINOR · effort M
- approach: Client-only, no protocol.
  - **WEB-5-P3, debounce.**
    - Root cause: `Composer.vue:86-101` watches `mentionPartial` with no debounce, so every keystroke after `@` is a synchronous `fs.search` on the shared serve loop.
    - Fix: a leading + trailing debounce of 120 ms (`MENTION_SEARCH_DEBOUNCE_MS`).
      - A partial that arrives more than 120 ms after the last search runs at once, so the first `@` still opens instantly.
      - Partials inside the window collapse to one trailing search carrying the newest partial.
      - A `null` partial cancels the pending timer and resets the window.
      - The `fileSeq` latch stays, and the timer is cleared in `onBeforeUnmount`.
    - The existing specs, which type one partial per step, keep passing unchanged.
  - **WEB-6-P1, cross-tab resurrection.**
    - Root cause: `Composer.vue:147` loads the ring into memory once. `pushPromptHistory()` (`stores/promptHistory.ts:30-36`) writes `[text, ...inMemory]`. `clearPromptHistory()` (:44-46) removes only the key, so another open tab's next send rewrites the whole pre-sign-out ring.
    - Fix in `promptHistory.ts`: a new `onPromptHistoryCleared(listener): () => void`.
      - It lazily attaches one `window` `storage` listener. That fires on `key === 'candy-code-web:promptHistory' && newValue === null`, or on `key === null` (`localStorage.clear()`).
      - `clearPromptHistory()` also notifies in-tab listeners, because the `storage` event never fires in the tab that made the change.
    - `Composer.vue` subscribes at setup and unsubscribes on unmount. A clear resets `history` to `[]`, `historyIndex` to -1 and `draftBackup` to `''`.
    - Correct the docblocks that say "the in-memory copy callers hold is untouched".
  - **WEB-6-P2, no clear UI.**
    - Root cause: `candy-code-web/README.md:146-147` advertises `clearPromptHistory()` "any time without signing out", but it has no UI. Its only caller is `protocol/auth.ts:144`.
    - Fix: a palette `Actions` entry "Clear prompt history" (hint "this browser"), next to the theme entry in `CommandPalette.vue` `entries` (:41-46). It asks `window.confirm` and then calls `clearPromptHistory()`.
    - Reword the README disclosure to name the palette action and to say that a sign-out clears the ring in every open tab.
  - **WEB-7-P1, silent repeats.**
    - Root cause: `lib/questions.ts:44-52` assigns the composed sentence to a ref. Two identical asks yield the same string, so `App.vue:107`'s live region never mutates and a screen reader stays silent.
    - Fix: set the ref to `''`, then set the sentence on `nextTick`.
  - **WEB-7-P1, trust prompts.**
    - Root cause: trust prompts (`kind:"trust"`) are filtered out before `approvals.onNew` (`stores/approvals.ts:124-125`), so they are never announced.
    - Fix: `useQuestionAnnouncements()` also `watch`es the contextSources store's open trust prompt by `askId`, using the `pendingTrust` that C-SEC-4.b introduces. It announces "`<session>` — project trust prompt for `<root>` is waiting in the Context panel".
    - It only reads the store; `stores/contextSources.ts` is not edited.
- files.create: `candy-code-web/src-web/components/panels/__tests__/paletteClearHistory.spec.ts`
- files.modify: `candy-code-web/src-web/components/Composer.vue` (`mentionPartial` watcher + debounce timer, history-cleared subscription, unmount cleanup), `candy-code-web/src-web/stores/promptHistory.ts` (`onPromptHistoryCleared`, `clearPromptHistory` in-tab notify, docblocks), `candy-code-web/src-web/components/panels/CommandPalette.vue` (`entries` Actions row), `candy-code-web/src-web/lib/questions.ts` (`useQuestionAnnouncements`), `candy-code-web/README.md` (prompt-history disclosure, :143-147)
- tests:
  - Modify `candy-code-web/src-web/components/__tests__/composerFilesImages.spec.ts`. Add a fake-timer `describe`:
    - five partials typed inside 120 ms send exactly 2 searches: the first and the last;
    - the popup shows the last answer;
    - a stale answer that arrives after a newer partial is dropped (latch);
    - a `null` partial cancels the trailing search.
  - Modify `candy-code-web/src-web/components/__tests__/composerHistory.spec.ts`:
    - a `StorageEvent` (`key: 'candy-code-web:promptHistory'`, `newValue: null`) empties the mounted ring, so the next send persists only `[new]` and Ctrl+Up recalls only it;
    - a same-tab `clearPromptHistory()` does the same;
    - an unrelated key's event leaves the ring untouched.
  - Create `candy-code-web/src-web/components/panels/__tests__/paletteClearHistory.spec.ts`:
    - the palette lists "Clear prompt history";
    - confirm → key removed;
    - cancel → key kept.
  - Modify `candy-code-web/src-web/lib/__tests__/questions.spec.ts`:
    - two identical consecutive asks produce two DOM text mutations of `question-announcement`;
    - a trust prompt adopted by the store is announced once.
- docs/guards: `candy-code-web/README.md` (soft; no test pins it); `npm run typecheck`, `npm run lint`, vitest; dist rebuilt at weld
- serialized: [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/stores/promptHistory.ts] [hot:candy-code-web/src-web/components/panels/CommandPalette.vue]
- deps: C-SEC-4.b
- phase: normal
- ruling: none
- risks:
  - Composer.vue is also edited by C-WEB-4.a, C-WEB-4.e, C-CTX-2.d and steps from agt, mcp and tui. `promptHistory.ts` is edited by C-WEB-4.a (`searchPromptHistory`), and `CommandPalette.vue` by C-WEB-4.b (keymap binding at :143).
  - If C-SEC-4.b names its field something other than `pendingTrust`, read that name. The trust half is a read-only `watch`.

### C-WEB-7.c — Paged `session.export` + memoized non-git `fs.search` walk
- item: C-WEB-7 (sources: WEB-1-P2, WEB-5-P3 (server half), p2-miss: web session export over 4 MiB)
- kind: fix · MINOR · effort M
- approach:
  - **Root cause, export (WEB-1-P2).**
    - `SessionMethods::export()` (`candy-code/src/Protocol/Methods/SessionMethods.php:237-258`) returns the whole rendered transcript in one answer.
    - `Dispatcher::answer()` (`src/Protocol/Dispatcher.php:79,382-391`) replaces any answer over 4,194,304 bytes with `too_large` "ask for less", yet the method offers no way to ask for less.
    - The web row menu (`SessionSidebar.vue:93-99` → `stores/sessions.ts:113-116`) can then only show that line.
  - **Fix, server.** Page by byte offset, following the `tool.output` precedent (`ToolMethods.php:34-66`) and the ISS-058 snapshot paging rule (`tests/Server/SnapshotPagingTest.php`: unparameterized answers stay byte-identical).
    - New optional params: `offset ≥ 0`, `limit` (65,536 … 1,048,576) and `revision` (string ≤ 64).
    - With no `limit`, the answer is today's unpaged `{format, content}`, and the 4 MiB bound still applies.
    - With `limit`, the server:
      1. renders as today and sets `revision = hash('xxh128', $text)`;
      2. refuses a supplied `revision` that differs with `ErrorCode::Conflict`, kind `export_changed` ("the session changed since the export began; start again"), because a running turn or a compaction can reshape the rendering, and JSON pages from two renders would splice into garbage;
      3. backs `offset` up to a UTF-8 lead byte and cuts with `mb_strcut`;
      4. **shrinks the page to fit the frame:** while `strlen(json_encode($slice, JsonRpc::FLAGS))` exceeds `MAX_SERVER_FRAME_BYTES − 65,536`, it halves the cut. A page of control bytes escapes about 6×, which would otherwise trip `too_large` again;
      5. answers `{format, content, offset, nextOffset, total, more, revision}`.
    - The server computes `nextOffset`, so a client never byte-counts text that `JSON_INVALID_UTF8_SUBSTITUTE` may have rewritten in transit.
  - **Fix, client.** `stores/sessions.ts` `exportTranscript()` keeps its signature `{format, content}`, so `SessionSidebar.vue` is untouched. It:
    - loops with `limit: 1_048_576`, collecting pages and echoing `revision` from page 2 on;
    - joins the pages when `more` is false;
    - guards against `nextOffset <= offset`;
    - restarts once from offset 0 on `export_changed`; a second conflict surfaces through the sidebar's existing error line.
  - **Root cause, walk (WEB-5-P3 server half).** A non-git root re-walks up to 20,000 entries on every `fs.search` (`FsMethods.php:162-220`), synchronously on the serve loop.
  - **Fix, walk.** Move the walk into a new `SugarCraft\Code\Attachments\WorkspaceWalk` (a move, not a removal).
    - `listing(string $root, bool $showHidden): list<string>` returns every file among the first `WALK_MAX_ENTRIES` read entries, in walk order, unfiltered.
    - It is memoized per (realpath, showHidden) for `TTL_SECONDS = 2.0`, holds at most 8 roots, and has a `forget()` seam and a clock seam.
    - `FsMethods::walk()` becomes: filter by the substring needle, take the first `WALK_MAX_CANDIDATES` in walk order, then `sortedRelative()`. That is the same result set as today's early-stopping walk.
    - Containment re-checks, link skips and the depth cap move unchanged.
  - **What the user sees:** Export works for any transcript size, and typing `@` in a large non-git root no longer stalls other clients.
- files.create: `candy-code/src/Attachments/WorkspaceWalk.php`, `candy-code/tests/Server/SessionExportPagingTest.php`, `candy-code/tests/Attachments/WorkspaceWalkTest.php`, `candy-code-web/src-web/stores/__tests__/sessionsExportPaging.spec.ts`
- files.modify: `candy-code/src/Protocol/Methods/SessionMethods.php` (`export()` + the `session.export` MethodSpec description), `candy-code/src/Protocol/Schema/MethodSchemas.php` (`session.export` row), `candy-code/src/Protocol/Methods/FsMethods.php` (`search()`, `walk()` → WorkspaceWalk; walk constants move), `candy-code-web/src-web/stores/sessions.ts` (`exportTranscript`), `candy-code/docs/SERVER.md` (regenerated method row; `maxServerFrameBytes` limits row at :378), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen)
- tests:
  - Create `candy-code/tests/Server/SessionExportPagingTest.php`, using `ProtocolFixture` and seeding rows the way `SnapshotPagingTest::seed()` does:
    - a transcript whose markdown is over 4 MiB answers `too_large` unpaged (the legacy shape still holds);
    - walking the pages reassembles a byte-identical `Exporter::toMarkdown()` (and `toJson()`) output;
    - every page fits `MethodSchemas::result('session.export')` via `ProtocolSchema::errors`;
    - an offset placed mid-codepoint backs up to the lead byte;
    - a transcript of control bytes still pages under the frame cap;
    - a stale `revision` → Conflict `export_changed`.
  - Create `candy-code/tests/Attachments/WorkspaceWalkTest.php`:
    - two listings within the TTL return the memo, so a file created between them is absent;
    - past the TTL (clock seam) the file appears;
    - `showHidden` is keyed separately;
    - links and dot entries are skipped as before;
    - the root cap evicts the oldest.
  - Create `candy-code-web/src-web/stores/__tests__/sessionsExportPaging.spec.ts`:
    - three pages are joined in order;
    - `revision` is echoed from page 2;
    - one `export_changed` restarts at offset 0, and a second rejects.
  - Modify `candy-code-web/src-web/stores/__tests__/sessionsRowActions.spec.ts` (:59-63) and `candy-code-web/src-web/components/__tests__/sidebarActions.spec.ts` (:116, :129): the sent params now carry `offset: 0, limit: 1048576`.
  - The existing FsSearchTest (tests/Server) must pass unchanged. It is not edited here, because C-WEB-1.c edits it.
- docs/guards:
  - `php candy-code/scripts/gen-protocol-schema.php` (`--check` is guarded by `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php`);
  - `npm run gen:protocol -- --check` (`candy-code-web/src-web/protocol/__tests__/generated.spec.ts`);
  - `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php`;
  - the `docs/SERVER.md` limits-row prose: "`session.export` pages the same way (`limit`/`offset`)";
  - vitest; dist rebuilt at weld;
  - no child-lifetime row.
- serialized: [protocol] [hot:candy-code/src/Protocol/Methods/SessionMethods.php] [hot:candy-code-web/src-web/stores/sessions.ts] [docs:SERVER.md]
- deps: none
- phase: normal
- ruling: none
- risks:
  - Other areas also edit these files: `SessionMethods.php` (design-sec/ses/web), `stores/sessions.ts` (C-WEB-3.d, C-WEB-3.e, C-SES) and `sidebarActions.spec.ts` / `sessionsRowActions.spec.ts` (design-ses archive/restore). Every one of these edits is a small region.
  - The 2 s walk memo means a file the agent just wrote in a non-git root can take up to 2 s to appear under `@`. C-WEB-1.c's turn-end `MentionIndex::forget()` may also call `WorkspaceWalk::forget($root)` if it lands later; that is optional.

### C-WEB-7.d — Typed AskUser `choices` on the ask payload
- item: C-WEB-7 (sources: p2-miss: WEB-5 structured AskUser `options[]`)
- kind: fix · MINOR · effort S
- approach:
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
- files.create: `candy-code/tests/Protocol/AskUserChoicesWireTest.php`
- files.modify: `candy-code/src/Protocol/SessionFeed.php` (`askSummary`), `candy-code/src/Host/TranscriptProjector.php` (`askData`), `candy-code/src/Protocol/Schema/Definitions.php` (`PENDING_ASK`), `candy-code-web/src-web/components/PermissionCard.vue` (`choices` computed + its docblock), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen)
- tests:
  - Create `candy-code/tests/Protocol/AskUserChoicesWireTest.php`. It uses `ProtocolFixture` and emits its own `PermissionAsked` with a `PendingAsk::fromFrame` of source `tool:AskUser`; `ScriptedTurnBackend::emit()` is public, so the fixture is not edited. It pins:
    - the permission.requested event, the server-scope permission.asked broadcast, the permission.pending items and the snapshot `pendingAsks` each carry `choices` equal to the options, and each fits its schema;
    - a `hook:x` ask about an AskUser call, and a gate ask on `Bash`, carry no `choices` key.
  - Modify `candy-code-web/src-web/components/__tests__/permissionCardExtras.spec.ts` (:56-92):
    - fixtures move from `arguments.options` to `choices`;
    - the "too many choices" case becomes "renders every typed choice";
    - "ignores option lists on non-AskUser tools" becomes "an ask without `choices` shows no choice buttons, even with `arguments.options`".
- docs/guards: the protocol gen chain (`gen-protocol-schema.php --check` / `ProtocolSchemaDriftTest`, `npm run gen:protocol -- --check` / `generated.spec.ts`, `ServerProtocolDocumentationDriftTest`; Definitions do not reach the SERVER.md tables, so SERVER.md is not edited); vitest; dist rebuilt at weld
- serialized: [protocol] [hot:candy-code/src/Protocol/SessionFeed.php] [hot:candy-code/src/Host/TranscriptProjector.php] [hot:candy-code-web/src-web/components/PermissionCard.vue]
- deps: none
- phase: normal
- ruling: none
- risks:
  - C-SEC-2.b adds a `diff` field to the same `askSummary`/`askData` arrays, `PENDING_ASK` and `PermissionCard.vue`, and C-SEC-2.a/6.c also edit `PermissionCard.vue`. Both changes are additive keys, so whichever lands second rebases trivially. Keep them out of one wave, which the shared paths already force.

---

## Notes for the orchestrator

1. **Protocol lanes.** Only C-WEB-7.c and C-WEB-7.d touch the chain. They are path-disjoint on the server: c has `MethodSchemas` `session.export` and `SessionMethods`; d has `Definitions` `PENDING_ASK`, `SessionFeed` and `TranscriptProjector`. They are also disjoint on the client (`stores/sessions.ts` vs `PermissionCard.vue`).
   - The scheduler's 2-per-wave protocol cap would let them pair. The brief's one-per-wave rule would not; either way, both regenerate `generated.ts` at weld.
   - **c's `FsMethods.php` edit lives under `src/Protocol/`**, so the scheduler counts c as protocol in any case. That is why the walk memo rides c and not the server-only lane a.
2. **Hot files and the steps sharing them:**
   - `Composer.vue`: 7.b; also C-WEB-4.a, 4.e, C-CTX-2.d, agt/mcp/tui.
   - `stores/promptHistory.ts`: 7.b; also C-WEB-4.a.
   - `CommandPalette.vue`: 7.b; also C-WEB-4.b.
   - `stores/sessions.ts`: 7.c; also C-WEB-3.d, 3.e, C-SES.
   - `PermissionCard.vue`: 7.d; also C-SEC-2.a, 2.b, 6.c.
   - `SessionFeed.php` / `TranscriptProjector.php`: 7.d; also C-SEC-2.b, C-WEB-1.d, C-CTX-2.
   - `Bash.php`: 7.a; also design-tool.
   - `SessionSidebar.vue` and `StatusBar.vue` are NOT touched. The export loop lives in the store, so the sidebar row menu is unchanged.
3. **Cross-area dep.** 7.b waits on C-SEC-4.b only for the trust-prompt announcement (it reads C-SEC-4.b's `pendingTrust`). Everything else in 7.b is independent. If C-SEC-4.b slips, drop the trust half to a follow-up rather than reading `sources.trust`, which C-SEC-4.b restructures.
4. **New PHP test files for the durations TSV (weld):**
   - `candy-code/tests/Tools/` gains none (7.a only modifies existing tests);
   - `candy-code/tests/Server/SessionExportPagingTest.php` and `candy-code/tests/Attachments/WorkspaceWalkTest.php` (7.c);
   - `candy-code/tests/Protocol/AskUserChoicesWireTest.php` (7.d).
   - The suite figure moves at weld.
5. **Review corrections recorded here:**
   - WEB-4-P1's "the onChunk seam needs an idle callback" is unnecessary: `$onWait` already beats per select slice.
   - The p2-miss's field name `options` collides with the verdict list, so it is `choices`.
   - WEB-5-P3's "5 keystrokes → 1 request" becomes 2 (leading + trailing), which keeps the first `@` instant and the existing specs green.
6. **No rulings.**
7. **Could not verify:**
   - C-SEC-4.b's final store field name.
   - Whether C-WEB-1.c's planned `FsSearchTest` additions exercise a non-git root within 2 s of a write. If they do, that test needs `WorkspaceWalk::forget()` in its setup.
   - No live serve or web run was made (read-only brief).
8. **COMPARISON.md.** No cell flips beyond WEB-row detail wording (export size, AskUser buttons). Handle it at closeout; this lane does not edit COMPARISON.md.
