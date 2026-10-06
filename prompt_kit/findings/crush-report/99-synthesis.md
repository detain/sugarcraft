# sugar-crush roadmap: implementation report

**Subject:** `sugar-crush` · **Scope:** what is still to build or verify after the roadmap, with the competitor detail it needs.

**What this report is.** Twelve competitor studies, three design reports and five code audits were merged into one roadmap, and the roadmap was executed in eleven waves plus a remainder wave and a final pass. Everything shipped has been removed. What remains is:
- two partly built roadmap items and the Agent View remainder;
- five live provider checks that wait on credentials;
- the open follow-ups collected while the waves ran;
- the competitor notes and design sections those items still cite.

**How to read it:**
- **Part I:** the key findings.
- **Part II:** the roadmap items still open (step IDs 3.A, 4.3).
- **Part III:** competitor approaches, kept only where an open item uses them.
- **Part IV:** what remains of the requested additions (server, web UI, Agent View).
- **Part V:** code-audit status and the blocked live checks.
- **Open follow-ups:** smaller gaps, one line each, grouped by area.
- **Appendices A–M:** the baseline and competitor reports, trimmed to the parts a step needed. Each starts with a "Feeds steps" line.
- **Appendices N–P:** the three feature designs, trimmed to the sections an open item still cites.
- **Appendix R:** what is left of the execution plan (the blocked live checks).

**Where things live:**
- The per-step file impact research is in `prompt_kit/findings/crush-report/impact/`.
- The source clones are in `/home/sites/crush-research-repos/`.
- Line anchors in the appendices may drift. Prefer method names.

---

# Part I — Key findings

1. **The roadmap has landed.** Every scheduled step is on `master`; the final pass re-pinned the suite figure from a full serial run.
2. **Five live provider checks are blocked on credentials** (Part V): Anthropic, Bedrock and Vertex.
3. **Two roadmap items are partly built** (Part II): a workspace checkpoint after each write step (3.A) and steering a settled background result into a running turn (4.3).
4. **The Agent View's direct chat lacks its polish items** (Part IV): drafts per target, send-to-main and interrupt keys, the broadcast audience, a paused live line, and following a cold-resumed run.

---

# Part II — Roadmap items still open

Effort: S ≤1 day, M 2–5 days, L >1 week.

| # | Item | Effort | Sources |
|---|---|---|---|
| 3.A | **Workspace checkpoints after each write step.** Checkpoints are taken once per user turn; `EngineBackend::runTurn` takes none after a step that wrote. Optional capture per write step, so `/rewind` can land mid-turn. | M | CC OC Kilo Cline Zed dsh |
| 4.3 | **Background Task settle mid-turn.** A background result that settles while a turn runs is queued until the turn ends (`Chat::pumpBackgroundSessions`); inject it through the steer seam instead. | S | Claw nano dsh Goose Kilo OC |

**Project obligations** for any of it: wire dormant code rather than deleting it; every new tool, command, env var or key binding needs its doc edit (drift tests); a new test file goes into `scripts/parallel-tests-durations.tsv`, and `suite-figure.json` plus the README headline are re-pinned from a full serial run.

---

# Part III — Competitor approaches by theme

Short pointers to how competitors built what an open item needs. The full detail is in the cited appendix sections.

## III.1 Sub-agents and messaging (→ 4.3, the Agent View remainder)

