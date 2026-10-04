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
  | **R-CMDS** | `CommandRegistry::all`, `docs/COMMANDS.md` built-in table, README slash roster, `Chat::dispatchCommand` arms | W3 | **DH-CMDS** (W3): one spec file per command under `builtin-commands/` (glob-discovered, sorted), table-driven dispatch, COMMANDS.md table + README roster generated by `tools/gen-command-docs.php`. Changing an existing command's hint or description is row-scoped and always shareable. |
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

### W5 (order: a, b, c, d, e, f, g, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| d | O-5a (scaffold; no protocol types) | new `sugar-crush-web/**` skeleton, root `composer.json`, `PROJECT_NAMES.md`, `docs/MATCHUPS.md`, root `README.md` lib table, `docs/index.html`, `docs/_data/sugar-crush-web.*`, `docs/lib/` (generated), `codecov.yml`, `scripts/bootstrap-org-repos.sh`, `.github/workflows/web.yml`, `media/icons/sugar-crush-web.png` | — | root README lib table only | root force-all; sugar-crush-web | M |

### W6 (order: a, b, c, d, e, f, g, h, i, j)

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| a | 2.7-1b, 2.7-2, 2.7-3 | `src/Backend/EngineBackend.php`, `src/Runtime.php`, `src/Providers/{CompleteRequest,SglangProvider,VertexProvider,BedrockProvider,ProviderStreamException}.php` | EB: `runTurn` `after-step` + run wrapper. RT: `runStreaming`. SG: request params (not `formatMessages`) | README "What you see" (notice wording) | — | L |
| b | O-2g | `src/Chat.php`, new `src/Host/{TurnController,SessionHost,SessionHub,SubmitOptions,TurnTicket,SessionSnapshot}.php` | Chat: `submit`…`releaseQueuedPrompts`, `userTurnMessage`, `dispatchTurn`, custom-command expansion, turn hooks, `subscriptions` | README "Architecture"; ARCHITECTURE | — | L |
| c | 2.4-2, 5.4-1, 2.3 | `src/Host/CompactionService.php`, `src/Backend/EngineBackend.php`, `src/Cli/{Bootstrap,Help}.php`, new `src/Backend/SummarisesWithCache.php`, `src/Memory/CompactionJournal.php`, `src/Context/Pruning/Strategies/*` (4 new) | CompactionService: `buildSummarizationRequest`, `scheduleParkedCompaction`, `compactNow`, `applyModelCompaction`. EB: new `summariseAsync`. BS: `summaryBackend`, `toollessBackend`. `Help` env-var wording only | README :1129-1143; ENVIRONMENT `SUGARCRUSH_SUMMARY_MODEL` | — | M–L |
| d | 2.2-2, 3.B-2, 5.6 remainder (pruned rows in the `/context` breakdown) | `src/Backend/EngineBackend.php`, `src/Context/ContextBreakdown.php`, `src/Commands/ContextCommand.php`, `src/Host/{TurnRunner,TranscriptStore}.php`, `src/Session/EnhancedSessionStore.php`, `src/Runtime.php`, new `src/Context/Pruning/RefTag.php`, `builtin-commands/NNNN-{sweep,pruning}.php`, `src/Config/Settings/Definitions/Context.php` | EB: `toTypedMessages`, `encodeEvent`/`decodeEvent`, `runCompleteInChild`, `settleFromResultFrame`. RT: `buildMessages`. **TR, R-SCHEMA** | ARCHITECTURE frame table; COMMANDS/SETTINGS (generated); PROMPT_ENGINEERING ref tags | — | L |
| e | 4.1-1 (+ N-P3b remainder: rebind the provider's model so `withModel()` also moves `contextWindow()`/prices), 4.1-2, P-C1 | `src/Tools/BuiltIn/TaskTool.php`, `src/Backend/EngineBackend.php`, `src/Runtime.php`, `src/Cli/Bootstrap.php`, `src/App/App.php`, `src/Agents/{AgentPreset,Agent,AgentPresetRegistry,AgentManager,SuspendedDelegations}.php`, `src/Permissions/PermissionMode.php`, `src/Host/TranscriptProjector.php`, new `src/Agents/Live/{SubAgentTranscriptLog,AgentTranscriptTail}.php`, `src/Config/Settings/Definitions/Subagents.php` | TT: `schema`, `setup`. EB: `runTurn` `build`, `withReasoningEffort`, `resolveHookManager`. RT: `run`. BS: `agentManager`. **R-STATE** (subagents dir) | AGENTS_AUTHORING intro, provenance; PERMISSIONS (sub-agent mode); ARCHITECTURE "Sessions and state" | — | L |
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
| a | 3.B-4 | `src/Backend/EngineBackend.php`, `src/Host/Commands/Compact*.php`, `src/Renderer.php`, new `src/Tools/BuiltIn/Compress.php`, `src/Context/Pruning/NudgePolicy.php`, `builtin-commands/NNNN-{compress,decompress,recompress}.php` | EB: `runTurn` `step-top`. RN: collapsed block row, status bar | PROMPT_ENGINEERING reminder text | sugar-veil (modal) | L |
| b | 2.10 | `src/Host/{CompactionService,TurnController}.php`, `src/Chat.php`, `src/HistoryCompactedMsg.php`, new `src/Context/Compaction/HistoryFingerprint.php` | CompactionService: `applyModelCompaction`, `buildSummarizationRequest`. TC: `submit` tier block, `dispatchTurn` reminder. Chat: `route` HistoryCompacted arm | README :1112-1117 tiers | — | M |
| c | O-5b | `sugar-crush-web/src-web/**`, `sugar-crush-web/e2e/**`, `sugar-crush-web/dist/**`, `src/Providers/EchoProvider.php` | — | sugar-crush-web README; SERVER.md "Web UI" | sugar-crush-web | L |
| d | O-8a, O-7 | `src/Cli/{Attach,ParsedArgs,Subcommands,Help}.php`, `src/Cli/Bootstrap.php`, `src/Chat.php`, `tools/check-child-lifetimes.php`, new `src/Backend/RemoteBackend.php`, `src/Host/RemoteSessionHost.php`, `src/Server/Workspace/**` | BS: `openSession`. Chat: `relockedForCurrentSession`. **R-CLI** | README "Subcommands"; SERVER.md | — | L |
| e | 4.3-2, 4.7-3 | `src/Tools/BuiltIn/TaskTool.php`, `src/Sessions/{BackgroundSupervisor,BackgroundSessionRunner}.php`, `src/Support/Daemonize.php`, `src/Cli/Bootstrap.php`, `src/Runtime.php`, `src/Backend/EngineBackend.php`, `src/Events/SubAgentActivity.php`, `src/Host/TurnRunner.php` | TT: `schema`, `execute`, `setup`. RT: `executeConcurrently` `fork` (admission). EB: `turnTools`. BS: `tools` (supervisor bind). **TR** | AGENTS_AUTHORING `/bg` paragraph; ARCHITECTURE :456; README :1361/:1275 | — | L |
| f | 4.10-2 | `src/Workflows/{WorkflowEngine,WorkflowRegistry}.php`, new `src/Tools/BuiltIn/WorkflowTool.php` | — | WORKFLOWS (model-authored plans); AGENTS_AUTHORING workflow bullet | — | M |
| g | O-4b (calls `reconnect()`; no edit to `BackgroundSupervisor`) | `src/Chat.php`, new `src/Host/BackgroundEvents.php`, `src/Protocol/Methods/BgMethods.php` | Chat: `pumpBackgroundSessions` | SERVER.md `bg.*` | — | S–M |
| h | P-D2, P-D3 | `src/Chat.php`, `src/Renderer.php`, `src/App/App.php`, `src/Tui/KeyboardHandler.php`, `src/Commands/KeyBindingRegistry.php`, `src/Tools/BuiltIn/TaskTool.php`, `src/Agents/AgentManager.php`, `src/Message.php`, new `src/Host/AgentResume.php`, `src/{AgentMessageSentMsg,AgentControlMsg}.php` | Chat: `submit` delegator (route to `AgentInbox`), `route` AgentControlMsg arm. RN: `renderInput` placeholder. TT: `run` (`onProgress` control). **R-KEYBIND** | README Limitations (inert-commands bullet), "Keys", "What you see"; AGENTS_AUTHORING | — | L |
| i | 3.H | new `src/Hooks/BuiltIn/AutoTestHook.php`, `src/Lint/TestRunner.php`, `src/Cli/Bootstrap.php`, `src/Config/Settings/Definitions/Tools.php` | BS: `hooks`. **R-HOOKS** | HOOKS built-ins | — | M |
| j | 3.D-3 (`/goal`, `/grind`), 5.14a, 5.14b | `src/Chat.php`, `src/Host/TurnController.php`, new `src/Goal/GoalJudge.php`, `src/GoalJudgedMsg.php`, `builtin-commands/NNNN-{goal,grind,btw}.php`, `src/Config/Settings/Definitions/Ui.php` | Chat: `route` AssistantMsg arm, `requestPermission` (bell). TC: `refuseWhileInFlight` | COMMANDS/README slash roster (generated) | — | M |

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
| a | 3.I-3, 5.11-2 (incl. security findings force Ask in `auto`) | `src/Permissions/PermissionGate.php`, `src/Hooks/BuiltIn/ProtectFilesHook.php`, `src/Renderer.php`, `src/Cli/Bootstrap.php`, new `src/Tools/BuiltIn/ApplyPatch.php`, `src/Tools/Edit/PatchParser.php`, `src/Permissions/{ExecReviewer,ReviewVerdict}.php`, `src/Config/Settings/Definitions/Permissions.php` | BS: `permissionGate` | PERMISSIONS write-capable list, "What auto classifies", circuit breaker; HOOKS protect-files path table | — | L |
| b | 5.7-2 | new `src/Tools/BuiltIn/{PlanExitTool,AskUserTool}.php`, `src/Cli/NonInteractive.php` | — | PERMISSIONS "`Ask` needs somewhere to ask" | — | M |
| c | 4.5 | `src/Runtime.php`, `src/Tools/BuiltIn/TaskTool.php` (new `withBoard` method), new `src/Agents/Board/**`, `src/Tools/BuiltIn/{BoardReadTool,BoardPostTool}.php`, `src/Hooks/BuiltIn/BoardNoticeHook.php`, `src/Tools/SharesBoard.php`, `src/Cli/Bootstrap.php` | RT: `executeConcurrently` `ledger`. TT: class (new method only). BS: `hooks` (BoardNoticeHook). **R-STATE** (board dir), **R-HOOKS** | ARCHITECTURE "Sessions and state" | — | M |
| d | P-E3 | `src/Chat.php`, `src/Tools/BuiltIn/TaskTool.php`, `src/Commands/KeyBindingRegistry.php` | Chat: `route` Ctrl+X b arm. TT: `execute`. **R-KEYBIND** | README "Keys" | — | M |
| e | 5.14c, 5.14d | new `src/Host/Commands/{Handoff,NewRule}*.php`, `builtin-commands/NNNN-{handoff,new-rule}.php`, `src/Commands/RulesCommand.php`, `src/Chat.php` | Chat: `handlePaletteNewSession` | COMMANDS "`/rules`" | — | M |
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

## 5. Step index (91 steps)

Fields are: ID · size · depends on (besides same-region predecessors) · wave-group.

**Wave-0 fixes**

**Foundations**

**Context engine**
- 2.2-2 M · 2.2-1, 1.B-2 · W6-d
- 2.3 S–M · 2.2-1 · W6-c
- 2.4-2 M · 2.4-1, O-2e · W6-c
- 2.6 M · 1.A-2, 2.5 · W9-a
- 2.7-1b M · 2.7-1a, 2.4-1 · W6-a
- 2.7-2 M · 2.7-1b · W6-a
- 2.7-3 S · 2.7-2 · W6-a
- 2.10 M · 2.9, 2.5, O-2g · W8-b
- 2.11 S–M · 5.1-2, 2.4-1, 2.12 · W9-a

**Safety and self-management**
- 3.B-2 S–M · 2.2-2, 2.3 · W6-d
- 3.B-3 M · 3.B-2, 1.C-1, 2.12 · W7-d
- 3.B-4 L · 3.B-3 · W8-a
- 3.B-5 S–M · 3.B-4 · W9-b
- 3.C S–M · 1.A-2, O-2f · W7-h
- 3.D-2 M · 3.D-1, 2.12 · W7-a
- 3.D-3 S–M · 3.D-2 · W8-j
- 3.F M · — · W6-g
- 3.G M · 3.A-1, 0.3 · W6-g
- 3.H M · 3.D-2 · W8-i
- 3.I-2 S–M · 3.I-1, 1.B-2 · W7-g
- 3.I-3 M · 3.I-1, DH-TOOLS · W10-a

**Sub-agents**
- 4.1-1 S–M · N-P0, N-P3b · W6-e
- 4.1-2 M · 4.1-1, 4.2 · W6-e
- 4.3-2 M–L · 4.3-1, P-B1, 1.A-2 · W8-e
- 4.4 M–L · P-D1, P-D3, 4.3-2, 4.7-1 · W10-j
- 4.5 M · RELAY · W10-c
- 4.6-2 M–L · 4.6-1, 4.3-2, P-D1 · W9-g
- 4.7-2 S–M · 1.C-4a · W9-a
- 4.7-3 M · 4.1-1 · W8-e
- 4.9 M · 4.1-1, 4.3-2 · W9-b
- 4.10-2 M · 4.10-1, 4.2, RELAY · W8-f

**Memory, codebase understanding, UX, integrations**
- 5.3-2 M · 5.3-1, 1.A-2 · W6-f
- 5.4-1 S · 5.1-2, O-2e · W6-c
- 5.4-3 M · 5.4-1, 5.4-2, 5.2 · W7-i
- 5.5-5 S · 5.5-4, 1.A-2 · W6-f
- 5.6 remainder (pruned items in the `/context` breakdown) S · 3.B-2 · W6-d
- 5.7-1 M · 4.1-2, DEF-MODE, D8 · W9-j
- 5.7-2 M · 5.7-1, 1.C-2 · W10-b
- 5.9-1 M · — · W10-h
- 5.9-2 M · 5.9-1, O-2g · W10-h
- 5.11-2 (incl. security findings force Ask in `auto`) M · 1.C-2, N-P2 · W10-a
- 5.12 M · 0.4 · W6-h
- 5.13b M · 2.7-1a, N-P3b · W6-h
- 5.14a S · 1.C-2 · W8-j
- 5.14b S–M · O-2g · W8-j
- 5.14c M · 2.5, P-A1 · W10-e
- 5.14d S · 0.8 · W10-e
- 5.14i M · 1.A-2 · W10-f
- 5.14l S–M · O-2g · W10-f

**Settings**
- N-P3b remainder (provider-level model rebind behind `EngineBackend::withModel()`) S · 4.1-1 · W6-e
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
- P-C1 M · P-B1, RELAY, O-2f · W6-e
- P-C2 M · P-C1 · W7-f
- P-D1 M · P-B1, 1.C-3 · W7-e
- P-D2 M · P-D1, P-C2 · W8-h
- P-D3 M · P-D1, P-C2 · W8-h
- P-E1 S–M · P-D3, RELAY · W9-f
- P-E2 S · 1.C-5, P-C2 · W9-f
- P-E3 M · 4.3-2, P-D3 · W10-d

**Server and web**
- O-2g L · O-2f · W6-b
- O-2h L · O-2g, DH-CMDS · W7-b
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
| 0.8, 0.14 ".env*"/"settings protection" as all-new | `.env*` and `.sugar-crush/{hooks.yaml,config.json,agents/}` are already protected. |
| 1.B "give Message `uiOnly`" | Done (`Message::$uiOnly`, `agentVisible()`). |
| 3.C "dormant `TaskList`" | `TaskList` is the team queue; only `SessionMeta::$tasks` fits a todo. |
| 3.D JSON hook stdout as wholly new | Exit-code equivalents and `refusedBy` exist (3.D is PARTIAL). |
| 5.1 "index ≤200 lines/25 KB" as new | `MemoryStore` already builds that index; only injection is missing. |
| 5.7 "command guard", "Shift+Tab toggle" | The guard exists (`evaluatePlan`). Shift+Tab is `shell.pane-prev` (D8). |
| 5.11 "escalate after 3 denials" | Exists (`STRIKE_THRESHOLD`). |
| 5.14 `$skill` as wholly new | A session-scoped equivalent exists (Ctrl+S picker). |
| LIVE-CH "one reply" | `observeCacheHealth` needs 3 consecutive zero reports. |
| DCP §13.1 "`observeCacheHealth` dormant", "Task runs ≤50 steps with no context management" | Wired per step; Task runs ≤200 steps through `runTurn`, so 2.1/2.2-1/2.4-1 cover sub-agents. |
| O §4.8 "`session_leases` table" | Superseded by the existing flock `SessionLock`. |
| O §4.2 "`/workflow resume` runs synchronously" | Fixed (Fiber). |
| README :966-968 "`/bg` result comes back"; :1307 "`context: fork` enforced"; :1139-1143 "85% tier is heuristic-only" | All three are false today; fixed by 4.3-1, X-37a and 2.4-2 respectively. |
