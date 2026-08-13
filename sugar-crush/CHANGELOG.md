# Changelog

All notable work on `sugar-crush` (`SugarCraft\Crush`), summarized by phase.
This file is written from `git log`, not from the phase-tracking JSON/MD
files under `.sugar-crush-build/` and `.opencode/memory/` — those logs were
found to contradict each other and the actual code during the remediation
audit described below, so treat *this* file plus `git log` as the source of
truth going forward.

## Feature-parity pass (current) — 2026-08

Driven by `crush_feat.md` at the monorepo root: a 12-agent comparison of
sugar-crush against `opencode` and Claude Code. Its dominant finding was not
"features are missing" but **"most of this was already built and never wired
into the live runtime"** — so most of the work below is connection, not
construction. Waves are gated on the full suite staying green.

Note on numbering: these waves are a *separate* pass from the
`crush_code_update.md` remediation waves recorded further down this file, which
happen to share the numbers 1–4.

### Wave 1 — provider correctness, context, skills, CLI (2026-08)

- **Provider/wire fixes** — corrected streaming tool-call parsing; added
  parser-agnostic reasoning extraction (so a server that splits
  `reasoning_content` and one that inlines `<think>` both work); send SGLang
  route extras and sampling params on the wire; detect and report MiniMax's
  `</parameter>` tool-call truncation instead of silently mangling arguments;
  added `ToolCallParserInterface` with two implementations and wired it into
  `ProviderFactory`; fixed `contextWindow()`.
- **Context files** — new `EnvironmentBlock` (cwd/platform/git/date) prepended
  to system prompts; new `ImportResolver` giving `CLAUDE.md`/`AGENTS.md`
  `@import` expansion; wired instruction files into the system prompt and
  stopped double-injecting imported docs; forced instructions now sourced from
  user config.
- **Skills** — skill matching, a model-invocable `Skill` tool (level 2 of
  progressive disclosure), and cross-tool skill import; a
  `ForeignAgentPresetRegistry` for Claude Code and opencode agent presets;
  import of foreign CLI memory trees into `MemoryStore`.
- **Non-interactive CLI** — `-p`/`run "<prompt>"`, `--output-format json`,
  `--root`, and a fix for `--help` opening a blocking TUI instead of printing.
- **Diffs** — a real unified diff on `ToolResult`, and no-op edits stopped
  being reported as success.
- **Images** — added the candy-mosaic dependency, image-bearing tool results,
  and a `/doctor` capability-probe tool.

### Wave 2 — the live chat surface (2026-08)

- **Tool-lifecycle events** — an `onEvent` callback threaded through
  `EngineBackend`/`Runtime`; the two rival `ToolCall`/`ToolResult` type pairs
  reconciled into one; `Chat` wired to consume the event stream; hook gating
  routed through the single unified tool-call path (previously `Chat`'s own
  `registerTool()` calls were the one unguarded tool path in the binary).
- **Permissions** — `HookResult::ask()` and a genuinely *blocking*
  request/reply flow, rendered as a Veil modal. A follow-up closed a fail-open
  in `Runtime::settleAsk()`'s answer handling.
- **Transcript rendering** — Edit/Write diffs in the transcript; a per-tool-call
  human-readable description; collapse/expand of tool output with
  hide-on-success as the default (`Ctrl+O`); denied and interrupted calls as
  distinct visual states; tool-result images via `ImageLayer`/`ImageOverlay`.
- **Commands & palette** — `PaletteAction` and `CommandRegistry` collapsed into
  one source; fuzzy-match highlighting, category grouping and MRU bias;
  file-based custom commands loaded from disk; `/mcp` made a real command;
  provenance badges in the skills picker.
- **Mouse** — mouse mode enabled and `Mark`/`Scanner` wired into the root
  render pass; wheel scrolling; click-to-expand a tool call; click-to-switch
  session tab and pane; click-to-select in the palette/picker; and suppression
  of zone clicks that were really text-selection drags.
- **Sessions** — auto-generated session titles via a background small-model
  call.
- A follow-up guarded the **image-marker vs zone-sentinel collision**:
  candy-core's image markers and candy-mouse's zone sentinels both live in
  U+E000–U+F8FF, so `Renderer::maskImageMarkers()` masks that block out of the
  copy the mouse `Scanner` reads.

### Wave 3 — boot path, sessions, panes (2026-08)

