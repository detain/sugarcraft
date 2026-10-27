# comp-claude-code — Claude Code column refresh (2.1.301 → 2.1.311)

Read-only research for `candy-code/COMPARISON.md`, 2026-10-26, HEAD 5e9195687. Evidence shorthand follows the doc's own Appendix A convention: `cc:<page>#anchor` = `https://code.claude.com/docs/en/<page>` (all 228 unique pages re-fetched 2026-10-26 from `https://code.claude.com/docs/llms.txt`); `CL v2.1.xxx` = that release in `https://raw.githubusercontent.com/anthropics/claude-code/main/CHANGELOG.md`; `help (…)` = `claude … --help` on 2.1.311; "observed in-session" = this agent's own Claude Code tool roster and system context; "local" = read-only commands on this host.

## Summary

- **Latest release is 2.1.311** (published 2026-10-26: npm `latest`/`next` = 2.1.311, GitHub release `v2.1.311` at 21:51Z, `downloads.claude.ai/claude-code-releases/latest` = 2.1.311). npm `stable` is **2.1.308**. 2.1.297/2.1.298 were never published. Recommended column header: **`Claude Code 2.1.311`**.
- Eight releases since 2.1.301. 2.1.303 and 2.1.308 are bug fixes only, and 2.1.306 has two fixes. 2.1.302, 2.1.304, 2.1.305, 2.1.307, 2.1.309, 2.1.310 and 2.1.311 carry real changes. This is mostly a hardening and consistency run, with no new product surfaces. The largest user-visible changes:
  - `-p`/SDK runs default to Manual.
  - Fork/background subagents everywhere.
  - Tool search behind gateways.
  - MCP tasks public beta.
  - Per-file cap on on-demand CLAUDE.md files.
  - `claude_code.safety_event`.
  - `/usage` one-key fixes.
  - `ultrathink` really raising effort.
  - A signed model catalog for cloud providers.
  - A batch of sandbox and permission-check hardening.
- **Cell changes: 24 cells change text, 1 changes glyph** (CMD-12 🟡→✅). In 11 more rows only the Appendix A detail changes (the `new:` repeats the current cell). Scoreboard effect on the Claude Code column: CMD `✅10 🟡2` → `✅11 🟡1`; Total `✅276 🟡53 🔌6 ❌21` → **`✅277 🟡52 🔌6 ❌21`**. All other categories are unchanged.
- **Re-verified unchanged:**
  - Hooks: 33 events, 5 handler kinds (command/http/mcp_tool/prompt/agent; agent still experimental).
  - MCP: transports stdio/SSE/HTTP/WebSocket; elicitation yes, sampling no; scopes; `mcp serve` is stdio-only.
  - Official marketplace: still **315** entries (local catalog refreshed 2026-10-26 and upstream `marketplace.json` both = 315).
  - Skills: 20 bundled; `.agents/skills` still not read; `compatibility` still not enforced.
  - Auto memory: no consolidation or "dream".
  - Auto mode: still the interactive default.
  - Agent teams: still experimental, behind `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS`.
  - Fast mode: docs still say research preview; only the `/fast` dialog title dropped the label.
  - Unchanged surfaces and features: vim mode, keybindings, status line, output styles (Default/Proactive/Concise/Explanatory/Learning), checkpoints/rewind, worktrees, the Remote Control/web/desktop/mobile/Slack surfaces, and agent view (research preview).
- **New 🆕 row candidates: 4** (MCP-N2 MCP tasks/long-call backgrounding, WEB-N6 deep links, PLG-N3 contextual plugin recommendations, OBS-N1 usage-optimisation advice).
- **Narrative fixes: 16.** These cover headers and version labels, §1 "trained classifier", §4.1 size/nested rows, §4.5 count, §4.7 tool search + MCP tasks, §4.8, §4.9, §4.10 (`-p` Manual), §4.12, and Appendix B.
- **Pre-existing errors found (already wrong at 2.1.301):**
  - CMD-12: the `command:<name>` keybinding action exists. A 2.1.309 fix references "key-bound slash commands", so the feature predates 2.1.309.
  - §1 "trained classifier": auto mode uses server-side review, or a client-side model (Sonnet 5 by default).
  - TL-24 undersold artifacts: comments, live connector data, a per-page DB and the Docs type.
  - The TL-10 detail said Monitor was unavailable on Bedrock/Vertex/Foundry. That was true at 2.1.301 and is false now.
- **UNVERIFIED items:**
  - Whether `command:<name>` binds custom skills and commands, or only built-ins.
  - Whether the stale-resume "summarize" choice survives next to the new "Resume this conversation?" dialog.
  - What mechanism "Claude can look up the previous conversation" refers to (sessions.md, 2.1.304). This could affect MEM-12 if it is a real session-search tool. It is not in this session's tool roster.
  - When docs renamed routines to "cloud scheduled tasks". The changelog and UI still say "routines".
- **Side observation:** `claude daemon --help` on 2.1.311 says "Service install is disabled in this version — the daemon runs on demand and exits when the last client disconnects". Agent-view docs still say sessions survive sleep but stop on shutdown, so PA-01 and WEB-N4 stand. No cell was changed.

## Changelog digest

Category tags map each item to a COMPARISON taxonomy category. Changes to cloud-only and admin surfaces (Claude Tag, Code Review, cloud sessions) are included where they touch a row.

**2.1.311 (2026-10-26)**
- [MOD] In the interactive terminal on Anthropic's API, `ultrathink` runs its turn at **xhigh effort** on Opus/Sonnet/Haiku 5.5 and Fable 5.1. Typed mid-turn, it raises the rest of the turn, and its highlighting appears only where it can raise effort. → MOD-15
- [MOD] Bedrock/Mantle/Vertex/Foundry load model settings from a **signed catalog file on downloads.claude.ai** (`CLAUDE_CODE_MODEL_CATALOG=off`). The `[1m]` suffix and label are dropped where they have no effect; the suffix is still accepted. → MOD-10
- [CTX] A standard-context session that runs over 200K now compacts and continues instead of stopping with "Usage credits required for 1M context". Compaction asks again when the reply has no summary. `DISABLE_COMPACT` in settings or mods can only turn compaction off. → CTX-03/05/13
- [SH] A shell command that reaches its timeout returns the output so far and says it is still running in the background. → SH-03, TL-10
- [AUT] `--output-format json/stream-json` escape DEL, C1 and Unicode format chars as `\uXXXX`. In `claude -p`/SDK, transcripts no longer save out of order. → AUT-02, SES-01
- [AUT/WEB] `self-hosted-runner --hold-idle-file / --max-hold-min` keep an idle session while the host reports leftover processes. → WEB-12
- [MCP/AGT] Subagents can use MCP tools from servers that finish connecting after the subagent starts. The agent-teams task list survives `--resume`/`/clear`, and teammates honour `DISABLE_COMPACT`. `SubagentStop` fires once, at finish. → MCP-11, AGT-16, HK-02
- [PLAT] Faster startup for Pro/Max relaunches within an hour, and for options-only launches. `claude purge` states that it deletes local data only. The daemon error now suggests `claude daemon stop --any`, because service restart is disabled. → PLAT-09, SES-17
- [TL] Artifact multi-view pages route on `#name`. The page-vs-document choice now sends documents meant for editing to Claude Docs. → TL-24
- [UI] Ctrl+D twice exits agent view. A tip for the Explanatory output style. Scrollbar clicks act on release. Screen-reader wording improvements. → UI-30, PRM-10
- [WEB] VS Code: syntax-colored code blocks; sessions start with `DISABLE_TELEMETRY` when VS Code telemetry is off; a "Running UserPromptSubmit hooks" status. Claude Tag: a Skills analytics view, and routines can end without posting. Code Review: retry messaging. → UI-04, PLAT-07, WEB-06/09, GIT-12

