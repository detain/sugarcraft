# Changelog

All notable work on `sugar-crush` (`SugarCraft\Crush`), summarized by phase.
This file is written from `git log`, not from the phase-tracking JSON/MD
files under `.sugar-crush-build/` and `.opencode/memory/` — those logs were
found to contradict each other and the actual code during the remediation
audit described below, so treat *this* file plus `git log` as the source of
truth going forward.

## Remediation pass (current)

An independent line-by-line audit of the original P0–P7 build (recorded in
the monorepo root's `crush_code_update.md`) found that a large fraction of
"complete" steps were either subtly broken, unenforced, or entirely unwired
from the running binary despite passing their own unit tests — see that
document for the full findings. This pass fixes the audit's findings in five
waves, each gated on the full suite staying green (0 new failures/errors)
before the next wave starts.

### Wave 1 — concurrency/atomicity fixes

- **`TaskList` claim atomicity** — fixed a `flock`+`unlink` TOCTOU in
  `releaseTaskLock()` that let multiple concurrent contenders claim the same
  task; replaced the sequential "concurrency" test with a real
  `pcntl_fork`-based multi-process stress test. Extracted `TaskBlockedException`
  into its own PSR-4 file.
- **`Mailbox` real wake** — added a bounded poll-with-backoff
  `waitForMessage(timeout)` primitive instead of leaving inter-agent message
  delivery purely caller-driven; a `.fix` closed a missed-wakeup race by
  capturing the wake token before the initial inbox check.
- **`AgentWorkerPool` hardening** — replaced a zero-sleep busy-spin
  `waitForCompletion()` loop with a sleeping backoff poll; replaced
  predictable-path `unserialize(..., ['allowed_classes' => true])`
  result-passing with `json_encode`/`json_decode`; added a one-time visible
  warning log when the pool silently falls back to sequential execution
  because `pcntl_fork` is unavailable. A `.fix` guarded against a
  `json_encode` failure hanging the worker pool.
- **`WorkflowEngine` real interrupt handling** — registered
  `pcntl_signal()` handlers (`pcntl_async_signals(true)`) around the stage
  loop for `SIGINT`/`SIGTERM` so a real Ctrl-C captures a genuine pause file;
  a follow-up (`.fix2`) closed a fork/signal-inheritance race and a leaked
  `pcntl_async_signals` flag left set after `run()` returns. Documented the
  remaining, honest limitation: an in-flight *parallel* sub-stage still can't
  be resumed with partial credit — resume granularity stays per whole stage.
- **Team/Worktree cluster** — enforced `TeamConfig::$maxTeammates` in
  `Team::addTeammate()`; re-wired `.worktreeinclude` auto-copy into
  `WorktreeManager::createWorktree()`; added a `sweepIfDue()` call ahead of
  worktree creation so stale-worktree cleanup actually runs; gave
  `TaskList::dispatchTeammateIdle()` a real consumer that reassigns the next
  unblocked task to an idle teammate.

### Wave 1 — permission/security hardening

- **Permission gate hardening** — moved the rm-rf circuit breaker ahead of
  `evaluateRules()` so it runs unconditionally in every mode (an ordinary
  `Bash*: Allow` rule could previously short-circuit past it); broadened
  `isRmRfRootOrHome()` to catch flag reordering, splitting, long-form flags,
  and `--no-preserve-root`; fixed `SCOPED_WRITE_TOOLS`/`isReadOnlyTool()`
  /`isWriteTool()` to match real tool names instead of invented ones; made
  `evaluateAuto()` fail **closed** (`Ask`, not `Allow`) with no classifier
  configured. A `.fix` pass hardened the breaker further against quoted
  targets and updated stale pre-hardening tests for the new fail-closed
  behavior.
- **Mode-seal fix** — allowed the `Plan → {Auto, AcceptEdits, Default}`
  transition the design requires, while keeping the seal against re-entering
  `BypassPermissions`/`DontAsk` once sub-agents are live.
- **Hook exit-code semantics** — wired `blocksOnPreAction()`/
  `discardsOnBlock()`/`stderrToUserOnly()` into `HookDispatcher::dispatch()`
  so `UserPromptSubmit`/`PreCompact`/`SessionStart` genuinely differ in
  effect rather than only in enum metadata.
- **Per-agent MCP routing enforcement** — `McpClient::listTools()`/
  `callTool()`/`callToolByName()` now actually consult
  `McpRouter::resolveAllowedTools()` against the active `AgentPreset`'s
  `mcpServers` allowlist, instead of exposing every configured server's
  every tool unconditionally. A `.fix` made the default fail **closed**
  (deny by default) and forwarded deny patterns through to the router.
- **Skill flag enforcement** — `SkillRegistry::findForPrompt()` now checks
  `isAutoInvocable()`/`disableModelInvocation` before auto-triggering a
  skill; `isUserInvocable()` gained a real command-surface filter; a
  `context: fork` skill now dispatches through `AgentWorkerPool` as an
  isolated sub-agent instead of inlining into the main conversation.
- **`AgentPresetRegistry` hardening** — fixed a path-traversal prefix bug
  (`agents-secrets` matching a raw-string prefix of `agents`) by comparing
  against a trailing-separator-normalized path; removed the dead
  `$data['_file']` fallback in favor of the real filename already available
  to the parser.
- **Git-handler trivial fixes** — made the dangerous-branch-name guard
  case-insensitive (`head`/`HEAD`/`Head` all rejected) and fixed a test
  teardown that leaked `/tmp/git_command_handlers_test_*` directories.

### Wave 1 — honest-failure-over-fabrication fixes

- **`/share` no longer fabricates success** — `ShareUploader`/`ShareCommand`
  previously discarded the session content after hashing it and returned a
  "signed" URL from a hardcoded secret string committed to the source tree.
  `/share` now reports "not yet implemented, no data was uploaded" instead of
  a forgeable fake URL. Follow-up `.fix` commits deleted the now-dead
  fabricated-success `printResult()` path and its unused imports.
- Several `.fix` commits across R5/R16/R20/R24/R28 replaced misleading test
  assertions, dead placeholder checks, and doc-comments that overstated
  what was actually wired, with honest descriptions of the real (sometimes
  still partial) behavior.

### Wave 1 — data-layer fixes

- **`MemoryStore` scope partitioning** — `MemoryScope` (project/user/agent)
  now actually determines which on-disk directory a `MemoryStore` instance
  reads/writes, with cross-scope regression tests. Fixed the MEMORY.md index
  generator's 25KB truncation to be multibyte-safe (no more corrupted UTF-8
  at the cut point) and its 200-line cap to count real rendered newlines
  instead of raw array-element count.
