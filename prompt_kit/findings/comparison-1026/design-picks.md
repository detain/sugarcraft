# Design — the owner's four picks (P-CTX-BREAKDOWN, P-SESSION-TABS, P-AGENT-ATTACH, P-IMAGE-MENU)

Read-only design pass against `master` @ 5e9195687. Every MODIFY path below was checked to exist and every CREATE path was checked to be absent (2026-10-26). Line numbers are from that tree.

**What already exists, and so is not re-designed.**
- `/context` (ContextHostCommand → ContextSources::measure → ContextBreakdown) already prints per-layer, tools, history, pruning and cache figures. The web Context panel already lists the layers.
- The TUI already has an Agent View (P-C2/P-D2/P-D3): click, Enter or `/agents <run>` opens a run's live JSONL-tailed transcript, the composer sends to the run's signed mailbox, and Ctrl+G and Ctrl+X c/p/s/o/b work (`docs/AGENTS.md:208-262`).
- The TUI already has an in-chat session tab strip (`Renderer::renderSessionTabStrip`, `src/Renderer.php:3370`), shown only with ≥2 tabs. Switching tabs swaps the one Chat's history in place (`Chat::switchToSession`, `src/Chat.php:2355`) and is refused mid-turn (`src/Chat.php:7237-7252`).
- The web already has session tabs, an Agent View with a message box, and a Generate drawer tab.

The picks therefore need the missing halves:
- a categorised context box;
- truly concurrent tabs, with one hosted Chat per tab;
- agent windows that keep their own scroll and draft and sit in a clickable window list;
- a reachable Media menu with a content-sized popup.

**The shared session-window component (designed once, P-TABS.a).** `candy-code/src/Tui/Window/*` is a pure kit:
- `WindowRef`: kind Session|Agent, a stable key, a label, a colour and the parent session.
- `WindowAttention`: Idle, Working, Waiting, Unseen and Failed. Each has a distinct glyph and a rank.
- `WindowViewport`: scroll offset, draft and cursor.
- `WindowViewports`: the stash/restore map.
- `WindowList`: the ordered tree of sessions and their agent windows.
- `WindowStrip`: the horizontal renderer and its `win:*` zones.
- `AgentColor`: preset `color:` → theme hex.

