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
- **Appendix Q:** the open code-audit finding (15b-14, i18n).
- **Appendix R:** the execution plan (waves, ownership, rules, step index).

**Where things live:**
- The per-step file impact (exact files, method regions, forced docs and drift tests) is in `prompt_kit/findings/crush-report/impact/`.
- The source clones are in `/home/sites/crush-research-repos/`.
- Line anchors in the appendices may drift. Prefer method names.

---

# Part I — Key findings

1. **The forked turn's socketpair is used one way.** This causes several gaps:
   - Asks become denials in the TUI, which forces `bypass-permissions`.
   - Mid-turn prompts cannot steer the turn.
   - Esc kills the whole turn.
   - Running sub-agents cannot be messaged.

   Fix: the two-way frame channel (1.C; server Phase O-1), then DEF-MODE.
2. **The cache prefix is unstable.** `<env>` (git status/log, diffs) is re-rendered inside system message 0. SGLang hoists every history System row into message 0, and Custom leaves them as mid-history `system` rows. Fix: 1.A (verify with `cached_tokens`).
3. **DeepSeek/Qwen `reasoning_content` is dropped between tool steps.** Fix: 0.1.
4. **Tool history is replayed as assistant prose across turns.** Fix: 1.B. This blocks structured pruning, dedup and resumable approvals.
5. **Context is managed only at submit.** There is no step-level check and no overflow recovery, and `removeToolResults` is a no-op. Fix: the 2.x context engine, then agent self-pruning (3.B, Appendix D §13.2).
6. **A silent sequential tool kills the turn at 120 s.** Bash has no `timeout` parameter, and sequential tools send no heartbeat. Fix: 0.4.
7. **`bypass-permissions` with no file undo.** Fix: checkpoints (3.A), then DEF-MODE.
8. **Sub-agents are synchronous and closed off.**
   - Preset `model`, `effort`, `permissionMode`, `isolation` and `background` are inert.
   - A preset grant `Bash(git *)` does not restrict Bash on the live Task path (security gap, 4.2).
   - Fan-out is uncapped (0.16).
   - The infrastructure is dormant: `Mailbox`, `TeamManager`, `SuspendedDelegations`, `BackgroundSupervisor::reconnect`.

   Fix: 4.x, plus P-C/P-D for user-side control.
9. **Requested features:** a settings editor, server mode with `sugar-crush-web`, the session picker, live agent lines and an agent view with direct chat. They are designed in Appendices N–P and scheduled in Appendix R. All of them build on 1.C.

---

# Part II — Open problems and their steps

Severity is the user impact on the live default path.