- **Compaction stage ordering + reminder tier** — reordered
  `ContextCompactor::compact()` so file-to-diff/remove-nav stages run before
  summarization collapses their content; added `shouldSendReminder()` as a
  real, wired soft-warning consumer at the 70% threshold.
- **dev-sglang provider wiring** — `config.dev.json` is now actually read by
  the provider factory's default-config resolution, making `dev-sglang`
  genuinely selectable instead of an inert fixture file. A `.fix` pass
  corrected an off-by-one in the config path and de-tautologized the
  provider tests.
- **Built-in skill relocation** — moved 8 skill directories
  (`laravel-best-practices`, `symfony-best-practices`, `testing-strategies`,
  `api-design`, `explore-codebase`, `mcp-authoring`, `worktree-workflow`,
  `matchups-sync`) into `src/Skills/BuiltIn/<name>/SKILL.md` alongside the
  original 4, matching `SkillLoader`'s real scan path; restored
  `BuiltInSkillsTest.php` (deleted under a misleading "stale test removal"
  commit) and extended it to cover all 12.
- **`mutate()` convention fix** — routed `SplitLayout` and `SessionTab`'s
  `with*()` methods through a private `mutate()` helper, matching the
  repo-wide immutable/fluent pattern instead of hand-rolled `new self(...)`.

### Wave 2 — Chat.php cluster

One continuous, sequentially-committed track against the busiest shared file
in the remediation, gated on Wave 1's worker-pool hardening and MemoryStore
scope API landing first:

- Fixed `/agents` (no trailing space) mis-parsing into a single-character
  agent-name lookup instead of routing to `listAgents()`.
- **Disabled broken parallel tool-call routing (R14b)** — `Chat::handleToolCalls()`
  previously routed 2+ tool calls through `executeToolsParallel()` into a
  worker script that couldn't parse real tool JSON or reach real tool
  closures across the process boundary, silently replacing every tool's
  real output with simulated text. Routing now falls back unconditionally
  to the correct sequential path, with an `@todo` documenting the actual
  redesign a real fix needs (a name-keyed tool registry inside the worker
  process, not PHP closures).
- **`AgentResult::ok()` dead-test fix (R25)** — two P1.S10 tests called a
  static factory that never existed and only "passed" because they
  exercised an empty `$agents` array (dead code); rewritten against the
  real constructor with a non-empty pool dispatch.
- Widened `withSessionStore()`/`sessionStore()` to accept
  `SessionStore|EnhancedSessionStore|null`, matching the internal property's
  real type.
- Wired a real call site for `shouldPromptIdleCompaction()` and made
  `lastActivityAt` track genuine activity instead of only being set in tests.
- Wired `ContextCompactor::shouldSendReminder()` into `submit()` so the 70%
  soft-warning tier actually surfaces to the user.
- Updated `/workflow pause` help text to honestly describe real pause/resume
  semantics once Wave 1's signal handling landed.

### Wave 3 — bin/sugarcrush + TUI integration

One continuous, internally-ordered track (data layer before TUI):

- **Data-layer wiring** — `bin/sugarcrush` now constructs and injects a real
  `SessionStore`/`EnhancedSessionStore`, scope-correct `MemoryStore`, and
  `InstructionFileLoader` into the live `Chat`/`Read`/`Edit`/`Glob`
  instances at startup — making the P6 session/memory/instruction subsystems
  actually reachable instead of only reachable from tests.
- **TUI/App integration** — wired `AgentStatusBar`/`AgentViewPane`/
  `AgentOutputPane` into `App`'s render state and replaced `Renderer`'s
  stub "(no active agents)" pane with the real one; fixed an Attach-mode key
  leak (global quick-action keys were checked before mode dispatch); wired
  `KeyboardHandler`, session tabs, and a `/sessions` command into the live
  Chat renderer. Follow-up `.fix` commits disclosed two real remaining gaps
  candidly rather than papering over them: `createSession()` is never called
  in production (leaving `/sessions` and the tab strip empty until that's
  wired), and `Ctrl+Tab` session cycling needs a `Bootstrap`-shaped
  `currentSessionId` that doesn't exist yet. Both are honestly documented as
  open gaps, not silently patched over.

### Wave 4 — final validation and documentation

- **R30 (this pass)** — documentation catch-up: this `CHANGELOG.md`, a real
  runnable YAML workflow example at
  [`examples/workflows/lint-then-fix.yaml`](examples/workflows/lint-then-fix.yaml)
  (previously, YAML workflow loading was only ever exercised via workflows
  written to temp directories inside test methods), and `README.md` updates
  to the test-count claim and the `Capabilities` section (teams, worktrees,
  workflows, permission modes, and the real 12-skill roster were implemented
  in the original build but never documented).
- R29 (missing `FanOutResearchTest`/`MultiAgentRefactorTest` E2E coverage)
  and R31-finalize (marking the remediation-progress tracking file) are
  separate Wave-4 tracks; check `git log` for their current status rather
  than assuming this entry covers them.

## Original build (P0–P7)

