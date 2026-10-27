# design-agt — Agents, git & automation (C-AGT-1 … C-AGT-7)

Designed 2026-10-26 against master `5e9195687` (live tree, read-only). Every MODIFY path below was checked
to exist and every CREATE path to be absent. None of these items is on the owner's LATE list, so every step
is `phase: normal`.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-AGT-1.a | Bind persisted schedules to a project root | M | [bootstrap:workspace] [lang] [docs:SCHEDULES.md] [docs:README.md(docs index)] | none |
| C-AGT-1.b | Fire `/loop` on served sessions | M | [hot:SessionHost.php] [hot:ServerContext.php] [lang] [docs:SCHEDULES.md] | C-AGT-1.a |
| C-AGT-1.c | Webhooks refuse over-size bodies with 413 | S | [hot:HookWebhooks.php] [docs:SERVER.md] | none |
| C-AGT-2.a | `/review` refuses refs the scanner cannot attach | M | [chat:reviewTurn] [hot:ContextMentions.php] [lang] | none |
| C-AGT-2.b | Review turns run read-only (plan clamp) | M | [chat:reviewTurn, togglePermissionMode, applyCommandResult] [hot:HandlesTurnCompletion.php] [hot:SessionHost.php] [lang] | C-AGT-2.a |
| C-AGT-2.c | Composite action installs with root semantics + self-test | M | [workflows] [docs:CI.md] [docs:README.md] | none |
| C-AGT-3.a | Preset `mcpServers` scopes `mcp_call`/`mcp_list` | S | [hot:AgentManager.php] [docs:AGENTS.md] | none |
| C-AGT-3.b | Per-agent memory from the preset `memory:` key | M | [hot:AgentManager.php] [hot:TaskTool.php] [docs:AGENTS_AUTHORING.md] [docs:MEMORY.md] | C-AGT-3.a |
| C-AGT-4.a | Primary-agent core (preset → main backend) | M | [runtime:systemPromptSections] [hot:EngineBackend.php] [hot:AgentManager.php] [docs:AGENTS_AUTHORING.md] | C-AGT-3.a, C-AGT-3.b |
| C-AGT-4.b | `/agent use`, Alt+A cycle, TUI badge, serve effect | M | [chat:handleAgentsCommand, applyCommandResult, key dispatch] [renderer:modeBadge] [commands:0800-agents] [hot:SessionHost.php] [lang] [docs:README.md] [docs:AGENTS.md] [docs:COMMANDS.md] | C-AGT-4.a |
| C-AGT-4.c | Web agent select + `SessionSummary.agent` | M | [protocol] [hot:ServerContext.php] [hot:StatusBar.vue] [docs:SERVER.md] | C-AGT-4.b |
| C-AGT-4.d | `@agent:<name>` mentions (TUI, serve, `-p`, web) | M | [chat:userTurnMessage, mentionPopupRows] [headless] [hot:ContextMentions.php] [hot:TurnController.php] [hot:SessionHost.php] [hot:Composer.vue] [docs:README.md] [docs:AGENTS.md] | C-AGT-3.a, C-AGT-3.b |
| C-AGT-4.e | SendMessage reaches other sessions (`session:<id>`) | M | [chat:pumpBackgroundSessions] [hot:SessionHost.php] [hot:PermissionGate.php] [docs:AGENTS.md] [docs:README.md(tools block)] | C-AGT-1.b, C-AGT-7.a, C-AGT-3.b |
| C-AGT-5.a | Model-callable `Schedule` tool | M | [catalog:position 41] [runtime:WRITE_CAPABLE_TOOL_NAMES] [docs:README.md, ARCHITECTURE.md, PERMISSIONS.md (gen-tool-docs)] [docs:SCHEDULES.md] [lang] | C-AGT-1.a, C-AGT-1.b, C-AGT-1.c, C-AGT-7.a |
| C-AGT-5.b | `/schedules add`, fire outcomes, web schedules table | M | [commands:2910-schedules] [hot:BackgroundEvents.php] [hot:BackgroundPanel.vue] [lang] [docs:COMMANDS.md] [docs:README.md] [docs:SCHEDULES.md] | C-AGT-5.a |
| C-AGT-5.c | Heartbeat schedule kind | M | [chat:pumpBackgroundSessions] [lang] [docs:SCHEDULES.md] | C-AGT-5.b |
| C-AGT-5.d | Signed inbound webhooks + delivery dedupe | M | [hot:HookWebhooks.php] [hot:ServerConfig.php] [lang] [docs:SERVER.md] | C-AGT-1.c, C-AGT-7.c |
| C-AGT-5.e | Outbound webhooks core (sign, async send, retry) | M | none | C-AGT-1.a, C-AGT-1.b, C-AGT-1.c |
| C-AGT-5.f | Outbound webhooks wiring + `webhooksOut` setting | M | [bootstrap:workspace] [chat:handleTurnCompletionMessages, requestPermission, pumpSchedules] [hot:ServerContext.php] [hot:HandlesTurnCompletion.php] [lang] [docs:SETTINGS.md] [docs:SERVER.md] | C-AGT-5.e, C-AGT-5.c |
| C-AGT-5.g | GitLab CI recipe around the signed phar | S | [docs:CI.md] | C-AGT-2.c, C-AGT-1.c |
| C-AGT-5.h | PR-comment bot example workflow | S | [docs:CI.md] | C-AGT-5.g |
| C-AGT-6.a | `/commit` with a drafted message | M | [chat:new handleCommitCommand] [lang] [docs:COMMANDS.md] [docs:README.md] | C-AGT-2.a, C-AGT-2.b, C-AGT-2.c |
| C-AGT-6.b | `/review` ranges (`A..B`, `A...B`, `@{u}`) | M | [hot:ContextMentions.php] [lang] [docs:COMMANDS.md] | C-AGT-2.a, C-AGT-2.b |
| C-AGT-6.c | `--worktree [name]` main sessions | M | [headless] [docs:README.md] | C-AGT-2.a, C-AGT-2.b, C-AGT-2.c |
| C-AGT-6.d | `/pr` canned turn + `/review --pr <n>` | M | [chat:new handlePrCommand, reviewTurn] [hot:ContextMentions.php] [lang] [docs:COMMANDS.md] [docs:README.md] | C-AGT-6.b |
| C-AGT-6.e | PR status chips in the web | M | [protocol] [hot:ServerContext.php] [hot:StatusBar.vue] [hot:SessionSidebar.vue] [docs:SERVER.md] | C-AGT-6.d, C-WEB-4 |
| C-AGT-7.a | Schedule hygiene: watch tick, bypass ceiling, run limit | M | [chat:subscriptions] [lang] [docs:SCHEDULES.md] | C-AGT-1.a, C-AGT-1.b |
| C-AGT-7.b | Headless honesty: `session_id`, `--json-schema` | M | [headless] [lang] [docs:README.md] | none |
| C-AGT-7.c | Webhook mode clamp + hygiene | S | [hot:HookWebhooks.php] [hot:ServerConfig.php] [lang] [docs:SERVER.md] | C-AGT-1.c |

29 steps: 5 S, 24 M, 0 L.

---

## C-AGT-1 — Automation trigger fixes

### C-AGT-1.a — Bind persisted schedules to a project root
- item: C-AGT-1 (sources: AUT-1-P1, p1-miss: schedules UX)
- kind: fix · MAJOR · effort M
- approach: Root cause: a row is `name/every/prompt/provider/permissionMode/nextDue/enabled` (`src/Background/Schedule.php:136-195`), and `ScheduleSpawner::closure` spawns at the HOST's root (`src/Background/ScheduleSpawner.php:58`). One home-global file is evaluated by every TUI and serve, and the first lock wins (`ScheduleRegistry::fireDue` :391ff), so a row runs in whichever repo's host claims it. Fix:
  - add a realpath'd `root` field to `Schedule` (`fromArray`/`toArray`; required for persisted rows, ignored for `/loop`);
  - give the registry its host root: `ScheduleRegistry::loadDefault(?string $hostRoot)` / `atPath($path, $hostRoot)`. Bootstrap's single construction site passes `$root`, so Chat and BackgroundEvents need no edit;
  - `fireDue()` skips a row whose root ≠ host root BEFORE claiming its lock, without advancing it (new result action `skipped-foreign`). A registry with no host root fires no persisted row;
  - a rootless row loads as a `loadErrors()` note ("not bound to a project; add `\"root\"` — will not fire").
  - `/schedules` shows each row's root (or "unbound").
  - Write `docs/SCHEDULES.md`, the first page that documents the `schedules.json` format, root binding and the permission floor.
- files.create: `candy-code/docs/SCHEDULES.md`
- files.modify: `candy-code/src/Background/Schedule.php` (ctor, `fromArray`, `toArray`), `candy-code/src/Background/ScheduleRegistry.php` (ctor, `loadDefault`, `atPath`, `fireDue`, `load`), `candy-code/src/Background/ScheduleSpawner.php` (spawn at `$row->root` when set; docblock), `candy-code/src/Host/Commands/SchedulesCommand.php` (`listing`), `candy-code/src/Cli/Bootstrap.php` (`workspace()`, the `ScheduleRegistry::loadDefault()` service line ~1910), `candy-code/lang/en.php` (`host.schedules.none`, `host.schedules.row`, new `host.schedules.unbound*`), `candy-code/docs/README.md` (index row for SCHEDULES.md)
- tests: modify `candy-code/tests/Sessions/ScheduleTest.php` (root parse/realpath/round-trip), `candy-code/tests/Sessions/ScheduleRegistryTest.php` (a rootless row is a load note and never fires; a foreign-root row is skipped and not advanced), `candy-code/tests/Sessions/ScheduleCrossHostFireTest.php` (host /a fires a /a row, host /b never does), `candy-code/tests/Host/BackgroundEventsSchedulesTest.php` and `candy-code/tests/Chat/ScheduleLoopFireTest.php` (fixtures gain `root`), `candy-code/tests/Host/Commands/LoopSchedulesCommandTest.php` (listing shows root/unbound)
- docs/guards: new `docs/SCHEDULES.md` plus its `docs/README.md` index row. `ReadPathCensusTest` / `ProjectTierRefusalInventoryTest` rows for `ScheduleRegistry` are unchanged (same file, same read). The `ForeignAgentPresetWiringTest` permissionMode census is unchanged (no new reader).
- serialized: [bootstrap:workspace] [lang: `host.schedules.*`] [docs:SCHEDULES.md] [docs:README.md (docs/ index)]
- deps: none
- phase: normal
- ruling: none
- risks: existing hand-written rows stop firing until they gain a `root`. The load note and `/schedules` say so loudly; this is the prescribed fix.

