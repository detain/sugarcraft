# Plans Cleaning — Verification Report

---

## Source: orthodox-moccasin-guineafowl — W1-W18 + missing.md cross-cutting

### ✅ DONE

- W1: ANSI parser defork
- W2: Sanitizer SSOT
- W3: Bubblezone merge
- W4: Fuzzy SSOT
- W5: Palette SSOT
- W6: Testing harness
- W8: AtomicJsonFile
- W9: Highlighter interface
- W11: sugar-glow dead code
- W12: candy-layout solver
- W14.1: blob/main→master
- W14.2: Package count
- W17: Façade tests
- W18: Toast engine

### ⚠️ PARTIAL

- W7: unused path-repos — candy-vt still has unused candy-buffer path-repo
- W14.4/5: icons — 60 present but need size verification
- W15: orphaned features — only sugar-skate verified
- W16: status bar SSOT exists but migration unclear

### ❌ NOT DONE

- W10: candy-serve lacks candy-wish dep
- W13: immutability violations not comprehensively verified

### ❓ UNCLEAR / NOT VERIFIED

- missing.md audit findings (58 library sections, 30+ P0 security items, per-lib correctness bugs not verified)

---

## Source: squealing-maroon-squirrel — per-library backlogs

### ✅ DONE (15+)

- candy-ansi: OSC-8/hyperlink routing, CR/LF dispatch, CSI final methods
- candy-async: OperationCancelledException, dead $operationPromise, retry() loop param, disposeAll throw-guard, retry bounded wall-clock
- candy-buffer: Region validation
- candy-core: AtomicJsonFile/Json::decodeArray/Sanitize SSOT
- candy-fuzzy: ScoringProfile port, DoS caps
- sugar-stash: rename parsing
- sugar-skate: sanitizeForTty

### ⚠️ PARTIAL (2)

- candy-ansi: CsiHandlerImpl stubs not deleted (W1.3 in progress)
- candy-files: trash dir path construction without mkdir

### ❌ NOT DONE (2)

- candy-async: debounce/throttle no disposal handle
- candy-buffer: no resize() method

### ❓ UNCLEAR / NOT VERIFIED (40+)

- sugar-toast: placeAnsiStringAt
- candy-kit: Stage microtime
- sugar-wishlist: Config non-scalar cast
- candy-mouse: Scan duplicate zone
- candy-serve: SEC issues
- candy-query: SQL logging
- sugar-post: STARTTLS
- (and many others)

---

## Source: redundant-gray-chicken — crush_code_plan.md P0-P7

### ✅ DONE (approximately 45 steps across all phases)

- P0.S2: PermissionMode enum (6 cases)
- P0.S3: Effort enum (Low/Medium/High/XHigh/Max)
- P0.S4: MemoryScope enum
- P0.S5: Isolation enum
- P0.S6: AgentPreset DTO (readonly, declare(strict_types=1))
- P1.S1: AgentStatus enum (8 cases)
- P1.S2: AgentResult class (readonly DTO)
- P1.S3: ExecutorInterface
- P1.S4: AgentPoolConfig
- P1.S5: ProcessExecutor spawn/run (array-form proc_open)
- P1.S6: ProcessExecutor heartbeat/timeout/crash-recovery
- P1.S9: SubAgent timeout/maxRetries/isolation fields
- P2.S1: TeammateStatus enum
- P2.S2: TaskStatus enum
- P2.S5: TeamMessage value object (8 tests)
- P2.S6: TeamConfig (20 tests)
- P2.S12: AgentManager team methods
- P2.S13: SubAgent team fields
- P2B.S1: PermissionRule + PermissionAction
- P3.S2: WorktreeManager core (real git worktree add/remove)
- P3.S4: PathJail (traversal/symlink escape blocking)
- P3.S6: Teammate + claim wiring
- P4.S1: WorkflowStatus enum
- P4.S2: StageResult
- P4.S4: TaskBuilder
- P4.S5: Tasks factory
- P4.S7: WorkflowBuilder
- P4.S8: WorkflowRegistry PHP DSL
- P4.S9: WorkflowRegistry YAML
- P4.S11: parallel() primitive (via pcntl_fork)
- P4.S12: pipeline() + interpolation
- P4.S13: withVerification()
- P5.S1: AgentStatusBar (24 tests)
- P5.S3: AgentOutputPane
- P5.S5: BackgroundSupervisor (real proc_open/Unix-socket IPC)
- P5.S6: Stall detection (21 tests)
- P6.S1: CompactorConfig
- P6.S4: skill-aware compaction
- P6.S6: MemoryEntry
- P6.S10: SessionMeta + EnhancedSessionStore
- P6.S14: InstructionFileLoader loadRoot/loadForced
- P7.S1: GitOperationResult (29 tests)
- P7.S2/S3/S4: GitCommandHandlers (array-form proc_open)
- P7.S5: GitMcpServer wiring
- P7.S6: OAuthClientRegistration (RFC 7591)
- P7.S7: McpAuthStore + mcp auth command
- P7.S10: LspCache
- P7.S15: Deep research workflow
- P7.S18: GitMcpServerTest (7 tests)

### ⚠️ PARTIAL

**Phase P0:** dev-sglang path works only in monorepo; AgentPresetRegistry has path-traversal prefix bug; example presets use temp fixtures not shipped .md files

**Phase P1:** AgentWorkerPool busy-spin fixed but context:fork dispatch missing; P1.S10 parallel tool routing was broken but fix confirmed applied

