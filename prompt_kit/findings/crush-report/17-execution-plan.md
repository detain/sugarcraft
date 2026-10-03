# Execution plan: concurrency-aware waves

This appendix schedules every remaining roadmap step (Part III, Part V, Part VI) into **11 fix waves plus one final verification pass**. All work lands straight on `master`, with no PRs and at most 10 groups at a time.

**Step definitions** live in Part III (0.x–5.x), Appendix N (N-*), Appendix O (O-*), Appendix P (B1–B3, P-*) and Appendix Q/Part VI (15b-14).

**Per-step file impact** (exact files, method regions, forced docs and drift tests, dependencies) lives in `prompt_kit/findings/crush-report/impact/<batch>.md`. These files are research inputs and are not assembled into this report. The batches are:

| Batch | Covers |
|---|---|
| `wave0-engine` | 0.1, 0.2, 0.4, 0.5, 0.10, 0.13–0.16, X-31 |
| `wave0-tools` | 0.3, 0.6–0.8, 0.11, 0.12, X-35, X-37 |
| `foundations` | 1.A, 1.B, 1.C, RELAY, DEF-MODE |
| `context-engine` | 2.x |
| `wave3-safety` | 3.x |
| `wave4-subagents` | 4.x, X-30 |
| `wave5-memory-ux` | 5.1–5.8 |
| `wave5-integrations` | 5.9–5.14, 15b-14, LIVE |
| `settings` | N-* |
| `sessions-agentview` | B*, P-* |
| `server-web` | O-* |

A group's owned files are its row below, plus that step's "new files" and tests from the impact file.

All sugar-crush paths below are relative to `sugar-crush/`. Parts III and V name methods, not lines; current line anchors are in the impact files (Chat.php is 19,220 lines).

## 1. Rules

### 1.1 Isolation and setup

- Each group works in `/home/sites/sugarcraft-wt/<wave>-<group>` (e.g. `w3-c`) on local branch `fix/<wave>-<group>`.
- Each worktree is created from `origin/master` with every lib's `vendor/` copied in.
- Each group gets its own scratch directory `/home/sites/sugarcraft-wt/.scratch/<wave>-<group>`, exported as `TMPDIR`.
- The `sugarcraft-wt` directory was deleted. Recreate it with this helper (paste it into the shell; it is not committed):

```bash
mkwave() {  # usage: mkwave w3 a b c …   (creates w3-a, w3-b, …)
  local w=$1; shift
  local R=/home/sites/sugarcraft W=/home/sites/sugarcraft-wt
  mkdir -p "$W/.scratch"
  git -C "$R" fetch -q origin
  for g in "$@"; do
    local d="$W/$w-$g"
    git -C "$R" worktree add -q -b "fix/$w-$g" "$d" origin/master || return 1
    for v in "$R"/*/vendor "$R"/vendor; do
      [ -d "$v" ] || continue
      local rel=${v#"$R"/}
      mkdir -p "$d/$(dirname "$rel")"
      cp -a "$v" "$d/$rel"
    done
    mkdir -p "$W/.scratch/$w-$g"
  done
}

rmwave() {  # usage: rmwave w3 a b c …
  local w=$1; shift
  local R=/home/sites/sugarcraft
  for g in "$@"; do
    git -C "$R" worktree remove --force "/home/sites/sugarcraft-wt/$w-$g"
    git -C "$R" branch -D "fix/$w-$g"
  done
  git -C "$R" worktree prune
}
```

`cp -a` keeps the relative path-repo symlinks, so a copied `vendor/` resolves siblings inside the worktree. Check the mode with `php scripts/refresh-deps.php --status` before trusting a red test.

### 1.2 Ownership and hotspots

- **No source file is owned by two groups in one wave.** The one exception is the hotspot files below, and only for the explicitly named, disjoint regions in the wave tables.
- **Hotspots:**
  - Methods/files: `src/Chat.php`, `src/Runtime.php`, `src/Backend/EngineBackend.php`, `src/Cli/Bootstrap.php`, `src/Renderer.php`, `src/Providers/SglangProvider.php`, `src/Providers/CustomProvider.php`, `src/Permissions/PermissionGate.php`, `src/Workflows/WorkflowEngine.php`, `src/Tools/BuiltIn/TaskTool.php`, `src/Config/LayeredSettings.php`, `src/Session/EnhancedSessionStore.php`.
  - Host files created by O-2 (`src/Host/TurnRunner.php`, `TurnController.php`, `CompactionService.php`).
- **Region anchors.** W1 adds `// @region <name>` comment pairs so later waves can split the three hottest methods:
  - `EngineBackend::runTurn`: `build`, `step-top`, `after-step`, `no-tools`, `return`, added by W1-a.
  - `Runtime::executeConcurrently`: `fork`, `ledger`, `poll`, `drain`, added by W1-a.
  - `TaskTool::runOnEngine` and its class: `schema`, `execute`, `setup`, `run`, `finish`, added by W1-g.

  Two groups may edit one of these methods in the same wave only in different named regions.
- **Single-owner resources.** One group per wave owns each of these:

  | ID | Resource |
  |---|---|
  | **CS** | Chat state: `Chat::__construct` params/properties and `mutate()` |
  | **R-KEYBIND** | `src/Commands/KeyBindingRegistry.php`, `src/Tui/KeyboardHandler.php`, README "### Keys" |
  | **R-CLI** | `src/Cli/{ParsedArgs,Subcommands,ArgvParser}.php`, `Help::screen` subcommand/flag sections, README "### Subcommands" fence |
  | **R-HOOKS** | `src/Hooks/{HookEvent,HookManager,HookDispatcher,HookRegistry}.php` event surface, `Bootstrap::hooks`, `docs/HOOKS.md` event and built-in tables |
  | **R-STATE** | `docs/ARCHITECTURE.md` "Sessions and state" table (row count pinned by `DocFigureProseDriftTest`) |
  | **R-SCHEMA** | `SessionStore::initSchema`, `EnhancedSessionStore::initEnhancedSchema` |
  | **TR** | `src/Host/TurnRunner.php`, from W5 on |

- **Registries are single-owner until they are de-hotspotted:**

  | Registry | Covers | Single-owner until | Made shareable by |
  |---|---|---|---|
  | **R-KEYS** | `LAYERED_KEYS`, `docs/SETTINGS.md` key table, README layered roster and count words | W4 | **DH-KEYS** (W2 + W4): per-category definition files under `src/Config/Settings/Definitions/`, `LAYERED_KEYS` derived from the schema, SETTINGS table + README roster + counts generated by `tools/gen-settings-doc.php --write` (`--check` in a drift test). Afterwards it is shareable per category file. |
  | **R-CMDS** | `CommandRegistry::all`, `docs/COMMANDS.md` built-in table, README slash roster, `Chat::dispatchCommand` arms | W3 | **DH-CMDS** (W3): one spec file per command under `src/Commands/Specs/` (glob-discovered, sorted), table-driven dispatch, COMMANDS.md table + README roster generated by `tools/gen-command-docs.php`. Changing an existing command's hint or description is row-scoped and always shareable. |
  | **R-TOOLS** | `Bootstrap::unfilteredTools` list, `PermissionGate` / `ProtectFilesHook` tool-name lists, `BuiltInToolCorpusTest` count, the tool-count prose in README / ARCHITECTURE / AGENTS_AUTHORING / PERMISSIONS | W3 | **DH-TOOLS** (W3): tools discovered from `src/Tools/BuiltIn/*` through a catalog, each tool declares its permission class (read / write / no-ask), roster prose and counts generated, spelled-number maps extended to "twenty". |

