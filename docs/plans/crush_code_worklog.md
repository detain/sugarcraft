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