| Product | Talking to a running child | Result handling |
|---|---|---|
| Claude Code | resume/redirect by message; teams with mailbox + locked shared task list; child asks shown in the main session; nesting ≤3, ≤20 | escaped, "no user authority" header |
| opencode | re-calling a running task extends it; user can open the child session and type; Ctrl+B backgrounds | injected into the parent |
| Kilo (current) | **shared board** INFO/ASK/RESULT/HOLD/VETO; extend a running task | synthetic message wakes the parent |
| Cline 4.x | mailbox delivered mid-run through the steer seam; shared task board; crash recovery; concurrency 2 | — |
| OpenHands | multi-round parent↔child; child approvals relayed via `confirmation_handler` | — |
| Zed | follow-ups by session id | partial output on failure; child stopped at 80–90% of its window |
| Goose | `load`/`peek`/`cancel`; orchestrator `send_message`/`interrupt_agent`; ≤5 concurrent | background-tasks block in every turn |
| nanobot | shared inbox; `send_session_message` (rate-limited); Semaphore(4) | system message injected mid-turn; parent waits ≤300 s |
| **OpenClaw** | `sessions_send` (steer/follow-up/note/resume), `sessions_yield`, `subagents list\|wait\|cancel`; 8 concurrent / 5 active / depth 5 | status from the runtime outcome + stats line (runtime, tokens, cost) |
| **dsh** | `send_message` steers a running child, wakes an idle one, cold-resumes a stored one; child→parent messages; `interrupt_agent`; `list_agents` | settlement notice injected into the parent |

Detail: Appendix L §3, M §3, I §3, E §3, K §3, C §3.

## III.2 Checkpoints (→ 3.A)