**2.1.310 (2026-10-23)**
- [TL/PLG] The **Monitor tool and plugin monitors** now work on Bedrock, Vertex and Foundry, and with telemetry or nonessential traffic off. Long foreground sleeps are blocked there too. → TL-10
- [MOD] Gateways that reject the `effort-2025-11-24` beta or mid-conversation system messages are retried without them. → MOD-06/18
- [PER] Auto mode's first outside-workdir read prompt explains the consequence for Bash. Read prompts show where symlinks lead. On Windows, PreToolUse/PermissionRequest hooks no longer fail open without Git Bash. → PER-07/08, HK-09
- [SH] `/sandbox` and the startup warning name Ubuntu's AppArmor user-namespace restriction and how to lift it. → SH-06
- [MCP] When an elicitation goes unanswered in a non-interactive session, the timeout error tells Claude not to repeat the call. → MCP-08
- [OBS] `tool_decision` gains `agent_id`/`parent_agent_id`/`request_id` (cc:monitoring-usage "requires v2.1.310"). → OBS-02
- [WEB] Enterprise custom roles gain per-role cloud-session and Remote Control permissions. The cloud-env setup script can be loaded from a file. → WEB-11/12, PER-13
- [UI] A mod's `Image` draws inside tmux with passthrough (kitty, Ghostty, iTerm2 3.5+). → UI-27

**2.1.309 (2026-10-22)**
- [SH/PER] Sandbox and permission hardening:
  - Shell startup files now run inside the sandbox.
  - macOS `denyWrite` globs apply through symlinked folders.
  - The `--mcp-config` file is write-protected.
  - Bash checks close redirect and wrapped `cd`+git gaps, and `Edit(path)` deny now beats subshell redirects.
  - Worktree isolation refuses locale-prefixed and zsh-arithmetic escapes.
  - On Linux, sandbox write denies hold after a background command outlives the session.
  - On Windows, PowerShell `cd`/`Set-Location` prompts.
  → SH-06/10, PER-03/08, AGT-18
- [INS] **A nested CLAUDE.md or on-demand rules file over a per-file cap loads only its start**, with a Read pointer. This stops repeated compaction. → INS-08/18
- [EDT/GIT] `sed -i` now gets the normal Bash prompt instead of a simulated edit preview, and `/rewind` no longer restores it. → EDT-03, GIT-05
- [GIT/AUT] `claude ultrareview` requires workspace trust (`--trust-workspace`, `CLAUDE_CODE_DISABLE_ULTRAREVIEW_TRUST_CHECK=1`). → AUT-05, GIT-10
- [PER] In Desktop/VS Code bypass mode, a dangerous `rm`/`rmdir` is denied at once with a rewrite hint. → PER-12
- [SKL] When an `--add-dir` skill's name clashes, it is offered as `<folder>:<name>`. The dataviz skill gains APCA. `/claude-api` gets tracing and Managed Agents `multiagent_20261001` updates. → SKL-02/08
- [HK] PowerShell hooks honour exit 2. → HK-05/09
- [AGT] Split-pane teammates inherit the leader's `--tools` and deny rules. → AGT-16
- [UI] Ctrl+Enter send-now fixes. Ctrl+O shows cross-agent messages that are still pending. Key-bound slash commands no longer run in `!` mode (evidence for CMD-12). → UI-13, CMD-12
- [GIT] Code Review now always leaves a completed check, and `@claude review stop` closes queued reviews. → GIT-12

**2.1.308 (2026-10-22)**: bug fixes and reliability only.

**2.1.307 (2026-10-21)**
- [OBS/PER] **New OTel event `claude_code.safety_event`**, logged for every tool call that a rule, auto mode, a safety check, a hook or the sandbox refuses, prompts or sends back (including network-proxy refusals). → PER-14, OBS-02
- [MCP] After a dropped HTTP connection, a pending MCP call is retried only for `readOnlyHint`/`idempotentHint` tools. Servers interrupted by sleep while connecting no longer stay failed. → MCP-03
- [MOD] An unparseable tool call is retried up to twice before the turn ends. → MOD-18
- [AGT] Concurrent fork subagents are told which launch is theirs. → AGT-09
- [PER] `/permissions` → Recently denied names the command, and the local classifier honours approvals made there. → PER-07/14
- [SKL] `/claude-api api-version` migrates code to API version 2026-10-21. → SKL-08
- [GIT] Code Review takes `@claude review stop|off|pause`. → GIT-12
- [WEB] Self-hosted runner checkout hooks time out after 30 min by default. VS Code dictation explains auto-stop. → WEB-12/06

**2.1.306 (2026-10-21)**: two fixes. `claude -p` inside cloud sessions no longer says "Not logged in", and many MCP tools no longer mask an API error as "prompt too long".

**2.1.305 (2026-10-20)**
- [SH] `sandbox.allowNestedUserNamespaces` (Linux/WSL2, user settings only) lets Chromium or rootless podman nest user namespaces, weakening file protections. → SH-06
- [MOD] `CLAUDE_CODE_RETRY_WATCHDOG_USAGE_LIMIT_RECHECK_MS` lets the unattended retry notice that a usage limit was lifted early. → MOD-18
- [OBS] `/usage` warnings become one-line fixes, and **`f` applies them**. The same fixes appear in VS Code and `/doctor`. → OBS-03, OBS-N1
- [MCP] `allowedMcpServers` no longer filters `managed-mcp.json` stdio servers that use `${VAR}` only in `env`. A stray Enter is less likely to accept an MCP consent dialog. A same-named settings server no longer replaces SDK/cloud servers. → MCP-09/10
- [AUT] Workflow resume serves completed `pipeline()`/`parallel()` agents from the journal. → AUT-10
- [WEB] Remote Control spawn mode (`w`) is saved per folder. → WEB-04
- [UI] Startup warnings go to the debug log, and no-op permission rules show as rows under the banner. A docked mod pane is capped at 45% width. The mod `Image` draws in iTerm2 3.5+. → UI-33/27
- [PLG] Plugins that Desktop mounts by path lose `allowed-tools` under `allowManagedPermissionRulesOnly`. → PLG-01, PER-13

**2.1.304 (2026-10-19)**
- [MCP] **Tool search on by default behind a custom `ANTHROPIC_BASE_URL`** (a 400/422 is retried without it). **MCP tasks are in public beta** in the CLI, SDK and GitHub Actions (cc:mcp "requires v2.1.304"). → MCP-12, §4.7, MCP-N2
- [PER] `/auto-mode-setup` also works on Bedrock/Vertex/Foundry, apps-gateway and telemetry-off sessions. → PER-07
- [PLG] Mods:
  - `tool.check` can answer `decider: "person"`.
  - The event carries `e.isReadOnly`.
  - `settings.read` hooks see each settings layer, which enables managed ceilings.
  - A transcript line appears when a plugin skips a mod hook.
  → PLG-N1
- [SKL] The skill list is re-sent after compaction, and `/reload-skills` sends only new or changed skills. Skills from one source can register at most 1,024 hooks and 16,384 matcher chars. Adds `/claude-api managed-agents-secure`. → SKL-04/13, HK-06, CTX-05
- [SES] On Pro/Max, a **"Resume this conversation?" dialog** estimates what share of the 5-hour limit resuming an inactive conversation would use. → CTX-N2
- [CTX] The Ctrl+O compaction summary names the tools called. `CLAUDE_CODE_AUTO_COMPACT_WINDOW` accepts `k`/`M`. → CTX-03/05
- [GIT] On exit, `claude --worktree` no longer silently deletes a worktree that is detached or on another branch. → GIT-08
- [AUT] Plugins can no longer keep a headless run going past `--max-turns`/Stop. → AUT-04
- [MOD] The `/fast` dialog drops "(research preview)" on Opus 5.5 (cc:fast-mode still says research preview). → MOD-N2
- [GIT] Code Review gains **review levels Quick/Standard/Deep** per org or repo, and an "all repos in the org" option. → GIT-12
- [WEB] VS Code shows background subagents as one status line. → WEB-06

**2.1.303 (2026-10-18)**: bug fixes and reliability only.