- **A real session at boot** — `Bootstrap::chat()` now seeds a session row and
  sets `currentSessionId`. Without it the Wave-2 auto-title call could never
  fire and `/sessions` had nothing to list.
- **Pane-shell migration** — this wave's original plan called for *deleting*
  the `App`/`Tui\Renderer` layer as dead code. That was superseded mid-wave
  (commit `beacaace`) once it became clear `App` is the live engine's state
  object, not dead TUI scaffolding. The merge branch was taken instead: `App`
  became a candy-core `Model` hosting `Chat`, `ChatPane` delegates to the live
  `Renderer`, shell keys route through `KeyboardHandler`, `bin/sugarcrush`
  boots via `Bootstrap::app()`, the remaining panes were wired to real data,
  and mouse events were rebased into the zone registry's coordinate space.
  The earlier delete commit (`9243aa2a`) was reverted.
- **Navigation** — `Ctrl+Tab`/`Ctrl+Shift+Tab` session cycling; a live session
  picker that persists across turns; a real `subscriptions()` heartbeat/poll
  pump; `/bg` and `/fork`.
- **Agents** — sub-agent execution routed through `AgentManager` so agent
  telemetry is real rather than synthesized; real elapsed/token/cost numbers;
  `AgentDashboardPane` bound into the live shell; each `AgentWorkerPool` given
  a private `0700` IPC directory.
- **Skills** — the subsystem made genuinely populated, and paths-based
  auto-scoping wired into the live tool-touch path.
- **Live-run fixes found by actually using it** — a 400 Bad Request on *every*
  SGLang message (an empty PHP tool-schema array encodes as `[]`, not `{}`);
  a second 400 on any turn that called a tool (`tool_calls` encoded as `{}`);
  `Ctrl+C` not exiting (candy-core normalizes control bytes, so `^C` arrives as
  `ctrl+rune c`, never the raw `\x03` the code tested for); tool stderr leaking
  onto the terminal under the TUI; `Tab` stranding the user on panes the strip
  never offered; the menu bar being unopenable; and `Page Up`/`Page Down`
  transcript scrolling.

### Wave 3 follow-ups — live-run polish (2026-08)

- Tool events **stream** from the forked child instead of being buffered until
  the turn ends, with an idle timeout; tool calls appear in the transcript
  live, running-then-done; the command a tool ran is shown.
- Shell `Cmd` objects are consumed so menu `Enter` and skill-picker selection
  actually act; the menu bar responds to the mouse.