**Phase P2:** Teammate::getStatus() returns Idle unconditionally; TaskList PSR-4 violation (exception in .php file); Mailbox event-driven wake never wired; Team::maxTeammates not enforced; TeamManager removeTeam() doesn't delete on-disk state

**Phase P2B:** evaluateAuto fails open when classifier null (FIXED per then-fuchsia-elk); rm -rf circuit breaker was evaded by flag permutation (FIXED per then-fuchsia-elk); HookDispatcher dispatch() only calls usesContinueOnBlockOnBlock()

**Phase P3:** PathJail exists but never constructed for live agents (DEAD WIRING); createWorktree() no longer calls resolveWorktreeInclude(); cleanup never wired

**Phase P4:** pause()/resume() structurally impossible (no yield point, no signal handlers originally; R28 adds SIGINT handlers but can't credit in-flight parallel stages); executeStage() only executes $tasks[0]; SessionPicker never wired

**Phase P5:** /share was fake (FIXED per then-fuchsia-elk - now honest failure); /agents bare command bug (FIXED per then-fuchsia-elk); P5.S12 manual verification was rubber-stamped

**Phase P6:** shouldSendReminder() doesn't exist; Stage 4/5 dead in pipeline; shouldPromptIdleCompaction() never called; MemoryScope enum never used by MemoryStore; MEMORY.md byte cap uses byte-blind substr(); SessionPicker never wired; InstructionFileLoader not wired to tools in Bootstrap

**Phase P7:** Per-agent MCP routing unenforced (McpRouter correct but McpClient never consults it); LspConnection TCP/socket transport absent; SkillLoader scan paths don't include skills/<name>/SKILL.md (batch 1 built-in skills never loaded); Batch 2 built-in skills in wrong location + tests were deleted under misleading pretext

### ❌ NOT DONE / INCOMPLETE

*All phases listed above remain partially incomplete; no phase reached full DONE status.*

### ❓ UNCLEAR / NOT VERIFIED

- Full end-to-end integration testing across all phases
- Real-world scenario validation for pause/resume under load

---

## Source: then-fuchsia-elk — crush_code_update.md audit findings vs actual code

### ✅ FIXED

- P2.S8: atomic claimTask TOCTOU (lock file never unlinked)
- P1.S10: parallel tool output discarded (now uses direct pcntl_fork)
- P2B.S4: rm -rf circuit breaker (runs BEFORE rules)
- P5.S10: /share lie (now honest failure)
- P7.S8: MCP routing (now enforced via McpRouter)
- P4.S14: pause/resume through real interrupts (R28 SIGINT/SIGTERM handlers)
- P5.S11: /agents parsing bare command
- evaluateAuto fails open (now fails closed/Ask)
- AgentWorkerPool busy-spin (now sleeps 5ms)
- unserialize security (now JSON IPC)
- dev-sglang config inert
- InstructionFileLoader not wired

### ⚠️ PARTIALLY FIXED

- P7.S14 skill flags — disable-model-invocation flag filtering works in SkillRegistry but context:fork has no dispatcher and skills subsystem never populated at bootstrap

### ❌ STILL BROKEN / UNWIRED

- P7.S14: context:fork no dispatcher (isContextFork() exists but zero callers fork based on it)
- PathJail: never constructed for live agents
- Skills subsystem: never populated (no SkillLoader::loadAll() in production)
- AgentViewPane/Renderer stub (renderAgentView() always returns '')

### ❓ NEED TESTS

- P2.S8: concurrency test
- P2B.S4: circuit breaker evasion tests
- P7.S8: MCP routing restrictive preset test
- P4.S14: signal handling test

---

## Source: rolling-teal-bison — open_crush.md superseded/moved content

### ✅ IMPLEMENTED

- Loop detection (Chat.php counter + StallWarning + StallDetector)
- Permission system (PermissionGate 6 modes + circuit breaker)
- Context budget management (ContextCompactor 5-stage)
- Streaming UI (StreamingCommandBackend + onToken)
- Chat history persistence (SessionStore SQLite WAL)
- MCP transports (StdioMcpServer + HttpMcpServer + OAuth)
- Hooks system (HookManager + built-ins ProtectFilesHook/BashEscapeDenyHook/ConfirmRemoveHook/AuditHook)
- Provider abstraction (6 providers)
- Plan/Build mode
- Syntax highlighting via candy-shine
- Custom themes
- Subagents
- LSP integration

### ❌ NOT IMPLEMENTED

- TreeSitter (AST-aware context packing)
- Workspace sharing (SSE-based collaboration)

### ⚠️ PARTIAL

- Built-in File Tools — ToolRegistry only has viewport tools (filter/sort/goto/select/quit), NO Read/Write/Edit tools

### ❓ UNCLEAR / NOT VERIFIED

- Server-Client REST API mode (could exist in Cli/ but not verified)

---

## Overall Summary

| Source Plan | ✅ DONE | ⚠️ PARTIAL | ❌ NOT DONE | ❓ UNCLEAR |
|---|---|---|---|---|
| orthodox-moccasin-guineafowl (W1-W18 + missing.md) | 15 | 4 | 2 | 1 |
| squealing-maroon-squirrel (per-library backlogs) | 15+ | 2 | 2 | 40+ |
| redundant-gray-chicken (crush_code_plan.md P0-P7) | ~45 | ~45 | ~15 | 2 |
| then-fuchsia-elk (crush_code_update.md) | 12 | 1 | 4 | 4 |
| rolling-teal-bison (open_crush.md) | 13 | 1 | 2 | 1 |
