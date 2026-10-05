# Execution plan: concurrency-aware waves

This appendix schedules every remaining roadmap step (Part III, Part V, Part VI) into **11 fix waves plus one final verification pass**. All work lands straight on `master`, with no PRs and at most 10 groups at a time.

**Step definitions** live in Part III (0.x–5.x), Appendix N (N-*), Appendix O (O-*), Appendix P (B1–B3, P-*) and Part VI (the LIVE checks).

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

### W10 (order: i)

The blocked live checks.

| G | Steps | Owned files | Hotspot regions (shared) | Shared-doc overlaps | Cross-lib | Size |
|---|---|---|---|---|---|---|
| i | LIVE-X31b, LIVE-A15b, LIVE-A15v, LIVE-A21b, LIVE-CH (blocked on credentials: `ANTHROPIC_API_KEY`; AWS `bedrock:InvokeModel`(+`WithResponseStream`) and Claude Sonnet 4.6 model access; `GCP_PROJECT_ID` and application-default credentials) | `scripts/provider-cache-live-probe.php`, `prompt_kit/findings/crush-report/live-checks.md` | — | — | — | S |

Run the blocked live checks from `sugar-crush/` with `php scripts/provider-cache-live-probe.php --check=<ID>` once the credentials exist.

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

## 5. Step index (5 steps)

Fields are: ID · size · depends on (besides same-region predecessors) · wave-group.

**Wave-0 fixes**

**Foundations**

**Context engine**

**Safety and self-management**

**Sub-agents**

**Memory, codebase understanding, UX, integrations**

**Settings**

**Sessions and agent view**

**Server and web**

**Deferred and live checks**
- LIVE-X31b, LIVE-A15b, LIVE-A15v, LIVE-A21b, LIVE-CH (blocked on credentials) · W10-i

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