- **Shared docs** (README.md, docs/*.md) may be shared within a wave only by section. The overlaps are listed per group. Generated blocks (settings, commands, tools) are never hand-merged; the integrator re-runs the generator.

### 1.3 Group coordinator

- One coordinator per group does the group's steps itself, sequentially, in the listed order. It must never spawn background sub-agents: a coordinator that ends its turn while one runs stalls.
- One commit per step: `sugar-crush: <step-id> <summary>`, authored by `Joe Huss <detain@interserver.net>`.
- Never `git stash` (the stash is shared across worktrees). Never `pkill -f`. Use a PID-scoped watchdog for hang-prone runs: `( sleep 600; kill -9 $PID ) &`.
- Skip Caliber. If a hook stages Caliber files, unstage them.
- Edit only the files and regions in the group's row. If a step needs anything else, stop that step and record it in `/home/sites/sugarcraft-wt/.scratch/<wave>-<group>/HANDOFF.md`. The lead reschedules it.
- For each new test file, add a row to `scripts/parallel-tests-durations.tsv` with an estimated duration. Never touch `tests/Config/Support/suite-figure.json` or the README test-count headline.
- Do not edit `crush_report.md` or its sources. The integrator does that.
- Never stage the user's uncommitted files. At plan time these are `candy-palette/*`, `ansi/ansi.json` and `prompt_kit/findings/README-SCAN.md`; re-check `git status` at each wave start.

### 1.4 Testing discipline

- **Targeted tests only during a wave.** A group runs:
  - its new and changed test files;
  - the drift, census and golden tests its step forces (named in the impact rows), selected by path or `--filter`.

  Example: `vendor/bin/phpunit tests/Tools/BuiltIn/BashTimeoutTest.php`, or `vendor/bin/phpunit --filter 'ReadmeRosterDriftTest|EnvRosterDriftTest' tests/Config`.
- **Run independent test files concurrently.** Use `printf '%s\n' <files…> | xargs -P"$(nproc)" -I{} sh -c 'vendor/bin/phpunit {} >"$TMPDIR/$(basename {}).log" 2>&1 || echo FAIL {}'`. Separate PHP processes are safe because `tests/bootstrap.php` pins the loop clock.
- **Cross-lib:** a group that edits another lib runs that lib's targeted tests too (e.g. `sugar-mcp/tests/CallToolDeadlineTest.php`).
- **Integration:**
  - run the union of the wave's targeted files concurrently;
  - run `tests/Config` and `tests/Commands`, which hold the doc gates;
  - run the tools gates (§1.5);
  - **no full suite.**
- **Full-suite fallback.** Run `scripts/parallel-tests.sh 8 --durations scripts/parallel-tests-durations.tsv` (sharded, 8 shards) only when a wave's integration shows an unexplained red, or after W4 and W8 if the lead decides the risk warrants it. Never a serial full run before the Final pass.
- **Final pass only (once):**
  - serial full run with `--log-junit`;
  - regenerate the durations TSV and `suite-figure.json`;
  - README test-count headline;
  - `scripts/parallel-tests.sh --against-json`;
  - full suites of every other lib touched.

### 1.5 Integration (one agent per wave)

1. Create `/home/sites/sugarcraft-wt/<wave>-int` detached at `origin/master`.
2. **Scope guard**, per group branch:
   - `git diff --name-only origin/master...fix/<wave>-<group>` must be a subset of the group's owned files plus the durations TSV and the generated doc blocks.
   - For hotspot files, `git diff -U0` hunks must sit inside the group's named methods or regions.
   - A violation sends the branch back.
3. Cherry-pick the groups serially, in the wave's stated **order**: `git cherry-pick origin/master..fix/<wave>-<group>`.
   - A conflict inside a generated block or the durations TSV is resolved by re-running the generator or taking the union.
   - Any other conflict is a scope bug: abort that group and reschedule it.
4. Re-measure derived figures:
   - spelled counts and census figures (`DocFigureProseDriftTest`, `*CensusTest`, `TreeWideGuardRosterTest`);
   - the PathGlob corpus count (`GlobFigureDriftTest`);
   - `SymbolCitationDriftTest` citations after code moves;
   - re-run `tools/gen-settings-doc.php`, `tools/gen-command-docs.php` and the tool-roster generator with `--write` once they exist.
5. Run the targeted union and the doc gates (§1.4).
6. Run the tools gates:
   - `php tools/check-path-repos.php --no-lib-path-repos`;
   - `php tools/check-child-lifetimes.php`;
   - `php tools/check-one-type-per-file.php`;
   - `candy-core/vendor/bin/phpunit --no-configuration tools/tests/`.
7. **Report edits:**
   - delete each landed step's row from §4 and §5 of this file and its item row in Part III / V / VI;
   - delete a design section in N/O/P once every step citing it has landed;
   - re-run `prompt_kit/tools/assemble-crush-report.sh`;
   - never add "fixed" or history notes.
8. `git push origin HEAD:master`, then `git -C /home/sites/sugarcraft pull --ff-only` (the user's dirty files are untouched), then `rmwave`.

**Overlapping waves.** Wave n+1 groups may be created and start while wave n's integration runs, but only if:
- they depend on no wave-n step;
- they own no file that an un-integrated wave-n branch changes (check with `git diff --name-only` over the wave-n branches).

They are cherry-picked onto the newer master at their own integration.

### 1.6 Decision gates (default adopted unless the user overrides)

| # | Decision | Default | Lands in |
|---|---|---|---|
| D1 | Fork-frame vocabulary | Appendix O names (`ask`/`ask_reply`/`steer`/`steer_ack`/`cancel_soft`/`cancel_tool`/`usage`/`step`); `askId` = hash of {toolCallId, tool, args} | W1-c |
| D2 | MCP call timeout (0.5) | opt-in per-server `toolTimeout`, unset = unbounded (README :1321 / sugar-mcp E646 stay true); no 30 s global | W1-d |
| D3 | MCP stdio env scrub (0.14-b) | scrub inherited secrets; servers declare needed vars in `env`; launch notice names stripped vars | W1-d |
| D4 | User-scope memory injection (0.6) | inject user notes first, ≤4 entries / ≤1 KB inside the 12 / 4 KB cap | W1-h |
| D5 | DEF-MODE scope | TUI default becomes `default`; `-p` and the daemon keep their current default | W3-g |
| D6 | Steering setting name | `queueMode` (1.C-3 hard-codes `steer`; the key arrives in N-P4g) | W4-a / W9-j |
| D7 | Settings editor keys + i18n | no Ctrl+S/Ctrl+R; literal English labels until 15b-14 (`labelKey` kept, no `lang/` yet) | W3-i |
| D8 | Plan-mode toggle key | `Alt+M`; Shift+Tab stays `shell.pane-prev` | W9-j |
| D9 | Model persistence shape | `models: {provider: modelId}`, user tier; no top-level `model` key | W4-g |
| D10 | Task fan-out cap (0.16) | cap Task members only, default 5 (`AgentPoolConfig`) | W1-a |
| D11 | Settings class names | `SettingsSchema` / `SettingDefinition` (drop `SettingsRegistry`) | W2-d |
| D12 | ACP ids | an id-preserving `McpMessage` variant; MCP behaviour unchanged | W10-h |

## 2. Wave summary

| Wave | Groups | Focus |
|---|---|---|
| W1 | 10 | Wave-0 fixes, ids (1.B-1), frame channel (1.C-1), session store (P-A1, B1–B3), Task-tool switch fix (N-P3a), region anchors |
| W2 | 10 | Structured replay (1.B-2), TUI approvals (1.C-2), prompt split (1.A-1), settings schema + DH-KEYS, O-2a, picker, sub-agent grants, checkpoints, spill |
| W3 | 10 | Step pressure + usage frames, RELAY + live-line DTO, hide-not-delete, O-2b/c/d, CLI verbs + DEF-MODE, settings editor + DH-CMDS, Memory tool + DH-TOOLS |
| W4 | 10 | Cache-stable prefix (1.A-2), steering (1.C-3), O-2e, live lines, `/undo` `/redo` `/diff`, policy Ask, persisted model, memory search, JSON hooks + lint |
| W5 | 10 | In-turn prune + summary, O-2f, PreCompact + state summary, `/context`, grandchild asks, O-3a, O-5a scaffold |
| W6 | 10 | Overflow/length recovery, O-2g, cache-reusing compaction, ledger persistence, preset model/mode, LSP + auto-commit, daemon, live settings apply |
| W7 | 10 | Stop hooks, O-2h, protocol (O-3b/c), Prune tool, agent inbox, agent view, staleness, Todo, dream pass, watchdog setting |
| W8 | 10 | Compress tool, background summaries, web MVP, attach + workspace hosts, background Task, model workflows, direct agent chat, auto-test, `/goal` |
| W9 | 10 | Post-compaction re-injection, worktrees, multi-session web (O-6a/b/c), hard cancel, teams, constants → settings, plan mode |
| W10 | 10 | Tail: ApplyPatch + smart-approve, PlanExit/AskUser, board, Ctrl+X b, small UX, ACP, LIVE checks, i18n infra, messaging tools |
| W11 | 4 | i18n (15b-14-2…4b) |
| Final | 1 agent | Serial full run, durations + suite-figure, README count, cross-lib suites, report cleanup |

## 3. Critical path

The longest dependency chain is:

**1.B-1** (W1) → **1.B-2** (W2) → **1.B-3** (W3) → **O-2e** (W4) → **O-2f** (W5) → **O-2g** (W6) → **O-3b/O-3c** (W7) → **O-5b** (W8) → **O-6a/b/c** (W9) → **15b-14 i18n** (W11) → **Final**.

O-2e additionally needs O-2a (W2) → O-2c (W3).

Near-critical chains end at W9/W10:
- **Agent view:** RELAY + P-B1 (W3) → P-B2 → P-B3 → P-C1 → P-C2 → P-D2/P-D3 → P-E1 (W9) → P-E3 (W10).
- **Self-pruning:** 2.1 (W3) → 2.2-1 (W5) → 2.2-2/3.B-2 (W6) → 3.B-3 → 3.B-4 → 3.B-5 (W9).
- **Plan mode:** 1.C-2 (W2) → 4.1-2 (W6) → 5.7-1 (W9) → 5.7-2 (W10).

Why the count cannot drop below 11 fix waves plus Final:
1. The critical chain has 9 links that each need the previous link on master, either as an API or because it rewrites the same Chat/Host region. Its head (1.B-1) already lands in W1. That gives ≥9 waves.
2. i18n is deferred by decision until after every roadmap step, which adds +1.
3. Capacity: 194 steps pack into 104 coordinator-sized groups at ≤10 per wave, so ≥11 waves. Packing more steps per group shortens the wave count only by making every wave longer.
4. The single-owner resources (`EngineBackend::runTurn` regions, `TaskTool::runOnEngine` regions, CS, R-KEYBIND, `PermissionGate`, TR) need W1–W10 even with the region anchors and DH-* steps. `runTurn` alone takes about 20 edits.

## 4. Wave tables

Columns:
- **Owned files**: the group's row. New files and tests come from the impact rows.
- **Hotspot regions**: listed only where another group in the same wave also touches that file.
- **Order**: the integration cherry-pick order for the wave.

### W1 (order: a, c, b, d, e, g, f, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 0.16, 0.2, 0.13-a, 0.10, 0.13-b (+ runTurn/executeConcurrently anchors) | `src/Backend/EngineBackend.php`, `src/Runtime.php`, `src/Chat.php`, `src/Cli/Bootstrap.php`, `src/Renderer.php`, `src/Providers/{SglangProvider,CustomProvider}.php`, `src/Providers/CompleteRequest.php`, `src/Providers/Concerns/SessionAffinity.php`, `src/Tools/ToolCall.php`, `src/Messages/HistorySanitizer.php`, `src/Agents/AgentPoolConfig.php` (read) | EB: `runTurn`, ctor + withers. RT: ctor, `run`, `runStreaming`/`runBatch` yields, `executeConcurrently`. Chat: `scheduleBackendCompletion` (withSessionId only). BS: `backendFor`. RN: `cacheIndicator`. SG/CU: post sites only | ARCHITECTURE "Parallel tool dispatch", "Runtime — the agentic loop"; PROMPT_ENGINEERING "Session affinity"; README "The agent loop" | — | M |
| b | 1.B-1, X-31a, X-31b | `src/Message.php`, `src/Session/EnhancedSessionStore.php`, `src/Backend/EngineBackend.php`, `src/Chat.php`, `src/Providers/OpenAIProvider.php`, `src/Providers/ProviderFactory.php` | EB: `toTypedMessages`. Chat: `persistTranscript`. ESS: `saveTranscript`/`loadTranscript` | ARCHITECTURE session/transcript paragraph; ENVIRONMENT `ANTHROPIC_BASE_URL`; README "Providers" anthropic row | — | M |
| c | 1.C-1 (+ D1 frame vocabulary, `PendingAsk`) | `src/Backend/EngineBackend.php`, new `src/Backend/{ChildChannel,PendingAsk,InteractiveTurn}.php`, `src/Events/{PermissionAsked,PermissionResolved}.php` | EB: `completeAsync`, `runCompleteInChild`, `drainFrames`, `encodeEvent`/`decodeEvent`, `completeAsyncBlocking` | ARCHITECTURE "EngineBackend forks" frame table (also edited by d: d edits the idle-ceiling sentence only) | candy-testing (LoopPin, unchanged) | M |
| d | 0.4-a, 0.4-b, 0.5, 0.14-b | `src/Tools/BuiltIn/{Bash,Grep}.php`, `src/Tools/Concerns/CapturesProcessOutput.php`, `src/Runtime.php`, `src/Tools/McpToolBridge.php`, `src/MCP/{StdioMcpServer,McpClient,McpTrustPins}.php`, `src/Support/ProcessContainment.php`, `../sugar-mcp/src/StdioMcpServer.php` | RT: `executeToolCalls`, `executeSequentially`. Bash: `inputSchema`, `execute`, `description` | README :1321 MCP and :1292 Bash bullets; MCP.md (trust pins, `.mcp.json` table, `${VAR}`); ENVIRONMENT `secretEnvAllowlist` row; ARCHITECTURE "EngineBackend forks" idle sentence | **sugar-mcp** | M–L |
| e | 0.7 → 0.12 → 0.11 | `src/Context/ContextCompactor.php`, `src/Chat.php`, `src/Tools/BuiltIn/{Read,Edit}.php`, new `src/Tools/Edit/*` | Chat: `compactionWire` | SKILLS.md :176 (1.375x figure) | — | M |
| f | B2 → P-A1 (store side; the Chat TitleSource latch moves to P-A4) → B1 → B3 | `src/Session/{SessionStore,EnhancedSessionStore}.php`, new `src/Session/{SessionKind,TitleSource,SessionQuery,SessionRow}.php`, `src/Chat.php`, `src/Cli/Bootstrap.php`, `src/Tui/SessionPicker.php` (footer) | Chat: `scheduleTitleGeneration`, `route` SessionTitledMsg arm, `sanitizeSessionRows`, `dispatchTurn` checkpoint block, `handleBranchCommand`. ESS: resumable/prune/list API. BS: `openSession`/`seedSession`. **R-SCHEMA, R-STATE** | README "Sessions" :137, Capabilities "Sessions" :1322; ARCHITECTURE "Sessions and state" (prose) | — | M–L |
| g | 0.1, 0.14-a, 0.15 (+ TaskTool anchors), 0.3, 0.8, 0.14-c | `src/Providers/SglangProvider.php`, `src/Context/PromptFence.php`, `src/Tools/BuiltIn/{TaskTool,Bash}.php`, `src/Cli/Bootstrap.php`, `src/Config/LayeredSettings.php`, `src/Hooks/BuiltIn/ProtectFilesHook.php`, `/home/sites/sugarcraft/AGENTS.md` (check only) | SG: `buildParams`, `formatMessages`. Bash: `promptGuidance` + ctor. BS: `tools` (reads settings; no `backendFor` edit). **R-KEYS** | README layered roster (count 21→23); SETTINGS "Which keys are layered"; PERMISSIONS ProtectFiles table; HOOKS "What protect-files covers" | — | M |
| h | N-P3a, 0.6, X-35a (=5.14f) | `src/Chat.php`, `src/Cli/Bootstrap.php`, `src/Context/MemoryBlock.php`, `src/Commands/{ShareCommand,CommandRegistry}.php`, `src/Share/*`, `src/Util/Exporter.php` | Chat: **CS** (backend factory), `selectPaletteProvider`, `handleModelCommand`, memory handlers, `handleShareCommand`/`shareResponse`. BS: `chat` (factory closure), `taskWorkerPool`, `agentPoolConfig`. **R-CMDS** | MEMORY "/memory", "three tiers"; ARCHITECTURE :271; PROMPT_ENGINEERING :46; SKILLS :201-205; COMMANDS `/share` row; ENVIRONMENT share rows | — | M |
| i | X-30, 4.6-1, 4.10-1 | `src/Chat.php`, `src/Sessions/{BackgroundSupervisor,BackgroundSessionRunner}.php`, `src/Agents/TaskList.php`, `src/Workflows/{WorkflowEngine,WorkflowRegistry,WorkflowBuilder}.php` | Chat: `scheduleBackgroundSpawn`. WE: `executeStage` | WORKFLOWS "Three limits"; COMMANDS `/fork` row | — | M |
| j | O-0 (findings note only), 5.5-1, N-DOC-1, N-DOC-3, 5.14k | new `src/RepoMap/{PhpSymbolExtractor,TagCache,Tag}.php`, `src/Skills/{SkillFrontmatter,Skill,SkillRegistry}.php`, `prompt_kit/findings/crush-report/o0-spikes.md` | — | ENVIRONMENT "Variables read from any config file"; SETTINGS "When a file is ignored"; SKILLS "Frontmatter", "Diagnostics" | candy-core only if spike (d) is adopted | M |

### W2 (order: a, c, b, e, d, g, h, i, f, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 1.B-2 | `src/Backend/EngineBackend.php`, `src/Chat.php`, `src/Renderer.php`, `src/Messages/{AssistantMessage,ToolResultMessage,HistorySanitizer}.php`, `src/Message.php` | EB: `runTurn` (`return`), `runCompleteInChild` (result frame), `settleFromResultFrame`, `toTypedMessages`. Chat: `route` AssistantMsg arm, `replaceToolRunningPlaceholder`, `toolResultMessage`. RN: `renderHistory` | ARCHITECTURE turn pipeline / result frame | — | M |
| b | 1.C-2 | `src/Chat.php`, `src/Runtime.php`, `src/Permissions/PermissionGate.php`, `src/Hooks/HookManager.php` (verdict type only), `src/PermissionRequestMsg.php`, `src/Agents/AgentManager.php`, `src/Cli/{HeadlessPermissionPrompt,NonInteractive}.php`, new `src/Permissions/{SessionPermissionMemo,ApprovalVerdict}.php` | Chat: `requestPermission`, `answerPermission`, `handlePermissionKey`, `scheduleBackendCompletion`, `pumpLiveToolEvents`. RT: `settleAsk` | README "Permission prompts" :1326, Limitations :1357; PERMISSIONS "`Ask` needs somewhere to ask" | sugar-veil (no change) | M |
| c | 1.A-1 | `src/Context/EnvironmentBlock.php`, `src/Runtime.php`, `src/Providers/{SglangProvider,CustomProvider}.php`, new `src/Context/{TurnContextBlock,SessionPromptMemo}.php` | RT: `systemPromptSections` + snapshots. SG: `formatMessages`. CU: `formatMessages`, system prepend in `complete`/`completeStream` | PROMPT_ENGINEERING slot list; ARCHITECTURE assembly order | — | M |
| d | N-P0-1, N-P0-2, N-DOC-2, DH-KEYS (generated docs; per-category definitions) | new `src/Config/Settings/**`, `tools/gen-settings-doc.php`, `src/Config/LayeredSettings.php`, `src/Cli/Bootstrap.php` | BS: `promptEnabledSkills`. **R-KEYS** | SETTINGS (generated block + layered table + count); ENVIRONMENT (new last column); README layered roster (becomes generated); SKILLS :213 | — | M |
| e | O-2a (+ `WorkspaceContext::service()` locator, so O-2b…h add no Chat state) | `src/Cli/Bootstrap.php`, `src/Chat.php`, `src/Backend/EngineBackend.php`, `src/Diagnostics/RuntimeNoticeSink.php`, new `src/Host/WorkspaceContext.php`, `src/Diagnostics/NoticeSink.php` | BS: `chat` (non-UI half), `backendFor`. Chat: **CS**, `selectPaletteProvider`, `handleModelCommand`, `runtimeNoticeWake`, `pumpRuntimeNotices`. EB: `completeAsync` child branch (sink arm) | ARCHITECTURE "Cli\Bootstrap" | — | M |
| f | P-A2 | `src/Chat.php`, `src/Renderer.php`, `src/Tui/{SessionPicker,SessionRow}.php`, `src/Commands/KeyBindingRegistry.php` | Chat: picker block, `handlePointer` session zone arm, `selectSessionRow`. RN: `renderSessionPicker`, `markSessionRows`, zone constants. **R-KEYBIND** | README "Keys" (picker rows) | candy-forms, candy-fuzzy (consume only) | M |
| g | 4.2, 4.7-1 | `src/Agents/{AgentManager,AgentDefinition,SuspendedDelegations}.php`, `src/Backend/EngineBackend.php`, `src/Tools/BuiltIn/TaskTool.php`, new `src/Hooks/BuiltIn/SubAgentGrantHook.php` | EB: ctor + withers, `resolveHookManager`. TT: `setup`, `finish` | AGENTS_AUTHORING (grant enforcement) | — | M |
| h | 3.A-1 | `src/Chat.php`, `src/Session/EnhancedSessionStore.php`, new `src/Workspace/{GitRunner,WorkspaceCheckpointer,ShadowRepo}.php` | Chat: `dispatchTurn` checkpoint block. ESS: checkpoint save/restore/prune/copy/delete. **R-STATE** | ARCHITECTURE "Sessions and state"; README "Sessions" :137 | — | M |
| i | 2.8, 3.I-1 | `src/Tools/Concerns/TruncatesOutput.php`, `src/Tools/PathJail.php`, `src/Support/HookContextFiles.php`, `src/Runtime.php`, `src/Tools/BuiltIn/Edit.php`, new `src/Support/{ToolOutputSpill,PrivateRetainedDir}.php`, new matcher stages | RT: `settle`, `resultMessage`, `basePrompt` (Edit paragraph) | ARCHITECTURE "Tools" :366; PERMISSIONS (jail exception) | — | M–L |
| j | 5.14j, 5.5-3, 5.14e, 5.14h | `src/Runtime.php`, `src/Context/InstructionFileLoader.php`, new `src/RepoMap/{SymbolGraph,PageRank,RepoMapRenderer}.php`, `src/Chat.php`, `src/Commands/CommandRegistry.php` | RT: `planInstructionDocuments`. Chat: `dispatchCommand` arms (`init`, `editor`), `READ_ONLY_COMMANDS`. **R-CMDS** | MEMORY "Instruction files"; README slash roster; COMMANDS table; ENVIRONMENT "OS variables" (EDITOR) | candy-core `Cmd::exec` (use) | M |

### W3 (order: a, b, c, d, e, f, g, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 2.1, 1.C-4a (usage/step/`cancel_soft` frames via ChildChannel; `onStep` param) | `src/Backend/{EngineBackend,ChildChannel}.php`, `src/Runtime.php`, `src/Chat.php`, `src/Renderer.php`, `src/Util/TokenEstimate.php`, new `src/Context/{ContextBudget,ContextPressure}.php`, `src/Events/{UsageUpdated,StepStarted}.php` | EB: `runTurn` `step-top`/`after-step`, `completeAsync`, `runCompleteInChild` (`cancel_soft`), `completeTranscript` signature. RT: `run`. Chat: **CS**, `route` Escape arm. RN: status bar | ARCHITECTURE "Runtime" + frame table; README "What you see" tier sentence | — | M–L |
| b | RELAY, P-B1 | `src/Runtime.php`, `src/Backend/EngineBackend.php`, `src/Tools/BuiltIn/TaskTool.php`, `src/Events/SubAgentActivity.php`, `src/Agents/AgentManager.php`, new `src/Tools/{StreamsActivity,ActivitySink,DatagramActivitySink}.php`, `src/Agents/Live/{ActivityItem,SubAgentActivityBuffer,ToolSummary}.php` | RT: `executeConcurrently`, `runToolInChild`. EB: `turnTools`, `encodeEvent`/`decodeEvent` | ARCHITECTURE activity relay (frame-table rows distinct from a); TROUBLESHOOTING (pcntl) | — | L |
| c | 1.B-3 | `src/Chat.php`, `src/Context/ContextCompactor.php`, `src/Renderer.php` | Chat: `compactNow`, `compactionChanges`, `compactionWire`, `applyModelCompaction`. RN: `renderHistory` | MEMORY/ARCHITECTURE compaction paragraph | — | M |
| d | O-2b | `src/Chat.php`, `src/Session/EnhancedSessionStore.php`, new `src/Host/{TranscriptStore,EventLog}.php` | Chat: `persistTranscript`…`switchToSession`, `reviveCheckpointMessage`. **R-SCHEMA** (`session_events`) | ARCHITECTURE "Sessions and state" (prose, no new row) | — | M |
| e | O-2c | `src/Chat.php`, new `src/Host/{SpendLedger,ContextMeter}.php` | Chat: meters `shouldPromptIdleCompaction`…`spendCapTurnRefusal`, `appendSpendCapNotice`, budget handlers | — | — | S–M |
| f | O-2d | `src/Chat.php`, `src/{SessionTitledMsg,PromptSuggestionMsg}.php`, new `src/Host/TitleService.php` | Chat: `scheduleTitleGeneration`…`sanitizePromptSuggestion` | — | — | S |
| g | P-A3 (+ subcommand-scoped flags), DEF-MODE (D5) | `src/Cli/{Subcommands,Help,ArgvParser,ParsedArgs}.php`, `src/Cli/Bootstrap.php` | BS: `DEFAULT_PERMISSION_MODE`, `permissionGate`. **R-CLI** | README :89/:1304/:1326 (already edited by W2-b; sequential)/:1357, "Subcommands" fence; PERMISSIONS "Setting the mode", :432/:513; ENVIRONMENT `SUGARCRUSH_PERMISSION_MODE`; TROUBLESHOOTING :219; HOOKS :800/:851; AGENTS_AUTHORING :11 | — | M |
| h | X-37a, 2.7-1a | `src/Cli/Bootstrap.php`, `src/Agents/AgentPresetRegistry.php`, `src/Skills/SkillFrontmatter.php`, `src/Commands/CommandSpec.php`, `src/Context/RuleLoader.php`, `src/Providers/{SglangProvider,CustomProvider,OpenAIProvider,VertexProvider,BedrockProvider}.php`, `src/Providers/{ProviderResponseException,ProviderStreamException}.php`, new `src/Support/FrontmatterKeyAudit.php`, `src/Providers/ContextOverflow.php` | BS: `agentRoster`, `skillRegistry`, command-loader feed. SG/CU: error-mapping methods only | AGENTS_AUTHORING "Which fields reach the roster"; SKILLS field table; COMMANDS frontmatter; README :1307; TROUBLESHOOTING overflow | — | M |
| i | N-P1 (D7), DH-CMDS | `src/App/App.php`, `src/Tui/Renderer.php`, `src/Tui/KeyboardHandler.php`, `src/Tui/Components/SettingsPane.php`, `src/Commands/{CommandRegistry,KeyBindingRegistry}.php`, new `src/Commands/Specs/*`, `tools/gen-command-docs.php`, `src/Palette/PaletteAction.php`, `src/Chat.php`, new `src/Tui/Settings/*` | Chat: `dispatchCommand` (made table-driven), `runRootPaletteAction`, `submit` docblock. **R-CMDS, R-KEYBIND** | README slash roster (becomes generated), "Keys", "Pane docking"; COMMANDS table (becomes generated) | candy-forms/fuzzy/focus/mouse, sugar-veil (consume) | L |
| j | 5.1-1, 5.1-2, DH-TOOLS | `src/Runtime.php`, `src/Context/MemoryBlock.php`, `src/Memory/MemoryStore.php`, `src/Chat.php`, `src/Cli/Bootstrap.php`, `src/Permissions/PermissionGate.php`, `src/Hooks/BuiltIn/ProtectFilesHook.php`, new `src/Tools/BuiltIn/MemoryTool.php`, `src/Memory/MemoryWriter.php`, tool catalog | RT: `memorySnapshot`, memory slot. Chat: `memoryAdd`/`memoryImport`. BS: `unfilteredTools`, `memoryStore`. **R-TOOLS** | MEMORY "three tiers", fence, new Memory tool section; PROMPT_ENGINEERING slot 8; README Capabilities Tools (becomes generated); PERMISSIONS "six modes" row (distinct from g's sections) | — | L |

### W4 (order: a, b, c, d, e, f, g, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 1.A-2, 1.C-3 (D6; creates `TurnInbox` + composite) | `src/Backend/EngineBackend.php`, `src/Runtime.php`, `src/Chat.php`, `src/Commands/KeyBindingRegistry.php`, `src/Tui/KeyboardHandler.php`, new `src/Backend/{TurnInbox,CompositeTurnInbox,SocketSteerInbox,QueueMode}.php` | EB: `runTurn` `step-top`/`build`, `completeAsync` (pre-fork memo). RT: `executeToolCalls`, `executeSequentially`. Chat: `submit` busy branch, `enqueuePrompt`, `releaseQueuedPrompts`. **R-KEYBIND** | README "Keys" | — | L |
| b | O-2e | `src/Chat.php`, `src/Compactor.php`, new `src/Host/CompactionService.php` | Chat: compaction regions (`handleCompactCommand`…`withoutParkedSubmission`, `withCompactionOutcome`…`contextTruncatedMessage`) | ARCHITECTURE compaction | — | M–L |
| c | P-B2 (+ per-step stats via `onStep`) | `src/Chat.php`, `src/Renderer.php`, `src/Message.php`, `src/Tools/BuiltIn/TaskTool.php`, new `src/Agents/Live/{AgentLiveState,AgentLiveRegistry}.php`, `src/Tui/AgentActivityLine.php` | Chat: `applyBackendToolEvent` arm, `pumpLiveToolEvents` arm, `route` ToolEventPump arm, `subscriptions` (registry via `WorkspaceContext`; no CS). RN: `renderHistory`, `renderPendingToolCall`, `renderToolResults`, `renderView`, zones. TT: `run` | README "What you see while a turn runs"; TROUBLESHOOTING (pcntl) | — | M |
| d | 3.A-2 | `src/Chat.php`, `src/Session/EnhancedSessionStore.php`, new `src/Commands/Specs/{Undo,Redo,Diff}*.php`, `src/Workspace/CheckpointDiff.php` | Chat: `handleRewindCommand`, new handlers next to it. ESS: `restoreCheckpoint` soft-delete. **R-SCHEMA** | COMMANDS (generated) | — | M |
| e | 0.8b (policy surfaces → always-Ask), 5.8 | `src/Hooks/BuiltIn/ProtectFilesHook.php`, `src/Permissions/{PermissionGate,SafetyClassifier}.php`, `src/Chat.php`, `src/Attachments/FileMentions.php`, `src/Cli/NonInteractive.php`, `src/Messages/UserMessage.php`, `src/AttachmentType.php`, `src/Tools/BuiltIn/WebFetch.php`, `src/Context/EnvironmentBlock.php` (`runGit` → `Workspace/GitRunner`), new `src/Attachments/ContextMentions.php` | Chat: `userTurnMessage` | README "Attachments"; COMMANDS "@file"; PERMISSIONS "hooks that outrank the gate" | — | M |
| f | 5.10, 5.13a | `src/Runtime.php`, `src/Context/Sections/MaximsSection.php`, `src/Providers/{SglangProvider,CustomProvider,ProviderFactory,OpenAIProvider}.php`, new `src/Providers/{ModelFamily,ModelMetadata}.php`, `src/Context/Sections/FamilyPrompt.php` | RT: `basePrompt`. SG: `modelFamily`. CU: `contextWindow`, `costPer1kTokens` | README "Providers"; ENVIRONMENT (metadata opt-out) | — | M |
| g | N-P2 (D9; DH-KEYS: derive `LAYERED_KEYS`) | `src/Config/LayeredSettings.php`, `src/Cli/Bootstrap.php`, `src/Chat.php`, `composer.json` (`sugarcraft/sugar-diff`), new `src/Config/Settings/{SettingsWriter,SettingsTier}.php`, `src/Tui/Settings/{SettingsSavePreview,SettingsSavedMsg}.php` | BS: `selectedModelName`, `backendFor`, `selectedProviderLabel`. Chat: **CS** (`onSettingsWrite`) | SETTINGS :67-69/:91/:196/:517-525/:761; README :191/:219/:738; ENVIRONMENT :35 | sugar-diff (consumer) | M |
| h | 5.3-1, 5.2 | `src/Memory/MemoryStore.php`, `src/Chat.php`, `src/Cli/Bootstrap.php`, new `src/Memory/{MemorySearchIndex,AutoMemoryConsolidator,ConsolidationOp,ConsolidationPlan,SecretRedactor}.php`, `src/MemoryConsolidatedMsg.php` | Chat: `memorySearch`, `route` AssistantMsg arm. BS: `chat` (summary backend pass) | MEMORY "/memory", layout, new "Auto-memory" | — | M–L |
| i | 2.9, 4.3-1 | `src/Context/{CompactorConfig,ContextCompactor}.php`, `src/Chat.php`, `src/Sessions/BackgroundSession.php` | ContextCompactor: threshold methods only (b edits call sites). Chat: `pumpBackgroundSessions`, `dispatchTurn` (auto-dispatch) | README :1112-1115 tiers, :966-968 `/bg` | — | M |
| j | 3.D-1 (JSON hook stdout), 3.E | `src/Hooks/{ScriptHook,HookResult}.php`, `src/Cli/Bootstrap.php`, new `src/Hooks/BuiltIn/PostEditLintHook.php`, `src/Lint/{LintRunner,LintReport}.php` | BS: `hooks`. **R-HOOKS** | HOOKS "exit-code contract", "built-in hooks" | — | M |

### W5 (order: a, b, c, d, e, f, g, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 2.2-1, 2.4-1 | `src/Backend/EngineBackend.php`, `src/Runtime.php`, `src/App/App.php`, `src/Context/ContextCompactor.php`, new `src/Context/Pruning/**`, `src/Context/Compaction/StepSummarizer.php` | EB: `runTurn` `step-top`, `summariseStoppedTurn`. RT: `buildMessages`. ContextCompactor: `removeToolResults`, `stagePairs` | ARCHITECTURE "Runtime" + "Tools"; PROMPT_ENGINEERING placeholder; README :1129-1143 | — | L |
| b | O-2f (exposes `TurnRunner` + `TranscriptProjector`) | `src/Chat.php`, `src/{ToolEventPumpMsg,BackendToolEventsMsg}.php`, new `src/Host/{TurnRunner,TranscriptProjector,SessionEvent}.php` | Chat: `applyBackendToolEvent`…`toolResultMessage`, `scheduleBackendCompletion`, `drainToolEventInbox`. **TR** | ARCHITECTURE "Chat" | — | M–L |
| c | 2.12, 2.5 | `src/Host/CompactionService.php`, `src/Hooks/{HookEvent,HookManager,HookDispatcher,HookRegistry}.php`, `src/Backend/EngineBackend.php`, `src/HistoryCompactedMsg.php`, `src/Context/ContextCompactor.php`, `src/Commands/Specs/Compact*.php`, new `src/Context/Compaction/{StateSummaryTemplate,FilesTouched}.php` | EB: `resolveHookManager`. ContextCompactor: `summarizeExchanges`. **R-HOOKS** | HOOKS event table ("twelve"); PROMPT_ENGINEERING summary prompt | — | M |
| d | O-5a (scaffold; no protocol types) | new `sugar-crush-web/**` skeleton, root `composer.json`, `PROJECT_NAMES.md`, `docs/MATCHUPS.md`, root `README.md` lib table, `docs/index.html`, `docs/_data/sugar-crush-web.*`, `docs/lib/` (generated), `codecov.yml`, `scripts/bootstrap-org-repos.sh`, `.github/workflows/web.yml`, `media/icons/sugar-crush-web.png` | — | root README lib table only | root force-all; sugar-crush-web | M |
| e | 5.6, P-A4 | `src/Runtime.php`, `src/Chat.php`, `src/Renderer.php`, `src/Host/TitleService.php`, `src/Palette/PaletteAction.php`, new `src/Commands/Specs/Context*.php`, `src/Context/ContextBreakdown.php`, `src/Commands/ContextCommand.php` | RT: new `promptSectionSizes`. Chat: **CS** (inline title + `TitleSource` latch), `handleRenameCommand`, `runRootPaletteAction`, `selectSessionTab`. RN: tab strip, `renderInput` | README slash roster (generated); COMMANDS (generated; `/rename`, `/sessions` hints row-scoped) | — | M |
| f | N-P3b, 5.4-2 | `src/Chat.php`, `src/Backend/EngineBackend.php`, `src/Palette/PaletteState.php`, `src/Memory/MemoryHistory.php` (new), `tools/check-child-lifetimes.php` roster | Chat: `handleModelCommand`, `selectPaletteProvider`, `handleMemoryCommand`, `memoryHelpResponse`. EB: ctor + withers (`withModel`, also used by 4.1-1) | README "Choosing a backend"; MEMORY "/memory" | — | M |
| g | 1.C-5, 1.C-4b (`cancel_tool`), P-B3 | `src/Runtime.php`, `src/Backend/EngineBackend.php`, `src/Tools/BuiltIn/TaskTool.php`, `src/Chat.php`, `src/Renderer.php`, `src/Tui/Components/AgentDashboardPane.php`, `src/App/App.php`, `src/Commands/KeyBindingRegistry.php`, `src/Tui/KeyboardHandler.php`, new `src/Tui/AgentStrip.php` | RT: `executeConcurrently` `poll`. EB: `runCompleteInChild`. TT: `setup`. Chat: `route` key arms, `handlePointer` agent arm (strip focus state lives on `App`). RN: `renderView` strip, `renderAgentView`. **R-KEYBIND** | PERMISSIONS (parallel-child caveat); README "Keys" | — | L |
| h | 5.11-1, 5.5-2, 5.5-4 | `src/Permissions/PermissionGate.php`, `src/MCP/McpTool.php`, `src/Tools/McpToolBridge.php`, `src/Cli/Bootstrap.php`, `src/Tools/BuiltIn/Doctor.php`, new `src/RepoMap/CtagsSymbolExtractor.php`, `src/Tools/BuiltIn/RepoMapTool.php` | BS: capability-probe sites | PERMISSIONS "What auto classifies"; README MCP bullet :1311, "Dependency-free shell-out"; MCP.md | — | M |
| i | 4.3-3, 5.14g | `src/Sessions/BackgroundSupervisor.php`, `src/Chat.php`, `src/Cli/Bootstrap.php` | Chat: `init`, `submit` (`!` branch). BS: `chat` wiring. **R-STATE** | ARCHITECTURE "Sessions and state" (bg row); README Limitations, "Using the TUI" (`!cmd`); PERMISSIONS (`!cmd` note) | — | M |
| j | O-3a (+ `suggest: sugarcraft/sugar-crush-web`) | `composer.json` (sugar-crush), `src/Support/ForkedChild.php`, `src/Backend/EngineBackend.php`, `src/Cli/{ParsedArgs,Subcommands,Help,NonInteractive}.php`, new `src/Cli/Serve.php`, `src/Server/**`, `src/Config/Settings/Definitions/Server.php` | EB: `completeAsync` child branch (fd close). **R-CLI** | README "Subcommands"; ENVIRONMENT `SUGARCRUSH_SERVER_*`; new docs/SERVER.md; ARCHITECTURE "Dependencies" | — | L |

### W6 (order: a, b, c, d, e, f, g, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 2.7-1b, 2.7-2, 2.7-3 | `src/Backend/EngineBackend.php`, `src/Runtime.php`, `src/Providers/{CompleteRequest,SglangProvider,VertexProvider,BedrockProvider,ProviderStreamException}.php` | EB: `runTurn` `after-step` + run wrapper. RT: `runStreaming`. SG: request params (not `formatMessages`) | README "What you see" (notice wording) | — | L |
| b | O-2g | `src/Chat.php`, new `src/Host/{TurnController,SessionHost,SessionHub,SubmitOptions,TurnTicket,SessionSnapshot}.php` | Chat: `submit`…`releaseQueuedPrompts`, `userTurnMessage`, `dispatchTurn`, custom-command expansion, turn hooks, `subscriptions` | README "Architecture"; ARCHITECTURE | — | L |
| c | 2.4-2, 5.4-1, 2.3 | `src/Host/CompactionService.php`, `src/Backend/EngineBackend.php`, `src/Cli/{Bootstrap,Help}.php`, new `src/Backend/SummarisesWithCache.php`, `src/Memory/CompactionJournal.php`, `src/Context/Pruning/Strategies/*` (4 new) | CompactionService: `buildSummarizationRequest`, `scheduleParkedCompaction`, `compactNow`, `applyModelCompaction`. EB: new `summariseAsync`. BS: `summaryBackend`, `toollessBackend`. `Help` env-var wording only | README :1129-1143; ENVIRONMENT `SUGARCRUSH_SUMMARY_MODEL` | — | M–L |
| d | 2.2-2, 3.B-2 | `src/Backend/EngineBackend.php`, `src/Host/{TurnRunner,TranscriptStore}.php`, `src/Session/EnhancedSessionStore.php`, `src/Runtime.php`, new `src/Context/Pruning/RefTag.php`, `src/Commands/Specs/{Sweep,Pruning}*.php`, `src/Config/Settings/Definitions/Context.php` | EB: `toTypedMessages`, `encodeEvent`/`decodeEvent`, `runCompleteInChild`, `settleFromResultFrame`. RT: `buildMessages`. **TR, R-SCHEMA** | ARCHITECTURE frame table; COMMANDS/SETTINGS (generated); PROMPT_ENGINEERING ref tags | — | L |
| e | 4.1-1, 4.1-2, P-C1 | `src/Tools/BuiltIn/TaskTool.php`, `src/Backend/EngineBackend.php`, `src/Runtime.php`, `src/Cli/Bootstrap.php`, `src/App/App.php`, `src/Agents/{AgentPreset,Agent,AgentPresetRegistry,AgentManager,SuspendedDelegations}.php`, `src/Permissions/PermissionMode.php`, `src/Host/TranscriptProjector.php`, new `src/Agents/Live/{SubAgentTranscriptLog,AgentTranscriptTail}.php`, `src/Config/Settings/Definitions/Subagents.php` | TT: `schema`, `setup`. EB: `runTurn` `build`, `withReasoningEffort`, `resolveHookManager`. RT: `run`. BS: `agentManager`. **R-STATE** (subagents dir) | AGENTS_AUTHORING intro, provenance; PERMISSIONS (sub-agent mode); ARCHITECTURE "Sessions and state" | — | L |
| f | 5.3-2, 5.5-5 | `src/Runtime.php`, `src/Backend/EngineBackend.php`, `src/Context/{MemoryBlock,RepoMapBlock}.php`, new `src/Memory/{HybridMemoryRanker,EmbeddingCache}.php`, `src/Context/{MemoryRecallBlock,SymbolMapBlock}.php`, `src/Config/Settings/Definitions/Memory.php` | RT: `systemPromptSections` (fragment, repo-map slot), `repoMapSnapshot`. EB: `completeAsync` (pre-fork snapshot) | MEMORY "Recall"; PROMPT_ENGINEERING slots | — | M |
| g | 3.F, 3.G | `src/Cli/Bootstrap.php`, `src/LSP/LspClient.php`, `src/Tools/BuiltIn/{LspTool,Read}.php`, `src/Chat.php`, new `src/LSP/LspLauncher.php`, `src/Hooks/BuiltIn/{PostEditDiagnosticsHook,AutoCommitHook}.php`, `src/Workspace/{AutoCommitter,CommitMessageWriter}.php`, `src/Config/Settings/Definitions/{Lsp,Git}.php` | BS: `lspTool`, `tools` callers, `lspClient`, `hooks`. Chat: `route` AssistantMsg arm, `/undo` handler. **R-HOOKS** | README Capabilities Tools (`Lsp` paragraph); ARCHITECTURE "Tools"; HOOKS built-ins | — | L |
| h | 5.12, 5.13b | `src/Tools/BuiltIn/Bash.php`, `src/Tools/Concerns/CapturesProcessOutput.php`, `src/Providers/ProviderFactory.php`, new `src/Tools/Sandbox/Bubblewrap.php`, `src/Providers/FallbackProvider.php`, `src/Config/Settings/Definitions/Tools.php` | — | PERMISSIONS new "Sandbox"; README "Providers"; ARCHITECTURE provider prose | — | M |
| i | O-4a | `src/Sessions/BackgroundSupervisor.php`, `src/Cli/{Serve,Subcommands,Help,ParsedArgs}.php`, new `src/Support/{Daemonize,PrivateDir}.php`, `src/Server/{StateDir,DiscoveryFile,ParentPidWatchdog}.php`, `docs/examples/sugarcrush.service` | **R-CLI** | README "Subcommands"; ENVIRONMENT server vars; SERVER.md | — | M |
| j | N-P3 | `src/Chat.php`, `src/Host/CompactionService.php`, `src/Cli/Bootstrap.php`, `src/Config/StatusLineCommand.php`, `composer.json` (`sugarcraft/sugar-toast`) | Chat: **CS**, `withBackend`, `mouseMode`, `programOptions`. CompactionService: config setter only. BS: `mergedConfig` | SETTINGS "When a change takes effect"; README "Settings files" | sugar-toast (consumer) | M |

### W7 (order: b, c, a, d, e, f, g, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 3.D-2 (Stop/SubagentStop/SessionEnd dispatch) | `src/Backend/EngineBackend.php`, `src/Hooks/HookManager.php`, `src/Tools/BuiltIn/TaskTool.php`, `src/Agents/EngineExecutor.php`, `bin/sugarcrush`, `src/Cli/NonInteractive.php` | EB: `runTurn` `no-tools`, `resolveHookManager`. TT: `finish`. **R-HOOKS** | HOOKS "Events" dispatch cells | — | M |
| b | O-2h (handlers → `Host/Commands`) | `src/Chat.php`, new `src/Host/Commands/**` | Chat: `dispatchCommand` handlers (permissions, clear, workflow, share, agents, rules, branch, bg/fork, rename, rewind, memory, mcp-auth) | — | — | L |
| c | O-3b, O-3c | new `src/Protocol/**`, `src/Server/Ws/Outbox.php`, `src/Session/EnhancedSessionStore.php`, `docs/protocol/sugarcrush.v1.schema.json`, `scripts/gen-protocol-schema.php` | ESS: paged `session_events` read | SERVER.md; PERMISSIONS "`Ask` needs somewhere" (server sentence) | sugar-mcp (only if a neutral codec is lifted) | L |
| d | 3.B-3 | `src/Backend/EngineBackend.php`, `src/Renderer.php`, new `src/Tools/{MutatesContextLedger.php,BuiltIn/Prune.php}`, `src/Events/ContextLedgerChanged.php` | EB: `turnTools` (bind). RN: `renderHistory` (badge) | README Capabilities (generated) | — | M |
| e | P-D1 | `src/Backend/EngineBackend.php`, `src/Tools/BuiltIn/TaskTool.php`, `src/Agents/Mailbox.php`, `src/Host/WorkspaceContext.php` (HMAC key), new `src/Backend/MailboxTurnInbox.php`, `src/Agents/Live/{AgentInbox,AgentMessage,MessageMode}.php` | EB: `withTurnInbox` wither. TT: `setup`, `run`. **R-STATE** (mailboxes dir) | AGENTS_AUTHORING (direct-message authority); ARCHITECTURE "Sessions and state" | — | M |
| f | P-C2 (view state on `App`, not Chat) | `src/Chat.php`, `src/Renderer.php`, `src/App/App.php`, `src/Tui/{KeyboardHandler,AgentOutputPane}.php`, `src/Commands/{AgentsCommand,KeyBindingRegistry}.php`, new `src/Tui/AgentViewHeader.php`, `src/{OpenAgentViewMsg,CloseAgentViewMsg}.php` | Chat: `handlePointer` agent arms, `route` Escape arm, `subscriptions`. RN: `renderView` body. **R-KEYBIND** | README Limitations :1359, "Keys" | — | M |
| g | 3.I-2 | `src/Runtime.php`, `src/Backend/EngineBackend.php`, `src/Tools/BuiltIn/{Read,Edit,Write}.php`, new `src/Tools/ReadLedger.php` | RT: `runToolInChild`, `collectChildResult`. EB: `runCompleteInChild` result frame, `settleFromResultFrame` | PROMPT_ENGINEERING (notice) | — | S–M |
| h | 3.C | `src/Host/{TurnRunner,TranscriptStore}.php`, `src/Backend/EngineBackend.php`, `src/Tui/{Pane.php,Renderer.php,Components/MenuBar.php}`, new `src/Tools/BuiltIn/Todo.php`, `src/Todo/*`, `src/Events/TodoUpdated.php`, `src/Tui/Components/TodoPane.php` | EB: `encodeEvent`/`decodeEvent`. **TR** | README "Pane docking"; Capabilities (generated) | — | M |
| i | 5.4-3 | `src/Chat.php`, new `src/Memory/DreamPass.php`, `src/DreamPassCompletedMsg.php` | Chat: `route` AssistantMsg arm (restricted tools via `EngineBackend::withTools`, no `turnTools` edit) | MEMORY new "Dream pass" | — | M |
| j | N-P4a | `src/Backend/EngineBackend.php`, `src/Cli/Bootstrap.php`, `src/Providers/{TransientFailure,CustomProvider}.php`, `src/Providers/Concerns/HttpClientDefaults.php`, `src/Config/Settings/Definitions/Engine.php` | EB: `completeAsync` (idle timeout resolved pre-fork), `runTurn` `build` (`userConfig`), parallel-deadline ceiling. BS: `resolvedMaxToolSteps` | SETTINGS (generated); ENVIRONMENT `SUGARCRUSH_CONNECT_TIMEOUT` | — | M |

### W8 (order: a, b, c, d, e, f, g, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 3.B-4 | `src/Backend/EngineBackend.php`, `src/Host/Commands/Compact*.php`, `src/Renderer.php`, new `src/Tools/BuiltIn/Compress.php`, `src/Context/Pruning/NudgePolicy.php`, `src/Commands/Specs/{Compress,Decompress,Recompress}*.php` | EB: `runTurn` `step-top`. RN: collapsed block row, status bar | PROMPT_ENGINEERING reminder text | sugar-veil (modal) | L |
| b | 2.10 | `src/Host/{CompactionService,TurnController}.php`, `src/Chat.php`, `src/HistoryCompactedMsg.php`, new `src/Context/Compaction/HistoryFingerprint.php` | CompactionService: `applyModelCompaction`, `buildSummarizationRequest`. TC: `submit` tier block, `dispatchTurn` reminder. Chat: `route` HistoryCompacted arm | README :1112-1117 tiers | — | M |
| c | O-5b | `sugar-crush-web/src-web/**`, `sugar-crush-web/e2e/**`, `sugar-crush-web/dist/**`, `src/Providers/EchoProvider.php` | — | sugar-crush-web README; SERVER.md "Web UI" | sugar-crush-web | L |
| d | O-8a, O-7 | `src/Cli/{Attach,ParsedArgs,Subcommands,Help}.php`, `src/Cli/Bootstrap.php`, `src/Chat.php`, `tools/check-child-lifetimes.php`, new `src/Backend/RemoteBackend.php`, `src/Host/RemoteSessionHost.php`, `src/Server/Workspace/**` | BS: `openSession`. Chat: `relockedForCurrentSession`. **R-CLI** | README "Subcommands"; SERVER.md | — | L |
| e | 4.3-2, 4.7-3 | `src/Tools/BuiltIn/TaskTool.php`, `src/Sessions/{BackgroundSupervisor,BackgroundSessionRunner}.php`, `src/Support/Daemonize.php`, `src/Cli/Bootstrap.php`, `src/Runtime.php`, `src/Backend/EngineBackend.php`, `src/Events/SubAgentActivity.php`, `src/Host/TurnRunner.php` | TT: `schema`, `execute`, `setup`. RT: `executeConcurrently` `fork` (admission). EB: `turnTools`. BS: `tools` (supervisor bind). **TR** | AGENTS_AUTHORING `/bg` paragraph; ARCHITECTURE :456; README :1361/:1275 | — | L |
| f | 4.10-2 | `src/Workflows/{WorkflowEngine,WorkflowRegistry}.php`, new `src/Tools/BuiltIn/WorkflowTool.php` | — | WORKFLOWS (model-authored plans); AGENTS_AUTHORING workflow bullet | — | M |
| g | O-4b (calls `reconnect()`; no edit to `BackgroundSupervisor`) | `src/Chat.php`, new `src/Host/BackgroundEvents.php`, `src/Protocol/Methods/BgMethods.php` | Chat: `pumpBackgroundSessions` | SERVER.md `bg.*` | — | S–M |
| h | P-D2, P-D3 | `src/Chat.php`, `src/Renderer.php`, `src/App/App.php`, `src/Tui/KeyboardHandler.php`, `src/Commands/KeyBindingRegistry.php`, `src/Tools/BuiltIn/TaskTool.php`, `src/Agents/AgentManager.php`, `src/Message.php`, new `src/Host/AgentResume.php`, `src/{AgentMessageSentMsg,AgentControlMsg}.php` | Chat: `submit` delegator (route to `AgentInbox`), `route` AgentControlMsg arm. RN: `renderInput` placeholder. TT: `run` (`onProgress` control). **R-KEYBIND** | README Limitations (inert-commands bullet), "Keys", "What you see"; AGENTS_AUTHORING | — | L |
| i | 3.H | new `src/Hooks/BuiltIn/AutoTestHook.php`, `src/Lint/TestRunner.php`, `src/Cli/Bootstrap.php`, `src/Config/Settings/Definitions/Tools.php` | BS: `hooks`. **R-HOOKS** | HOOKS built-ins | — | M |
| j | 3.D-3 (`/goal`, `/grind`), 5.14a, 5.14b | `src/Chat.php`, `src/Host/TurnController.php`, new `src/Goal/GoalJudge.php`, `src/GoalJudgedMsg.php`, `src/Commands/Specs/{Goal,Grind,Btw}*.php`, `src/Config/Settings/Definitions/Ui.php` | Chat: `route` AssistantMsg arm, `requestPermission` (bell). TC: `refuseWhileInFlight` | COMMANDS/README slash roster (generated) | — | M |

### W9 (order: a, b, c, d, e, f, g, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 2.6, 4.7-2 (enabled in `completeTranscript`, no TaskTool edit), 2.11 | `src/Backend/EngineBackend.php`, `src/Host/CompactionService.php`, `src/Context/TurnContextBlock.php`, `src/Tools/BuiltIn/SkillTool.php`, new `src/Context/Compaction/ReinjectionPlan.php` | EB: `runTurn` `step-top`/`after-step`, `completeTranscript`. CompactionService: `compactionChanges` flag, `scheduleParkedCompaction` | MEMORY flush section; README "The agent loop" | — | L |
| b | 3.B-5, 4.9 | `src/Tools/BuiltIn/TaskTool.php`, `src/Backend/EngineBackend.php`, `src/Agents/{SuspendedDelegations,SubAgent}.php`, `src/Compactor.php`, `src/Chat.php`, `src/Cli/Bootstrap.php`, new `src/Tools/BuiltIn/Recall.php` | TT: `setup`, `finish`. EB: `observeCacheHealth`, `withWorktreeRoot`. Chat: `scheduleBackgroundSpawn`. BS: `tools` (WorktreeManager). **R-STATE** (worktrees) | AGENTS_AUTHORING "Teams and worktrees"; README Limitations | — | L |
| c | O-6a | `src/Protocol/**` (narration, `client.viewing`), `sugar-crush-web/src-web/{components/grid,components/approvals,stores/layout,stores/approvals}/**` | — | SERVER.md | sugar-crush-web | L |
| d | O-6b | new `src/Protocol/Methods/SettingsMethods.php`, `sugar-crush-web/src-web/{components/settings,stores/settings}/**` | — | SETTINGS producers (`ConfigWriteProducerDocumentationDriftTest`) | sugar-crush-web | M |
| e | O-6c | new `src/Protocol/Methods/{Agents,Workflow,Memory}Methods.php`, `sugar-crush-web/src-web/{components/agents,components/panels,stores/agents}/**` | — | SERVER.md | sugar-crush-web | L |
| f | P-E1, P-E2 | `src/Runtime.php`, `src/Backend/EngineBackend.php`, `src/Chat.php`, `src/Renderer.php` | RT: `executeConcurrently` `poll` (`agent_cancel`). EB: `runCompleteInChild`. Chat: `requestPermission`/`answerPermission` (origin). RN: agent-view body | ARCHITECTURE frame table; PERMISSIONS | — | M |
| g | 4.6-2 | `src/Agents/{TeamManager,Team}.php`, `src/Cli/Bootstrap.php`, new `src/Tools/BuiltIn/TeamTool.php` | BS: `agentManager` | AGENTS_AUTHORING "Teams and worktrees" (team paragraph only; b owns the worktree paragraph) | — | M–L |
| h | N-P4b, N-P4d | `src/Context/{CompactorConfig,IdleCompactionPolicy,MemoryBlock,ProjectMemoryWriter,RepoMapBlock,EnvironmentBlock}.php`, `src/Skills/SkillPathNudge.php`, `src/Runtime.php`, `src/Cli/Bootstrap.php`, `src/Backend/EngineBackend.php`, `src/Config/Settings/Definitions/{Compaction,Memory}.php` | RT: `memorySnapshot` + standing-rule constants. BS: `chat` (CompactorConfig), launch-notice constants. EB: `withCompactorConfig` | MEMORY caps; PROMPT_ENGINEERING; SETTINGS (generated) | — | M–L |
| i | N-P4c, N-P4e, N-P4f | `src/Tools/Concerns/TruncatesOutput.php`, `src/Tools/BuiltIn/{Read,Glob,WebFetch,WebSearch}.php`, `src/Commands/CommandSpec.php`, `src/Hooks/ScriptHook.php`, `src/Tools/McpToolBridge.php`, `src/Agents/{EngineExecutor,AgentPoolConfig}.php`, `src/Config/Settings/Definitions/{Tools,Subagents}.php` | EB: `turnTools` (caps + Task binding; no Bootstrap edit). TT: `DEFAULT_MAX_TURNS` constant only. Chat: tool-timeout constant. `CapturesProcessOutput`: idle-ceiling constant only. BS: `agentPoolConfig` | ENVIRONMENT `SUGARCRUSH_SEARCH_ENDPOINT`; AGENTS_AUTHORING `maxTurns` | — | M–L |
| j | 5.7-1 (D8), N-P4g | `src/Permissions/{PermissionGate,PermissionMode}.php`, `src/Runtime.php`, `src/Chat.php`, `src/Renderer.php`, `src/Commands/KeyBindingRegistry.php`, `src/Tui/KeyboardHandler.php`, `src/Agents/AgentManager.php`, `src/Session/EnhancedSessionStore.php`, `src/Config/StatusLineCommand.php`, new `src/Context/Sections/PlanModeSection.php`, `src/PermissionModeToggledMsg.php`, `src/Config/Settings/Definitions/Ui.php` | RT: `systemPromptSections` (plan section). Chat: `permissionGate` toggle, UI constants, `mouseMode`. RN: status-bar badge, `DIFF_MAX_ROWS`. **R-KEYBIND** | PERMISSIONS "six modes", "Setting the mode"; README "Keys"; ENVIRONMENT (`_DISABLE_MOUSE` etc. settings-key cells) | — | L |

### W10 (order: a … j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 3.I-3, 5.11-2 | `src/Permissions/PermissionGate.php`, `src/Hooks/BuiltIn/ProtectFilesHook.php`, `src/Renderer.php`, `src/Cli/Bootstrap.php`, new `src/Tools/BuiltIn/ApplyPatch.php`, `src/Tools/Edit/PatchParser.php`, `src/Permissions/{ExecReviewer,ReviewVerdict}.php`, `src/Config/Settings/Definitions/Permissions.php` | BS: `permissionGate` | PERMISSIONS write-capable list, "What auto classifies", circuit breaker; HOOKS protect-files path table | — | L |
| b | 5.7-2 | new `src/Tools/BuiltIn/{PlanExitTool,AskUserTool}.php`, `src/Cli/NonInteractive.php` | — | PERMISSIONS "`Ask` needs somewhere to ask" | — | M |
| c | 4.5 | `src/Runtime.php`, `src/Tools/BuiltIn/TaskTool.php` (new `withBoard` method), new `src/Agents/Board/**`, `src/Tools/BuiltIn/{BoardReadTool,BoardPostTool}.php`, `src/Hooks/BuiltIn/BoardNoticeHook.php`, `src/Tools/SharesBoard.php`, `src/Cli/Bootstrap.php` | RT: `executeConcurrently` `ledger`. TT: class (new method only). BS: `hooks` (BoardNoticeHook). **R-STATE** (board dir), **R-HOOKS** | ARCHITECTURE "Sessions and state" | — | M |
| d | P-E3 | `src/Chat.php`, `src/Tools/BuiltIn/TaskTool.php`, `src/Commands/KeyBindingRegistry.php` | Chat: `route` Ctrl+X b arm. TT: `execute`. **R-KEYBIND** | README "Keys" | — | M |
| e | 5.14c, 5.14d | new `src/Host/Commands/{Handoff,NewRule}*.php`, `src/Commands/Specs/{Handoff,NewRule}*.php`, `src/Commands/RulesCommand.php`, `src/Chat.php` | Chat: `handlePaletteNewSession` | COMMANDS "`/rules`" | — | M |
| f | 5.14i, 5.14l | `src/Chat.php`, `src/Host/TurnController.php`, `src/Skills/SkillRegistry.php`, new `src/Support/AiCommentWatcher.php`, `src/Skills/SkillMentions.php` | Chat: `subscriptions`, `completeMention`. TC: `userTurnMessage` | SKILLS "Invoking a skill"; SETTINGS (generated) | — | M |
| g | N-P5 | `src/Tui/Settings/**`, `src/Config/Settings/SettingsWriter.php` | — | README "Settings files" (project-shared tier) | — | S |
| h | 5.9-1, 5.9-2 (D12) | `src/Cli/{ParsedArgs,Subcommands,Help}.php`, `src/McpMessage.php`, `src/ToolResult.php`, new `src/Acp/**`, `src/Cli/Acp.php` | **R-CLI** | README "Subcommands"; SERVER.md "ACP" | — | L |
| i | LIVE-0.1, LIVE-X31b, LIVE-A15b, LIVE-A15v, LIVE-A21b, LIVE-CH, 15b-14-1 | new `scripts/provider-cache-live-probe.php`, new `src/Lang.php`, `lang/en.php`, `tests/LangParityTest.php`, root `LOCALES.md` | — | — | candy-core I18n (use) | M |
| j | 4.4 | `src/Tools/BuiltIn/TaskTool.php`, new `src/Tools/BuiltIn/{SendMessageTool,SubagentsTool,InterruptAgentTool}.php` | TT: `setup` (reply-tool filter) | AGENTS_AUTHORING; README Capabilities (generated) | — | M–L |

LIVE-0.1 and LIVE-X31b need no code. The W2 integrator may run them early: `cached_tokens` on skynet2 with `--enable-cache-report`, and one tool-calling `anthropic` request.

LIVE-A15v and LIVE-A21b need GCP credentials, and this host has none. If credentials are still missing, the group records them as blocked.

### W11: i18n (order: a, b, c, d)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 15b-14-2 (CLI) | `src/Cli/**`, `src/Cli/Bootstrap.php` (launch notices) | — | — | — | M |
| b | 15b-14-3 (registries) | `src/Commands/**` (specs, KeyBindingRegistry) | — | generated docs pinned to `en` | — | S–M |
| c | 15b-14-4a (Chat + Host) | `src/Chat.php`, `src/Host/**` | — | — | — | L |
| d | 15b-14-4b (TUI) | `src/Renderer.php`, `src/App/App.php`, `src/Tui/**`, `src/Palette/**` | — | — | — | L |

### Final (one agent, after W11)

1. Serial full runs:
   - sugar-crush with `--log-junit`;
   - every lib any wave touched: sugar-mcp, sugar-crush-web, plus candy-core if O-0(d) was adopted.
2. Regenerate the durations TSV with `scripts/parallel-tests.sh --junit … --out …`, then copy `durations.tsv` into place.
3. Re-pin `tests/Config/Support/suite-figure.json` and the README test-count headline.
4. Run `scripts/parallel-tests.sh 8 --durations scripts/parallel-tests-durations.tsv --against-json tests/Config/Support/suite-figure.json`.
5. Run the tools gates (§1.5) and every doc generator in `--check` mode.
6. Report cleanup:
   - delete the emptied sections from Part III/V/VI and from N/O/P;
   - delete this appendix's wave tables once they are empty;
   - reassemble.
7. Push.

## 5. Step index (194 steps)

Fields are: ID · size · depends on (besides same-region predecessors) · wave-group.

**Wave-0 fixes**
- 0.1 S · — · W1-g
- 0.2 S · — · W1-a
- 0.3 S · — · W1-g
- 0.4-a S · — · W1-d
- 0.4-b S–M · 0.4-a · W1-d
- 0.5 S–M · D2 · W1-d
- 0.6 S–M · D4 · W1-h
- 0.7 S · — · W1-e
- 0.8 S · — · W1-g
- 0.8b S · 1.C-2, DEF-MODE · W4-e
- 0.10 S · — · W1-a
- 0.11 S–M · 0.12 · W1-e
- 0.12 S–M · 0.7 · W1-e
- 0.13-a S · — · W1-a
- 0.13-b S · — · W1-a
- 0.14-a S · — · W1-g
- 0.14-b S · D3 · W1-d
- 0.14-c S · — · W1-g
- 0.15 S · 0.14-a · W1-g
- 0.16 S · D10 · W1-a
- X-30 S · — · W1-i
- X-31a S–M · — · W1-b
- X-31b S · — · W1-b
- X-35a S · — · W1-h
- X-37a M · — · W3-h

**Foundations**
- 1.A-1 M · 0.1 · W2-c
- 1.A-2 M · 1.A-1, 1.B-2 · W4-a
- 1.B-1 S–M · — · W1-b
- 1.B-2 M · 1.B-1 · W2-a
- 1.B-3 M · 1.B-2 · W3-c
- 1.C-1 M · D1 · W1-c
- 1.C-2 M · 1.C-1 · W2-b
- 1.C-3 M · 1.C-1 · W4-a
- 1.C-4a S–M · 1.C-1 · W3-a
- 1.C-4b S · 1.C-1, RELAY · W5-g
- 1.C-5 S–M · 1.C-2, RELAY · W5-g
- RELAY M · 0.16 · W3-b
- DEF-MODE S/M · 1.C-2, 3.A-1, D5 · W3-g

**Context engine**
- 2.1 M · — · W3-a
- 2.2-1 M · 2.1, 0.2 · W5-a
- 2.2-2 M · 2.2-1, 1.B-2 · W6-d
- 2.3 S–M · 2.2-1 · W6-c
- 2.4-1 M · 2.2-1 · W5-a
- 2.4-2 M · 2.4-1, O-2e · W6-c
- 2.5 S–M · 1.B-3, O-2e · W5-c
- 2.6 M · 1.A-2, 2.5 · W9-a
- 2.7-1a S · — · W3-h
- 2.7-1b M · 2.7-1a, 2.4-1 · W6-a
- 2.7-2 M · 2.7-1b · W6-a
- 2.7-3 S · 2.7-2 · W6-a
- 2.8 S–M · 0.4, 0.5, 0.12 · W2-i
- 2.9 S · 2.1 · W4-i
- 2.10 M · 2.9, 2.5, O-2g · W8-b
- 2.11 S–M · 5.1-2, 2.4-1, 2.12 · W9-a
- 2.12 S–M · 1.B-3, O-2e · W5-c

**Safety and self-management**
- 3.A-1 M · — · W2-h
- 3.A-2 M · 3.A-1, DH-CMDS · W4-d
- 3.B-2 S–M · 2.2-2, 2.3 · W6-d
- 3.B-3 M · 3.B-2, 1.C-1, 2.12 · W7-d
- 3.B-4 L · 3.B-3 · W8-a
- 3.B-5 S–M · 3.B-4 · W9-b
- 3.C S–M · 1.A-2, O-2f · W7-h
- 3.D-1 S–M · — · W4-j
- 3.D-2 M · 3.D-1, 2.12 · W7-a
- 3.D-3 S–M · 3.D-2 · W8-j
- 3.E S–M · — · W4-j
- 3.F M · — · W6-g
- 3.G M · 3.A-1, 0.3 · W6-g
- 3.H M · 3.D-2 · W8-i
- 3.I-1 M · 0.11 · W2-i
- 3.I-2 S–M · 3.I-1, 1.B-2 · W7-g
- 3.I-3 M · 3.I-1, DH-TOOLS · W10-a

**Sub-agents**
- 4.1-1 S–M · N-P0, N-P3b · W6-e
- 4.1-2 M · 4.1-1, 4.2 · W6-e
- 4.2 M · — · W2-g
- 4.3-1 M · X-30 · W4-i
- 4.3-2 M–L · 4.3-1, P-B1, 1.A-2 · W8-e
- 4.3-3 M · 4.3-1 · W5-i
- 4.4 M–L · P-D1, P-D3, 4.3-2, 4.7-1 · W10-j
- 4.5 M · RELAY · W10-c
- 4.6-1 S–M · — · W1-i
- 4.6-2 M–L · 4.6-1, 4.3-2, P-D1 · W9-g
- 4.7-1 S · — · W2-g
- 4.7-2 S–M · 1.C-4a · W9-a
- 4.7-3 M · 4.1-1 · W8-e
- 4.9 M · 4.1-1, 4.3-2 · W9-b
- 4.10-1 S–M · — · W1-i
- 4.10-2 M · 4.10-1, 4.2, RELAY · W8-f

**Memory, codebase understanding, UX, integrations**
- 5.1-1 S–M · 0.6, 1.A-1 · W3-j
- 5.1-2 M · 5.1-1 · W3-j
- 5.2 M · 5.1-2 · W4-h
- 5.3-1 M · 5.1-2 · W4-h
- 5.3-2 M · 5.3-1, 1.A-2 · W6-f
- 5.4-1 S · 5.1-2, O-2e · W6-c
- 5.4-2 M · 5.1-2 · W5-f
- 5.4-3 M · 5.4-1, 5.4-2, 5.2 · W7-i
- 5.5-1 M · — · W1-j
- 5.5-2 S–M · 5.5-1 · W5-h
- 5.5-3 M · 5.5-1 · W2-j
- 5.5-4 S · 5.5-3, DH-TOOLS · W5-h
- 5.5-5 S · 5.5-4, 1.A-2 · W6-f
- 5.6 S · 2.1, DH-CMDS · W5-e
- 5.7-1 M · 4.1-2, DEF-MODE, D8 · W9-j
- 5.7-2 M · 5.7-1, 1.C-2 · W10-b
- 5.8 S–M · 3.A-1 (GitRunner) · W4-e
- 5.9-1 M · — · W10-h
- 5.9-2 M · 5.9-1, O-2g · W10-h
- 5.10 S–M · 1.A-1 · W4-f
- 5.11-1 S · — · W5-h
- 5.11-2 M · 1.C-2, N-P2 · W10-a
- 5.12 M · 0.4 · W6-h
- 5.13a M · N-P0 · W4-f
- 5.13b M · 2.7-1a, N-P3b · W6-h
- 5.14a S · 1.C-2 · W8-j
- 5.14b S–M · O-2g · W8-j
- 5.14c M · 2.5, P-A1 · W10-e
- 5.14d S · 0.8 · W10-e
- 5.14e S · — · W2-j
- 5.14g S–M · — · W5-i
- 5.14h S · — · W2-j
- 5.14i M · 1.A-2 · W10-f
- 5.14j S–M · — · W2-j
- 5.14k S · — · W1-j
- 5.14l S–M · O-2g · W10-f
- (5.14f = X-35a)

**Settings**
- N-P0-1 S · — · W2-d
- N-P0-2 S · N-P0-1 · W2-d
- N-DOC-1 S · — · W1-j
- N-DOC-2 S · N-P0-1 · W2-d
- N-DOC-3 S · — · W1-j
- DH-KEYS S–M · N-P0-2 (generated docs), N-P2 (derived keys) · W2-d / W4-g
- N-P1 M · N-P0-1, D7 · W3-i
- DH-CMDS M · — · W3-i
- N-P2 M · N-P0, N-P1, D9 · W4-g
- N-P3a S · — · W1-h
- N-P3b S–M · N-P2, N-P3a · W5-f
- N-P3 M · N-P2, N-P3a · W6-j
- N-P4a M · N-P3, 1.C-1 · W7-j
- N-P4b M · N-P3, 2.1, 2.9 · W9-h
- N-P4c M · N-P3, 0.4, 2.8 · W9-i
- N-P4d M · N-P3, 5.1, 1.A · W9-h
- N-P4e S · 0.3 · W9-i
- N-P4f S–M · N-P3, 4.1, 4.7-3 · W9-i
- N-P4g M · N-P3, DEF-MODE · W9-j
- N-P5 S · N-P1, N-P2, N-P3 · W10-g

**Sessions and agent view**
- B1 S · P-A1 · W1-f
- B2 S · — · W1-f
- B3 S · P-A1 · W1-f
- P-A1 M · — · W1-f
- P-A2 M · P-A1 · W2-f
- P-A3 S–M · P-A1 · W3-g
- P-A4 S–M · P-A1, O-2d · W5-e
- P-B1 M · — · W3-b
- P-B2 M · P-B1, 1.C-4a · W4-c
- P-B3 S–M · P-B2 · W5-g
- P-C1 M · P-B1, RELAY, O-2f · W6-e
- P-C2 M · P-C1 · W7-f
- P-D1 M · P-B1, 1.C-3 · W7-e
- P-D2 M · P-D1, P-C2 · W8-h
- P-D3 M · P-D1, P-C2 · W8-h
- P-E1 S–M · P-D3, RELAY · W9-f
- P-E2 S · 1.C-5, P-C2 · W9-f
- P-E3 M · 4.3-2, P-D3 · W10-d

**Server and web**
- O-0 S · — · W1-j
- O-2a M · 1.B-1, N-P3a · W2-e
- O-2b M · O-2a, P-A1 · W3-d
- O-2c S–M · O-2a · W3-e
- O-2d S · O-2a, B2 · W3-f
- O-2e M–L · O-2c, 1.B-3 · W4-b
- O-2f M–L · O-2b, 1.C-2, 1.C-4a · W5-b
- O-2g L · O-2f · W6-b
- O-2h L · O-2g, DH-CMDS · W7-b
- O-3a L · O-0, P-A3 · W5-j
- O-3b L · O-3a, O-2g · W7-c
- O-3c S–M · O-3b · W7-c
- O-4a M · O-3a · W6-i
- O-4b S–M · O-4a, O-3b, 4.3-3 · W8-g
- O-5a M · — · W5-d
- O-5b L · O-5a, O-3c · W8-c
- O-6a L · O-5b · W9-c
- O-6b M · O-5b, N-P2 · W9-d
- O-6c L · O-5b, P-B2, 4.3-2, 3.C · W9-e
- O-7 M–L · O-4a, O-3b · W8-d
- O-8a M · O-3b, O-2g · W8-d

**Deferred and live checks**
- 15b-14-1 S · — · W10-i
- 15b-14-2 M · all roadmap steps · W11-a
- 15b-14-3 S–M · all roadmap steps · W11-b
- 15b-14-4a L · all roadmap steps · W11-c
- 15b-14-4b L · all roadmap steps · W11-d
- LIVE-0.1, LIVE-X31b, LIVE-A15b, LIVE-A15v, LIVE-A21b, LIVE-CH · W10-i

**Subsumed (no separate work):**
- 3.B-1 is 1.B-1 + 1.B-2 + 0.2 (RefTag is in 3.B-2).
- 4.8 is P-A1 + P-C1 + P-D2 + P-D3 + P-E3.
- O-1 is 1.C (O-1x is folded into 1.C-1/1.C-4a).
- O-8b is 5.9.
- X-35b is N-P4e.
- X-35c is 3.F.
- X-37b is N-P0-1.
- 4.6's inert shell commands are P-D3.

## 6. Stale roadmap claims found during impact research

| Claim | Reality |
|---|---|
| 5.8 "wire the dormant `Message::attachFile()`" | Attachments (`@file`, image paste, drag-drop) are LIVE. Only `@diff`/`@session`/`@url` remain, and the wire uses `<file path>` blocks, not `<context>`. |
| 13-settings "maxToolSteps default 8", "sub-agent max turns 50" | 1000 and 200 respectively. |
| V.1 / Part II #35 "WebSearch private default host", "no default endpoint" as a defect | No default endpoint by design (audit F-W3(b)); only the settings key remains (N-P4e). |
| VI / 0.13 "wire the session-affinity header", "show cached %" | Trait and readout exist. The header is unwired, and the readout is unreachable on OpenAI-shaped providers (no cache-write count). |
| 0.4 "SIGTERM→SIGKILL the setsid group" | Built (`runCaptured` timeout, `terminateGroup`). Only the Bash parameter and the sequential heartbeat remain. |
| 0.3 "move the cadence into AGENTS.md" | It is already there; the work is deleting it from `Bash::promptGuidance`. |
| 0.8, 0.14 ".env*"/"settings protection" as all-new | `.env*` and `.sugar-crush/{hooks.yaml,config.json,agents/}` are already protected. |
| 1.B "give Message `uiOnly`" | Done (`Message::$uiOnly`, `agentVisible()`). |
| 1.C "always-allow", "rejection feedback" as new | Exact-call session grants and `HookManager::resolveAsk($feedback)` exist; pattern grants and the widened verdict type are new. |
| 3.C "dormant `TaskList`" | `TaskList` is the team queue; only `SessionMeta::$tasks` fits a todo. |
| 3.D JSON hook stdout as wholly new | Exit-code equivalents and `refusedBy` exist (3.D is PARTIAL). |
| 4.3 "wire `reconnect()`" | Wiring alone is a no-op: it reads in-memory sessions and the IPC dir is per-process random (4.3-3). |
| 4.10 "fix only the first task of a stage runs" | Intentional and unreachable from YAML; it is a prerequisite only for multi-task plans (4.10-1). |
| 5.1 "index ≤200 lines/25 KB" as new | `MemoryStore` already builds that index; only injection is missing. |
| 5.6 status bar `ctx % · tokens · cache % · $` | Done; only `/context` and the system-prompt + tools basis (2.1) remain. |
| 5.7 "command guard", "Shift+Tab toggle" | The guard exists (`evaluatePlan`). Shift+Tab is `shell.pane-prev` (D8). |
| 5.11 "escalate after 3 denials" | Exists (`STRIKE_THRESHOLD`). |
| 5.14 `$skill` as wholly new | A session-scoped equivalent exists (Ctrl+S picker). |
| LIVE-CH "one reply" | `observeCacheHealth` needs 3 consecutive zero reports. |
| DCP §13.1 "`observeCacheHealth` dormant", "Task runs ≤50 steps with no context management" | Wired per step; Task runs ≤200 steps through `runTurn`, so 2.1/2.2-1/2.4-1 cover sub-agents. |
| O §4.8 "`session_leases` table" | Superseded by the existing flock `SessionLock`. |
| O §4.2 "`/workflow resume` runs synchronously" | Fixed (Fiber). |
| O §6.10 `SettingsRegistry` vs N `SettingsSchema` | One class: `SettingsSchema` (D11). |
| README :966-968 "`/bg` result comes back"; :1307 "`context: fork` enforced"; :1139-1143 "85% tier is heuristic-only" | All three are false today; fixed by 4.3-1, X-37a and 2.4-2 respectively. |

**New defects found during research:**
- The default `anthropic` base URL lacks `/v1` (X-31b).
- Engine-path hooks receive an empty `sessionId` (fixed by 0.13-a).
- A preset `Bash(git *)` grant does not restrict Bash on the live Task path (4.2, a security gap).
