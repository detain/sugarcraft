# sugar-crush roadmap: implementation report

**Subject:** `sugar-crush` · **Scope:** everything still to build or fix, with the competitor detail each step needs.

**What this report is.** Twelve competitor studies, three design reports and five code audits were merged into one roadmap. Everything already shipped has been removed. What remains is:
- the steps still open;
- the designs they implement;
- the competitor implementation notes those steps cite;
- a concurrency-aware execution plan.

**How to read it:**
- **Part I:** the key findings, each mapped to its steps.
- **Part II:** open problems and the steps that fix them.
- **Part III:** the roadmap items (step IDs 0.x–5.x) and the wave summary.
- **Part IV:** competitor approaches by theme, kept only where a step uses them.
- **Part V:** the requested additions: settings, server mode, `sugar-crush-web`, sessions and agent view (steps N-*, O-*, B*/P-*).
- **Part VI:** code-audit status and live checks.
- **Appendices A–M:** the baseline and competitor reports, trimmed to the parts a step needs. Each starts with a "Feeds steps" line.
- **Appendices N–P:** the three feature designs.
- **Appendix R:** the execution plan (waves, ownership, rules, step index).

**Where things live:**
- The per-step file impact (exact files, method regions, forced docs and drift tests) is in `prompt_kit/findings/crush-report/impact/`.
- The source clones are in `/home/sites/crush-research-repos/`.
- Line anchors in the appendices may drift. Prefer method names.

---

# Part I — Key findings