**2.1.302 (2026-10-16)**
- [PER] **`CLAUDE_CODE_AUTO_MODE_MODEL`** picks the client-side classifier model. Server-side review compacts conversations too long to check instead of denying every call. → PER-07
- [PER/AUT] **`claude -p` and SDK runs with no permission mode start in Manual (`default`)**. → PER-01, AUT-01, §4.10
- [AGT] **Fork subagents are on by default in `-p`, the SDK, desktop and VS Code** (`CLAUDE_CODE_FORK_SUBAGENT=0`; never via `claude mcp serve`). Desktop and VS Code always run main-conversation subagents in the background, while a subagent's own Agent calls run in the foreground there. → AGT-13, AGT-10
- [CTX/CFG] Adds an **Idle compaction row in `/config`**. → CTX-N2, CFG-02
- [HK] At most 12 prompt/agent hooks of one event run at once. → HK-03
- [MCP] Servers on protocol 2026-07-28 that omit `resultType`/`ttlMs`/`cacheScope` are tolerated. → MCP-03
- [TL] Read refuses image sources over 128 MB. `/design-sync` and `/design-login` warn that standalone Claude Design closes on 2026-12-14. → TL-19, TL-24
- [PRM] Claude in Chrome instructions move into the shared cached prompt. → PRM-N1
- [OBS] `classifier_category` is restored on `tool_decision`. → PER-14
- [WEB] A routine page shows why its last run didn't start. Cloud "Rewind to here" works on older messages. → AUT-07, GIT-06

## Cell changes

Column header for every block: `Claude Code 2.1.311`. The first 24 blocks change the cell text. The last 11 blocks change only the Appendix A detail, so their `new:` repeats the current cell verbatim. Every `new:` summary was checked at ≤130 characters.

### PLAT-01 · Claude Code 2.1.311
- current: `✅ TypeScript app shipped as a native single-file executable (Bun-compiled, ~256 MB); npm package run`
- new: ✅ TypeScript app shipped as a native single-file executable (Bun-compiled, ~280 MiB); npm package runs on Node
- detail: ✅ — The native installer keeps versioned binaries under `~/.local/share/claude/versions/` with a launcher symlink at `~/.local/bin/claude` (observed: ELF x86-64; 2.1.311 = 293,776,752 bytes, `du` 281M; the binary embeds Bun v1.4.4). The npm package `@anthropic-ai/claude-code` is still a supported install path and runs under Node (npm `latest` 2.1.311, `stable` 2.1.308). Bundles ripgrep; on macOS/Linux/WSL the shell's `find`/`grep` are embedded `bfs`/`ugrep`. The Agent SDK (TS/Python) drives the same CLI as a subprocess. — _Evidence:_ local: `ls -la ~/.local/share/claude/versions/`, `du -sh …/2.1.311`, `file ~/.local/bin/claude`, `grep -a 'Bun v1.'`; `npm view @anthropic-ai/claude-code dist-tags`; cc:setup; cc:tools-reference#glob-tool-behavior
- why: binary grew from ~256 MB (2.1.301) to ~280 MiB (2.1.311)

### PLAT-09 · Claude Code 2.1.311
- current: `🟡 Heavy install (~256 MB binary); `--bare` / `--safe-mode` trim startup; no published startup benchm`
- new: 🟡 Heavy install (~280 MiB binary); `--bare` / `--safe-mode` trim startup; no published startup benchmarks
- detail: 🟡 — The 2.1.311 binary is ~280 MiB. Measured locally: `claude --version` ≈10 ms, 36 MB RSS (fast path only, not a full session start). `--bare` skips hooks, LSP, plugin sync, attribution, auto-memory, background prefetches, keychain reads and CLAUDE.md discovery (sets CLAUDE_CODE_SIMPLE=1); `--safe-mode` disables customizations. Pro/Max relaunches within an hour of the last check skip two network requests, and launches whose only arguments are options start faster. `/heapdump`; `CLAUDE_CODE_TOOL_MEMORY_LIMIT` caps Bash/Monitor memory via cgroup on Linux. — _Evidence:_ local `du -sh ~/.local/share/claude/versions/2.1.311`, `/usr/bin/time claude --version`; help (`--bare`, `--safe-mode`); CL v2.1.311; cc:tools-reference#memory-limit-on-linux-and-wsl
- why: install size figure stale (~256 MB → ~280 MiB); 2.1.311 startup trims

### MOD-10 · Claude Code 2.1.311
- current: `🟡 Claude-only catalog via aliases (default, best, fable, opus, sonnet, haiku, [1m], opusplan) over ~`
- new: 🟡 Claude-only aliases (default, best, fable, opus, sonnet, haiku, [1m], opusplan); 3P routes use a signed catalog; no models.dev
- detail: 🟡 — Provider routes: Anthropic API, Bedrock (incl. Mantle), Agent Platform, Foundry, Claude Platform on AWS, gateways. Current API defaults: Opus 5.5, Sonnet 5.5, Haiku 5.5, Fable 5.1. On Bedrock, Mantle, Vertex and Foundry the model settings are loaded from a signed catalog file on downloads.claude.ai (`CLAUDE_CODE_MODEL_CATALOG=off` turns it off); the `[1m]` suffix and label are hidden where they have no effect but still accepted. `ANTHROPIC_DEFAULT_{FABLE,OPUS,SONNET,HAIKU}_MODEL` pins; `availableModels` allowlist; `modelOverrides`. No models.dev and no non-Claude families. — _Evidence:_ cc:model-config#model-aliases; cc:model-config#restrict-model-selection; CL v2.1.311
- why: 2.1.311 adds a signed remote model catalog for cloud-provider routes