| Product | Approach |
|---|---|
| opencode | shadow git dir; `write-tree` per step; per-file revert; `/undo` restores the prompt into the input; `/redo` |
| Cline | 4.x `git stash create` + untracked files via a scratch index → private ref; restore files, chat or both; refuses if HEAD moved |
| Zed | checkpoint before each user message (temp index, untracked <2 MB, binary ignore list); "Restore" only when it would change something (unpinned refs can be gc'd) |
| Claude Code | per-prompt snapshots of edited files (100 kept); restore code, conversation or both |

---

# Part IV — Requested additions: what remains

**Still open (decision 7, 2026-10-01):** whether TLS through a reverse proxy is enough for v1 (default: yes; Appendix O §10).

**Agent View and direct chat** (Appendix P §5). The view, its composer through the signed `AgentInbox`, soft and hard cancel, pause, stop-all, broadcast and "open as session" are live. Still to build:
- a draft per target;
- `Ctrl+X Enter` to send a result to the main chat and `Ctrl+Enter` to interrupt;
- the broadcast audience in the view's header and footer;
- a paused state on the live line;
- the view following a cold-resumed run's new id.

**Attached TUI** (Appendix O §4.9). `sugarcrush attach` still runs slash commands and compaction on its own copy of the transcript and shows a turn another client starts only on re-attach. The fix: server-logic commands go to `command.exec`, local compaction is skipped, and foreign `message.*`/`turn.*`/`session.updated` events are applied live.

---

# Part V — Code-audit status and live checks

The five code audits have no open finding. Argument-scoped **permission rules** are implemented (`PermissionRule::matches`/`matchesShellSubject`, fail-closed on `$(…)`, backticks and redirects).

**Live checks** (`sugar-crush/scripts/provider-cache-live-probe.php`; results and unblocking steps in `live-checks.md`, schedule in Appendix R):

| ID | Check | Status |
|---|---|---|
| LIVE-X31b | one tool-calling `anthropic` request after the `/v1` fix | blocked: no `ANTHROPIC_API_KEY` (the wiring answers a dummy key with 401, not 404) |
| LIVE-A15b | Bedrock prompt-cache marks: creation tokens > 0, then read tokens > 0 | blocked: the `~/.aws` IAM user lacks `bedrock:InvokeModel` on the Claude Sonnet 4.6 inference profile |
| LIVE-A15v | Vertex prompt-cache marks (override the outdated default model) | blocked: no GCP credentials |
| LIVE-A21b | Gemini 2.5 output budget with `thinkingConfig` | blocked: no GCP credentials |
| LIVE-CH | cache-health notice after **three** consecutive replies with zero buckets | blocked: as LIVE-A15b (Bedrock) and LIVE-A15v (Vertex) |

---

# Open follow-ups

Gaps recorded as "later" or "optional" while the waves ran and verified still open against the source at the end of the roadmap. Paths are relative to `sugar-crush/`.

**Engine and providers**
- The Chat-side `ToolCall` has no `rawArguments`/`argumentsError`, so a call replayed in a later turn re-encodes its arguments (the engine-side one has them).
- Engine step summaries (`StepSummarizer`) are not journalled.
- `connectTimeoutSeconds` applies only after a restart (`ApplyMode::Restart`).
- Esc stops only a lone Task's delegated run; sequential in-process tools have no stop point (1.C-4b).
- Optional: a `Usage::cacheHitShare()` helper.

**Context and compaction**
- A compressed section is only marked above its first row; there is no collapse or `Ctrl+O` expand.
- `ContextMeter` shows no projected-token estimate for the next request.
- `/rewind`, `/undo`, `/redo` and `/diff` run their git work synchronously inside `update()`.
- `@https://` mentions are fetched synchronously on Enter (up to 30 s); an async resolver is wanted.

**Permissions and tools**
- A `permissionRules` change does not apply live; there is no `applySettings` arm for it.
- Over the wire: `permission.respond` takes no `scope` (the web card cannot edit a grant's scope) and refuses `remember: "user"`.
- "Always" grants do not reach background daemons (`BackgroundSessionRunner`, `BackgroundSupervisor`).
- `/skills` is not in `Chat::READ_ONLY_COMMANDS`, so it cannot run mid-turn.
- A hook's `turnHalt()` is honoured only on the engine path, not on Chat's native tool path.
- A configured sandbox that cannot start gives no launch notice; each call fails closed instead.
- Auto-commits use a fixed committer identity with no signing (`GitRunner` has no `withUserIdentity`), and a turn released from the prompt queue gets no auto-commit.
- `/undo` and `/redo` have no palette rows or key bindings.

**Sub-agents and teams**
- `context: fork` skills still have no fork executor (`App::dispatchSkill` has no production caller); the launch reports the field as inert.
- No setting turns the parallel-Task board on or off.

**TUI**
- Right- or middle-click on a session tab does not archive it.
- Rows of the `/new` folder picker cannot be clicked, only scrolled.
- A URL attachment shows as a `File` chip (`AttachmentType` has no URL case).

**Sessions, server and web**
- `sugarcrush session delete` does not refuse a session that is open and locked; shell completion ignores the per-verb session flags.
- A new session records `getcwd()`, not `--root`.
- No headless host command for `/model`, `/compact`, `/budget`, `/init`, `/goal`, `/grind`, `/compress`, `/newrule`, so the server and web UI cannot run them; no `session.setModel` method.
- Missing protocol events: `assistant.started`, `notice`, `compaction.started`, `settings.changed`, and `workflow.*` stage events (Appendix O §6.5).
- `SessionEnd` hooks fire for `-p` and the TUI only, not for `serve`, `SessionHost` or `BackgroundSessionRunner`.
- `EventLog` is capped at 20,000 rows; the planned 64 MiB byte cap was never added.

**MCP and ACP**
- HTTP MCP servers keep a fixed 30 s tool timeout; `toolTimeout` applies to stdio servers only, and `McpForeignTranslate::CANONICAL_KEY_ORDER` omits it.
- The claude-mcp server sends no heartbeat.
- ACP, documented as not implemented: `acp --connect`, image and audio blocks, `session/list` and resume, `available_commands_update`.

**Memory**
- Memory-tool and auto-memory writes are committed to the memory history lazily (only `/memory` commands and the dream pass call `MemoryHistory::commit`).
- The `Memory` tool's recall does not use the hybrid ranker.

**i18n**
- Still English-only: `ApplyMode::badge()`, `SettingsTier::label()`, `PermissionMode::description()`, the settings help texts, and the strings in `ContextMentions`, `DreamPass`, `AutoMemoryConsolidator`, `AutoCommittedMsg` and `DenialKind`.

**Tests**
- `PaletteClickTest` can flake on a one-second boundary in the export file name.
- The `/editor` live TTY check has not been run.