| # | Severity | Problem | Steps |
|---|---|---|---|
| 1 | High | TUI asks become denials; default is `bypass-permissions`; plan mode unusable interactively | 1.C-1, 1.C-2, DEF-MODE, 5.7 |
| 3 | High | `reasoning_content` not sent back on tool-call steps | 0.1 |
| 4 | High | Cross-turn tool replay is lossy (tool output arrives as assistant prose) | 1.B-1, 1.B-2 |
| 5 | High | No in-turn context management or overflow recovery (sub-agents included) | 2.1, 2.2-1, 2.4-1, 2.7 |
| 7 | High | A silent sequential tool trips the 120 s watchdog; Bash has no `timeout` | 0.4-a, 0.4-b |
| 11 | Med-High | MCP results uncapped; no per-call MCP timeout | 0.5 |
| 12 | Med-High | Tool-call ids repeat on the DSML and MiniMax parsers | 0.2 |
| 13 | Medium | SugarCraft PR cadence sent to every project via Bash guidance | 0.3 |
| 15 | Medium | `removeToolResults` no-op; tail not token-budgeted | 2.2-1 |
| 16 | Medium | `removeNavigationSteps` drops user rows matching `rm`/`mv`/`ls`… *(inferred)* | 0.7 |
| 17 | Medium | `isFileReadMessage` guesses from content | 0.7 |
| 18 | Medium | Compaction summary keeps history, not state; no audit | 2.5 |
| 19 | Medium | Summariser uses a different prompt/prefix (no cache reuse) | 2.4-2 |
| 21 | Medium | Token estimate ignores system prompt and tool schemas; % thresholds fire too late on 1M windows | 2.1, 2.9, 5.6 |
| 22 | Medium | Sub-agent output unescaped, no "no authority" framing | 0.15 |
| 23 | Medium | Preset `model`/`permissionMode`/`effort`/`isolation`/`background` inert; preset grants match by name | 4.1, 4.2, 4.9, 4.3 |
| 24 | Medium | Parallel Task fan-out uncapped | 0.16, 4.7-3 |
| 25 | Medium | Edit exact-match only, terse errors, no staleness check | 0.11, 3.I |
| 26 | Medium | Read has no paging or line numbers | 0.12 |
| 27 | Medium | No retry after first token; no continuation on length stops | 2.7-2, 2.7-3 |
| 28 | Medium | Empty or reasoning-only reply ends the turn silently *(inferred)* | 0.10 |
| 29 | Low-Med | Interim assistant narration lost between turns | 1.B-2 |
| 30 | Low-Med | Daemons not re-adopted after restart | 4.3-3 |
| 31 | Low-Med | OpenAI provider never emits streamed tool calls; `anthropic` sends no tools and lacks `/v1` | X-31a, X-31b |
| 32 | Low-Med | `/model` switches provider, not model; provider switch drops the Task tool and `/rules` toggles | N-P3a, N-P3b |
| 33 | Low | Session-affinity header dormant; engine-path hooks get an empty `sessionId` | 0.13-a |
| 34 | Low | No Unicode-tag stripping; MCP stdio env unfiltered | 0.14-a, 0.14-b |
| 35 | Low | `/share` always fails (stub uploader); LSP tool has a null client | X-35a, 3.F |
| 36 | Low | No snapshot test of the assembled system prompt | 1.A-1 |
| 37 | Low | Silently ignored frontmatter/config keys | X-37a, N-P0-1, 4.1 |

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
| 2.2 | **Carry the in-turn prune ledger across turns** (2.2-2): the deterministic pruning (placeholders keeping tool + main arg, protected recent window, Task/Skill never pruned) runs inside a turn; its ledger is discarded at turn end, so the next turn re-prunes from the full history and the cache-stable rewrite it bought is lost | M | OC Kilo DCP Cline Claw dsh |
| 2.3 | **Path-keyed dedup and stale-read pruning**: newest Read of a path wins; Reads older than an Edit/Write of the same path are pruned; superseded Write `content` arguments are elided; inputs of failed calls older than N turns are blanked (DCP + Cline) | S–M | DCP Cline |
| 2.4 | **Summarise reusing the cache everywhere** (2.4-2): the in-turn step summary already sends the same system prompt + tools + history with a final "do not call tools" instruction; `/compact` and the 85% tier should do the same, defaulting to the main model with `SUGARCRUSH_SUMMARY_MODEL` (and a `summaryModel` key) as the option | M | CC dsh nano OH Zed |
| 2.6 | **Post-compaction re-injection**: the 5 most recently edited/read files (≤5k tokens each) or Cline's "Required Files" (≤8 files/100k chars), invoked skill bodies, fresh git snapshot, todo list, plan | M | CC Cline |
| 2.7 | **Context-overflow and length-stop recovery**: on a provider failure classified as `ContextOverflow`, prune maximally and retry once (twice in goose). On `finish_reason=length` without tool calls, continue with prefill (`continue_final_message` on SGLang) up to 3× (Aider/nanobot/Cline). Retry dropped streams with "Continue where you left off" (Zed/OpenClaw) | M | 9 reports |
| 2.9 | **Absolute thresholds** on by default (DCP's 50k/100k "smart zone"; on a 1M window, 70% is far past the point where quality holds) with their settings keys and a thrash-breaker that cannot refuse every prompt under a cap; an absolute term in the step-level `ContextBudget` | S | DCP |
| 2.10 | Ahead-of-need **background summarisation** at 70% so the 85% submit never blocks; splice in if the history fingerprint still matches | M | Aider |
| 2.11 | **Memory flush before compaction**: one silent tool-enabled turn that writes durable notes to memory, once per compaction cycle | S–M | Claw |

## 3.x — safety net and agent self-management

| # | Item | Effort | Sources |
|---|---|---|---|
| 3.A | **Workspace checkpoints**: optionally capture after each write step (`EngineBackend::runTurn`) | M | CC OC Kilo Cline Zed dsh |
| 3.B | **Agent self-pruning tools** (the user's headline request): `Prune{targets:[{ref,distillation?}],reason}` and `Compress{topic,ranges:[{from,to,summary}]}` over the DCP ledger/projector. Applied mid-turn through a `ledger` fork frame. Nested blocks with placeholders; size guard (`summary ≤ 0.5×source + 2000`, DCP #573); nesting cap 16k. Task/Skill outputs re-attached verbatim. Anchored nudges (never on an assistant message, DCP #520), cooldown after a compress. Commands `/context`, `/compress [focus]`, `/decompress bN`, `/recompress bN`, `/sweep [n]`, `/pruning auto\|manual\|off`. Default: strategies **auto**, Prune **auto**, Compress **manual** until evals show good behaviour. Optional `Recall` tool to bring back pruned content. Sub-agents get an ephemeral ledger. **Full design: Appendix D §13.2 (classes, schemas, tests, rollout phases 1–5).** Add Kilo-legacy `/compact --self` (the model writes the summary, the user previews in a Veil modal; don't repeat Kilo's re-summarise bug) | L | DCP Kilo Goose |
| 3.C | **Todo tool** wiring the dormant `SessionMeta::$tasks` (`TaskList` is the team queue, not a todo): whole-list replace, at most one `in_progress`, survives compaction, re-injected via the 1.A turn context every ~6 steps or when stale, shown in a dock pane. Goose's anti-over-use wording: "Never redo or re-verify completed work because of these notes" | S–M | 8 reports |
| 3.D | **Stop / SubagentStop / SessionEnd hooks** dispatched; a hook's JSON `continue: false` ends the turn and shows its `stopReason`; a block continues the turn (cap 8). `/goal <condition>` (judge via title backend, strict JSON, "claimed-but-unverified ≠ satisfied" — OpenHands) and `/grind` | M | CC Goose OH Cline dsh |
| 3.F | **Wire the LSP client** (`src/LSP/*`, dormant) for post-edit diagnostics (≤20 errors/file, 5 s wait) and Read outlines for large files (Zed) | M | OC CC Zed |
| 3.G | Opt-in **auto-commit** (`autoCommit: off\|turn\|edit`) with a weak-model Conventional-Commits message (`titleBackend`), dirty-commit of user changes first, `Co-authored-by`, never `--no-verify`; `/undo` with Aider's five refusals | M | Aider |
| 3.H | **Auto-test reflection**: `testCommand` + `autoTest`, up to 3 reflections, using Aider's `run_output` shape | M | Aider |
| 3.I | Edit staleness check (mtime/hash from the session read ledger) plus a "files changed since you read them" notice in the turn context. Optional `ApplyPatch` | M | OC Kilo Cline Zed Claw nano dsh |

## 4.x — sub-agents and orchestration

| # | Item | Effort | Sources |
|---|---|---|---|
| 4.1 | Honour preset `model`/`effort`/`permissionMode` (new `EngineBackend::withModel`, per-sub-agent `PermissionGate`); per-call `model` arg; `subagentModel` default; **fail loudly** on unsupported preset fields (dsh) | S–M | 9 reports |
| 4.3 | **Background Task** (`background:true` / preset `background`): returns `{agent_id}` at once ("DO NOT sleep or poll"). Runs via `BackgroundSupervisor` or `AgentWorkerPool`. On settle, a user-role announce row (`[Subagent '<label>' completed] … status from the runtime outcome (ok/error/timeout), stats line: runtime, tokens, cost, resume id`) is appended, and a turn is auto-dispatched if idle or injected via steer if busy. An "Active subagents" block appears in each turn context. | M–L | Claw nano dsh Goose Kilo OC |
| 4.4 | **Messaging tools** on the dormant `Mailbox`: `SendMessage{to, text, mode: steer\|followup\|note}` (steer a running child, wake an idle one, cold-resume a stored one via `SuspendedDelegations`), child→parent replies, `Subagents{list\|wait\|cancel}`, `InterruptAgent`; delivery at step boundaries through the 1.C seam; untrusted-peer framing | M–L | Claw dsh Kilo Goose nano |
| 4.5 | **Shared board** for parallel children (Kilo): `BoardRead`/`BoardPost` with INFO/ASK/RESULT/HOLD/VETO, notice appended to the next tool result | M | Kilo |
| 4.6 | **Teams**: construct `TeamManager` (`AgentManager::setTeamManager`), `team_*` tools on `TaskList` (claim/complete/dependencies); make `GroupInputCmd`/`CancelAgentCmd`/`ResumeAgentCmd`/`StopAllAgentsCmd` real | L | CC Cline dsh |
| 4.7 | Stop a child at 80–90% of its window with "wrap up or hand off" (Zed); depth 2–3 nesting with caps (CC 3/20, OpenClaw depth 5, 8 concurrent) | S–M | Zed CC OC Claw |
| 4.8 | Sub-agents as stored child sessions: navigable in the tab strip, typable into, promotable to background (opencode Ctrl+B) | L | OC |
| 4.9 | Worktree isolation for `isolation: worktree` presets and `/bg` (wire `WorktreeManager`, `withWorktreeRoot`, `BashEscapeDenyHook`) | M | CC Claw |
| 4.10 | Model-authored workflows: expose `WorkflowEngine` as a tool taking a YAML plan | M | OH dsh |

## 5.x — memory, codebase understanding, UX, integrations

| # | Item | Effort | Sources |
|---|---|---|---|
| 5.3 | **Memory search**: the dormant `embeddings` beside the FTS5 BM25 index (0.7 vector / 0.3 keyword, 30-day half-life, MMR); a relevance-ranked snapshot keyed on the latest user message; a mandatory "search memory before answering about prior work" prompt fragment | M | Claw |
| 5.4 | **Dream pass**: compaction summaries appended to a tagged journal; a periodic restricted-tool pass edits memory/skills | M–L | nano |
| 5.5 | **Symbol-level repo map in the prompt**: a byte-stable PerSession block beside the existing `RepoMapBlock`, over the `RepoMap` tool's extractor, graph and ranking — which first needs per-(from,to) edge aggregation or a cached rank to be turn-cheap | L | Aider |
| 5.6 | Pruned items in the `/context` breakdown, once the 3.B ledger records them | S | 8 reports |
| 5.7 | **Plan mode** made real: plan-mode prompt section, plans-dir write exception (the command guard exists), `PlanExit` + `ask_user` tools over the 1.C channel, `Alt+M` toggle (Shift+Tab is pane-prev), superseding "agent changed" reminder | M | OC Kilo Cline dsh |
| 5.9 | **ACP mode** (`sugarcrush acp`): stdio JSON-RPC so Zed, JetBrains and Neovim can host sugar-crush; reuse `McpMessage` framing | L | Zed |
| 5.11 | LLM exec reviewer / smart-approve for `auto` mode (title backend, JSON verdict, untrusted transcript; the 3-strike breaker exists); security findings force Ask even in auto | M | Goose Claw dsh OH |
| 5.12 | Optional bubblewrap sandbox for Bash on Linux | M–L | Zed |
| 5.13 | `FallbackProvider` with `fallbackModels` | M | Aider nano |
| 5.14 | Small UX: bell/OSC 9 notifications on turn end or approval wait; `/btw` side question; `/handoff` (new session seeded with a summary); `/newrule`; watch-files `AI!` comments; `$skill` per-turn injection (a session-scoped Ctrl+S picker exists) | S each | many |
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
- **Next-turn reload is mostly free.** The forked child re-reads the settings files each turn (`EngineBackend.php`), so making a key take effect on the next turn usually needs no new plumbing. Today only `parallelToolCalls`, `parallelToolDeadlineSeconds` and `maxOutputTokens` are re-applied that way. `docs/SETTINGS.md` names only the first two.

**Behaviour that should become settings.**

The report lists **about 45 hard-coded constants**, each with file:line and a proposed key, type, default and tier. The main ones:
- the 120 s idle watchdog, as `turnIdleTimeoutSeconds`;
- the compaction thresholds 70/85/95 and keep-10;
- the 64 KiB and 1 MiB output caps;
- the memory caps of 40 index entries, 4 KiB and 512 B;
- the private WebSearch default host;
- sub-agent max turns.

It adds **about 30 future knobs** that the Part III roadmap will create: Bash timeout, doom-loop thresholds, steering mode, `contextPruning.*`, `autoCommit`, `lintCommands`, `testCommand`, sub-agent model and concurrency, and notifications.

**Recommended design:**
- **The `SettingsSchema` registry** (`SettingDefinition` rows, `src/Config/Settings/`) drives the editor form, and `LayeredSettings`' tier rosters are derived from it.
- **A full-band `SettingsEditor` view** with category tabs (Model & Provider, Agent loop, Context & Compaction, Permissions, Tools, Memory, Sub-agents, UI/Theme, and read-only Hooks/MCP, plus Server). It has fuzzy search, a provenance panel showing where each value came from, env-locked fields, live/next-turn/restart badges, reset-to-default, and a diff preview before saving.
  - **Built from libraries already in the dependency tree:** `candy-forms` (fields, groups, validators, `hydrate`), `candy-fuzzy`, `candy-focus`, `candy-mouse`, `sugar-veil`, `candy-sprinkles`, `candy-layout`, `candy-core` (`AtomicJsonFile`, i18n).
  - **Optional additions:** `sugar-diff` for the save preview and `sugar-toast` for feedback. The report advises against `sugar-dash`, because it would pull `candy-pty` into the runtime.
- **The `SettingsWriter`'s remaining tier and UI:**
  - "Session" stays in memory.

  Edits made mid-turn apply from the next turn. Store keys **flat with dots** (`"compaction.autoPercent"`), because `LayeredSettings::merge` only merges one level deep.

**Phases** (full class, test and doc list in N §5):
- **P3 — live apply and session tier (M).**
- **P4 — promote the hard-coded constants (L, incremental).**
- **P5 — polish (S).**

## V.2 Server mode (Appendix O, §0–§6, §8)

**Decisions:**
1. **Protocol: one WebSocket per client, multiplexed across sessions, carrying JSON-RPC 2.0.**
   - It reuses `sugar-mcp`'s `McpMessage` codec, with subprotocol `sugarcrush.v1`.
   - Server→client traffic is `event` notifications. Each session has a durable event log with a monotonic `seq` in a `session_events` table in `session.db`. Streaming deltas are ephemeral; full values are durable.
   - Reconnect sends `resume: {sessionId: lastSeq}`.
   - Approvals are events: any client may answer, the first answer wins, and pending asks are re-sent on reconnect.
   - The method and event catalogue (§6) covers sessions, prompting, steering, cancel, tools, diffs, sub-agents, usage, compaction, settings get/set, slash commands, memory, todos and background agents. It also includes a version handshake and backpressure rules (watermarks, 1013 close).
2. **Headless core.** `Chat.php` (over 19,000 lines) and candy-core `Program::run` own the event loop and the terminal. A strangler-pattern extraction therefore moves non-UI logic into `src/Host/`, which already holds `TranscriptStore`, `EventLog`, `SpendLedger`, `ContextMeter`, `TitleService` and `CompactionService`; still to come are `SessionHub`, `SessionHost`, `TurnController` and `TurnRunner`. Both `Chat` and the server become clients of it.
   - `Bootstrap` holds more than 25 static, root-sensitive caches. So one server process handles **one project root**.
   - Multi-root support comes later, with one workspace-host child process per root.
3. **Background mode:**
   - **Commands:** `sugarcrush serve [--detach]`, `serve status|stop|logs|url|token`.
   - **Daemon plumbing:** reuses `BackgroundSupervisor`'s double-fork, `setsid` and 0600 IPC idioms (moved into `Support\Daemonize`), with a pidfile plus a process-start-time check.
   - **Reconnect:** `BackgroundSupervisor::reconnect` gets its first caller at boot.
4. **Security defaults still to wire:**
   - server sessions default to `default` (ask) mode, and `bypass-permissions` is refused over the wire unless `--allow-bypass` is given (`ServerConfig::admitsPermissionMode()` exists; the O-3b dispatcher must call it for `session.create`/`setMode`);
   - TLS through a reverse proxy in v1.
5. **Later phases:**
   - `sugarcrush attach` lets the TUI act as a client.
   - `sugarcrush acp` is an Agent Client Protocol stdio adapter (about 8 methods) so Zed and JetBrains can host sugar-crush.

## V.3 `sugar-crush-web` (Appendix O §7)

**Stack:** Vite + Vue 3 + TypeScript + Pinia + vue-router.

**Packaging:**
- It ships as a **composer package** `sugarcraft/sugar-crush-web` with a one-class PHP shim (`SugarCraft\CrushWeb\Assets::distPath`) and a **committed `dist/`**.
- `sugarcrush serve` serves the UI on the same port, so PHP users need no Node.
- `scripts/affected-libs.php` discovers it through `composer.json` + `phpunit.xml`, and splitsh sync works unchanged.
- A Node CI job (`web.yml`) checks that `dist/` matches the source.

**Code layout:**
- TypeScript lives in `src-web/`.
- `protocol/` holds the generated types from `docs/protocol/sugarcrush.v1.schema.json`, the client, reconnect logic (backoff 0.5 s→15 s with jitter) and the seq cursor.
- Pinia stores: connection, sessions, session, approvals, layout, settings.

**UI:**
- a sessions sidebar;
- tabs **and** a tiled multi-pane grid for watching several sessions at once;
- a virtualised transcript with markdown, code and reasoning folds;
- tool cards with diffs;
- permission cards plus a **cross-session approvals drawer** with browser notifications;
- a composer with queue / steer / interrupt;
- a status bar showing context %, spend and cap, model and permission mode;
- a sub-agent tree, background tasks, workflows, a memory panel and a command palette;
- a **settings form generated from the server's settings schema** — the same `SettingsSchema` as V.1, so the TUI and the web share one source of truth.

**Testing:** vitest, plus Playwright end-to-end tests against the offline `EchoProvider` with scripted tool calls.

**Server and web phases** (O §9): 0 spikes (S) → 1 bidirectional fork channel and TUI approvals (L) → 2 Host extraction (L) → 3 server and protocol (L) → 4 daemon and background agents (M) → 5 web MVP (L) → 6 multi-session polish (L) → 7 multi-root workspace hosts (M–L) → 8 `attach` and ACP (M–L).

**Effort:** about 7–9 weeks for one engineer to reach phase 6, and about 9–11 weeks through phase 8.

## V.4 Sessions, live agent lines, agent view and direct chat (Appendix P)

**Live agent activity lines.**
- **What exists:** an `AgentLiveRegistry` in the parent draws one width-safe line per agent under its Task row (`└ ⠋ Grep "LoginController" · 7 tools · 0:12 · 4.1k tok`), with a set drop order on narrow terminals and the outcome glyphs ✓/✗/⏹/⏸.
- **Design (still to build):** the Task row as an `agent:<id>` click zone, and `agent:` zones on the dashboard's run rows (the strip above the input and the per-instance dashboard rows exist).

  This is how opencode and Claude Code show running agents.

**Agent view and direct chat.**
- **What exists:** the skeleton (`AgentViewMode::{List,Peek,Attach}`, `AgentOutputPane::renderAttach`) is **unreachable**. The cancel, resume, stop-all, group-input and quit-view commands are inert, and `Mailbox` is dormant.
- **Opening the view:** clicking an agent line (a `candy-mouse` zone keyed only by the safe agent id) or pressing Enter on it swaps the main transcript area for that agent's live transcript. The parent tails a **per-agent JSONL transcript log written by the agent's own process**, which keeps transcript volume off the frame channel.
- **Messaging the agent:** the input box becomes that agent's composer. Messages go through an `AgentInbox` built on the dormant `Mailbox`, HMAC-signed `from:'user'`, framed as untrusted, and drained at the sub-agent's step boundaries.
  - That uses the same `TurnInbox` seam as the Wave 1.C steering, **but does not depend on it**.
- **Controls:** soft cancel (an inbox control message), then hard cancel (SIGTERM via the turn child); pause, capped at 10 min with heartbeats; stop-all; broadcast; and "open as session".
- **Finished agents** become child sessions (`kind='subagent'`, `parent_id`) that you can view and cold-resume.
- **Web compatibility:** every DTO serialises to the same event envelope the server mode uses (`agent.spawned`, `agent.activity`, `agent.status`, `agent.message`), so the web UI gets the same features.

**Phases** (P §6.5):
- **A — sessions (M):** independent, so it ships first.
- **B — live lines (M).**
- **C — read-only agent view (M).**
- **D — direct chat and controls (M–L).**
- **E — hard cancel, approval relay and background (L):** needs 1.C, 4.3 and server mode.

## V.5 How the new features fit the Part III roadmap

These features are scheduled in Appendix R together with Part III:
- Live apply (N-P3, and the provider-level model rebind left from N-P3b) runs W6.
- The host extraction O-2g–O-2h runs W6–W7, then the protocol (W7), web MVP (W8) and multi-session web (W9).
- The agent view and direct chat (P-C, P-D, P-E) run W6–W10.

---

# Part VI — Code-audit status and live checks

The five code audits have one open finding (Appendix Q): **15b-14**, sugar-crush has no i18n (Low). It is deferred by decision until after the roadmap: 15b-14-1 runs in W10 and 15b-14-2…4b in W11 (Appendix R).

**Corrections to earlier assumptions:**
- Argument-scoped **permission rules** are implemented (`PermissionRule::matches`/`matchesShellSubject`, fail-closed on `$(…)`, backticks and redirects).

**Live checks** (W10-i, `scripts/provider-cache-live-probe.php`):

| ID | Check | Status |
|---|---|---|
| LIVE-0.1 | `cached_tokens` before/after a write step on skynet2 (SGLang `--enable-cache-report`) | runnable |
| LIVE-X31b | one tool-calling `anthropic` request after the `/v1` fix | runnable |
| LIVE-A15b | Bedrock prompt-cache marks: creation tokens > 0, then read tokens > 0 | `~/.aws/credentials` present; model access unverified |
| LIVE-A15v | Vertex prompt-cache marks (override the outdated default model) | blocked: no GCP credentials |
| LIVE-A21b | Gemini 2.5 output budget with `thinkingConfig` | blocked: no GCP credentials |
| LIVE-CH | cache-health notice after **three** consecutive replies with zero buckets | Bedrock maybe; Vertex blocked |
