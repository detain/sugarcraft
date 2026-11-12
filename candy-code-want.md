# candy-code: shortcomings and improvements vs. Claude Code

Consolidated from six read-only reviews of `candy-code/src/` (about 480k lines, 1,382 PHP files), each on one angle: skills/tools/MCP, workflows/background/concurrency, Windows and shell portability, TUI informativeness, permissions/config/providers, and context/memory/sessions. Claude Code is the reference point. Nothing was executed: findings are from reading code and docs. Each item is tagged **VERIFIED** (code read directly) or **INFERRED** (reasoned from the code, not confirmed at runtime). Line numbers may drift.

## Priority summary

| # | Area | Finding | Severity |
|---|------|---------|----------|
| 1 | Windows | Git/gh/grep/hook spawns build POSIX shell strings (`unset`, `exec`, `2>/dev/null`) that cmd.exe cannot run | High |
| 2 | Windows | Windows CI never exercises candy-code; its smoke tests are dormant by default | High (process) |
| 3 | Background | No `/tasks` panel for shells, sub-agents or workflow runs; background work is model-only | High |
| 4 | TUI | Tool rows hide the literal command/path and Bash rows omit the exit code | High |
| 5 | MCP | MCP servers start serially; launch latency adds up | High |
| 6 | Context | Context window fallback is silent (100K guess, and the same model gets different windows by provider) | Med |
| 7 | Context | Compaction is invisible (model vs heuristic) and not reversible | Med |
| 8 | Permissions | ~~Bypass mode turns `ask` rules into allows~~ — withdrawn, this is the intended meaning of bypass | — |
| 9 | Permissions | Permission prompt does not say which rule matched | Med |
| 10 | Windows | Launch TERM notice fires falsely on native Windows | Med |
| 11 | Concurrency | Bash is a barrier, so independent shell calls serialize | Med-High |
| 12 | Concurrency | Parallel group has a hard 90 s kill that discards finished siblings | Med |
| 13 | Concurrency | Delegation concurrency is unbounded by default, and sequential fallback is silent | Med |
| 14 | Concurrency | Ctrl+C quits while hook process groups keep running | Med-High |
| 15 | TUI | `/help` and the `/` popup omit keybindings | Med |
| 16 | TUI | Status bar has no background-jobs indicator | Med |
| 17 | MCP | No periodic health check; a dead stdio server is found only on the next call | Med |
| 18 | MCP | Tool-schema deferral is all-or-nothing per server | Med |
| 19 | MCP | No hot-reload of MCP config (`/mcp reload`) | Med |
| 20 | Workflows | Tool-path workflow kill leaves no resumable pointer | Med |
| 21 | Sessions | Resume may not restore model/permission mode; no restore notice | Med |
| 22 | Permissions | API keys stored in plaintext in `config.json` by `login` | Med |
| 23 | Windows | Hook commands run under cmd.exe with no process-group kill; docs suggest a list form that hooks cannot take | Med |
| 24 | Permissions | Repo-supplied `providerRetryAttempts` can raise retry cost without a trust prompt | Low-Med |
| 25 | Skills | Skill/MCP problems are announced only at launch | Low-Med |
| 26 | Workflows | Accumulate loops re-run completed rounds on resume | Low-Med |
| 27 | Background | Background shells have a fixed 30-minute lifetime and 64-shell cap, not shown in the UI | Low-Med |
| 28 | Windows | `bin/*` and `*.sh` have no `eol=lf` rule; autocrlf clones break shebangs | Low-Med |
| 29 | Permissions | No non-interactive `config show --sources` or `permissions list --json` | Low-Med |
| 30 | Permissions | Sandbox is off by default | Low |
| 31 | Windows | No Scoop/Winget/Chocolatey manifest; no `.cmd`/`.ps1` shim in repo | Low |
| 32 | Windows | POSIX fallbacks (`/usr/bin` PATH, `/tmp` HOME, `posix_geteuid` guards) | Low |
| 33 | Context | Stage-0 pruning placeholder says "re-run the tool", not `Recall` | Low-Med |
| 34 | TUI | Collapsed tool output does not say how many lines are hidden | Low |
| 35 | TUI | Failed tool results show `✗ error` with no hint toward stderr | Low |
| 36 | Sessions | Agent transcript loses up to 0.5 s on SIGKILL (debounce) | Low |
| 37 | Skills | Skill invocation is a generic tool row, not a distinct "Skill(name)" row (unverified) | Low |
| 38 | Skills | No user-facing notice when an MCP tool name shadows a built-in (unverified) | Low |

