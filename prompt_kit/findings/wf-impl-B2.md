# wf-impl track B2 — P2-1 (tool-path forking) + B1/D1 cross-track wiring

## 1. What was implemented

### B1 cross-track: StructuredOutput in the tool corpora
- `tests/Tools/BuiltInToolCorpus.php::DYNAMIC_TOOL_CLASSES` gains `Agents\StructuredOutputTool`
  (evidence: `EngineExecutorStructuredOutputTest::testTheToolRidesTheWireWithTheSchemaAndNoJsonModeIsRequested`).
  `BuiltInToolCorpusTest` and `Integration/BinCandyCodeWiringTest` are green again.
- `tests/RuntimeTest.php`: `StructuredOutput` added to `readOnlyBuiltInToolNames()` and to the
  deliberate gate-divergence list in `testTheWriteToolRosterDoesNotDriftFromThePermissionGate`
  (it is not a catalog built-in, so the gate's Read class cannot name it). RuntimeTest: 156/156.

### P2-1 · Parallel `Workflow`-tool stages fork the agents that cannot ask

Semantics (doc-ready, for docs/WORKFLOWS.md § "Model-authored plans: the `Workflow` tool", which
today says parallel agents "take turns"):

- The `Workflow` tool still runs its stages in the turn's own process, so a permission question
  reaches the turn's approver. **Exception:** on a `parallel: true` stage (incl. `from:` fan-outs),
  an agent whose **effective tools cannot put a permission question** runs in a forked child, so
  those agents run concurrently (up to the stage width). Sequential stages, `tasks:` lists,
  pipeline and verification stages are unchanged (always inline).
- "Cannot ask" (`src/Agents/AskFreeGrant.php`): every tool on the agent's request (what is really
  on its wire, after `Task`/`Workflow`/other delegation tools are withheld) is in the catalog's
  **Read** permission class — the list the gate itself treats as read-only and allows in every
  mode: `Read, Glob, Grep, Skill, Lsp, RepoMap, Ls, ShellOutput, mcp_list, skills_search,
  BoardRead` — and no configured `Ask` rule (session gate or the agent's own gate) names any of
  them by tool-name pattern. `StructuredOutput` is always free. Anything else (Bash, Edit, Write,
  WebFetch — ParallelSafe but asks under `default` —, every `mcp__*`, tools that relay asks)
  keeps the agent inline. An agent with no `tools:` inherits the session's tools, which nearly
  always include a write tool, so it stays inline.
- Why not "the gate pre-resolves every declaration to Allow": `PermissionGate` has no such query
  (`refuses()` answers Deny only), and an Allow would still not cover the built-in
  `ProtectFilesHook`, which asks about policy-file writes after the gate allowed them. So only the
  read-class criterion is used.
- Residual: a user `PreToolUse` script hook that answers `ask` for a read can't be seen from here.
  In a forked agent that question **fails closed**: the call is refused with ChildChannel's
  grandchild reason, the agent reads the refusal and carries on. Nothing runs unasked.
- Today's engine limitation (not B2's): on the pool path every parallel agent's request carries
  the **first task's** grant (WorkflowEngine's E641 note), so all agents of a stage are judged on
  the first agent's tools. Mixed forked/inline stages become possible on their own once C-track
  gives each agent its own grant. The decision already reads the per-agent request.
- Result: each parallel stage's JSON row gains `dispatch` (omitted on stages that never went
  through the per-agent decision, i.e. sequential ones). Values:
  - `parallel: forked (3 read-only agents)`
  - `parallel: inline (agent "shell" may ask: Bash; 2 more inline)`
  - `parallel: inline (agent "a0" may ask: an Ask rule "Read(*.env)" covers Read)`
  - `parallel: 2 forked, 1 inline (agent "x" may ask: Bash)`
  - `parallel: inline (agent "x": pcntl_fork() is unavailable in this process)` / `…: pcntl_fork() failed`
  The tool description names the `dispatch?` key and the rule in one sentence.
- Liveness: inline agents beat the turn heartbeat themselves (as before). While forked agents
  run, the pool's idle poll calls the executor's `whileForkedAgentsRun()`, which beats the turn
  heartbeat (children can't — it's pid-bound).
- Cancellation / orphans: a cancelled turn is torn down with `ProcessContainment::killTreeAsync`,
  which takes the forked agents with it; each forked agent also checks `ParentProcessGuard` at
  every provider chunk/tool event and stops once its parent is gone; an unwinding pool's
  `__destruct` kills and reaps its children; per-agent `timeout`/stage budget/stall deadlines use
  the pool's existing fork-path kill. No new fork site: the pool's single `pcntl_fork()` site is
  reused. `php tools/check-child-lifetimes.php` → 0 problems.