P-TABS.c adds the vertical renderer (`SessionsPane`: a `+ New session` row on top, the sessions under it, and each session's running agents under that). Tabs and agent windows both draw through this kit, so the strip, the pane, the badges and the viewport law are one implementation.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| P-TABS.a | Window kit: shared session-window primitives | M | [lang] | none |
| P-TABS.b | TabScope envelope + SessionDeck (wires dormant SessionTabs detach/summary) | M | none | none |
| P-TABS.c | SessionsPane component (vertical `+` / sessions / agents list) | M | [lang] | P-TABS.a |
| P-TABS.d | Tab-Chat factory (`Bootstrap::tabChat`) | M | [bootstrap:chat] [hot:LaunchContext.php] [hot:App.php] | none |
| P-AGENT.a | Live-agent registry: session scope + preset colour (k1-dorm#3) | M | [bootstrap:workspace] [docs:AGENTS_AUTHORING.md] [docs:AGENTS.md] | P-TABS.a |
| P-CTX.a | Context attribution by category (core + `/context` text) | M | [lang] [docs:CONTEXT.md] | none |
| P-CTX.b | `/context --raw [path]` prompt dump (GAP-PRM-15) | S | [runtime] [commands] [lang] [docs:COMMANDS.md] [docs:PROMPT_ENGINEERING.md] | none |
| P-CTX.c | ContextPane component (collapsed meter → expanded breakdown) | M | [lang] | P-CTX.a |
| P-IMG.a | Media menu + menu-bar overflow | M | [commands] [lang] [docs:README.md] [docs:MEDIA.md] | none |
| P-IMG.b | Content-sized, centred `/generate` popup | M | [renderer:Tui/Renderer.php renderView+renderMediaForm] | none |
| P-TABS.e | App hosts many Chats: scoped routing, park/unpark, background streaming (GAP-SES-12) | M | [chat:withNoticeDrain] [hot:App.php] [docs:ARCHITECTURE.md] | P-TABS.b, P-TABS.d |
| P-CTX.d | Chat context-snapshot producer (TurnRunner-held) | S | [chat:measureContextCmd + route arm] [hot:HandlesTurnCompletion.php] [hot:TurnRunner.php] | P-CTX.a |
| P-TABS.f | Chat goes deck-aware: session-opening routes become tabs, mid-turn refusals lifted, palette Close tab | M | [chat:constructor+presentSession+picker/palette/agent-open/refuseWhileInFlight] [lang] [docs:COMMANDS.md] [hot:PaletteAction.php] | P-TABS.e |
| P-CTX.e | Shell wiring: docked Context pane + clickable status-bar meter | M | [renderer:Tui/Renderer.php renderPane; Renderer.php renderStatusBar] [chat:handlePointer] [lang] [docs:README.md] | P-CTX.c, P-CTX.d, P-IMG.b (serial: layout) |
| P-TABS.g | Shell window strip + Sessions pane, attention badges, OSC 2 title (GAP-UI-N6) | M | [renderer:Tui/Renderer.php renderView+renderPane; Renderer.php renderSessionTabStrip] [lang] [docs:README.md] | P-TABS.a, P-TABS.c, P-TABS.f, P-AGENT.a, P-CTX.e (serial: layout) |
| P-AGENT.b | Agent windows: own viewport, rows/chips in the window list, `Ctrl+X i` interrupt-send | M | [chat:submitToAgents + withDraft/draft] [lang] [docs:AGENTS.md] | P-AGENT.a, P-TABS.g |
| P-CTX.f | Protocol: `context.sources` categories | S | [protocol] [docs:SERVER.md] | P-CTX.a |
| P-AGENT.c | Protocol: `agents.list` colour + `agents.message` mode | S | [protocol] [docs:SERVER.md] | P-AGENT.a |
| P-CTX.g | Web: context meter + category breakdown + cache % | M | [hot:web StatusBar.vue] [hot:web stores/contextSources.ts] | P-CTX.f |
| P-AGENT.d | Web agents parity: colours, interrupt send, running-agents line (CLAW-new#7) | M | [hot:web SessionView.vue] | P-AGENT.c |
| P-TABS.h | Web: `+` new-session tab and unseen badges | S | none | none |
| P-IMG.c | Web: Generate entry in the top bar | S | [hot:web App.vue] | none |

---

### P-TABS.a — Window kit: shared session-window primitives
- item: P-SESSION-TABS + P-AGENT-ATTACH (sources: GAP-SES-12, GAP-UI-N6, k1-dorm#3)
- kind: feature · effort M
- approach:
  - New pure value and renderer classes under `src/Tui/Window/`, shared by both picks.
  - The model and its values:
    - `WindowRef{kind, key, label, ?color, ?parentKey}`;
    - `WindowAttention` enum (Idle/Working/Waiting/Unseen/Failed), with a distinct glyph per case (`●` `⠋` `⏳` `✱` `✗`), so NO_COLOR stays legible, a theme token and a rank for the title count;
    - `WindowViewport{scrollOffset, draft, cursor}`, and `WindowViewports` as an immutable `stash(key, vp)`/`restore(key)` map;
    - `WindowList`: an ordered tree of session entries, each with child agent entries, with focus, a badge count and a `summary` line.
  - `WindowStrip::render(WindowList, Theme, cols, bool marked)` paints one row:
    - `[+]` first, then a tab per session (glyph · name · badge · `×`), with the focused session's open agent chip `▸ @explore` right after its tab;
    - three densities (full / icon / terse) plus a `‹N›` overflow, and it is never wider than `cols`;
    - `WindowStrip::zones()` returns `win:new`, `win:s:<key>`, `winx:s:<key>`, `win:a:<runId>` and `winx:a:<runId>` spans from the same arithmetic, the MenuBar marked-twin law.
  - `AgentColor::resolve(?string, Theme): ?string` maps the preset `color:` vocabulary (red/blue/green/yellow/purple/orange/pink/cyan → theme tokens; `#rrggbb` passes after a contrast check against `$theme`), and returns null for anything else.
  - Nothing is wired yet.
- files.create: `candy-code/src/Tui/Window/WindowKind.php`, `candy-code/src/Tui/Window/WindowRef.php`, `candy-code/src/Tui/Window/WindowAttention.php`, `candy-code/src/Tui/Window/WindowViewport.php`, `candy-code/src/Tui/Window/WindowViewports.php`, `candy-code/src/Tui/Window/WindowList.php`, `candy-code/src/Tui/Window/WindowEntry.php`, `candy-code/src/Tui/Window/WindowStrip.php`, `candy-code/src/Tui/Window/AgentColor.php`
- files.modify: `candy-code/lang/en.php` (`tui.window.*` keys)
- tests: create these, each a new file for the weld:
  - `candy-code/tests/Tui/Window/WindowStripTest.php`: a 20–240-col sweep; never over-wide; `+` always present; zones land on painted cells at every density; badges keep distinct glyphs under NO_COLOR.
  - `candy-code/tests/Tui/Window/WindowViewportsTest.php`: stash/restore round-trip; an unknown key yields the bottom/empty viewport.
  - `candy-code/tests/Tui/Window/WindowAttentionTest.php`: rank order and glyph uniqueness.
  - `candy-code/tests/Tui/Window/AgentColorTest.php`: names, hex, garbage → null, low-contrast hex refused.
- docs/guards: none (one type per file: 9 files, 9 types)
- serialized: [lang] (prefix `tui.window.`)
- deps: none
- phase: normal
- ruling: none
- risks: the kit is speculative until P-TABS.g and P-AGENT.b consume it. Keep the API minimal and let the consumer steps widen it.

### P-TABS.b — TabScope envelope + SessionDeck (wires dormant SessionTabs detach/summary)
- item: P-SESSION-TABS (sources: GAP-SES-12, GAP-UI-N6)
- kind: feature · effort M
- approach:
  - **The problem.** Today one `App::$chat` receives every Msg (`App::delegateToChat`, `src/App/App.php:4298`). For a parked tab to keep streaming, its Cmds must come back addressed to it.
  - **`TabScope::cmd(string $tabKey, ?Closure)`** wraps lazily and maps each Cmd result:
    - BatchMsg/SequenceMsg → each inner Cmd wrapped;
    - TickRequest → produce wrapped;
    - AsyncCmd → `then(scope, reject → scoped ExceptionMsg)`;
    - a plain Msg → `TabScopedMsg{tabKey, inner}`;
    - Program sentinels pass through unwrapped (RawMsg, PrintMsg, ExecRequest, Quit/Interrupt/Suspend, SubscriptionsMsg, ScreenStack*; see `sugar-core/src/Program.php:723-866`).
  - **`TabScope::subscriptions(tabKey, ?Subscriptions, list<string> $foregroundOnly)`** re-keys the ids `tab:<key>:<id>` and wraps the producers. It drops foreground-only ids such as the background-supervisor poll, so two Chats never double-announce.
  - **`SessionDeck`** wraps the existing `SessionTabs` model, using the deck's own stable tab keys rather than session ids, because a lazily minted or `/branch`ed session changes id in place. It adds:
    - `parked: array<key, Model>` and `sessionOf(key)`;
    - attention per tab;
    - `park()`/`unpark()`/`open()`/`close()`/`cycle()`;
    - `rekey(key, newSessionId)`.
  - It finally calls the dormant `SessionTabs::detachTab`/`reattachTab`/`updateTabSummary` (`src/Tui/SessionTabs.php:195-235`, no production caller), and `SessionTab` gains `withAttention()`.
  - `OpenTabMsg`, `CloseTabMsg` and `CycleTabMsg` are created here for P-TABS.e and P-TABS.f.
- files.create: `candy-code/src/App/Tabs/TabScope.php`, `candy-code/src/App/Tabs/TabScopedMsg.php`, `candy-code/src/App/Tabs/SessionDeck.php`, `candy-code/src/App/Tabs/OpenTabMsg.php`, `candy-code/src/App/Tabs/CloseTabMsg.php`, `candy-code/src/App/Tabs/CycleTabMsg.php`
- files.modify: `candy-code/src/Tui/SessionTab.php` (`attention` field + `withAttention()`), `candy-code/src/Tui/SessionTabs.php` (`updateTabAttention()`)
- tests:
  - create `candy-code/tests/App/Tabs/TabScopeTest.php`: nested batch/sequence/tick/promise wrapping; rejection → scoped ExceptionMsg; sentinels unwrapped; subscription re-keying and foreground-only filtering;
  - create `candy-code/tests/App/Tabs/SessionDeckTest.php`: park/unpark/rekey/close-last-refused; detach/reattach/summary wired;
  - modify `candy-code/tests/Tui/SessionTabsTest.php` and `candy-code/tests/Host/SessionTabsExtractionParityTest.php` (attention field).
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: if a Program sentinel is missed and wrapped, it would be silently swallowed. The test must enumerate every sentinel `Program::runCmd` inspects.

### P-TABS.c — SessionsPane component (vertical `+` / sessions / agents list)
- item: P-SESSION-TABS + P-AGENT-ATTACH (sources: GAP-UI-N6; owner wording "a tab up top to open a new session … tabs for the running sessions under it"; OC2 vertical tabs, COMPARISON SES-12)
- kind: feature · effort M
- approach:
  - `SessionsPane::renderModel(WindowList, Theme, int width, int rows)` draws the dockable pane form of the window list:
    - row 0 is `＋ New session`;
    - then one row per session tab: attention glyph, name, badge and summary (`⠋ step 4 · 2 agents`);
    - each session's agent windows are indented under it (`└ ⠋ @explore`, in the preset colour);
    - the focused window is highlighted;
    - a list longer than the pane keeps the focused row in view (the TodoPane anchor rule).
  - `layout()` returns `[block, rowKeys]` in the `Tui\Renderer::keyed()` shape (`src/Tui/Renderer.php:1930-1938`): keys `new`, `s:<key>`, `a:<runId>`.
  - It reads only the model and does no I/O.
- files.create: `candy-code/src/Tui/Components/SessionsPane.php`
- files.modify: `candy-code/lang/en.php` (`tui.sessions.*`)
- tests: create `candy-code/tests/Tui/Components/SessionsPaneTest.php` (row keys align with painted rows; never over-wide; the agent rows nest; a long list keeps focus in view; the "+" row is always row 0)
- docs/guards: none
- serialized: [lang] (prefix `tui.sessions.`)
- deps: P-TABS.a
- phase: normal
- ruling: none
- risks: low. The widths follow PaneFrame chrome (`src/Tui/Components/PaneFrame.php`).

### P-TABS.d — Tab-Chat factory (`Bootstrap::tabChat`)
- item: P-SESSION-TABS (sources: GAP-SES-12)
- kind: feature · effort M
- approach:
  - `Bootstrap::chat()` (`src/Cli/Bootstrap.php:1183`) is the only Chat constructor. It also seeds launch notices, opens/locks the launch session and arms the notice drain (`drainsRuntimeNotices: true`, :1415).
  - Extract its body into `private static buildChat(?string $root, ?string $sessionId, bool $launch)`. Then add `public static tabChat(?string $root, ?string $sessionId): Chat`, which:
    - shares the memoized `workspace($root)`;
    - opens the given session (load its transcript, take its lock; a lock held by another process gives the existing read-only window) or a lazily minted new one;
    - seeds no launch notices;
    - sets `drainsRuntimeNotices: false`;
    - gets a fresh `liveToolEvents` ArrayObject, so it has its own pump lineage.
  - `LaunchContext::app()` (`src/Cli/LaunchContext.php:2184`) hands the App a factory closure: `App::withTabFactory(?Closure)` (field only; the routing lands in P-TABS.e).
- files.create: none
- files.modify: `candy-code/src/Cli/Bootstrap.php` (`chat()` → `buildChat()` + `tabChat()`), `candy-code/src/Cli/LaunchContext.php` (`app()`), `candy-code/src/App/App.php` (constructor field `tabFactory`, `withTabFactory()`, mutate carry)
- tests: create `candy-code/tests/Cli/TabChatFactoryTest.php` (the same workspace instance; a distinct liveToolEvents identity; no launch notices; drain off; an existing id loads its transcript; the second tab for a locked session goes read-only)
- docs/guards: none
- serialized: [bootstrap:chat] [hot:candy-code/src/Cli/LaunchContext.php] [hot:candy-code/src/App/App.php]
- deps: none
- phase: normal
- ruling: none
- risks: `chat()` sets process statics (StatusLineCommand, media render mode, static spinner). `tabChat()` must not re-run anything that clears launch state. Pin it in the test.

### P-AGENT.a — Live-agent registry: session scope + preset colour (k1-dorm#3)
- item: P-AGENT-ATTACH + P-SESSION-TABS (sources: k1-dorm#3, GAP-SES-12)
- kind: dormant-wire · effort M
- approach:
  - **Session scope.** `AgentLiveRegistry` is ONE workspace service (`src/Cli/Bootstrap.php:1893`), and `apply()` (`src/Agents/Live/AgentLiveRegistry.php:125`) keeps no session. With several hosted Chats, tab A's strip would list tab B's runs.
    - `SubAgentActivity` already carries `$parentSessionId` (P-C1). `AgentLiveState::fromActivity` keeps it.
    - Add `AgentLiveRegistry::forSession(?string)` (null = all, for the serve and legacy readers), plus `AgentStrip::itemsFor($live, $dismissed, ?string $sessionId)` beside the unchanged `items()`. The renderer call-site switch lands in P-TABS.g.
  - **Colour.** The preset `color:` key is parsed and read by nothing (`src/Support/FrontmatterKeyAudit.php:120-123`, `INERT[AGENT]['color']`).
    - Seed the registry with a name→colour resolver at `workspace()`: `AgentLiveRegistry::new()->withColorResolver(fn ($n) => $agentManager->get($n)?->color)`. `AgentLiveState::$color` is set at fold.
    - `AgentStrip`, `AgentViewHeader`, `AgentActivityLine` and `AgentDashboardPane` paint the run's name and dot through `AgentColor` (P-TABS.a; if that has not landed, a private copy of the resolve table is refused, so the dep is real).
    - Drop `color` from INERT, keeping `memory`.
- files.create: none
- files.modify: `candy-code/src/Agents/Live/AgentLiveState.php`, `candy-code/src/Agents/Live/AgentLiveRegistry.php`, `candy-code/src/Tui/AgentStrip.php`, `candy-code/src/Tui/AgentViewHeader.php`, `candy-code/src/Tui/AgentActivityLine.php`, `candy-code/src/Tui/Components/AgentDashboardPane.php`, `candy-code/src/Support/FrontmatterKeyAudit.php` (INERT), `candy-code/src/Cli/Bootstrap.php` (`workspace()`, :1893), `candy-code/docs/AGENTS_AUTHORING.md` (field table `color` row), `candy-code/docs/AGENTS.md` (strip/header colour sentence)
- tests:
  - create `candy-code/tests/Agents/Live/AgentLiveSessionScopeTest.php` (two sessions' runs; `forSession` filters; null = all);
  - create `candy-code/tests/Tui/AgentColorPaintTest.php` (the strip, header and dashboard use the preset colour; an invalid colour falls back to the status token);
  - modify `candy-code/tests/Agents/Live/AgentLiveRegistryTest.php`, `candy-code/tests/Tui/AgentStripTest.php`, `candy-code/tests/Support/FrontmatterKeyAuditTest.php`.
- docs/guards: `docs/AGENTS_AUTHORING.md` `| Field | Effect today |` table, moved in-step with `tests/Config/InertFrontmatterDocumentationDriftTest.php`; `docs/AGENTS.md`
- serialized: [bootstrap:workspace] [docs:AGENTS_AUTHORING.md] [docs:AGENTS.md]
- deps: P-TABS.a (AgentColor)
- phase: normal
- ruling: none
- risks: the session filter must stay off for serve (SessionHost readers pass null) or the web agent tree empties. The test covers the null path.

### P-CTX.a — Context attribution by category (core + `/context` text)
- item: P-CTX-BREAKDOWN (sources: owner pick; PRM-12; CC `/context` grid, `comp-claude-code.md` PRM-12 row)
- kind: feature · effort M
- approach:
  - **What exists.** `ContextBreakdown` (`src/Context/ContextBreakdown.php:94-105`) knows the per-label prompt layers (`Runtime::promptSectionSizes()`, `src/Runtime.php:4389`), a single tools figure, history, pruning and cache.
  - **New `ContextCategory` enum and `ContextCategories::from(ContextBreakdown, ?iterable $tools, ?CompactorConfig)`.** They attribute every token to CC-style categories:
    - System prompt: `base`, `maxims`, `tool-guidance` minus the agents roster, `system-reminder`, `env`, and any unknown label.
    - System tools: built-in schemas.
    - MCP tools: `mcp__<server>__*` bridges plus `mcp_list`/`mcp_call`, with per-server sub-rows.
    - Custom agents: the `<available-agents>` suffix of the Task tool's `promptGuidance()`, cut at `TaskTool::AGENTS_OPEN` (`src/Tools/BuiltIn/TaskTool.php:283`).
    - Memory files: `user-rules` + `project-instructions`, with per-file sub-rows from `instructionFiles`.
    - Project memory: `project-memory`.
    - Repository map: `repo-map` + the symbol map.
    - Skills: `skills` + `available-skills`.
    - Messages: history − pruned, with the largest-5 sub-rows.
    - Autocompact buffer: `window − backgroundCompactionTokenThreshold(window)` when `autoCompacts()` (`src/Context/CompactorConfig.php:511,761`).
    - Free space.
  - MCP server instructions are listed as "not sent". candy-code injects none, so the row is honest rather than a fake 0.
  - `bar(int cells)` apportions cells by largest remainder, so the bar sums exactly to the width.
  - `ContextBreakdown::measure` splits MCP from built-in tools (it gains `mcpToolCount`/`mcpToolTokens`/`mcpServers`/`agentsTokens`; the named args default null). `ContextSources::measure` passes `EngineBackend::compactorConfig()` (:1226) and the same tool list `promptSectionSizes()` prices (`EngineBackend.php:2810`).
  - `/context` opens with a "By category" block (bar + rows). The serialization is untouched here (that is the protocol step).
- files.create: `candy-code/src/Context/ContextCategory.php`, `candy-code/src/Context/ContextCategories.php`
- files.modify: `candy-code/src/Context/ContextBreakdown.php` (`measure()`, new fields), `candy-code/src/Host/ContextSources.php` (`measure()` only), `candy-code/src/Commands/ContextCommand.php` (`compose()`), `candy-code/src/Backend/EngineBackend.php` (public `promptTools()` accessor if `tools()` differs from `turnTools()`), `candy-code/lang/en.php` (`cmd.context.category.*`), `candy-code/docs/CONTEXT.md` (the `/context` section)
- tests:
  - create `candy-code/tests/Context/ContextCategoriesTest.php`:
    - every label `promptSectionSizes()` can emit maps to its category (fixture from a real Runtime build), and an unknown label → System;
    - the categories sum to `totalTokens()`;
    - the bar sums to the cells;
    - the agents roster is moved out of tool-guidance;
    - the buffer is 0 when compaction is off;
  - modify `candy-code/tests/Commands/ContextCommandTest.php`, `candy-code/tests/Chat/ContextCommandsTest.php`.
- docs/guards: `docs/CONTEXT.md` (prose; no roster guard)
- serialized: [lang] (prefix `cmd.context.category.`) [docs:CONTEXT.md]
- deps: none
- phase: normal
- ruling: none
- risks: the token figures stay script-weighted estimates. The labels must print `~`, and the free space never goes negative when the estimate over-runs.

### P-CTX.b — `/context --raw [path]` prompt dump (GAP-PRM-15)
- item: P-CTX-BREAKDOWN (sources: GAP-PRM-15)
- kind: gap · effort S
- approach:
  - There is no raw system-prompt dump today. `Runtime::buildSystemPrompt()` is private (`src/Runtime.php:4362`).
  - Add a public one-line `Runtime::assembledSystemPrompt(App)`, and a new opt-in `Backend\DumpsSystemPrompt` capability (not a `Backend` method; third-party backends keep loading, as `ReportsPromptSections` does). `EngineBackend` implements it, returning `{prompt, tools[name, description, input_schema]}` from the same `sessionApp()->withTools(turnTools())` build.
  - `ContextHostCommand` parses `--raw [path]` and writes JSON plus a `.txt` prompt, 0600 atomic, under the `/export` jail and naming rules (`src/Commands/ExportCommand.php:49-58,191`).
    - It replies with the path and sizes.
    - A non-dumping backend gets a named refusal.
  - The TUI gets it free (`Chat::handleContextCommand` runs the host command, `src/Chat.php:11983`).
  - The spec gains an `argumentHint`.
- files.create: `candy-code/src/Backend/DumpsSystemPrompt.php`
- files.modify: `candy-code/src/Runtime.php` (accessor beside `buildSystemPrompt()`), `candy-code/src/Backend/EngineBackend.php` (implements), `candy-code/src/Host/Commands/ContextHostCommand.php` (`run()`), `candy-code/builtin-commands/1350-context.php` (`argumentHint`), `candy-code/lang/en.php` (`cmd.context.hint`, `cmd.context.raw.*`), `candy-code/docs/COMMANDS.md` (regenerated), `candy-code/docs/PROMPT_ENGINEERING.md` (how to dump)
- tests: create `candy-code/tests/Host/Commands/ContextRawDumpTest.php` (mode 0600; `../` refused by the jail; the dumped prompt's byte length equals the `promptSectionSizes()` byte sum; a non-engine backend is refused)
- docs/guards: `docs/COMMANDS.md` via `tools/gen-command-docs.php --write` with `tests/Commands/CommandDocsDriftTest.php`; `docs/PROMPT_ENGINEERING.md`
- serialized: [runtime] [commands] (existing row `1350-context.php`) [lang] (prefix `cmd.context.raw.`) [docs:COMMANDS.md] [docs:PROMPT_ENGINEERING.md]
- deps: none
- phase: normal
- ruling: none
- risks: the dump holds instruction-file bodies. Keep it 0600, jailed, and never print the body into the transcript.

### P-CTX.c — ContextPane component (collapsed meter → expanded breakdown)
- item: P-CTX-BREAKDOWN (sources: owner pick)
- kind: feature · effort M
- approach:
  - `ContextPane::renderModel(ContextPaneModel, Theme, width, rows)` and `layout()` (row keys), the pure "sidebar box". `ContextPaneModel` holds `?ContextCategories`, the live history tokens (status-bar figure), the window, `expanded`, `?drill`, `stale` and `cachePercent`.
  - **Collapsed** (4 rows with border): a stacked category bar, then `81K / 200K · 41% ▸`.
  - **Expanded:**
    - the bar;
    - one row per category: a glyph per category (█▓▒░▚… for NO_COLOR) in its own theme colour, the label, and `~tokens` right-aligned;
    - `▸` on drillable rows;
    - a drilled category shows its sub-rows in place (layers / files / MCP servers / largest messages);
    - a footer: `cache 94% · −4K pruned`, plus a `dump prompt` row (key `raw`).
  - While a turn runs, the Messages row and the total follow the live history tokens and the other rows keep the snapshot. Unmeasured parts read "not measured", never 0.
- files.create: `candy-code/src/Tui/Components/ContextPane.php`, `candy-code/src/Tui/Components/ContextPaneModel.php`
- files.modify: `candy-code/lang/en.php` (`tui.context.*`)
- tests: create `candy-code/tests/Tui/Components/ContextPaneTest.php` (collapsed exactly 4 rows; never over-wide over a 20–80 sweep; distinct glyphs under NO_COLOR; drill rows; a null snapshot → "measuring…"; live tokens override Messages)
- docs/guards: none
- serialized: [lang] (prefix `tui.context.`)
- deps: P-CTX.a
- phase: normal
- ruling: none
- risks: theme tokens for 11 categories must stay distinct in all five palettes. Reuse the ShellContrastTest helper.

### P-IMG.a — Media menu + menu-bar overflow
- item: P-IMAGE-MENU (sources: owner pick)
- kind: feature · effort M
- approach:
  - **Root cause.** "Generate image" IS a menu row, but in the Tools menu. Menus are ordered by first appearance in `CommandRegistry::all()` (`src/Tui/Components/MenuBar.php:69-77`), so Tools is the 11th and last. `compose()` drops menus from the right when the bar overflows (:170-200). At 120 cols only Session/Model/App fit, and Tools appears only at about 190 cols.
  - **Fix.**
    - An explicit `CommandRegistry::MENU_ORDER`, with a new **Media** category second (Session, Media, Model, App, …).
    - A `»` overflow menu that lists every dropped menu's rows under section headers, so every command stays reachable at any width.
    - Move `generate-form` (label `Generate image…`), `/play`, `/sendto` and `/rendermode` to Media.
    - A new `CommandSpec::$menuVisible` (default true) hides the bare `/generate` row (the form row covers it; the slash roster is unchanged).
- files.create: none
- files.modify: `candy-code/src/Tui/Components/MenuBar.php` (`menus()`, `compose()`, overflow dropdown, `activeMenuColumn()`), `candy-code/src/Commands/CommandRegistry.php` (`MENU_ORDER`, `menuEntries()`), `candy-code/src/Commands/CommandSpec.php` (`menuVisible`), `candy-code/builtin-commands/4000-generate.php`, `candy-code/builtin-commands/4010-generate-form.php`, `candy-code/builtin-commands/4020-sendto.php`, `candy-code/builtin-commands/4030-play.php`, `candy-code/builtin-commands/4040-rendermode.php`, `candy-code/lang/en.php` (`cmd.category.media`, `tui.menu.more`, `cmd.generate.form.label`), `candy-code/README.md` (L308 the form's doors: `F10 ▸ Media ▸ Generate image…`), `candy-code/docs/MEDIA.md` (how to open the form)
- tests:
  - create `candy-code/tests/Tui/MenuOverflowTest.php` (at 80/100/120/160/240 cols every `CommandRegistry::all()` menu row is reachable through a visible menu or `»`; Media is visible at ≥100 cols; an overflow click dispatches the same spec);
  - modify `candy-code/tests/Tui/MenuBarTest.php`, `candy-code/tests/Tui/MenuDropdownTest.php`, `candy-code/tests/Tui/MenuBarCompactStripTest.php`, `candy-code/tests/Tui/MenuBarMouseTest.php`.
- docs/guards: `README.md` prose; `docs/MEDIA.md`; the `docs/COMMANDS.md` table does not render the category, so it does not move (checked: `src/Commands/Specs/CommandDocGenerator.php:107-118`); `tests/Commands/RegistryTranslationTest.php` (the new category key)
- serialized: [commands] (`CommandRegistry.php`, `CommandSpec.php`, existing rows 4000-4040) [lang] (prefix `cmd.category.media`, `tui.menu.`) [docs:README.md] [docs:MEDIA.md]
- deps: none
- phase: normal
- ruling: none
- risks: palette grouping also reads the category, so Media rows regroup in Ctrl+P too (intended; pin it in the test).

### P-IMG.b — Content-sized, centred `/generate` popup
- item: P-IMAGE-MENU (sources: owner pick)
- kind: feature · effort M
- approach:
  - **Root cause.** `Tui\Renderer::renderView` diverts to `renderMediaForm()` before the band is laid out (`src/Tui/Renderer.php:646-650`). It hands `MediaForm::view()` the full `$cols × $paneRows` (:940-960), and `view()` draws its border at `cols-4` (`src/Tui/MediaForm/MediaForm.php:716-757`), so the form always fills the screen.
  - **Fix.**
    - Add `MediaForm::naturalSize(Theme, maxCols, maxRows)`: the widest of title, natural tab strip (`FormTabs`), longest label+value of the visible sections, footer hints and status, plus chrome. It is clamped to `[MIN_COLS 60, min(cols, MAX_COLS 104)]`, and full width only when the content needs more. Rows are `6 + max visible section rows`, bounded by the band.
    - `renderMediaForm()` composes the normal shell frame as a backdrop:
      - extract the tail of `renderView` into `composeBand()`;
      - drop images, because a graphics placement would paint through the popup;
      - `LiveRenderer::clearZones()`, so the chat underneath is inert;
      - dim the backdrop with `Veil::withBackdrop(50)`, as the chat palette does;
      - centre the form box in the band below a live menu bar;
      - offset `MediaForm::zones()` by the box origin.
  - `view()` keeps its exact-size contract, so the goldens stay valid.
- files.create: none
- files.modify: `candy-code/src/Tui/MediaForm/MediaForm.php` (`naturalSize()`), `candy-code/src/Tui/MediaForm/FormTabs.php` (natural-width helper), `candy-code/src/Tui/Renderer.php` (`renderView()` band extraction, `renderMediaForm()`)
- tests:
  - create `candy-code/tests/Tui/MediaForm/MediaFormPopupSizeTest.php` (200×50: the box is narrower than the terminal and centred; content wider than cols → full width; zones land on painted cells; the View carries no images while open; no frame line over-wide; the menu bar stays clickable);
  - modify `candy-code/tests/App/MediaFormWiringTest.php`.
- docs/guards: none (`tests/Tui/MediaForm/golden/*` unaffected: `view()` contract unchanged)
- serialized: [renderer:Tui/Renderer.php renderView+renderMediaForm] (layout #1 of 3)
- deps: none
- phase: normal
- ruling: none
- risks: the band extraction is the riskiest refactor here. It must leave the normal frame byte-identical (assert with `tests/Tui/RendererTest.php` unchanged).

### P-TABS.e — App hosts many Chats: scoped routing, park/unpark, background streaming (GAP-SES-12)
- item: P-SESSION-TABS (sources: GAP-SES-12)
- kind: gap · effort M
- approach:
  - `App` gains a `SessionDeck`. Every Cmd a Chat returns, from `delegateToChat` (:4298), `runRegistryCommand` and `feedChat`, is wrapped by `TabScope::cmd($fgKey)`. App-level Cmds stay unscoped, so shell Msgs (SettingsSaved…) can never be misrouted.
  - A new `TabScopedMsg` arm in `update()` (:2714) does the routing:
    - **foreground:** unwrap and re-enter `update()`;
    - **parked:** `$parked->update($inner)`, re-wrap its Cmd, and refresh that tab's attention (inFlight → Working; pendingPermission → Waiting; reply settled while parked → Unseen; error → Failed).
  - Shell-claimed inner Msgs from a parked tab are dropped (OpenMediaForm/OpenSettings/OpenAgentView), except AgentMessageSentMsg, which is keyed by run. A background tab can never pop a modal; it raises attention instead.
  - `subscriptions()` (:4664) merges each parked Chat's `TabScope::subscriptions()`. The foreground-only ids are the background poll, statusLine and idle-compact.
  - **Switch:** park the foreground Chat (its turn keeps running), unpark or factory-build the target, replay `WindowSizeMsg($cols,$rows)` (`handleWindowSize`, :4265), and move the notice drain to the new foreground via a new `Chat::withNoticeDrain(bool)` (the field exists, `src/Chat.php:1440`).
  - The App intercepts Ctrl+Tab/Ctrl+Shift+Tab and Ctrl+N (shell.new-session) when the deck is armed. After each delegate it rekeys the deck when `currentSessionId()` moved in place (`/branch`, mint).
  - **Close:** closing a busy tab needs a second press within 3 s (`cancel and close`); then it cancels, flushes and releases the lock.
  - **Quit** with busy background tabs asks once.
  - No new fork site: each tab's turn uses the existing TurnRunner/EngineBackend fork path. TurnRunner is already keyed per token and session (`src/Host/TurnRunner.php:643-734`).
- files.create: none
- files.modify: `candy-code/src/App/App.php` (deck field, `update()` arm, `delegateToChat()`, `runRegistryCommand()`, `subscriptions()`, `handleKey()` Ctrl+Tab/Ctrl+N, new `switchTab()`/`openTab()`/`closeTab()`), `candy-code/src/Chat.php` (new wither `withNoticeDrain()` beside `drainsRuntimeNotices`), `candy-code/src/Commands/KeyBindingRegistry.php` (`chat.session-cycle` and `shell.new-session` descriptions), `candy-code/docs/ARCHITECTURE.md` (§ App/Chat hosting, L262), `candy-code/README.md` (L275 key table, L1023 tab section)
- tests:
  - create `candy-code/tests/App/BackgroundTabStreamingTest.php`:
    - a turn starts in tab A on a deferred backend promise;
    - Ctrl+Tab to B is not refused;
    - A's promise resolves, and A's history gets the reply while B is untouched;
    - A is marked Unseen;
    - A's pump subscription stays declared;
  - create `candy-code/tests/App/TabScopedRoutingTest.php` (foreground unwrap; parked routing; shell Msgs from a parked tab dropped; App Cmds never scoped);
  - create `candy-code/tests/App/TabCloseAndQuitTest.php`;
  - modify `candy-code/tests/Commands/KeyBindingDriftTest.php` (deck-mode observations for Ctrl+Tab and Ctrl+N) and `candy-code/tests/App/AppModelTest.php`.
- docs/guards: `README.md` keys and tabs prose; `docs/ARCHITECTURE.md`; KeyBindingDriftTest moved in-step
- serialized: [chat:withNoticeDrain (new wither)] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [docs:README.md] [docs:ARCHITECTURE.md]
- deps: P-TABS.b, P-TABS.d, C-TOOL-3.c (session-owned background shells, so tabs never share one registry)
- phase: normal
- ruling: none
- risks:
  - Process statics on Chat (`clickTracker`, `textSelection`, `pressGesture`, `src/Chat.php:6137-6249`) are foreground-only. Reset them on switch.
  - Each busy tab is one forked child, so many concurrent tabs multiply provider spend. Show the count in the title (P-TABS.g).

### P-CTX.d — Chat context-snapshot producer (TurnRunner-held)
- item: P-CTX-BREAKDOWN (sources: owner pick)
- kind: feature · effort S
- approach:
  - The pane must never measure in `view()`: `promptSectionSizes()` builds a Runtime, and the first call can touch disk.
  - Follow TodoPane's "read, never fetched" law (`src/Tui/Components/TodoPane.php:103-115`): `TurnRunner` gains `heldContext(?sid)`/`saveContext(?sid, ContextCategories)`, memory only and per session (like `heldTodos`, :725).
  - `Chat::measureContextCmd()` is a public Cmd. It runs `ContextSources::measure($this->commandContext(), priceInstructionFiles(root), contextTokens())` → `ContextCategories::from()` → `saveContext`, and answers a new `ContextMeasuredMsg`, which Chat routes as a no-op repaint.
  - It is scheduled on turn settle (HandlesTurnCompletion), after compaction lands, after `/clear` and after a model switch. App calls it when the pane docks (P-CTX.e).
  - No Chat constructor field.
- files.create: `candy-code/src/App/ContextMeasuredMsg.php`
- files.modify: `candy-code/src/Host/TurnRunner.php` (`heldContext()`, `saveContext()`), `candy-code/src/Chat.php` (new `measureContextCmd()`; route arm for `ContextMeasuredMsg` in the session-surface family), `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php` (settle schedule), `candy-code/src/Chat/Handlers/HandlesSessionSurface.php` (the arm)
- tests: create `candy-code/tests/Host/TurnRunnerContextTest.php` (per-session hold; overwrite); create `candy-code/tests/Chat/ContextSnapshotScheduleTest.php` (a settle emits the Cmd; running it fills `heldContext`; the RouteTraitParityTest arm is unique)
- docs/guards: `tests/Chat/RouteTraitParityTest` (or its real name: the "no class claimed twice" pin) covers the new arm
- serialized: [chat:measureContextCmd (new method) + session-surface route arm] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [hot:candy-code/src/Host/TurnRunner.php]
- deps: P-CTX.a
- phase: normal
- ruling: none
- risks: measuring on every settle costs one prompt assembly from the session memo (cheap, per `Runtime::promptSectionSizes` docblock). Debounce if two settles land in one tick.

### P-TABS.f — Chat goes deck-aware: session-opening routes become tabs, mid-turn refusals lifted, palette Close tab
- item: P-SESSION-TABS (sources: GAP-SES-12)
- kind: gap · effort M
- approach:
  - Inside Chat, four routes still swap the session in place through `switchToSession()` (`src/Chat.php:2355`):
    - session-picker Enter (:15166) and fork (:15080);
    - Agent View "open as session" (:9263);
    - palette New session (`handlePaletteNewSession`, :17681).
  - Several are refused mid-turn by `refuseWhileInFlight` (:10596).
  - Add a `tabHosted` flag (constructor + mutate carry; set by `App::withChat` when the deck is armed) and `presentSession(id, name)`:
    - deck-hosted, it returns `Cmd::send(new OpenTabMsg(id, name))` and touches nothing else;
    - standalone, it is the old in-place switch.
  - Route all four through it. In deck mode those routes are allowed mid-turn, because they no longer touch the running history.
  - Add a palette-only `Close tab` (new file `0105-close-tab.php`, `PaletteAction::CloseTab`) that sends `CloseTabMsg`.
  - The in-chat `tab:` click arm stays for standalone Chats.
- files.create: `candy-code/builtin-commands/0105-close-tab.php` (new per-command file, not serialized)
- files.modify: `candy-code/src/Chat.php` (constructor `tabHosted`; new `presentSession()`; `handleSessionPickerKey()` :15080/:15166; Agent View open-as-session :9263; `handlePaletteNewSession()` :17681; `refuseWhileInFlight()` :10596; palette dispatch arm for CloseTab), `candy-code/src/Palette/PaletteAction.php` (`CloseTab`), `candy-code/src/App/App.php` (`withChat()` sets the flag), `candy-code/lang/en.php` (`chat.tabs.*`, `cmd.close-tab.*`), `candy-code/docs/COMMANDS.md` (regenerated: the table lists palette-only rows)
- tests: create `candy-code/tests/Chat/DeckHostedSessionRoutesTest.php` (each route emits OpenTabMsg when hosted and switches in place when standalone; allowed mid-turn only when hosted); modify `candy-code/tests/MouseModalGuardTest.php` (standalone pins unchanged; add the hosted cases)
- docs/guards: `docs/COMMANDS.md` via `tools/gen-command-docs.php` with `tests/Commands/CommandDocsDriftTest.php`; the README slash roster does not move (`slashVisible: false`)
- serialized: [chat:constructor(tabHosted) + presentSession + handleSessionPickerKey + Agent-View open-as-session + handlePaletteNewSession + refuseWhileInFlight + palette arm,ctor] [lang] (prefix `chat.tabs.`, `cmd.close-tab.`) [docs:COMMANDS.md] [hot:candy-code/src/Palette/PaletteAction.php] [hot:candy-code/src/App/App.php]
- deps: P-TABS.e
- phase: normal
- ruling: none
- risks: a palette action needs its PaletteAction→spec roster row. Check `PaletteAction::spec()`'s exhaustiveness test.

### P-CTX.e — Shell wiring: docked Context pane + clickable status-bar meter
- item: P-CTX-BREAKDOWN (sources: owner pick)
- kind: feature · effort M
- approach:
  - **The pane.** Add `Pane::Context` (dockable, icon `◔`, `tabCycle()`), a `MenuBar::PANE_TABS` entry, and a `Tui\Renderer::renderPane()` arm (`src/Tui/Renderer.php:1944`) through `keyed()`. `ContextPane::render(App …)` builds the model from `TurnRunner::heldContext(currentSessionId)` and `chat->contextTokens()`.
  - **App state and clicks.** App gains `contextPaneExpanded`, `contextDrill` and a side-row arm in `dispatchChromeClick()` (:3422):
    - the header or meter row toggles expand;
    - a category row drills or undrills;
    - `raw` runs `/context --raw`.
  - **Keys.** Pane-focused keys: Enter/Space expand or drill, Esc/← undrill.
  - **Status-bar meter.** The chat status bar's context segment (`Renderer::renderStatusBar`) becomes a `ctxmeter` zone. `Chat::handlePointer`'s chain (:6460+) answers it with a DockPaneMsg for Context. Docking calls `measureContextCmd()`.
  - **Auto-show.** It auto-docks right once on wide terminals (≥120 cols) after the first reply, through `PaneAutoShow`. A user close is honoured and persisted.
- files.create: none
- files.modify: `candy-code/src/Tui/Pane.php` (case, `icon()`, `label()`, `dockable()`, `tabCycle()`), `candy-code/src/Tui/Components/MenuBar.php` (`PANE_TABS`), `candy-code/src/Tui/Components/ContextPane.php` (`render(App)` adapter), `candy-code/src/Tui/Renderer.php` (`renderPane()`), `candy-code/src/Renderer.php` (`renderStatusBar()` zone), `candy-code/src/Chat.php` (`handlePointer()` `ctxmeter` arm), `candy-code/src/App/App.php` (fields, side-row arm, `autoEnablePanes()`), `candy-code/src/Tui/KeyboardHandler.php` (pane keys), `candy-code/src/Commands/KeyBindingRegistry.php` (`context-pane` rows), `candy-code/lang/en.php` (`tui.pane.title.context`, `keys.context.*`), `candy-code/README.md` (panes paragraph + L642 `/context` section mentions the box)
- tests:
  - create `candy-code/tests/Tui/ContextPaneShellTest.php` (docked frame never over-wide; a click on a category drills; the meter zone docks the pane; auto-show honours userToggled);
  - modify `candy-code/tests/Tui/PaneTest.php` (tabCycle ↔ PANE_TABS pin), `candy-code/tests/Tui/MenuBarCompactStripTest.php`, `candy-code/tests/Commands/KeyBindingDriftTest.php` (observations for the new rows).
- docs/guards: `README.md`; `tests/Commands/KeyBindingDriftTest.php` moved in-step
- serialized: [renderer:Tui/Renderer.php renderPane; Renderer.php renderStatusBar] (layout #2 of 3) [chat:handlePointer] [lang] (prefix `tui.pane.title.context`, `keys.context.`) [docs:README.md] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/Pane.php] [hot:candy-code/src/Tui/Components/MenuBar.php] [hot:candy-code/src/Tui/KeyboardHandler.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php]
- deps: P-CTX.c, P-CTX.d, P-IMG.b (serial: layout)
- phase: normal
- ruling: auto-dock the Context pane once on ≥120-col terminals after the first reply (proposed), or leave it off until the user docks it?
- risks: one more pane tab widens the menu strip. P-IMG.a's overflow and MenuBar density absorb it; re-run the compact-strip sweep.

### P-TABS.g — Shell window strip + Sessions pane, attention badges, OSC 2 title (GAP-UI-N6)
- item: P-SESSION-TABS (sources: GAP-UI-N6, GAP-SES-12)
- kind: gap · effort M
- approach:
  - `App::windowList()` builds the `WindowList` from the deck: tab names, attention and the summary through `SessionTabs::updateTabSummary` (`⠋ step 4 · 2 agents` from `AgentLiveRegistry::forSession`).
  - **Horizontal.** `Tui\Renderer::renderView` (:602) adds one strip row under the menu bar (counted in `$chrome`, :625-630). It shows whenever the deck is armed and the Sessions pane is not docked, even with one tab, so `+` is always reachable. Zones go through `scanChrome`. `Renderer::renderSessionTabStrip` (:3370) returns '' for a deck-hosted Chat, so there is no double strip.
  - **Vertical.** Add `Pane::Sessions` (dockable, left) via `renderPane()`. It auto-docks once when the second tab opens.
  - **Clicks.** `dispatchChromeClick` handles `win:new` (openTab), `win:s:<key>` (switchTab) and `winx:s:<key>` (closeTab, confirm if busy), plus the Sessions side rows.
  - **Attention.**
    - The OSC 2 title (`Cmd::setWindowTitle`) is `candy-code — 2 waiting · 1 running`, emitted only on change.
    - A background tab reaching Waiting or Unseen fires the existing `TerminalNotifier` (`src/Chat.php:2731`), once per transition.
    - The in-frame AgentStrip call site switches to `AgentStrip::itemsFor(…, currentSessionId)`.
- files.create: none
- files.modify: `candy-code/src/App/App.php` (`windowList()`, chrome arms, side-row arm, title Cmd), `candy-code/src/Tui/Renderer.php` (`renderView()` strip row, `renderPane()` Sessions arm), `candy-code/src/Renderer.php` (`renderSessionTabStrip()` suppression; AgentStrip `itemsFor` call ~:1823), `candy-code/src/Tui/Pane.php` (`Sessions` case), `candy-code/src/Tui/Components/MenuBar.php` (`PANE_TABS`), `candy-code/src/Tui/Components/SessionsPane.php` (`render(App)` adapter), `candy-code/src/Commands/KeyBindingRegistry.php` ("Click tab" row reworded; `sessions` pane rows), `candy-code/lang/en.php` (`tui.window.title.*`, `keys.sessions.*`), `candy-code/README.md` (L1023 tab section rewritten: concurrent tabs, `+`, `×`, badges, Sessions pane)
- tests:
  - create `candy-code/tests/Tui/WindowStripShellTest.php` (the strip row is present with one tab; pane rows drop by exactly 1; never over-wide; a `+` click opens a tab via the factory; × on a busy tab needs a confirm);
  - create `candy-code/tests/App/TabAttentionTest.php` (Working/Waiting/Unseen/Failed transitions; notify once per transition);
  - create `candy-code/tests/App/WindowTitleTest.php` (OSC 2 only on change);
  - modify `candy-code/tests/Tui/PaneTest.php` and `candy-code/tests/Commands/KeyBindingDriftTest.php`.
- docs/guards: `README.md`; `tests/Commands/KeyBindingDriftTest.php` moved in-step
- serialized: [renderer:Tui/Renderer.php renderView+renderPane; Renderer.php renderSessionTabStrip + AgentStrip call] (layout #3 of 3) [lang] (prefix `tui.window.title.`, `keys.sessions.`) [docs:README.md] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/Pane.php] [hot:candy-code/src/Tui/Components/MenuBar.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php]
- deps: P-TABS.a, P-TABS.c, P-TABS.f, P-AGENT.a, P-CTX.e (serial: layout)
- phase: normal
- ruling: none
- risks: the strip costs one row on short terminals. Drop it below 12 rows (the Sessions pane and Ctrl+Tab still work), and pin the cut-off.

### P-AGENT.b — Agent windows: own viewport, rows/chips in the window list, `Ctrl+X i` interrupt-send
- item: P-AGENT-ATTACH (sources: owner pick; CLAW-new#7 note; CC-new#9 parity note only)
- kind: feature · effort M
- approach:
  - **Gap 1: shared viewport.** The Agent View shares the main chat's scroll and draft. `App::openAgentView`/`closeAgentView` (`src/App/App.php:710,810`) never save `Chat::scrollOffset()` or the input, so the main draft becomes the agent composer and the scroll position leaks across.
    - Stash and restore through `WindowViewports` (keys `s:<tab>` / `a:<runId>`) on open, close and Alt+N/P.
    - Chat gains public `draft()`/`withDraft(text, cursor)`; `withInputBuf` is private (:15672). `withScrollOffset` exists (:7158).
    - The open agent view is remembered per tab, so switching tabs and back restores it.
  - **Gap 2: no session-level list of agents.** `App::windowList()` adds each session's live runs (`forSession`) as child entries:
    - the SessionsPane agent rows and the strip chip (`▸ ⠋ @explore` in preset colour);
    - ⏳ when the run waits on you (`AskOrigin`);
    - a click switches tab if needed, then `openAgentView`.
  - **Gap 3: delivery is steer-only.** User messages reach a running child only at its next step boundary (`AgentInbox::send` → mailbox → `MailboxTurnInbox` drained at the child's step boundary, `src/Tools/BuiltIn/TaskTool.php:1544`).
    - Add `Ctrl+X i`: send the draft as `MessageMode::Interrupt`, which skips the step's unstarted calls and reads the message first (`src/Agents/Live/MessageMode.php`).
    - Wiring: a new `AgentControlMsg::INTERRUPT` verb → `Chat::submitToAgents` (~:9142) passes the mode. The sent row reads `⚡ interrupt` vs `⧗ queued`.
- files.create: none
- files.modify: `candy-code/src/App/App.php` (`openAgentView()`, `closeAgentView()`, sibling nav, `windowList()` agent entries, `win:a:*` and side-row arms), `candy-code/src/Chat.php` (`submitToAgents()` mode; new `draft()`/`withDraft()`), `candy-code/src/App/AgentControlMsg.php` (`INTERRUPT`), `candy-code/src/Tui/KeyboardHandler.php` (leader map :948-975, `'i'`), `candy-code/src/Commands/KeyBindingRegistry.php` (`agent-transcript` row `Ctrl+X i`), `candy-code/lang/en.php` (`tui.agent_view.sent.interrupt`, `keys.agent-transcript.interrupt`), `candy-code/docs/AGENTS.md` (Agent View key table + "Messaging an agent")
- tests:
  - create `candy-code/tests/App/AgentWindowViewportTest.php` (the main draft and scroll survive an open→close round-trip; each agent keeps its own; per-tab memory);
  - create `candy-code/tests/Chat/AgentInterruptSendTest.php` (the mailbox line carries mode `interrupt`; a finished run still follows up);
  - modify `candy-code/tests/Chat/AgentViewTest.php` and `candy-code/tests/Commands/KeyBindingDriftTest.php`.
- docs/guards: `docs/AGENTS.md`; `tests/Commands/KeyBindingDriftTest.php` moved in-step
- serialized: [chat:submitToAgents + new draft()/withDraft()] [lang] (prefix `tui.agent_view.sent.`, `keys.agent-transcript.`) [docs:AGENTS.md] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/KeyboardHandler.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php]
- deps: P-AGENT.a, P-TABS.g
- phase: normal
- ruling: none
- risks: the history cap (`AGENT_VIEW_MAX_ITEMS` 4000, `AGENT_VIEW_OPEN_READS` 64×64 KiB, App.php:157-160) still bounds the scroll-back of a huge live run. A finished run reads its full child session.

### P-CTX.f — Protocol: `context.sources` categories
- item: P-CTX-BREAKDOWN (sources: owner pick; PRM-12 web)
- kind: feature · effort S
- approach:
  - `ContextSources::serialize()` (`src/Host/ContextSources.php:217-233`) adds `categories: [{key, label, tokens|null, share, sub:[{label, tokens}]}]` plus `buffer` and `mcpServers`.
  - The `context.sources` breakdown schema (`src/Protocol/Schema/Definitions.php:230-278`) gains them as optional members, so older clients still validate.
  - Regenerate the schema JSON, SERVER.md and `generated.ts`.
- files.create: none
- files.modify: `candy-code/src/Host/ContextSources.php` (`serialize()`), `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/docs/protocol/candy-code.v1.schema.json` (gen), `candy-code/docs/SERVER.md` (gen), `candy-code-web/src-web/protocol/generated.ts` (gen)
- tests: modify `candy-code/tests/Protocol/ContextSourcesTest.php` (categories shape from a real session fixture)
- docs/guards: `tools/gen-protocol-schema.php --check`, `npm run gen:protocol`, `tests/Config/ServerProtocolDocumentationDriftTest.php`, `tests/Protocol/ProtocolSchemaDriftTest.php`
- serialized: [protocol] [docs:SERVER.md]
- deps: P-CTX.a
- phase: normal
- ruling: none
- risks: C-MCP-4 (user-tier MCP rows) edits `ContextSources::answer()` in the same file. Keep this to `serialize()`.

### P-AGENT.c — Protocol: `agents.list` colour + `agents.message` mode
- item: P-AGENT-ATTACH (sources: k1-dorm#3, CLAW-new#7)
- kind: feature · effort S
- approach:
  - `agents.list` items gain an optional `color` (the preset's, resolved through `AgentColor` to a hex or omitted).
  - `agents.message` gains an optional `mode: steer|interrupt` (default steer). `AgentsMethods` passes it to `AgentMessage::fromUser($text, MessageMode)`.
  - Schemas at `src/Protocol/Schema/MethodSchemas.php:426-465`; regenerate the chain.
- files.create: none
- files.modify: `candy-code/src/Protocol/Methods/AgentsMethods.php` (list, message), `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json` (gen), `candy-code/docs/SERVER.md` (gen), `candy-code-web/src-web/protocol/generated.ts` (gen)
- tests: create `candy-code/tests/Server/AgentsColorAndInterruptTest.php` (colour present or omitted; an interrupt-mode mailbox line; a bad mode is refused by the schema)
- docs/guards: the gen `--check`s; `tests/Config/ServerProtocolDocumentationDriftTest.php`
- serialized: [protocol] [docs:SERVER.md]
- deps: P-AGENT.a
- phase: normal
- ruling: none
- risks: must not share a wave with P-CTX.f or any other protocol lane.

### P-CTX.g — Web: context meter + category breakdown + cache %
- item: P-CTX-BREAKDOWN (sources: PRM-12 web; GAP-MOD-15w cache half; MOD-19 web; MOD-N3 web)
- kind: feature · effort M
- approach:
  - `ContextPanel.vue` gets the same model as the TUI box:
    - a stacked category bar;
    - category rows with expandable sub-rows (layers, files, MCP servers, largest messages);
    - the "not sent" MCP-instructions row;
    - a hint naming `/context --raw`.
  - **Status bar.** `StatusBar.vue`'s context meter becomes a button that opens the panel (`panels.show('context')`). It gains a `cache NN%` span computed from `turnUsage.cacheReadTokens` (already in the reducer; no protocol change).
  - **Store.** `contextSources.ts` re-loads the open session after `turn.completed`.
  - The `context.changed` live reload belongs to C-CTX-2, which edits the same store.
  - Every server string stays in plain `{{ }}` spans (the panel's security posture).
- files.create: none
- files.modify: `candy-code-web/src-web/components/panels/ContextPanel.vue`, `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/stores/contextSources.ts`
- tests: modify `candy-code-web/src-web/components/panels/__tests__/contextPanel.spec.ts` (bar widths sum to 100%; drill toggles) and `candy-code-web/src-web/components/__tests__/statusBar.spec.ts` (meter opens the panel; cache %)
- docs/guards: web unit tests
- serialized: [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/stores/contextSources.ts]
- deps: P-CTX.f
- phase: normal
- ruling: none
- risks: C-WEB-2 (`/effort` readout) also edits `StatusBar.vue`. Do not share a wave with it.

### P-AGENT.d — Web agents parity: colours, interrupt send, running-agents line (CLAW-new#7)
- item: P-AGENT-ATTACH (sources: CLAW-new#7, k1-dorm#3)
- kind: feature · effort M
- approach:
  - `agents.ts` keeps the catalogue's colours by name and `message(sessionId, id, text, mode)`.
  - `SubAgentTree` paints a colour dot per run.
  - `AgentView` gets an "interrupt" send (a toggle, plus Ctrl+Enter) with the same `⚡`/`⧗` row states as the TUI.
  - A new `RunningAgentsLine` in SessionView's transcript `#after` slot: `⠋ 2 agents running` with per-child activity rows. Each row opens that run (`panels.openAgent`). This is OpenClaw's working-line countdown and launch rows linking in.
- files.create: `candy-code-web/src-web/components/agents/RunningAgentsLine.vue`
- files.modify: `candy-code-web/src-web/stores/agents/agents.ts`, `candy-code-web/src-web/components/agents/SubAgentTree.vue`, `candy-code-web/src-web/components/agents/AgentView.vue`, `candy-code-web/src-web/views/SessionView.vue` (`#after` slot)
- tests: create `candy-code-web/src-web/components/agents/__tests__/agentsParity.spec.ts` (dot colour; interrupt mode sent; the line counts running runs and opens one)
- docs/guards: web unit tests
- serialized: [hot:candy-code-web/src-web/views/SessionView.vue]
- deps: P-AGENT.c
- phase: normal
- ruling: none
- risks: low. Colours come from the server already contrast-checked; the web still falls back to the status colour.

### P-TABS.h — Web: `+` new-session tab and unseen badges
- item: P-SESSION-TABS (sources: GAP-UI-N6 web half; owner pick)
- kind: feature · effort S
- approach:
  - The web tab strip (`components/grid/SessionTabs.vue`) already streams every session and badges asks, but has no `+`.
  - Add a trailing `+` tab → `useNewSessionStore().start()` (picker-aware), then route to the new session.
  - Add an unseen-finished badge: `layout.ts` records a non-active session whose status goes busy→idle, and clears it on select.
- files.create: none
- files.modify: `candy-code-web/src-web/components/grid/SessionTabs.vue`, `candy-code-web/src-web/stores/layout.ts`
- tests: create `candy-code-web/src-web/components/grid/__tests__/sessionTabsNew.spec.ts` (`+` starts a session; unseen set and cleared); modify `candy-code-web/src-web/stores/__tests__/layout.spec.ts`
- docs/guards: web unit tests
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

### P-IMG.c — Web: Generate entry in the top bar
- item: P-IMAGE-MENU (sources: owner pick)
- kind: feature · effort S
- approach:
  - The web Generate panel is only a drawer tab and a palette row.
  - Add a `generate` button to the top bar beside settings/about, shown only when `connection.canGenerateImage`. It opens `usePanelsStore().show('generate')`.
  - The drawer is 28 rem, so the web popup is already not full width.
  - C-WEB-3 adds the video tab inside the panel itself.
- files.create: none
- files.modify: `candy-code-web/src-web/App.vue` (topbar)
- tests: create `candy-code-web/src-web/components/__tests__/topbarGenerate.spec.ts` (hidden without the capability; the click shows the panel)
- docs/guards: web unit tests
- serialized: [hot:candy-code-web/src-web/App.vue]
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

## Notes for the orchestrator

- **Layout chain (at most one per wave):** P-IMG.b → P-CTX.e → P-TABS.g. Each touches `src/Tui/Renderer.php`, and the last two also touch `src/Renderer.php`. The chain is encoded as `(serial: layout)` deps. No step touches `Chat::view()` itself.
- **[chat] regions:**
  - P-TABS.e: new `withNoticeDrain()`.
  - P-CTX.d: new `measureContextCmd()` + session-surface arm.
  - P-TABS.f: the ONLY constructor field, `tabHosted`; plus picker/palette/agent-open/`refuseWhileInFlight`.
  - P-CTX.e: `handlePointer` `ctxmeter` arm.
  - P-AGENT.b: `submitToAgents` + `draft()`/`withDraft()`.
  - P-CTX.e and P-TABS.f can fall in one wave; their regions differ.
- **Bootstrap:** P-TABS.d `chat()`→`buildChat()`/`tabChat()`, and P-AGENT.a `workspace()` (:1893). These are different methods but the same file.
- **Protocol lanes:** P-CTX.f and P-AGENT.c must go in different waves.
- **Hot files:**
  - `candy-code/src/App/App.php`: P-TABS.d/e/f/g, P-CTX.e, P-AGENT.b. These are already chained by deps except P-CTX.e.
  - `src/Tui/Pane.php` + `src/Tui/Components/MenuBar.php`: P-IMG.a, P-CTX.e, P-TABS.g.
  - `src/Commands/KeyBindingRegistry.php` + `src/Tui/KeyboardHandler.php`: P-TABS.e, P-CTX.e, P-TABS.g, P-AGENT.b.
  - `src/Host/TurnRunner.php`: P-CTX.d.
  - `src/Agents/Live/AgentLiveRegistry.php`: P-AGENT.a.
  - Web: `App.vue` (P-IMG.c), `StatusBar.vue` + `stores/contextSources.ts` (P-CTX.g), `SessionView.vue` (P-AGENT.d).
- **Cross-area:**
  - C-CTX-2 edits `web:stores/contextSources.ts` (`context.changed` reload); keep it out of P-CTX.g's wave.
  - C-MCP-4 edits `src/Host/ContextSources.php` `answer()`; keep it out of P-CTX.f's wave.
  - C-WEB-2 edits `StatusBar.vue`/`session.ts`; keep it out of P-CTX.g's wave.
  - C-WEB-3 adds the GeneratePanel video tab, beside P-IMG.c (no file overlap).
  - Any C-TUI-*/C-AGT-* step touching `Renderer.php`, `MenuBar.php`, `KeyBindingRegistry.php`, `TaskTool.php` or `AgentLiveRegistry.php` serializes against these steps.
- **[commands] collision:** P-IMG.a (CommandRegistry/CommandSpec/4000-4040) and P-CTX.b (1350-context) are both [commands]. P-TABS.f adds a NEW file (`0105-close-tab.php`) plus a PaletteAction case.
- **Rulings:** one, P-CTX.e: auto-dock the Context pane once on wide terminals (proposed default), or leave it off until the user docks it?
- **No LATE items, no new fork sites, no E646 exposure.** Background tabs use the existing TurnRunner/EngineBackend fork path, so `tools/check-child-lifetimes.php` gains no row.
- **Dormant code wired:** `SessionTabs::detachTab`/`reattachTab`/`updateTabSummary` (P-TABS.b/g) and the preset `color:` key (P-AGENT.a). The Chat's in-frame tab strip and `SessionTabState` stay live for standalone Chats; nothing is deleted.
- **Could not verify (read-only):**
  - the exact OpenCode v2 tab look; the vertical-plus-horizontal design follows the COMPARISON SES-12 note "vertical/horizontal, unread status" and the owner's wording;
  - that `TabScope` covers every Cmd shape in the live tree; P-TABS.b's test must enumerate `Program::runCmd`'s sentinels;
  - that Veil compositing over the shell frame (P-IMG.b) leaves no stale zone; that step's test pins it;
  - the real class name of the "no message class claimed twice" route-parity test cited in P-CTX.d.