## 1. Windows, cmd, PowerShell, WSL

**1. Git and gh calls are POSIX shell strings (High, VERIFIED by code; runtime INFERRED).** `GitRunner::commandLine` builds `unset VARS 2>/dev/null; GIT_AUTHOR_NAME='…' exec git '…'` (`src/Workspace/GitRunner.php:279-306`) and runs it via the string form of `proc_open` (`:247`, `:325`). On Windows, `ProcessContainment::spawnSpec()` returns the string unchanged (`src/Support/ProcessContainment.php:238`, `:504-511`), so PHP sends it through cmd.exe, which cannot parse `unset`, `exec`, `VAR=val cmd` or `2>/dev/null`. `EnvironmentBlock.php:1619` has the same shape via `shell_exec`.
*Fix:* pass git and gh as argv arrays, with `GIT_*` in the `$env` argument of `proc_open`.

**2. Other string-form shell spawns skip the Windows ladder (High, VERIFIED).** Only the Bash tool uses `WindowsShell::bash()` and refuses when no bash.exe exists (`src/Tools/BuiltIn/Bash.php:550-560`, `:705`, `:718`). These do not: `Grep.php:450`, `Commands/BangShell.php:233` (`bash -c`), `Tools/UserTools/UserScriptTool.php:127`, `Format/FormatRunner.php:320`, `Lint/LintRunner.php:242`, `RepoMap/CtagsSymbolExtractor.php`, `Workspace/GhRunner.php:128`. `escapeshellarg()` produces cmd-style quoting on Windows, and `%` and `!` are mangled.
*Fix:* one helper that returns an argv for `WindowsShell::bash()`, or refuses with `BASH_MISSING_NOTICE`, used by all of them.

**3. Windows CI does not test candy-code (High as a process gap, VERIFIED).** `scripts/affected-libs.php:30-32` lists `WINDOWS_LIBS` without candy-code. The `candy-code-windows-smoke` job (`.github/workflows/ci.yml:816-870`) runs `--version` and `tests\Windows\`, which skip unless `SuiteSkipRoster::windowsSmokeRuns()` is true (`candy-code/tests/Windows/WindowsBashSmokeTest.php:43`). Finding 1 is invisible to CI.

**4. Hook commands on Windows (Med, VERIFIED code / INFERRED behaviour).** `ScriptHook::spawnCommand()` returns the raw user string when no sandbox is active (`src/Hooks/ScriptHook.php` ~830-840), so hooks run under cmd.exe. The tree-kill (`taskkill /T /F`, `CapturesProcessOutput.php:144`, `:440`) seems wired only to the captured-output path, not to hooks. `docs/ENVIRONMENT.md:160-173` tells Windows users to "pass your command as a list", but hook commands are config strings and have no list form.

**5. False TERM warning on Windows (Med, VERIFIED code / INFERRED env).** `Cli/Bootstrap.php:11326-11339` warns when TERM is unset, empty, `dumb*` or `unknown*`, with no platform exemption. Native cmd and PowerShell usually leave TERM unset, though ConPTY/VT works, so every interactive launch shows a false warning.
*Fix:* skip the notice on `PHP_OS_FAMILY === 'Windows'`, or when `WindowsConsole` reports VT enabled.

**6. Line endings (Low-Med, VERIFIED).** Root `.gitattributes` sets `-text` only for fixtures. `bin/candy-code` and `docker/entrypoint.sh` (mode 100755) have no `eol=lf` rule, so an autocrlf clone gets `#!/usr/bin/env php\r`.
*Fix:* `bin/* text eol=lf` and `*.sh text eol=lf`.

**7. Launch shims and distribution (Low, VERIFIED).** The repo has `bin/candy-code` only. Composer generates `vendor/bin/candy-code.bat` on install (INFERRED, standard Composer behaviour). Packaging is Homebrew only; there is no Scoop, Winget or Chocolatey manifest.

