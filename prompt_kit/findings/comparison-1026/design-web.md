# design-web — C-WEB-1, C-WEB-2, C-WEB-3, C-WEB-4, C-WEB-5 (LATE)

Design pass per `BRIEF-design.md`. Every MODIFY path was checked to exist and every CREATE path was checked to be absent on master at `5e9195687` (2026-10-26). C-WEB-6 was not picked, and C-WEB-7 belongs to another lane; neither is designed here.

Step shape: client-only web work, server cores and protocol rides are split into separate steps. A step that touches the protocol chain is tagged `[protocol]`. In this file that is seven steps: five normal and two LATE.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-WEB-1.a | Served `/model` and `/rename` reach the session summary | S | [protocol] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php] | none |
| C-WEB-1.b | Status-bar model select never shows an unapplied pick | S | [hot:candy-code-web/src-web/components/StatusBar.vue] | none |
| C-WEB-1.c | `@`-mention index refreshes at every turn end (TUI + serve) | S | [hot:candy-code/src/Host/TurnRunner.php] | none |
| C-WEB-1.d | Read-image MediaCards adopt into the session media store | M | [hot:candy-code/src/Host/TranscriptProjector.php] [docs:MEDIA.md] | none |
| C-WEB-2.a | `/btw` over `command.exec` while a turn runs | S | [hot:candy-code/src/Host/SessionHost.php] [docs:SERVER.md] | none |
| C-WEB-2.b | Typed `/skill-name` on served sessions (shared rewrite) | M | [chat:skillInvocationDraft] [hot:candy-code/src/Host/SessionHost.php] [docs:SKILLS.md] | none |
| C-WEB-2.c | Typed `/skill-name` and `$name` in `-p` | M | [headless] [docs:SKILLS.md] | C-WEB-2.b |
| C-WEB-2.d | Skills in the web `/` menu (`command.list` `source:"skill"`) | S | [protocol] | C-WEB-2.b |
| C-WEB-2.e | `/help` and `/budget` host bodies | M | [commands] [lang:host.help.,host.budget.] [hot:candy-code/src/Host/SessionHost.php] | none |
| C-WEB-2.f | `/effort` host body over a shared effort dial | M | [commands] [chat:handleEffortCommand] [lang:chat.effort.] | C-PRV-1 |
| C-WEB-2.g | `/review` and `/security-review` host body | S | [commands] | C-AGT-2 |
| C-WEB-3.a | Memory History & Undo (`memory.log`/`memory.undo`) | S | none | none |
| C-WEB-3.b | Files panel + read-only file viewer (`files.read`) | M | [hot:candy-code-web/src-web/components/panels/SidePanels.vue] [hot:candy-code-web/src-web/stores/agents/panels.ts] [hot:candy-code-web/src-web/components/panels/ChangesPanel.vue] | none |
| C-WEB-3.c | Live workflow run status (`workflow.status`) | S | none | none |
| C-WEB-3.d | Close a non-primary root's host (`workspace.close`) | S | [hot:candy-code-web/src-web/components/SessionSidebar.vue] [hot:candy-code-web/src-web/stores/sessions.ts] | none |
| C-WEB-3.e | Share link / Revoke on the session row | S | [hot:candy-code-web/src-web/components/SessionSidebar.vue] [hot:candy-code-web/src-web/stores/sessions.ts] | C-SES-3 |
| C-WEB-3.f | Video tab in the Generate panel (`generate.submit` kind) | M | [protocol] [hot:candy-code-web/src-web/components/panels/GeneratePanel.vue] [docs:MEDIA.md] | none |
| C-WEB-3.g | Dormant-method roster spec (fail-loud on new uncalled RPCs) | S | [hot:candy-code-web/src-web/__tests__/dormantRoster.spec.ts] | C-WEB-3.a, C-WEB-3.b, C-WEB-3.c, C-WEB-3.d, C-WEB-3.e |
| C-WEB-4.a | Composer: drag-drop images, Ctrl+G history search, paste chips | M | [hot:candy-code-web/src-web/components/Composer.vue] | none |
| C-WEB-4.b | `?` help overlay + web keymap | M | [hot:candy-code-web/src-web/App.vue] [hot:candy-code-web/src-web/components/settings/SettingsView.vue] | none |
| C-WEB-4.c | Transcript toggles: show thinking, expand all tool cards | S | [hot:candy-code-web/src-web/components/ToolCard.vue] | none |
| C-WEB-4.d | Elapsed turn timer in the status bar | S | [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/stores/reducer.ts] | none |
| C-WEB-4.e | PDF upload from the browser (`session.send` `documents[]`) | M | [protocol] [hot:candy-code-web/src-web/components/Composer.vue] | C-WEB-4.a |
| C-WEB-4.f | Branch badge (`SessionSummary.branch`) | S | [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/components/SessionSidebar.vue] | none |
| C-WEB-5.a | `serve --tls-cert/--tls-key` (built-in TLS) | M | [headless] [docs:SERVER.md] [docs:README.md] [lang:cli.serve.tls.] [hot:candy-code/src/Cli/Serve.php] | none |
| C-WEB-5.b | `serve url --qr` pairing QR | M | [headless] [docs:SERVER.md] [lang:cli.serve.qr.] [hot:candy-code/src/Cli/Serve.php] | C-WEB-5.a |
| C-WEB-5.c | Web Push core: VAPID keys, RFC 8291 cipher, allowlisted sender | M | none | none |
| C-WEB-5.d | Web Push wiring: `push.*` RPCs, subscription store, triggers | M | [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [docs:SERVER.md] | C-WEB-5.c |
| C-WEB-5.e | Web Push client: service worker + consent toggle | M | [hot:candy-code-web/src-web/components/settings/SettingsView.vue] | C-WEB-5.d |
| C-WEB-5.f | `server.logs` tail RPC + About-modal Logs tab | M | [protocol] [hot:candy-code/src/Cli/Serve.php] [hot:candy-code-web/src-web/components/AboutModal.vue] | C-SES-3 |
| C-WEB-5.g | Confirmed "Stop server" for Admin tokens | S | [hot:candy-code-web/src-web/components/AboutModal.vue] [hot:candy-code-web/src-web/__tests__/dormantRoster.spec.ts] | C-WEB-3.g |

Totals: 31 steps (24 normal, 7 LATE): 16 S, 15 M, 0 L.

---

## C-WEB-1 — Web stale-state fixes

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

### C-WEB-1.b — Status-bar model select never shows an unapplied pick
- item: C-WEB-1 (sources: WEB-2-P1 client half)
- kind: fix · MAJOR · S
- approach:
  - **Root cause.** `pickModel()` (`candy-code-web/src-web/components/StatusBar.vue:52-55`) emits `/model` and leaves the `<select>` DOM showing the pick. The bound `:value` (the summary) did not change, so Vue never repaints. A refused or still-pending switch therefore displays a model the session is not using.
  - **Fix.** Right after the emit, `pickModel()` writes `modelOptionValue(currentProvider)` back into the element. The select then repaints only from the summary, which C-WEB-1.a now updates.
  - **Independence.** The step does not need C-WEB-1.a; it is most useful after it.
- files.create: none
- files.modify: `candy-code-web/src-web/components/StatusBar.vue` (`pickModel`)
- tests: modify `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`. It pins three things:
  - after picking B, the DOM value is the summary's A;
  - a summary prop change to B repaints;
  - picking A afterwards emits `/model A …`.
- docs/guards: vitest; dist rebuild (see Notes).
- serialized: [hot:candy-code-web/src-web/components/StatusBar.vue]
- deps: none
- phase: normal
- ruling: none
- risks: A test that drives `change` with a synthetic event must set `target.value` first, or the reset is invisible to it.

### C-WEB-1.c — `@`-mention index refreshes at every turn end (TUI + serve)
- item: C-WEB-1 (sources: WEB-5-P2, TUI-1-P1, p2-miss: @-mention freshness)
- kind: fix · MAJOR · S
- approach:
  - **Root cause.** `MentionIndex::files()` (`candy-code/src/Attachments/MentionIndex.php:103-111`) memoizes one `git ls-files` per root per process. `forget()` (:540-560), "the hook a future refresh command would call", has only test callers. `FsMethods.php:136-137` routes every git-root `fs.search` through the memo.
  - **Effect.** A file the agent writes never appears in the TUI popup or in the web `@` list until restart.
  - **Fix.** Wire the dormant hook. `TurnRunner::complete()` (`src/Host/TurnRunner.php:891`) calls `MentionIndex::forget()` as its first statement, so cancelled turns count too. TurnRunner is the one settle seam both Chat and SessionHost share, which fixes TUI and serve together. The next `@` re-lists, `--others` included, so untracked files from Write, ApplyPatch or Bash appear.
  - **Docblock.** Truth-flip the class docblock (:19-27, "invisible … until the process restarts").
- files.create: `candy-code/tests/Host/TurnRunnerMentionFreshnessTest.php`
- files.modify: `candy-code/src/Host/TurnRunner.php` (`complete()`), `candy-code/src/Attachments/MentionIndex.php` (class + `forget()` docblocks)
- tests: create `candy-code/tests/Host/TurnRunnerMentionFreshnessTest.php`: a file written while a started turn runs is absent before `complete()` and present after it, untracked included. Modify `candy-code/tests/Server/FsSearchTest.php`: search, create an untracked file, finish a turn, search again, and the file is listed with no `forget()` in the test body.
- docs/guards: MentionIndex docblock only (no README/docs prose pins the staleness).
- serialized: [hot:candy-code/src/Host/TurnRunner.php]
- deps: none
- phase: normal
- ruling: none
- risks: `forget()` is process-wide, so in serve every root re-lists once after any session's turn. That costs one bounded `git ls-files` (10 s GitRunner ceiling), the same as today's first `@`.

### C-WEB-1.d — Read-image MediaCards adopt into the session media store
- item: C-WEB-1 (sources: GAP-TL-19 web half, k2-sum `media.fetch` live defect, k2-dorm broken Read-image path)
- kind: fix · MAJOR · M
- approach:
  - **Root cause.** `TranscriptProjector::mediaRefs()` (`candy-code/src/Host/TranscriptProjector.php:301-303`, `mediaRef()` :351-364) projects Read's workspace `imagePath` (set at `src/Tools/BuiltIn/Read.php:745`) as an artifact named by its basename stem. `MediaMethods::pathForArtifact()`/`containSessionPath()` (`src/Protocol/Methods/MediaMethods.php:499-530`) serve only the session MediaStore. The card therefore answers `media_artifact_not_found`, or shows the wrong image when a stored artifact shares the stem.
  - **New store method.** `mediaRefs()`/`mediaRefsForPaths()` gain an optional `?MediaStore`. A head path outside the store that carries bytes is adopted through a new `MediaStore::adopt(string $stem, string $bytes, string $ext)`. Bytes ride live and are persisted as `imageBytesBase64` (`src/Message.php:1148`).
  - **Naming.** `adopt()` is idempotent: the name is deterministic, `read-<sha256(toolResult id|path) prefix>.<ext>`, and an existing copy is reused. The ref then names the copy. A ref with no bytes projects nothing, so no card ever renders that cannot load.
  - **Wiring.** `toolFinishedEvent()` builds the store from its `$sessionId`. `SessionSnapshot::mediaBearingRow()` (`src/Host/SessionSnapshot.php:161-180`) passes the same store, so the live view and a reload agree (R16(a)).
  - **What stays the same.** There is no new file read (bytes come from the ToolResult), and the TUI still paints from `imagePath`.
- files.create: none
- files.modify: `candy-code/src/Host/TranscriptProjector.php` (`mediaRefs`, `mediaRefsForPaths`, `toolFinishedEvent`), `candy-code/src/Host/SessionSnapshot.php` (`mediaBearingRow`), `candy-code/src/Support/MediaStore.php` (new `adopt()`), `candy-code/docs/MEDIA.md` (the `tool.finished` media-refs paragraph, ~:182-186)
- tests: modify `candy-code/tests/Host/TranscriptProjectorMediaTest.php`, which pins four cases:
  - a Read image is adopted, and its ref sits under the store dir;
  - a second projection reuses the same artifactId;
  - the snapshot projects the same id;
  - an unadoptable path gives no ref.

  Modify `candy-code/tests/Protocol/MediaMethodsTest.php`: a Read-image ref taken from `tool.finished` fetches through `media.fetch`.
- docs/guards: `docs/MEDIA.md`. `tests/Support/ReadPathCensusTest.php` is unaffected (no new read sink); confirm it stays green.
- serialized: [hot:candy-code/src/Host/TranscriptProjector.php] [docs:MEDIA.md]
- deps: none
- phase: normal
- ruling: none
- risks:
  - The projector now writes up to Read's image cap per Read image on the TUI update path.
  - A snapshot of an old session creates its media dir on first read.

## C-WEB-2 — Served command parity

### C-WEB-2.a — `/btw` over `command.exec` while a turn runs
- item: C-WEB-2 (sources: GAP-CTX-11w, k1-sum (c))
- kind: gap · S
- approach:
  - **Root cause.** `SessionHost::runCommand()` (`candy-code/src/Host/SessionHost.php:792-793`) refuses every built-in while busy, except workflow control. It bypasses `TurnController::midTurnRoute()` (`src/Host/TurnController.php:303-330`), which `admitWhileBusy()` (:852-861) already applies.
  - **Effect.** A `/btw` typed into `session.send` works mid-turn. The web, however, routes built-ins through `command.exec` (`candy-code-web/src-web/stores/session.ts:169-180`) and is refused.
  - **Fix.** `runCommand()` consults the same decision table and admits `ROUTE_WORKFLOW_CONTROL` and `ROUTE_SIDE_QUESTION`. "One decision table, two doors" — no CommandSpec field and no registry edit.
  - **What the user sees:** `/btw` answers beside a running turn in the web.
- files.create: `candy-code/tests/Host/BtwMidTurnTest.php`
- files.modify: `candy-code/src/Host/SessionHost.php` (`runCommand`), `candy-code/docs/SERVER.md` (the `command.exec` paragraph ~:589-597: "`/workflow pause|status` inside its own run and `/btw` excepted")
- tests: create `candy-code/tests/Host/BtwMidTurnTest.php`: while busy, `runCommand('btw', 'q')` is not refused and holds the turn; `runCommand('clear')` is still refused.
- docs/guards: `docs/SERVER.md` prose (not a generated table).
- serialized: [hot:candy-code/src/Host/SessionHost.php] [docs:SERVER.md]
- deps: none
- phase: normal
- ruling: none
- risks: The UI-only rows `/btw` appends mid-turn must survive the turn's settle and save. The test should assert this after the turn completes.

### C-WEB-2.b — Typed `/skill-name` on served sessions (shared rewrite)
- item: C-WEB-2 (sources: GAP-SKL-06, p1-miss: VIS-5 parity)
- kind: gap · M
- approach:
  - **Gap.** Only `Chat::submit()` rewrites `/name args` into the `$name` mention channel (`candy-code/src/Chat.php:9586`, `skillInvocationDraft()` :11318). Served sessions send it as prose.
  - **Lift.** Move the body into a pure `Skills\SkillSlashInvocation::draft(string $text, ?SkillRegistry $registry): ?string`. It keeps the built-in-spelling guard and the colon longest-first walk. `Chat::skillInvocationDraft()` becomes a one-line delegate, so the TUI is byte-identical.
  - **Serve.** `SessionHost::admit()` (:892-932) calls it after `$this->command($text)` returns null and before `admitPrompt()`, using `$this->workspace->skills`. Precedence matches the TUI: file commands, then built-ins, then skills.
- files.create: `candy-code/src/Skills/SkillSlashInvocation.php`, `candy-code/tests/Skills/SkillSlashInvocationTest.php`, `candy-code/tests/Host/ServedSkillSlashTest.php`
- files.modify: `candy-code/src/Chat.php` (`skillInvocationDraft` body → delegate), `candy-code/src/Host/SessionHost.php` (`admit`), `candy-code/docs/SKILLS.md` (§"`$name` in a prompt", ~:578-584: the rewrite runs on served sessions too)
- tests:
  - Create `candy-code/tests/Skills/SkillSlashInvocationTest.php`. Pure pins: a built-in spelling stays prose; `ns:sub` is walked longest-first; a disabled or non-invocable skill gives null.
  - Create `candy-code/tests/Host/ServedSkillSlashTest.php`: `session.send "/my-skill x"` attaches the skill and raises the `✨ Loaded skill:` notice.
  - The existing `candy-code/tests/Skills/SlashSkillInvocationTest.php` must stay green unedited (TUI parity).
- docs/guards: `docs/SKILLS.md`.
- serialized: [chat:skillInvocationDraft] [hot:candy-code/src/Host/SessionHost.php] [docs:SKILLS.md]
- deps: none
- phase: normal
- ruling: none
- risks: The refusal notice's skill half (`Chat.php:11409`) still reads the old method name in a docblock. Keep the delegate's name.

### C-WEB-2.c — Typed `/skill-name` and `$name` in `-p`
- item: C-WEB-2 (sources: p1-miss: VIS-5 parity (`-p`), GAP-SKL-06)
- kind: gap · M
- approach:
  - **Gap.** `NonInteractive::historyFrom()` (`candy-code/src/Cli/NonInteractive.php`, ~:1375-1410) resolves only `@file` and `@keyword` mentions. `-p` has no `$name` skill channel at all, and no `/name` rewrite.
  - **Fix.** Apply `SkillSlashInvocation::draft()` to the typed prompt, never to stdin context. Then resolve `$name` with `SkillMentions::new($registry)->resolve()` against the skill registry the `-p` backend was built with, which is the same law as `TurnController::userTurnMessage()` :1053-1056. Skill attachments join the message, and notices go out through the existing `noticeAttachment()`.
  - **What the user sees:** `candy-code -p "/security-audit src/"` loads the skill as the TUI does.
- files.create: `candy-code/tests/Cli/NonInteractiveSkillSlashTest.php`
- files.modify: `candy-code/src/Cli/NonInteractive.php` (`historyFrom` + its call site ~:443-455), `candy-code/docs/SKILLS.md` (same paragraph: "and `-p`")
- tests: create `candy-code/tests/Cli/NonInteractiveSkillSlashTest.php`. It pins:
  - `/skill args` and `$skill args` attach the body;
  - `$HOME` stays text;
  - stdin text is never scanned.
- docs/guards: `docs/SKILLS.md`. If README's `-p` section lists the mention kinds `-p` resolves, it moves too (`candy-code/tests/Config/ReadmeRosterDriftTest.php` re-run).
- serialized: [headless] [docs:SKILLS.md]
- deps: C-WEB-2.b
- phase: normal
- ruling: none
- risks: The registry handle may need a small accessor on the `-p` backend path. If one is added in `Bootstrap.php`, the step gains `[bootstrap:<method>]`; avoid it by reading the registry off the EngineBackend.

### C-WEB-2.d — Skills in the web `/` menu (`command.list` `source:"skill"`)
- item: C-WEB-2 (sources: GAP-SKL-06 (menu half))
- kind: gap · S
- approach:
  - **Server.** `CommandMethods::list()` (`candy-code/src/Protocol/Methods/CommandMethods.php:48-62`) adds one row per user-invocable skill: `source:"skill"`, `runsIn:"server"`. Built-in and file names win a clash, as they do at admission.
  - **Schema.** The `source` enum (`src/Protocol/Schema/MethodSchemas.php:267`) gains `skill`.
  - **Client.** The Composer filter (`Composer.vue:54`) and the palette filter (`CommandPalette.vue:52`) already admit `runsIn:"server"` rows. `stores/session.ts send()` already sends non-built-ins as `session.send`, and the host rewrites them after C-WEB-2.b. So the client change is the `CommandInfo.source` union only.
- files.create: `candy-code/tests/Protocol/CommandListSkillRowsTest.php`
- files.modify: `candy-code/src/Protocol/Methods/CommandMethods.php` (`list`), `candy-code/src/Protocol/Schema/MethodSchemas.php` (`command.list` source enum), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/stores/session.ts` (`CommandInfo.source`)
- tests: create `candy-code/tests/Protocol/CommandListSkillRowsTest.php`. It pins that a user-invocable skill is listed, while a disabled one and a built-in-named one are not. Modify `candy-code-web/src-web/components/__tests__/components.spec.ts`: a skill row completes in the composer and sends via `session.send`.
- docs/guards: the protocol gates (as in C-WEB-1.a); `tests/Config/ServerProtocolDocumentationDriftTest.php`.
- serialized: [protocol]
- deps: C-WEB-2.b
- phase: normal
- ruling: none
- risks: GAP-CMD-02 (another area) edits `CommandMethods::files()` for the trust flag. The two steps are protocol-serialized anyway.

### C-WEB-2.e — `/help` and `/budget` host bodies
- item: C-WEB-2 (sources: GAP-CMD-01, p1-miss/p2-miss parity)
- kind: gap · M
- approach:
  - **Mechanism.** Admission flips by construction: `runsIn` is "server iff `hostCommand`" (`CommandMethods.php:117-120`).
  - **HelpHostCommand.** It renders `CommandRegistry` slash rows grouped one heading per category (the property the TUI pins), plus the workspace's file commands loaded as `CommandMethods::files()` does. It marks body-less rows "(terminal only)" and does not clip columns.
  - **BudgetHostCommand, bare form.** Answers `SpendLedger::statusLine()` (`src/Host/SpendLedger.php:215`) over the session's own TokenTracker against the launch cap `$workspace->maxCostUsd`.
  - **BudgetHostCommand, amount and `off` forms.** These refuse out loud (`host.budget.server_cap`): the serve cap is the launch's `--max-cost`, shared by every session. The refusal is explicit, never set-but-ignored.
  - **Plumbing.** `CommandContext` gains an optional `spend` TokenTracker, passed by `SessionHost::commandContext()`.
- files.create: `candy-code/src/Host/Commands/HelpHostCommand.php`, `candy-code/src/Host/Commands/BudgetHostCommand.php`, `candy-code/tests/Host/Commands/HelpHostCommandTest.php`, `candy-code/tests/Host/Commands/BudgetHostCommandTest.php`
- files.modify: `candy-code/builtin-commands/1100-help.php` (`->withHostCommand`), `candy-code/builtin-commands/2200-budget.php` (`->withHostCommand`), `candy-code/src/Host/Commands/CommandContext.php` (optional `spend`), `candy-code/src/Host/SessionHost.php` (`commandContext()`), `candy-code/lang/en.php` (`host.help.*`, `host.budget.*`)
- tests:
  - Create `candy-code/tests/Host/Commands/HelpHostCommandTest.php`. It pins that the spelling carries a host body, that there is one heading per category, that file commands are listed, and that terminal-only rows are marked.
  - Create `candy-code/tests/Host/Commands/BudgetHostCommandTest.php`. It pins:
    - the bare form shows spend vs the cap;
    - an unpriced session reads `≥`;
    - `/budget 5` refuses with the server-cap reason and leaves the cap unchanged.
- docs/guards:
  - `tests/Commands/BuiltInCommandsTest.php` (every hostCommand is a HostCommand) must stay green;
  - `tests/LangParityTest.php`;
  - `docs/COMMANDS.md` is unaffected (the generator renders no runs-in column);
  - no roster counts.
- serialized: [commands] [lang:host.help.,host.budget.] [hot:candy-code/src/Host/SessionHost.php]
- deps: none
- phase: normal
- ruling: none
- risks: A per-session tighten-only cap from the web is deliberately out of scope. Flag it to the owner as a follow-up, not as a blocker.

### C-WEB-2.f — `/effort` host body over a shared effort dial
- item: C-WEB-2 (sources: GAP-MOD-15w (`/effort` half), GAP-CMD-01)
- kind: gap · M
- approach:
  - **Gap.** `/effort` (`candy-code/builtin-commands/0350-effort.php`) has no host body. Its logic (`Chat::handleEffortCommand`, `src/Chat.php:12234`) is the ShapesReasoningEffort judgement C-PRV-1 makes honest.
  - **Lift.** Move the decision into a pure `Commands\EffortDial`: args + EngineBackend → reply text, next backend, and persist intent (level or delete). Chat delegates to it.
  - **EffortHostCommand.** Answers the same rows and applies the backend through the existing `CommandEffect::setModel($provider, $model, $engine->withReasoningEffort(...))`. Same provider and model, so no new effect kind. It persists through the workspace SettingsWriter, the same door `ModelHostCommand` uses (`src/Host/Commands/ModelHostCommand.php:62-67`).
  - **If C-PRV-1 already lifted an equivalent dial,** reuse it and drop both the create and the `[chat]` touch.
- files.create: `candy-code/src/Commands/EffortDial.php`, `candy-code/src/Host/Commands/EffortHostCommand.php`, `candy-code/tests/Commands/EffortDialTest.php`, `candy-code/tests/Host/Commands/EffortHostCommandTest.php`
- files.modify: `candy-code/builtin-commands/0350-effort.php` (`->withHostCommand`), `candy-code/src/Chat.php` (`handleEffortCommand` → delegate), `candy-code/lang/en.php` (only if the dial needs a host-side key; the `chat.effort.*` keys are reused)
- tests:
  - Create `candy-code/tests/Commands/EffortDialTest.php`. It pins:
    - the bare form per provider shape;
    - an unsupported provider refuses and never persists;
    - `auto` clears.
  - Create `candy-code/tests/Host/Commands/EffortHostCommandTest.php`. It pins that the spelling carries a host body, that a served `/effort high` changes the session backend's `reasoningEffort()`, and that it persists through the writer.
- docs/guards:
  - check `tests/Config/ConfigWriteProducerDocumentationDriftTest.php` and the `docs/SETTINGS.md` producer prose for the `reasoningEffort` writer list (a second door to the same key);
  - `tests/LangParityTest.php`.
- serialized: [commands] [chat:handleEffortCommand] [lang:chat.effort.]
- deps: C-PRV-1
- phase: normal
- ruling: none
- risks: C-PRV-1 edits the same Chat method. Land strictly after it, and re-read the method rather than the audit's line numbers.

### C-WEB-2.g — `/review` and `/security-review` host body
- item: C-WEB-2 (sources: GAP-GIT-10, GAP-CMD-01, p2-miss: `/review` on serve/web/headless)
- kind: gap · S
- approach:
  - **Gap.** Both specs (`candy-code/builtin-commands/2840-review.php`, `2845-security-review.php`) are Chat-only (`Chat::reviewTurn()`, `src/Chat.php:11760-11800`).
  - **ReviewHostCommand.** One class serves both spellings. It validates the ref with the ref validator C-AGT-2 fixes (`ContextMentions::isDiffRef` today), answering `cmd.review.bad-ref` on a miss. Otherwise it returns `CommandEffect::submitPrompt(ReviewCommand::prompt($ref, $security))`, the exact shape of `InitHostCommand` (`src/Host/Commands/InitHostCommand.php:22-31`). It refuses when the session is read-only.
  - **What the user sees:** `/review` and `/security-review` run in the web, and the diff attaches.
- files.create: `candy-code/src/Host/Commands/ReviewHostCommand.php`, `candy-code/tests/Host/Commands/ReviewHostCommandTest.php`
- files.modify: `candy-code/builtin-commands/2840-review.php`, `candy-code/builtin-commands/2845-security-review.php` (`->withHostCommand`)
- tests: create `candy-code/tests/Host/Commands/ReviewHostCommandTest.php`. It pins:
  - both spellings carry the body;
  - a bad ref refuses with no turn billed;
  - a served `/review` turn's user message carries the `@diff` attachment (SessionHost's SubmitPrompt → `admitPrompt` resolves mentions);
  - a C-AGT-2 range form attaches a non-empty diff.
- docs/guards: `tests/Commands/BuiltInCommandsTest.php`.
- serialized: [commands]
- deps: C-AGT-2
- phase: normal
- ruling: none
- risks: `-p` has no slash dispatch, so `/review` in headless stays out of scope (see Notes).

## C-WEB-3 — Wire uncalled web RPCs

### C-WEB-3.a — Memory History & Undo (`memory.log`/`memory.undo`)
- item: C-WEB-3 (sources: k1-dorm#4, k3-dorm#3, WEB-1-P1, p2-miss: WEB-1 residual dormant methods)
- kind: dormant-wire · S
- approach:
  - **Gap.** `memory.log` and `memory.undo` (`candy-code/src/Protocol/MethodSchemas`/`MemoryMethods.php:70-71`) have no browser caller.
  - **Store.** `stores/agents/memory.ts` gains `log(limit)` and `undo(revision?)`.
  - **Panel.** `MemoryPanel.vue` gains a History tab beside the scope tabs, listing `shortSha · committedAt · subject` as plain text spans. Undo to a revision is shown only with `write` scope, behind a confirm. After an undo, the current scope list reloads.
- files.create: `candy-code-web/src-web/components/panels/__tests__/memoryHistory.spec.ts`
- files.modify: `candy-code-web/src-web/stores/agents/memory.ts`, `candy-code-web/src-web/components/panels/MemoryPanel.vue`
- tests: create `candy-code-web/src-web/components/panels/__tests__/memoryHistory.spec.ts`. It pins three things:
  - History lists `memory.log` rows;
  - Undo sends `memory.undo {revision}` and reloads;
  - Undo is hidden without write scope.
- docs/guards: `candy-code-web/README.md` side-panel bullet (soft, see Notes); vitest; dist rebuild.
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: `memory.log` commits pending changes first (its schema says so). The tab should load on open, not on every panel mount.

### C-WEB-3.b — Files panel + read-only file viewer (`files.read`)
- item: C-WEB-3 (sources: k3-dorm#3 (`files.read`), p2-miss: WEB-8 Files panel, WEB-1-P1)
- kind: dormant-wire · M
- approach:
  - **FilesPanel.** A new Files side panel: a search box over the existing `fs.search`, through `stores/session.ts searchFiles()`; empty lists the front of the project. Clicking a row opens a shared `FileViewer`.
  - **FileViewer.** Uses `files.read {path, offset, limit}`. Content renders as plain text in a `<pre>` with line numbers — never `v-html`, per the ChangesPanel security posture. `binary: true` shows a size notice; `truncated` offers "load more" by offset.
  - **ChangesPanel.** Changed-file rows get a "view" action that opens the same viewer.
  - **Registration.** `PANELS` and `PANEL_LABELS` gain `files`.
- files.create: `candy-code-web/src-web/components/panels/FilesPanel.vue`, `candy-code-web/src-web/components/FileViewer.vue`, `candy-code-web/src-web/components/panels/__tests__/filesPanel.spec.ts`
- files.modify: `candy-code-web/src-web/stores/agents/panels.ts` (`PANELS`, `PANEL_LABELS`), `candy-code-web/src-web/components/panels/SidePanels.vue` (tab + mount), `candy-code-web/src-web/components/panels/ChangesPanel.vue` (row "view")
- tests:
  - Create `candy-code-web/src-web/components/panels/__tests__/filesPanel.spec.ts`. It pins: search → rows; open → `files.read`; HTML in the content is rendered as text; binary notice; load-more offset.
  - Modify `candy-code-web/src-web/components/panels/__tests__/changesPanel.spec.ts`: "view" opens the viewer for that path.
- docs/guards: `candy-code-web/README.md` ("ten" side panels → eleven; soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/panels/SidePanels.vue] [hot:candy-code-web/src-web/stores/agents/panels.ts] [hot:candy-code-web/src-web/components/panels/ChangesPanel.vue]
- deps: none
- phase: normal
- ruling: none
- risks: A stored `panels.active` of `files` on an older bundle falls back to `agents`, which is already handled. Watch `panels.spec.ts` if it pins the PANELS length.

### C-WEB-3.c — Live workflow run status (`workflow.status`)
- item: C-WEB-3 (sources: k3-dorm#3 (`workflow.status`), WEB-1-P1)
- kind: dormant-wire · S
- approach:
  - **Gap.** `workflow.status` (`WorkflowMethods.php:59`) has no caller. A run row's status comes from the transcript-derived `workflow.runs`, so it goes stale while a run is live.
  - **Fix.** `stores/agents/workflows.ts` gains `status(workflowId)`. For an open running row with a `workflowId`, `WorkflowPanel.vue` polls it every 2 s.
  - **Polling lifetime.** The client interval is cleared on close, unmount or session change. When the status turns terminal, `loadRuns()` reloads the row to pick up its report.
- files.create: `candy-code-web/src-web/components/panels/__tests__/workflowStatus.spec.ts`
- files.modify: `candy-code-web/src-web/stores/agents/workflows.ts`, `candy-code-web/src-web/components/panels/WorkflowPanel.vue`
- tests: create `candy-code-web/src-web/components/panels/__tests__/workflowStatus.spec.ts` (fake timers): polling runs only while a row is running, stops on terminal and reloads runs, and stops on unmount.
- docs/guards: vitest; dist rebuild.
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: A `tool`-source run without a `workflowId` stays unpolled. That is by design.

### C-WEB-3.d — Close a non-primary root's host (`workspace.close`)
- item: C-WEB-3 (sources: k3-dorm#3 (`workspace.close`), WEB-1-P1)
- kind: dormant-wire · S
- approach:
  - **Gap.** `workspace.close {root, force?}` (`WorkspaceMethods.php:37`) has no caller. The gateway's idle recycle is today's only release.
  - **Menu item.** Rows whose `summary.root` is not the server root (`SessionSidebar.vue elsewhere()`, :38-41) get a row-menu item "Close `<root>/` host".
  - **Confirm flow.** It asks for confirmation first. If the server refuses because a turn is running, a second confirm sends `force: true`. Afterwards, `stores/sessions.ts` drops that root's rows and reloads.
- files.create: `candy-code-web/src-web/components/__tests__/sidebarCloseRoot.spec.ts`
- files.modify: `candy-code-web/src-web/stores/sessions.ts` (`closeRoot`), `candy-code-web/src-web/components/SessionSidebar.vue` (row menu)
- tests: create `candy-code-web/src-web/components/__tests__/sidebarCloseRoot.spec.ts`. It pins:
  - the item appears only on non-primary rows;
  - the refused → force confirm path;
  - the rows reload.
- docs/guards: `candy-code-web/README.md` sessions-sidebar bullet (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/SessionSidebar.vue] [hot:candy-code-web/src-web/stores/sessions.ts]
- deps: none
- phase: normal
- ruling: none
- risks: C-WEB-7 (another lane) edits the same row menu (Export over 4 MiB). Serialize the two.

### C-WEB-3.e — Share link / Revoke on the session row
- item: C-WEB-3 (sources: k1-dorm#5, SES-3-P4, WEB-1-P1, p2-miss: cross-session web share UI)
- kind: dormant-wire · S
- approach:
  - **Gap.** `session.share` and `session.unshare` (`SessionMethods.php:57-58`) have no caller.
  - **Share.** The row menu gets "Share page…". It calls `session.share {sessionId, days?}` and shows `origin + path` with expiry and a copy button. The token and URL render as plain text.
  - **Revoke.** The menu gets "Revoke share", which calls `session.unshare {token}`. Existing links come from C-SES-3's cross-session share listing, so the browser never keeps share tokens in localStorage.
  - **Delete.** A delete no longer needs a client-side revoke, because C-SES-3 revokes on delete.
- files.create: `candy-code-web/src-web/components/__tests__/sidebarShare.spec.ts`
- files.modify: `candy-code-web/src-web/stores/sessions.ts` (`share`, `unshare`, listing via C-SES-3's method), `candy-code-web/src-web/components/SessionSidebar.vue` (row menu + share dialog)
- tests: create `candy-code-web/src-web/components/__tests__/sidebarShare.spec.ts`. It pins: share shows the URL; revoke sends the token; the items are hidden without write scope; no token reaches storage.
- docs/guards: `candy-code-web/README.md` sessions-sidebar bullet (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/SessionSidebar.vue] [hot:candy-code-web/src-web/stores/sessions.ts]
- deps: C-SES-3
- phase: normal
- ruling: none
- risks: If C-SES-3 ships no listing RPC, Revoke can only offer the link minted in this tab. Re-scope then instead of persisting tokens client-side.

### C-WEB-3.f — Video tab in the Generate panel (`generate.submit` kind)
- item: C-WEB-3 (sources: k3-dorm#4, k3-sum (Generate form image-only))
- kind: dormant-wire · M
- approach:
  - **Gap.** `canGenerateVideo` (`candy-code-web/src-web/stores/connection.ts:91`) is read by nothing. `GeneratePanel.vue:34` gates on image only. `generate.submit` (`candy-code/src/Protocol/Methods/MediaMethods.php:166-232`) always runs `GenerateImage` with `MediaJob::new(MediaKind::Image)`.
  - **Server.** `generate.submit` gains `kind: image|video` (default image) and the video fields `GenerateVideo` takes (`motion`, `cfg2`, frames/fps as its schema names them). The handler forwards each kind's own key set.
  - **Video path.** A video job runs `GenerateVideo::new()->withStoreSession($sessionId)` — a new seam mirroring `GenerateImage` — inside the existing forked `$run` and consent gate.
  - **Client.** The panel shows Image and Video tabs gated on the two capability flags. Video artifacts render through MediaCard's video kind.
- files.create: `candy-code/tests/Protocol/GenerateVideoSubmitTest.php`
- files.modify: `candy-code/src/Protocol/Methods/MediaMethods.php` (`submit`), `candy-code/src/Protocol/Schema/MethodSchemas.php` (`generate.submit`), `candy-code/src/Tools/BuiltIn/GenerateVideo.php` (`withStoreSession`), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/stores/generate.ts` (`submit` kind), `candy-code-web/src-web/components/panels/GeneratePanel.vue` (tabs), `candy-code/docs/MEDIA.md` (:173, "video is TUI-side only in v1")
- tests:
  - Create `candy-code/tests/Protocol/GenerateVideoSubmitTest.php`. It pins:
    - `kind: video` writes a video ledger and stores into the session store;
    - a server without `mediaKinds` video refuses;
    - an image submit is unchanged.
  - Modify `candy-code-web/src-web/components/panels/__tests__/generatePanel.spec.ts`: the video tab appears only with `canGenerateVideo` and submits `kind: video`.
- docs/guards: the protocol gates; `docs/MEDIA.md`; `candy-code/tests/Server/HelloMediaFlagsTest.php` stays green; no new fork site (rides `TurnController::forkPayload`), so no child-lifetime row.
- serialized: [protocol] [hot:candy-code-web/src-web/components/panels/GeneratePanel.vue] [docs:MEDIA.md]
- deps: none
- phase: normal
- ruling: none
- risks: P-IMAGE-MENU (pre-pick) may touch the same GeneratePanel. Also, video renders are minutes long inside the fork. Keep no total timeout (E646 spirit); only the existing poll budget applies.

### C-WEB-3.g — Dormant-method roster spec (fail-loud on new uncalled RPCs)
- item: C-WEB-3 (sources: WEB-1-P1 (fix: dormancy roster spec))
- kind: dormant-wire · S
- approach:
  - **What the spec derives.** One spec: the `METHOD_SCOPES` keys (`protocol/generated.ts`) minus every method a shipped module calls. It reuses the `shutdownDormant.spec.ts` source-scan regex (`request('M'` / `method: 'M'`).
  - **What it pins.** The exact deliberate-dormant set, `{server.shutdown}`, with a one-line reason per entry.
  - **Why.** A method a later lane adds with no browser caller now fails loudly until it is wired or declared. `shutdownDormant.spec.ts` stays as the behavioural pin.
- files.create: `candy-code-web/src-web/__tests__/dormantRoster.spec.ts`
- files.modify: none
- tests: create `candy-code-web/src-web/__tests__/dormantRoster.spec.ts` (the derivation plus the pinned set).
- docs/guards: vitest. The `candy-code/COMPARISON.md` §6.7 wording is the orchestrator's (see Notes).
- serialized: [hot:candy-code-web/src-web/__tests__/dormantRoster.spec.ts]
- deps: C-WEB-3.a, C-WEB-3.b, C-WEB-3.c, C-WEB-3.d, C-WEB-3.e
- phase: normal
- ruling: none
- risks: Methods other areas add before this lands (C-SEC-*, C-MCP-3, C-SES-7/8, P-CTX-BREAKDOWN) must already have callers or join the set. Re-derive at landing.

## C-WEB-4 — Composer & chrome polish

### C-WEB-4.a — Composer: drag-drop images, Ctrl+G history search, paste chips
- item: C-WEB-4 (sources: GAP-UI-12, GAP-UI-09, GAP-UI-11)
- kind: gap · M
- approach: Client-only, three features:
  - **Drag-drop.** `@dragover.prevent`/`@drop` on the composer feeds `readPastedImages(event.dataTransfer)`. A DataTransfer already satisfies `ClipboardFiles` (`lib/paste.ts`). Drops use the same `MAX_PASTED_IMAGES` strip and busy/command refusals.
  - **History search.** Ctrl+G (browsers reserve Ctrl+R) opens a `HistorySearch` overlay. It filters the persisted ring by containment, newest first, with the TUI HistorySearch semantics: Enter fills the draft, Esc restores it.
  - **Paste chips.** A text paste over the TUI's chip threshold becomes a `[Pasted #n +X lines]` placeholder backed by a chip map. Submit expands it through `expandPasteChips()`. Deleting the placeholder drops the chip. History stores the expanded text.
- files.create: `candy-code-web/src-web/components/HistorySearch.vue`, `candy-code-web/src-web/components/__tests__/composerDropAndChips.spec.ts`
- files.modify: `candy-code-web/src-web/components/Composer.vue` (drop target, Ctrl+G, paste branch, submit expansion, placeholder hint), `candy-code-web/src-web/lib/paste.ts` (`pasteChip`, `expandPasteChips`), `candy-code-web/src-web/stores/promptHistory.ts` (`searchPromptHistory`)
- tests:
  - Create `candy-code-web/src-web/components/__tests__/composerDropAndChips.spec.ts`. It pins:
    - a drop adds image chips;
    - Ctrl+G filters, Enter fills, Esc restores;
    - a long paste becomes a chip and the sent text is expanded.
  - Modify `candy-code-web/src-web/lib/__tests__/paste.spec.ts` and `candy-code-web/src-web/stores/__tests__/promptHistory.spec.ts`.
- docs/guards: `candy-code-web/README.md` Composer bullet (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/Composer.vue]
- deps: none
- phase: normal
- ruling: none
- risks: C-WEB-7 (another lane: `fs.search` debounce, history storage event) edits Composer.vue and promptHistory.ts. Serialize the two.

### C-WEB-4.b — `?` help overlay + web keymap
- item: C-WEB-4 (sources: GAP-UI-28, GAP-UI-25)
- kind: gap · M
- approach: Client-only.
  - **Help overlay.** `?` on an empty composer, or outside any input, opens `HelpOverlay`. It lists the web shortcuts plus `command.list` rows with `runsIn` markers. The global capture-phase handler lives in `App.vue`, so `Composer.vue` stays untouched.
  - **Keymap module.** `lib/keymap.ts` holds the rebindable web shortcuts: palette (today hard-coded Ctrl/⌘+K at `CommandPalette.vue:143`), help, and the tab chord (folding `lib/tabChord.ts`'s stored chord in as one entry, with the old storage key migrated).
  - **Storage and editor.** The keymap persists per viewer through `stores/storage.ts`. A small editor sits in SettingsView's client section.
- files.create: `candy-code-web/src-web/components/HelpOverlay.vue`, `candy-code-web/src-web/lib/keymap.ts`, `candy-code-web/src-web/components/__tests__/helpOverlay.spec.ts`, `candy-code-web/src-web/lib/__tests__/keymap.spec.ts`
- files.modify: `candy-code-web/src-web/App.vue` (mount + global `?`), `candy-code-web/src-web/components/panels/CommandPalette.vue` (binding from keymap), `candy-code-web/src-web/lib/tabChord.ts` (read/write through keymap), `candy-code-web/src-web/components/settings/SettingsView.vue` (keymap editor), `candy-code-web/src-web/lib/__tests__/tabChord.spec.ts` (migration)
- tests:
  - Create `candy-code-web/src-web/components/__tests__/helpOverlay.spec.ts`. It pins: `?` on an empty composer opens it; `?` mid-draft types normally; it lists command rows.
  - Create `candy-code-web/src-web/lib/__tests__/keymap.spec.ts`. It pins: defaults; rebinding persists; the old tab-chord key migrates; conflicts are refused.
- docs/guards: `candy-code-web/README.md` (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/App.vue] [hot:candy-code-web/src-web/components/settings/SettingsView.vue]
- deps: none
- phase: normal
- ruling: none
- risks: P-SESSION-TABS / P-CTX-BREAKDOWN may also mount chrome in App.vue. Serialize on the hot tag.

### C-WEB-4.c — Transcript toggles: show thinking, expand all tool cards
- item: C-WEB-4 (sources: GAP-UI-22, GAP-UI-32 (web half))
- kind: gap · S
- approach: Client-only.
  - **Store.** A per-viewer `stores/transcriptView.ts` (localStorage via `stores/storage.ts`) holds `showThinking` and `expandTools`. It is kept separate from `stores/settings.ts` so this step does not collide with C-WEB-4.b.
  - **Toolbar.** `Transcript.vue` gets a two-button toolbar.
  - **Thinking.** `ReasoningFold.vue` uses `:open="live || view.showThinking"`.
  - **Tool cards.** `ToolCard.vue` follows `expandTools` until the user toggles that card; failures and denials still open.
- files.create: `candy-code-web/src-web/stores/transcriptView.ts`, `candy-code-web/src-web/components/__tests__/transcriptToggles.spec.ts`
- files.modify: `candy-code-web/src-web/components/Transcript.vue` (toolbar), `candy-code-web/src-web/components/ReasoningFold.vue`, `candy-code-web/src-web/components/ToolCard.vue` (`open` init/watch)
- tests: create `candy-code-web/src-web/components/__tests__/transcriptToggles.spec.ts`. It pins three things:
  - each toggle flips every fold or card;
  - a manually toggled card keeps its state;
  - the toggles persist across a remount.
- docs/guards: `candy-code-web/README.md` Transcript bullet (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/ToolCard.vue]
- deps: none
- phase: normal
- ruling: none
- risks: The transcript is virtualised (`@tanstack/vue-virtual`), so a remounted row must read the store, not local state alone.

### C-WEB-4.d — Elapsed turn timer in the status bar
- item: C-WEB-4 (sources: GAP-UI-31)
- kind: gap · S
- approach: Client-only.
  - **Reducer.** `stores/reducer.ts` records `turnStartedAt = envelope.ts` on `turn.started` (:416-425) and clears it on `turn.completed`.
  - **Status bar.** `StatusBar.vue` shows `working · 0:42` (with `· step n/m` when known), ticking on a 1 s interval that runs only while busy and is cleared on idle or unmount.
  - **Snapshot resume.** A resumed snapshot mid-turn has no start, so the timer stays hidden rather than guessing.
- files.create: `candy-code-web/src-web/stores/__tests__/turnTimer.spec.ts`, `candy-code-web/src-web/components/__tests__/statusBarTimer.spec.ts`
- files.modify: `candy-code-web/src-web/stores/reducer.ts` (`turn.started`/`turn.completed` arms, SessionState field), `candy-code-web/src-web/components/StatusBar.vue` (`statusText`)
- tests:
  - Create `candy-code-web/src-web/stores/__tests__/turnTimer.spec.ts`: start and clear on the right events; a snapshot resume leaves it null.
  - Create `candy-code-web/src-web/components/__tests__/statusBarTimer.spec.ts` (fake timers): it ticks only while busy.
- docs/guards: `candy-code-web/README.md` status-bar bullet (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/stores/reducer.ts]
- deps: none
- phase: normal
- ruling: none
- risks: reducer.ts is a cross-area hot file (P-AGENT-ATTACH, C-WEB-7), so keep the edit to two arms and one field.

### C-WEB-4.e — PDF upload from the browser (`session.send` `documents[]`)
- item: C-WEB-4 (sources: GAP-MOD-22w)
- kind: gap · M
- approach:
  - **Gap.** PDFs reach the model only by `@path` of a server file. `session.send` carries `images[]` only (`candy-code/src/Protocol/Schema/MethodSchemas.php:206-222`, `TurnMethods.php:90-173`).
  - **Wire.** Add `documents[]` with `{name?, mime: application/pdf, data}`. The server decodes and sniffs `%PDF-` with `FileMentions::sniffDocument()` (refusing a mismatch) and attaches `AttachmentType::Document` through the existing `SubmitOptions::withAttachments()`.
  - **Caps.** The new caps keep the base64 frame under `ServerConfig::MAX_CLIENT_FRAME_BYTES` (16 MiB): at most 2 documents, and one decoded budget shared with images (≈11 MiB), each document also ≤ `FileMentions::DOCUMENT_MAX_BYTES`. A larger PDF keeps the `@path` route.
  - **Refusals.** As for images: `documents_while_busy` and `documents_with_command`.
  - **Client.** A file picker (`accept=application/pdf,image/*`) and the 4.a drop target route PDFs into a documents chip strip.
  - **Wire support.** Only the Anthropic and Vertex-Claude wires carry documents. Others get the existing decline notice; C-TOOL-2 widens that.
- files.create: `candy-code/tests/Protocol/SessionSendDocumentsTest.php`, `candy-code-web/src-web/components/__tests__/composerDocuments.spec.ts`
- files.modify: `candy-code/src/Protocol/Methods/TurnMethods.php` (`decodeDocuments`, caps, refusals), `candy-code/src/Protocol/Schema/MethodSchemas.php` (`session.send`), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen + the `session.send` attachments paragraph), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/lib/paste.ts` (`readDroppedDocuments`), `candy-code-web/src-web/components/Composer.vue` (picker, document strip), `candy-code-web/src-web/stores/session.ts` (`send` signature)
- tests:
  - Create `candy-code/tests/Protocol/SessionSendDocumentsTest.php`. It pins:
    - a PDF attaches as a Document;
    - a mime mismatch, over-budget, busy and `/command` cases refuse with named kinds;
    - images plus documents share the budget.
  - Create `candy-code-web/src-web/components/__tests__/composerDocuments.spec.ts`: picker and drop add PDF chips and send `documents[]`.
- docs/guards: the protocol gates; `tests/Config/ServerProtocolDocumentationDriftTest.php`; `docs/SERVER.md`; `candy-code-web/README.md` (soft).
- serialized: [protocol] [hot:candy-code-web/src-web/components/Composer.vue]
- deps: C-WEB-4.a
- phase: normal
- ruling: none
- risks: CTX-2-P1 (PDFs re-sent every later turn, no page check) is another lane's fix. Uploads widen its blast radius, so say so in SERVER.md.

### C-WEB-4.f — Branch badge (`SessionSummary.branch`)
- item: C-WEB-4 (sources: GAP-GIT-01)
- kind: gap · S
- approach:
  - **Gap.** The `sessions` row already stores `git_branch` (`candy-code/src/Session/SessionStore.php:271,311,776`), but `SessionSummary` (`src/Protocol/Schema/Definitions.php:44-60`) does not carry it.
  - **Server.** Add a nullable `branch`, filled by `ServerContext::summary()` from `$row['git_branch']`. That is the branch the session started on; there is no live `git` call on the serve loop.
  - **Client.** The status bar shows a branch chip, and the sidebar row meta shows `⎇ branch`.
- files.create: `candy-code/tests/Protocol/SessionSummaryBranchTest.php`, `candy-code-web/src-web/components/__tests__/branchBadge.spec.ts`
- files.modify: `candy-code/src/Protocol/Schema/Definitions.php` (SESSION_SUMMARY), `candy-code/src/Protocol/ServerContext.php` (`summary()`), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/components/SessionSidebar.vue`
- tests: create `candy-code/tests/Protocol/SessionSummaryBranchTest.php`: the summary carries the row's branch, and null when unset. Create `candy-code-web/src-web/components/__tests__/branchBadge.spec.ts`: the chip appears only when branch is non-null, and as plain text.
- docs/guards: the protocol gates; `candy-code-web/README.md` (soft).
- serialized: [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/components/SessionSidebar.vue]
- deps: none
- phase: normal
- ruling: none
- risks: The tooltip must say "branch at session start", or it reads as live state after an agent `git checkout`.

## C-WEB-5 — Remote serve: TLS & push (LATE)

No non-LATE sliver qualifies:
- C-WEB-5.g is S and client-only, but it flips a deliberate-dormant decision, which needs a ruling.
- C-WEB-5.f is M.
- Every other step is M and under R13.

### C-WEB-5.a — `serve --tls-cert/--tls-key` (built-in TLS)
- item: C-WEB-5 (sources: GAP-WEB-04, OC-new#12)
- kind: gap · M
- approach:
  - **Gap.** `--allow-remote` is cleartext; `docs/SERVER.md:53` says "there is no built-in TLS".
  - **Wrapping.** `Listener` already `implements ServerInterface` (`candy-code/src/Server/Listener.php:29`). `Server` (:249) wraps it in `React\Socket\SecureServer` when both flags are given, so FD registration and CLOEXEC are unchanged.
  - **Effects.** `url()` (:284) and the sign-in URLs (:311) become `https://`. The `Secure` cookie flag already follows `ClientAddress::isSecure()` (`ApiController.php:155`). The cleartext warning prints only without TLS.
  - **Validation.** `ServerConfig` checks that the cert and key are readable files, that the key is not group/world-readable, and that the pair loads (`openssl_x509_check_private_key`).
- files.create: `candy-code/tests/Server/TlsListenerTest.php`
- files.modify: `candy-code/src/Cli/Serve.php` (`ACTION_FLAGS['']`, validation, warning), `candy-code/src/Cli/ParsedArgs.php` (`SUBCOMMAND_FLAGS['serve']` value flags), `candy-code/src/Server/ServerConfig.php` (fields + validation), `candy-code/src/Server/Server.php` (bind/wrap, `url()`, sign-in URLs), `candy-code/src/Cli/Help.php` (serve help), `candy-code/lang/en.php` (`cli.serve.tls.*`), `candy-code/docs/SERVER.md` (flags table :51-53, Remote access), `candy-code/README.md` (serve synopsis ~:2114)
- tests: create `candy-code/tests/Server/TlsListenerTest.php`. It generates a self-signed pair in a temp dir via ext-openssl and pins:
  - an https handshake and `GET /` round trip;
  - a plain-http connect fails;
  - the login cookie carries `Secure`.

  Modify `candy-code/tests/Cli/ServeArgsTest.php`: the flags parse, a missing key or an unreadable or loose key refuses, and `Help::screen()` is pinned.
- docs/guards: `docs/SERVER.md`, `README.md`; `tests/Config/ReadmeRosterDriftTest.php` re-run; `tests/LangParityTest.php`; no env var, no child process.
- serialized: [headless] [docs:SERVER.md] [docs:README.md] [lang:cli.serve.tls.] [hot:candy-code/src/Cli/Serve.php]
- deps: none
- phase: LATE
- ruling: R13 — does the owner want built-in TLS on `serve` (and with it a supported remote/phone posture), or keep SSH-tunnel/proxy as the only remote answer?
- risks: Workspace-host gateway relays and the control socket stay plain unix/loopback. The test must prove that only the public listener is wrapped.

### C-WEB-5.b — `serve url --qr` pairing QR
- item: C-WEB-5 (sources: GAP-WEB-04 (pairing half), p2-miss: WEB-8 pairing/QR, OC-new#12)
- kind: gap · M
- approach:
  - **Flag.** `serve url` (`candy-code/src/Cli/Serve.php:864`, already a fresh LoginCodes one-time URL) gains `--qr`.
  - **Rendering.** The sign-in URL prints as a terminal QR: two rows per text line in half-blocks, with a quiet zone.
  - **Encoder.** A real encoder is needed: byte mode, ECC M, versions 1–10, mask scoring, format/version bits. The monorepo's `sugar-dashboard/src/Components/Media/QRCode.php` is a self-described non-scannable placeholder.
  - **Cleartext guard.** A non-loopback `http://` URL prints the cleartext warning above the code, because a phone pairing link over cleartext leaks a one-time code.
- files.create: `candy-code/src/Server/Pairing/QrEncoder.php`, `candy-code/src/Server/Pairing/QrTerminal.php`, `candy-code/tests/Server/Pairing/QrEncoderTest.php`
- files.modify: `candy-code/src/Cli/Serve.php` (`ACTION_FLAGS['url']`, `url` action), `candy-code/src/Cli/ParsedArgs.php` (`--qr` in `SUBCOMMAND_FLAGS['serve']`), `candy-code/src/Cli/Help.php`, `candy-code/lang/en.php` (`cli.serve.qr.*`), `candy-code/docs/SERVER.md` (`serve url` rows :134, :188)
- tests:
  - Create `candy-code/tests/Server/Pairing/QrEncoderTest.php`. It pins:
    - the version and format bits for known inputs;
    - a matrix for a fixed URL equals a reference matrix fixture;
    - over-length input refuses.
  - Modify `candy-code/tests/Cli/ServeArgsTest.php`: `url --qr` parses, and `--qr` on other actions is a usage error.
- docs/guards: `docs/SERVER.md`; `tests/LangParityTest.php`; one type per file (encoder and renderer are separate).
- serialized: [headless] [docs:SERVER.md] [lang:cli.serve.qr.] [hot:candy-code/src/Cli/Serve.php]
- deps: C-WEB-5.a
- phase: LATE
- ruling: R13 + QR source — write an in-tree encoder (default here), add a third-party QR dependency, or upgrade sugar-dashboard's placeholder `QRCode` into a real encoder and depend on it?
- risks: An in-tree encoder needs an independent reference fixture. Generate it once with a known tool and commit it, rather than testing the encoder against itself.

### C-WEB-5.c — Web Push core: VAPID keys, RFC 8291 cipher, allowlisted sender
- item: C-WEB-5 (sources: GAP-WEB-10, p2-miss: WEB-8 web push, OC-new#12)
- kind: gap · M
- approach: Pure server core, no wiring.
  - **VAPID keys.** `VapidKeys` holds a P-256 pair, made once with `openssl_pkey_new` and stored 0600 in StateDir. It provides the ES256 VAPID JWT (RFC 8292, DER→raw signature).
  - **Cipher.** `PushPayloadCipher` implements RFC 8291 aes128gcm: ECDH via `openssl_pkey_derive`, `hash_hkdf`, `openssl_encrypt aes-128-gcm`.
  - **Sender.** `WebPushSender` POSTs asynchronously through react/http `Browser`, with a connect timeout only and no redirects.
  - **Endpoint allowlist.** Endpoints must be https and on known push-service hosts (FCM, Mozilla autopush, WNS, Apple). An arbitrary endpoint a write-scope client registers would otherwise be an SSRF primitive.
  - **Subscription value.** `PushSubscription` holds `{endpoint, p256dh, auth}`.
- files.create: `candy-code/src/Server/Push/VapidKeys.php`, `candy-code/src/Server/Push/PushPayloadCipher.php`, `candy-code/src/Server/Push/WebPushSender.php`, `candy-code/src/Server/Push/PushSubscription.php`, `candy-code/tests/Server/Push/VapidKeysTest.php`, `candy-code/tests/Server/Push/PushPayloadCipherTest.php`, `candy-code/tests/Server/Push/WebPushSenderTest.php`
- files.modify: `candy-code/src/Server/StateDir.php` (VAPID key file constant)
- tests:
  - Create `candy-code/tests/Server/Push/PushPayloadCipherTest.php`: the RFC 8291 §5 test vector, byte-exact.
  - Create `candy-code/tests/Server/Push/VapidKeysTest.php`: the key is made once, 0600, and reloads; the JWT verifies with the public key.
  - Create `candy-code/tests/Server/Push/WebPushSenderTest.php` (fake Browser). It pins:
    - an off-allowlist or http endpoint refuses with no request;
    - 404/410 marks the subscription gone;
    - redirects are not followed.
- docs/guards: `tests/Support/ReadPathCensusTest.php` row for the key read (StateDir, OWNED_HOME); no proc_open, so no child-lifetime row.
- serialized: none
- deps: none
- phase: LATE
- ruling: R13 + crypto source — in-tree RFC 8291/8292 (default here; ext-openssl only) or a web-push library dependency?
- risks: Hand-rolled crypto. The RFC vector test is the gate, and a reviewer must check the DER→raw ES256 conversion.

### C-WEB-5.d — Web Push wiring: `push.*` RPCs, subscription store, triggers
- item: C-WEB-5 (sources: GAP-WEB-10, p2-miss: WEB-8 web push)
- kind: gap · M
- approach:
  - **New RPCs** (`PushMethods`):
    - `push.vapidKey` (read): the public key;
    - `push.subscribe {endpoint, keys}` (write);
    - `push.unsubscribe {endpoint}` (write).
  - **Store.** `PushSubscriptionStore` keeps rows 0600 in StateDir, keyed by sha256(endpoint) and owned by the token principal. Revoking a token drops its rows.
  - **Triggers.** `ServerContext::heard()` (`candy-code/src/Protocol/ServerContext.php:359-379`) sends on `permission.requested` ("A question is waiting") and on turn completion. Titles are redacted by default; a session name rides only when the subscription opted in.
  - **Scope.** Push sends fire only while no client follows that session, which avoids double notification.
- files.create: `candy-code/src/Protocol/Methods/PushMethods.php`, `candy-code/src/Server/Push/PushSubscriptionStore.php`, `candy-code/tests/Protocol/PushMethodsTest.php`, `candy-code/tests/Server/Push/PushSubscriptionStoreTest.php`
- files.modify: `candy-code/src/Protocol/Dispatcher.php` (register, ~:139-142), `candy-code/src/Protocol/Schema/MethodSchemas.php` (three rows), `candy-code/src/Protocol/ServerContext.php` (`heard()`), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen + a Push section), `candy-code-web/src-web/protocol/generated.ts` (regen)
- tests:
  - Create `candy-code/tests/Protocol/PushMethodsTest.php`. It pins:
    - subscribe needs write scope;
    - an off-allowlist endpoint is refused;
    - a question and a completion each send once;
    - a followed session sends nothing;
    - titles are redacted by default.
  - Create `candy-code/tests/Server/Push/PushSubscriptionStoreTest.php`: 0600, hash-keyed, token revoke prunes.
- docs/guards: the protocol gates; `tests/Config/ServerProtocolDocumentationDriftTest.php`; `docs/SERVER.md`; `dormantRoster.spec.ts` (C-WEB-3.g) must list `push.*` as dormant until C-WEB-5.e lands, or this lane adds the callers' stub.
- serialized: [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [docs:SERVER.md]
- deps: C-WEB-5.c
- phase: LATE
- ruling: R13 — default push payload (redacted generic text vs session titles) and whether serve may make outbound requests to third-party push services at all.
- risks: The send must never run synchronously on the serve loop. Assert in the test that `heard()` returns before the fake Browser resolves.

### C-WEB-5.e — Web Push client: service worker + consent toggle
- item: C-WEB-5 (sources: GAP-WEB-10, p2-miss: WEB-8 web push)
- kind: gap · M
- approach:
  - **Service worker.** `sw.ts` shows a notification on `push` and focuses or opens the session on click. It is emitted at the dist root by `vite.config.ts`.
  - **Consent.** A "Notify me on this device" toggle in SettingsView's client section, with a redaction note, drives `stores/push.ts`: permission prompt → `pushManager.subscribe(vapidKey)` → `push.subscribe`. Turning it off unsubscribes both sides.
  - **Static files.** `StaticFiles.php` serves `/sw.js` with `Cache-Control: no-cache`, not the immutable asset cache. The CSP's `default-src 'self'` already covers `worker-src`, so `Responses.php` needs no change.
  - **Secure context.** Hidden outside a secure context (localhost or C-WEB-5.a TLS), mirroring the existing notification guard.
- files.create: `candy-code-web/src-web/sw.ts`, `candy-code-web/src-web/stores/push.ts`, `candy-code-web/src-web/stores/__tests__/push.spec.ts`
- files.modify: `candy-code-web/vite.config.ts` (sw entry), `candy-code-web/src-web/components/settings/SettingsView.vue` (toggle), `candy-code-web/public/manifest.webmanifest` (if `gcm_sender_id`/scope is needed), `candy-code/src/Server/Http/StaticFiles.php` (`/sw.js` headers)
- tests:
  - Create `candy-code-web/src-web/stores/__tests__/push.spec.ts`. It pins:
    - no subscribe without consent;
    - the subscribe → RPC order;
    - off unsubscribes;
    - hidden in an insecure context.
  - Modify `candy-code-web/src-web/__tests__/pwa.spec.ts` (sw registered only after consent).
  - Add a PHP StaticFiles header pin to the existing static-files test (or create `candy-code/tests/Server/StaticFilesServiceWorkerTest.php`).
- docs/guards: `candy-code-web/README.md` (soft); vitest; dist rebuild; `dormantRoster.spec.ts` drops `push.*`.
- serialized: [hot:candy-code-web/src-web/components/settings/SettingsView.vue]
- deps: C-WEB-5.d
- phase: LATE
- ruling: R13 (as C-WEB-5.d).
- risks: The dist rebuild emits a new root file (`sw.js`), which changes the dist-parity check's file list.

### C-WEB-5.f — `server.logs` tail RPC + About-modal Logs tab
- item: C-WEB-5 (sources: GAP-WEB-N4 (logs), GAP-OBS-01)
- kind: gap · M
- approach:
  - **Lift.** Move `Serve::tail()` (`candy-code/src/Cli/Serve.php:1134`) into `Server\LogTail`. `serve logs` (:794-830) and a new `server.logs {lines?}` (Scope::Admin) share it.
  - **Output.** The RPC returns flattened lines (`ErrorLog::flatten`) capped by count and bytes. A foreground server with no `server.log` answers an empty list with a reason.
  - **Client.** `AboutModal.vue` shows a Logs tab only when `connection.scopes` includes `admin`. It renders a plain `<pre>` with refresh.
  - **Dependency.** Depends on C-SES-3, which stops share tokens landing in the request log (SES-3-P2).
- files.create: `candy-code/src/Server/LogTail.php`, `candy-code/tests/Server/LogTailTest.php`, `candy-code/tests/Protocol/ServerLogsTest.php`
- files.modify: `candy-code/src/Cli/Serve.php` (`logs`/`tail` → LogTail), `candy-code/src/Protocol/Methods/ServerMethods.php` (`server.logs`), `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/components/AboutModal.vue` (Logs tab)
- tests:
  - Create `candy-code/tests/Server/LogTailTest.php`: the last N lines across a rotation boundary, and a non-regular file is refused.
  - Create `candy-code/tests/Protocol/ServerLogsTest.php`: admin only (write scope gets -32003), and byte cap.
  - Modify `candy-code-web/src-web/components/__tests__/aboutModal.spec.ts`: the tab is hidden without admin.
- docs/guards: the protocol gates; `tests/Support/ReadPathCensusTest.php` (the tail read sink moves from Serve.php to LogTail.php in-step); `tests/Cli/StderrEmitterCensusTest.php` if `serve logs` output paths move.
- serialized: [protocol] [hot:candy-code/src/Cli/Serve.php] [hot:candy-code-web/src-web/components/AboutModal.vue]
- deps: C-SES-3
- phase: LATE
- ruling: R13 — `ErrorLog`'s docblock says log lines "go to the 0600 server.log and never onto the wire". Does the owner accept Admin-scope log lines on the wire?
- risks: Log lines can quote prompts or paths, so Admin scope is the only gate. Keep the byte cap tight.

### C-WEB-5.g — Confirmed "Stop server" for Admin tokens
- item: C-WEB-5 (sources: GAP-WEB-N4 (stop), k3-dorm#3 (`server.shutdown`))
- kind: gap · S
- approach:
  - **Gap.** `server.shutdown` (Scope::Admin, `candy-code/src/Protocol/Methods/ServerMethods.php:46`) is deliberately dormant behind `candy-code-web/src-web/__tests__/shutdownDormant.spec.ts`.
  - **Button.** `AboutModal.vue` shows a "Stop server" button only with admin scope. It sits behind a confirm naming the running-turn count (`server.health`), and the existing draining banner (`connection.shutdown`) takes over after the call.
  - **Flip.** The negative control becomes a positive pin: AboutModal is the only caller, admin plus confirm only. `server.shutdown` leaves the `dormantRoster.spec.ts` set.
- files.create: none
- files.modify: `candy-code-web/src-web/components/AboutModal.vue`, `candy-code-web/src-web/__tests__/shutdownDormant.spec.ts` (flip), `candy-code-web/src-web/__tests__/dormantRoster.spec.ts` (drop `server.shutdown`), `candy-code-web/src-web/components/__tests__/aboutModal.spec.ts`
- tests: modify the three specs above. They pin:
  - no button without admin;
  - cancel sends nothing;
  - confirm sends `server.shutdown` once;
  - no other module calls it.
- docs/guards: `candy-code-web/README.md` (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/AboutModal.vue] [hot:candy-code-web/src-web/__tests__/dormantRoster.spec.ts]
- deps: C-WEB-3.g
- phase: LATE
- ruling: R13 — flip `server.shutdown` from deliberate-dormant to an Admin-only, confirmed browser action?
- risks: A remote Admin token can now stop the server from a phone. That is exactly the R13 question.

---

## Notes for the orchestrator

1. **Protocol lanes from this area.**
   - Normal: C-WEB-1.a, C-WEB-2.d, C-WEB-3.f, C-WEB-4.e, C-WEB-4.f. LATE: C-WEB-5.d, C-WEB-5.f.
   - C-WEB-1.a only changes an EventType description (no MethodSchemas row) but still moves the gen chain.
   - C-WEB-1.a and C-WEB-4.f both edit `ServerContext::summary()` and `StatusBar.vue`. One agent may run them as ONE protocol lane to save a protocol wave.
2. **`candy-code-web/dist/` is committed and CI-checked for parity** (`.github/workflows/web.yml:102`). Its bundles are content-hashed (`dist/assets/index-<hash>.js`), so any two web lanes in one wave will conflict on dist.
   - Recommendation: treat `candy-code-web/dist/**` as weld-only, like `suite-figure.json`. Lanes do not commit dist, and the weld rebuilds it once per wave (`npm ci && npm run build`).
   - Every web step here assumes that recommendation.
3. **`docs/SERVER.md` overlap.** Every protocol lane regenerates the marked method/event tables in `docs/SERVER.md`. Prose edits by C-WEB-2.a (normal), and by C-WEB-5.a and C-WEB-5.b (LATE), fall outside those tables. Either allow the marked-table regen to share a wave with a prose owner, or have non-protocol steps REPORT their SERVER.md lines to the weld.
4. **Hot files.**
   - Server side:

     | File | My steps | Other areas |
     |---|---|---|
     | `candy-code/src/Host/SessionHost.php` | 1.a, 2.a, 2.b, 2.e | C-CTX-2 notice drain, GAP-MEM-02w, C-AGT-1 schedules pump, P-SESSION-TABS |
     | `candy-code/src/Protocol/ServerContext.php` | 1.a, 4.f, 5.d | — |
     | `candy-code/src/Host/TurnRunner.php` | 1.c | C-SES-4 OTel, P-SESSION-TABS |
     | `candy-code/src/Host/TranscriptProjector.php` | 1.d | C-CTX-2 |
     | `candy-code/src/Cli/Serve.php` | 5.a, 5.b, 5.f | — |

   - Web side:

     | File | My steps | Other areas |
     |---|---|---|
     | `components/StatusBar.vue` | 1.b, 4.d, 4.f | — |
     | `components/Composer.vue` | 4.a, 4.e | C-WEB-7 |
     | `components/SessionSidebar.vue` + `stores/sessions.ts` | 3.d, 3.e, 4.f | C-WEB-7 export |
     | `stores/promptHistory.ts` | 4.a | C-WEB-7 |
     | `App.vue` | 4.b | possibly the pre-picks |
     | `components/settings/SettingsView.vue` | 4.b, 5.e | — |
     | `components/panels/SidePanels.vue` + `stores/agents/panels.ts` | 3.b | P-CTX-BREAKDOWN, P-AGENT-ATTACH |
     | `components/panels/GeneratePanel.vue` | 3.f | P-IMAGE-MENU |
     | `stores/reducer.ts` | 4.d | P-AGENT-ATTACH, C-WEB-7 |
     | `components/AboutModal.vue` | 5.f, 5.g | — |
     | `components/ToolCard.vue` | 4.c | P-AGENT-ATTACH |

   - `candy-code-web/README.md` feature bullets are edited by most web steps. No test pins that README, so treat it as soft-serial: disjoint bullets, or REPORT to the weld.
5. **`dormantRoster.spec.ts` (C-WEB-3.g) becomes a standing gate.** Any later protocol lane, from any area, that adds a method without a browser caller must add it to the pinned dormant set in the same commit. Fold that into the [protocol] lane's in-step guard law. C-WEB-5.d/5.e and 5.g edit the set themselves.
6. **Rulings.**
   - All of C-WEB-5 waits on R13. It has sub-questions: the QR encoder source (5.b), the push crypto source and payload redaction default (5.c/5.d), `server.log` lines on the wire against ErrorLog's doctrine (5.f), and flipping `server.shutdown` dormancy (5.g).
   - No normal step needs a ruling.
   - Design choice to surface (not blocking): served `/budget <n>` refuses out loud, because the serve cap is the launch's `--max-cost`. A per-session tighten-only override would be a later item.
7. **Deliberately not covered here.**
   - `/review` in `-p`: NonInteractive has no slash dispatch; only the skill rewrite reaches `-p` (C-WEB-2.c).
   - TUI-1-P1's second half (an async cold `git ls-files` off the keystroke path).
   - GAP-TL-19's wire half (C-TOOL-2).
   - The cache-hit-% readout (folded into P-CTX-BREAKDOWN).
   - C-WEB-7 (not assigned to this lane).
8. **Could not verify.**
   - The designs of C-PRV-1, C-AGT-2 and C-SES-3.
   - C-WEB-2.f assumes the effort decision still lives in `Chat::handleEffortCommand`.
   - C-WEB-3.e assumes C-SES-3 ships a cross-session share listing; re-scope if it does not.
   - The exact `GenerateVideo` field names for the `generate.submit` video schema; read them from `GenerateVideo.php:217-250` at implementation time.
   - No live serve/web run was made (read-only brief).
9. **COMPARISON.md.** These steps flip several candy-code-web cells: MOD-11/MOD-15/MOD-22, SKL-06, TL-19, CMD rows, WEB/UI rows, GIT-01. This lane does not edit COMPARISON.md; update it at closeout.
