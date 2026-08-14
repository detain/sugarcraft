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