### C-AGT-1.b — Fire `/loop` on served sessions
- item: C-AGT-1 (sources: GAP-AUT-08, k3-sum (live defect), k3-dorm#1, p1-miss: schedules UX (`/loop` for served sessions))
- kind: dormant-wire · MAJOR · effort M
- approach: Root cause: `LoopCommand` registers an ephemeral row (`src/Host/Commands/LoopCommand.php:65-72`), but `fireDue()` skips non-persisted rows (`ScheduleRegistry.php:395`), and the only ephemeral pump is `Chat::pumpSchedules()` (`src/Chat.php:18518`), so a browser `/loop` answers "registered" and never fires. Fix:
  - stamp each ephemeral `Schedule` with its owning `sessionId` (LoopCommand reads `CommandContext::$sessionId`);
  - add `ScheduleRegistry::dueEphemeralFor(string $sessionId, int $now)` and `removeEphemeralFor(string $sessionId)`;
  - add a public `SessionHost::tick(int $now): int` that advances each due loop row in memory, appends a notice row, and `submit()`s the prompt with `QueueMode::Followup`, so it waits behind a running turn. `release()` drops the session's rows, so loops die with their session or host;
  - in `ServerContext::pump()` (`src/Protocol/ServerContext.php:438`), call `tick()` for every open host, not only busy ones, at most once per second.
  - The TUI pump is unchanged.
  - `SessionHost::tick()` is the generic per-session clock that C-AGT-4.e reuses.
- files.create: none
- files.modify: `candy-code/src/Background/Schedule.php` (ephemeral `sessionId`, never serialized), `candy-code/src/Background/ScheduleRegistry.php` (`dueEphemeralFor`, `removeEphemeralFor`), `candy-code/src/Host/Commands/LoopCommand.php` (`run`), `candy-code/src/Host/SessionHost.php` (new `tick()`, `release()`), `candy-code/src/Protocol/ServerContext.php` (`pump()`), `candy-code/lang/en.php` (`host.loop.queued`), `candy-code/docs/SCHEDULES.md` (`/loop` on serve)
- tests: create `candy-code/tests/Host/SessionHostLoopFireTest.php` (a served `/loop` fires within one tick past due; a busy session queues it; `release()` removes the row; another session's row never fires here); modify `candy-code/tests/Sessions/ScheduleRegistryTest.php` (keyed due/remove)
- docs/guards: `docs/SCHEDULES.md` section. No new fork or child.
- serialized: [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php] [lang: `host.loop.*`] [docs:SCHEDULES.md]
- deps: C-AGT-1.a
- phase: normal
- ruling: none
- risks: P-SESSION-TABS will need the same per-session keying in the TUI pump; today's Chat pump fires every in-process loop row.

### C-AGT-1.c — Webhooks refuse over-size bodies with 413
- item: C-AGT-1 (sources: AUT-6-P1)
- kind: fix · MAJOR · effort S
- approach: Root cause: `RequestBodyBufferMiddleware(MAX_API_BODY_BYTES)` (`src/Server/Server.php:241`) does not refuse an over-limit body; it swaps in an empty body and calls `$next`. `HookWebhooks::handle()` then splices `PromptFence::escape('')` (`src/Server/Http/HookWebhooks.php:130-133`) and spawns a billed turn (:136). Fix: before the splice, answer `413 payload_too_large` when `Content-Length` > `ServerConfig::MAX_API_BODY_BYTES`, or when a body was declared (`Content-Length` > 0 or `Transfer-Encoding: chunked`) and the buffered body is `''`. `$run` is never invoked on that path. Correct the :127-129 docblock and document the 413 in `docs/SERVER.md`.
- files.create: none
- files.modify: `candy-code/src/Server/Http/HookWebhooks.php` (`handle`, docblock), `candy-code/docs/SERVER.md` (the `POST /api/hooks/{name}` row, ~:299)
- tests: modify `candy-code/tests/Server/HookWebhooksTest.php` (`Content-Length: 70000` with an empty body → 413, `$run` never called); create `candy-code/tests/Server/HookWebhooksBodyCapStackTest.php` (posts 70 KiB through the real middleware stack → 413)
- docs/guards: `docs/SERVER.md` row; `ServerProtocolDocumentationDriftTest` stays green (the endpoint table wording only)
- serialized: [hot:candy-code/src/Server/Http/HookWebhooks.php] [docs:SERVER.md]
- deps: none
- phase: normal
- ruling: none
- risks: real GitHub push payloads over 64 KiB now get a loud 413 instead of a silent empty run. A per-hook body cap is out of scope (noted for C-AGT-5.d).

---

## C-AGT-2 — /review & CI action fixes

### C-AGT-2.a — `/review` refuses refs the scanner cannot attach
- item: C-AGT-2 (sources: GIT-1-P1)
- kind: fix · MAJOR · effort M
- approach: Root cause: `ContextMentions::isDiffRef()` checks only `REF_PATTERN` (`src/Attachments/ContextMentions.php:93-96`). The scanner then strips trailing `}` (`trimmed()` :522-527, `TRAILING_PUNCTUATION` :79), and `diff()` runs `diff-index <ref>`, which takes ONE tree-ish (:234-245). `Chat::reviewTurn()` (`src/Chat.php:11784-11800`) submits on `isDiffRef` alone, so `base..HEAD`, `HEAD@{1}` and `@{u}` bill a review turn with no diff. Fix:
  - `trimmed()` keeps a trailing `}` while the token holds an unmatched `@{`, so `HEAD@{1}` and `@{u}` attach;
  - `isDiffRef()` becomes the scanner's full round trip (`trimmed('diff:'.$ref) === 'diff:'.$ref`) and refuses `..` (C-AGT-6.b re-admits ranges properly);
  - add a pure `ReviewCommand::refError(string $ref, string $root): ?string` that also pre-resolves with a bounded `git rev-parse --verify <ref>^{tree}` through `GitRunner`. `reviewTurn()` calls it and refuses loudly before submitting.
  - C-WEB-2's future `ReviewHostCommand` reuses this helper.
- files.create: none
- files.modify: `candy-code/src/Attachments/ContextMentions.php` (`isDiffRef`, `trimmed`), `candy-code/src/Commands/ReviewCommand.php` (`refError`, docblock :18-20), `candy-code/src/Chat.php` (`reviewTurn`), `candy-code/lang/en.php` (`cmd.review.bad-ref` drops the false `main..HEAD` example; `cmd.review.hint`)
- tests: modify `candy-code/tests/Chat/ReviewCommandTest.php` (flip :138 and the :98-102 `main..HEAD` submit arm to "no turn + bad-ref row"; new arms: `/review HEAD@{1}` attaches, `/review @{u}` in a temp repo with an upstream attaches, `/review base..HEAD` starts no turn), `candy-code/tests/Attachments/ContextMentionsTest.php` (`@diff:HEAD@{1}` attaches)
- docs/guards: none (the `/review` hint has no argument roster in the docs beyond `[<ref>]`)
- serialized: [chat:reviewTurn] [hot:candy-code/src/Attachments/ContextMentions.php] [lang: `cmd.review.*`]
- deps: none
- phase: normal
- ruling: none
- risks: until C-AGT-6.b lands, range forms are refused, never silently empty. The docs must not advertise ranges in between.

### C-AGT-2.b — Review turns run read-only (plan clamp)
- item: C-AGT-2 (sources: GIT-1-P2)
- kind: fix · MINOR · effort M
- approach: Root cause: the "report, do not edit" mandate is prompt prose. The review turn runs under whatever mode the session holds (`src/Chat.php:11798` plain `submit()`), and the only test pins docblock text (`tests/Chat/ReviewCommandTest.php:130-131`).
  - TUI: `reviewTurn()` swaps the gate to `PermissionMode::Plan` for that one turn. Reuse the gate-swap body of `togglePermissionMode()` (:17390-17428), extracted into a private helper. A `reviewModeRestore` field puts the prior mode back on every settle path (`HandlesTurnCompletion::handleTurnCompletionMessages`), including cancel and error. Announce the clamp in a notice row. A session already in plan or dont-ask is left as it is.
  - Serve: add a turn-scoped `SubmitOptions::withModeCeiling(PermissionMode)` that `SessionHost` honours for that turn's gate (`sessionGate()`/`dispatch`). C-WEB-2's `ReviewHostCommand` then has a door to use.
- files.create: none
- files.modify: `candy-code/src/Chat.php` (`reviewTurn`, `togglePermissionMode` extraction, state field), `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php` (`handleTurnCompletionMessages`: restore), `candy-code/src/Host/SubmitOptions.php` (`withModeCeiling`), `candy-code/src/Host/SessionHost.php` (`dispatch`/`sessionGate` per-turn ceiling), `candy-code/src/Commands/ReviewCommand.php` (docblock: the clamp is now the action-side answer), `candy-code/lang/en.php` (`cmd.review.clamped`)
- tests: modify `candy-code/tests/Chat/ReviewCommandTest.php` (replace the :130-131 prose pin: under a bypass-mode Chat, a scripted Write/Bash call during `/review` is refused; the next ordinary turn runs in bypass again; a cancelled review restores the mode); create `candy-code/tests/Host/SessionHostModeCeilingTest.php` (a ceiling'd submit refuses a write; the next submit does not)
- docs/guards: none (no key, setting or command roster moves)
- serialized: [chat:reviewTurn, togglePermissionMode, applyCommandResult (none — the restore lives in the settle trait),ctor] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [hot:candy-code/src/Host/SessionHost.php] [lang: `cmd.review.*`]
- deps: C-AGT-2.a
- phase: normal
- ruling: none. It enforces the shipped "Report, do not edit" law. The owner may veto if `/review` should keep write power.
- risks: the status-bar mode reads "plan" during a review turn. That is intended and visible, but the restore must cover every settle path, or the session sticks in plan.

### C-AGT-2.c — Composite action installs with root semantics + self-test
- item: C-AGT-2 (sources: GIT-2-P1, GIT-2-P2)
- kind: fix · MAJOR · effort M
- approach: Root cause: `action.yml:103` runs `composer global require "sugarcraft/candy-code:…"`. Stability flags are root-only, the global root is `stable`, and Packagist carries no stable sibling, so the install cannot resolve; no workflow has ever run it. The same line is the README's user install (`README.md:149`) and `docs/CI.md:164`. Fix:
  - install with root semantics: `composer create-project --no-dev --prefer-dist "sugarcraft/candy-code:${CANDY_CODE_CONSTRAINT}" "$RUNNER_TEMP/candy-code"`, and add its `bin/` to `$GITHUB_PATH`;
  - fix the README and CI.md global-install lines the same way, or with `composer global config minimum-stability dev && … prefer-stable true` first;
  - document the only real pin, `dev-master#<split-sha>`, and that it pins candy-code only, not its siblings;
  - add a self-test workflow (`uses: ./candy-code`, echo provider, `permission-mode: plan`, path-filtered on `candy-code/action.yml` and `candy-code/docs/CI.md`) asserting `exit-code == 0` and a non-empty `result`;
  - add a `tools/tests` guard that `action.yml` never runs `composer global require` and never interpolates `${{ inputs.* }}` inside a `run:` body.
  - Update CI.md's "no repo test surface by design" paragraph.
- files.create: `.github/workflows/candy-code-action-selftest.yml`, `tools/tests/ActionYmlInstallTest.php`
- files.modify: `candy-code/action.yml` (install step, `candy-code-version` description), `candy-code/docs/CI.md` (install description :26, input table :40, "without the action" :164, "Linting this action" :173-178), `candy-code/README.md` ("Install as a user" :146-152)
- tests: create `tools/tests/ActionYmlInstallTest.php` (runs in the `tools-guards` job, not the candy-code suite, so no durations row); the self-test workflow is the behavioural test
- docs/guards: `docs/CI.md`, `README.md` install block. `ReadmeRosterDriftTest` is unaffected (not a roster).
- serialized: [workflows] [docs:CI.md] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: the self-test installs from Packagist dev-master (the split repo), not the PR's tree, so it proves the install leg and the envelope, not unmerged PHP.

---

## C-AGT-3 — Preset memory & MCP scope

### C-AGT-3.a — Preset `mcpServers` scopes `mcp_call`/`mcp_list`
- item: C-AGT-3 (sources: GAP-MCP-11, k2-dorm (`setAgentPreset`), k2-sum (MCP-11 ✅→🟡))
- kind: dormant-wire · effort S
- approach: Today `AgentManager::resolveGrantedTools()` (`src/Agents/AgentManager.php:1496-1580`) filters `McpToolBridge`s by `mcpServers`, but the deferred dispatchers `McpCallTool`/`McpListTool` keep the launch `McpClient`. That client is built unrestricted (`Bootstrap.php:8015`), and `McpClient::setAgentPreset()` (`src/MCP/McpClient.php:178`) has no caller, so a scoped sub-agent can `mcp_call` any server. Wire the dormant setter without mutating the shared client:
  - `McpClient::forAgentPreset(AgentPreset): self` clones and calls `setAgentPreset()`; servers stay shared by handle;
  - `McpToolShape::scopedTo(array $mcpServers): self` uses that client and filters `dispatchedDescriptors` with `McpRouter::serverAllowed()`;
  - `McpCallTool::scopedTo()` and `McpListTool::scopedTo()` rebuild from the scoped shape.
  - In `resolveGrantedTools()`, when `$agent->mcpServers !== []`, swap each dispatcher for its scoped copy on both the declared and the silent-tools path.
  - Lane must verify the parent-side executor for fork children dispatches through the granted instances (`refuseCallOutsideGrant` region). If it looks tools up by name, apply the scope there too.
- files.create: none
- files.modify: `candy-code/src/MCP/McpClient.php` (`forAgentPreset`), `candy-code/src/MCP/McpToolShape.php` (`scopedTo`), `candy-code/src/Tools/BuiltIn/McpCallTool.php` (`scopedTo`), `candy-code/src/Tools/BuiltIn/McpListTool.php` (`scopedTo`), `candy-code/src/Agents/AgentManager.php` (`resolveGrantedTools`), `candy-code/docs/AGENTS.md` (mcpServers now scopes the dispatchers)
- tests: create `candy-code/tests/Agents/McpDispatcherPresetScopeTest.php` (a preset with `mcpServers: [docs]` cannot `mcp_call` server `git`; `mcp_list` lists only `docs`; a preset with no `mcpServers` is unchanged; the parent's client is unscoped after the child runs); modify `candy-code/tests/MCP/McpToolShapeTest.php` (`scopedTo` filter)
- docs/guards: `docs/AGENTS.md`. No roster guard moves.
- serialized: [hot:candy-code/src/Agents/AgentManager.php] [docs:AGENTS.md]
- deps: none
- phase: normal
- ruling: none
- risks: `clone` of `McpClient` must not duplicate stdio children or the lazy router state. The lane checks for `__clone` needs, since the router caches the server map.

### C-AGT-3.b — Per-agent memory from the preset `memory:` key
- item: C-AGT-3 (sources: GAP-MEM-13, k1-dorm#2)
- kind: dormant-wire · effort M
- approach: Today `memory:` is parsed onto `AgentPreset`/`Agent` and read by nothing (`src/Support/FrontmatterKeyAudit.php:122`, `INERT[AGENT]`). It also defaults to `User` when absent (`AgentPresetRegistry.php:608`, `Agent.php:104,176`), so "declared" is indistinguishable from "absent". Fix:
  - make the field nullable: absent = no agent memory, with a `memorySet` sentinel if a wither needs it;
  - add `Memory\AgentMemory`, which resolves a per-agent `MemoryStore`: `user` → `~/.candy-code/agent-memory/<name>/`, `project` → `<root>/.candy-code/agent-memory/<name>/`, `local` → `<root>/.candy-code/agent-memory-local/<name>/` with a `*` `.gitignore`. Names are sanitised to the note-id grammar;
  - `AgentManager::resolveBatchSystemPrompts()` appends that store's `MemoryBlock` index to the child prompt;
  - `TaskTool` re-binds the child's `Memory` tool through `MemoryTool::withWriter(MemoryWriter::forAgent(...))`, adding it to the grant when `memory:` is declared (Claude Code's auto-grant).
  - Remove `memory` from `INERT[AGENT]`.
- files.create: `candy-code/src/Memory/AgentMemory.php`
- files.modify: `candy-code/src/Agents/AgentPreset.php` (`$memory` nullable), `candy-code/src/Agents/Agent.php` (ctor, `fromArray`, `fromPreset`, `toArray`, docblock :39), `candy-code/src/Agents/AgentPresetRegistry.php` (`parseMemoryScope` default), `candy-code/src/Agents/ForeignAgentPresetRegistry.php` (:604 default), `candy-code/src/Memory/MemoryWriter.php` (`forAgent`), `candy-code/src/Tools/BuiltIn/MemoryTool.php` (`withWriter`), `candy-code/src/Context/MemoryBlock.php` (agent-index render), `candy-code/src/Agents/AgentManager.php` (`resolveBatchSystemPrompts`), `candy-code/src/Tools/BuiltIn/TaskTool.php` (child tool re-bind beside the SendMessage re-bind ~:1330), `candy-code/src/Support/FrontmatterKeyAudit.php` (`INERT`, docblock), `candy-code/docs/AGENTS_AUTHORING.md` (row :217 Inert → live; example :138), `candy-code/docs/MEMORY.md` (agent-memory section)
- tests: create `candy-code/tests/Memory/AgentMemoryTest.php` (tier paths, sanitising, the local `.gitignore`), `candy-code/tests/Tools/TaskToolAgentMemoryTest.php` (a `memory: project` preset sees its index in the child prompt, and its save lands in the agent store, not the shared one; no `memory:` means no index and no grant); modify `candy-code/tests/Agents/AgentPresetRegistryTest.php` (absent → null), `candy-code/tests/Integration/ForeignAgentPresetWiringTest.php` (the :355 encoded-field list if `memory` goes null)
- docs/guards: `candy-code/tests/Config/InertFrontmatterDocumentationDriftTest.php` (moves: `memory` leaves the inert roster), `candy-code/tests/Config/MemoryDocumentationDriftTest.php` (`docs/MEMORY.md`), `docs/AGENTS_AUTHORING.md`. If `AgentMemory` reads files through a new site, add a `ReadPathCensusTest` row.
- serialized: [hot:candy-code/src/Agents/AgentManager.php] [hot:candy-code/src/Tools/BuiltIn/TaskTool.php] [docs:AGENTS_AUTHORING.md] [docs:MEMORY.md]
- deps: C-AGT-3.a
- phase: normal
- ruling: none
- risks: making the field nullable touches every `Agent` (de)serialisation, including background-session records. The lane greps `->memory->value` and `'memory'` keys.

---

## C-AGT-4 — Primary agents & @agent

### C-AGT-4.a — Primary-agent core (preset → main backend)
- item: C-AGT-4 (sources: GAP-AGT-01/02)
- kind: gap · effort M
- approach: Add `Agents\PrimaryAgent`, which applies a preset to the MAIN backend and can be undone:
  - narrow tools through a new public `AgentManager::toolsForPrimary(Agent)`, a wrapper over `resolveGrantedTools()` that carries C-AGT-3's MCP scoping;
  - set the model with `withModel` unless the preset says `inherit`, and effort with `withReasoningEffort`;
  - narrow the permission mode only (stricter wins);
  - carry the preset body as a new fenced, PromptFence-escaped `<agent-instructions>` section in `Runtime::systemPromptSections()`, PerSession, set through `EngineBackend::withAgentInstructions(?string)`;
  - `PrimaryAgent::revert()` restores the launch tools, model and effort it recorded.
  - A new `mode: primary|subagent|all` preset key (OpenCode spelling, new enum `AgentMode`, default `all`) decides which presets the cycle offers.
  - `/context` (and P-CTX-BREAKDOWN) gets an `agent-instructions` row for free, from the section label.
- files.create: `candy-code/src/Agents/PrimaryAgent.php`, `candy-code/src/Agents/AgentMode.php`
- files.modify: `candy-code/src/Agents/AgentManager.php` (`toolsForPrimary`), `candy-code/src/Backend/EngineBackend.php` (`withAgentInstructions`), `candy-code/src/Runtime.php` (`systemPromptSections`: the section slot behind the maxims/tool-guidance layers), `candy-code/src/Agents/AgentPreset.php` (`mode`), `candy-code/src/Agents/AgentPresetRegistry.php` and `candy-code/src/Agents/ForeignAgentPresetRegistry.php` (parse `mode`), `candy-code/src/Support/FrontmatterKeyAudit.php` (known keys), `candy-code/docs/AGENTS_AUTHORING.md` (`mode` row)
- tests: create `candy-code/tests/Agents/PrimaryAgentTest.php` (apply narrows tools/model/effort; the mode only narrows; revert restores; the section appears once and is escaped); modify `candy-code/tests/Agents/AgentPresetRegistryTest.php` (`mode` parse)
- docs/guards: `docs/AGENTS_AUTHORING.md`; `InertFrontmatterDocumentationDriftTest` if it enumerates known keys. The `ForeignAgentPresetWiringTest` permissionMode census needs a ROW for `Agents/PrimaryAgent.php`, which reads `->permissionMode`; the row goes in-step.
- serialized: [runtime:systemPromptSections] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Agents/AgentManager.php] [docs:AGENTS_AUTHORING.md]
- deps: C-AGT-3.a, C-AGT-3.b
- phase: normal
- ruling: none
- risks: a mid-session section change busts the provider prompt cache once per switch. That is expected, but say so in docs/AGENTS.md (4.b).

### C-AGT-4.b — `/agent use`, Alt+A cycle, TUI badge, serve effect
- item: C-AGT-4 (sources: GAP-AGT-01/02)
- kind: gap · effort M
- approach:
  - `/agent use <preset>` and `/agent use default` become a verb of `AgentsCommand`/`AgentsHostCommand`, returning a new `CommandEffect::setPrimaryAgent(?string)`.
  - Chat's `applyCommandResult` arm applies it through `PrimaryAgent` (stored per session as `primaryAgent`), refused mid-turn like a mode switch. `SessionHost`'s `applyCommandResult` arm stores it on its session-backend override, as `SetModel` does (`SessionHost.php:1348`).
  - Add a `chat.agent-cycle` binding on `Alt+A` (free; Tab, Shift+Tab and Ctrl+Tab are taken) that cycles default → `mode: primary|all` presets.
  - Show the agent name in the status line beside the mode badge (`Renderer::modeBadge` :2522).
  - `handleAgentsCommand` (`Chat.php:12930`) keeps its live-run → Agent View branch, which P-AGENT-ATTACH owns. `use` is reserved before that lookup.
  - Not persisted across restarts (documented).
- files.create: none
- files.modify: `candy-code/src/Commands/AgentsCommand.php` (`use` verb), `candy-code/src/Host/Commands/AgentsHostCommand.php`, `candy-code/src/Host/Commands/CommandEffect.php` and `candy-code/src/Host/Commands/CommandEffectKind.php` (`SetPrimaryAgent`), `candy-code/src/Chat.php` (`handleAgentsCommand`, `applyCommandResult` arm, `primaryAgent` state, Alt+A key arm), `candy-code/src/Host/SessionHost.php` (`applyCommandResult` arm, `primaryAgent()` accessor), `candy-code/src/Renderer.php` (`modeBadge` neighbour), `candy-code/src/Commands/KeyBindingRegistry.php` (`chat.agent-cycle`), `candy-code/builtin-commands/0800-agents.php` (argument hint), `candy-code/lang/en.php` (`cmd.agents.*`, `keys.chat.agent-cycle`, `host.agents.use_*`), `candy-code/README.md` (Keys table), `candy-code/docs/AGENTS.md` (primary agents section), `candy-code/docs/COMMANDS.md` (regenerated)
- tests: create `candy-code/tests/Chat/PrimaryAgentSwitchTest.php` (`/agent use reviewer` narrows the next turn's tools and shows the badge; `default` reverts; refused mid-turn; Alt+A cycles only primary-mode presets); modify `candy-code/tests/Commands/AgentsCommandTest.php` (`use` verb), `candy-code/tests/Host/SessionHostCommandsTest.php` (served effect)
- docs/guards: `candy-code/tests/Commands/KeyBindingDriftTest.php` + README Keys table; `candy-code/tests/Commands/CommandDocsDriftTest.php` (`php tools/gen-command-docs.php --write`); `docs/AGENTS.md`
- serialized: [chat:handleAgentsCommand, applyCommandResult, key dispatch,ctor] [renderer:modeBadge] [commands:0800-agents.php] [hot:candy-code/src/Host/SessionHost.php] [lang: `cmd.agents.*`, `keys.chat.agent-cycle`, `host.agents.*`] [docs:README.md] [docs:AGENTS.md] [docs:COMMANDS.md]
- deps: C-AGT-4.a, P-AGENT.b (shared Agents handler/strip files; never the same wave)
- phase: normal
- ruling: none
- risks: shares `handleAgentsCommand`, `AgentsCommand` and the status line with P-AGENT-ATTACH. Never run both in one wave.

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

### C-AGT-4.d — `@agent:<name>` mentions (TUI, serve, `-p`, web)
- item: C-AGT-4 (sources: GAP-AGT-20)
- kind: gap · effort M
- approach:
  - Reserve `@agent:<name>` in `ContextMentions` (`MENTION_PATTERN`/`KEYWORD_PATTERN` :73-76, `resolve()` :186). A known name becomes a framed directive attachment, "the user asks you to delegate this to agent `<name>` via the Task tool". An unknown name is a notice, never a guess.
  - The roster is a new `withAgentRoster(\Closure)`, threaded into `TurnController::userTurnMessage()` (:1033, one construction site for TUI and serve) from `Chat::userTurnMessage()` (:9925) and `SessionHost::admitPrompt`, and into `NonInteractive`'s two sites (:448, :1394).
  - TUI: `mentionPopupRows()` (:16399) offers roster names after `@agent:`.
  - Web: `Composer.vue`'s `@` popup offers them from the agents store (client-only).
- files.create: none
- files.modify: `candy-code/src/Attachments/ContextMentions.php` (patterns, `resolve`, `withAgentRoster`), `candy-code/src/Host/TurnController.php` (`userTurnMessage` param), `candy-code/src/Chat.php` (`userTurnMessage`, `mentionPopupRows`), `candy-code/src/Host/SessionHost.php` (`admitPrompt` roster pass), `candy-code/src/Cli/NonInteractive.php` (mention construction :448, :1394), `candy-code-web/src-web/components/Composer.vue`, `candy-code/README.md` (mentions section), `candy-code/docs/AGENTS.md`
- tests: modify `candy-code/tests/Attachments/ContextMentionsTest.php` (`@agent:reviewer` known → directive; unknown → notice; trailing punctuation), `candy-code/tests/Host/TurnControllerTest.php` (roster threaded), `candy-code/tests/Cli/NonInteractiveMentionsTest.php` (`-p` resolves it); modify `candy-code-web/src-web/components/__tests__/composerFilesImages.spec.ts` (agent completions)
- docs/guards: README mentions section; `docs/AGENTS.md`; rebuild `candy-code-web/dist/` (weld)
- serialized: [chat:userTurnMessage, mentionPopupRows] [headless] [hot:candy-code/src/Attachments/ContextMentions.php] [hot:candy-code/src/Host/TurnController.php] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code-web/src-web/components/Composer.vue] [docs:README.md] [docs:AGENTS.md]
- deps: C-AGT-3.a, C-AGT-3.b
- phase: normal
- ruling: none
- risks: `ContextMentions.php` is also edited by C-AGT-2.a, C-AGT-6.b and C-AGT-6.d, and `TurnController.php` by C-WEB-2 (skill hoist). Keep them in different waves.

### C-AGT-4.e — SendMessage reaches other sessions (`session:<id>`)
- item: C-AGT-4 (sources: GAP-AGT-N3, GAP-TL-23)
- kind: gap · effort M
- approach: Turns run in a `pcntl_fork()`ed child (`src/LSP/LspExchangeLock.php:11`), so delivery must cross processes. Use a disk mailbox:
  - a new `Agents\Live\SessionMailbox`: 0700 `~/.candy-code/session-mail/<sessionId>/`, 0600 JSON files, signed with the `AgentInbox` scheme;
  - `SendMessageTool` accepts `to: "session:<id>"` from the session's own agent only, and only for a session of the same root (checked against the session store row). Sub-agents get a refusal;
  - in `PermissionGate::isNoAskTool()` (:1572), a `session:` target is NOT no-ask, so it asks like any write;
  - drains: `SessionHost::tick()` (from C-AGT-1.b) on serve, and Chat's `pumpBackgroundSessions()` (:18386) in the TUI, on the slow watch tick from C-AGT-7.a. Each message is submitted as a followup, fenced as `<session-message from="…">`, carrying no authority.
- files.create: `candy-code/src/Agents/Live/SessionMailbox.php`
- files.modify: `candy-code/src/Tools/BuiltIn/SendMessageTool.php` (`execute`, schema, description), `candy-code/src/Permissions/PermissionGate.php` (`isNoAskTool`), `candy-code/src/Host/SessionHost.php` (`tick` drain), `candy-code/src/Chat.php` (`pumpBackgroundSessions` drain), `candy-code/docs/AGENTS.md` (hub-and-spoke section), `candy-code/README.md` (tools roster block, via `php tools/gen-tool-docs.php --write` if the gloss changes)
- tests: create `candy-code/tests/Agents/Live/SessionMailboxTest.php` (0700/0600, signature refusal, ordering), `candy-code/tests/Tools/SendMessageCrossSessionTest.php` (a session target asks; a sub-agent is refused; a foreign root is refused; a served host drains it as a queued turn)
- docs/guards: `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php` if the `#[BuiltInTool]` gloss changes; `docs/AGENTS.md`; a `ReadPathCensusTest` row for the mailbox reads
- serialized: [chat:pumpBackgroundSessions] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Permissions/PermissionGate.php] [docs:AGENTS.md] [docs:README.md (tools block)]
- deps: C-AGT-1.b, C-AGT-7.a, C-AGT-3.b
- phase: normal
- ruling: none
- risks: background `/bg` daemons are not targets (no inbox), which the refusal says. P-SESSION-TABS makes several sessions per TUI, so the Chat drain must key on the active session id.

---

## C-AGT-5 — Schedules, webhooks, CI bot

### C-AGT-5.a — Model-callable `Schedule` tool
- item: C-AGT-5 (sources: GAP-TL-22, p1-miss: schedules UX)
- kind: gap · effort M
- approach: Add a `Schedule` tool (`#[BuiltInTool]` Ask class, position 41) with `create|list|delete` over `schedules.json`.
  - Turns run forked, so the tool writes PERSISTED rows only; the file under its lock is the channel, and hosts reload by digest. It builds `ScheduleRegistry::loadDefault($context->root)` from `ToolBuildContext::$root`, so no LaunchContext/Bootstrap threading is needed.
  - Rows are root-bound (C-AGT-1.a) and tagged `createdBy: "model"`.
  - Mode is no looser than the session's and never bypass-class (the C-AGT-7.a ceiling); the default is the `dont-ask` floor.
  - At most N model-created rows per root; the cadence floor comes from `Schedule`.
  - A shared `Background\ScheduleFactory` holds the validation that `/schedules add` (5.b) reuses.
  - Pin `Schedule` in `Runtime::WRITE_CAPABLE_TOOL_NAMES` (:704).
- files.create: `candy-code/src/Tools/BuiltIn/ScheduleTool.php`, `candy-code/src/Background/ScheduleFactory.php`
- files.modify: `candy-code/src/Runtime.php` (`WRITE_CAPABLE_TOOL_NAMES`), `candy-code/src/Background/Schedule.php` (`createdBy`), `candy-code/docs/SCHEDULES.md`, `candy-code/README.md` + `candy-code/docs/ARCHITECTURE.md` + `candy-code/docs/PERMISSIONS.md` (regenerated tool blocks and counts), `candy-code/lang/en.php` (`tool.schedule.*`)
- tests: create `candy-code/tests/Tools/BuiltIn/ScheduleToolTest.php` (create writes a root-bound persisted row; a bypass mode is refused; the row cap; delete only touches model rows unless asked; list), `candy-code/tests/Background/ScheduleFactoryTest.php`; modify `candy-code/tests/Tools/Catalog/ToolCatalogTest.php` (position 41), `candy-code/tests/Tools/BuiltInToolCorpusTest.php`, `candy-code/tests/RuntimeTest.php` and `candy-code/tests/Permissions/ShellSubjectToolsCompletenessTest.php` (write-capable list)
- docs/guards: `php tools/gen-tool-docs.php --write` + `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php`; a `candy-code/tests/Integration/ForeignAgentPresetWiringTest.php` census row for `Tools/BuiltIn/ScheduleTool.php` and `Background/ScheduleFactory.php` (`->permissionMode` readers); `docs/SCHEDULES.md`
- serialized: [catalog:position 41] [runtime:WRITE_CAPABLE_TOOL_NAMES] [docs:README.md] [docs:ARCHITECTURE.md] [docs:PERMISSIONS.md] [docs:SCHEDULES.md] [lang: `tool.schedule.*`]
- deps: C-AGT-1.a, C-AGT-1.b, C-AGT-1.c, C-AGT-7.a
- phase: normal
- ruling: none
- risks: a prompt-injected model can propose recurring runs. Ask class, the mode ceiling and the row cap are the bounds; the docs must say so.

### C-AGT-5.b — `/schedules add`, fire outcomes, web schedules table
- item: C-AGT-5 (sources: GAP-AUT-07, p1-miss: schedules UX)
- kind: gap · effort M
- approach:
  - `/schedules add <name> <interval> <prompt> [--mode m] [--provider p]` goes through `ScheduleFactory`, stamped with the host root. It is mutating, so it is refused in read-only windows; `READ_VERBS` is unchanged.
  - The registry records each row's last `fireDue` outcome (fired, error or refusal) in memory. `BackgroundEvents::pumpSchedules()` (`src/Host/BackgroundEvents.php:221`) stops discarding results, so serve users finally see refusals.
  - `/schedules list` renders a `TranscriptTable` with a last-outcome column.
  - Web: a Schedules subsection in `BackgroundPanel.vue` runs `command.exec /schedules` and renders the table markdown, with enable/disable/delete buttons through `command.exec`. No protocol change.
- files.create: none
- files.modify: `candy-code/src/Host/Commands/SchedulesCommand.php` (`run`, `listing`, new `add`), `candy-code/src/Background/ScheduleRegistry.php` (last outcome), `candy-code/src/Host/BackgroundEvents.php` (`pumpSchedules`), `candy-code/builtin-commands/2910-schedules.php` (hint), `candy-code/lang/en.php` (`cmd.schedules.hint`, `host.schedules.add*`, `host.schedules.outcome*`), `candy-code/docs/COMMANDS.md` + `candy-code/README.md` (regenerated), `candy-code/docs/SCHEDULES.md`, `candy-code-web/src-web/components/panels/BackgroundPanel.vue`
- tests: modify `candy-code/tests/Host/Commands/LoopSchedulesCommandTest.php` (`add` happy path, bad interval, bypass refused, read-only refused), `candy-code/tests/Host/BackgroundEventsSchedulesTest.php` (an outcome is recorded); create `candy-code-web/src-web/components/panels/__tests__/backgroundSchedules.spec.ts`
- docs/guards: `php tools/gen-command-docs.php --write` + `candy-code/tests/Commands/CommandDocsDriftTest.php`; `docs/SCHEDULES.md`; rebuild `candy-code-web/dist/` (weld)
- serialized: [commands:2910-schedules.php] [hot:candy-code/src/Host/BackgroundEvents.php] [hot:candy-code-web/src-web/components/panels/BackgroundPanel.vue] [lang] [docs:COMMANDS.md] [docs:README.md] [docs:SCHEDULES.md]
- deps: C-AGT-5.a
- phase: normal
- ruling: none
- risks: C-TOOL-3's web Processes tab may also land in BackgroundPanel. Mark it hot.

### C-AGT-5.c — Heartbeat schedule kind
- item: C-AGT-5 (sources: GAP-PA-02)
- kind: gap · effort M
- approach:
  - A row gains `kind: "task"|"heartbeat"`. For a heartbeat, `ScheduleSpawner` appends "reply exactly HEARTBEAT_OK when nothing needs attention".
  - When the daemon settles, Chat's `pumpBackgroundSessions()` suppresses the queued announcement turn for an `HEARTBEAT_OK` answer, and otherwise announces it and rings `terminalNotification()` (OSC 9).
  - On serve the run stays an ordinary `bg.completed` row. Web push is C-WEB-5's job (LATE), so it is NOT a dependency.
  - `/schedules add --heartbeat` and the tool's `kind` param pass it through.
- files.create: none
- files.modify: `candy-code/src/Background/Schedule.php` (`kind`), `candy-code/src/Background/ScheduleSpawner.php` (suffix, tag `schedule-heartbeat`), `candy-code/src/Chat.php` (`pumpBackgroundSessions` announce arm), `candy-code/src/Host/Commands/SchedulesCommand.php` (`--heartbeat`), `candy-code/src/Tools/BuiltIn/ScheduleTool.php` (`kind`), `candy-code/lang/en.php` (`chat.schedule.heartbeat_*`), `candy-code/docs/SCHEDULES.md`
- tests: create `candy-code/tests/Chat/HeartbeatScheduleTest.php` (an OK answer queues no turn and rings nothing; any other answer announces and notifies); modify `candy-code/tests/Sessions/ScheduleTest.php` (`kind` round-trip)
- docs/guards: `docs/SCHEDULES.md`
- serialized: [chat:pumpBackgroundSessions] [lang: `chat.schedule.*`] [docs:SCHEDULES.md]
- deps: C-AGT-5.b
- phase: normal
- ruling: none
- risks: `pumpBackgroundSessions` is also edited by C-AGT-4.e. Keep them in different waves.

### C-AGT-5.d — Signed inbound webhooks + delivery dedupe
- item: C-AGT-5 (sources: p1-miss: inbound webhook signatures)
- kind: gap · effort M
- approach: Each `webhooks` row gains an optional `signature: {scheme: "github-sha256"|"hmac-sha256", header?}` (`ServerConfig::webhooksValue()` ~:700-770).
  - With a scheme, `HookWebhooks::handle()` verifies `sha256=<hex>` over the RAW body with the row's secret through `hash_equals`, in place of the `x-candy-code-hook` header, because GitHub cannot send custom headers.
  - A per-hook LRU of delivery ids (`X-GitHub-Delivery`, or a configured `deliveryHeader`) answers a replay `200 {ok:true, duplicate:true}` without spawning.
  - Failed signatures feed the same lockout limiter.
  - A GitHub recipe goes in `docs/SERVER.md`.
- files.create: none
- files.modify: `candy-code/src/Server/ServerConfig.php` (`webhooksValue`), `candy-code/src/Server/Http/HookWebhooks.php` (`handle`, dedupe ring), `candy-code/lang/en.php` (`settings.webhooks.help`), `candy-code/docs/SERVER.md` (hooks row + recipe)
- tests: create `candy-code/tests/Server/HookWebhooksSignatureTest.php` (a valid GitHub signature runs; a bad one → 401 and counts toward lockout; a replayed delivery id spawns nothing); modify `candy-code/tests/Server/ServerConfigWebhooksTest.php` (`signature` parse and refusals)
- docs/guards: `docs/SERVER.md`; `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php` stays green
- serialized: [hot:candy-code/src/Server/Http/HookWebhooks.php] [hot:candy-code/src/Server/ServerConfig.php] [lang: `settings.webhooks.*`] [docs:SERVER.md]
- deps: C-AGT-1.c, C-AGT-7.c
- phase: normal
- ruling: none
- risks: GitHub push payloads often exceed the 64 KiB global body cap (now a loud 413 from C-AGT-1.c). A per-route larger cap would be a separate server change.

### C-AGT-5.e — Outbound webhooks core (sign, async send, retry)
- item: C-AGT-5 (sources: p1-miss: outbound webhooks, p2-miss: outbound webhooks)
- kind: gap · effort M
- approach: Add a self-contained `Automation` namespace, with no serialized files:
  - `OutboundWebhookConfig` parses a user-tier map `{name: {url, secret, events[]}}`. It requires https, or http only to loopback, and refuses redirects;
  - `WebhookSigner` sets `X-Candy-Code-Signature: sha256=<hmac>` and an `X-Candy-Code-Timestamp`;
  - `WebhookEvent` builds the JSON body for `turn.completed`, `session.ended`, `permission.asked` and `schedule.fired`, with bounded fields and no transcript text;
  - `WebhookDispatcher` POSTs with `React\Http\Browser` on the loop. It never blocks `update()`, uses a connect timeout plus a short per-POST cap (webhook, not an LLM call), retries 3× with 2/8/30 s backoff on loop timers, uses a bounded queue, and drops on overflow with a runtime notice.
  - No fork, so no child-lifetime row.
- files.create: `candy-code/src/Automation/OutboundWebhookConfig.php`, `candy-code/src/Automation/WebhookSigner.php`, `candy-code/src/Automation/WebhookEvent.php`, `candy-code/src/Automation/WebhookDispatcher.php`
- files.modify: none
- tests: create `candy-code/tests/Automation/OutboundWebhookConfigTest.php`, `candy-code/tests/Automation/WebhookSignerTest.php` (known-vector HMAC), `candy-code/tests/Automation/WebhookDispatcherTest.php` (a fake Browser: retry/backoff on 5xx, no retry on 4xx, overflow drop, never throws)
- docs/guards: none in-step (5.f documents)
- serialized: none
- deps: C-AGT-1.a, C-AGT-1.b, C-AGT-1.c (item order)
- phase: normal
- ruling: none
- risks: the tests must pin a stable loop clock (`LoopPin` via `candy-code/tests/bootstrap.php`, already present) for the backoff timers.

### C-AGT-5.f — Outbound webhooks wiring + `webhooksOut` setting
- item: C-AGT-5 (sources: p1-miss: outbound webhooks, p2-miss: outbound webhooks)
- kind: gap · effort M
- approach:
  - Register a `webhooksOut` setting, user tier only, so a project can never add egress, through `SettingDefinition` in `ServerSettings.php`, read at restart.
  - `Bootstrap::workspace()` registers one `WebhookDispatcher` service.
  - TUI emitters: the turn settle in `HandlesTurnCompletion::handleTurnCompletionMessages()` (beside `terminalNotification`, :343), `Chat::requestPermission()` (:2761), and `pumpSchedules()` fired results.
  - Serve emitters: `ServerContext` subscribes each host's `SessionEvent` stream (`TURN_COMPLETED`, permission asked) when it builds the host's feed, and `BackgroundEvents` reports `schedule.fired`.
  - Replace "there is no outbound webhook" in `docs/SERVER.md` (:308-310).
- files.create: none
- files.modify: `candy-code/src/Config/Settings/Definitions/ServerSettings.php`, `candy-code/src/Cli/Bootstrap.php` (`workspace()` service), `candy-code/src/Chat.php` (`requestPermission`, `pumpSchedules`), `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php` (`handleTurnCompletionMessages`), `candy-code/src/Protocol/ServerContext.php` (host feed subscription), `candy-code/src/Host/BackgroundEvents.php` (`pumpSchedules` emit), `candy-code/lang/en.php` (`settings.webhooksOut.*`), `candy-code/docs/SETTINGS.md` (regenerated), `candy-code/docs/SERVER.md` (outbound section)
- tests: create `candy-code/tests/Automation/OutboundWebhookWiringTest.php` (a TUI settle and a served `TURN_COMPLETED` each enqueue one signed POST; a project-tier `webhooksOut` is ignored)
- docs/guards: `php tools/gen-settings-doc.php` + `candy-code/tests/Config/Settings/SettingsSchemaDocDriftTest.php`; `candy-code/tests/Config/DocFigureProseDriftTest.php` (the settings count word "one hundred and twenty-nine" moves); `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php` if the README names tiers; `docs/SERVER.md`
- serialized: [bootstrap:workspace] [chat:requestPermission, pumpSchedules] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code/src/Host/BackgroundEvents.php] [lang: `settings.webhooksOut.*`] [docs:SETTINGS.md] [docs:SERVER.md]
- deps: C-AGT-5.e, C-AGT-5.c
- phase: normal
- ruling: none
- risks: the settings-count prose is shared with every other area adding a setting. `docs/SETTINGS.md` needs one owner per wave.

### C-AGT-5.g — GitLab CI recipe around the signed phar
- item: C-AGT-5 (sources: p2-miss: GitLab CI component)
- kind: gap · effort S
- approach: Add a `docs/CI.md` "GitLab CI" section: a `.gitlab-ci.yml` job that downloads the `candy-code/v*` release phar, verifies sha256 and the pinned-key minisig (the `release-phar.yml` assets), and runs `-p --output-format json --permission-mode plan` with `jq` over the envelope. It repeats the fork/MR and prompt-injection doctrine. Docs only.
- files.create: none
- files.modify: `candy-code/docs/CI.md` (new section)
- tests: none (docs-only; the YAML is illustrative)
- docs/guards: `docs/CI.md`
- serialized: [docs:CI.md]
- deps: C-AGT-2.c, C-AGT-1.c (item order)
- phase: normal
- ruling: none
- risks: the phar asset names must match `release-phar.yml`; the lane reads that workflow, not memory.

### C-AGT-5.h — PR-comment bot example workflow
- item: C-AGT-5 (sources: GAP-GIT-12, GAP-AUT-05, p2-miss: GIT-12 bot)
- kind: gap · effort S
- approach: Ship `candy-code/docs/examples/pr-comment-bot.yml`, an `issue_comment` workflow:
  - it is gated on an `@candy-code` mention AND `author_association` ∈ OWNER/MEMBER/COLLABORATOR;
  - job 1 runs the existing action in `plan` mode on the PR's diff text, with no write token and never checking out or running PR code;
  - job 2 alone holds `pull-requests: write` and posts `result` with `gh pr comment`;
  - every value crosses into shell through `env:` only.
  - `action.yml` is unchanged, so its "holds no token" doctrine stands.
  - Add a `docs/CI.md` section, and a `tools/tests` guard that the example keeps the env-only and association gates.
- files.create: `candy-code/docs/examples/pr-comment-bot.yml`, `tools/tests/PrCommentBotExampleTest.php`
- files.modify: `candy-code/docs/CI.md` (bot section), `candy-code/docs/README.md` (examples row wording)
- tests: create `tools/tests/PrCommentBotExampleTest.php` (no `${{` inside `run:`, the association gate is present, no `pull_request_target` checkout of the head)
- docs/guards: `docs/CI.md`
- serialized: [docs:CI.md] [docs:README.md (docs index)]
- deps: C-AGT-5.g
- phase: normal
- ruling: R13 (remote security). Is an issue-comment-triggered bot that posts with a write token acceptable, and under which association gate?
- risks: a mis-copied example by users is the residual risk. The guard pins only our copy.

---

## C-AGT-6 — /commit, /pr & worktrees

### C-AGT-6.a — `/commit` with a drafted message
- item: C-AGT-6 (sources: GAP-GIT-03, p2-miss: built-in `/commit` (×2))
- kind: gap · effort M
- approach: `/commit [message]` is a builtin with a host body, so serve and web get it as `runsIn: server`.
  - `CommitHostCommand` returns `CommandEffect::async`, which collects every changed path (`git status --porcelain -z`).
  - With no message, it drafts the subject on the title backend through `CommitMessageWriter::request()`/`subjectFrom()`, falling back to `CommitMessageWriter::fallback()`.
  - It commits through a new public `AutoCommitter::commitAll(string $subject)`: the hardened `commit()` path, recorded as kind `command`, so `/undo` can revert it.
  - It replies with the sha, subject and file count. "Nothing to commit" is a plain reply.
  - It is not read-only-safe: the default, so no roster edit.
  - The Chat handler is one line through `runHostCommand`. The new spec file is per-command-disjoint.
- files.create: `candy-code/builtin-commands/2850-commit.php`, `candy-code/src/Host/Commands/CommitHostCommand.php`
- files.modify: `candy-code/src/Workspace/AutoCommitter.php` (`commitAll`), `candy-code/src/Chat.php` (new `handleCommitCommand`), `candy-code/lang/en.php` (`cmd.commit.*`, `host.commit.*`), `candy-code/docs/COMMANDS.md` + `candy-code/README.md` (regenerated slash rosters)
- tests: create `candy-code/tests/Host/Commands/CommitHostCommandTest.php` (temp repo: given message → commit and sha; no message → scripted title backend drafts `feat: …`; clean tree → reply, no commit; read-only window refused; `/undo` reverts it); modify `candy-code/tests/Workspace/AutoCommitterTest.php` (`commitAll`)
- docs/guards: `php tools/gen-command-docs.php --write` + `candy-code/tests/Commands/CommandDocsDriftTest.php`, `candy-code/tests/Config/ReadmeRosterDriftTest.php`, `candy-code/tests/Commands/BuiltInCommandsTest.php`
- serialized: [chat:new handleCommitCommand] [lang: `cmd.commit.*`, `host.commit.*`] [docs:COMMANDS.md] [docs:README.md]. The new `builtin-commands/2850-commit.php` is per-command-disjoint, not [commands].
- deps: C-AGT-2.a, C-AGT-2.b, C-AGT-2.c (item order)
- phase: normal
- ruling: none
- risks: a project's own `.claude/commands/commit.md` already shadows by precedence rules. The lane checks that the R7 strict-bottom foreign-command rule keeps the built-in visible.

### C-AGT-6.b — `/review` ranges (`A..B`, `A...B`, `@{u}`)
- item: C-AGT-6 (sources: p2-miss: GIT-10 review scope parity, GIT-1-P1 fix (b))
- kind: gap · effort M
- approach: `ContextMentions::diff()` (:234) learns ranges, through the same bounded `GitRunner` and safety flags:
  - `A..B` → `git diff --no-ext-diff --no-textconv --no-color -M --patch A B`;
  - `A...B` → `git diff --merge-base A B`, falling back to `merge-base` + `diff` on an old git.
  - `isDiffRef()` and `ReviewCommand::refError()` re-admit `..`/`...` only in those two shapes. `refError()` pre-resolves each side.
  - `cmd.review.hint` and `bad-ref` list the accepted forms again.
  - Untracked files stay out, as documented.
- files.create: none
- files.modify: `candy-code/src/Attachments/ContextMentions.php` (`diff`, `isDiffRef`), `candy-code/src/Commands/ReviewCommand.php` (`refError`), `candy-code/lang/en.php` (`cmd.review.hint`, `cmd.review.bad-ref`), `candy-code/docs/COMMANDS.md` (regenerated if the hint changes)
- tests: modify `candy-code/tests/Attachments/ContextMentionsTest.php` (`@diff:base..HEAD` and `@diff:base...HEAD` attach the right patch in a temp repo), `candy-code/tests/Chat/ReviewCommandTest.php` (the 2.a refusal arms for ranges flip back to "attached")
- docs/guards: `candy-code/tests/Commands/CommandDocsDriftTest.php` (hint)
- serialized: [hot:candy-code/src/Attachments/ContextMentions.php] [lang: `cmd.review.*`] [docs:COMMANDS.md]
- deps: C-AGT-2.a, C-AGT-2.b
- phase: normal
- ruling: none
- risks: `git diff` is porcelain, so it honours `diff.*` config. The `--no-ext-diff --no-textconv --no-color` flags must stay, and a test sets a hostile `diff.external` to prove it.

### C-AGT-6.c — `--worktree [name]` main sessions
- item: C-AGT-6 (sources: GAP-GIT-08)
- kind: gap · effort M
- approach:
  - `--worktree [name]` (TUI and `-p`) parses in `ArgvParser` and `ParsedArgs`.
  - Before `Bootstrap::app()`/`NonInteractive`, `bin/candy-code` calls a new `Cli\MainWorktree::enter($root, $name)`. It reuses `WorktreeManager::createWorktree()` keyed `main-<name>` (branch `candy-code/<name>`, auto-name when bare) and returns the worktree path as the root.
  - A shutdown hook runs `releaseIfUnused()`, so the worktree is removed when it has no commits or diff and kept (and announced) otherwise.
  - Refused outside a git work tree, and with `serve`.
  - Bootstrap itself is untouched; the root swap happens in bin.
- files.create: `candy-code/src/Cli/MainWorktree.php`
- files.modify: `candy-code/bin/candy-code` (root swap, the `$strictValueFlags` list :189 if valued), `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/Help.php`, `candy-code/src/Agents/WorktreeManager.php` (main-session naming helper), `candy-code/lang/en.php` (`cli.argv.worktree.*`, `cli.worktree.*`), `candy-code/README.md` (CLI flags)
- tests: create `candy-code/tests/Cli/MainWorktreeTest.php` (temp repo: create, root is the worktree, an unused one is removed at release, one with commits is kept, non-git refused); modify `candy-code/tests/Cli/ArgvParserTest.php`, `candy-code/tests/Cli/LaunchFlagsTest.php`
- docs/guards: `candy-code/tests/Config/ReadmeRosterDriftTest.php` (flag roster); the ArgvParser/bin flag mirror in `ArgvParserTest`. WorktreeManager's git calls are existing accounted sites, so no child-lifetime row.
- serialized: [headless] [lang: `cli.*worktree*`] [docs:README.md]
- deps: C-AGT-2.a, C-AGT-2.b, C-AGT-2.c (item order)
- phase: normal
- ruling: none
- risks: [headless] is also claimed by C-AGT-7.b, C-AGT-4.d and C-SES-1. One owner per wave.

### C-AGT-6.d — `/pr` canned turn + `/review --pr <n>`
- item: C-AGT-6 (sources: GAP-GIT-04, p2-miss: GIT-10 review scope parity (`--pr`))
- kind: gap · effort M
- approach:
  - `/pr [focus]` is a canned turn (a `PrCommand::PROMPT` const, shaped like `ReviewCommand`) telling the agent to push the branch and open a PR with `gh pr create`, honouring `attribution.pr`. Its host body `PrHostCommand` returns `submitPrompt`.
  - `/review --pr <n>` closes on a new `@pr:<n>` mention that `ContextMentions` resolves through a new `Workspace\GhRunner`: a bounded `gh pr diff <n> --color never` on the `CapturesProcessOutput::runCaptured` path GitRunner already uses, so no new `proc_open` site. A missing or unauthenticated `gh` becomes a notice.
  - Gated on the R13 ruling.
- files.create: `candy-code/builtin-commands/2860-pr.php`, `candy-code/src/Commands/PrCommand.php`, `candy-code/src/Host/Commands/PrHostCommand.php`, `candy-code/src/Workspace/GhRunner.php`
- files.modify: `candy-code/src/Attachments/ContextMentions.php` (`@pr:` keyword), `candy-code/src/Chat.php` (new `handlePrCommand`; `reviewTurn` `--pr` parse), `candy-code/src/Commands/ReviewCommand.php` (`prompt` with a PR mention), `candy-code/lang/en.php` (`cmd.pr.*`, `cmd.review.hint`), `candy-code/docs/COMMANDS.md` + `candy-code/README.md` (regenerated)
- tests: create `candy-code/tests/Host/Commands/PrHostCommandTest.php`, `candy-code/tests/Workspace/GhRunnerTest.php` (a fake `gh` on PATH: diff captured and bounded; absent `gh` gives a notice); modify `candy-code/tests/Attachments/ContextMentionsTest.php` (`@pr:12`)
- docs/guards: `php tools/gen-command-docs.php --write` + `CommandDocsDriftTest`, `ReadmeRosterDriftTest`. If the lane must add a fresh spawn instead of `runCaptured`, it goes through `ProcessContainment::spawnSpec` with a `tools/check-child-lifetimes.php` roster row.
- serialized: [chat:handlePrCommand (new), reviewTurn] [hot:candy-code/src/Attachments/ContextMentions.php] [lang: `cmd.pr.*`, `cmd.review.*`] [docs:COMMANDS.md] [docs:README.md]. `builtin-commands/2860-pr.php` is a new per-command file.
- deps: C-AGT-6.b
- phase: normal
- ruling: R13 (remote security). May candy-code shell out to `gh` (the user's GitHub credentials) for `/review --pr` and drive `gh pr create` from `/pr`?
- risks: `gh` inherits the user's token. The runner passes a scrubbed env except `GH_*`/`GITHUB_TOKEN` and never passes model text as argv flags.

### C-AGT-6.e — PR status chips in the web
- item: C-AGT-6 (sources: p2-miss: WEB-8 PR chips)
- kind: gap · effort M
- approach:
  - For a session whose branch C-WEB-4 already exposes, the server reads `gh pr view <branch> --json number,state,url,statusCheckRollup` through `GhRunner`. It is cached per branch for 60 s and refreshed lazily on summary build, never on the turn path.
  - The result goes into a nullable `SessionSummary.pr {number, state, url, checks}`.
  - `StatusBar.vue` and the `SessionSidebar.vue` row render a chip linking to the PR, with a pass/fail/pending dot.
  - Gated on R13.
- files.create: `candy-code/src/Workspace/PrStatusCache.php`
- files.modify: `candy-code/src/Protocol/Schema/Definitions.php` (SessionSummary `pr`), `candy-code/src/Protocol/ServerContext.php` (`summary`), `candy-code/docs/protocol/candy-code.v1.schema.json` + `candy-code/docs/SERVER.md` + `candy-code-web/src-web/protocol/generated.ts` (generated), `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/components/SessionSidebar.vue`
- tests: create `candy-code/tests/Workspace/PrStatusCacheTest.php` (fake `gh`: cached within TTL; failure → null, never throws); modify `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`, `candy-code-web/src-web/components/__tests__/sidebar.spec.ts`
- docs/guards: protocol gen chain checks + `ServerProtocolDocumentationDriftTest`; rebuild `candy-code-web/dist/` (weld)
- serialized: [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/components/SessionSidebar.vue] [docs:SERVER.md]
- deps: C-AGT-6.d, C-WEB-4 (its GAP-GIT-01 `branch` on SessionSummary)
- phase: normal
- ruling: R13 (as C-AGT-6.d, plus showing CI status pulled with the user's GitHub token in a browser client)
- risks: the summary build must stay non-blocking. The `gh` call runs off the summary path (cached, refreshed on a timer), or a slow `gh` stalls `session.list`.

---

## C-AGT-7 — Automation minor/nit cleanup

### C-AGT-7.a — Schedule hygiene: watch tick, bypass ceiling, run limit
- item: C-AGT-7 (sources: AUT-1-P2, AUT-1-P3, AUT-1-P4)
- kind: fix · MINOR/NIT · effort M
- approach:
  - P2: `Chat::subscriptions()` arms the 2 s tick only when a row is enabled or a daemon is active (`src/Chat.php:18118-18128`), so a row hand-written after an empty launch never fires in that TUI. Add a slow 30 s watch tick, armed whenever the registry has a backing path (`ScheduleRegistry::isWatchable()`), that reloads by digest. C-AGT-4.e's mailbox drain reuses it.
  - P3: `Schedule` coerces only unknown modes (:110), so a spelled `bypass-permissions` row runs unattended. Refuse bypass-class rows at load with a named note and skip the row, matching the webhook refusal (`ServerConfig.php:753`).
  - P4: the docblock "no timeout anywhere" (:207) is false, because `spawnSession()` defaults to 3600 s. Correct it, and accept an optional row `timeoutSeconds` (bounded, at least 60) that `ScheduleSpawner` passes through. The default 3600 is documented. This is a session cap, not an LLM request timeout (E646).
- files.create: none
- files.modify: `candy-code/src/Chat.php` (`subscriptions`), `candy-code/src/Background/Schedule.php` (ctor floor, `fromArray`, `toArray`, docblocks), `candy-code/src/Background/ScheduleRegistry.php` (`load` note, `isWatchable`), `candy-code/src/Background/ScheduleSpawner.php` (`timeoutSeconds`), `candy-code/lang/en.php` (`host.schedules.none` text, bypass note), `candy-code/docs/SCHEDULES.md`
- tests: modify `candy-code/tests/Chat/ScheduleLoopFireTest.php` (a file row written after an empty launch fires within one watch tick), `candy-code/tests/Sessions/ScheduleTest.php` (bypass refused with a note; `timeoutSeconds` round-trip and bounds), `candy-code/tests/Sessions/ScheduleRegistryTest.php`
- docs/guards: `docs/SCHEDULES.md`; the `ForeignAgentPresetWiringTest` permissionMode census rows for `Schedule.php`/`ScheduleRegistry.php` already exist (the comment widens if the rationale changes)
- serialized: [chat:subscriptions] [lang: `host.schedules.*`] [docs:SCHEDULES.md]
- deps: C-AGT-1.a, C-AGT-1.b
- phase: normal
- ruling: none. Refusal was chosen over a `schedules.allowBypass` key, for parity with webhooks.
- risks: an always-armed 30 s tick wakes idle TUIs twice a minute. The pump must return `$this` unchanged when nothing moved, or every wake repaints.

### C-AGT-7.b — Headless honesty: `session_id`, `--json-schema`
- item: C-AGT-7 (sources: AUT-2-P1, AUT-4-P1, AUT-4-P2)
- kind: fix · MINOR · effort M
- approach:
  - AUT-2-P1: the envelope carries `$opened['id']` (`src/Cli/NonInteractive.php:597-612`) and is printed (:636-641) before the save (:653-664). For `json`/`stream-json`, persist first inside the same try and render after, with `session_id` only on a successful save. Text output keeps answer-first.
  - AUT-4-P1: `--json-schema` without `-p`/`run` parses clean and is ignored (`ArgvParser.php:640-688`; bin never reads it). Make it a usage error (exit 2). The lane checks whether `--output-format` merits the same.
  - AUT-4-P2: the schema rides only the first step (`Runtime::jsonSchemaFor()` :845-872). `NonInteractive` sets `json_schema_applied: false` when the settled answer followed a tool result (computed from the run's history, so no Runtime edit), plus one stderr notice. The README discloses the first-step boundary.
- files.create: none
- files.modify: `candy-code/src/Cli/NonInteractive.php` (render/persist order, envelope key), `candy-code/src/Cli/ArgvParser.php` (usage error), `candy-code/lang/en.php` (`cli.argv.json_schema.requires_prompt`, `cli.noninteractive.json_schema_unapplied`), `candy-code/README.md` (`--json-schema` line :1731, the "Six keys are **conditional**" paragraph :1947 → seven)
- tests: modify `candy-code/tests/Cli/NonInteractiveSessionPersistenceTest.php` (a forced save failure → no `session_id`), `candy-code/tests/Cli/LaunchFlagsTest.php` (`--json-schema` without a prompt → exit 2), `candy-code/tests/Cli/JsonSchemaEnvelopeTest.php` (a tool-using turn → `json_schema_applied:false` + notice; a toolless turn has no key)
- docs/guards: README envelope paragraph. `candy-code/tests/DenialPrefixRosterTest.php` anchors on "are **conditional**" (count-word-free), so it stays green. `candy-code/tests/Config/ReadmeJsonErrorContractDriftTest.php` if it pins the key set.
- serialized: [headless] [lang: `cli.*`] [docs:README.md]
- deps: none
- phase: normal
- ruling: none. The opt-in v2 "re-request the final step under the schema" is left out: it is an extra billed call.
- risks: [headless] collides with C-SES-1's ledger write at the same persist block (SES-1-P1). Same owner or different waves.

### C-AGT-7.c — Webhook mode clamp + hygiene
- item: C-AGT-7 (sources: AUT-6-P2, AUT-6-P3)
- kind: fix · MINOR/NIT · effort S
- approach:
  - AUT-6-P2: `answersAsksWithoutAHuman()` (`src/Server/ServerConfig.php:574`) classes the strictest mode, `dont-ask`, with bypass. Hooks refuse it (:753), and `hookPermissionModeFallback()` (:592) widens a dont-ask server's hooks to `default`. Add `hookRefusesMode()`, which refuses bypass only, and use it at :753. Make the fallback the stricter of the server's mode and `default`, via `PermissionMode::strictness()`. Correct the :590 docblock: hooks spawned with `askTimeoutSeconds` do reach the approvals relay.
  - AUT-6-P3: move the `PromptFence::escape()` splice inside `handle()`'s try (`HookWebhooks.php:130-136`), so a PCRE failure gives the generic 503. Add a per-hook global rate window beside the per-address one, and say "per address, and per hook overall" in `docs/SERVER.md`.
- files.create: none
- files.modify: `candy-code/src/Server/ServerConfig.php` (`hookRefusesMode`, `hookPermissionModeFallback`, `webhooksValue`), `candy-code/src/Server/Http/HookWebhooks.php` (`handle`, `admit`), `candy-code/lang/en.php` (`settings.webhooks.help` rate wording), `candy-code/docs/SERVER.md` (:299)
- tests: modify `candy-code/tests/Server/ServerConfigHookModeInheritanceTest.php` (flip :54: a dont-ask server's hooks stay dont-ask), `candy-code/tests/Server/ServerConfigWebhooksTest.php` (a `dont-ask` hook is accepted; bypass is still refused), `candy-code/tests/Server/HookWebhooksTest.php` (global rate across addresses; a throwing escape → 503)
- docs/guards: `docs/SERVER.md`
- serialized: [hot:candy-code/src/Server/Http/HookWebhooks.php] [hot:candy-code/src/Server/ServerConfig.php] [lang: `settings.webhooks.*`] [docs:SERVER.md]
- deps: C-AGT-1.c
- phase: normal
- ruling: none
- risks: an acceptEdits or auto server now hands mode-less hooks `default`, which is narrower than today. That is intended; the docs must state it.

---

## Notes for the orchestrator

- **Step counts.** C-AGT-4 has 5 steps and C-AGT-5 has 8, above the brief's 2–4 for an L item. Each bundles three to seven independent features: primary agents, `@agent`, cross-session messaging; Schedule tool, add/table, heartbeat, inbound signatures, outbound core and wiring, GitLab, bot. Each step is one cohesive S/M lane. Merging any two would turn an M into an L, or pin a core lane to a serialized file.
- **Item ordering honoured.** C-AGT-1 → C-AGT-5: every 5.x depends on 1.x, directly or transitively. C-AGT-2 → C-AGT-6: every 6.x depends on 2.a and 2.b. C-AGT-3 → C-AGT-4: 4.a and 4.d depend on 3.a and 3.b, and the rest chain through them.
- **P-AGENT-ATTACH shared files** (flagged as asked):
  - `candy-code/src/Chat.php` `handleAgentsCommand` (:12930): its live-run → Agent View branch is theirs, the `use` verb is C-AGT-4.b's;
  - `candy-code/src/Commands/AgentsCommand.php` and `candy-code/src/Host/Commands/AgentsHostCommand.php`;
  - `candy-code/src/Renderer.php` (status line);
  - `candy-code/src/Protocol/Methods/AgentsMethods.php` (C-AGT-4.c adds `mode` to `agents.list`; they likely touch `agents.message`/`agents.transcript`);
  - `candy-code-web/src-web/stores/agents/`;
  - `candy-code/src/Agents/AgentManager.php`.
  - Never schedule C-AGT-4.b or C-AGT-4.c in the same wave as P-AGENT-ATTACH's wiring and protocol steps.
- **Hot files spanning my steps.**
  - `candy-code/src/Host/SessionHost.php`: 1.b, 2.b, 4.b, 4.d, 4.e.
  - `candy-code/src/Protocol/ServerContext.php`: 1.b, 4.c, 5.f, 6.e. Also C-SES-4's serve OTel and probably P-SESSION-TABS.
  - `candy-code/src/Attachments/ContextMentions.php`: 2.a, 4.d, 6.b, 6.d. Also any C-MCP `@mcp:` work.
  - `candy-code/src/Server/Http/HookWebhooks.php` and `src/Server/ServerConfig.php`: 1.c → 7.c → 5.d, chained.
  - `candy-code/src/Agents/AgentManager.php`: 3.a, 3.b, 4.a.
  - `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php`: 2.b, 5.f.
  - `candy-code-web/src-web/components/StatusBar.vue`: 4.c, 6.e, and C-WEB-4's branch badge.
  - `candy-code/src/Host/TurnController.php`: 4.d, and C-WEB-2's skill hoist.
  - `candy-code/src/Permissions/PermissionGate.php`: 4.e, and the C-SEC items.
- **`candy-code-web/dist/` is committed (9 files, hashed names).** Every web lane (4.c, 4.d, 5.b, 6.e) rebuilds it, so concurrent web lanes conflict there. Have the weld rebuild dist once per wave instead of each lane.
- **New doc page.** C-AGT-1.a creates `candy-code/docs/SCHEDULES.md` plus its `docs/README.md` index row. Later edits are 1.b, 5.a, 5.b, 5.c and 7.a, all chained through deps.
- **Catalog.** C-AGT-5.a claims built-in position **41** (`Schedule`); positions 39 and 40 are `mcp_list` and `mcp_call`. Confirm no other non-LATE area claims 41. C-TOOL-6's new tools are LATE and should start at 42+.
- **Cross-area deps.**
  - C-WEB-2's `ReviewHostCommand` must come after C-AGT-2.a (reuse `ReviewCommand::refError()`) and C-AGT-2.b (submit with `SubmitOptions::withModeCeiling(Plan)`, so served reviews are clamped too).
  - C-AGT-6.e needs C-WEB-4's `SessionSummary.branch`.
  - C-AGT-5.c leaves heartbeat web push to C-WEB-5 (LATE); there is no dependency in either direction.
  - C-SES-1 (SES-1-P1) and C-AGT-7.b both edit the `NonInteractive.php` persist block ([headless]).
  - C-SES-6 (installer) may also edit README "Install as a user", which C-AGT-2.c rewrites.
- **Picks to tell.**
  - P-SESSION-TABS must key the TUI's ephemeral `/loop` pump and C-AGT-4.e's mailbox drain on the active tab's session id; C-AGT-1.b adds `ScheduleRegistry::dueEphemeralFor()`.
  - P-CTX-BREAKDOWN gets an `agent-instructions` row from C-AGT-4.a's new prompt section (the "custom agents" line).
- **Rulings.** R13 gates C-AGT-5.h (bot), C-AGT-6.d (`/pr` and `/review --pr` via `gh`) and C-AGT-6.e (PR chips). C-AGT-2.b needs no ruling, but it narrows `/review` to read-only; the owner may veto.
- **Design constraint the lanes must not miss.** Every turn's tools run in a `pcntl_fork()`ed child. So the Schedule tool (5.a) writes persisted rows only, and cross-session messages (4.e) go through a disk mailbox; in-memory registry or hub calls from a tool would vanish with the child.
- **Not verified (read-only pass).**
  - Whether the fork-child tool executor dispatches through `resolveGrantedTools()`'s instances (3.a says how to check).
  - Whether `McpClient` survives `clone` without a `__clone` (3.a).
  - The exact flag-mirror test for `bin/candy-code`'s `$strictValueFlags` (6.c).
  - Whether `ProtocolSchemaDriftTest` pins the SessionSummary field set (4.c, 6.e).
  - The `release-phar.yml` asset names (5.g).
  - GIT-2-P1's install failure was not executed (no composer run allowed).
- **New test files for the durations TSV (candy-code suite):**
  - Host: `tests/Host/SessionHostLoopFireTest.php`, `tests/Host/SessionHostModeCeilingTest.php`.
  - Server: `tests/Server/HookWebhooksBodyCapStackTest.php`, `tests/Server/HookWebhooksSignatureTest.php`.
  - Agents: `tests/Agents/McpDispatcherPresetScopeTest.php`, `tests/Agents/PrimaryAgentTest.php`, `tests/Agents/Live/SessionMailboxTest.php`.
  - Memory: `tests/Memory/AgentMemoryTest.php`.
  - Tools: `tests/Tools/TaskToolAgentMemoryTest.php`, `tests/Tools/SendMessageCrossSessionTest.php`, `tests/Tools/BuiltIn/ScheduleToolTest.php`.
  - Chat: `tests/Chat/PrimaryAgentSwitchTest.php`, `tests/Chat/HeartbeatScheduleTest.php`.
  - Background: `tests/Background/ScheduleFactoryTest.php`.
  - Automation: `tests/Automation/OutboundWebhookConfigTest.php`, `tests/Automation/WebhookSignerTest.php`, `tests/Automation/WebhookDispatcherTest.php`, `tests/Automation/OutboundWebhookWiringTest.php`.
  - Host commands: `tests/Host/Commands/CommitHostCommandTest.php`, `tests/Host/Commands/PrHostCommandTest.php`.
  - Cli: `tests/Cli/MainWorktreeTest.php`.
  - Workspace: `tests/Workspace/GhRunnerTest.php`, `tests/Workspace/PrStatusCacheTest.php`.
  - All paths are under `candy-code/`. The `tools/tests/*` and web `*.spec.ts` files run outside that suite.