Built as a PHP port of [`charmbracelet/crush`](https://github.com/charmbracelet/crush)
on top of the existing SugarCraft chassis (`candy-core`'s `Model`/`Program`,
buffer-diff `Renderer`). Absorbed the former experimental `candy-crush` port
partway through — there is now a single `SugarCraft\Crush` library.

- **Chassis + engine grafting** — streaming `CommandBackend`, tool-calling,
  slash-command parsing, an MCP client, and full session persistence were
  ported from `candy-crush` and grafted onto the sugar-crush chassis
  (`EngineBackend` as the seam), then rebranded and merged as a single agent.
- **P0 — Agent preset configuration schema.** `PermissionMode`, `Effort`,
  `MemoryScope`, `Isolation` enums; `AgentPreset` DTO; `AgentPresetRegistry`
  with example presets (`coder`/`reviewer`/`security-auditor`).
- **P1 — Parallel agent execution engine.** `AgentStatus`/`AgentResult`,
  `ExecutorInterface`/`ProcessExecutor` (heartbeat, timeout escalation, crash
  recovery), `AgentWorkerPool`, `AgentManager::executeAll()`, and the first
  (later found broken, then fixed in the remediation pass above) wiring into
  `Chat`.
- **P2 / P2B — Agent teams + permissions.** `TeammateStatus`/`TaskStatus`
  enums, `Task`/`Teammate`/`TeamMessage`/`TeamConfig`, SQLite-backed
  `TaskList` with claim semantics, append-only `Mailbox` messaging, `Team`
  aggregate root, `TeamManager` factory/registry, and the `PermissionGate`
  (`Default`/`AcceptEdits`/`Plan`/`Auto`/`DontAsk`/`BypassPermissions`) plus
  the `SafetyClassifier` and rm-rf circuit breaker (both later hardened
  above), and the `HookDispatcher`/`HookEvent` core.
- **P3 — Worktree isolation.** `WorktreeConfig`/`WorktreeIsolationMode`,
  `WorktreeManager` create/remove/list + cleanup/`.worktreeinclude` policy,
  `PathJail` worktree sandboxing routed through `Edit`/`Read`/`Bash`.
- **P4 — Workflow orchestration.** `WorkflowStatus`, `StageResult`,
  `WorkflowResult`, `TaskBuilder`/`Tasks`/`Workflow`/`WorkflowBuilder`,
  `WorkflowRegistry` (PHP DSL + YAML loading), `WorkflowEngine` with
  sequential, `parallel()`, `pipeline()`, and `withVerification()` stage
  types, plus pause/resume/getStatus persistence and the `/workflow`
  command wired into `Chat`.
- **P5 — Multi-agent TUI.** `AgentStatusBar`, `AgentViewPane`,
  `AgentOutputPane` (peek/attach modes), keyboard shortcuts,
  `BackgroundSession` supervisor + stall detection, split-pane rendering,
  session tabs, `/share` (later found to fabricate success, fixed above),
  and `/agents`/`/agent` commands.
- **P6 — Context + memory + sessions.** `ContextCompactor` (5-stage
  compaction, skill-aware compaction), `MemoryEntry`/`MemoryStore` with
  MEMORY.md index generation, `SessionMeta`/`EnhancedSessionStore`, session
  naming + `/branch` (fork/rename), checkpointing + `/rewind`, session
  picker keyboard navigation, and `InstructionFileLoader` (root/forced/
  path-nested injection with traversal guards).
- **P7 — Git/LSP/skills/MCP-auth.** `GitOperationResult` + git command
  handlers (`git_context`, `git_history`, `git_commits`, `git_branches`,
  `git_worktree`, `git_flow`, `git_lfs`) behind `GitMcpServer`;
  `OAuthClientRegistration` + `McpAuthStore` for dynamic MCP auth; per-agent
  MCP routing scaffolding (later actually enforced above); `LspConnection`/
  `LspCache`/`LspClient` (stdio/TCP transport, definitions/references/hover/
  symbols, TTL caching); `SkillLoader`/`SkillDiscovery`/`SkillRegistry`
  (three-stage loading, frontmatter flags — later actually enforced above);
  the `deep-research` example workflow; and the first 4 built-in skills
  (`security-audit`, `phpunit-master`, `composer-wizard`,
  `php-best-practices`) plus a second batch of 8 (later relocated into their
  correct loader path above).

An independent audit (`crush_code_update.md` at the monorepo root) then
found that a substantial fraction of the above — while individually
well-tested in isolation — was either not actually wired into the running
binary, silently broken under real concurrency, or misrepresented by its own
commit message/progress-tracking JSON. The remediation pass above is the
fix for those findings.