**8. POSIX fallbacks (Low, VERIFIED code / INFERRED impact).** `MCP/GitCommandHandlers.php:1680-1690` appends `:/usr/bin` to PATH, which corrupts a `;`-separated Windows PATH, and defaults HOME to `/tmp`. `Session.php:214` falls back to `/tmp`. `HookLog.php:352-381` and `AuditHook.php:828` skip owner checks when `posix_geteuid` is missing.

**Already correct:** no cmd.exe fallback for Bash (`src/Support/WindowsShell.php:30-36`); `serve` refuses without posix (`src/Server/Preflight.php:67-68`), matching the docs; ConPTY gated by PHP 8.4 and ext-ffi (`ProcessContainment.php:859-865`); VT mode and UTF-8 code page set and restored (`sugar-core/src/Util/Tty/WindowsBackend.php:279-280`, `:488-489`); WSL detected before native Windows (`sugar-core/src/Util/Tty.php:59-76`); bubblewrap and Seatbelt OS-gated and refuse cleanly; pcntl background shells refuse without pcntl (`BackgroundShellRegistry.php:498`); `HOME`/`USERPROFILE` fallback in `src/Support/HomeDirectory.php:303`.

**Status matrix (by reading, nothing executed):**

| Capability | Linux | macOS | WSL | Win cmd | Win PowerShell |
|---|---|---|---|---|---|
| TUI render / VT / UTF-8 | works | works | works | partial (PHP 8.4 + ffi; false TERM notice) | partial |
| Bash tool | works | works | works | partial (needs Git for Windows bash.exe, else refuses) | partial |
| Git / gh tools | works | works | works | **broken** (#1) | **broken** |
| Grep / format / lint / `!` | works | works | works | broken or partial (#2) | broken or partial |
| Hooks | works | works | works | partial (#4) | partial |
| Background PTY | works | works | works | partial (ConPTY, smoke dormant) | partial |
| Sandbox | works (bwrap if installed) | works (Seatbelt) | unknown | refused cleanly | refused cleanly |
| serve | works | works | works | refused (correct) | refused (correct) |

## 2. Concurrency, workflows, background work

**11. Bash is a concurrency barrier (Med-High, VERIFIED).** `src/Runtime.php:2497` runs a call concurrently only if its tool is `ParallelSafe`. `Bash` does not implement `isParallelSafe()`, and per the `runsConcurrently` docblock, Bash, Edit and every `mcp__*` tool are barriers. A turn of "run tests, then grep" queues behind every shell command; Claude Code runs independent Bash calls concurrently.
*Fix:* make read-only Bash (or `run_in_background` Bash) parallel-safe, declared per invocation rather than per class.

**12. 90-second parallel-group kill (Med, constant VERIFIED / impact INFERRED).** `src/Runtime.php:70-80` (`PARALLEL_TOOL_DEADLINE_SECONDS = 90`) SIGKILLs every still-running sibling and reports a timeout. A slow WebFetch in a batch is lost with no progress shown. The `ExemptFromParallelDeadline` marker covers little.
*Fix:* per-call elapsed time in the UI, and extend a call's budget while it is still producing heartbeats, up to the 120 s turn watchdog.

**13. Delegation concurrency is unbounded by default (Med, VERIFIED defaults).** `src/Agents/AgentWorkerPool.php:17` documents a default of 5, but `src/Runtime.php:2856` sets `$delegationSlots = null` (no limit) when `maxConcurrentDelegations` is unset. The sequential fallback is only a notice (`AgentWorkerPool.php:2712-2716`, `2771`).
*Fix:* show the limit and queue depth in the status line or `/agents`, and make a fallback a visible status change.

**14. Ctrl+C leaves hook children running (Med-High, VERIFIED from comment).** `src/Chat.php:5617-5621`: quitting does not wait; "the child runs out its chain," bounded by the script-hook budget. Sub-agents are handled well: `AgentWorkerPool::__destruct()` (`AgentWorkerPool.php:484`) kills and reaps.
*Fix:* on quit, SIGTERM the hook process groups and wait a bounded grace period, mirroring `cancelAll()`.

**20. Tool-path workflow kill is not resumable (Med, VERIFIED `docs/FLEET-DRIVING.md:202-216`).** SIGTERM on the `Workflow` tool path exits 143 with empty stdout and no pause record. The run ID was in a tool result the process never printed, so the user must find `.running/plan-*.json` by hand. The native `workflow run` verb does write a cooperative pause.
*Fix:* install the same interrupt handler on the tool path, or print the run ID on stderr at start.

**26. Accumulate loops re-run on resume (Low-Med, VERIFIED `docs/WORKFLOWS.md:493-496`, `:550`).** The accumulated set is not persisted, so completed rounds re-execute, costing tokens and possibly repeating side effects. *Fix:* persist the set per round.

**27. Background shell lifetime and cap (Low-Med, VERIFIED `src/Background/BackgroundShellRegistry.php:169,180`).** Shells are killed at 30 minutes and capped at 64, whether or not anything reads them. The cap is not shown. *Fix:* per-call lifetime, and remaining lifetime shown in `ShellOutput`.

**Strengths (VERIFIED):** `BackgroundSupervisor` keeps a per-session index and `reconnect()` (`BackgroundSupervisor.php:1959`) re-adopts daemons across TUI restarts, with 15 s heartbeat timeouts and per-spawn tokens. Growth is capped in `AgentLiveState::MAX_RUNNING_CALLS=16`, `AgentRunCards::MAX_FOLLOWUPS=20`, `SubAgentTranscriptLog::MAX_RESULT_BYTES` and `BackgroundShellRegistry::MAX_READ_BYTES`. Retention of completed `AgentManager` results across long sessions was not verified (INFERRED unbounded).

**Missing task surface (High, VERIFIED for tools).** Background shells are reachable only by model tools `ShellOutput`/`ShellStop` (`src/Tools/BuiltIn/ShellStop.php:100`, `Bash.php:477`). `/bg` appears only as a reserved word (`src/Chat.php:11029`); no handler found. `/agents` exists (`src/Commands/AgentsCommand.php`). There is no `/tasks` view showing running shells, age, output tail and a kill key. *Fix:* a `/tasks` panel for shells, sub-agents and workflow runs, with tail, stop and elapsed time.

## 3. TUI informativeness and discoverability

**4. Tool rows hide what ran (High, VERIFIED).** `src/Renderer.php:5317-5360` builds rows from the tool name, a status glyph and the model-authored `description` (`toolCallSuffix()`, `src/Message.php:463-470`). The literal command or path is not shown. `Bash.php:750-754` returns `isError: exitCode !== 0` and the text carries no exit number.
*Fix:* header shows the real command (Bash), path (Read/Edit/Write) or pattern (Grep/Glob), truncated with `…`. Bash rows always show `exit N` and duration (duration already exists at `Renderer.php:5436`).

**15. Keybindings missing from `/help` and the popup (Med, VERIFIED).** `src/Chat.php:13570-13590` prints `/name <argHint>` and the description only. `CommandSpec::$shortcut` feeds only the palette (`src/Palette/PaletteCatalog.php:64,83`).
*Fix:* a right-aligned shortcut column in `/help` and the `/` popup.

**16. No background-jobs indicator in the status bar (Med, VERIFIED by grep).** `renderStatusBar` (`src/Renderer.php:2488+`) covers mode, agent, incognito, processing, context and spend. It never references `BackgroundSupervisor`.
*Fix:* a `⚙ N bg` segment at the lowest fitting priority.

**34. Collapsed output hides line counts (Low, VERIFIED).** `InterfaceSettings.php:304` defaults `expandToolOutput` to false (`Renderer.php:905-912`). Expansion works by Ctrl+O, but the collapsed row does not say how much is hidden. *Fix:* "· 214 lines hidden · ctrl+o".

**35. Failed tools give no next step (Low, VERIFIED).** `✗ error` has no pointer to stderr or the expand key.

**Strengths (VERIFIED):** unknown-command, step-limit and loop-guard notices name the fix (`lang/en.php:3368`, `:1480-1482`); the stall notice reads "no tokens for {gap} · Esc Esc to cancel" (`lang/en.php:3371`); slash popup shows argument hints (`Renderer.php:7002`); `@file` completion (`HandlesKeys.php:670`); configurable status line (`src/Config/StatusLineCommand.php`). `ScreenReaderMode` is used at 39 call sites and `CANDY_CODE_REDUCED_MOTION` is read once (`Cli/Bootstrap.php:1461`). All 12 `lang/*.php` files match `en.php` in line count (parity test `tests/LangParityTest.php` not run). Tool errors are deliberately English-only.

**Accessibility doc gap (Low, VERIFIED).** `docs/ACCESSIBILITY.md` never mentions `NO_COLOR`, which sugar-core honours (`sugar-core/src/Util/ColorProfile.php:67`) and `docs/ENVIRONMENT.md:362` documents. Not verified: the 4.5:1 contrast floor and whether streamed text is announced to screen readers.

## 4. Skills, tools, MCP

**5. MCP startup is serial (High, VERIFIED).** `src/MCP/McpClient.php:390` (`startServers()`) loops over entries; `docs/MCP.md:355-370` says concurrent spawn was deliberately not built. Each cold `npx` server holds the first frame up to its `startTimeout`, and the bounds stack.
*Fix:* concurrent or background startup, with a "connecting" row per server in the roster.

**17. No MCP health check (Med, VERIFIED).** The only `ping` in `src/MCP/` is the inbound reply at `McpServerRequests.php:128`. Liveness is a snapshot (`McpClient.php:667`, `docs/MCP.md:750-764`, which can say "state unknown"). A dead stdio server is found on the next call.
*Fix:* periodic ping, explicit up/degraded/down state, one bounded auto-reconnect with a visible notice, then `/mcp reconnect` (`docs/MCP.md:129`).

**18. Deferral is all-or-nothing per server (Med, VERIFIED).** A server whose total schema exceeds `mcp.lazySchemaTokens` (default 8000, `docs/MCP.md:707-711`) is demoted entirely to `mcp_list`/`mcp_call` (`McpToolShape.php:128-137` names servers only).
*Fix:* per-tool deferral with on-demand schema lookup, closer to Claude Code's deferred tools and ToolSearch.

**19. No MCP hot-reload (Med, VERIFIED).** `docs/MCP.md:785-795` explicitly declines it. Skills already have `/skills reload` (`docs/SKILLS.md:409-429`).
*Fix:* `/mcp reload` that diffs the server set (start new, stop removed, restart changed), reusing the skills reload logic.

**25. Load problems announced only at launch (Low-Med, VERIFIED).** `reportSkillSkips()` (`src/Cli/Bootstrap.php:5796-5812`) reports each path once per process; `recordSkip` writes to the log only in debug mode (`src/Skills/SkillLoader.php:163-169`). A SKILL.md that breaks mid-session is silent until `/skills` or a reload.
*Fix:* surface new skips on reload or next turn, and show the reason in `/skills`.

**37. Skill invocation not a distinct row (Low, UNVERIFIED).** `SkillTool` (`src/Tools/BuiltIn/SkillTool.php:139-146`) is a generic tool call; "Loaded skill: {name}" (`lang/en.php:3776`) exists but its rendering was not confirmed. *Fix:* a "Skill(name)" row with body size.

**38. MCP name shadowing (Low, UNVERIFIED).** Wire names are injective (`McpToolBridge.php:371-378`), so no collision bug was found. There is no notice when an MCP tool would shadow a built-in (`ToolCatalog.php:146` checks but seems silent).

**Strengths (VERIFIED):** skill listing is clipped per line and under an aggregate byte budget (`docs/SKILLS.md:137-142`); progressive disclosure via `SkillTool` (`docs/SKILLS.md:264`); MCP auth-required is shown with a fix command (`McpClient.php:855-870`, `:564`); per-server session disable and enable (`McpClient.php:331-343`); `/mcp` panel shows status and liveness (`src/Tui/McpPanel.php:100-185`); skill `paths:` nudges.

## 5. Permissions, safety, configuration, providers

**8. Withdrawn.** Bypass mode treating `ask` rules as allow is the intended behaviour: bypass means skip permission checks (`src/Permissions/PermissionGate.php:1050`, `docs/PERMISSIONS.md:81-86`). The `/bg` default to bypass (`docs/PERMISSIONS.md:26-30`) is a design choice, not a defect, so it is not listed as a problem.

**9. The prompt does not say which rule matched (Med, VERIFIED).** `evaluateRules()` (`PermissionGate.php:1137-1146`) returns only the action, discarding the rule and source file. `renderPermissionPrompt` (`Renderer.php:7997`) has no "matched by rule X in file Y" line.
*Fix:* return the matched rule with its source (user, project, saved); show it in the prompt and in `/permissions explain <call>`.

**22. Plaintext API keys (Med, VERIFIED storage / INFERRED file mode).** `src/Config/ProviderKeyStore.php:55` writes `apiKey` into `~/.candy-code/config.json` via `writeUserConfig()`. The `0600` mode appears to come only from `tempnam()` (`Bootstrap.php` ~5313) and is not asserted. `ENVIRONMENT.md:320` documents `${keychain:<name>}`, which `login` does not use by default.
*Fix:* `login` writes a `${keychain:…}` or env reference by default; test the `0600` mode; `doctor` warns on group- or world-readable config.

**29. No scriptable config introspection (Low-Med, VERIFIED).** The CLI verb map (`src/Cli/Subcommands.php:6722-6752`) has no `config` verb. Source tracking exists (`SettingSource`, `ResolvedSetting`) and shows in `/settings`.
*Fix:* `candy-code config show [--sources] [--json]` and `candy-code permissions list --json`.

**30. Sandbox off by default (Low, VERIFIED).** `src/Config/Settings/Definitions/ToolSettings.php:98` sets `bashSandbox` to `MODE_OFF`. The sandbox design is good (write jail, egress allowlist, fail-closed, `off` user-tier only). *Fix:* a first-run prompt or `doctor` row naming the sandbox state, with a one-step enable.

**24. Repo-controlled retry count (Low-Med, VERIFIED / impact INFERRED).** `providerRetryAttempts` is project-tier (`LayeredSettings.php` `PROJECT_TIER_KEYS`), so a checkout can raise retries to 5 without a trust prompt. Bounded, but it is cost amplification a repo can set.

**Strengths (VERIFIED):** trust list is absolute-path-only and frozen per process; `permissionMode` and `permissionRules` readable only from user files; project rules can only narrow; hooks, MCP, commands and settings each have a trust key; settings files are on the protected-write list (`ProtectFilesHook.php:313`); corrupt user settings refuse launch; provider retries use full jitter with `Retry-After` and caps (`TransientFailure.php:190, 224`); unpriced models are flagged, not counted as free (`Usage.php:188-201`); `/permissions`, `/settings`, `/doctor`, plan mode (Alt+M) and a sandbox richer than Claude Code's. Note: project settings are re-read every turn after trust, so a tool call in a trusted repo can change next turn's `disabledSkills` or `notify`; the trust prompt does not say this.

## 6. Context, memory, compaction, sessions

**6. Context window fallbacks are inconsistent (Med, VERIFIED).** `src/Providers/CodexProvider.php:207-210` returns 0, which becomes the 100k `FALLBACK_TOKENS` (`src/Context/ContextWindow.php:53,68`). `CustomProvider.php:380-385` falls back to 128k. `ClaudeCodeProvider.php:186-196` returns 200k for every model and has identical match arms (dead code), while `AnthropicProvider.php:85-93` lists `claude-opus-4-6` at 1M. The same model reports different windows depending on the wrapper. The 100k guess is deliberate (`ContextWindow.php:28-51`), but nothing marks it as unknown in `/context` or the status bar.
*Fix:* an explicit `Known(n)` / `Unknown` value, "window unknown (assuming 100K)" in the status bar, one shared window table.
*Mitigation:* `Host/ContextMeter.php:223` calibrates against provider-reported usage; `ContextBudget::threshold()` (`ContextBudget.php:89-102`) is sound.

**7. Compaction is opaque and irreversible (Med, VERIFIED / INFERRED for reversibility).** `src/Context/ContextCompactor.php:21-23`: the heuristic fallback truncates user messages and writes `[exchanged information]` for assistant replies. It runs on `compaction.mode = heuristic`, on hitting the spend cap, or when the summary call fails. `docs/CONTEXT.md` says the transcript shows which path did the work, but the status bar does not. Rows are hidden, not deleted, yet no command restores a compacted range to the model's view. `/rewind` is a checkpoint restore, not an uncompact.
*Fix:* "summary: model | heuristic (reason)" on the boundary notice and in `/context`; `/compact --undo` while the summary is still in the transcript.
*Credit:* `/compact [focus]` and `/compact --self` (preview) exist, at parity with Claude Code.

**33. Pruning placeholder names the wrong recovery path (Low-Med, VERIFIED text / INFERRED behaviour).** `src/Context/Pruning/PrunedOutputPlaceholder.php:36` says "re-run the tool". `Recall` exists under `src/Tools/BuiltIn/` but is not named. A re-run may return different output, and the model may not know `Recall` exists.
*Fix:* "recall with `Recall` id=…", plus a short content hash so the model can detect staleness.

**21. Resume state is partly unverified (Med, PARTLY VERIFIED).** Verified: `switchToSession()` (`src/Chat.php:2538-2557`) loads the transcript; permission grants (`src/Host/TranscriptStore.php:279`) and todos (`TranscriptStore.php:222-238`) are reloaded. Not verified: whether model, provider, cwd and permission mode are re-applied. The session row stores `model` (`src/Session/EnhancedSessionStore.php:2758`), but no read-back into the live engine was found. A resumed session may silently run on the current default model. Whether an active `/goal` survives resume is also unverified.
*Fix:* on resume, print "restored: model X, permissions Y, cwd Z, N todos", and warn on any mismatch.

**36. Crash safety (Low, VERIFIED).** The agent transcript is SQLite with WAL (`src/Session/SessionStore.php:918`). `DebouncedTranscriptWriter.php:48` debounces 0.5 s and flushes on shutdown (`:146-164`), so SIGKILL can lose 0.5 s. The file-browser session (`src/Session.php:55-118`) uses `AtomicJsonFile` correctly. Naming trap: `src/Compactor.php` is file grouping, not conversation compaction.

**Strengths (VERIFIED):** instruction-file discovery covers CLAUDE.md, CLAUDE.local.md, AGENTS.md, GEMINI.md, .cursorrules, .clinerules and copilot-instructions (`src/Context/InstructionFileLoader.php:209`); `@import` has cycle protection and a 61,440-byte cap (`:109`); loaded files are reported (`:1770`, `chat.instructions.loaded`). Gap (INFERRED): a document over the cap may be skipped without a visible notice. `/memory` supports add, list, search, edit, import, clear and restore (`docs/COMMANDS.md:403`), but it is entry-based, while Claude Code's `/memory` opens CLAUDE.md files directly. The repo map is capped at 8,192 bytes (`src/Context/RepoMapBlock.php:262`), with walk limits; whether it is cached across sessions was not confirmed.

## Top changes, across all areas

1. **Make Windows git/gh/shell work**: argv spawns with `$env`, one WindowsShell-aware helper, and candy-code added to Windows CI with a non-dormant smoke set (#1, #2, #3).
2. **Show the work**: a `/tasks` panel, real command and exit code in tool rows, a background-jobs segment in the status bar (#3, #4, #16).
3. **Permission transparency**: a matched-rule line in prompts and a `/permissions explain` view (#9).
4. **Concurrency that matches expectations**: read-only Bash parallel-safe, no 90 s discard of finished siblings, bounded and visible delegation, quit that reaps hooks (#11, #12, #13, #14).
5. **MCP reliability**: concurrent startup, health pings, per-tool deferral, `/mcp reload` (#5, #17, #18, #19).
6. **Honest context and sessions**: explicit unknown window, compaction source visible with an undo, and a resume restore notice (#6, #7, #21).
7. **Secrets**: keychain or env references by default for `login`, and a tested `0600` mode (#22).

## Not covered

- `RemoteBackend` TLS and token handling, and whether "always allow" scopes can widen in the scope editor (permissions review).
- Test suite was not run; no finding here was reproduced at runtime.
- Items marked UNVERIFIED (#37, #38) need a check before anyone acts on them.