- `/bg` genuinely runs the backgrounded task (a `BackgroundSupervisor` is
  constructed per launch — without one, `/bg` answered "Background sessions not
  configured" on every real run).
- Image-bearing tool results are labelled and collapsible; context usage is
  shown as a token count as well as a percentage; the Settings pane got real
  content.

### Wave 4 — verification and documentation (2026-08)

- **Reachability tests** (`tests/Integration/`) asserting that the session
  store, session tabs, background sessions, the skills subsystem, mouse mode,
  the environment block and root context-file loading are reached from
  `bin/sugarcrush` → `Bootstrap::app()` — the tier that would have caught the
  original build's "well-tested but unwired" failures.
- **Documentation catch-up** (this entry): `README.md` rewritten around what
  the binary actually does now — non-interactive mode, the key/mouse/slash
  reference, the pane-shell architecture, and an honest `Limitations` section;
  this changelog section; and new `CALIBER_LEARNINGS.md` entries for the
  gotchas this pass surfaced.

No on-disk format changed in this pass. Sessions written before it still load.

## Second audit pass — `crush_code.md`

A second, independent 13-angle audit (monorepo root `crush_code.md`). Phase 0
is its highest-severity findings.

### Phase 0 — session-store performance (2026-08)

- **Checkpoints stopped rewriting the whole conversation every turn**
  (item 11, §1). `EnhancedSessionStore::saveCheckpoint()` used to
  `json_encode()` the entire history per turn. Message bodies are now
  content-addressed in a new `checkpoint_blobs` table and stored once each; a
  checkpoint keeps only the ordered list of blob ids, so total *message* bytes
  over a session are O(N) instead of O(N²).
  Measured over a simulated growing session of distinct 200-byte bodies, on
  bytes WRITTEN: **18× less at 50 turns, 28× at 100, 38× at 200, 46× at 400,
  50× at 800**. The factor moves with both turn count and message size — the
  same 400-turn run is 19× with 50-byte bodies and 145× with 2 KB ones — so it
  is a range, not a headline number.
  **The envelope's id list is itself O(N) per checkpoint, so total bytes
  written remain Θ(N²)**, just with a far smaller constant: the measured
  doubling factor climbs 2.5 → 3.7 across those runs (4.0 would be purely
  quadratic) and the envelope is 77% of everything written by turn 400, 88% by
  turn 800. Removing that last term needs a checkpoint format that can
  reference a range of blobs, which is a separate schema change. Bytes left ON
  DISK are bounded by the existing 100-checkpoint cap.
  **No recovery guarantee was traded away** — every turn is still its own
  checkpoint and `/rewind n` still means exactly n turns, which is why a
  "checkpoint every K turns" throttle was rejected.
- **The WAL is bounded again.** `saveCheckpoint()` left the cursor of its
  MAX-index query open across the following INSERT, which holds a read
  transaction, and SQLite skips its automatic WAL checkpoint at a COMMIT taken
  while a reader is open — so `session.db-wal` grew for the life of the
  process while the main database stayed at 4 KB. That is the audit item's
  actual "files reaching hundreds of MB" symptom. With the cursor closed, a
  1500-turn run goes from `main=4 KB / wal=57 MB` to `main=656 KB / wal=4 MB`
  (SQLite's default 1000-page auto-checkpoint threshold).
- **`/rewind` survives a second terminal.** The interned hash → blob-id cache
  is now invalidated by `PRAGMA data_version`, so a `/rewind` in one process
  no longer leaves another process writing checkpoints that name
  garbage-collected blob ids and read back as "Checkpoint N not found" for the
  rest of its life. Two sugar-crush terminals always share a session, because
  `Bootstrap::seedSession()` resumes the globally most recent one.
- **An un-encodable message no longer becomes a null hole.** Checkpoint
  encoding uses `JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR`: a tool
  result carrying raw bytes (nothing on `Chat::finishToolCalls()`'s path
  validates UTF-8) used to encode as `false`, store as `""`, hash as `""` —
  collapsing every un-encodable message onto one shared blob — and decode back
  to `null`, killing `/rewind` with "Argument #1 ($m) must be of type array,
  null given".
- **`sessions.updated_at` is indexed** (item 12, §1). `listSessions()` runs
  from the render path up to 60×/sec and was `SCAN sessions` +
  `USE TEMP B-TREE FOR ORDER BY`; it is now `SCAN sessions USING INDEX
  idx_sessions_updated_at` with no sort. The index is ASC on purpose: scanned
  backwards it satisfies `updated_at DESC, rowid DESC` outright, where a DESC
  index would leave the rowid half of the sort behind.
- **That query also stopped running once per frame** — `listSessions()` is
  memoised per `$limit` and invalidated by this connection's own writes plus
  `PRAGMA data_version` for other connections'.
- **Retention is wired up, and OPT-IN** — `pruneSessions()` had never been
  called from anywhere. `Bootstrap::sessionStore()` now runs it once per
  launch, before the session to resume is chosen, but **only when
  `$SUGARCRUSH_SESSION_RETENTION_DAYS` is set; the default is `0`, which
  disables retention entirely.** A destructive default deletes precisely the
  session the user came back for, and "unnamed" is a weak proxy for
  "abandoned" (auto-titling fires at most once per session, needs a working
  title backend, and fails silently). Three guards on top: **named sessions
  are exempt at any age**, the row the launch is about to resume is exempt at
  any age, and what was deleted — ids, ages, message counts — is reported on
  stderr instead of the return value being discarded. The window is clamped to
  100 years (`ctype_digit()` accepts a 20-digit value, `(int)` saturates it to
  `PHP_INT_MAX`, and `strtotime()` overflows that into a cutoff in the FUTURE
  that matches every row), and the cutoff is computed with `gmdate()` because
  `updated_at` is SQLite's UTC `CURRENT_TIMESTAMP` — a local-time cutoff east
  of UTC deleted sessions up to 14 hours early.

On-disk format: additive and backward-compatible. `checkpoint_blobs` and
`idx_sessions_updated_at` are created by the existing `IF NOT EXISTS` schema
init, so an existing `session.db` migrates on its next open, and checkpoints
written in the old inline format still read back unchanged.

## Remediation pass

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