- Forked agents keep the same provider, tools, hooks, gate, root and model pin (the child is a
  copy of the turn process running the same `EngineExecutor`); `effort`, `outputSchema`
  (StructuredOutput), `isolation: worktree` all apply as inline. Not carried back from a child:
  session-scoped tool state (e.g. Read's once-per-session instruction announcements) and the
  sibling spend ledger (each forked agent's cap check starts from the turn baseline — the same as
  inline agents today; a `SiblingSpendLedger` would tighten it).

Mechanism (files):
- `src/Agents/ForksAskFreeAgents.php` (new interface, extends `ExecutorInterface`):
  `forkRefusal(SubAgent, CompleteRequest): ?string`, `recordDispatch(SubAgent, bool $forked,
  ?string $why)`, `whileForkedAgentsRun()`. It lives on the executor because WorkflowEngine
  rebuilds each stage pool from `executor()` only.
- `src/Agents/AgentWorkerPool.php::startAgent()`: an injected executor implementing it is asked
  per agent; a cleared agent takes the normal fork path (same child body, deadline, reap, kill
  bookkeeping, live progress). Still exactly one fork site. Child/parent arms moved to private
  `runForkedChild(): never` / `trackForkedChild()`. `idle()` beats the executor while children run.
- `src/Agents/EngineExecutor.php`: new trailing ctor flag `forkAskFree` (default false = every
  injected dispatch inline, byte-identical), implements the interface, `dispatchLog()`.
- `src/Tools/BuiltIn/WorkflowTool.php`: builds the executor with `forkAskFree: true`; public
  static `dispatchSummary(StageResult, array $log): ?string`; `dispatch` row key; header doc.

Tests: `tests/Agents/AskFreeGrantTest.php` (5), `tests/Agents/AgentWorkerPoolInjectedForkTest.php`
(3: cleared agents fork + heartbeat beats, no-pcntl and failed-fork fallbacks recorded),
`tests/Tools/WorkflowToolForkTest.php` (6: forked read-only stage with distinct child pids and
heartbeat beats, inline Bash stage, first-task-grant judgement, Ask rule keeps inline,
sequential stage has no `dispatch`, mixed summary). `WorkflowToolLintAndResultTest`'s
order-sensitive two-agent case now carries a Bash probe so it stays inline (an in-memory scripted
provider does not advance across a fork).

### D1 cross-track · lint before dispatch on the TUI and headless doors
- `Chat::workflowRun()` passes `$this->commandContext()` to `WorkflowCommand::start()`, so TUI
  `/workflow run` lints exactly as the host command does (errors → error row, no fiber, turn not
  held; warnings under the report heading). Test: `tests/Chat/WorkflowRunLintChatTest.php` (2).
- `candy-code workflow run <name>` (src/Cli/Subcommands.php) lints before building the engine:
  same tiers as `Bootstrap::workflowEngine()` (user `~/.candy-code/workflows`, project
  `<configRoot>/.candy-code/workflows`), `--param` pairs as the seed, the launch backend's tool
  names when it is an `EngineBackend`, `uses:` resolved through that registry. A name that does
  not load skips the lint (the engine's refusal speaks).
  - Errors → exit 1, nothing dispatched. JSON: `result = {workflow, status: "refused",
    refusal: "<name> was not run: lint found N error(s), M warning(s) (nothing was dispatched)",
    lint: {errors, warnings, findings: ["- error [stage] …", …]}, stages: [], totals: {0…},
    session_id: null}`. Text (stderr): `candy-code: workflow run: <name> was not run: lint found …`
    followed by the finding lines.
  - Warnings never block. JSON: `result.lint = {errors: 0, warnings: N, findings: [...]}` (key
    absent on a clean lint — documents unchanged). Text (stdout): one `  lint warning: [stage] …`
    line per finding under the heading line. (Routed through existing emitters, so the stderr
    census is unchanged.)
  Tests: two new legs in `tests/Cli/WorkflowSubcommandTest.php` (26/26 green).

## 2. Not done / caveats
- No `PermissionGate` "would this declaration ask?" query was added (src/Permissions not owned);
  see cross-track 1. The gate leg of the brief is therefore the Ask-rule check only.
- Forked `Ask`s are not relayed to the parent (the report's alternative); they fail closed.
- No `SiblingSpendLedger` across forked stage agents (see above).
- An inline agent dispatched before a forkable one in the same stage delays the forkable one's
  start (the pool fills slots in queue order); irrelevant today because all agents of a stage
  share the first task's grant.

## 3. Cross-track requests
1. Permissions owner (optional): `PermissionGate::wouldAsk(ToolDeclaration): bool` (non-mutating,
   like `refuses()`), so `AskFreeGrant` can drop its Ask-rule scan and admit non-read tools the
   session's policy allows outright.
2. Workflow-engine owner (C2): per-agent tool grants on the pool path (`executeAll`'s
   `$toolGrantResolver`) — the fork decision then becomes per agent automatically.
3. Owner of `tests/Support/ReadPathCensusTest.php`: red on master from `Workflows/CpuCount.php|
   file_get_contents => 2` (P0-3's new file), not B2.
4. Docs: WORKFLOWS.md § the `Workflow` tool — replace "agents of a parallel stage take turns"
   (≈1150-1155) with §1 P2-1; document the `dispatch` row key; CLI section — the pre-run lint
   (exit 1, `result.lint`, `lint warning:` lines); TUI `/workflow run` now lints.
5. `candy-tetris/pipe_none` (untracked) was not created by B2's runs (all in candy-code); left.

## 4. Commits
- `b013de19e` register StructuredOutput in the dynamic tool corpus and the read-only roster (P0-2)
- `f904fc347` Workflow tool forks parallel agents whose tools cannot ask; stage rows report dispatch (P2-1)
- `62e6a411a` TUI /workflow run lints before dispatch (P0-4)
- `b07b866b3` headless workflow run lints before dispatch; warnings ride the report (P0-4)