1. **The scheduled work left is the live checks.** Five live provider checks wait on credentials (Part VI). Parts III and V still list unscheduled polish (2.9, 3.A, 4.3, the agent view's remaining items).

---

# Part II — Open problems and their steps

None is open on the live default path. The remaining steps (Parts III, V and VI) complete or polish features that work today.

---

# Part III — Unified, prioritised roadmap

These are the recommendations from all twelve reports, de-duplicated and grouped by theme. The theme numbers (0.x–5.x) are stable step IDs. They do **not** set the order of work. Effort: S ≤1 day, M 2–5 days, L >1 week.

**Execution order:** Appendix R (`17-execution-plan.md`) schedules every remaining step in this Part, Part V and Part VI. It uses 11 fix waves of up to 10 concurrent groups, then one final verification pass, all committed straight to `master`.

| Wave | Groups | Main content |
|---|---|---|
| W1 | 10 | 0.x fixes, 1.B-1 ids, 1.C-1 frame channel, P-A1 + B1–B3, N-P3a |
| W2 | 10 | 1.B-2 replay, 1.C-2 TUI approvals, 1.A-1, N-P0, O-2a, P-A2, 4.2, 3.A-1, 2.8 |
| W3 | 10 | 2.1, 1.C-4a, RELAY + P-B1, 1.B-3, O-2b/c/d, DEF-MODE, N-P1, 5.1 |
| W4 | 10 | 1.A-2, 1.C-3, O-2e, P-B2, 3.A-2, N-P2, 5.2/5.3-1, 3.D-1, 3.E |
| W5 | 10 | 2.2-1, 2.4-1, O-2f, 2.12, 2.5, 5.6, P-A4, 1.C-5, O-3a, O-5a |
| W6 | 10 | 2.7, O-2g, 2.4-2, 2.2-2 + 3.B-2, 4.1, P-C1, 3.F, 3.G, O-4a, N-P3 |
| W7 | 10 | 3.D-2, O-2h, O-3b/c, 3.B-3, P-D1, P-C2, 3.I-2, 3.C, 5.4-3, N-P4a |
| W8 | 10 | 3.B-4, 2.10, O-5b, O-7, O-8a, 4.3-2, 4.10-2, P-D2/P-D3, 3.H, `/goal` |
| W9 | 10 | 2.6, 2.11, 3.B-5, 4.9, O-6a/b/c, P-E1/P-E2, 4.6-2, N-P4b–g, 5.7-1 |
| W10 | 10 | 3.I-3, 5.11-2, 5.7-2, 4.5, P-E3, 5.14 tail, N-P5, 5.9 ACP, LIVE checks, 4.4 |
| W11 | 4 | 15b-14 i18n |
| Final | 1 | serial full runs, suite figure, README count, cross-lib suites |

**Critical path:** 1.B-1 → 1.B-2 → 1.B-3 → O-2e → O-2f → O-2g → O-3b/c → O-5b → O-6 → i18n → Final. The full per-wave tables (owned files, shared regions, doc overlaps), the rules and the step index are in Appendix R.

**Where the code goes:** each item names the main files. The appendices hold the full designs. Current anchors and files are in `prompt_kit/findings/crush-report/impact/`.

**Project obligations:**
- Wire dormant code rather than deleting it.
- Every new tool, command, env var or key binding needs its doc edit (drift tests).
- Each new test file goes into `scripts/parallel-tests-durations.tsv` during the waves. `suite-figure.json` is refreshed once, in the Final pass.

## 2.x — context engine (agent-aware context management)

| # | Item | Effort | Sources |
|---|---|---|---|
| 2.9 | **Absolute thresholds** on by default (DCP's 50k/100k "smart zone"; on a 1M window, 70% is far past the point where quality holds) with their settings keys and a thrash-breaker that cannot refuse every prompt under a cap; an absolute term in the step-level `ContextBudget` | S | DCP |

## 3.x — safety net and agent self-management

| # | Item | Effort | Sources |
|---|---|---|---|
| 3.A | **Workspace checkpoints**: optionally capture after each write step (`EngineBackend::runTurn`) | M | CC OC Kilo Cline Zed dsh |

## 4.x — sub-agents and orchestration

| # | Item | Effort | Sources |
|---|---|---|---|
| 4.3 | **Background Task** settle: a settled result is injected via steer when a turn is running (it waits for the turn's end today), and the announce stats line carries the resume id | S | Claw nano dsh Goose Kilo OC |

## 5.x — memory, codebase understanding, UX, integrations

| # | Item | Effort | Sources |
|---|---|---|---|
| 5.15 | Settings pane, configurable behaviours, server mode, web UI, session management, live agent lines and agent view | — | **Part V** |

---

# Part IV — Competitor approaches by theme

These are short pointers to how competitors built what a step needs. The full detail is in the cited appendix sections.

## IV.1 Turn loop, steering and recovery (→ 1.C-3, 1.C-4, 2.7, 0.10)

| Concern | Approaches |
|---|---|
| Retries after first token | Zed/OpenClaw continue from the partial transcript; Goose retries empty turns 3×; OpenClaw 10 attempts for rate limits |
| Interrupt | CC: Esc cancels only the running tool; steering is read at the next tool boundary |
| Steering | OpenClaw `steer/followup/collect/interrupt`; nanobot Enter=now / Tab=later; Goose drains steers before the next model call |
| End of budget | opencode `MAX_STEPS_PROMPT`; nanobot `BUDGET_EXHAUSTED_FINALIZATION_PROMPT` (tools disabled) |

Detail: Appendix B §2, C §2, J §2, K §2, L §2.

## IV.2 Sub-agents and messaging (→ 4.x, P-D)

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

Target: OpenClaw's tool surface plus dsh's send semantics, built on the dormant `Mailbox`, `TaskList` and `SuspendedDelegations`. Detail: Appendix L §3, M §3, I §3, E §3, K §3, C §3.

## IV.3 Context management and self-pruning (→ 2.x, 3.B)

| Concern | Approaches |
|---|---|
| When to check | before every request: Zed 90%, Cline 0.9, dsh `min(0.8W, W−O−65,536)`, Goose state machine; opencode after any step over the usable window; all of them on an overflow error |
| Counting | provider-reported usage of the last request + delta (Zed, DCP, dsh); Kilo `(system+tools)×1.3 + tail + reported` |
| First line of defence | prune old tool outputs: opencode/Kilo protect 40k / minimum 20k; OpenClaw soft-trim 30%, hard-clear 50%; dsh head 4096 / tail 1024; Cline duplicate-read dedup (≥30% savings, 64 KB batched rewrites); CC context editing (keep 3, `clear_at_least`) |
| Summariser | same system + tools + history + a final instruction (CC, dsh, nanobot); main model on a warm cache (DCP #387) |
| Summary shape | opencode 5-section anchored + merge; dsh 8 sections; OpenClaw Goal/Constraints/Progress/Decisions/Next/Critical + latest unresolved request + file lists + 16k cap + heading audit; Goose JSON with `pending_tasks/current_work/next_step`; Zed Goal/State/Context/Next/Pitfalls |
| What survives | token-budgeted tail (opencode `clamp(usable×0.25, 2k, 15k)`); the user's last ~80 KB verbatim (Zed); unsent tail verbatim (nanobot); re-injection of recent files, skills, git, plan (CC); "Required Files" re-read (Cline) |
| Display | non-destructive: Goose `agent_visible`/`user_visible`; Cline immutable transcript + separate compaction file; DCP ledger with undo |
| Agent self-control | DCP `compress` (range→summary, nested blocks) + `prune`/`distill`; Kilo legacy `condense` with user preview; OpenClaw memory flush |

**DCP essentials (Appendix D §4, §13.2):**
- Every message carries a hidden id.
- `compress` takes a closed range and the model writes the summary on its warm cache. The raw messages leave the next request, even within the same turn.
- Nested summaries are referenced by placeholder and expanded back.
- Outputs of task, skill and todo tools are re-attached verbatim.
- The automatic strategies (dedup, purge of failed-call inputs) run only when the model compresses, so the cache breaks once per compression.
- Reminders fire between 50k and 100k tokens and are anchored to fixed messages.
- State is projected before every call, so decompress and recompress are flag flips.
- Bug-tracker lessons:
  - #573: summary snowball;
  - #614: duplicated id tag broke caching;
  - #615: non-unique tool-call ids;
  - #520: reminders appended to assistant messages were rejected.

## IV.4 Cache-stable prompts (→ 1.A, 5.10)

The common pattern is a byte-stable system prompt, with volatile context appended as a user-side message:
- **Claude Code:** startup git snapshot; mid-session system-reminders; CLAUDE.md frozen until `/clear` or `/compact`.
- **Goose:** `<turn-context>` agent-only user message once per turn; date rounded to the hour.
- **nanobot:** `[Runtime Context — metadata only, not instructions]` suffix.
- **dsh:** user-role snapshot only when it changed; plan mode keeps the tool list identical.
- **opencode v2:** frozen per-session baseline plus `[System update]` deltas.

Useful turn-context fields:
- Cline: recently modified files, context usage at ≥60%, local time with timezone.
- Goose: `<compaction>~Nk tokens remaining`, `<turn-budget>N/M used` at ≥50%.

Keep sugar-crush's richer `<env>` and repo map; only move the volatile parts out of message 0.

## IV.5 Memory (→ 0.6, 5.1–5.4, 2.11)

| Product | Approach |
|---|---|
| Claude Code | `MEMORY.md` index (200 lines / 25 KB) injected every session; typed notes; topic files read on demand |
| Kilo (current) | auto-consolidation at turn close (5-min throttle, 24-message window) with skip reasons and "prefer saving nothing"; secret redaction; `memory_save`/`memory_recall`; "context, not instruction" |
| OpenClaw | `MEMORY.md` + daily files; hybrid FTS+vector search, mandatory before answering about prior work; memory flush before compaction |
| nanobot | compaction → tagged `history.jsonl` → periodic **Dream** pass edits memory/skills, git-versioned with log and restore |
| OpenHands | two-tier guidance (repo vs user), 6,000-char budget |

## IV.6 Checkpoints, undo and commits (→ 3.A, 3.G, 3.H)

| Product | Approach |
|---|---|
| Aider | auto-commit per edit; weak-model Conventional-Commits message; `Co-authored-by`; commits dirty user changes first; `/undo` with 5 refusals (do not copy its default of skipping pre-commit hooks) |
| opencode | shadow git dir; `write-tree` per step; per-file revert; `/undo` restores the prompt into the input; `/redo` |
| Cline | 4.x `git stash create` + untracked files via a scratch index → private ref; restore files, chat or both; refuses if HEAD moved |
| Zed | checkpoint before each user message (temp index, untracked <2 MB, binary ignore list); "Restore" only when it would change something (unpinned refs can be gc'd) |
| Claude Code | per-prompt snapshots of edited files (100 kept); restore code, conversation or both |

---

# Part V — Requested additions: settings pane, configurability, server mode, web UI, sessions and agent view

> **Decisions recorded (user, 2026-10-01)**
> 1. **Package name:** keep `sugar-crush-web`. Record it in `PROJECT_NAMES.md` as an "app satellite `<app>-<surface>`" exception.
> 2. **Server port:** default `7420`.
> 3. **TUI permission default:** moves off `bypass-permissions` once engine-path approvals (Wave 1.C / server Phase 1) work.
> 4. **Model choice:** the settings editor **persists the model choice**. This reverses the `docs/SETTINGS.md` "no model is persisted" contract, so update that doc and its drift tests in the same change.
> 5. **Settings file:** the editor writes **`config.json`**, the file the app already writes (`Bootstrap::writeUserConfig`, `Bootstrap.php`). The "settings.json is never written" invariant and the current precedence stay unchanged.
> 6. **Web build:** `sugar-crush-web/dist/` **is committed**, like the GIFs; CI checks it matches the source.
> 7. **Still open:** whether TLS via a reverse proxy is enough for v1 (default: yes).

## V.1 Settings pane and configurability (Appendix N)

**What exists today:**
- **A settings view with a save door.** `/settings` (alias `/config`), the palette, the menu, or Enter on the sidebar open a full-band view of every key: its value, where it came from, and when a change applies. Field editing, reset, a tier switch, the save preview, the trust-grant confirm and `SettingsWriter` are bound to keys, and `/model <provider> <model>` saves through the same writer.
- **The current key set:** about 26 config keys, 25 `SUGARCRUSH_*` environment variables and 10 CLI flags. The report has the full inventory table: type, default, allowed tiers, env override, whether a change applies live / next turn / after restart, and how easy each is to edit in a UI.
- **Next-turn reload is mostly free.** The forked child re-reads the settings files each turn (`EngineBackend.php`), so making a key take effect on the next turn usually needs no new plumbing. `docs/SETTINGS.md` ("When a saved change takes effect") lists which keys apply live, next turn or after a restart.

**Behaviour that should become settings.**

The report lists **about 45 hard-coded constants**, each with file:line and a proposed key, type, default and tier.

**Recommended design:**
- **The `SettingsSchema` registry** (`SettingDefinition` rows, `src/Config/Settings/`) drives the editor form, and `LayeredSettings`' tier rosters are derived from it.
- **A full-band `SettingsEditor` view** with category tabs (Model & Provider, Agent loop, Context & Compaction, Permissions, Tools, Memory, Sub-agents, UI/Theme, and read-only Hooks/MCP, plus Server). It has fuzzy search, a provenance panel showing where each value came from, env-locked fields, live/next-turn/restart badges, reset-to-default, and a diff preview before saving.
  - **Built from libraries already in the dependency tree:** `candy-forms` (fields, groups, validators, `hydrate`), `candy-fuzzy`, `candy-focus`, `candy-mouse`, `sugar-veil`, `candy-sprinkles`, `candy-layout`, `candy-core` (`AtomicJsonFile`, i18n).
  - **Optional additions:** `sugar-diff` for the save preview and `sugar-toast` for feedback. The report advises against `sugar-dash`, because it would pull `candy-pty` into the runtime.
- Store keys **flat with dots** (`"compaction.autoPercent"`), because `LayeredSettings::merge` only merges one level deep.

## V.2 Server mode (Appendix O, §0–§6, §8)

**Decisions:**
1. **Protocol: one WebSocket per client, multiplexed across sessions, carrying JSON-RPC 2.0.**
   - It reuses `sugar-mcp`'s `McpMessage` codec, with subprotocol `sugarcrush.v1`.
   - Server→client traffic is `event` notifications. Each session has a durable event log with a monotonic `seq` in a `session_events` table in `session.db`. Streaming deltas are ephemeral; full values are durable.
   - Reconnect sends `resume: {sessionId: lastSeq}`.
   - Approvals are events: any client may answer, the first answer wins, and pending asks are re-sent on reconnect.
   - The method and event catalogue (§6) covers sessions, prompting, steering, cancel, tools, diffs, sub-agents, usage, compaction, settings get/set, slash commands, memory, todos and background agents. It also includes a version handshake and backpressure rules (watermarks, 1013 close).
2. **Headless core.** `Chat.php` (over 19,000 lines) and candy-core `Program::run` own the event loop and the terminal. A strangler-pattern extraction therefore moves non-UI logic into `src/Host/`, which holds `TranscriptStore`, `EventLog`, `SpendLedger`, `ContextMeter`, `TitleService`, `CompactionService`, `TurnRunner`, `TurnController`, `SessionHost`, `SessionHub` and the slash-command bodies (`Host\Commands`). Both `Chat` and the server are clients of it. The built-ins whose logic is still Chat's (`/compact`, `/budget`, `/model`, `/init`, `/new`, the session and settings pickers) answer `ui_only` over the wire.
   - `Bootstrap` holds more than 25 static, root-sensitive caches. So one server process handles **one project root**, and `serve` runs each other root in a workspace-host child process behind its gateway.
3. **Background mode:** the server re-adopts background daemons at boot through `BackgroundSupervisor::reconnect` (O-4b), as the TUI launch already does.
4. **TLS** through a reverse proxy in v1.
5. **Later phase:** an attached TUI (`sugarcrush attach`) still runs slash commands and compaction on its own copy of the transcript, and shows a turn another client starts only when it re-attaches: server-logic commands go to `command.exec`, local compaction is skipped, and foreign `message.*`/`turn.*`/`session.updated` events are applied live.

## V.3 `sugar-crush-web` (Appendix O §7)

**What exists:** the composer package `sugarcraft/sugar-crush-web` (Vite + Vue 3 + TypeScript + Pinia + vue-router), a one-class PHP shim (`SugarCraft\CrushWeb\Assets::distPath`) and a **committed `dist/`** that the Node CI job (`web.yml`) checks against the source. `sugarcrush serve` serves it on the same port, so PHP users need no Node. The multi-session UI is live:
- protocol types generated from `docs/protocol/sugarcrush.v1.schema.json` (`npm run gen:protocol`), a client that reconnects with backoff 0.5 s→15 s with jitter and resumes from its seq cursor;
- a sessions sidebar, a virtualised transcript with markdown and reasoning folds, tool cards with diffs, permission cards, a composer with queue / steer / interrupt, and a status bar with context, spend, model and permission mode;
- tabs and a tiled grid of live sessions (unfocused tiles narrated by the server), and a cross-session approvals drawer with browser notifications;
- a settings form generated from the server's `SettingsSchema`, with provenance, locks, apply-mode badges and a diff preview before saving;
- a sub-agent tree with an agent view, todo, background, workflow and memory panels, and a command palette;
- vitest, plus Playwright end-to-end tests against a real `serve` on the offline `EchoProvider`, whose `::tool <Name> <json>` prompts drive real tool calls.

## V.4 Sessions, live agent lines, agent view and direct chat (Appendix P)

**Live agent activity lines.**
- **What exists:** an `AgentLiveRegistry` in the parent draws one width-safe line per agent under its Task row (`└ ⠋ Grep "LoginController" · 7 tools · 0:12 · 4.1k tok`), with a set drop order on narrow terminals and the outcome glyphs ✓/✗/⏹/⏸. Each line is a click zone that opens the agent view.
  This is how opencode and Claude Code show running agents.

**Agent view and direct chat.**
- **What exists:** the read-only view. Clicking an agent line, Enter on a strip item or `/agent <id|name>` swaps the main transcript area for that agent's live transcript, tailed from the **per-agent JSONL transcript log written by the agent's own process**; Esc leaves it. The input box is that agent's composer, sending through the signed `AgentInbox` drained at the sub-agent's step boundaries; soft cancel, a hard cancel on a second press (SIGTERM, then SIGKILL), pause, stop-all, broadcast and "open as session" are live, and a sub-agent's permission question names the run that asked.
- **Still to build:** a draft per target; `Ctrl+X Enter` to send a result to the main chat and `Ctrl+Enter` to interrupt; the broadcast audience in the view's header and footer; a paused state on the live line; the view following a cold-resumed run's new id.
- **Finished agents**' child sessions (`kind='subagent'`, `parent_id`) become viewable and cold-resumable.
- **Web compatibility:** every DTO serialises to the same event envelope the server mode uses (`agent.spawned`, `agent.activity`, `agent.status`, `agent.message`), so the web UI gets the same features.

**Phases** (P §6.5):
- **A — sessions (M):** independent, so it ships first.
- **B — live lines (M).**
- **C — read-only agent view (M).**
- **D — direct chat and controls (M–L).**

## V.5 How the new features fit the Part III roadmap

These features are scheduled in Appendix R together with Part III.

---

# Part VI — Code-audit status and live checks

The five code audits have no open finding.

**Corrections to earlier assumptions:**
- Argument-scoped **permission rules** are implemented (`PermissionRule::matches`/`matchesShellSubject`, fail-closed on `$(…)`, backticks and redirects).

**Live checks** (W10-i, `scripts/provider-cache-live-probe.php`):

| ID | Check | Status |
|---|---|---|
| LIVE-X31b | one tool-calling `anthropic` request after the `/v1` fix | blocked: no `ANTHROPIC_API_KEY` (the wiring answers a dummy key with 401, not 404) |
| LIVE-A15b | Bedrock prompt-cache marks: creation tokens > 0, then read tokens > 0 | blocked: the `~/.aws` IAM user lacks `bedrock:InvokeModel` on the Claude Sonnet 4.6 inference profile |
| LIVE-A15v | Vertex prompt-cache marks (override the outdated default model) | blocked: no GCP credentials |
| LIVE-A21b | Gemini 2.5 output budget with `thinkingConfig` | blocked: no GCP credentials |
| LIVE-CH | cache-health notice after **three** consecutive replies with zero buckets | blocked: as LIVE-A15b (Bedrock) and LIVE-A15v (Vertex) |