### MOD-15 · Claude Code 2.1.311
- current: `✅ Effort low/medium/high/xhigh/max (`/effort` slider, `--effort`, env, per-model settings), `ultrath`
- new: ✅ Effort low/medium/high/xhigh/max (`/effort` slider, `--effort`, env, per-model); `ultrathink` runs its turn at xhigh; `ultracode`
- detail: ✅ — Adaptive reasoning on current models; thinking can't be disabled on Opus/Sonnet/Haiku 5.5 or Fable. In the interactive terminal on Anthropic's API, `ultrathink` runs its turn at xhigh effort on Opus/Sonnet/Haiku 5.5 and Fable 5.1, and typed while Claude works it raises the rest of that turn (the docs page still describes only an in-context instruction). `maxEffortLevel` caps; `/effort` applies mid-turn after a cache warning; `max` is session-only. — _Evidence:_ CL v2.1.311; cc:model-config#use-ultrathink-for-one-off-deep-reasoning; help (`--effort`)
- why: `ultrathink` now really raises the request effort (xhigh) instead of only adding an instruction

### INS-18 · Claude Code 2.1.311
- current: `✅ Files >4 MiB skipped; startup and `/status` warn over ~200 lines per file and on combined size; ME`
- new: ✅ Files >4 MiB skipped; on-demand nested/rule files over a per-file cap load only their start; ~200-line warnings; MEMORY.md capped
- detail: ✅ — A CLAUDE.md loaded at launch arrives whole up to 4 MiB and is skipped above that; startup and `/status` warn on long files. Subdirectory CLAUDE.md/CLAUDE.local.md, their `@` imports and on-demand rules files have a per-file cap (40,000 chars at 200K context; 200,000 on Opus/Sonnet 4.6 at 1M; 150,000 on later 1M models): over it Claude gets only the start plus a note to Read the rest. MEMORY.md loads its first 200 lines / 25 KB; `/doctor` estimates cost and biggest contributors. — _Evidence:_ cc:memory#claude-ignores-the-end-of-my-subdirectory-claude-md; cc:memory#my-claude-md-is-too-large; CL v2.1.309
- why: 2.1.309 adds a per-file cap with truncation + Read pointer for on-demand instruction files

### CTX-05 · Claude Code 2.1.311
- current: `✅ LLM summary on the cached prefix; re-injects CLAUDE.md, memory, plan, 5 recent files, skills (5K/2`
- new: ✅ LLM summary on the cached prefix; re-injects CLAUDE.md, memory, plan, 5 recent files, skill listing + bodies (5K/25K), git status
- detail: ✅ — Clears older tool outputs, then summarises on the cached prefix (asking once more when the reply holds no summary). Re-injects root CLAUDE.md and unscoped rules, auto memory, the plan, up to 5 recently modified files (>5K tokens come back as `Referenced file`), invoked skill bodies (5K each / 25K total), a rebuilt skill listing (v2.1.304+) and a fresh git snapshot; still-running background work is listed; SessionStart(`compact`) hooks re-run; Ctrl+O shows the tools called before the summary. — _Evidence:_ cc:context-window#what-survives-compaction; CL v2.1.304; CL v2.1.311
- why: 2.1.304 re-sends the skill listing after compaction; 2.1.311 re-asks for a missing summary

### CTX-N2 · Claude Code 2.1.311
- current: `✅ compacts idle 200K+ chats before cache expiry; offers summary on stale resume`
- new: ✅ compacts idle 200K+ chats before cache expiry (`/config` Idle compaction row); stale long resumes show a usage-cost dialog
- detail: ✅ — Idle compaction compacts 200K+ conversations left idle before the 1-hour cache expires; `/config` has an Idle compaction row (v2.1.302+) and the post-compaction notice says how to turn it off. On Pro/Max, resuming a long conversation after a long break or on another model opens "Resume this conversation?", estimating its share of the 5-hour limit and offering Resume or Start a new conversation (which may still look the old one up); whether the older summarize-on-resume choice remains is UNVERIFIED. — _Evidence:_ cc:settings-reference (Idle compaction row); cc:sessions#the-resume-this-conversation-dialog; CL v2.1.302; CL v2.1.304
- why: settings toggle added; the stale-resume dialog is now a usage-estimate choice, not (only) a summary offer

### AGT-13 · Claude Code 2.1.311
- current: `✅ Background by default in interactive sessions (fork mode on); results arrive later as completion n`
- new: ✅ Main-conversation subagents start in the background on every surface (fork mode on by default); results arrive as notifications
- detail: ✅ — With nothing configured, subagents Claude spawns from the main conversation start in the background; in the terminal, desktop app and VS Code Claude can't wait for them while fork mode is on (default everywhere since v2.1.302; `CLAUDE_CODE_FORK_SUBAGENT=0` turns it off), while in `-p`/SDK it may wait on its own judgment. A subagent's own Agent calls run in the foreground by default in `-p`, SDK, desktop and VS Code. Ctrl+B backgrounds a running task; background subagents get a reduced tool set and their permission prompts surface in the main session. — _Evidence:_ cc:sub-agents#which-subagents-run-in-the-background; cc:sub-agents#turn-fork-mode-off; CL v2.1.302
- why: 2.1.302 extends background-by-default/fork mode from the terminal to every surface

### CMD-01 · Claude Code 2.1.311
- current: `✅ ~116 built-in commands/bundled skills (119 table rows, 3 marked removed): /context, /compact, /rew`
- new: ✅ ~117 built-in commands/bundled skills (120 table rows, 3 marked removed): /context, /compact, /rewind, /btw, /goal, /loop, /diff…
- detail: ✅ — The commands reference has 120 rows, 3 marked removed (`/pr-comments`, `/ultraplan`, `/vim`), including 20 bundled skills and the bundled workflow `/deep-research`. Notable: /agents (reminder only), /auto-mode-setup, /autocompact, /background, /batch, /branch, /cd, /fork, /subtask, /code-review (+ultra), /security-review, /doctor, /effort, /fast, /insights, /mcp, /memory, /model, /plugin, /permissions, /remote-control, /schedule, /skill-doctor, /skills, /tasks, /teleport, /tui, /usage, /voice, /workflows. Some run immediately mid-turn; availability varies by plan/provider. — _Evidence:_ cc:commands#all-commands (counted 2026-10-26)
- why: docs table now has 120 rows (was 119)

### CMD-12 · Claude Code 2.1.311
- current: `🟡 `~/.claude/keybindings.json` rebinds built-in actions (incl. chords) with hot reload; no binding f`
- new: ✅ `~/.claude/keybindings.json` rebinds built-in actions (chords, hot reload); `command:<name>` binds a key to run `/<name>`
- detail: ✅ — `~/.claude/keybindings.json` rebinds actions per context (chords, `null` unbinds, JSON schema, hot reload). The `command:<name>` action binds a key to run `/<name>` (e.g. `"meta+g": "command:compact"` in a `Chat` block; unbound by default), and a 2.1.309 fix stops key-bound slash commands from running as shell input in `!` mode. Whether `command:<name>` also reaches custom skills/commands is UNVERIFIED (the docs say only "Run the command `/<name>`"). Mods' Buttons can trigger keybinding actions. — _Evidence:_ cc:keybindings (Chat actions, `command:<name>` row); CL v2.1.309; cc:plugins/mods/reference
- why: glyph 🟡→✅: documented `command:<name>` contradicts "no binding for slash commands" (feature predates 2.1.309, so likely already wrong at 2.1.301)

### MCP-12 · Claude Code 2.1.311
- current: `✅ Tool search on by default: only tool names + server instructions load; `ToolSearch` loads schemas `
- new: ✅ Tool search on by default, also behind gateways: only tool names + server instructions load; `ToolSearch` loads schemas on demand
- detail: ✅ — Only tool names and server instructions load; `ToolSearch` loads schemas on demand. On by default on Opus 4/Sonnet 4/Haiku 4.5 and later, and since v2.1.304 also behind an `ANTHROPIC_BASE_URL` gateway (requests carry `defer_loading`/`tool_reference`; a 400 or 422 is retried without tool search). Tools load upfront on older models, earlier Agent Platform models and Azure-hosted Foundry; `ENABLE_TOOL_SEARCH` true/auto/auto:N/false; `alwaysLoad` exempts a server; `WaitForMcpServers` when off. — _Evidence:_ cc:mcp#configure-tool-search; cc:mcp#scale-with-mcp-tool-search; CL v2.1.304; observed in-session (deferred-tools reminder + ToolSearch)
- why: 2.1.304 turns tool search on behind gateways (2.1.301 detail said it fell back there)

### TL-22 · Claude Code 2.1.311
- current: `✅ `CronCreate/Delete/List` (session-scoped, 7-day expiry), `ScheduleWakeup` (self-paced /loop), `Rem`
- new: ✅ `CronCreate/Delete/List` (session-scoped, 7-day expiry), `ScheduleWakeup` (self-paced /loop), `RemoteTrigger` (cloud tasks)
- detail: ✅ — Session crons (`CronCreate/Delete/List`, 5-field cron; recurring tasks expire after 7 days) are restored on resume; `ScheduleWakeup` paces a self-paced `/loop` (1 min–1 h); `RemoteTrigger` creates, updates, runs and lists cloud scheduled tasks (formerly routines) and backs `/schedule`; needs a claude.ai plan. — _Evidence:_ cc:tools-reference; cc:scheduled-tasks#seven-day-expiry; cc:routines; observed in-session (`loop`, `schedule` skills)
- why: docs rename routines to cloud scheduled tasks (rename date UNVERIFIED)

### TL-24 · Claude Code 2.1.311
- current: `✅ `Artifact` tool publishes live HTML/Markdown pages to claude.ai (Pro/Max/Team/Ent); `/design`, `/s`
- new: ✅ `Artifact` tool publishes live claude.ai pages (comments, live connector data, per-page DB, downloads); Slides/Docs/Design types
- detail: ✅ — `Artifact` publishes an HTML/Markdown file as a private claude.ai page (shareable publicly or in-org) and updates it in place; viewers can comment and Claude can answer, pages can pull live data through each viewer's own connectors and offer file downloads, and a page can keep a small shared database. Claude can start from Slides, Design or Docs templates (Claude Docs reaches Claude Code as a claude.ai connector for editable documents); `/design`, `/slides`; mods can draw panes/bands inside the TUI. Pro/Max/Team/Enterprise. — _Evidence:_ cc:artifacts; observed in-session (Artifact publish/read/list/quickstart/pin actions, `ArtifactComments`, `ArtifactData`, Slides/Docs/Design types)
- why: cell undersold the artifact surface (comments, live data, DB, Docs); possibly already true at 2.1.301

### PER-01 · Claude Code 2.1.311
- current: `✅ Six modes: Manual(`default`), acceptEdits, plan, auto, dontAsk, bypassPermissions; auto is the sta`
- new: ✅ Six modes: Manual(`default`), acceptEdits, plan, auto, dontAsk, bypassPermissions; auto starts interactive runs, `-p`/SDK Manual
- detail: ✅ — Auto = classifier reviews actions (server-side review on 1P); deny rules apply in every mode; auto needs supported models (Opus/Sonnet 4.6+, Haiku 5.5, Fable). Interactive terminal and VS Code sessions start in auto (v2.1.283+); `claude -p` and Agent SDK runs with no mode set start in Manual and deny calls needing approval unless a rule, hook, `canUseTool` or `--permission-prompt-tool` approves (v2.1.302+). The CLI flag spells Manual as `manual`, settings and SDK as `default`. — _Evidence:_ cc:permission-modes#which-mode-a-session-starts-in; cc:permission-modes#tool-calls-denied-in-claude--p-and-agent-sdk-runs; help (`--permission-mode` choices); CL v2.1.302
- why: 2.1.302: headless/SDK runs now start in Manual, not auto

### PER-07 · Claude Code 2.1.311
- current: `✅ Auto mode classifier (server-side review when available) blocks escalation, unknown infra, hostile`
- new: ✅ Auto mode classifier (server-side review, else a client model via `CLAUDE_CODE_AUTO_MODE_MODEL`) blocks escalation, hostile acts
- detail: ✅ — Where server-side checks are on, the server reviews calls inside the session's own requests; otherwise Claude Code sends classifier requests to Claude Sonnet 5 by default or to the model in `CLAUDE_CODE_AUTO_MODE_MODEL` (v2.1.302+). `/auto-mode-setup` drafts `autoMode.environment` from the project and recent sessions (also on Bedrock/Vertex/Foundry/gateway and telemetry-off sessions since v2.1.304); `claude auto-mode config/defaults/critique/reset`; 3-in-a-row / 20-total blocks fall back to prompts; Recently denied names the command; reviews SendMessage and subagent handbacks. — _Evidence:_ cc:permission-modes#server-side-classifier-review; cc:permission-modes#choose-the-classifier-model; cc:auto-mode-config; help (`auto-mode`); CL v2.1.302; CL v2.1.304
- why: classifier model now selectable; setup wizard extended to 3P/gateway sessions

### PER-14 · Claude Code 2.1.311
- current: `🟡 OTel `tool_decision` events (classifier verdict/category since 2.1.301) + `/permissions` Recently `
- new: 🟡 OTel `tool_decision` + `safety_event` per refusal/prompt (rule, mode, hook, classifier, sandbox); Recently denied; no local log
- detail: 🟡 — OTel `claude_code.tool_decision` carries source, classifier verdict and category; `claude_code.safety_event` (v2.1.307+) is logged whenever a permission rule, mode, auto mode, a built-in safety check, a hook or the sandbox (incl. its network proxy) refuses, prompts or sends back a call, with `decision`, `reason_code`, `rule_source`, `grant_scope`, `circuit_breaker` and no command text. `/permissions` → Recently denied lists denials; there is no local audit-log file. — _Evidence:_ cc:monitoring-usage#safety-event; cc:monitoring-usage#tool-decision-event; CL v2.1.307
- why: 2.1.307 adds a dedicated per-refusal safety event (still export-only, so 🟡 stays)

### GIT-12 · Claude Code 2.1.311
- current: `✅ `@claude` in PR/issue comments (Action), Code Review app (inline comments, Team/Enterprise), `/aut`
- new: ✅ `@claude` in PR/issue comments (Action), Code Review app (inline comments, Quick/Standard/Deep levels, Team/Ent), `/autofix-pr`
- detail: ✅ — `@claude` mentions in PR/issue comments via `anthropics/claude-code-action`; the managed Code Review GitHub App (Team/Enterprise) posts inline findings at an Owner-chosen review level (Quick, Standard default, Deep) per org or per repo, can cover every repo in a GitHub org, and takes `@claude review always|stop` subscriptions; `/autofix-pr` cloud watcher. — _Evidence:_ cc:github-actions; cc:code-review#review-levels; cc:code-review-setup; CL v2.1.304; CL v2.1.307
- why: Code Review gained review levels, org-wide coverage and `review stop`

### UI-04 · Claude Code 2.1.311
- current: `✅ Syntax highlighting of code blocks (toggle with Ctrl+T inside `/theme`)`
- new: ✅ Syntax highlighting of code blocks (toggle with Ctrl+T inside `/theme`); the VS Code panel colors code blocks too
- detail: ✅ — Code blocks are syntax-highlighted in the terminal (toggle with Ctrl+T inside `/theme`); the VS Code chat panel colors code blocks in light, dark and high-contrast dark themes. — _Evidence:_ cc:interactive-mode#theme-and-display; CL v2.1.311
- why: 2.1.311 adds code-block coloring to the VS Code panel

### UI-27 · Claude Code 2.1.311
- current: `🟡 No inline image rendering of conversation content; mods' `Image` elements use kitty graphics proto`
- new: 🟡 No inline image rendering of conversation content; mods' `Image` elements draw via kitty/iTerm2 protocols (tmux passthrough too)
- detail: 🟡 — Conversation content is never drawn as inline images. Mods' `Image` elements use the kitty graphics or iTerm2 protocol when detected — including iTerm2 3.5+ with fullscreen rendering and inside tmux with `allow-passthrough` and `focus-events` on (kitty, Ghostty, iTerm2) — and otherwise show their `alt` text; `CLAUDE_CODE_FORCE_TERMINAL_IMAGES`. — _Evidence:_ cc:plugins/mods/gallery; cc:env-vars; CL v2.1.305; CL v2.1.310
- why: mod images gained iTerm2 and tmux-passthrough paths (2.1.305/2.1.310)

### AUT-01 · Claude Code 2.1.311
- current: `✅ `claude -p` print mode (workspace trust skipped; settings errors silent)`
- new: ✅ `claude -p` print mode (workspace trust skipped; settings errors silent; starts in Manual unless a permission mode is set)
- detail: ✅ — `--bare` for reproducible CI. With no `--permission-mode` (or `permissions.defaultMode`) a print run starts in Manual and denies calls needing approval unless an allow rule, hook or `--permission-prompt-tool` approves them (v2.1.302+); `--permission-prompts none` denies anything that would prompt. — _Evidence:_ help (`-p`, `--permission-prompts`); cc:headless; cc:permission-modes#tool-calls-denied-in-claude--p-and-agent-sdk-runs
- why: 2.1.302 headless default-mode change

### AUT-07 · Claude Code 2.1.311
- current: `✅ Routines (cloud cron/API/GitHub triggers, research preview), Desktop scheduled tasks, session `Cro`
- new: ✅ Cloud scheduled tasks (ex-routines: cron/API/GitHub triggers, research preview), Desktop scheduled tasks, session `CronCreate`
- detail: ✅ — `/schedule` (backed by `RemoteTrigger`) or claude.ai/code/routines creates cloud scheduled tasks — the docs' new name for routines — with schedule, API and GitHub triggers (research preview; Pro/Max/Team/Enterprise); changelog and admin UI still say "routines". Desktop scheduled tasks run locally; session crons expire after 7 days. — _Evidence:_ cc:routines; cc:desktop-scheduled-tasks; cc:scheduled-tasks; CL v2.1.311
- why: naming: docs call routines "cloud scheduled tasks" now

### AUT-11 · Claude Code 2.1.311
- current: `✅ Routine API triggers (HTTP POST + bearer) and GitHub event triggers; channels push webhooks into a`
- new: ✅ Cloud scheduled-task API triggers (HTTP POST + bearer) and GitHub event triggers; channels push webhooks into a live session
- detail: ✅ — A cloud scheduled task's API trigger is an HTTP POST to its own endpoint with a bearer token; GitHub triggers fire on repository events (e.g. `pull_request.opened`); channel plugins push webhook events into a live session. — _Evidence:_ cc:routines; cc:channels
- why: naming only (routines → cloud scheduled tasks)

### OBS-02 · Claude Code 2.1.311
- current: `✅ OpenTelemetry metrics, events and traces (beta) with mTLS, dynamic headers, cardinality controls`
- new: ✅ OpenTelemetry metrics, events (incl. per-refusal `safety_event`) and traces (beta); mTLS, dynamic headers, cardinality controls
- detail: ✅ — Metrics, events and traces (beta) over OTLP with mTLS, dynamic headers and cardinality controls; `tool_decision` (with `agent_id`/`request_id` since v2.1.310), opt-in raw API bodies, GenAI semantic conventions, and the per-refusal `claude_code.safety_event` (v2.1.307+). — _Evidence:_ cc:monitoring-usage; CL v2.1.307
- why: new `safety_event`

### OBS-03 · Claude Code 2.1.311
- current: `✅ `/usage` (`/cost`, `/stats`) with plan breakdown and cache stats; Team/Enterprise analytics dashbo`
- new: ✅ `/usage` (`/cost`, `/stats`): plan breakdown, usage attribution, cache stats, recommendations `f` applies; Team/Ent analytics
- detail: ✅ — `/usage` (aliases `/cost`, `/stats`) shows session cost, plan limits and activity; on paid plans its breakdown attributes recent usage to skills, subagents, plugins and MCP servers, lists the heaviest loops and recommends changes, and `f` applies the ones it can (v2.1.305+; the same fixes appear in VS Code and `/doctor`); Team/Enterprise analytics dashboard; 🔌 `session-report`, `receipts`. — _Evidence:_ cc:costs#plan-usage-breakdown; cc:costs#apply-recommendations-with-the-fix-shortcut; cc:analytics; CL v2.1.305
- why: 2.1.305 one-key fixes; attribution/recommendations were missing from the cell

### TL-10 · Claude Code 2.1.311
- current: `✅ `run_in_background`, Ctrl+B, auto-background on timeout; `Monitor` streams output lines/WebSocket `
- new: ✅ `run_in_background`, Ctrl+B, auto-background on timeout; `Monitor` streams output lines/WebSocket events back to Claude
- detail: ✅ — Output goes to a file Claude reads; `/tasks` + `TaskStop`; background time limits only in unattended runs (30 min default, 2 h max); a foreground command that reaches its timeout moves to the background and returns its output so far. `Monitor` streams command output lines or WebSocket messages back as events; on Bedrock, Claude Platform on AWS, Agent Platform, Foundry, or with telemetry/nonessential traffic off it needs v2.1.310+; Windows needs Git Bash; plugins can declare monitors. — _Evidence:_ cc:interactive-mode#background-bash-commands; cc:tools-reference#monitor-tool; CL v2.1.310; CL v2.1.311; observed in-session (Monitor among the deferred tools)
- why: detail-only: the 2.1.301 detail says Monitor is unavailable on Bedrock/Vertex/Foundry/telemetry-off — no longer true

### SH-03 · Claude Code 2.1.311
- current: `✅ Default 2 min, max 10 min (BASH_DEFAULT/MAX_TIMEOUT_MS); Claude sets per-call `timeout`; timeout m`
- new: ✅ Default 2 min, max 10 min (BASH_DEFAULT/MAX_TIMEOUT_MS); Claude sets per-call `timeout`; timeout moves it to background
- detail: ✅ — Background commands: unlimited interactively, 30 min/2 h in unattended runs; a command that reaches its timeout (not `sleep`) moves to the background and its result shows the output so far. — _Evidence:_ cc:tools-reference#timeout-and-output-limits; CL v2.1.311
- why: detail-only

### SH-06 · Claude Code 2.1.311
- current: `✅ Opt-in OS sandbox (`/sandbox`, `sandbox.enabled`): bubblewrap on Linux/WSL2, Seatbelt on macOS; no`
- new: ✅ Opt-in OS sandbox (`/sandbox`, `sandbox.enabled`): bubblewrap on Linux/WSL2, Seatbelt on macOS; not native Windows
- detail: ✅ — Writes limited to cwd/added dirs/temp; protected paths denied inside; `sandbox.credentials` masks secrets; shell startup files run inside the sandbox; built on open-source sandbox-runtime; `dangerouslyDisableSandbox` retry goes through permissions; `failIfUnavailable` fails closed. On Linux/WSL2 `sandbox.allowNestedUserNamespaces` (user settings only) lets Chromium or rootless podman create user namespaces at the cost of file protections; `/sandbox` names Ubuntu's AppArmor userns restriction and the fix. — _Evidence:_ cc:sandboxing; cc:settings-reference#sandbox-allownestedusernamespaces; CL v2.1.305; CL v2.1.309; CL v2.1.310
- why: detail-only

### MOD-18 · Claude Code 2.1.311
- current: `✅ 10 retries w/ exponential backoff, stall/sleep detection, max_tokens shrink, unattended retry watc`
- new: ✅ 10 retries w/ exponential backoff, stall/sleep detection, max_tokens shrink, unattended retry watchdog, limit auto-continue
- detail: ✅ — Retries 5xx/529/timeouts/dropped connections/429 throttles (not spend-limit 429s, nor requests the API marks not to retry); unparseable tool calls are retried up to twice; gateways that reject a beta value or field are retried without it; `system/api_retry` events in stream-json; `CLAUDE_CODE_RETRY_WATCHDOG` waits out 429/529 unattended (`…_MAX_WAIT_MS`, `…_MAX_USAGE_LIMIT_WAIT_MS`, `…_USAGE_LIMIT_RECHECK_MS`); `CLAUDE_CODE_OVERLOADED_RETRY_*` delays. — _Evidence:_ cc:errors#automatic-retries; cc:env-vars; CL v2.1.305; CL v2.1.307; CL v2.1.310; CL v2.1.311
- why: detail-only

### MCP-03 · Claude Code 2.1.311
- current: `✅ Streamable HTTP (`--transport http`, headers) plus WebSocket servers`
- new: ✅ Streamable HTTP (`--transport http`, headers) plus WebSocket servers
- detail: ✅ — Two client runtimes (MCP TS SDK 1.x / 2.0 with protocol 2026-07-28); 16 MiB response cap; discovery cache opt-in; a call pending when an HTTP connection drops is repeated once only for tools marked `readOnlyHint`/`idempotentHint`. — _Evidence:_ cc:mcp#mcp-client-runtimes; cc:mcp#mark-tools-that-are-safe-to-call-twice; CL v2.1.307
- why: detail-only

### HK-03 · Claude Code 2.1.311
- current: `✅ Five handler types: command (stdin JSON), http (POST), mcp_tool, prompt (LLM verdict), agent (expe`
- new: ✅ Five handler types: command (stdin JSON), http (POST), mcp_tool, prompt (LLM verdict), agent (experimental)
- detail: ✅ — Plus mods (JS/TS functions in-process). Command hooks: exec or shell form, `shell: powershell`, `async: true` background. Defaults: 600 s command/http/mcp_tool, 30 s prompt, 60 s agent (30 s on UserPromptSubmit/model-switch). Matching hooks run in parallel, but at most 12 prompt/agent hooks of one event at once; skills from one source may register at most 1,024 hooks / 16,384 matcher chars per session. — _Evidence:_ cc:hooks#hook-handler-fields; cc:hooks#hooks-in-skills-and-agents; CL v2.1.302; CL v2.1.304
- why: detail-only (33 events and 5 handler kinds re-verified unchanged)

### SKL-02 · Claude Code 2.1.311
- current: `✅ Enterprise, personal, project (walk-up), nested (lazy), --add-dir, plugin, claude.ai-synced and bu`
- new: ✅ Enterprise, personal, project (walk-up), nested (lazy), --add-dir, plugin, claude.ai-synced and bundled skills
- detail: ✅ — Precedence enterprise > personal > project; plugin skills namespaced `/plugin:skill`; nested dupes exposed as `/apps/web:deploy`, and an `--add-dir` skill whose name clashes is offered as `<folder>:<name>`; claude.ai skills sync into `~/.claude/skills/synced/`; worktrees fall back to the main checkout's skills. Reserved names `synced`, `anthropic-skills`. — _Evidence:_ cc:skills#where-skills-live; cc:skills#how-synced-skills-behave; CL v2.1.309
- why: detail-only

### AUT-05 · Claude Code 2.1.311
- current: `✅ `anthropics/claude-code-action` GitHub Action, GitLab CI/CD recipe, `claude ultrareview` for CI`
- new: ✅ `anthropics/claude-code-action` GitHub Action, GitLab CI/CD recipe, `claude ultrareview` for CI
- detail: ✅ — `anthropics/claude-code-action`, the GitLab CI/CD recipe, and `claude ultrareview [PR|base]` for CI, which requires workspace trust: scripts pass `--trust-workspace` or set `CLAUDE_CODE_DISABLE_ULTRAREVIEW_TRUST_CHECK=1`. — _Evidence:_ cc:github-actions; cc:gitlab-ci-cd; cc:ultrareview; help (`ultrareview`); CL v2.1.309
- why: detail-only (2.1.301 detail was empty)

### WEB-12 · Claude Code 2.1.311
- current: `✅ Cloud sessions on Anthropic infra or self-hosted runners (Pro, Max, Team, Enterprise premium seats`
- new: ✅ Cloud sessions on Anthropic infra or self-hosted runners (Pro, Max, Team, Enterprise premium seats)
- detail: ✅ — `--cloud`, `/teleport` (warns when a compacted cloud session loads from its last compaction), setup scripts, environments; Projects coordinate many cloud sessions; self-hosted runners (`claude self-hosted-runner`, incl. `--hold-idle-file`/`--max-hold-min`); Enterprise custom roles grant cloud sessions and Remote Control per role. — _Evidence:_ cc:claude-code-on-the-web; cc:self-hosted-environments; cc:claude-projects; cc:remote-control (custom roles); help (`self-hosted-runner`); CL v2.1.310; CL v2.1.311
- why: detail-only

### PLG-N1 · Claude Code 2.1.311
- current: `✅ in-process JS/TS plugins that draw panes, rewrite events, add commands/tools; several CC features `
- new: ✅ in-process JS/TS plugins that draw panes, rewrite events, add commands/tools; several CC features are built-in mods
- detail: ✅ — Built-ins: cc-plugin-agents-md, -diff, -you-should-know, -telemetry, -sec-default, -plugin-authoring; `claude plugin test`. A mod's `tool.check` can answer `decider: "person"` (only the permission dialog or a host callback may approve) and sees `e.isReadOnly`; `settings.read` hooks receive each settings layer, so a managed mod can make an org setting a ceiling. — _Evidence:_ cc:plugins/mods/overview; cc:plugins/mods/events; CL v2.1.304; observed in-session (`plugin-authoring` skill)
- why: detail-only

### AUT-02 · Claude Code 2.1.311
- current: `✅ `--output-format text/json/stream-json`, `--input-format stream-json`, partial messages, hook even`
- new: ✅ `--output-format text/json/stream-json`, `--input-format stream-json`, partial messages, hook events, replay
- detail: ✅ — `--forward-subagent-text`; `prompt_suggestion` messages; `--include-hook-events`; json/stream-json write DEL, C1 and Unicode format characters inside strings as `\uXXXX` escapes. — _Evidence:_ help; cc:headless#stream-responses; CL v2.1.311
- why: detail-only

## New 🆕 row candidates

Next free IDs were checked against §3: MCP-N1, WEB-N1..N5, PLG-N1..N2 exist, and there is no OBS-N row. The other five columns are **UNRATED** (`· Not assessed`) unless a note says otherwise.

### MCP-N2 · Long-running MCP calls: auto-backgrounded tool calls and server-side MCP tasks that report back later
- feature: 🆕 Long-running MCP calls (auto-backgrounded tool calls; server-run MCP tasks that outlive the turn/session)
- Claude Code 2.1.311: ✅ Long MCP calls move to the background after 2 min; MCP tasks (2026-07-28 tasks extension) outlive the session (CLI public beta)
- candy-code: · UNRATED. A grep of `candy-code/src` for `tasks/get|tasks/result|taskSupport` finds nothing, which suggests ❌, but this needs an audit.
- candy-code-web / OpenCode 1.18.35 / OpenCode 2.0.31 / OpenClaw 2026.9.8: · UNRATED
- detail (Claude Code 2.1.311): ✅ — An MCP tool call in the main conversation that is still running after 2 minutes moves to a `/tasks` background task, and its result arrives as a notification (`CLAUDE_CODE_MCP_AUTO_BACKGROUND_MS`; off in `-p` unless `CLAUDE_AUTO_BACKGROUND_TASKS=1`). A server on protocol revision 2026-07-28 can answer `tools/call` with an MCP task (tasks extension). The footer counts running tasks, and Claude Code polls their status. A remote task keeps running after exit and is followed again on resume; `x` in `/tasks` asks the server to cancel. MCP tasks are a public beta in the CLI, Agent SDK and GitHub Actions from v2.1.304, and are not available in VS Code or the desktop app. — _Evidence:_ cc:mcp#automatic-backgrounding-of-long-tool-calls; cc:mcp#mcp-tasks-that-run-on-the-server; cc:mcp#mcp-tasks-for-server-authors; cc:env-vars (`CLAUDE_CODE_DISABLE_MCP_TASK_BACKGROUND`, v2.1.304)
- why: MCP tasks are new since 2.1.301 (public beta, v2.1.304). No taxonomy row covers long-running or async MCP calls; MCP-05 only covers plain tools.

### WEB-N6 · Deep links (a URL scheme that opens a local session in a chosen repo with a pre-filled prompt)
- feature: 🆕 Deep links (URL opens a local session in a given directory with a pre-filled, unsent prompt)
- Claude Code 2.1.311: ✅ `claude-cli://` links open a local terminal session in the linked directory with the prompt pre-filled, never auto-sent
- candy-code: · UNRATED. A grep of `candy-code/src`, `bin` and `docs` for a URL-scheme handler finds nothing, which suggests ❌.
- candy-code-web / OpenCode 1.18.35 / OpenCode 2.0.31 / OpenClaw 2026.9.8: · UNRATED
- detail (Claude Code 2.1.311): ✅ — Claude Code registers the `claude-cli://` scheme with the OS. A link carries a working directory and prompt text, and opens a new terminal window running Claude Code there with the prompt typed but not sent. A `Prompt from an external link` warning stays below the input, with a length warning past 1,000 chars. Permission rules, CLAUDE.md and trust prompts apply as usual. The feature is meant for runbooks, alerts, dashboards and READMEs, and handler registration can be managed or disabled per platform. — _Evidence:_ cc:deep-links
- why: an entry-point surface with no taxonomy row. It is not necessarily new since 2.1.301.

### PLG-N3 · Contextual plugin recommendations (suggest installing a plugin when session signals match)
- feature: 🆕 Contextual plugin recommendations (install suggestions triggered by repo/files/commands or by a tool's hint)
- Claude Code 2.1.311: ✅ Marketplace `relevance` signals (dirs, files read, commands run) and CLI `<claude-code-hint />` tags trigger install suggestions
- candy-code / candy-code-web / OpenCode 1.18.35 / OpenCode 2.0.31 / OpenClaw 2026.9.8: · UNRATED
- detail (Claude Code 2.1.311): ✅ — A marketplace entry's `relevance` block (working directory, files Claude read, commands it ran) makes Claude Code suggest installing that plugin when a session matches, once an admin allowlists the marketplace in managed settings. A third-party CLI or SDK can print a one-line `<claude-code-hint />` tag to stderr; Claude Code strips it from Bash/PowerShell output before the model sees it and shows a one-time install prompt for that tool's official-marketplace plugin. The LSP recommendation dialog offers code-intelligence plugins when a server binary is on PATH. — _Evidence:_ cc:plugins/relevance; cc:plugins/cli-hints; cc:plugins/code-intelligence
- why: plugin discovery driven by the session's context has no row. PLG-02 covers only the marketplace and install command.

### OBS-N1 · Usage-optimisation advice (usage attributed to skills/agents/plugins/MCP, with actionable fixes)
- feature: 🆕 Usage-optimisation advice (usage attributed to skills/subagents/plugins/MCP servers, with one-step fixes)
- Claude Code 2.1.311: ✅ `/usage` attributes plan usage to skills, subagents, plugins, MCP servers and recommends fixes (`f` applies); `/skill-doctor`
- candy-code: · UNRATED. `/usage 7/30/all` day×model rollups exist per §6.5. Attribution and recommendations are not known.
- candy-code-web / OpenCode 1.18.35 / OpenCode 2.0.31 / OpenClaw 2026.9.8: · UNRATED
- detail (Claude Code 2.1.311): ✅ — On Pro/Max/Team/Enterprise, `/usage` attributes the last 24 h or 7 days of usage to skills, subagents, plugins and individual MCP servers. It lists the heaviest loops and recommends changes, one row each with its usage share (e.g. trimming a 49K-token CLAUDE.md). `f` applies what it can (v2.1.305+): it runs any `/effort` rows, then has Claude make the rest, showing each change first. The same recommendations appear in VS Code and `/doctor`. `/skill-doctor` reports each skill's context cost and use and where to turn unused ones off. — _Evidence:_ cc:costs#plan-usage-breakdown; cc:costs#apply-recommendations-with-the-fix-shortcut; cc:skills#find-unused-skills; cc:commands (`/skill-doctor`); CL v2.1.305
- why: OBS-03 rates usage and cost *reports*. Advice that tells you what to change, and applies it, is a distinct capability, and the one-key fix is new since 2.1.301.

## Narrative fixes

The headers and labels below are mechanical. Use the applier's `headers` map for §2 and §3 header rows plus the Appendix A bullet labels: `"Claude Code 2.1.301": "Claude Code 2.1.311"`. That map does **not** reach the narrative lines listed here. The applier's recompute then moves the Claude Code scoreboard column to CMD `✅11 🟡1` and Total `✅277 🟡52 🔌6 ❌21`. The §2 footnotes saying "competitor columns unchanged" become stale for Claude Code; fix them in the prose pass.

- §0 L9: current "| **Claude Code** | 2.1.301 (installed here) | All 228 official doc pages, the changelog, `--help` for every subcommand, and a read-only look at `~/.claude` |" → new "| **Claude Code** | 2.1.311 (installed here; npm `latest` 2.1.311, `stable` 2.1.308) | All 228 official doc pages, the changelog through 2.1.311, `--help` for every subcommand, and a read-only look at `~/.claude` |"
- §1 L65: current "where Claude Code ships a trained classifier" → new "where Claude Code runs a model-based classifier (server-side review, else a client-side model)" (this was already inaccurate at 2.1.301: cc:permission-modes says classifier requests go to Sonnet 5 by default, or are reviewed server-side)
- §4.1 L551: current "Claude Code 2.1.301 | OpenCode 1.18.35 | OpenClaw 2026.9.8 |" → new "Claude Code 2.1.311 | OpenCode 1.18.35 | OpenClaw 2026.9.8 |" (bump the OpenCode/OpenClaw parts per their own reports)
- §4.1 L556: current "Lazily on first read/write/edit (a Bash `cat` counts); terminal prints `Loaded <path>`" → new "Lazily on first read/write/edit (a Bash `cat` counts); terminal prints `Loaded <path>`; a file over the per-file cap (40K chars at 200K context, 150K–200K at 1M) arrives as its start plus a Read pointer"
- §4.1 L560: current "Files >4 MiB skipped; warnings over ~200 lines" → new "Files >4 MiB skipped; on-demand nested/rule files over a per-file cap load only their start; warnings over ~200 lines"
- §4.5 L625: current "~116 commands and bundled skills" → new "~117 commands and bundled skills"
- §4.7 L654: current "(it falls back to upfront loading for older models, non-first-party base URLs and Azure Foundry)" → new "(also behind `ANTHROPIC_BASE_URL` gateways, which are retried without it on a 400/422; it falls back to upfront loading for older models and Azure-hosted Foundry)"
- §4.7 L655: current "  - `claude mcp serve` exposes Claude Code itself as a server." → new "  - `claude mcp serve` exposes Claude Code itself as a server.\n  - Long tool calls move to the background after 2 minutes, and servers on protocol 2026-07-28 can run MCP tasks that outlive the session (public beta)."
- §4.8 L675: current "  - Idle compaction before the 1-hour cache expires." → new "  - Idle compaction before the 1-hour cache expires (a `/config` toggle); on Pro/Max, resuming a long idle chat first estimates its share of the 5-hour limit."
- §4.9 L687: current "≈ model limit (`/autocompact`), idle compaction before cache expiry" → new "≈ model limit (`/autocompact`); a standard-context session past 200K compacts and continues; idle compaction before cache expiry (`/config` toggle)"
- §4.9 L688: current "Clear old tool outputs first, then summarise; re-inject CLAUDE.md, memory, plan, last 5 files, skills, git" → new "Clear old tool outputs first, then summarise (asking again if the reply has no summary); re-inject CLAUDE.md, memory, plan, last 5 files, skill listing + invoked skills, git"
- §4.10 L704: current "(classifier, falls back to prompts after 3 consecutive / 20 total blocks)" → new "(classifier: server-side review, else a client-side model selectable with `CLAUDE_CODE_AUTO_MODE_MODEL`; falls back to prompts after 3 consecutive / 20 total blocks); `claude -p` and SDK runs with no mode set start in Manual since 2.1.302"
- §4.12 L727: current "- **Claude Code**: CLI, VS Code, JetBrains, Desktop, web (claude.ai/code), mobile (cloud sessions + Remote Control), Slack, channel plugins." → new "- **Claude Code**: CLI, VS Code, JetBrains, Desktop (Linux in beta), web (claude.ai/code), mobile (cloud sessions + Remote Control), Slack and Claude Tag, Chrome, channel plugins, `claude-cli://` deep links."
- App. B L4924: current "**Claude Code 2.1.301.**" → new "**Claude Code 2.1.311.**"
- App. B L4926: current "- Changelog: <https://github.com/anthropics/claude-code/blob/main/CHANGELOG.md>." → new "- Changelog: <https://github.com/anthropics/claude-code/blob/main/CHANGELOG.md> (through 2.1.311, 2026-10-26)."
- Optional, App. A L1039 (candy-code MOD-09 evidence): it quotes "Claude Code 2.1.301 `--help`". The 2.1.311 `--bare` help text is word-for-word the same ("Anthropic auth is strictly ANTHROPIC_API_KEY or apiKeyHelper via --settings (OAuth and keychain are never read)"), so the quote may be re-labelled 2.1.311 or left as is.

No other Claude Code statement in §1, §4.2–§4.6, §4.11 or §6 is stale. Re-checked items: 33 hook events, 315-entry marketplace, 20 bundled skills, `/memory`/`/context` visibility, Remote Control push, the agent-view model, 20 concurrent / 3-deep subagents, and no built-in `/commit`.

## What's new (for the plan)

Claude Code 2.1.302 → 2.1.311 (2026-10-16 → 2026-10-26) is a hardening and consistency run with no new surfaces. The items candy-code would need to match:
1. **Safe headless default.** CC's `-p`/SDK runs now start in Manual and deny unapproved calls (2.1.302). candy-code `-p` still defaults to bypass with a stderr notice (§4.10), and is now the outlier: consider Manual/deny-by-default plus an explicit `--permission-mode`.
2. **MCP long calls.** CC moves MCP calls running longer than 2 min to background tasks and supports MCP tasks (protocol 2026-07-28 tasks extension; public beta, survives exit). candy-code has neither (MCP-N2). It also retries dropped HTTP calls only for `readOnlyHint`/`idempotentHint` tools.
3. **Tool search behind gateways** is CC's default now. candy-code's deferred loading is threshold-based (`mcp_list`/`mcp_call`), which is already at parity in spirit, so no action is needed beyond docs.
4. **Safety telemetry.** CC's `claude_code.safety_event` fires on every refusal or prompt, by rule, mode, hook, classifier or sandbox. candy-code's OTLP exporter could emit an equivalent per-verdict event from the rule engine and breaker (PER-14).
5. **Usage advice.** `/usage` attribution to skills, agents, plugins and MCP, with one-key fixes (OBS-N1). candy-code has ledger rollups but no attribution or advice.
6. **On-demand instruction cap.** Nested CLAUDE.md and rules over a per-file cap now arrive truncated with a Read pointer. candy-code already budgets with named overflow pointers, so this is parity with no action needed.
7. **Effort keyword.** `ultrathink` really raises request effort (xhigh) for the turn, even mid-turn. candy-code could map a keyword onto its per-provider `/effort` mapping.
8. **Auto-mode classifier model** is selectable (`CLAUDE_CODE_AUTO_MODE_MODEL`), and `/auto-mode-setup` drafts environment rules from recent sessions. candy-code's `autoReview` reviewer model and a rules-drafting helper would be the analogue.
9. **Subagents.** Fork mode and background-by-default now apply on every CC surface. candy-code already backgrounds and forks, so this is parity.
10. **Idle compaction** has a settings-UI toggle, and stale resumes show a cost estimate ("Resume this conversation?"). candy-code has opt-in `compaction.idleAuto`, but no resume-cost guard.
11. **Sandbox hardening** worth mirroring in candy-code's bwrap path:
    - run shell startup files inside the sandbox;
    - write-protect the MCP config passed on the CLI;
    - opt-in nested user namespaces (user tier only);
    - an AppArmor userns diagnostic in `doctor`.
12. **Smaller wins:**
    - deep links (`candy-code://` with cwd + prompt, never auto-sent; WEB-N6);
    - key-bindable slash commands (`command:<name>`, CMD-12);
    - contextual plugin and bundle recommendations (PLG-N3);
    - JSON-output escaping of control and format characters;
    - unparseable tool calls retried twice.
