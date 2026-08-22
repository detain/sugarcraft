# sugar-crush — hardening / audit-instrument backlog

**What this is.** A single actionable ledger of every security, containment,
permission-surface and audit-instrument finding that `crush_code.md`'s 13-angle
audit and its review chain **measured and then deliberately deferred**. It is
compiled from `docs/plans/crush_code_worklog.md` (5855 lines), `crush_code.md`'s
execution-status block, and `git log`/source verification at HEAD
`cfc00909e47de9159e5af6517aa75c21c55b069d` (2026-08-18).

**The rule that produced it.** The user's instruction, verbatim (2026-08-17):

> "security updates are to be held off till the end of the plan where possible…
> i mean make notes and steps for it .. dont ignore the problems but where
> possible push the fixing of the security ones to the end … id like to start
> using it with all the functionality finished asap .. while we finish the
> security parts of it"

So: **defer the fix, never the finding.** Everything below was probed once, by a
review round that had a sandbox and a mutation protocol. This file exists so the
end-of-plan hardening pass starts **from proof rather than re-discovery**.

**Acceptance target.** The user wants to daily-drive `bin/sugarcrush` as soon as
the functionality phases (4 → 5 → 6 → rest of 2 → 7) land, *while this backlog is
still open*. So each entry's Severity is written from that angle: what does a
single-user, own-checkout, own-`$HOME` operator actually risk today. Almost
everything here needs either a **cloned/tarball-delivered repository** or a
**second local user**; a solo operator on their own trees is the least-exposed
case, which is exactly why the deferral is affordable.

**A caveat on five in-flight files' line numbers.** `sugar-crush/src/Chat.php`,
`src/Renderer.php`, `src/Commands/CommandRegistry.php`, `README.md` and several
files under `sugar-crush/tests/` were **modified and uncommitted** while this was
compiled (a concurrent Phase 4 lane). Line references into those five files were
read against the **working tree**, not against HEAD, and will move again when that
lane commits. Every citation into any other file is against HEAD. No test suite
was run during compilation, deliberately: a run that loads a file another lane is
editing shifts `file(__FILE__)` ranges against already-loaded reflection and
produces phantom failures.

**Reading the numbers.** This document is made almost entirely of counts, widths
and `file:line` references — the failure mode this whole chain kept producing
was *a number that travelled without its domain*. So: every count names what it
counts; every line was re-checked against the current file (per the caveat
above) and marked **DRIFTED**
where it moved; and where the worklog corrected an earlier measurement, the
**latest** figure is given and the superseded one is named.

**Standing rule, honored throughout.** No Step below says "delete it". Where a
subsystem is unwired, the fix is to wire it or to document it as an intentional
dormant seam. The audit's own research agents proposed several deletions and were
explicitly overridden during compilation; §F records those as seams, not as work.

---

## Status corrections to apply before executing this list

Three things the supervisor's running list carries that are **stale at HEAD**.
Fix the tracking, not the code:

1. **"lane D findings F3–F7 follow-ups" are already landed.** `dad90b18`'s own
   commit message closes them: *"F3-F7 in the same round: the include gate no
   longer skips on the constructor default; `patternStaysInside()` refuses
   backslash- and drive-rooted patterns (C:x drive-relative remains a stated
   known gap, unmeasurable on a POSIX host); an unconstructible tool fails loudly
   instead of vanishing; and the discarded-result rule now names consumption."*
   Verified in source: `WorktreeManager::resolveWorktreeInclude()` gate is
   unconditional (`src/Agents/WorktreeManager.php:311-360`);
   `patternStaysInside()` refuses `\`-rooted and `[A-Za-z]:[\\/]` patterns
   (`:575`); `isConstructible()` moved out of `isDispatchableTool()` into
   `instances()` where a false is a named throw plus an `instanceof Tool` check
   (`tests/Tools/BuiltInToolCorpus.php:251`, `:527`, `:565`);
   `statementStartIn()` gained the named-argument-colon and `)`-boundary arms
   (`tests/Support/ContainedPathInventoryTest.php:905`); F7's
   `ProviderFactory::defaultConfigPath()` gained a gated
   `readableDefaultConfigPath()` in the same commit
   (`src/Providers/ProviderFactory.php:145`, `:199`).
   **The two genuine residuals from that group survive as §A1 and §A2 below.**
   The worklog contradicts itself here — see *Contradictions*.
2. **`TerminalBackground::observe()` is wired.** `crush_code.md` still lists it as
   a dormant seam awaiting two `App/App.php` edits; at HEAD `src/App/App.php:496`
   calls it and `src/Tui/TerminalBackground.php:111` reads *"Before `observe()`
   was wired…"*. Not a backlog item.
3. **The `Write` tool is wired.** `crush_code.md` says Phase 8 item 12 is
   "deliberately not registered"; at HEAD `src/Cli/Bootstrap.php:2498`
   constructs it. Closed by `c2ab3e31`.

---

## A. Path containment / jail

### A1 — `patternStaysInside()` allows the drive-relative spelling `C:x`

- **What** A `.worktreeinclude` pattern of the Windows drive-relative form `C:x`
  (colon with **no** separator after it) passes the lexical pattern guard, and on
  Windows that form denotes a path relative to the current directory *on drive C*
  — i.e. potentially outside the source root.
- **Where** `sugar-crush/src/Agents/WorktreeManager.php:575`
  (`patternStaysInside()`); the residual is stated in its own docblock at
  `:560-573`.
- **Severity** Containment gap — **Windows-only, and UNVERIFIED as exploitable**.
- **Evidence** Lane D round 9's F4 measured, on the *pre-fix* predicate, that
  `\etc\passwd`, `C:\Users\victim\.ssh\id_rsa`, `C:/Users/x` and a bare `\` were
  all ALLOWED while `/etc/passwd` was refused. `dad90b18` closed all of those
  (`str_starts_with($pattern,'\\')` plus `preg_match('#^[A-Za-z]:[\\\\/]#',…)`).
  What remains is only the separator-less `C:x`, and the code says so in the
  honest form: *"THE RESIDUAL IS UNVERIFIED, not argued away: this host is POSIX,
  so whether `<root>/C:x` reaches the drive-relative path or is rejected by the
  Windows filesystem as a colon inside a component could not be driven here. It
  is a negative result — an untested case — and not a claim of safety."*
- **Step** Establish the consequence on a real Windows host first (does
  `<root>/C:x` resolve, or does the filesystem reject a colon inside a path
  component?). Only then decide: widening the predicate to bare `[A-Za-z]:`
  closes it, but the predicate is deliberately the *same one*
  `HomeDirectory::owned()` uses (`src/Support/HomeDirectory.php`), and two
  spellings of one absoluteness test is how the two drift — so widen **both** or
  neither, in one edit.
- **Blocked on** Access to a Windows host. Nothing else.

### A2 — `WorkflowEngine`'s `.running/*.json` pause files bypass the anchored path

- **What** Every *read* `WorkflowRegistry` performs goes through the containment-
  anchored answer, but the engine's pause files are written to and read back from
  the **configured** `workflowsPath()`. So a `~/.sugar-crush/workflows ->
  <outside>` link that the registry refuses to `list()` or `require` out of still
  relocates `<outside>/.running/*.json`.
- **Where** `sugar-crush/src/Workflows/WorkflowRegistry.php:275`
  (`workflowsPath()`, residual stated at `:262-274`);
  `sugar-crush/src/Workflows/WorkflowEngine.php:62` (`PAUSE_DIR = '.running'`)
  and `:308` (`getPauseFilePath()`).
- **Severity** Containment gap — bounded, and the bound is written down.
- **Evidence** Surfaced not by a reviewer but by `dad90b18`'s **own new
  instrument**: `tests/Support/ReadPathCensusTest.php` derives every read/execute
  sink in `src/` (76 occurrences, `require`/`include` and generated-code strings
  included) and demands a per-occurrence verdict; writing the verdict for this
  one forced the residual into the record. The commit message states the harm
  bound: *"What that yields an attacker is the engine's own JSON in a directory
  they already chose — not code, not a foreign file's contents."*
- **Step** Do **not** hand the engine a second, stricter accessor — the code
  argues, correctly, that two accessors could then disagree about where a run was
  paused. Either anchor `workflowsPath()` itself (and accept that a user's own
  symlinked workflows directory stops pausing), or move the pause directory out
  of the registry's tree entirely onto a path the process chose. Whichever is
  picked, the choice must be asserted, not described.
- **Blocked on** Nothing. Independent of the plan's remaining phases.

### A3 — tracker #84: a same-checkout `workflows` symlink still discloses `*.yaml` basenames

- **What** A repository can commit `.sugar-crush/workflows -> <another directory
  inside the same checkout>` and have that directory's `*.yaml` **basenames**
  disclosed through the workflow listing.
- **Where** `sugar-crush/src/Workflows/WorkflowRegistry.php`
  (`readableProjectDir()` / `yamlDirectories()`, `:565` and `:728`).
- **Severity** Containment gap — documented as *a reduction, not an elimination*.
- **Evidence** Filed as tracker **#84** (worklog `:2020`, confirmed and sharpened
  at `:2278`). The reason it was not closed is a measured cost, not an oversight:
  closing it would refuse the ordinary `-> tools/workflows` layout.
- **Step** No code change unless someone decides basename disclosure *within a
  checkout the user already cloned* is worth breaking `-> tools/workflows` for.
  Recommended resolution is to **close this as accepted risk with the cost
  written next to it**, which is most of what the current docblock already does.
- **Blocked on** A product decision, not an engineering one.

### A4 — lane D F-6: the dangling-link refusal misses the one-component-higher layout

- **What** With the symlink one component higher — `.sugar-crush -> <a directory
  that exists but has no workflows/ in it>` — `realpath('<root>/.sugar-crush/
  workflows')` is `false` **and** `is_link(...)` is `false`, so the dangling-link
  refusal does not fire and the directory is granted.
- **Where** `sugar-crush/src/Workflows/WorkflowRegistry.php:528-540` as cited by
  the review. **DRIFTED** — at HEAD that region is inside the
  `yamlDirectories()`/`readableProjectDir()` doc cluster (`:522-565`); re-locate
  by the refusal string rather than the line.
- **Severity** Instrument blindness / claim defect — **not a live escape**.
- **Evidence** The review that found it also pushed on it and failed to exploit
  it: *"Not exploitable as far as the reviewer pushed it: **0 disclosures in
  40,000 `list()` calls** against a child flipping the target, and once the
  target exists the directory is refused."* What is wrong is the **claim**: the
  doc says the residual window costs "write access to the checkout", but in that
  layout the path that must appear is *outside* the checkout (`/tmp/pwn/
  workflows`) — i.e. write access to `/tmp`.
- **Step** Correct the claim in the docblock to name the real precondition
  (write access to the *link target's* parent, which may be outside the
  checkout). Optionally add the one-component-higher layout to the refusal, but
  measure the false-refusal cost first — this is the same trade as A3.
- **Blocked on** Nothing.

### A5 — foreign agent presets scan user-then-project, last-write-wins

- **What** `ForeignAgentPresetRegistry` scans the user tier then the project
  tier and lets the project tier overwrite, so a **cloned repository's**
  `.claude/agents/reviewer.md` outranks the user's own
  `~/.claude/agents/reviewer.md`. Its sibling `ForeignSkillDiscovery`
  deliberately does the **opposite**, with the argument written out.
- **Where** `sugar-crush/src/Agents/ForeignAgentPresetRegistry.php:169`
  (`discover()`); the asymmetry and its reasoning are in the docblock at
  `:148-168`. Wired into production by `15a2e605` via
  `src/Cli/Bootstrap.php:793` (`foreignAgentPresets()`) →
  `:693` (`agentRoster()`).
- **Severity** Containment gap — **newly reachable**, bounded by layering.
- **Evidence** `15a2e605`'s record: the wiring *"does not widen it (native still
  wins) but makes it reachable for the first time."* Final precedence is
  **foreign < built-in < native preset**, ordered in one place in
  `agentRoster()`. The registry's own docblock states the harm shape it is
  choosing to keep: *"foreign content arrives with any repository you clone, and
  letting it displace a name the user relies on is the 'cloned content silently
  redefines the user's setup' shape. The same argument applies here with a
  stronger conclusion, since an imported preset carries a sub-agent's whole
  prompt."* It was recorded rather than changed because reversing it is a
  behaviour change with its own pinned tests.
- **Step** Reverse the merge direction so the **user** tier wins, matching
  `ForeignSkillDiscovery::discoverClaude()`, and invert the tests that currently
  pin the project-wins direction rather than deleting them (this chain's
  convention). Keep the union operator (`$claude + $this->scanOpencode(...)`) —
  the docblock records why a spread or `array_merge` breaks numeric-string
  filename stems like `12.md`.
- **Blocked on** Nothing. Independent of §A6.

### A6 — `Agent::fromPreset()` silently drops the preset's behavioural fields

- **What** The preset→`Agent` bridge carries only `name`, `description`,
  `initialPrompt`, `model`, `tools`, `skills`. Every richer field is dropped
  without notice — which today *bounds* the §A5 exposure, but the bound is only
  as good as `Agent`'s shape.
- **Where** `sugar-crush/src/Agents/Agent.php:116` (`fromPreset()`); the field
  set is `sugar-crush/src/Agents/AgentPreset.php:22-37`.
- **Severity** Containment gap — a **load-bearing accident**, which is the
  dangerous kind.
- **Evidence** Counted at HEAD against `AgentPreset`'s constructor: **8
  behavioural fields dropped** — `disallowedTools`, `permissionMode`,
  `maxTurns`, `mcpServers`, `memory`, `background`, `effort`, `isolation` — plus
  `color` and `source` not carried. **Two records disagree and both undercount**:
  the worklog names seven (`permissionMode`, `disallowedTools`, `maxTurns`,
  `memory`, `background`, `effort`, `isolation` — no `mcpServers`), and
  `fromPreset()`'s own docblock names five (`permissionMode`, `maxTurns`,
  `effort`, `isolation`, `mcpServers` — no `disallowedTools`, `memory`,
  `background`). The 8+2 figure above is measured against the constructor at
  HEAD and is the one to use.
- **Step** Whatever else happens, make the drop **explicit and asserted**: a test
  that enumerates `AgentPreset`'s constructor by reflection and fails when a new
  field appears without a decision recorded for it. Then decide per field. Note
  the trap: carrying `permissionMode` through would make a **cloned repo's**
  preset able to request `bypass-permissions`, which is the escape lane D
  measured in a different path (worklog `:4790`: `permissionMode=bypass-
  permissions` arriving from a foreign preset). So the correct move for
  `permissionMode` is probably to keep dropping it and *say so*, not to wire it.
- **Blocked on** Nothing, but sequence it **after** §A5 so the precedence rule
  is settled before the field surface widens.

---

## B. Permission surface

### B1 — `c075adcf` (the permission fix) shipped without adversarial review

- **What** The commit that made a permission prompt stop answering to a slash
  command was committed without the adversarial review round this chain applies
  to everything else. That omission is itself the gap.
- **Where** commit `c075adcf`, *"sugar-crush: lane E round 8 — a permission
  prompt no longer answers to a slash command"*. Touches `src/Chat.php`,
  `src/Renderer.php`, `src/Permissions/*`,
  `src/Commands/KeyBindingRegistry.php`, `tests/Renderer/KeyHelpTest.php`.
- **Severity** Instrument blindness — an unreviewed change to the **permission
  gate**, i.e. the highest-consequence surface in the package.
- **Evidence** Recorded four separate times in the worklog queue as
  *"Never reviewed: `c075adcf` (the permission fix)"* (`:5485`, `:5610`), and
  the review brief for it *"never ran"*. What the round *self*-reported is
  substantive and re-drivable: all nine slash commands are swallowed whole;
  `/agents`, Enter, `y` approves once at keystroke nine; `ay` grants at keystroke
  two; `an` cancels the confirm and leaves the prompt ARMED; `and` cancels and
  DISARMS. It also self-reported two defects a reviewer would have looked for —
  see §B2 — and that *"the first draft of the queued-ask test could not fail"*.
- **Step** Run the review. The brief is reconstructable from the worklog section
  "Round 8 landed — `c075adcf`". Re-drive the nine-command table and the
  `ay`/`an`/`and` sequences against HEAD, and attack the ARMED-prompt keyboard
  ownership, since Phase 3 item 1 (`939f8ada`) has since changed key routing
  underneath it.
- **Blocked on** A window with no full-suite run in flight (standing rule 4:
  editing a file while a run of it is in flight shifts `file(__FILE__)` ranges
  against already-loaded reflection and produces phantom failures).

### B2 — every keybinding description except one is pinned by nothing

- **What** A binding's *description* text can be reverted or rewritten and the
  entire suite stays green. The drift suite reads a description only for
  keyish-token violations and paints it; it never asserts its words.
- **Where** `sugar-crush/src/Commands/KeyBindingRegistry.php` (rows, e.g.
  `:393` `chat.cursor`); the instruments are
  `tests/Commands/KeyBindingDriftTest.php` and `tests/Renderer/KeyHelpTest.php`.
- **Severity** Instrument blindness.
- **Evidence** Measured by `c075adcf`'s own fix agent, not by a reviewer:
  reverting `permission.always`'s wording left `KeyBindingRegistryTest`,
  `KeyBindingDriftTest`, `KeyHelpTest` and `RendererTest` **all green**. One
  assertion was added for that single row; *"every other row's wording remains
  unpinned, and that is a standing gap worth its own round."*
- **Step** Make the drift test assert each row's description against a declared
  expectation, or — better, given this chain's history with hand-maintained
  figures — derive the check: for each row, assert the description's promised
  key spellings and effect verbs against the handler actually reached.
  `KeyBindingDriftTest::alternates()` (`:181`) is already half of that machinery.
- **Blocked on** Nothing. Naturally bundles with §B1.

### B3 — `$projectTierRefusals` is reset only in `chat()`

- **What** The launch-wide collector of "which directories were refused and why"
  is cleared on one entry path only, so a second `Bootstrap` call on another
  entry path inherits the previous launch's refusals.
- **Where** `sugar-crush/src/Cli/Bootstrap.php:210` (declaration), `:247`
  (the only reset, inside `chat()`); accessor at `:513`; drained into at `:461`,
  `:472`, `:809`, `:882`, `:890`, `:1432`.
- **Severity** Real bug — but **cosmetic in the shipped binary**, structural for
  consumers.
- **Evidence** `15a2e605` recorded it as deliberately left, third of three:
  *"`$projectTierRefusals` is reset only in `chat()`."* The reasoning for the
  reset that *does* exist is at `:236-247` and states the harm exactly:
  *"`chat($badRepo)` followed by `chat($goodRepo)` still reported `$badRepo`'s
  directory as refused — harmless for the one launch a real binary makes, wrong
  for every consumer the accessor's doc-block invites."* The same sentence is
  the argument for extending the reset to the other entry paths.
- **Step** Reset it at every `Bootstrap` entry point that builds a fresh launch
  (not just `chat()`), and keep `$reportedProjectTierRefusals` deliberately
  **un**reset — the asymmetry at `:241-246` is correct and reasoned ("say this
  once per process" survives across launches).
- **Blocked on** Nothing.

---

## C. Process / execution

### C1 — tracker #60: `ScriptHook` has no execution timeout

- **What** A config-file hook script that never exits wedges the CLI. The drain
  loop blocks on a **null** `stream_select` timeout, so there is no upper bound
  on a hook's runtime.
- **Where** `sugar-crush/src/Hooks/ScriptHook.php:238`
  (`@stream_select($read, $write, $except, null)`); `proc_open` at `:158`.
- **Severity** Real bug — availability, reachable from a file the repository or
  the user supplies (`.sugar-crush/hooks.yaml`).
- **Evidence** Filed as tracker **#60** in the worklog's "Queued follow-ups
  found by the review chain" (`:566-572`): *"`ScriptHook` has no execution
  timeout (a hook that never exits wedges the CLI; **pre-existing**,
  deliberately excluded from the security fix)"*. Confirmed present at HEAD.
  The null timeout is *argued for* in code (`:234-237`) on cost grounds — *"a
  hook that runs for a minute costs no polling"* — which is a real benefit and
  not a mistake; what is missing is a ceiling on top of it.
- **Step** Keep the null-timeout blocking drain (it is the right shape) but wrap
  it in a wall-clock deadline: compute a deadline once, pass the remaining
  budget as the `stream_select` timeout each iteration, and on expiry
  `proc_terminate()` and report the hook as failed. Make the budget
  configurable with a documented default, and follow this project's LLM-timeout
  rule in spirit — a hook is *not* an LLM call, so a bounded total here is
  correct where it would be wrong on a provider request.
- **Blocked on** Nothing.

### C2 — `TextArea`'s Ctrl+O `$EDITOR` `proc_open` seam is one keymap edit from live

- **What** The vendored `candy-forms` `TextArea` binds Ctrl+O to
  `openInEditor()`, which spawns `$EDITOR` via `proc_open`. `Chat` does not
  route Ctrl+O to the widget today, so the seam is unreachable — but it is one
  keymap addition away from being a live process-spawn driven by an environment
  variable.
- **Where** `candy-forms/src/TextArea/TextArea.php:127` (`'o' => [$this,
  $this->openInEditor()]`), exit-code handling documented at `:742`. The gate is
  `sugar-crush/src/Chat.php:109` (`DRAFT_KEYS`, the delegation const) behind a
  total `!$msg->ctrl` guard, plus the explicit ctrl arms at `:1163-1175`.
- **Severity** Containment gap — **dormant**, and pinned as dormant.
- **Evidence** `939f8ada`'s deferral list, verified rather than assumed:
  *"`TextArea::update()`'s Ctrl+O `$EDITOR` `proc_open` seam is unreachable
  today and pinned, one keymap edit away from not being."* The reason Ctrl+O
  cannot arrive is recorded in `Chat.php`'s own comment: a ctrl-flagged `Char`
  goes to `insertRune()` and **not** to `update()`, *"because
  `TextArea::update()` reserves ctrl+a/e/u/k/o for its own line edits"*.
- **Step** Do **not** remove it. Before anyone binds Ctrl+O in `Chat`, require:
  (a) `$EDITOR` validated as an executable path rather than passed to a shell,
  (b) the spawn suppressed entirely in the non-interactive `-p`/`run` path and
  under `PermissionMode` values that disallow execution, and (c) a test that
  fails if Ctrl+O reaches `DRAFT_KEYS`. Item (c) can land **now**, cheaply, and
  is the highest-value half.
- **Blocked on** Nothing for (c). (a)/(b) only matter if the binding is added.

### C3 — `withCharLimit(0)` leaves the chat draft unbounded

- **What** The draft editor is constructed with `withCharLimit(0)`, disabling
  `TextArea`'s 65536-character paste-DoS guard.
- **Where** `sugar-crush/src/Chat.php:6213` (`freshInput()`), reasoning at
  `:6183-6189`.
- **Severity** Containment gap — **deliberate, reasoned, and narrow**.
- **Evidence** `939f8ada` recorded it as deferred-and-verified: *"`withCharLimit(0)`
  leaves the draft unbounded (deliberate)"*. The code states both halves of the
  trade: *"Its 65536 default is a paste-DoS guard, and this box has no paste path
  (a `PasteMsg` is dropped by `update()`), but it DOES receive arbitrarily long
  revived checkpoint rows through the Up arm — a cap would silently truncate one,
  which is a feature loss the previous hand-rolled string did not have."*
- **Step** Not "add a cap". Either (i) confirm the `PasteMsg`-dropped claim still
  holds with a test that fails if a paste path is ever added, and leave the limit
  at 0 with that test as the guard; or (ii) add a large cap that *refuses loudly*
  instead of truncating, so a revived checkpoint row cannot be silently lost.
  (i) is cheaper and matches the measured reality.
- **Blocked on** Nothing.

### C4 — tracker #80: `ProcessExecutor::createInlineWorkerScript()` is still a simulation

- **What** No workflow stage reaches a live model: the inline worker script the
  executor generates is the Phase-1 simulation.
- **Where** `sugar-crush/src/Agents/ProcessExecutor.php`
  (`createInlineWorkerScript()`).
- **Severity** Real bug — functionality, **and an audit trap**.
- **Evidence** Tracker **#80** (worklog `:1253`, `:1683`, `:2007`, `:3455`). The
  trap is stated at `:3456`: *"a WIRED tier over a SIMULATED executor reads as
  working."* That is why it belongs in a hardening ledger and not only in a
  feature queue — anything that measures workflow execution end-to-end will
  measure the simulation and call it green.
- **Step** Replace the generated script with a real invocation path, and — before
  that — add an assertion that fails while the simulation is in place, so the
  "reads as working" state cannot recur silently.
- **Blocked on** Phase 2's `WorkflowEngine` construction work; overlaps with
  tracker #79 (§E7).

### C5 — `AgentWorkerPool::waitForCompletion()` is still a blocking `usleep()` poll

- **What** The pool's completion wait blocks the event loop on a sleep-poll.
  `69d58867` fixed the *hang* (a worker dying without writing), not the
  *blocking*.
- **Where** `sugar-crush/src/Agents/AgentWorkerPool.php`
  (`waitForCompletion()`).
- **Severity** Real bug — **dormant today**.
- **Evidence** `crush_code.md`'s own "Partially complete, do not read the ✅ as
  finished" block: *"`AgentWorkerPool::waitForCompletion()` is **still a blocking
  `usleep()` poll** — `69d58867` fixed the hang, not the blocking, and it is safe
  today only because `Chat::executeAgents()` has zero production callers and
  `WorkflowEngine` is never constructed."* Both of those safety conditions are
  scheduled to stop being true in Phase 2.
- **Step** Convert to the fork-plus-socket pattern already proven in
  `EngineBackend::completeAsync()` (named as the fix for tracker #79 as well).
  **Sequence this before** whatever wires `Chat::executeAgents()` or constructs
  `WorkflowEngine` in production, or a freeze ships with it.
- **Blocked on** Nothing to fix; but it **blocks** Phase 2's engine wiring.

### C6 — `15a2e605` (foreign agent-preset wiring) shipped without adversarial review

- **What** The commit that made foreign, repository-authored agent presets reach
  the live roster for the first time was not reviewed.
- **Where** commit `15a2e605`; wiring at `src/Cli/Bootstrap.php:693`/`:793`.
- **Severity** Instrument blindness — over a surface that carries **a sub-agent's
  whole prompt** out of a cloned repository.
- **Evidence** Worklog queue `:5611`: *"Never reviewed: `c075adcf` (the
  permission fix) and `15a2e605`."* The commit did self-report two things a
  reviewer would want to re-drive: that the brief's literal wording ("wire it
  alongside the native registry construction") would have ranked a cloned repo's
  preset **above** the built-in `reviewer`, and that the plan's own cross-tool
  precedence claim was false — `discover()` cited `SkillLoader::loadAll()` as its
  authority, a method about **native** tiers, and the real rule resolves the pair
  the other way (**opencode wins for skills, Claude wins for agents**).
- **Step** Review it against §A5 and §A6 specifically: is `foreign < built-in <
  native` actually what `agentRoster()` produces for every collision shape
  (including numeric-stem filenames), and is the field-drop in `fromPreset()`
  the only thing standing between a cloned preset and `permissionMode`?
- **Blocked on** Same suite-run serialization constraint as §B1.

### C7 — `AgentDefinition::$defaultTools` is inert end to end: no launch path filters a sub-agent's tool set by it

- **What** Every preset declares a tool roster (`['Read', 'Grep', 'Glob']` for
  `architect`, `['Read', 'Edit', 'Bash']` for `coder`, and so on) and **nothing
  restricts a sub-agent to it**. A preset's roster is a label, not a grant. That
  is not a smaller version of the intended behaviour, it is the opposite of it in
  the one case that matters: `architect`'s narrower-looking roster does not make
  the sub-agent read-only.
- **Where** `sugar-crush/src/Agents/AgentDefinition.php` (the six
  `defaultTools:` arguments, `:49`/`:66`/`:83`/`:109`/`:126`/`:142`);
  `src/Agents/Agent.php:83` (`tools: $definition->defaultTools`);
  `src/Agents/AgentManager.php:383-389` (the `CompleteRequest` the presets
  actually launch through).
- **Severity** Real bug — **functionality today, permission surface once wired.**
  Dormant in the sense that no roster is being *violated* (there is no
  enforcement to violate); live in the sense that a prompt or a UI listing can
  describe a containment the runtime does not provide.
- **Evidence** Probed at the working tree, 2026-08-18:
  - `grep -rn 'defaultTools' src/` → nine hits: the constructor property, the six
    preset literals, `Agent.php:83`, and one comment. **No filter.**
  - `Agent::$tools` from there on is only **copied, serialised and displayed**:
    `src/Agents/Agent.php:151` (`'tools' => $this->tools` in `toArray()`),
    `:166`/`:182`/`:202` (`with*()` copies), `src/Agents/Teammate.php:86`, and
    `src/Commands/AgentsCommand.php:132-133`, which *prints* the roster to the
    user (`"  Tools:       " . implode(", ", $agent->tools)`). That display site
    is the one worth flagging: it shows the operator a roster that constrains
    nothing.
  - `AgentManager::executeSubAgent()` builds its `CompleteRequest` with `model`,
    `messages` and `systemPrompt` and **no `tools` argument at all**
    (`src/Agents/AgentManager.php:383-389`), so an `architect` sub-agent does not
    get read-only tools — it gets none.
  - The one path that does put a roster on an `Agent` from a caller's data,
    `WorkflowEngine` (`src/Workflows/WorkflowEngine.php:865`, `tools:
    $task->tools`), lands it in the same inert field; and `WorkflowEngine` is
    never constructed in production (§C5).
- **Step** Wire `Agent::$tools` into the request the sub-agent actually runs —
  i.e. resolve the roster to `Tool` instances and pass them on
  `CompleteRequest::$tools` in `executeSubAgent()`, with the `Bash(git:*)`-style
  scoped entries resolved through the permission surface rather than by string
  match. **Add the assertion first**: nothing currently fails while the field is
  inert, which is why this shipped with a preset prompt asserting a grant that
  did not exist. Sequence with §A6 (`fromPreset()`'s dropped fields) and §B1 —
  this is tool-capability filtering, so it lands in the permission-surface pass,
  not in a functionality round.
- **Blocked on** Nothing technical. Deliberately deferred out of Phase 5
  "Bundle A" (a functionality round) because it touches the permission surface.
  Until it lands, no preset prompt may assert a tool grant — the `architect`
  prompt was reworded to state its METHOD instead, and that rewording is the
  only thing standing between this seam and a false model-facing claim.

### C8 — `App::dispatchSkill()` and the simulated worker are a dormant PAIR: a correct payload with nothing to deliver it to

- **What** `dispatchSkill()` now builds a correct fork payload — the skill body
  reaches the worker through `Agent::systemPrompt()`, with an `EnvironmentBlock`
  captured at `$root` and at the fork's own model, so both of `ProcessExecutor`'s
  send sites agree about it. **No fork is oriented by it.** The method has no
  production caller, and the executor it would reach ignores both fields it
  sends.
- **Where** `sugar-crush/src/App/App.php` (`dispatchSkill()`, and the
  `withEnvironment()` capture inside it); `src/Agents/AgentWorkerPool.php`
  (`executeOne()` → `ProcessExecutor` fallback);
  `src/Agents/ProcessExecutor.php:455-470` (the two send sites) and `:515+`
  (`createInlineWorkerScript()`).
- **Severity** Dormant seam — **not a bug, and not a deletion candidate.** The
  payload half is a real fix that will be needed the moment either half of the
  pair goes live; recorded here so a hardening pass does not read the wiring as
  working.
- **Evidence** Probed at the working tree, 2026-08-18:
  - `grep -rn dispatchSkill src/ bin/` → the definition, one docblock
    cross-reference from `applySkillsToSystemPrompt()`, and the comment inside
    the method. **No invocation.** The only caller anywhere is
    `tests/App/AppSkillDispatchTest.php`.
  - `ProcessExecutor` sends both `agent.prompt` (`:459`,
    `$agent->agent->systemPrompt()`) and `request.systemPrompt` (`:466`) — which
    is why the payload had to be correct on the `Agent`, not only on the
    request.
  - `createInlineWorkerScript()` is still the Phase-1 simulation and consumes
    **neither**: it reads `$agentConfig['name']` and `$task` only, and its own
    comment says *"this is a simplified simulation that doesn't actually call an
    LLM"*. Same simulation as §C4, viewed from the skill-fork side.
- **Step** No code change owed for the payload. When §C4 replaces the simulation,
  assert that a dispatched skill's worker actually receives the environment
  block (cwd / git / platform / model lines), and wire a production caller for
  `context: fork` skills at the same time — the two halves have to land together
  or the seam stays dormant with a passing test.
- **Blocked on** §C4 (the simulated executor). Note for whoever picks this up:
  the comment that used to sit on `dispatchSkill()` described this outcome in the
  **present tense** while the paragraph below it criticised a *different*
  mechanism for never being reached. A mechanically correct fix can still ship a
  false sentence about what it accomplishes; the tense is now future and says
  why.

---

## D. Audit-instrument correctness (deliberately deferred — audit hygiene)

Lane B round 22 owes findings **F1–F9** against `f0d95785`. All nine are
**parked, not next** — the sequencing decision explicitly covers instruments:
*"Audit-instrument correctness defers too — mutation registers, censuses,
inventories are audit hygiene, not functionality."*

All nine live in one file, `sugar-crush/tests/VhsTapeContractTest.php` (5902
lines at HEAD), which has not been touched since `f0d95785` — so every citation
below still resolves. **F1 is the one to fix first**: an 8-assertion tautology is
worse than no check, because it reads as coverage.

**The sweep figures to trust** (independently re-derived by the round-22
reviewer, and lane B's set was confirmed correct): **38 leaves** (14/16/4/4),
swept one at a time → **21 killed / 17 survivors**, survivor set 1:1 with
`SWEEP_SURVIVORS` (`:235`), classes summing 4/6/7, **two** warning-only kills.
This **supersedes** two earlier figures: the register's own `38 / 22 killed /
one warning-only` (three wrong numbers) and the round-19/20 reviewer's
`37 / 16 / 21 plus a 17th` (does not reconcile).

**A trap to carry into any re-sweep:** both warning-only kills are red *purely*
via `failOnWarning="true"` changing the exit code. The printed banner still reads
`OK, but there were issues!` — anyone sweeping by reading output rather than `$?`
records them as **survivors**.

### D1 — F1 [HIGH]: `sweepLeafCensus()`'s reconciliation is identically zero

- **What** The reconciliation asserted to keep two instruments honest is
  `(G + T) − G − T`, identically 0 for any token stream. 8 assertions, **zero**
  detection power.
- **Where** `tests/VhsTapeContractTest.php:3469`
  (`self::sweepLeafCensus($tokens) …`), with `guardCensus()` and the
  byte-identical `$ternaries` recomputation immediately above (`:3464`).
- **Severity** Instrument blindness.
- **Evidence** Measured **GREEN** under all of: adding `T_FOREACH` to the
  anchors; removing `T_BOOLEAN_AND` from the operators; adding `T_SL`; widening
  `isTernaryCondition()`; **breaking `isTernaryCondition()` to `return false`
  always**; and adding a third census term that is 0 on these methods. Only a
  *non-zero* third term reds it. So the file's two claims — *"neither side can be
  widened or narrowed without the other's figure moving"* and *"this subtraction
  is what makes a third round of it red instead of quiet"* — are both false.
- **Step** Replace the subtraction with a comparison that can disagree: assert
  the census against an **independently derived** leaf count (a different parse
  strategy, or a pinned per-method expectation table), not against a recomputation
  of one of its own terms. What actually prevents drift today is the shared
  constant — see D4 — so pin that instead of pretending the arithmetic does it.
- **Blocked on** Must not start while a full-suite run is in flight: editing this
  file mid-run shifts `file(__FILE__)` ranges.

### D2 — F2 [HIGH]: four ternary shapes are invisible; a real ternary leaf lands green

- **What** `isTernaryCondition()` misses a `?` preceded by any token outside its
  whitelist, so four real ternary shapes are not counted as leaves.
- **Where** `tests/VhsTapeContractTest.php:3750` (`isTernaryCondition()`),
  consumed at `:3464`, `:3666`, `:3794`.
- **Severity** Instrument blindness — in the **under-count** direction, which the
  file itself calls *"the direction that matters"*.
- **Evidence** The four missed shapes: `Foo::class ? :` (prev `T_CLASS`),
  `match($z){…} ? :` (prev `}`), `"x$b" ? :` (prev bare `"`), and
  heredoc/nowdoc (prev `T_END_HEREDOC`). Whole-file mutation: inserting
  `$z = self::class ? 1 : 2;` or `$z = "x$depth" ? 1 : 2;` into `callArgument()`
  leaves **OK (115 / 625)** with the register total still **38**; the
  `$depth >= 0 ? 1 : 2` control **reds**. Same defect class as round 19's `??=`
  finding, one operator along, and these four are **not** among the escapes the
  file's own failure message enumerates.
- **Step** Widen the predicate to those four token kinds and add each as a fixture
  that reds the register. Note the coupling: widening it also changes D1's
  arithmetic — but D1's arithmetic cannot detect that, which is D1.
- **Blocked on** D1 first (fixing the tautology is what makes D2's fix
  verifiable).

### D3 — F3 [MEDIUM-HIGH]: one `fragment` row is satisfied by a different leaf's bytes

- **What** Nothing constrains a survivor row's `fragment` to be **unique within
  its method**, so a row can be satisfied by a *different* leaf's bytes.
- **Where** `tests/VhsTapeContractTest.php:235` (`SWEEP_SURVIVORS`), row 9;
  fragment matching at `:3402`
  (`self::methodSourceWithoutComments($survivor['method'])`).
- **Severity** Instrument blindness — *"verbatim the failure mode the `fragment`
  half was added to prevent, reintroduced in the commit that added it."*
- **Evidence** 16 of 17 survivor rows red on a meaning-preserving respelling.
  **Row 9 does not.** Its fragment `$depth === 1` occurs **twice** in
  `callArgument()` — the registered survivor (an early-continue) and a KILL
  (`elseif ($token === ',' && $depth === 1)`). Rewriting the survivor as
  `if (1 === $depth)` leaves the file green. Worse: **deleting that leaf
  outright** and rebaselining 16→15 / 38→37 / 21→20 also leaves the register
  test green.
- **Step** Assert each `fragment` occurs **exactly once** in its method's
  comment-stripped source, and fail the row when it does not — which forces
  whoever adds a row to pick a discriminating fragment.
- **Blocked on** Nothing beyond the file-lock constraint.

### D4 — F4 [MEDIUM]: 4 of the shared domain's 11 members can be deleted in silence

- **What** The shared token domain the two censuses draw from has 11 members;
  removing 4 of them is **silent**, so the claim *"widen or narrow the domain
  here and BOTH figures move together"* is false for those 4.
- **Where** `tests/VhsTapeContractTest.php` — the shared operator/anchor constant
  consumed by `guardCensus()` and `sweepLeafCensus()` (`:3789`).
- **Severity** Instrument blindness — **narrowing is the leaf-hiding direction**.
- **Evidence** Sharing is genuinely real (no second copy; removing an *occurring*
  member reds both censuses). But `T_COALESCE_EQUAL`, `T_LOGICAL_AND`,
  `T_LOGICAL_OR` and `T_LOGICAL_XOR` are all **SILENT** on removal — exactly the
  four the docblocks argue hardest for (*"every one of them was invisible to the
  regex these censuses replaced"*). Adding `T_FOREACH` is silent too. They are
  load-bearing only against a **future** leaf, and nothing pins the constant's
  content the way `KEYWORDS` is pinned.
- **Step** Pin the constant's **content** the way `KEYWORDS` is pinned — an
  explicit expected member list — so narrowing it reds regardless of whether any
  current leaf uses the removed member.
- **Blocked on** Nothing beyond the file-lock constraint. Fixing this is what
  actually delivers what D1 falsely claims, so bundle D1+D4.

### D5 — F5 [MEDIUM]: the twin defect, reintroduced by round 21's own new assertion

- **What** An `assertLessThanOrEqual`'s failure message cites, as the measurement
  proving it bites, a scenario in which it is **never reached** — an earlier check
  in the same loop fires first.
- **Where** `tests/VhsTapeContractTest.php:3431` (`self::assertLessThanOrEqual(`);
  the fragment check that pre-empts it is at `:3402`, in the same `foreach`.
- **Severity** Instrument blindness / claim defect.
- **Evidence** The message cites dropping `splitNamedArgument()`'s
  `\is_array($argument[0])` and correcting 4→3 / 38→37 / 21→20. Driven: the
  fragment check — added in the **same commit**, **earlier in the same
  `foreach`** — fires first, so the assertion is never reached in its own cited
  scenario. It *is* reachable, but only by a route the message does not describe:
  `methodSourceWithoutComments()` (`:3540`) strips comments and **keeps string
  literals**, so a nowdoc containing the fragment satisfies the row while the leaf
  is gone.
- **Step** Rewrite the message to describe the route that actually reaches the
  assertion (the nowdoc/string-literal route), or reorder the checks so the cited
  scenario is the reaching one. Either way the message must state the measurement
  that is true of it.
- **Blocked on** Interacts with D3 (same `foreach`, same ordering) — fix together.

### D6 — F6 [MEDIUM]: the register's own sweep instruction contradicts itself and is false

- **What** The sweep instruction a future sweeper is told to follow is wrong, and
  following it produces the conclusion that the register is stale.
- **Where** `tests/VhsTapeContractTest.php:4328-4329` — verified at HEAD, reading
  *"It now reports 'killed' for all thirty-eight without the flag, and a survivor
  is green either way."*
- **Severity** Instrument blindness — it actively misdirects the next sweeper.
- **Evidence** Measured across five survivors, each run twice: green **only** with
  `--exclude-group syntax-census`; **without** it, every one exits 1. So a sweeper
  who follows that sentence records **38 kills / 0 survivors** and concludes the
  register is stale.
- **Step** Correct the sentence to state the `--exclude-group syntax-census`
  requirement, and add the `failOnWarning` banner trap (see the group preamble) to
  the same instruction block — both are things a sweeper gets wrong by reading
  rather than measuring.
- **Blocked on** Nothing beyond the file-lock constraint. Cheapest of the nine.

### D7 — F7 [LOW-MEDIUM]: `isTernaryCondition()` false-positives on `#[Attr] ?type`

- **What** A nullable parameter type preceded by an attribute is mis-classified as
  a ternary, because `]` is not in the docblock's enumeration of what may precede
  a nullable `?` and `]` falls on the ternary side.
- **Where** `tests/VhsTapeContractTest.php:3750` (`isTernaryCondition()`).
- **Severity** Instrument blindness — false-RED direction (over-count).
- **Evidence** Adding `#[\SensitiveParameter] ?string $z = null` to
  `callArgument()` **reds** the register with a figure moved and no leaf added;
  the bare `?string $z = null` control is **silent**. Not hypothetical:
  `callArgument()`'s own docblock probes `#[\SensitiveParameter]`.
- **Step** Add `]` to the nullable-type side of the enumeration and to the
  docblock's list, with the attribute shape as a fixture. Bundle with D2 — same
  predicate, opposite direction.
- **Blocked on** D2 (same method).

### D8 — F8 [LOW]: a claim refuted as worded, two screens from the code

- **What** The file states a property of **itself** that is untrue of itself: that
  the killed total *"exists nowhere as a literal"*, when both `21` and `38` are
  literals in it.
- **Where** the claim is at `tests/VhsTapeContractTest.php:3324-3325`
  (**DRIFTED** by ~1 line from the review's `:3325`; at HEAD `:3325` reads
  *"…the KILLED total is the subtraction of the two and"*); the literal is at
  `:3449` (`21,`, inside the multi-line `assertSame`).
- **Severity** Cosmetic — *"Functionally fine — each is an expected value against
  a derived actual, and both are falsifiable."*
- **Step** Reword the claim to what is true: each literal is an *expected* value
  checked against a derived *actual*. One-line edit.
- **Blocked on** Nothing.

### D9 — F9 [LOW]: the comment-strip admission is honest but cites the wrong domain

- **What** The comment-strip justifies itself by citing `tokenize()`/`scanRegex()`,
  which are **outside its only call site's domain**, and does not mention that
  **strings are not stripped** — which is D5's live half.
- **Where** `tests/VhsTapeContractTest.php:3540`
  (`methodSourceWithoutComments()`).
- **Severity** Cosmetic — and the finding itself is a **negative result**: 0
  comment-token occurrences of all 17 fragments inside their own methods, so
  there is **no hidden gap**.
- **Step** Cite the actual call site's domain and state the strings-are-kept
  limitation, cross-referencing D5.
- **Blocked on** Bundle with D5.

### D10 — `ReadPathCensusTest`'s stated weakness: it cannot auto-fail a *missing* check

- **What** The read-path census forces a written verdict per read/execute sink,
  but a path that never had a containment check produces no compare for it to
  judge — so it would not have auto-failed the tenth path.
- **Where** `sugar-crush/tests/Support/ReadPathCensusTest.php` (755 lines).
- **Severity** Instrument blindness — **the residual of the fix for exactly this
  class of blindness**.
- **Evidence** `dad90b18` states it plainly: *"it would **not** have auto-failed
  the tenth path (the file held two project-tier compares), it would have forced
  someone to write a sentence next to that `require` where the only true one was
  'none'."* The positive half is real and worth keeping: it derives **76**
  read/execute sinks in `src/` (`require`/`include` and generated-code strings
  included), demands a per-occurrence verdict with four verdict words measured
  against the mechanism, *"caught a wrong verdict of its author's own on first
  run"*, and an ungated `file_get_contents` added anywhere in `src/` now reds it
  **by existing**.
- **Step** This is the strongest instrument in the package; do not weaken it. The
  residual is closed by process, not code: require a verdict sentence at review
  time for every new read sink. If it must be automated, the rule the chain
  derived is the one to encode — *"the grep must be for the DECISIONS the
  predicate is supposed to govern (every read of a repo-chosen path), not for the
  SPELLINGS of the predicate. A sweep instrumented on `str_starts_with` is
  structurally incapable of finding a MISSING check, which is the more dangerous
  half."*
- **Blocked on** Nothing.

### D11 — `KEY_HELP_COLS`'s docblock measurement is stale (58 vs a 59-column widest row)

- **What** The docblock says the widest declared keybinding row was *"measured at
  58 columns"*; the widest live row is now **59**.
- **Where** `sugar-crush/src/Renderer.php:555-556` (the docblock claim); the
  constant itself is `:561`, **value 64**.
- **Severity** Cosmetic — a stale claim, **not** a truncation risk. The constant
  is 64, so 59 still fits with 5 columns of headroom, and
  `KeyHelpTest::testScrollingThroughItShowsEveryLiveBinding()` asserts every
  description is painted in full, so a row growing past 64 fails rather than
  clipping.
- **Evidence** `939f8ada`'s deferral list: *"`KEY_HELP_COLS` says 58 where the
  widest live row is 59 (pre-existing)."* Verified at HEAD: the const reads 64
  while the prose reads 58, so the item is about the **prose**, and the
  worklog's shorthand ("`KEY_HELP_COLS` says 58") is the number-without-its-domain
  shape it warns about — the 58 is a *measurement of the widest row*, not the
  constant's value.
- **Step** Re-measure the widest declared row and update the prose to the measured
  figure **with what it was measured over**. Better: derive it — have `KeyHelpTest`
  compute the widest row and assert the headroom, so the prose cannot go stale.
- **Blocked on** Nothing.

### D12 — `ContainedPath`'s read-tier count was deleted rather than fixed

- **What** The head of `ContainedPath` used to carry a count of read tiers. It
  said five, then seven, then eight, was wrong within a round each time, and is
  now **deleted** — so there is no instrument on how many repository-chosen read
  paths exist.
- **Where** `sugar-crush/src/Support/ContainedPath.php` (class docblock).
- **Severity** Instrument blindness — a **deliberate** removal of a figure that
  could not be kept true by hand.
- **Evidence** `dad90b18`: *"The tier count at the head of `ContainedPath` is
  DELETED, not re-incremented. It said five, then seven, then eight, wrong within
  a round each time, and could not move at all while the tenth and eleventh paths
  were open."*
- **Step** Do not restore a hand-maintained number. If a count is wanted, derive
  it from `ReadPathCensusTest`'s own sink enumeration (§D10) so it cannot drift,
  and cite the census as its domain.
- **Blocked on** Nothing.

---

## E. Deferred UX / correctness follow-ups (not security)

### E1 — issue #88: the README's whole-suite figure

- **What** `README.md` advertises a test/assertion count that is many commits
  stale.
- **Where** `sugar-crush/README.md:396` — **"6,424 tests / 51,767 assertions, 0
  failures, 1 skipped"**. (Line may drift: another agent is editing this file.)
- **Severity** Cosmetic.
- **Evidence** Deferred **five times** by name in the worklog queue, each time
  with a fresh true measurement, because every landing round moves it. The
  measurement history, latest first — **use the latest, and re-measure before
  writing**: **6806 / 70298 / 1 skipped** at `939f8ada` (verified by the
  supervisor from a clean run, not taken from an agent's report); 6764 / 69788 at
  `15a2e605`; 6742 / 69699 at `dad90b18`; 6678 / 69306 at `c075adcf`; 6678 /
  69298 at `f0d95785`; 6603 / 68722 at `f8b37f63`; 6525 / 68522 at `1fa7af45`;
  6465 / 68244 at `cbdb5e2e` (confirmed twice). An earlier reported
  6386 / 45478 was explicitly disowned: *"That does not describe the
  repository."*
- **Step** Land it **last**, in a standalone commit, when nothing else is in
  flight — that is why it has slipped five times. Lane E round 4 already deleted
  the *freshness promise* next to the figure, so the sentence is honest about
  being point-in-time; keep it that way and put the run command beside the
  number so the figure carries its provenance.
- **Blocked on** Everything else landing. It is the natural final commit.

### E2 — `ESC b` / `ESC f` type a stray letter instead of moving by word

- **What** readline's Alt+B / Alt+F word motion, when a terminal sends it as
  `ESC b`/`ESC f`, decodes as `KeyMsg(Char "b", alt)` and is **typed** rather
  than moving the cursor.
- **Where** `sugar-crush/src/Chat.php:1145-1152` (the reasoning) and `:1153-1156`
  (the arms that do handle `Left`/`Right` with alt/ctrl).
- **Severity** Real bug — **identical at HEAD before the cursor work**, so a
  regression it is not.
- **Evidence** `939f8ada` recorded it as deferred-and-verified: *"`ESC b`/`ESC f`
  type a stray letter (identical at HEAD)"*. The code states the same and names
  the sibling case that is also unbound: *"`ESC ESC[D`, the ESC-prefixed Alt+Left
  some terminals emit, decodes as TWO messages (Escape, then a bare Left), so the
  Escape is consumed by the Escape arm above and the Left moves one character
  rather than one word."*
- **Step** A keymap addition: route `KeyMsg(Char 'b'|'f', alt)` to
  `wordLeftOffset()`/`wordRightOffset()` (`src/Chat.php:6334`, `:6341`), which
  already exist. Deliberately excluded from `939f8ada` as *"a keymap addition
  rather than part of moving the draft into the widget"*.
- **Blocked on** Nothing.

### E3 — the Ctrl+P palette has its own hand-rolled append-only query buffer

- **What** After the chat draft got a real cursor, the command palette's query is
  still a hand-rolled append/`dropLast` string — no cursor movement in it.
- **Where** `sugar-crush/src/Chat.php:6701`
  (`withPaletteQuery(self::dropLast($this->palette->query))`); the helper survives
  at `:6912` **specifically because** of this second buffer.
- **Severity** Real bug — cosmetic in effect (a short query), structural in shape.
- **Evidence** `939f8ada`: *"the palette's own query buffer"*, deferred. And the
  reason `dropLast()` survived the refactor at all: *"`dropLast()` because the
  Ctrl+P palette query is a **second** hand-rolled append-only buffer"* — which
  is also why the plan's 30–50-line estimate came out at **11** non-comment lines
  removed, 8 of them match-arm bodies.
- **Step** Give the palette the same `TextArea`/`TextInput` treatment the draft
  got. Note `freshInput()`'s docblock records that the palette *"has no
  fill-on-select at all — its selections run actions, and its own query buffer is
  a separate string this widget never sees"*, so this is additive and does not
  touch the draft's ownership of completion or `↑` recall.
- **Blocked on** Nothing.

### E4 — the draft cursor position is not persisted in checkpoints

- **What** A restored checkpoint reseeds the draft with the cursor at the end
  rather than where it was.
- **Where** `sugar-crush/src/Chat.php` — the `input`/`inputBuf` `mutate()` keys
  (reasoning at `:6265-6273`, `inputCursorOffset()` at `:6275`); checkpoint
  restore is one of the four whole-draft writers.
- **Severity** Cosmetic.
- **Evidence** `939f8ada`: *"cursor not persisted in checkpoints"*, deferred and
  verified. The mechanism is by design: *"`mutate()` carries an `input` key and
  **drops it when a change names `inputBuf` alone** — that key means 'replace the
  whole draft' (submit clear, `↑` recall, slash completion, checkpoint restore)
  and reseeds with the cursor at the end."* Deleting that guard reddens
  `ChatTest` (11 F + 1 E), so it is load-bearing.
- **Step** Persist the flat character offset alongside the draft in the checkpoint
  state map (`inputCursorOffset()` already produces exactly that shape, and its
  docblock notes the flat form is *"also the shape the checkpoint state map …
  see"*), and restore it via `withInputCursor()` (`:6291`).
- **Blocked on** Nothing.

### E5 — combining marks splice onto the block cursor glyph

- **What** The block cursor `█` is spliced into the draft at a character offset,
  so a combining mark immediately after that offset composes onto the **cursor
  glyph** rather than onto its base character.
- **Where** `sugar-crush/src/Renderer.php:2929` (`renderInput()`); the splice is
  at `:2956-2958` (`$body = "> " . mb_substr(...) . $cursor . mb_substr(...)`).
- **Severity** Cosmetic.
- **Evidence** `939f8ada`: *"combining marks splice onto the block glyph"*,
  deferred and verified. Bounded by the byte-identity result from the same
  round: with the cursor at the end, painted output is byte-identical to the
  pre-cursor form across **57 `md5(view())` comparisons over 19 drafts × 3 sizes**
  — CJK, emoji, ZWJ, combining marks, RTL, embedded SGR, a lone C1, C0 bytes, and
  the 38–41 column boundary — **zero differences**. So this only manifests
  mid-draft. A related residual is already stated in code at `:2942-2947`: on a
  draft whose cursor sits inside an escape sequence (reachable only via a revived
  checkpoint row, never by typing) the two sanitizer passes can disagree and the
  glyph lands a column or two off.
- **Step** Compute the cursor position on **grapheme** clusters rather than code
  points, and paint the cursor as a reverse-video attribute on the cluster at the
  offset instead of splicing a glyph between clusters.
- **Blocked on** Nothing.

### E6 — the input box over-widens past the terminal at 40 columns

- **What** `renderInput()` sets **no width** at all — the bordered box grows with
  its content, so a long single-line draft emits rows wider than the terminal.
- **Where** `sugar-crush/src/Renderer.php:2959-2964` (the `Style::new()->border()
  ->padding(0,1)->render($body)` chain, with no `->width(...)`).
- **Severity** Real bug — **pre-existing and byte-identical to HEAD**; the
  render-invariant class that caused PR #1403.
- **Evidence** `939f8ada`: *"the input box over-widens past 40 columns
  (byte-identical to HEAD)"*, deferred and verified. This is the same invariant
  the project already learned the hard way — *"no over-wide lines (the diff
  renderer is 1 line/row)"* — and the same class as the tab-expansion break
  `4e10360b` fixed in the diff gutter (`Width::string("\t") === 0` while
  `Style::render()` paints a tab as 4 spaces, so a diff at `cols: 40` emitted
  48-cell rows).
- **Step** Give the box an explicit content width derived from `$chat->cols()`
  minus its own chrome (the pattern already used for the keybinding reference at
  `src/Renderer.php:701`), and wrap or scroll the draft within it. `renderInput()`
  already receives `$chat`, so the width is available.
- **Blocked on** Nothing. Highest user-visible value in group E.

### E7 — tracker #79: `/workflow run` freezes the TUI

- **What** A real `/workflow run` blocks the event loop for the duration of the
  run.
- **Where** `sugar-crush/src/Chat.php` (documented at the `/workflow` dispatch;
  the fix is named in code).
- **Severity** Real bug — availability, on a user-invoked command.
- **Evidence** Tracker **#79** (worklog `:3457`): *"Now DOCUMENTED in `Chat.php`
  with the fix named (`EngineBackend::completeAsync()`'s fork-plus-socket
  pattern); the fix itself is its own change-set."*
- **Step** Apply the fork-plus-socket pattern from
  `EngineBackend::completeAsync()`. Bundle with §C5, which needs the same
  treatment and gates the same subsystem.
- **Blocked on** Nothing to start; overlaps Phase 2's engine work.

### E8 — tracker #85 (lane-D sense): "Stages completed" counts a synthetic pre-flight stage

- **What** The workflow result line prints `count($result->stageResults)`, and the
  pre-flight builds **one synthetic `StageResult`** whose own comment says
  "Nothing ran" — so a run that dispatched nothing reports
  "Stages completed: 1".
- **Where** **DRIFTED** — the review cited `src/Chat.php:3823`; at HEAD the
  print is at **two** sites, `sugar-crush/src/Chat.php:4868` and `:4943`. The
  pre-flight is `src/Workflows/WorkflowEngine.php:506-533` per the review (also
  likely drifted; locate by the "Nothing ran" comment).
- **Severity** Real bug — reporting only.
- **Evidence** Measured: stage 2 declaring `Bash` under `dont-ask` →
  *"Workflow 'danger' failed … Stages completed: 1 … (0.00s elapsed)"* while
  stage 1 never ran — proven by the round's **own**
  `WorkflowFailureReportingTest.php:47-48` (`expects($this->never())`), which
  asserts nothing about that line. Same shape after resume: "Stages completed: 0"
  for a workflow that had completed one. Deferred because `Chat.php` was owned by
  a concurrent lane.
- **Step** Count only stages that actually dispatched (exclude the synthetic
  pre-flight `StageResult`), and fix **both** print sites — the count has since
  been duplicated. Add the assertion the failure-reporting test lacks.
- **Blocked on** Nothing.

### E9 — tracker #86: the `README.md:272` half of lane D's F-3

- **What** The second half of lane D round 5's F-3 was a `README.md` claim,
  deferred because `README.md` was owned by a concurrent lane.
- **Where** `sugar-crush/README.md:272` as cited — **UNVERIFIED at HEAD**; the
  README has changed substantially since, and the ledger compiler could not
  match a claim at that line to the finding.
- **Severity** Unknown — recorded so it is not lost.
- **Evidence** Worklog `:3961-3963`: *"Its F-5 (`Chat.php:3823`'s 'Stages
  completed') and the `README.md:272` half of its F-3 are DEFERRED to trackers
  **#85** and **#86**, both recorded with their measurements so they can be
  picked up cold."* Lane D round 5's F-3 as written up (`:3817`) is *"the wiring
  test cannot catch a recurrence of the bug it was written for"* — which does not
  obviously have a README half, so the mapping of #86 to a specific claim is
  itself uncertain.
- **Step** Re-read the "Lane D round-5 review" section of the worklog (`:3767`
  onward) in full and re-derive what the README half was, before spending any
  time on the line number. See *Unresolved references*.
- **Blocked on** Reconstructing the finding.

### E10 — tracker #78: `Doctor::name()` is lowercase where nine sibling tools are TitleCase

- **What** The tool-schema name is `'doctor'` while the other built-in tools are
  TitleCase.
- **Where** `sugar-crush/src/Tools/BuiltIn/Doctor.php:44-47`. Verified at HEAD.
- **Severity** Cosmetic.
- **Evidence** Tracker **#78** (worklog `:3450`): *"Deliberately not renamed by a
  review round: the model already knows the name. Belongs to whoever owns the
  tool schema."*
- **Step** Rename with the schema, or record the inconsistency in the tool-naming
  test as intentional. Note the real risk is the *rename*, not the status quo —
  the model has been trained on prompts containing `doctor`.
- **Blocked on** A tool-schema decision.

### E11 — tracker #82: an orphaned docblock in `MenuBar`

- **What** Two consecutive docblocks with no declaration between them, so the
  first documents nothing.
- **Where** `sugar-crush/src/Tui/Components/MenuBar.php` — cited as `:362-368`;
  **confirmed present at HEAD** around `:362-371` (the `handleKey()`-persistence
  docblock is immediately followed by the dropdown-panel docblock).
- **Severity** Cosmetic.
- **Evidence** Tracker **#82** (worklog `:1280`, `:1699`, `:3462`).
- **Step** Attach the orphan to the declaration it describes, or fold its content
  into the surviving docblock. Two-line edit.
- **Blocked on** Nothing.

### E12 — trackers #83/#85 (palette sense): Ctrl+P opens a palette the shell never paints

- **What** `Ctrl+P` (and `Ctrl+K`, which translates to it) opens a hosted-`Chat`
  palette that the shell's full-pane agent dashboard never paints and never
  drives — visible-but-undrivable in the skill-picker and F10-menu states.
- **Where** `sugar-crush/src/Tui/Renderer.php` (`renderAgentDashboard()`), which
  *"never renders the hosted chat at all — verified"*.
- **Severity** Real bug — a live keychord with no visible result.
- **Evidence** Worklog `:2500` and `:3463` (filed under **two different tracker
  numbers**, see *Contradictions*). The escape route was measured and rejected:
  *"Yielding the chord makes it **worse** (`ProviderSelectCmd` → `/model`)."* And
  a cheaper mitigation was considered and rejected **in-lane**: a shell-side cue
  (`hostedNotice`/dashboard footer) *"change[s] the chrome line count, hence
  `paneRows` and dashboard geometry — a layout change belonging to [this item],
  not to a review-fix round."*
- **Step** Either have the shell composite the hosted palette, or have the palette
  stand down while the dashboard owns the pane. This is a layout change and
  should be sized as one.
- **Blocked on** Phase 4's `/model` work touches the adjacent chord — sequence
  after it.

### E13 — tracker #61: the unsearchable-directory tests assert a throw root does not produce

- **What** Two tests assert an exception that a **root** user never triggers,
  because root can traverse a `chmod 0` directory. Fine on a uid-1000 dev host;
  CI containers often run as root.
- **Where** `sugar-crush/tests/Cli/BootstrapUserConfigTest.php` and
  `tests/Cli/BootstrapPermissionGateTest.php` are the candidates at HEAD
  (both contain unsearchable-directory fixtures); the review did not name the
  file. **Location approximate.**
- **Severity** Instrument blindness — a CI-only false green/red.
- **Evidence** Worklog `:566-572`: *"#61 P1.2's unsearchable-dir tests assert a
  throw **root does not produce** — fine locally (uid 1000) but CI containers
  often run as root."*
- **Step** Gate those assertions on `posix_geteuid() !== 0` with an explicit
  `markTestSkipped` naming the reason, so "exempt" is distinguishable from
  "forgotten" — the pattern `tests/Providers/ProviderConnectTimeoutTest.php:56`
  already uses.
- **Blocked on** Nothing.

### E14 — `src/ToolRegistry.php` declares its own `SugarCraft\Crush\Tool`

- **What** A second class literally named `Tool` in the root namespace, one `use`
  statement away from colliding with the tool **interface** every built-in tool
  implements.
- **Where** `sugar-crush/src/ToolRegistry.php:5` (`namespace SugarCraft\Crush;`)
  and `:33` (`final class Tool`). Confirmed at HEAD.
- **Severity** Real bug waiting to happen — a latent collision, not a live
  defect.
- **Evidence** Worklog `:5192`: *"Reported, unowned: `src/ToolRegistry.php`
  declares its own `SugarCraft\Crush\Tool`, one `use` away from colliding with
  the tool interface."*
- **Step** **Do not delete it** — `crush_code.md`'s consolidation-review list
  flags root-namespace `ToolRegistry` as superseded-with-zero-production-callers,
  and the standing rule is that removal needs explicit human sign-off. The safe
  move now is to *move* it into a distinct namespace (or rename the class) so the
  collision cannot be created accidentally, and to leave the consolidation
  decision to the human review it is already queued for.
- **Blocked on** Nothing for the rename; the consolidation decision is separate.

### E15 — tracker #81: port the vhs lexical grammar into `candy-vcr`

- **What** `candy-vcr/src/Tape/Lexer.php` has no JSON token and no regex token,
  so it does not implement upstream vhs's real grammar.
- **Where** `candy-vcr/src/Tape/Lexer.php` (a **sibling lib**, not `sugar-crush`).
- **Severity** Real bug — in the tape renderer, not in the agent.
- **Evidence** Tracker **#81** (worklog `:1261`, `:1692`, `:2010`, `:3458`).
  Lane B round 15 established four portable facts to port with it: *"tab is
  whitespace, there are THREE time units not two, the JSON closer is synthesized,
  and both the keyword gate and the suffix gate are on token KIND not text."*
  Three independent vhs oracles were built and all three agree (worklog
  `:4535-4540`).
- **Step** Port the grammar into `candy-vcr`, carrying the named constants with
  the Go predicate names plus the grammar write-up. Out of `sugar-crush`'s scope
  — record it here only so it is not lost with the worklog.
- **Blocked on** Nothing, but it belongs to `candy-vcr`, not this plan.

### E16 — `HookManager::applyPreHooks()` and the `ToolStarted`/`ToolFinished` payloads

- **What** Flagged by an early round as *"the same stale-`toolArgs` family as
  `HookDispatcher` was"*, with `ToolStarted`/`ToolFinished` still carrying the
  **pre-rewrite** `ToolCall` on both pipelines.
- **Where** `sugar-crush/src/Hooks/HookManager.php:199` (`applyPreHooks()`); the
  re-scan reasoning is at `:180`.
- **Severity** **UNVERIFIED at HEAD.** `df0a563b` (*"make the permission gate a
  real hook, and stop rewrites reaching the tool unjudged"*) plausibly closed it,
  and `:180` now documents a re-scan of rewritten arguments — but no record
  states the flagged item as closed.
- **Evidence** Worklog `:574-577`: *"Also flagged by round 3, not queued yet:
  `HookManager::applyPreHooks()` is the same stale-`toolArgs` family as
  `HookDispatcher` was, and `ToolStarted`/`ToolFinished` still carry the
  pre-rewrite `ToolCall` on both pipelines."* It was never assigned a tracker.
- **Step** Re-establish it before spending fix time: drive a rewriting pre-hook
  and assert what `ToolStarted`/`ToolFinished` observers actually receive. If the
  events still carry the pre-rewrite call, decide whether that is a defect —
  observers may legitimately want the original — and **document the choice**
  either way, since the ambiguity is what made this sit unqueued for the whole
  chain.
- **Blocked on** The measurement.

---

### E17 — the 95% blocking tier refuses a turn on an ESTIMATE, not a token count

- **What** `Chat::submit()` now refuses to dispatch a turn when
  `ContextCompactor::shouldCompactForeground()` reports the history is still at
  or over 95% of the context window after automatic compaction (crush_code.md
  Phase 5 item 5). The window on the right of that comparison is the provider's
  real, tokenizer-counted figure; the count on the left is
  `Chat::estimateTokenCount()`'s chars/4 + 10-per-message proxy. The two units
  do not match, so the refusal can be wrong in both directions — a turn that
  would have fit is refused, or one that will not fit is sent.
- **Where** `sugar-crush/src/Chat.php` — the tier block in `submit()` and
  `foregroundBlockedResponse()`; the estimate is `estimateTokenCount()` and
  `ContextCompactor::countTokens()`; the window comes from
  `Context/ContextWindow::ofBackend()`.
- **Severity** Correctness / UX, not security. The direction of the error is
  benign for overruns (code and CJK tokenize worse than chars/4, so the estimate
  runs LOW and the tier fires late) and user-visible for false refusals.
- **Evidence** Measured while wiring the tier: a 2,400-message history of
  `"question "x20` reads 122,400 estimated tokens; no tokenizer was consulted at
  any point. A real figure is partly available and unused —
  `Providers/CompleteResponse::$tokensUsed` is populated on the non-streaming
  path by six of the seven providers (OpenAI, Custom, Sglang, ClaudeCode, Vertex,
  Bedrock; Echo has nothing to report). **Corrected 2026-08-19:** an earlier
  draft of this entry said it "is 0 on every streaming path by those providers'
  own docblocks", which is false for two of the six, and the correction changes
  what this item is blocked on. Read on the source:

  - `OpenAIProvider` (`:240`), `CustomProvider`, `SglangProvider` and
    `ClaudeCodeProvider` do yield `tokensUsed: 0` on every streaming chunk — four
    of six, as claimed.
  - `BedrockProvider::completeStream()`'s docblock (`:194-197`) says the opposite
    on purpose: *"Only the final `metadata` event carries usage, so every chunk
    before it reports tokensUsed/costUsd of 0 and the last one reports the turn
    total with empty content."* A turn TOTAL, on the stream.
  - `VertexProvider::parseAnthropicChunk()` (`:876-897`) is the one that matters
    most: it emits `tokensUsed: $inputTokens` on `message_start` and
    `tokensUsed: $outputTokens` on `message_delta` — i.e. the PROMPT half, split
    out, on the stream. That is precisely the figure the Step below says a single
    total cannot substitute for, and its supposed absence is what the Blocked-on
    cited.

  Nothing between the provider and `Chat` carries any of it back:
  `Backend::complete()` returns a `Message`, which has no token field, so the
  estimate is still the only number the tier can see. The rest of this entry is
  unchanged and holds.
- **Step** Either (a) carry a provider-reported prompt-token count back to
  `Chat` — which needs `$tokensUsed` split into prompt/completion and a seam on
  the `Backend` return path, since a single total cannot calibrate a
  history-size estimate — and use the last real measurement to calibrate the
  estimator, or (b) keep the proxy and widen the refusal's margin explicitly,
  documenting the assumed worst-case chars-per-token. Do NOT simply raise the
  95% threshold: that hides the unit mismatch instead of naming it.
- **Blocked on** A decision on whether `CompleteResponse::$tokensUsed` is split
  into prompt/completion and surfaced through `Backend` (an API change every
  provider and every backend has to fill in). Note that the DECISION is what is
  blocking, not the data: at least one provider (Vertex, anthropic path) already
  reports the prompt half on its own, so the seam has a first consumer whether or
  not the other five are ever taught to split.

### E18 — one exchange bigger than the tier is a permanent refusal; a big HISTORY is not

**Rewritten 2026-08-19.** The original entry said "a session of ten enormous
exchanges is refused until `/clear`" and that "the refusal stands no matter how
many times the turn is retried". Both are false, measured on this entry's own
fixture. What is left after the correction is a narrower but real dead end.

- **What** `ContextCompactor::compact()` preserves the most recent
  `recentPreserveCount` (10) exchanges in FULL, so there is no "compact harder"
  path — no truncation within a preserved exchange, no per-message cap. The
  consequence depends on WHERE the bulk sits:

  - **A large history is self-healing.** Each refusal appends a small
    notice/user/refusal group, and that group pushes one of the enormous
    exchanges out of the ten-pair preserve window on the next attempt. So the
    history really does shrink per attempt and the turn eventually goes out.
  - **A single exchange over the tier is the actual dead end.** It stays inside
    the preserve window forever no matter how many small groups pile up behind
    it, and every refusal makes the total slightly WORSE. `/clear` is the only
    way out.

- **Where** `sugar-crush/src/Context/ContextCompactor.php:197` (the
  `count($pairs) <= $preserveCount` early return) and the refusal in
  `sugar-crush/src/Chat.php`'s `submit()` tier block.
- **Severity** UX dead end for the single-exchange case, not security. Reachable
  in normal use: one tool turn that reads a file larger than 95% of the window.
  The many-exchanges case is a slow start, not a dead end.
- **Evidence** Both driven at HEAD against the default `EchoBackend`, whose
  window resolves to `ContextWindow::FALLBACK_TOKENS` (100,000), all figures in
  ESTIMATED tokens (`Chat::estimateTokenCount()`'s chars/4 + 10 per message):

  - This entry's own fixture — 13 exchanges of ~50,000 chars,
    `ChatTest::oversizedHistory()`, 26 messages, 325,286 estimated tokens —
    refuses turns 1 through 4 and **dispatches turn 5**: 325,286 → 250,531 →
    200,768 → 151,005 → 101,241, i.e. ~49,760 shed per refusal, one whole
    exchange. Four refused turns, not an indefinite block.
  - A single 800,000-char exchange (2 messages, 200,020 estimated tokens) is
    refused on every one of five consecutive attempts and the estimate RISES each
    time — 200,148 → 200,276 → 200,404 → 200,532 → 200,660 — because the refusal
    group is the only thing being added and nothing can be evicted.
  - Cost of the many-exchanges case is four refused turns plus four rewrites of
    older history. Those rewrites are reported in the transcript as of the
    reviewer's B3 fix (`Chat::foregroundBlockedResponse()` now carries the same
    `contextCompactedMessage()` notice the dispatching path emits); before that
    fix they were silent, which is what made the original "until `/clear`"
    framing look plausible.
  - The refusal message no longer names `/fork`: measured,
    `Chat::handleForkCommand()` leaves this history in place, so it frees nothing
    here. It names `/clear` (frees everything at once) and `/compact` (sheds the
    oldest preserved exchange by the same mechanism a retry does), and
    `ContextWindowWiringTest::testEveryEscapeTheBlockingRefusalNamesActuallyGetsOutOfIt()`
    drives every command the message names and asserts exactly which ones unblock
    the next turn.

- **Step** Give the compactor a last-resort pass that truncates WITHIN a
  preserved exchange (head+tail with an elision marker) rather than dropping it,
  and only when the ordinary pass has already failed. That is aimed squarely at
  the single-exchange case, which is the one that cannot recover on its own. Keep
  the 95% refusal as the backstop for when even that is not enough.
- **Blocked on** Nothing. Deferred only because functionality-before-hardening
  puts a working refusal ahead of a better one.

### E19 — `BedrockProvider` flattens every `Role::System` turn to `user`, producing consecutive same-role turns

- **What** Chat's history legitimately contains mid-conversation `Role::System`
  messages — the 70% context reminder, the 85% compaction notice, and every
  `Message::toolRunning()` placeholder. `EngineBackend::toTypedMessages()`
  (`:1219-1231`) turns each into a `Messages\SystemMessage`, and
  `BedrockProvider::formatMessages()` (`:294`) maps `SystemMessage` to role
  `'user'` with the comment *"System wrapped as user"*. Two of those in a row —
  or a system notice adjacent to the real user turn — is two consecutive `user`
  entries in a Converse `messages` array, which the Anthropic-on-Bedrock models
  reject.
- **Where** `sugar-crush/src/Providers/BedrockProvider.php:294` and
  `sugar-crush/src/Backend/EngineBackend.php:1219`.
- **Severity** Provider-specific request rejection, not security. Bedrock only.
- **Evidence** Settled by reading the two other shapes rather than by driving a
  live provider, and the conclusion is that this is **pre-existing and unrelated
  to the position** of any one notice:
  - `VertexProvider::anthropicSystem()` (`:464-482`) hoists EVERY
    `SystemMessage` out of `messages` into the top-level `system` field, with a
    docblock saying *"a `system` role inside `messages` is a 400"*. Position in
    the transcript is irrelevant there; the notices never reach `messages`.
  - `OpenAIProvider` (`:163`) emits `['role' => 'system', …]` in place, which the
    Chat Completions API accepts anywhere in the list.
  - Bedrock is the only one that collapses the role, and it produces the same
    adjacency for the pre-existing 70% reminder (which lands immediately AFTER
    the user turn) as for the 85% compaction notice (which lands immediately
    BEFORE it) and for tool-running placeholders. So the ordering question raised
    against the compaction notice specifically has no bite: it is one more
    instance of a shape Bedrock already produced.
- **Step** Give Bedrock the Vertex treatment — hoist `SystemMessage` content into
  the Converse request's own `system` field instead of forging a `user` turn —
  and, independently, decide whether `formatMessages()` should coalesce
  consecutive same-role entries as a general safety net for the `default => 'user'`
  arm beside it.
- **Blocked on** Nothing but a Bedrock credential to verify against. Not urgent:
  it cannot be a regression from the context-tier work, since the reminder tier
  already produced it.
- **Note 2026-08-19 — MORE REACHABLE, same bug.** The 85% tier's parked route
  (Phase 5 item 6) emits a `Role::System` park notice BEFORE the prompt and a
  `Role::System` tier report AFTER it, so it produces the adjacency **with no 70%
  reminder present at all** — previously the shape needed either the reminder or a
  tool-running placeholder. Measured on the dispatched wire, tail roles
  `system user system system`, which Bedrock's mapping renders as **four
  consecutive `user` entries** (`OpenAI: user assistant system user system`;
  `Vertex: system hoisted out of messages, ends on user`). The mapping is
  CONFIRMED; the Converse 400 remains SUSPECTED — nobody has called Bedrock.
  Moving the report ahead of the prompt was considered and rejected: measured, it
  gives `system system user system`, the same four consecutive `user` entries after
  the mapping, so no message position available to `Chat` reduces the number. Only
  this entry's own Step does. The measurement is recorded in
  `compactionChanges()`'s docblock so the next person to reach for "just move the
  notice" finds it first.

### E20 — the spend cap can be overshot by one whole agentic turn, and the turn cannot be aborted

- **What** `Chat::spendCapRefusal()` (Phase 5 item 7) refuses to START a turn once
  the reported spend has reached `$SUGARCRUSH_MAX_COST` / `/budget <n>`. It cannot
  abort a turn already in flight, so the turn that crosses the cap runs to
  completion and the session's final total overshoots by that turn's whole cost.
  A turn is up to `maxSteps` (default 8) provider calls, so the overshoot is not
  bounded by one call's price.
- **Where** `sugar-crush/src/Chat.php` — `spendCapRefusal()`, whose docblock states
  this — and `sugar-crush/src/Backend/EngineBackend.php:441` (the
  `for ($step = 0; $step < $this->maxSteps; $step++)` loop) plus
  `completeAsync()`'s fork.
- **Severity** Cost exposure on unattended use (workflows, `/bg`, background
  sessions). Not security.
- **Evidence** The per-step figures are produced inside the pcntl-forked child and
  only reach the parent in the final result frame
  (`runCompleteInChild()`'s `'usage'` key), so at the moment a step would need to
  be refused there is nothing in the parent's process holding the running total.
  The parent's `CancellationToken` machinery CAN kill the child mid-turn — that is
  how double-Escape works — but nothing computes a cap breach to trigger it with.
- **Step** Carry the cap into `EngineBackend` and check it between steps of the
  agentic loop, reporting the breach through the same frame channel the tool
  events use so the transcript can say which step was refused. Alternatively feed
  a running total forward over the socket and have the parent trip the existing
  cancellation path. The message must distinguish "refused to start" from "aborted
  mid-turn" — they are different guarantees, and today only the first exists.
- **Blocked on** Nothing. Deliberately out of scope for the bundle that added the
  cap: the pre-flight refusal is the half that needs no new IPC.
- **Amended 2026-08-19 (B2 fix round).** The original row described only the
  submitted-turn path, and there were two other provider calls the cap did not see
  at all. **Both are now closed**, so this amendment records what was fixed rather
  than adding work:
  - **`/compact`'s summarization call is now gated AND counted.** It is dispatched
    past `spendCapRefusal()` (the cap is deliberately checked after
    `dispatchCommand()` so `/budget` still works while capped, and `/compact`
    dispatches there too). Probed on the old code: spend $5.00 against a $1.00
    cap, `/compact` returned a Cmd and invoking it made **1** provider call — a
    full-conversation completion on the provider's DEFAULT model — and the
    resulting `Message`'s `usage` was discarded by the `HistoryCompactedMsg`
    handler, so the cap and the readout never saw it. Now
    `scheduleModelCompaction()` asks the shared `spendCapReached()` predicate and
    falls back to the local heuristic with a notice naming `/budget`; the
    compaction itself is never refused, so the "compaction is what frees context"
    objection does not apply.
  - **The session titler's call is now counted, and deliberately NOT gated.**
    Measured: `scheduleTitleGeneration()` is only ever called from `submit()`'s
    turn-dispatch tail, which sits AFTER `spendCapRefusal()`, so a session already
    at its cap has its turn refused and never reaches it. A gate there could only
    refuse a call the turn-level gate had already let through. Its usage now rides
    out on `SessionTitledMsg` — including on the empty-title and failed-rename
    exits, which used to dispatch nothing and so made the call free in the readout.
  - **Still open, and this row's actual remaining work:** the mid-turn abort. The
    overshoot described above is unchanged.
- **Amended 2026-08-19 (E21 fix round) — a THIRD gate, closing a bypass E21 itself
  introduced.** The 85% tier's parked route is the one place in the app that starts
  a turn without passing `spendCapRefusal()`: that check ran in an earlier
  `update()`, and the summarization dispatched between then and the dispatch is
  itself billed. Probed on the unfixed bundle: spend $0.50 against a $1.00 cap, the
  summary reported $0.60, the session sat at $1.10 with the cap reached, and the
  parked turn was **dispatched anyway** (1 conversation-backend call) while a
  freshly typed prompt at the same spend was correctly refused. `applyModelCompaction()`
  now re-checks `spendCapReached()` before the 95% tier and refuses the turn through
  the shared `spendCapTurnRefusal()`, naming the summarization as what reached the
  cap. Note this was NOT an instance of the documented overshoot allowance: there
  the crossing happens inside a turn already under way, whereas here it happened in
  a previous `update()` and the app was electing to start a fresh chargeable turn.

### E21 — the automatic 85% compaction tier uses the heuristic summarizer, never the model

- **What** Phase 5 item 6 gave `/compact` model-written exchange summaries. The
  AUTOMATIC 85% tier still uses the truncate-and-`[exchanged information]`
  heuristic, so the compaction that happens without the user asking is the lossier
  of the two.
- **Where** `sugar-crush/src/Chat.php` — the `shouldCompact()` branch in `submit()`
  (the automatic tier) versus `scheduleModelCompaction()` (the `/compact` route).
- **Severity** Information loss on the unattended path. Not security.
- **Evidence** The automatic tier fires INLINE, inside `submit()`, as the turn it
  gates is dispatched. A provider round-trip there would freeze the render loop
  for the length of a completion — the exact freeze `scheduleModelCompaction()`
  exists to avoid — and this codebase deliberately puts no total-request timeout
  on a completion. Making it model-driven therefore means deferring the user's
  turn behind a compaction round-trip: a new pending state, a new Msg, and a
  re-entry into `submit()`'s tail, plus moving the 95% blocking tier's decision
  (which reads the compaction RESULT) into the async path.
- **Step** Give the automatic tier the same `Cmd` treatment: park the submitted
  draft, schedule the summarization, and on `HistoryCompactedMsg` apply the
  compaction and then dispatch the parked turn. Re-site the foreground-blocking
  check inside that continuation so it still tests the already-compacted history.
- **Blocked on** Nothing, but it is a real TEA restructure of the busiest method
  in `Chat`, and the seam it needs
  (`ContextCompactor::exchangesToSummarize()`/`withExchangeSummaries()`) is
  already built and tested.
- **Note 2026-08-19.** `crush_code.md`'s Phase 5 item 6 marker was changed from ✅
  to partially-done for exactly this reason. The item says model summaries "when a
  provider is available", and on the automatic tier a provider IS available; the
  automatic tier is also where most real compactions happen and it is the lossier
  path. The scope boundary is legitimate in engineering terms — see the Evidence
  above — but it was filed under the wrong marker.
- **CLOSED 2026-08-19 (bundle E21).** Shipped as the Step describes:
  `Chat::scheduleParkedCompaction()` parks the submitted turn behind the
  summarization, `applyModelCompaction()` re-sites the 95% blocking check where the
  compacted history first exists, and both routes dispatch through the extracted
  `Chat::dispatchTurn()`. With no summary backend the tier is the same synchronous
  heuristic code it always was. Three follow-ups came out of it and are filed
  separately: §E31 (the route's dormant cap gate's shape), §E32 (a parked
  summarization cannot be cancelled at the provider), §E33 (the 70% reminder is
  appended per turn). The spend-cap bypass the first cut introduced is recorded in
  §E20's E21 amendment.

### E22 — the transcript box is not wrapped to `cols()`, so a long message paints an over-wide row

- **What** `Chat::view()` composes the transcript without bounding any message's
  rendered line to the terminal width. The diff renderer paints **one line per
  row**, so an over-wide line pushes the frame's remaining rows down and collides
  with the absolute-`cursorTo()` positioning `render()`'s tail clip exists to
  protect. This is **frame corruption, i.e. functionality, not hardening** — filed
  here only so it is not lost, and it needs its own bundle rather than the
  end-of-plan security pass.
- **Where** `sugar-crush/src/Chat.php` — `view()` and the transcript composition it
  drives. Contrast `Renderer::renderStatusBar()`, which DOES select a form against
  a measured `$room`, and `handleHelpCommand()`, which clips every listing row.
- **Severity** Functionality. Every app state that can produce a long line is
  affected: a long assistant reply, a long user prompt, and a `[summary]` line.
- **Evidence** Measured at `cols=80`, `rows=24`, widest row of `Chat::view()` with
  `Width::string()` after stripping SGR:

      assistant  210 chars -> 216 cols   <-- over-wide
      assistant  300 chars -> 306 cols   <-- over-wide
      user       210 chars -> 222 cols   <-- over-wide  (a wider prefix)
      [summary]  210 chars -> 216 cols   <-- over-wide
      [summary]   90 chars ->  96 cols   <-- over-wide

  **NOT caused by Bundle B2**, and the last line is why: a 90-character message
  already overflows an 80-column terminal, so the hole was fully open before this
  round. B2 does raise the `/compact` summary ceiling, from **193** characters
  (heuristic: `summaryUserMaxChars` 80 + `' → '` 3 + `summaryAssistantMaxChars`
  100, plus the `'[summary] '` prefix 10) to **210** (model line
  `SUMMARY_LINE_MAX_CHARS` 200 + the same 10-char prefix). A hostile model reply
  cannot exceed 210: `Chat::sanitizeSummaryLine()` flattens all control bytes and
  whitespace and `mb_substr`s to 200 characters. So B2 widens the worst case by 17
  columns in a place that was already 16 columns over at 90 characters.
- **Step** Wrap (not truncate) each transcript line to `cols()` before it enters
  the frame, following the widest-first-form pattern `Renderer::contextIndicator()`
  uses and measuring with `Width::of(self::stripZoneMarkers(...))`. Never cut a
  line between a `markPane()` sentinel PAIR — `Scan::parse()` throws and the frame
  loses all its click zones. Assert the widest row's width in a test at several
  `cols` values rather than writing a width table in prose.
- **Blocked on** Nothing. It is its own bundle because the wrap has to be
  reconciled with the scroll offset (a wrapped message occupies more rows than one)
  and with `expanded` tool bodies.
- **Note 2026-08-19 — a second caller is now waiting on this.** The 85% tier's
  parked route adds two more app-authored transcript messages: the park notice
  (trimmed to **193** characters in the E21 fix round, from 220) and the tier
  report (**219**). Neither is the app's worst case — measured, the 95% blocking
  refusal is **423** characters and the idle-compaction advisory **391**, both
  pre-existing and both longer — so the claim that the park notice is "the longest
  app-authored message in the app" is false; it is fourth. But all of them paint as
  a single unwrapped row, and the tier that emits the new two fires without anyone
  asking for it, so this entry now has an unattended caller as well as the
  `/compact`-and-long-reply ones. Role-agnostic, as this entry already records.

### E23 — `ContextCompactor::exchangeKey()` collapses byte-identical exchanges, and the "harmless" clause is a judgement

- **What** `exchangeKey()` keys an exchange by its CONTENT, so N byte-identical
  exchanges collapse onto one key. `Chat::parseExchangeSummaries()`'s `isset()`
  guard keeps the FIRST model line for that key and discards the rest, and stage 3
  then groups all N into one message. The docblock calls this *"harmless — they
  would receive the same summary either way"*, which is a **judgement, not a
  measurement**, and `testTwoIdenticalExchangesShareOneKey` asserts the COLLISION,
  not the harmlessness.
- **Where** `sugar-crush/src/Context/ContextCompactor.php` — `exchangeKey()` and
  the stage-3 grouping; `sugar-crush/src/Chat.php` —
  `parseExchangeSummaries()`'s `isset()` guard.
- **Severity** Low. Correctness of a docblock claim, and possibly of the summary
  count the `/compact` notice reports.
- **Evidence** Not measured this round — the claim is what is unasserted, and
  asserting it is the work. The specific thing to measure: with 21 byte-identical
  exchanges, does the compacted transcript carry one `[summary]` line or 21, and
  does the "Summarising N earlier exchanges" notice's N match what the model was
  actually asked for? If the model returns DIFFERENT lines for the duplicated
  positions, 20 of them are silently discarded.
- **Step** Either measure the harmlessness and assert it (a test driving N
  identical exchanges through `/compact` and pinning the resulting line count and
  notice), or delete the "harmless" adjective from the docblock and record the
  clause as unasserted. Do not leave a judgement standing as if it were measured.
- **Blocked on** Nothing.

### E24 — summing streamed usage is safe only because no current provider reports a running total

- **What** `Runtime::runStreaming()` SUMS `Usage::reported()` across chunks
  (`Usage::sum($usages)`), which is correct for a provider that reports per-chunk
  DELTAS — and every one of the seven does. A future provider that reported a
  CUMULATIVE total on each chunk would be over-counted, quadratically in the worst
  case, with no guard to notice.
- **Where** `sugar-crush/src/Runtime.php` — `runStreaming()`'s `$usages[]`
  accumulation and the `Usage::sum()` at the yield.
- **Severity** Low today, and purely a cost-readout error rather than a behaviour
  change — except that the spend cap reads the same total, so an over-count would
  refuse turns early.
- **Evidence** Read all seven providers' streaming paths at HEAD: `Bedrock` and
  `Vertex` report on terminal/`message_start` events only, and
  `ClaudeCode`/`Custom`/`OpenAI`/`Sglang`/`Echo` report `tokensUsed: 0` on every
  chunk. So the sum equals the total for all seven, today.
- **Step** State the delta assumption in `runStreaming()`'s docblock as a
  REQUIREMENT on `ProviderInterface::completeStream()` rather than as an
  observation, and consider a cheap guard: if a chunk's `tokensUsed` is
  monotonically non-decreasing across three or more chunks, take the last rather
  than the sum. Do not add the guard without a provider that needs it.
- **Blocked on** A provider that reports cumulatively. None exists in-tree.

---

### E25 — project-scope memory entries are user-authored text folded into the system prompt

- **What** Since crush_code.md Phase 5 item 9, `Context/MemoryBlock` renders every
  PROJECT-scope `MemoryStore` entry into a `<project-memory>` fence inside the
  system prompt of every turn. Entry content is arbitrary text, so the block is a
  standing instruction channel whose contents nobody reviews: a note reading
  "ignore the Security section above" is, mechanically, in the same document as
  the Security section.
  **CORRECTED (fix round).** The first draft of this entry said the text is
  "written through `/memory add` (and by `Memory/ForeignMemoryImporter`)". The
  parenthetical is false, and it was the entry's whole reason for expecting the
  severity to rise — see Severity. `/memory add --scope project` is the ONLY
  writer that reaches this block.
- **Where** `sugar-crush/src/Context/MemoryBlock.php` — `render()`/`renderEntry()`;
  folded in at `sugar-crush/src/Runtime.php` `buildSystemPrompt()`, after the
  `<project-instructions>` documents and before the skill listing.
- **Severity** Low for the target operator (own `$HOME`, own notes: the content is
  their own, and this is no worse than their own `AGENTS.md`, which is also
  unreviewed text in the same prompt), and it does NOT currently rise on import.
  Measured: both of `ForeignMemoryImporter`'s write paths pass
  `scope: MemoryScope::Local` (`src/Memory/ForeignMemoryImporter.php:183, 244`),
  which `MemoryStore::normalizeScope()` maps to the on-disk string `agent`
  (`src/Memory/MemoryStore.php:441`) — a scope `MemoryBlock` excludes and
  `MemoryBlockTest::testUserAndAgentScopeNotesAreNotRendered()` pins. So an
  imported entry never reaches `<project-memory>` at all. The severity rises only
  if a future importer, sync feature or shared checkout can write PROJECT scope;
  until then the author and the operator are always the same person.
- **Evidence** No sanitisation of any kind is applied to entry content beyond
  whitespace collapsing and a byte clip: `renderEntry()` interpolates
  `$entry->content()` directly. The fence is a plain string, so an entry
  containing `</project-memory>` closes the block early — confirmed by reading
  `render()`; not currently exploited by anything, and NOT a frame-corruption
  issue because the system prompt is never painted to the terminal (nothing in
  `src/` renders it; `Runtime::buildSystemPrompt()` is private and its output
  leaves the object only inside a `CompleteRequest`).
- **Step** Two separable pieces. (1) Neutralise fence-breaking: strip or escape
  `</project-memory>` and `<project-memory>` in `renderEntry()`, the same way an
  instruction document would need it. (2) Decide whether an IMPORTED entry
  (`ForeignMemoryImporter`) should be marked as such in the rendered line so the
  model can weigh it below a note the operator wrote themselves. Neither changes
  behaviour for a solo operator, which is why both are deferred.
- **Blocked on** Nothing technical. Deferred under the functionality-first rule.

---

### E26 — `EnvironmentBlock` has no "additional working directories" line because there is no multi-root concept to describe

- **What** crush_code.md Phase 5 item 10a asks for an "additional working
  directories" line in the `<env>` block. It was deliberately NOT added. This
  records the prerequisite so the item is a decision rather than an oversight.
- **Where** `sugar-crush/src/Context/EnvironmentBlock.php` — `render()`'s line
  array, which now carries seven lines (the OS-version line from the same item
  WAS added). The absence is pinned by
  `tests/Context/EnvironmentBlockTest::testNoAdditionalWorkingDirectoriesLineIsEmitted()`.
- **Severity** None — this is a missing prompt line, not a defect.
- **Evidence** `grep -rniE 'additionalDir|additionalWorking|extraDirs|workingDirs|additionalDirectories' src/ bin/` returns
  **zero hits** at the time of writing. Extending that grep to `tests/` returns
  exactly **two** hits, both inside
  `tests/Context/EnvironmentBlockTest::testNoAdditionalWorkingDirectoriesLineIsEmitted()` —
  i.e. the test that asserts the absence, and nothing else. (Noted precisely
  because the first draft of this entry claimed zero hits across all three
  directories, which that very test had already falsified: the same
  number-without-its-domain slip this document opens by describing.) The only
  directories that exist are
  `App::$root` (`--root`'s value, nullable) and the process cwd, resolved together
  by `Runtime::projectRoot()`. A line fed from those two would either duplicate
  the `Working directory:` line above it or be permanently empty, and a
  permanently-empty line is a decorative surface.
- **Step** The line becomes meaningful only once a second root can exist. The
  natural source is a settings key (`additionalDirectories`) plus a `PathJail`
  that accepts more than one root — Phase 6 item 2's territory. Order: settings
  key → `PathJail` multi-root → `App` field → this line. Adding the line first
  would ship a promise the tools do not keep, which is worse than the omission:
  the model would be told it may work in a directory `Grep`/`Read`/`Edit`/`Write`
  still refuse.
- **Blocked on** Phase 6 item 2 (a settings surface), and a multi-root `PathJail`.

---

### E27 — two provider failure shapes are deliberately left unclassified by the transient-failure retry

- **What** crush_code.md Phase 5 item 8's retry classifies on an ALLOW-LIST
  (`Providers/TransientFailure`), so anything unrecognised is treated as
  permanent and not retried. Two shapes in-tree are unrecognised on purpose, and
  both are arguably transient:
  (a) **`ClaudeCodeProvider`** throws bare `\RuntimeException`s carrying only
  prose — `'Failed to start Claude Code process'` and
  `"Claude Code exited with code $exitCode: $errors"` — so there is nothing
  structured to classify. It is not retried, and its message is deliberately NOT
  pattern-matched.
  (b) **`VertexProvider::parseAnthropicChunk()`**'s truncated-tool-call branch
  returns an error `CompleteResponse` when a streamed tool call's argument JSON
  does not decode to an object. A truncated stream is a plausible transport
  failure, but the same branch also fires for a model that emitted genuinely
  malformed JSON, which retrying cannot fix and would cost 3x.
- **Where** `sugar-crush/src/Providers/ClaudeCodeProvider.php` (its two throws);
  `sugar-crush/src/Providers/VertexProvider.php` — the truncated-arguments
  `CompleteResponse` in `parseAnthropicChunk()`, which leaves `errorTransient`
  null. (a) is pinned by
  `tests/Providers/TransientFailureTest::testAClaudeCodeSubprocessFailureIsNotTransientAndThatIsAKnownGap()`.
- **Severity** None security-wise; a missed recovery opportunity only. The
  user-visible effect is the pre-item-8 behaviour for those two shapes.
- **Evidence** Read all seven providers' failure paths. Classified and retried:
  `OpenAI` (`ErrorException::getStatusCode()`/`TransporterException`), `Sglang`
  and `Bedrock` (informative exception preserved as `getPrevious()`), `Custom` and
  `Vertex` (classified at the catch site into
  `CompleteResponse::$errorTransient`). `Echo` cannot fail. That leaves exactly
  the two above.
- **Step** For (a): give `ClaudeCodeProvider` a typed exception carrying the exit
  code, then decide per code — a spawn failure is transient, a non-zero exit
  usually is not. For (b): distinguish "stream ended mid-tool-call" from "decoded
  but was not an object" at the point the buffer is inspected; only the former is
  transient.
- **Blocked on** Nothing. Both are small and both are deferred as scope.

---

### E28 — the sub-agent retry is only reachable through tests and embedders

- **What** `AgentManager::executeSubAgent()` carries the same transient-failure
  retry as `Runtime`'s two seams, including a mid-stream rollback the `Runtime`
  path deliberately cannot do. Nothing in `src/` or `bin/` calls
  `executeSubAgent()`, so that retry — and the rollback — is exercised only by
  this repo's tests and by an embedder driving the manager directly.
- **Where** `sugar-crush/src/Agents/AgentManager.php` — `executeSubAgent()`'s
  streaming and non-streaming branches. The reachability fact is already recorded
  in `src/Renderer.php`'s class docblock.
- **Severity** **Raised (fix round): Medium the day the seam is wired.** The
  original wording — "the retry is correct, it is just not on a user-reachable
  path yet" — was wrong on the first half. The retry rolls back the three fields
  it names (`$output`, `$tokensUsed`, `$costUsd`) and nothing else, but an
  attempt also runs `evaluateToolCalls()`, which calls
  `PermissionGate::evaluate()` — i.e. `decide($call, commitAutoStrikes: true)`,
  which advances the Auto-mode circuit-breaker counters
  (`src/Permissions/PermissionGate.php:96-99`, counters `:290-302`) — and then
  the `$permissionApprover`, a blocking user-facing prompt. Neither is rolled
  back and neither CAN be: it is the same append-only argument that stops
  `Runtime::runStreaming()` from retrying after a byte has reached `$onToken`,
  reaching the opposite answer on a different channel.
  **Measured:** one `Write` tool call plus a 503 mid-stream produces **2 approval
  prompts for the same tool call** and double-commits its gate strikes. Harmless
  only because nothing calls the seam; it surfaces the day something does, which
  is exactly when a reader would trust the old "is correct".
- **Evidence** `grep -rn executeSubAgent src/ bin/` finds only docblock
  cross-references plus the definition itself. The route that would populate it is
  crush_code.md #45 (a Task tool that delegates to a registered agent) or #13
  (constructing `WorkflowEngine`).
- **Step** Two, both due before the seam gains a caller, not after. (1) Make the
  retried region free of user-visible and gate-visible side effects: hoist
  `evaluateToolCalls()` out of the retry loop, or make approval idempotent per
  tool-call id so a second attempt reuses the first attempt's verdict and commits
  no second strike. (2) Then re-read the OUTPUT rollback's premise: it holds
  because consumers PULL `SubAgent::$output` as a whole-value snapshot
  (`AgentManager::liveOutput()` documents exactly that); a future consumer that
  treats each `yield` as a delta invalidates it and the gate has to become
  `Runtime::runStreaming()`'s emitted-bytes gate. The retry itself stays — a
  dormant seam gets completed or documented, never deleted; what is deferred is
  the fix, not the finding.
- **Blocked on** crush_code.md #45 / #13.

---

### E29 — `vendor/bin/phpunit tests/Cli` hangs, while the full configured run passes

- **What** A directory-scoped run of `tests/Cli` does not finish. Measured over 4
  minutes and killed at 250s, while the full configured suite passes in ~2m26s and
  every single file under `tests/Cli/` passes on its own in ≤1s. Something leaks
  across tests in a way that only bites when that directory runs as its own suite
  and without the rest of the suite's ordering, and `defaultTimeLimit=60` in
  `phpunit.xml` does not abort it — so whatever blocks is not interruptible by the
  time-limit signal.
- **Where** `sugar-crush/tests/Cli/` as a selection. Not attributable to one file:
  each passes individually. Candidate mechanism (unverified): a test that leaves an
  env var, a `getcwd()`, a latched static (`ToolIpcFiles::sweepOnce()`,
  `Bootstrap`'s config-dir latch) or a child process behind, which a later file in
  the same process then waits on.
- **Severity** None to shipped behaviour — CI runs the configured suite, which is
  green. Real cost to DEVELOPMENT: it makes directory-scoped runs untrustworthy, so
  every verification round has to pay the full ~2m20s, and an agent that judges
  green from `vendor/bin/phpunit tests/Cli` gets a hang it may read as a failure of
  whatever it just changed.
- **Evidence** **Pre-existing — not introduced by Bundle B3.** Reproduced on the
  B3 tree at baseline by the independent reviewer, then again during the fix round.
  Full configured run: 7190 tests, RC 0, ~2m26s. `tests/Cli` alone: no completion,
  killed at 250s. Per-file: all green, ≤1s each.
- **Step** Bisect by halving the file list (PHPUnit accepts repeated positional
  paths) until a pair reproduces it, then diff what the first leaves behind:
  `getenv()` snapshot, `getcwd()`, open handles, `pcntl` children, and the two
  latching statics named above. The fix is almost certainly a `tearDown()` in one
  file, not a suite-wide change.
- **Blocked on** Nothing. Deferred as out of scope for a bundle that did not cause
  it and must not be judged by it.

---

### E30 — three backoff figures are literals with no test reading them back

- **What** `TransientFailure::BASE_BACKOFF_MICROSECONDS`, `MAX_ATTEMPTS` and the
  derived total are described in prose in three places, and no test asserts any of
  those descriptions. Mutating `BASE_BACKOFF_MICROSECONDS = 500_000` to `1`
  survives 3188 tests, because every backoff assertion is deliberately RELATIONAL
  (`totalBackoffMicroseconds()` derived from the constants, compared against
  `EngineBackend::COMPLETE_TIMEOUT_SECONDS`). That is the "derive, don't literal"
  rule working exactly as intended for the CODE — the rot risk is in the PROSE.
- **Where** `crush_code.md` Phase 5 item 8's status entry ("500ms doubling, ~1.5s
  of backoff per provider call"), and `sugar-crush/src/Providers/TransientFailure.php`'s
  class docblock, which after the fix round names `totalBackoffMicroseconds()`
  instead of quoting 1.5s — so the surviving literals are in `crush_code.md` only,
  now marked "at the constants of the time".
- **Severity** None operationally. Documentation-rot risk: the day someone raises
  `BASE_BACKOFF_MICROSECONDS`, the suite stays green and the prose silently starts
  lying, which is the exact defect class this project keeps re-finding.
- **Evidence** The surviving mutation above, measured by the independent reviewer
  across 3188 tests. Confirmed intentional: `testTheTotalBackoffIsTheSumOfEveryWaitItWouldPerform()`
  and `testTheWholeBackoffSequenceFitsFarInsideTheIdleTimeout()` both recompute
  from the constants rather than naming a duration.
- **Step** Either (a) add one test that asserts the human-readable figures the
  prose quotes — `BASE_BACKOFF_MICROSECONDS === 500_000` and
  `totalBackoffMicroseconds() === 1_500_000` — accepting that it is a literal test
  whose only job is to fail when the prose goes stale, or (b) delete the durations
  from the prose and cite the constants and `totalBackoffMicroseconds()` by name.
  (b) is preferred and is what was done inside `src/`; (a) would be needed to
  protect `crush_code.md`, which no test reads.
- **Blocked on** Nothing. A choice about which of two rot-protection styles this
  repo wants for planning documents.

### E31 — the parked tier's dormant spend-cap gate answers `null` where `/compact` answers with a notice

- **What** `Chat::scheduleParkedCompaction()` opens with
  `if ($this->spendCapReached()) { return null; }`, i.e. it downgrades a capped
  session to the heuristic summarizer in SILENCE. Its sibling
  `scheduleModelCompaction()` (`/compact`) deliberately does the opposite: it
  answers with a notice naming the spend, the cap and the `/budget` figure that
  would lift it, because "a silent downgrade is indistinguishable from having no
  provider at all, and the user set the ceiling that caused it".
- **Where** `sugar-crush/src/Chat.php` — `scheduleParkedCompaction()`'s first
  statement, versus `scheduleModelCompaction()`'s cap arm.
- **Severity** None today: dormant, and the wrong answer is only lossier, not
  unsafe. It becomes a real silent downgrade the day the ordering upstream changes.
- **Evidence** Unreachable as written, and that was measured rather than assumed:
  `submit()` runs `spendCapRefusal()` before the 85% block, so a capped session's
  ordinary prompt is refused outright and never reaches the tier. A mutation
  deleting the gate survives the whole suite (M08 in the E21 review), which is the
  definition of unasserted defence — now said in those words in the docblock rather
  than left to look covered.
- **Step** Keep the gate (repo policy is wire-don't-remove) but give it the
  `compactNow`-with-notice shape its sibling uses, so that if `submit()`'s ordering
  ever changes the capped session is TOLD it is on the heuristic instead of
  silently getting the lossier path. The notice text already exists in
  `scheduleModelCompaction()`; the two would share it.
- **Blocked on** Nothing. It is a shape decision worth making before the ordering
  it depends on is ever touched.

### E32 — a parked summarization cannot be cancelled at the provider, so a cancelled turn still pays for it

- **What** During the 85% tier's parked window, double-Escape abandons the turn:
  the latch is released, the summaries are dropped when they land, and no turn is
  ever sent. What it cannot do is stop the summarization CALL. `Chat` holds no
  cancellation token for it — `Backend::completeAsync($prompt)` is invoked with no
  `CancellationToken` argument at all on that path, and during the parked window
  `$this->inFlightCancellation?->cancel()` is a no-op on null, because
  `scheduleParkedCompaction()` deliberately arms no token (there is no backend turn
  yet). So the user cancels, the request runs to completion in the background, and
  the cost is accounted — correctly — against their key.
- **Where** `sugar-crush/src/Chat.php` — `buildSummarizationRequest()`'s
  `Cmd::promise` (the `completeAsync($prompt)` call), the double-Escape arm in
  `update()`, and `scheduleParkedCompaction()`.
- **Severity** Money, not correctness. Bounded by one summarization per parked
  turn; a compaction reads the whole earlier conversation, so it is routinely the
  largest single prompt the app sends.
- **Evidence** Settled by reading the three sites rather than by driving a
  provider. `sugar-crush/tests/Chat/AutomaticCompactionModelSummaryTest.php::testAnAbandonedSummarizationIsStillBilled()`
  pins the accounting half behaviourally: after a cancel, the landing still bills
  the call. That test asserts the current behaviour is HONEST, not that it is
  desirable.
- **Step** Arm a `CancellationToken` for the summarization itself and thread it
  into `completeAsync()`, then cancel it from the double-Escape arm alongside the
  latch release. **The standing policy applies and is not negotiable while doing
  it: a short `connect_timeout` is fine, a blanket total-request timeout on a
  completion never is** — a completion can legitimately run for many minutes.
  Cancellation is the mechanism; a deadline is not.
- **Blocked on** Nothing, but it touches the shared `buildSummarizationRequest()`
  seam that `/compact` also uses, and `/compact`'s route has no `inFlight` window
  to cancel from — so the two routes need different cancel triggers for one shared
  call.

### E33 — the 70% reminder is appended to PERMANENT history every turn, and compaction now keeps every copy

- **What** `Chat::dispatchTurn()` appends `contextReminderMessage()` to `history`
  on every turn once the estimate passes the reminder threshold. It is not a
  transient render-time hint: it is committed, checkpointed, sent on the wire, and
  sent again on every subsequent turn alongside a fresh copy of itself. A session
  driven twenty turns past 70% carries twenty near-identical ~171-character system
  messages whose whole content is "consider running /compact".
- **Where** `sugar-crush/src/Chat.php` — `dispatchTurn()`'s reminder arm and
  `contextReminderMessage()`.
- **Severity** Wasted context on the very histories that are short of it, plus
  transcript noise. Not security.
- **Evidence** Newly worth recording because the E21 fix round changed what happens
  to those copies. `ContextCompactor::groupIntoPairs()` used to DROP a
  non-user/non-assistant message directly following a user turn, which is exactly
  where the reminder lands — so compaction silently deleted every reminder, and the
  waste was self-limiting by accident. That drop also erased `_Request cancelled._`
  and the tier's own report, so it had to go. With it gone, measured on a 20-turn
  history with a reminder after every prompt: `compact()` returns 50 messages
  instead of 30 and frees 32% of the estimate instead of 46%, and the difference is
  almost entirely preserved reminders. Correct — a compaction must not erase what it
  was handed — but it makes the duplication visible and worth fixing at the source.
- **Step** Emit the reminder ONCE per crossing rather than per turn: either keep it
  out of `history` and render it from state (the same treatment the status bar's
  context indicator already gets), or suppress it when the newest history entry is
  already a reminder. Prefer the first: a message the app can re-derive does not
  need to be on the wire at all. `groupSimilarExchanges()` already collapses
  consecutive identical entries, which is why the waste shows up as 20 copies rather
  than 20 collapsed ones — they are not adjacent.
- **Blocked on** Nothing, but it changes what a wire looks like for every session
  over 70%, so it wants its own bundle and its own fixture diff rather than riding
  along with a tier change.

- **Amended 2026-08-19 (bundle C1's round).** Two things the entry left open are
  now measured. First, the "keep it out of `history`" step is not blocked by an
  append-only invariant, because there is none: `src/Chat.php` rewrites `history`
  wholesale on several established paths — `'history' => $newHistory` (tool-result
  splice, twice), `'history' => []` (`/clear`), `[...$compactedHistory, ...]`
  (every compaction tier), and `[...$messages, ...]` (`/rewind` truncating). So
  dropping a previous copy while adding a new one is the same class of operation
  the compactor already performs, and either shape is available. Second,
  `dispatchTurn()` persists `'messages' => $next->history` into the checkpoint, so
  whichever shape wins is inherited by checkpoints for free — there is no second
  serialisation site to keep in step. Third, `ContextCompactor::shouldSendReminder()`
  is confirmed PURE and stateless (`src/Context/ContextCompactor.php:167-177`: a
  bare `$tokenCount >= $threshold`) — no latch, no timestamp, nothing to make it
  fire once, which is the mechanical reason it fires per turn.
- **Test the fix must carry** the pile-up as a QUANTITY, not a presence: drive N
  turns past the threshold and assert the NUMBER of reminder messages in history.
  A test asserting only that "a reminder is present" passes under all three
  candidate shapes AND under the bug.

---

### E34 — a shell-out completion blocks the event loop, so `$onToken` fires and nothing can paint it

- **What** Both shell-out tiers run the whole completion synchronously inside a
  `Loop::futureTick`, so the ReactPHP loop is blocked for its entire duration. The
  streaming tier's `$onToken` callback genuinely fires per token — but the
  `withTick` subscription in `Chat` that turns `TokenDelta`s into `$streamingText`
  cannot run until the completion has already resolved. The user sees a silent
  spinner and then the whole answer, which is precisely the behaviour the streaming
  tier was added to remove.
- **Where** `sugar-crush/src/Backend/StreamingCommandBackend.php` —
  `completeAsync()` wrapping the synchronous `complete()`;
  `sugar-crush/src/Backend/CommandBackend.php` does the same. The render side is
  `sugar-crush/src/Chat.php` (`withTick` → `$streamingText`).
- **Severity** Not security. It is the defect that the wiring of Phase 2 item 8
  was justified by removing, so it matters to whether that item is really done.
- **Evidence** Measured by bundle C1's reviewer on a six-token/1.81s fixture:
  callback times `[0.006, 0.306, 0.606, 0.906, 1.211, 1.511]`, and render ticks
  that fired during the stream: **`[]`** — zero, out of zero total. Separately, on
  the `-p` path `NonInteractive::run()` passes no `$onToken` at all, so tier 3 there
  streams to nobody by construction.
- **Step** Implement `completeAsync()` natively: drive the pipes with
  `Loop::addReadStream` and resolve a promise on child exit, instead of wrapping a
  blocking read. Deliberately NOT done in C1's fix rounds — it is an architectural
  change to an optional tier, the same blocking defect affects tier 2, and letting a
  fix round grow a new subsystem is how these rounds get lost. C1's round B instead
  withdrew the false display claim and left the callback claim standing with its
  domain stated. **Do not "fix" this by adding a total request deadline** — an LLM
  completion legitimately runs tens of minutes.

---

### E35 — cancellation cannot interrupt a shell-out completion

- **What** `StreamingCommandBackend::completeAsync()` checks
  `$cancellation?->isCancelled()` exactly once, at `futureTick` entry, and never
  again. `complete()` then blocks the loop, so no keystroke is even read — double
  Escape and Ctrl+C are unobservable until the command finishes on its own.
- **Where** `sugar-crush/src/Backend/StreamingCommandBackend.php`, the
  `completeAsync()` cancellation check. Identical on `CommandBackend`.
- **Severity** Not security. A user cannot abandon a wedged or wrong turn.
- **Evidence** Bundle C1's reviewer traced the single check and confirmed the
  blocking read behind it. Sharpened by the same round's finding that a wrapper
  which backgrounds anything can hold the pipes open long after the child exits —
  C1 bounded that at a 2-second post-exit grace, but a well-behaved long-running
  wrapper is still uninterruptible for its whole run.
- **Step** Falls out of E34: once the read loop is driven off the event loop, the
  cancellation token can be honoured between reads and the child terminated with
  the bounded SIGTERM→SIGKILL escalation C1 already added
  (`terminateAndReap()`). Do these together; doing E35 alone is not possible while
  the loop is blocked.

---

### E36 — `tests/Cli/BootstrapSkillSkipsTest.php` is rc=1 alone and green in the full suite

- **What** Run on its own against a clean tree the file exits 1 with
  `OK, but there were issues! Risky: 2`; in the full configured run it contributes
  no risky tests and the suite is exit 0 with 0 risky. Order-dependent.
- **Where** `sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php`.
- **Severity** Not security. It is a trap for any future round that tries to
  judge this file in isolation — and `failOnRisky` is ON, so the exit code is red
  while the banner says OK.
- **Evidence** Measured by bundle C1's reviewer on the clean tree, both ways.
- **Step** Find which other test establishes the state that makes these two
  non-risky and either make the file self-sufficient or mark the dependency
  explicitly. Companion to **E29** (`vendor/bin/phpunit tests/Cli` hanging) — but
  note the two are different failures, and C1's round A established that
  single-FILE runs inside `tests/Cli` are fine and fast (0.054s), so E29's warning
  should not be read as covering this.

---

### E37 — `--help` documents 6 of the 20 `SUGARCRUSH_*` variables the code reads

- **What** `src/Cli/Help.php` names six environment variables; `src/` and `bin/` read
  twenty. (Five before bundle C1, which added the streaming variable — the heading said
  five until this was re-measured. And the twenty is right only after discarding
  `SUGARCRUSH_DISABLE_`, a prefix fragment, from a 21-name raw grep.) Missing include `SUGARCRUSH_MAX_COST`,
  `SUGARCRUSH_PERMISSION_MODE`, `SUGARCRUSH_TITLE_MODEL`,
  `SUGARCRUSH_SUMMARY_MODEL` and `SUGARCRUSH_SEARCH_ENDPOINT` — i.e. the spend cap
  and the permission mode, both of which change what the agent is allowed to do.
- **Where** `sugar-crush/src/Cli/Help.php`; the inventory gap is why bundle C1's
  finding 7 existed (a nine-line block for a brand-new variable that no test
  pinned, because the only assertion was a substring the OLD variable's name
  already satisfied).
- **Severity** Not security in itself, though two of the undocumented variables
  govern permission and spend.
- **Evidence** Counted by bundle C1's reviewer across `src/` and `bin/`.
  `docs/ENVIRONMENT.md` is missing only one (`SUGARCRUSH_DEBUG_SKILLS`), so the
  reference doc is nearly complete and the help screen is not.
- **Step** Extend the env-var scrape C1's round B added for the backend-selection
  subset to cover all twenty, the way `HelpTest` already scrapes `ArgvParser` so a
  new FLAG cannot ship undocumented. Derive the names from source, never from a
  hand-written list — a written list is exactly as blind to the twenty-first
  variable as the current assertion was to the sixth.
---

### E38 — a compaction folds the reminder's full text into a `[summary]` line the dedup can never match

- **What** Bundle E33 bounds the 70% reminder at one VERBATIM copy. It does not
  bound the summarized ones. `ContextCompactor`'s summarizer folds a rider into
  `'[summary] ' . $riderContent`, so after a compaction the reminder's entire
  171-character text survives as a `Role::System` line whose content no longer
  STARTS with `Chat::CONTEXT_REMINDER_PREFIX` — which is exactly what
  `Chat::isContextReminder()` matches on. One such line can accrue per compaction,
  so the pile-up changes shape rather than being eliminated.
- **Where** `sugar-crush/src/Context/ContextCompactor.php` (the `[summary] ` rider
  path) against `sugar-crush/src/Chat.php`'s `isContextReminder()` /
  `withoutContextReminders()`.
- **Severity** Not security. Wasted context and transcript noise, at one line per
  compaction rather than one per turn — a large improvement on the original bug, but
  not the "bounded at one copy" the fix's docblock implies without qualification.
- **Evidence** Measured by bundle E33's reviewer after a compaction:

      lines still containing the reminder text after compaction: 2
        role=system content=[summary] Heads up: this conversation has grown to ~70123 estimated tokens, past the context-usage r
        role=system content=Heads up: this conversation has grown to ~70123 estimated tokens, past the context-usage reminder th

  The stripper's docblock claim that a folded reminder's "content no longer starts
  with the marker" is TRUE, and scoping the predicate to the verbatim form is
  correct — a summary line is a record of what happened and should not be silently
  deleted. What is false is the accompanying framing that a folded reminder is "no
  longer a copy of anything": 100% of the text survives, merely prefixed.
- **Step** Decide whether an app-authored notice should be summarized at all. The
  cheapest correct answer is probably that the summarizer should not carry a
  reminder's body into a summary line — it is a notice about the transcript rather
  than content of it, so a summary that names it ("a context reminder fired here")
  costs a few tokens instead of 171. Do NOT fix this by widening
  `isContextReminder()` to match the `[summary] ` form and delete those lines: that
  would let a compaction's own record be erased, which is the class of bug bundle
  E21 spent a round removing.
- **Related** E33 (the verbatim copies), E21 (`groupIntoPairs()`'s silent drops).

---

### E39 — a full-suite run stalls in the `tests/Cli` region, rarely, on an unchanged tree

- **What** Two independent agents, in different bundles, hit a full
  `vendor/bin/phpunit` run that stopped making progress around the `tests/Cli`
  region on a tree that then ran clean twice. **This is NOT E29.** E29 is
  `vendor/bin/phpunit tests/Cli` invoked as a DIRECTORY, which hangs
  reproducibly; this is the full configured run, and it is intermittent.
- **Where** Unknown. Suspected: a test row that makes a real network connect.
  Bundle C1's reviewer observed the stall with two `[sh] <defunct>` children aged
  2m22s and named `tests/Cli/NonInteractiveProviderFailureTest.php`, whose
  `dev-sglang` row connects to `localhost:30000`. Bundle E33's fixer observed a run
  reach only 1,891/7,283 before a 10-minute wall, with what looked like `R`
  (risky) progress markers in the same region, and found no orphan `php`/`phpunit`
  processes afterwards.
- **Severity** Not security. It is a **trap for future rounds**, which is the
  reason to record it: the shape reads as "my change broke the suite" and is not.
- **Evidence** Neither observer could reproduce it. In both cases the IDENTICAL
  tree then completed in ~2m38s at rc 0 with zero risky, and the individual
  `tests/Cli` files run in ~0.06s each.
- **Step** Before diagnosing, re-run. If it recurs, capture `ss -tnp` / `lsof` for
  a connection to `localhost:30000` and the `php` process's `wchan`, and check
  whether any provider row in the suite makes a live connect that is not gated on
  reachability. A test that dials a port nothing is listening on should be gated,
  not left to a connect timeout.
- **Operational note** 600,000 ms (the 10-minute Bash ceiling used for suite runs)
  is enough for a normal 2m40s run but NOT enough headroom to survive one of these
  stalls, so a stall presents as a timeout kill. Re-run before believing it.

## F. Known dormant seams — documented, NOT work

These are features intentionally left unwired. **They are not bugs and none of
them is a deletion candidate.** The standing rule on this project is to complete
a dormant seam or document it as one — never to remove it because it looks dead.
They are listed so a hardening pass does not mistake them for defects, and so
anyone who *does* wire one knows what gate to add at the same time.

- **F1 — `src/Commands/CommandLoader.php`: file-based custom commands are
  loadable, and nothing constructs a loader in production.** `grep -rn 'new
  CommandLoader' src/ bin/` returns **nothing** at HEAD; the only occurrences of
  the name in `src/` are six `{@see}` cross-references from other classes'
  docblocks. The class is complete (3-tier discovery, frontmatter parsing,
  containment via `loadFromDirectory($dir, ?$anchoredIn)` at `:83`/`:90`,
  `loadUserCommands()` at `:179` — **DRIFTED** from the review's `:138` —
  `loadProjectCommands()` at `:205`). Wiring it is a **Phase 2 functionality
  item**, and `crush_code.md` records the prerequisite: the template-substitution
  engine (`$ARGUMENTS`/`$1`/`` !`cmd` ``/`@file`) does not exist, so *"wiring the
  loader alone isn't sufficient"*. When it is wired, note that lane D round 10
  already fixed its containment (user tier switched from `HomeDirectory::path()`
  to `owned()` and given an `$anchoredIn`) — that fix was for the **eleventh read
  path**, and the seam being dormant is what limited the blast radius until then.
- **F2 — `TextArea` Ctrl+O `$EDITOR` `proc_open`.** See §C2, which is the same
  seam viewed as a hardening prerequisite.
- **F3 — `AgentPoolConfig::$maxRetries`.** `src/Agents/AgentPoolConfig.php:51`
  (`= 2`). `crush_code.md`: *"documented as an intentional dormant seam rather
  than wired, because re-running a sub-agent that already edited files or spent
  tokens is a behavioural decision, not a bug fix."* The per-sub-agent
  equivalent is `src/Agents/SubAgent.php:54`.
- **F4 — `Tui\Components\InputPane`.** Reachable only on the non-hosted branch:
  `src/Tui/Renderer.php:349` (`$bottom = $hosted ? '' : InputPane::render(...)`),
  and `Bootstrap::app()` always constructs a hosted `Chat`. Unlike `AgentsPane`,
  nothing documents it as intentional — `crush_code.md` explicitly calls this
  *"worth a human decision (document it as intentionally dormant the way
  `AgentsPane` already is, or remove it, but only with explicit sign-off; not an
  automatic removal)"*. **The cheap, rule-compliant action is to write the
  docblock**, which converts an ambiguity into a documented seam.
- **F5 — the tmux/iTerm2 split-pane compositor. WIRED, NOT YET VISIBLE — stays
  open.** It used to have zero production callers, deferred per its own docblock
  pending a public live-output-buffer accessor on `AgentManager`. That accessor
  landed (Phase 1 item 1), and Phase 8 item 4 wired the compositor:
  `Tui\Renderer::renderView()` reaches `renderForCurrentEnvironment()` through a
  private `composeAgentSplit()` whenever `AgentManager::liveOutputs()` is
  non-empty and the terminal is at least 80 columns, laying a
  `Components\AgentSplitColumn` of live-agent tiles beside the shell band.
  Activation is data-driven — no flag, no config key. Covered by
  `tests/Tui/AgentSplitCompositorTest.php`.

  **This was briefly marked RESOLVED and that was wrong on three counts, two of
  them since fixed.** (1) The compositor read a `liveOutputs()` that iterated
  the REGISTERED agent map, and a workflow's parallel stage never registers —
  it builds ad-hoc `Agent`s named `$task->name ?? $task->agentType`
  (`WorkflowEngine.php:1254`) and hands the `SubAgent`s to `executeAll()`, which
  files them under the sub-agent map only (`AgentManager.php:681`). Measured:
  with a real roster registered and a `SubAgent` named `style-fixer` inserted
  exactly as `executeAll()` does, `liveOutput('style-fixer')` returned its
  buffer while `liveOutputs()` returned `[]`. Neither shipped workflow names a
  parallel task after a roster agent. **Fixed:** `liveOutputs()` now derives
  from the sub-agent map. (2) It never deactivated — `liveOutput()` filters only
  on `output !== ''` and nothing clears `SubAgent::$output`, so the first
  workflow of a session would have opened a column that stayed open until exit.
  **Fixed:** `liveOutputs()` applies the same `!isComplete() && !isStopped()`
  predicate `active()` has always applied. (3) **NOT fixed, and this is why F5
  stays open:** no frame paints while the agents talk. `Chat::workflowRun()`
  calls `WorkflowEngine::run()` synchronously (`Chat.php:6212`) from inside
  `update()` (dispatched at `Chat.php:5480`); candy-core's `Program` repaints
  from a periodic timer on the ReactPHP loop (`Program.php:387`) while
  `ProcessExecutor` blocks in a raw `stream_select()` (`ProcessExecutor.php:81`,
  `:235`), so the tick cannot fire until the run is over — and by then the
  liveness filter has correctly emptied the map. `workflowRun()`'s own docblock
  records this as KNOWN GAP issue #79 and names the fix (the fork-plus-socket
  pattern `Backend\EngineBackend::completeAsync()` uses). **F5 closes when #79
  lands, not before.**

  Two follow-ups this uncovered, neither in scope here:
  - The in-transcript agent strip (`Renderer::renderAgentView()`) and
    `AgentDashboardPane` both read `AgentManager::active()`, which is still
    keyed off the REGISTERED map — so a workflow's agents are invisible to both
    for the same reason the compositor's source was. Whether `active()` should
    derive from the sub-agent map too is a four-call-site decision, not a
    docblock edit.
  - `AgentSplitColumn::state()` used `$agent?->isActive` for its status string,
    which reads "stopped" for any roster agent, since `Bootstrap::agentRoster()`
    registers the roster INACTIVE. Now `AgentManager::isWorking()`, mirroring
    `active()`.
- **F6 — `Agent::$isActive`.** Named as an intentional dormant seam in
  `Agent::fromPreset()`'s docblock (`src/Agents/Agent.php:113-114`), pointing at
  `fromDefinition()` for the reasoning.
- **F7 — `Tui\SessionTabs` / `SessionTab`.** Deliberately bypassed; the live tab
  strip reimplements the same semantics directly against `SessionStore`.
  **DRIFTED** — `crush_code.md` cites `src/Renderer.php:124-134`; at HEAD the
  reasoning is in the class docblock at `src/Renderer.php:163-167` (*"its
  constructor always…"*), cross-referenced from `:1393`. On `crush_code.md`'s
  consolidation-review list, which is a human decision point, not a task.

---

### E40 — `.mcp.json` TOCTOU, and a hard link escapes containment

- **What** Two limits on the `.mcp.json` containment check, both low-severity but
  both attached to the one `ContainedPath` caller whose granted file causes
  `proc_open()`. (a) `Bootstrap::mcpClient()` resolves the path three times —
  `is_file()`, `ContainedPath::within()`, then `McpClient::loadConfig()`'s
  `file_exists` + `file_get_contents` — and neither narrows the window nor says so,
  against `ContainedPath`'s own documented house rule ("Callers that grant a path
  and read it later must say so where they grant it — see
  `WorkflowRegistry::readableProjectDir()`, which narrows its own window rather
  than pretending it has none"). (b) A `.mcp.json` that is a HARD LINK to a file
  outside the tree passes containment.
- **Where** `sugar-crush/src/Cli/Bootstrap.php` (`mcpClient()`),
  `sugar-crush/src/Support/ContainedPath.php`.
- **Severity** Low. (a) needs a co-resident writer. (b) needs someone who can
  already hard-link, and who could therefore just write the file — so it is not an
  escalation.
- **Evidence** Bundle C3's reviewer ran the full containment battery; five of six
  shapes behave correctly (symlinked root, config→file inside root, config→symlink
  outside REFUSED, root spelled with `..`, config is a directory). The sixth,
  `cfg = hardlink outside`, was accepted.
- **Step** For (a), narrow the window or state it at the grant site the way
  `WorkflowRegistry` does. For (b), do NOT try to defeat hard links — instead
  qualify `ContainedPath`'s threat-model paragraph, which argues hard links are out
  of scope because "git cannot represent or commit a hard link, so no `git clone`
  produces one". That argument is sound for a clone and NOT for `.mcp.json`, whose
  threat model includes a prior session's own `Write`/`Bash` and any co-resident
  process — the result being a repo-local config whose bytes live outside the repo
  and which `git status` cannot see. The paragraph should say so for this caller
  rather than being inherited.
- **Related** The trust gate added in C3's fix round A is the primary control; this
  is about the secondary one.

---

### E41 — `McpClient` config-loading gaps: a lost config tail, and four silent broken shapes

- **What** Three defects in `McpClient`'s config handling, all pre-existing and all
  newly reachable now that something constructs the client. (a) `startServers()`
  loses the TAIL of the config on a bad entry: an unknown `type` throws out of
  `startServer()`'s `match` default, which is OUTSIDE the
  `try/catch(\RuntimeException)` that protects a failing `start()` — so servers
  listed before the bad entry are up and every server after it is never reached,
  and which capability you lose depends on file order. (b) `$denyPatterns` are
  **inert** on the path C3 wired: they are consulted only through `router()`, which
  is reached only from the `$agentPreset !== null` arm, so with `unrestricted: true`
  and no preset `listTools()` merges every server directly and deny patterns
  enforce nothing. (c) Four of five broken-config shapes are SILENT.
- **Where** `sugar-crush/src/MCP/McpClient.php` — `startServers()`, `startServer()`,
  `loadConfig()`, `listTools()`.
- **Severity** Not security (the trust gate is upstream of all of it). Silent
  capability loss on a file the user wrote on purpose.
- **Evidence** Measured by bundle C3's reviewer across five shapes:

      A) server that cannot start (bogus binary)    stderr = []            <- the common case
      B) unknown type                               stderr = [reported]
      C) malformed JSON                             stderr = []
      D) valid JSON, wrong top-level key            stderr = []
      E) unknown type first + working server after  stderr = [reported], tail lost

  Only (B) reports, because `startServer()` swallows a failing `start()` with
  `catch (\RuntimeException) { return; }` and never reaches `Bootstrap`'s catch,
  which only ever sees the `default => throw`.
- **Step** Collect per-server failures and report them all rather than throwing out
  of the loop; report a malformed or wrong-shaped config file explicitly. For (b),
  either enforce deny patterns on the unrestricted arm too or state plainly in the
  docblock that they are preset-only — do not leave a security-shaped option that
  silently does nothing on the live path.
- **Related** C3's fix round B is correcting the docblock that currently asserts the
  opposite of (c).

---

### E42 — `mcp__` wire names: `__` is both delimiter and legal character, and user permission rules cannot match

- **What** Two naming defects in `McpToolBridge`'s `mcp__<server>__<tool>` scheme.
  (a) `__` is the delimiter AND a legal character, so server `a__b` + tool `c` and
  server `a` + tool `b__c` both yield `mcp__a__b__c` with no substitution at all —
  the docblock attributes collisions solely to "two servers whose keys differ only
  in a substituted character". (b) A user writing a permission rule against the REAL
  name — `mcp__github.com/foo__*` for the `.mcp.json` key `github.com/foo` — never
  matches the sanitised wire name `mcp__github_com_foo__…`, so the tool stays behind
  an Ask forever, and nothing surfaces the sanitised name to the user.
- **Where** `sugar-crush/src/Tools/McpToolBridge.php` (`sanitize()`, `name()`).
- **Severity** Low. (b) is the more annoying half in daily use.
- **Evidence** Bundle C3's reviewer, by construction for (a) and by rule-matching
  for (b).
- **Step** For (a), escape or reject `__` inside a segment. For (b), surface the
  wire name where a user would look — `/mcp`, `--help`, or the tool listing — so a
  permission rule can be written against something that exists.
- **Related** E41(b); C3's mis-routing fix (a bridge now calls
  `callTool($serverName, …)` rather than `callToolByName()`, so a wire-name
  collision no longer mis-routes, it only makes the second tool unaddressable).

---

### E43 — code blocks and tables are reflowed because nothing can scroll them horizontally

- **What** Bundle W1 makes the transcript honour the pane width, which it must —
  a row wider than the terminal breaks candy-core's one-logical-line-per-row model
  and every row painted after it lands at a stale absolute coordinate. But
  `candy-shine` deliberately never wraps code blocks or tables ("they have their
  own width semantics", `candy-shine/src/Renderer.php:175-176`), so W1's
  frame-level net is what handles them, and reflowing a code block is a
  compromise: it preserves every byte and damages the shape, which for aligned
  code or a wide table is exactly the information the reader wanted. The real
  answer is horizontal scrolling (or a per-block "expand" the way tool results
  already have one), not reflow.
- **Where** `sugar-crush/src/Renderer.php` — `renderHistory()` and whatever W1
  lands as the width net; `candy-shine/src/Renderer.php` for why the wrap does
  not cover these two block types.
- **Severity** Not security. A readability regression against the alternative of
  a corrupted frame, so the compromise is the right way round — but it is a
  compromise, and it should not be filed as finished.
- **Evidence** Measured before W1: with word wrap ON at 94 columns, a fenced code
  block containing a 150-character line still renders one row 150 columns wide.
- **Step** A horizontal offset for code/table blocks driven by the existing
  expand/collapse keys, or a per-block toggle between reflow and clip. Whichever
  ships, the width invariant W1 establishes must keep holding for every state.
- **Blocked on** W1 landing first — the invariant is the floor this builds on.
- **Related** The `renderDiff()` precedent (`Width::truncate` for diff rows) is
  the same trade made the other way, and is defensible there because a
  horizontal cut in a diff row reads naturally.

---

### E44 — a `composer update` silently moves the suite off the monorepo, and the only signal is a skip count

- **What** `sugar-crush/vendor/sugarcraft/*` is symlinked to the sibling libs when
  path repos are injected, and replaced with real Packagist directories by any
  `composer install`/`update` that runs without the injection. When that happens
  the suite stops testing the monorepo's own `candy-*` and starts testing
  published copies **with no failure and no warning** — the sole observable
  difference is `Skipped: 1` becoming `Skipped: 2`, because
  `GitignoreAwarenessTest::testTheMonorepoPathRepoSymlinksAreNotFollowed`
  self-skips on the absence of symlinks. A self-skip is not a signal; it is the
  absence of one.
- **Where** `sugar-crush/tests/Tools/BuiltIn/GitignoreAwarenessTest.php:255`
  (the `markTestSkipped`), `tools/check-path-repos.php`, `CONTRIBUTING.md`'s
  local-wiring loop.
- **Severity** Not security. It costs correctness of *verification*, which is
  worse than it sounds: every "green, exit 0" in this chain means one of two
  different things depending on a state nobody was watching.
- **Evidence** Happened during bundle C3's fix rounds. Same code measured
  **7387 / 76811 / 2** against Packagist copies and **7387 / 76813 / 1** against
  local symlinks. It also left an unrelated third-party bump (aws-sdk 3.390.4 →
  3.393.1 and others) in the tracked root `composer.lock`, which would have
  ridden along inside a feature commit had it not been noticed and reverted.
- **Step** Make the mode explicit rather than inferable: have the suite assert
  which wiring it is running under (both modes legitimate, neither silent) so the
  banner says so, and state it in the resume/contributing docs as a pre-verify
  check. The cycle guard that currently self-skips should keep its skip for
  genuine Packagist installs, but the SUITE should not be able to change what it
  tests without saying so.
- **Blocked on** Nothing.
- **Related** The house rule that per-lib `composer.lock` is never committed, and
  that CI injects path repos before every install — this is the same hazard on the
  developer's side of that boundary.

---

### E45 — two leftovers from C3's gate, both reported by the fix round and deliberately not changed

- **What** (a) `Bootstrap::mcpClient()`'s untrusted branch is guarded
  `$canonicalRoot === false || !projectMcpIsTrusted(…)`, and the `false` arm is
  **unreachable** at that point, because `is_file()` has already succeeded on a
  path composed from `$canonicalRoot`. It is structurally the same as the dead
  `stdClass` clause deleted for finding 15 — except this dead arm is the
  fail-CLOSED direction on a security gate. **Decision taken: keep it**, because a
  later reader deleting an unreachable guard "because it is unreachable" is how a
  gate acquires a hole, and the cost of keeping it is one branch. What is missing
  is the docblock saying that on purpose. (b) Neither refusal branch writes
  `$mcpClients`, so an untrusted or out-of-tree root re-stats and re-checks trust
  on every `tools()` call, while the memo's docblock reads as if every outcome is
  cached.
- **Where** `sugar-crush/src/Cli/Bootstrap.php` — `mcpClient()` and the
  `$mcpClients` docblock.
- **Severity** Neither is a hole. (a) is a documentation gap on a security gate,
  which is the kind that gets "cleaned up" later. (b) is idempotent and the notice
  is deduped.
- **Evidence** Bundle C3 fix round B, reported and left alone by instruction.
- **Step** Document (a) as deliberate belt-and-braces at the branch. For (b),
  correct the memo docblock's scope rather than the behaviour — caching a refusal
  would mean a mid-session grant could not take effect, and the freeze already
  makes that a non-goal.
- **Blocked on** Nothing.

---

## Contradictions found between sources

1. **The worklog contradicts itself about lane D F3–F7.** The queue at
   `docs/plans/crush_code_worklog.md:5608` lists *"lane D F3–F7 follow-ups"* as
   still owed, in the same session whose commit table (`:5504-5508`) lists
   `dad90b18` — whose commit message says *"F3-F7 in the same round"* and whose
   changes are verifiable in source at HEAD. **Resolution: the commit is right,
   the queue line is stale.** Only the `C:x` residual (§A1) and the
   `.running/*.json` residual (§A2) survive from that group. Recorded rather than
   silently dropped because the supervisor's running list inherited the stale
   line.
2. **Tracker numbers #83 and #85 each denote two different things.** `#83` is
   *"`README.md:42` advertises 4,337 tests / 12,587 assertions"* at `:2017`
   (itself later corrected: *"#83 corrected — stale test counts are at
   `README.md:377`, not `:42`"*, `:2277`) and *"`Ctrl+P`/`Ctrl+K` opens a
   hosted-Chat palette"* at `:3463`. `#85` is the palette item at `:2500` and
   *"`Chat.php:3823`'s 'Stages completed'"* at `:3963`. The worklog already warns
   about this class of error at `:3430`: *"CAUTION on IDs: an earlier draft of
   this section cited tracker numbers that do not exist."* **Both meanings are
   carried above** (§E8 and §E12) with their content rather than their number,
   which is the only unambiguous handle.
3. **`Agent::fromPreset()`'s dropped-field set has three different values.** The
   worklog says seven fields, the code docblock says five, and the constructor
   measured at HEAD gives eight behavioural fields plus two more. See §A6 — the
   measured set is the one to use, and the disagreement is itself the argument
   for making the drop assertion-backed.
4. **`KEY_HELP_COLS` "says 58".** The constant reads **64**; the **58** is a
   docblock claim about the widest declared row. The worklog's shorthand attaches
   a number to the wrong subject — the exact defect class this chain tracked for
   fifteen rounds. See §D11.
5. **`crush_code.md` and HEAD disagree on three closures** —
   `TerminalBackground::observe()` (wired), the `Write` tool (registered), and
   Phase 2 item 6 / `ForeignSkillDiscovery` (wired since `d1e0f2b1`; `15a2e605`
   added the missing **proof**, and recorded that *"the plan's line numbers are
   stale by several commits"*). `crush_code.md` is untracked and hand-maintained;
   HEAD wins.

---

## Unresolved references — found but not pinned down

Recorded so they are not lost when the worklog stops being read.

- **Tracker #86** (§E9). The `README.md:272` half of lane D round 5's F-3 could
  not be matched to a claim at HEAD, and lane D F-3 as written up
  (*"the wiring test cannot catch a recurrence of the bug it was written for"*)
  does not obviously have a README half. Reconstruct from the worklog's
  "Lane D round-5 review" section (`:3767`) before spending time on it.
- **Tracker #61's exact test file** (§E13). The review named the finding, not the
  file. Two files at HEAD carry unsearchable-directory fixtures
  (`tests/Cli/BootstrapUserConfigTest.php`,
  `tests/Cli/BootstrapPermissionGateTest.php`); which pair of assertions was meant
  was not established.
- **`HookManager::applyPreHooks()`** (§E16). Flagged in round 3, never queued,
  never given a tracker, and plausibly closed by `df0a563b` without anyone saying
  so. **UNVERIFIED** in both directions.
- **Trackers #58 and #59** (Bedrock streaming discarding its connect bound; a
  stale `ProviderConnectTimeoutTest` exemption). Both **appear closed** at HEAD —
  `src/Providers/BedrockProvider.php:82` sets `connect_timeout` from
  `connectTimeoutSeconds()`, and `tests/Providers/ProviderConnectTimeoutTest.php`
  now carries a self-checking exemption list (`:422-423` asserts every exemption
  names a file that still exists). Not carried as backlog items; noted here in
  case the streaming path (`BedrockProvider::completeStream()`, `:201`) turns out
  to build its own client.
- **Tracker #87** (lane D round 5's F-1, the live `AgentPresetRegistry` escape).
  Filed at worklog `:3967` and never mentioned again by number; the commit
  `b35c0f2d` (*"lane D round 6 — the agent-preset escape, and a corpus that could
  not see it"*) almost certainly closed it. Treated as closed; verify by number if
  anyone reopens the tracker list.
- **`#63`'s follow-up** — *"the timer bounds computing, not thrashing"*
  (worklog `:4863`). `enforceTimeLimit` landed in `4dbf5074`; the follow-up
  section was not read in full for this compilation.
### E46 — candy-core: `Width::of()` and `Width::wrapAnsi()` disagree on grapheme clusters

**What.** The two measure the same string differently, so any caller that DECIDES with one and FITS with
the other has a bound that is false. `Width::of()` counts `👍🏽` (emoji + skin-tone modifier) as **4**
cells while `wrapAnsi()` counts it as 2; `wrapAnsi()` counts a regional-indicator codepoint as **0**
while `Width::of()` counts the pair as 2. Measured by W1's reviewer:

    flag    Width::of=2  wrapAnsi(x10,@10) rows=1 widths=[20]
    skin    Width::of=4  wrapAnsi(x10,@10) rows=2 widths=[20,20]

`wrapAnsi()` also splits regional-indicator **pairs**, so a flag renders as two letter-boxes. No cluster
is deleted — every case round-tripped byte-identically.

**Where.** `candy-core/src/Util/Width.php:57-89` (`compute()`) vs `:264+` (`wrapAnsi()`).

**Severity.** Medium, and wider than sugar-crush — every candy-* consumer that wraps user or model text
inherits it. For 40 `🇺🇸` on a 40-column pane the real terminal overflow was measured at 80 columns.

**Evidence.** W1's review, probe `/tmp/…/scratchpad/w1r/p2.php` and `p9.php`.

**ROOT CAUSE, corrected — my first framing of this entry blamed `wrapAnsi()` and that is not where it
starts.** There are **two cluster accountings**, and *either* can be the wrong one:

- `Width::of()`/`compute()` wants `grapheme_str_split()`, and **that function does not exist on PHP
  8.3** — it is 8.4+. Verified on this machine: `extension_loaded('intl')` is **true** while
  `function_exists('grapheme_str_split')` is **false** on PHP 8.3.6. So `of()` falls back to
  one-codepoint-per-cluster.
- `wrapAnsi()`/`truncateAnsi()` share a `nextCluster()` scanner that clusters properly.

Hence the asymmetry: a **flag under-counts in the scanner** (2 codepoints read as 1 cluster of 1 cell
where `of()` says 2), and a **skin-tone sequence over-counts in `of()`** (4 where the scanner says 2).
This also means **the obvious fix does not work**: falling back to `truncateAnsi` when a wrapped piece
still overflows is useless, because `truncateAnsi` uses the *same* scanner — measured,
`Width::of(truncateAnsi(flags×10, 10)) == 20`. W1's fix round found this after the review round had
recommended exactly that fallback, and both my brief and the review carried the wrong remedy.

**Step.** Give `compute()` a real grapheme splitter on PHP 8.3 (the `nextCluster()` scanner is already
in the file), so both paths measure with one instrument. Add a shared fixture set both functions are
tested against — skin-tone modifier, regional-indicator pair, ZWJ family, combining mark — so the two
can never drift again. Until then, any caller that wraps with one and measures with the other must
re-measure and retry, which is what `Renderer::wrapToPane()` now does locally.

**Blocked on.** Nothing, but it is a **candy-core** change and W1 defended locally instead (re-measure
each wrapped piece and truncate any that still overflows). Fixing upstream lets that local fallback be
simplified, not removed — the fallback is also what protects against the NEXT such disagreement.

### E47 — the overlay composite path has no width discipline

**What.** W1's `fitToPane()` choke point covers `$body` only. The Veil composite that draws the palette,
the session picker and the permission prompt emits rows wider than the terminal: measured
**`palette open, cols=40 → widest=56, over rows 1..21 and 23`**.

**Where.** `sugar-crush/src/Renderer.php:1099-1114` (the Veil composite).

**Severity.** Medium. Pre-existing, not introduced by W1 — but W1 established the invariant for the
transcript, and this is the remaining hole in it. On the hosted path
`Tui/Renderer.php:394`'s `clipWidth()` still catches it, so the visible symptom is a cut overlay rather
than a corrupted frame; on the standalone `Chat::view()` path there is no such net.

**Evidence.** W1's review, probe `/tmp/…/scratchpad/w1r/p7.php`.

**Step.** Route the composite through the same fitter, or clip each overlay row to the pane. Then extend
`PaneWidthInvariantTest`'s sweep to overlay states — W1 added an honest out-of-scope note there rather
than a silent gap.

### E48 — the frame is 2 rows tall at `rows=1`

**What.** `$available = max(1, $rows - 1)` plus the always-present status bar means a 1-row terminal gets
a 2-line frame. Pre-existing.

**Where.** `sugar-crush/src/Renderer.php:1019` and `:1032`.

**Severity.** Low — a 1-row terminal is not a real configuration. Recorded because
`PaneWidthInvariantTest`'s class docblock asserted the height invariant unconditionally, and W1 bounded
that prose rather than leaving a claim its own sweep (8/20/40) would have refuted at 1.

**Evidence.** W1's review, probe `/tmp/…/scratchpad/w1r/p7.php`: `plain rows=1 → frame rows=2`.

**Step.** Either reserve the bar out of `$available` so the frame can be 1 row, or decide 2 is the floor
and say so in `renderStatusBar()`'s docblock.

### E49 — candy-shine's `withTableWrap(true)` cannot bound a table's width, and reads as if it can

**What.** `withTableWrap(true)` wraps each **cell** at the full `wrapWidth`, so a three-column table
still renders roughly three times the pane wide. Measured at `wrapWidth: 60`: cells wrap to 67/49/56/23
while the box borders stay **195** columns. The option name and its docblock read as though it solves
"table too wide", and it does not.

**Where.** `candy-shine/src/Renderer.php:916-917`.

**Severity.** Medium for readability of a coding agent's output — tables are common in replies. This is
the mechanism behind **E43**: with W1's invariant in force a wide table keeps all its data but its
border rows wrap, so glyphs land mid-row. W1 chose wrap over truncation deliberately (the user's
complaint was lost content, and truncating a table drops whole columns silently).

**Correction on the record:** the implementation round called that trade "strictly better than today".
The reviewer measured it and it is **not** — at cols=100 the header loses its right `│`, the separator
is cut mid-run, and the 195-column border rows wrap into several rows of dashes. Better on the reported
axis, **visually worse**. A trade, not an improvement.

**Step.** A per-column width budget in candy-shine's table renderer, sized to `wrapWidth`. Pairs with
E43's horizontal-scroll answer: budget the columns, and let a genuinely wide table scroll rather than
reflow.

**Blocked on.** It is a **candy-shine** change; W1 was scoped to sugar-crush.

### E50 — `SgrState` does not track SGR 58 (underline colour) or OSC 8 hyperlinks

**What.** `SgrState` handles only `Token::CSI` with `final === 'm'`, and `Ansi::reset()` (CSI 0 m) does
not close an OSC 8 hyperlink. Any row-wise re-emission of style state therefore loses underline colour
and can leave a link open.

**Where.** `candy-core/src/SgrState.php:50-53`.

**Severity.** Low for SGR 58 (rarely emitted). The OSC 8 half was **medium and live** — a wrapped link
label left a row that opens a hyperlink and never closes it, so every later cell joined the link in
iTerm2/WezTerm/VTE/Kitty. W1 fixed that **locally** in `Renderer::balanceSgr()` by tracking the open URI
itself; the general fix belongs in `SgrState`.

**Evidence.** W1's review, probe `/tmp/…/scratchpad/w1r/p10.php`.

**Step.** Extend `SgrState` to carry OSC 8 and SGR 58, then let `balanceSgr()` delegate instead of
keeping its own URI tracking.

- **The `.claude/settings.json` mystery** (worklog `:4526-4532`). The file is
  modified and uncommitted with every Caliber hook stripped out; *"Neither I nor
  any agent wrote it — lane E's reviewer independently reported the same."* Not a
  code finding, but an unexplained working-tree change that two independent
  observers logged. Left untouched, as it has been throughout.

---

## Round 33 — findings from the P3.2 / P3.5 lane (FocusRing + cell-width padding)

Recorded, not fixed: each is either outside that lane's granted file set or outside the bundle's
scope. Every figure below was re-measured in the lane before it was written down.

### E51 — mouse clicks bypass the permission modal (`Chat::handleMouse()` has no modal guard)

**What.** `Chat::update()` dispatches `MouseMsg` to `handleMouse()` **before** its
`if (!$msg instanceof KeyMsg) { return [$this, null]; }` early return. Every modal guard in the class —
the `pendingPermission !== null` arm and the in-flight swallows — sits *after* that return and is
therefore on the **keyboard path only**. `handleMouse()` itself opens on a wheel check and a
left-button check and consults no modal state at all. So while a permission prompt is up and the
keyboard is correctly captured, the mouse is not.

**Where.** `sugar-crush/src/Chat.php:1108` (the `MouseMsg` dispatch), `:1110` (the non-`KeyMsg`
return the guards hide behind), `:1194` (the `pendingPermission` guard), and `:3555`
(`handleMouse()`, unguarded). Tool-call zones are dispatched at `:3639` into
`toggleToolOutput()` (`:4174`).

**Which zones actually survive the modal — correcting the record.** The implementation round reported
this as a **`pane:files` zone surviving at row=1, col=1..7**. That is wrong on every part, and the
correction matters more than the original claim:

- **There is no `pane:files` zone anywhere in the product.** `markPane()`
  (`sugar-crush/src/Renderer.php:1684`) is the sole producer of the `pane:` prefix
  (`PANE_ZONE_PREFIX`, `:404`), and it is reached with exactly two panes: `Pane::Menu`, directly at
  `:1253`, and `Pane::Agents`, via `markPaneHeader()` at `:1575` which calls `markPane()` at `:1793`.
  The string `pane:files` occurs once in the whole tree, at `sugar-crush/tests/PaneClickTest.php:252`,
  where it is a **hand-written synthetic fixture** fed straight to `Renderer::scanner()->scan()`. It
  is almost certainly where the original report came from — a test fixture read as a live render.
- **`pane:menu` is destroyed by the modal, not preserved.** It was reported as surviving; it does not.
- **What genuinely survives are the `toolcall:<id>` zones** (`TOOL_CALL_ZONE_PREFIX`,
  `sugar-crush/src/Renderer.php:424`), one per rendered tool call in the transcript.

**On coordinates.** The reviewer's enumeration observed the surviving zones at **rows 5/8/11/14,
cols 1..30** under its own fixture. Those numbers are *fixture-dependent* — row positions follow
transcript content and column extent follows the label — and are recorded here as that reviewer's
measurement, **not re-derived in this lane**. The load-bearing, encoding-independent fact is the
zone **id prefix** (`toolcall:`) and the dispatch site (`:3639`), both verified here.

**Severity — UX / correctness, NOT security.** Stated deliberately against the implementation round,
which called it security-relevant. The only reachable action behind a surviving zone is
`toggleToolOutput()`: expand or collapse an already-completed tool call in the scrollback. It **cannot
answer, dismiss, grant, deny or bypass the permission prompt** — no zone that survives the modal
dispatches into `handlePermissionKey()` or the deferred resolution. Calling it a permission bypass is
not supported by measurement. What it *is*: the modal is advertised as capturing input, and it does
not capture the mouse, so the transcript mutates under a prompt that claims to own the screen.

**Step.** Guard `handleMouse()` on the same modal state the keyboard path checks — earliest correct
point is a `pendingPermission !== null` check at the top of `handleMouse()`
(`Chat.php:3555`), matching the arm at `:1194`. Decide explicitly whether a wheel *scroll* should
remain allowed under a modal (reading the transcript while deciding is legitimate); a click dispatch
should not.

**Blocked on.** `Chat.php` was outside the P3.2/P3.5 lane's granted file set and is held by another
lane. Not attempted.

🔴 **ROUND 37 SCOUT — TWO OF THIS ENTRY'S OWN CORRECTIONS ARE THEMSELVES WRONG, AND EVERY LINE
NUMBER ABOVE IS STALE.** Measured by execution against master `4a4ecb98`.

**Stale line numbers.** `Chat.php`: dispatch `:1108`→**`:1123`**, non-`KeyMsg` return `:1110`→**`:1126`**,
`pendingPermission` guard `:1194`→**`:1209`**, `handleMouse()` `:3555`→**`:3570`**, toolcall dispatch
`:3639`→**`:3653`**, `toggleToolOutput()` `:4174`→**`:4189`**. `Renderer.php`: `PANE_ZONE_PREFIX`
`:404`→**`:467`**, `TOOL_CALL_ZONE_PREFIX` `:424`→**`:487`**, `markPane()` `:1684`→**`:1747`**,
`markPaneHeader()` `:1575`→**`:1853`**. The `Pane::Menu` marking cited at `:1253` is inside
`renderStatusBar()`, now at **`:1243`**.

**FALSE: "`pane:menu` is destroyed by the modal, not preserved."** It is destroyed by **`inFlight`**.
Measured across all four states:

| state | surviving zones |
|---|---|
| modal only | `toolcall:call_1`, **`pane:menu`** |
| modal + `inFlight` | `toolcall:call_1` |
| `inFlight` only | `toolcall:call_1` |
| neither | `toolcall:call_1`, `pane:menu` |

This is the recurring defect appearing **inside a note written to correct the recurring defect** — a
fact true of one thing (`inFlight`) written next to a different thing (the modal). And
prompt-up-and-idle is not a hypothetical state: `Chat.php:1155-1162` argues at length that `update()`
itself produces it, because the `AssistantMsg` arm writes `'inFlight' => false` without clearing
`pendingPermission`.

**FALSE: "The only reachable action behind a surviving zone is `toggleToolOutput()`."** A `pane:menu`
click under a live prompt **opens the command palette** — confirmed by execution, and the keyboard
equivalent under the identical state is correctly refused:

```
palette AFTER mouse click under modal:   true
palette after Ctrl+P (keyboard) under modal: false
```

The palette overlay does not render in that frame, so no `palette:` item zones are reachable to chain
further — but the state is set, and it pops open the instant the prompt is answered. **The severity
verdict above survives** (it still cannot grant, deny or dismiss the permission, so "permission
bypass" remains the wrong label) — but the hole is strictly larger than recorded, and the real finding
is sharper than either label: **the two input paths give opposite answers to the same request.**

**Step, restated.** Guard at the top of `handleMouse()` (**`:3570`**) on `pendingPermission !== null`
— and on `keyHelp !== null`, which the original step omits. The wheel-scroll question above still
stands and is still a deliberate decision.

### E52 — `CSI 1;5Z` loses the shift its final byte encodes

**What.** In `InputReader::decodeCsi()` the post-match rebuild replaces the **whole** `KeyMsg` from
`$mods` whenever a `;<mod>` parameter was present. The new `'Z'` arm sets `shift: true`, but for a
parameterised backtab the rebuild overwrites it wholesale. `CSI 1;2Z` is fine (the parameter encodes
shift, so the rebuild re-derives the same flag), and that is the only case the arm's docblock used to
discuss. `CSI 1;5Z` — ctrl *without* the shift bit — is rebuilt as plain `ctrl+tab`, dropping the
shift that the `Z` final byte is the entire meaning of.

**Where.** `candy-core/src/InputReader.php`, the `'Z'` arm and the `if ($key !== null && $mods !== null)`
rebuild immediately below the key table.

**Severity.** Low. No terminal in the xterm family is known to emit `CSI 1;5Z`; a fix would be guessing
at a shape nothing sends. Recorded so the next reader does not have to re-derive it. The arm's
docblock has been corrected in-place to name this limit rather than imply the parameterised form
always works.

**Step.** If it ever needs fixing: make the rebuild merge rather than replace, or special-case a `Z`
final byte to OR the shift flag in. Merging is the more general fix and would want its own test sweep
across every modified key, so it is not a drive-by.

**ROUND 37 SCOUT — STILL OPEN, confirmed by decoding.** `\e[Z`→shift=1 and `\e[1;2Z`→shift=1, but
**`\e[1;5Z`→ctrl=1, shift=0**: the `;<mod>` rebuild in `candy-core/src/InputReader.php` replaces the
`KeyMsg` wholesale and drops the shift the `Z` final byte encodes. Nothing in this tree emits
`CSI 1;5Z`, so this stays recorded rather than scheduled.

**ROUND 41 — FIXED (`ae30fee5`), AND THIS ENTRY UNDERSTATED ITS OWN SIZE.** Everything above is
accurate and the heading is too narrow: it is the whole **shift-bit-clear family**, not one shape.
Measured before the fix, at `ae30fee5`'s parent: `ESC[1;3Z` → `alt+tab`, `ESC[1;5Z` → `ctrl+tab`,
`ESC[1;7Z` → `ctrl+alt+tab` — every xterm modifier whose shift bit is clear (the odd values, since
mod = 1 + bitmask(shift=1, alt=2, ctrl=4)). **A fix scoped to the literal `1;5` this entry names would
have left two thirds of the family broken**, and a mutation in `InputReaderTest` now demonstrates
exactly that: constraining the OR to `ctrl && !alt` kills mod 3 and mod 7 and nothing else.

**The Step recorded above was right about the mechanism and right about which option to take.** The
rebuild in `decodeCsi()` now **ORs** rather than assigns:
`alt: $mods->alt || $key->alt` and the same for ctrl and shift. The entry called merging "the more
general fix" that "would want its own test sweep across every modified key, so it is not a drive-by" —
that sweep is the second data provider added (`unshiftedModifiedKeyProvider`: ctrl+Up, ctrl+Down,
alt+Right, ctrl+Home, ctrl+Tab, ctrl+F5), and it is what the merge needed, because the danger of an OR
is inventing a modifier rather than dropping one. **`'Z'` is the only arm in the key table that sets a
flag of its own**, so for every other final byte the OR is provably identical to the assignment it
replaced — which is what makes this a small change rather than a sweep.

The load-bearing pair is `ESC[1;5I` vs `ESC[1;5Z`: the SAME modifier parameter must yield an unshifted
tab for one final byte and a shifted one for the other, and before the fix both came back `ctrl+tab`.
A rebuild that reads only the parameter cannot tell them apart. Pinned by
`testTheSameModifierParameterDivergesOnTheFinalByte`.

**The declined-on-emitter-grounds argument has been rewritten in place, not deleted** (per the standing
rule): the `'Z'` arm's comment now records what it used to say, that the reasoning was right about the
emitter and wrong about the size, and why the merge is the general fix.
candy-core: 795 tests / 7181 assertions / 25 skipped / rc 0 (from 785 / 7134).

### E53 — fast and slow width paths diverge on ZWJ sequences

**What.** `AgentViewPane::visualWidth()` delegates to `Width::string()` and is **grapheme-aware over a
whole string**, while `AgentViewPane::truncate()` still walks `preg_split('//u')` and sums
`charWidth()` **one codepoint at a time**. The two disagree on any ZWJ sequence. Measured in this
lane: the family emoji `U+1F468 ZWJ U+1F469 ZWJ U+1F467` is **2 cells** whole-string and **6** summed
per codepoint.

**Where.** `sugar-crush/src/Tui/AgentViewPane.php` — `truncate()` and `visualWidth()`/`charWidth()`.

**Severity.** Low. **CORRECTED IN ROUND 38 — the bound this paragraph rested on is not there.** It
read: the per-codepoint sum is the **larger** of the two, so the loop over-truncates and **cannot**
emit a row wider than its budget. True of most inputs, false in general. `Width::string()` charges
**+2** for `<emoji> ZWJ` — it credits the emoji its ZWJ state machine skipped — where the
per-codepoint sum charges `1 + 0`, so on those inputs the WHOLE-string measure is the larger and
`truncate()`'s `$visualWidth <= $maxWidth` early return hands an over-wide string straight back.
Measured at `087a3179`: `truncate(U+1F1E6 U+11A8 U+2764 ZWJ U+1F1F8, 4)` returns **5 cells** for a
4-cell budget, and 400,000 fuzzed calls over an emoji-heavy alphabet produced **727 over-runs**
(worst +2) against **0** for the cluster loop that replaced it. So this was an over-run — the failure
mode this project treats as broken functionality, because the diff renderer paints one line per
terminal row — and not only a dangling-joiner hazard. It was filed Low on the strength of a bound that
did not hold.

**Step.** Make the truncation loop iterate graphemes rather than codepoints, so both paths answer the
same measure. The `visualWidth()` docblock has been softened in-place — it previously claimed the
truncator and the pad "answer to ONE width authority", which is stronger than the code delivers; it
now says they read the same width *table* and names this divergence.

**ROUND 37 SCOUT — STILL OPEN, and it carries a hazard this entry does not record.** Divergence
confirmed: `visualWidth(<family emoji>)` = 2 while summing `charWidth()` per codepoint = 6.
`AgentViewPane::truncate()` (`:171`) walks `preg_split('//u')` summing `charWidth()` (`:232`) while
`visualWidth()` (`:224`) is grapheme-aware. The truncation direction is **safe** — it under-fills and
never over-runs. But at budget 4 the truncator emitted `U+1F468 ZWJ …`: a **dangling ZWJ**, which is a
rendering hazard in its own right, not merely over-truncation.

**ROUND 38 — FIXED, with one scout figure corrected.** `AgentViewPane::truncate()` now steps over a
new private `clusters()` and measures each unit with `visualWidth()`, so both paths ask
`Width::string()` and a ZWJ sequence is taken whole or dropped whole. `clusters()` joins four things
to the unit before them — anything after a ZWJ, any zero-width codepoint, a skin-tone modifier
(U+1F3FB..U+1F3FF) and a regional indicator following exactly one other — over `preg_split('//u')`,
which needs no extension: `grapheme_str_split()` is PHP 8.4+ and this tree is 8.3, and `intl` is not a
declared dependency of sugar-crush or candy-core, so branching on it would give the truncator two
behaviours. It is not full UAX #29 and the docblock says so: Hangul syllables and Indic conjuncts are
still split at codepoint boundaries. What that costs is version-dependent, and this stamp now splits
it the way the shipped `visualWidth()` docblock does — an earlier draft of this paragraph said
`Width::string()` "scores those additively either way", which is true of 8.3 only and is exactly the
half the code comment was corrected away from. On **PHP 8.3** — this tree — `Width` splits with
`mb_str_split()`, one codepoint per cluster, so the whole-string measure IS the per-codepoint sum for
anything without a ZWJ in it: measured, jamo-spelled `L+V+T` is 4 either way and a Devanagari `kṣi`
conjunct 4 either way, so the split costs the glyph and not a cell. On **PHP 8.4** `Width` would split
with `grapheme_str_split()` and score the cluster by its first codepoint: measured against a simulated
8.4 path, `L+V+T` is **2** whole against **4** summed here, and `kṣi` **1** against **4**. On 8.4 the
split therefore DOES cost cells — in the over-truncating direction, never the over-running one.

*Corrected figure.* The scout's "at budget 4 the truncator emitted `U+1F468 ZWJ …`" does NOT
reproduce on the bare family emoji: it is 2 cells whole-string, so `truncate()`'s
`$visualWidth <= $maxWidth` early return hands it back verbatim and the loop never runs. The dangling
ZWJ needs trailing context, so the whole string exceeds the budget — measured,
`truncate(FAMILY . 'cde', 4)` returned `U+1F468 ZWJ …` (3 cells), and after the fix returns
`FAMILY . 'c' . '…'` (4 cells). Stated without the context the reproduction is a fixture shaped like
the property rather than like the bug, and it passes before the fix as well as after. The regression
test uses the context.

The `visualWidth()` docblock, softened in an earlier round to *name* the divergence, has been turned
back the other way now the divergence is closed — it would otherwise understate the code, which is
this project's recurring defect with the sign flipped. `charWidth()` is not deleted: `truncate()` no
longer sums it, but `joinsPrevious()` reads it for the one bit it needs (does this codepoint own a
cell), and its docblock now says so.

### E54 — `AgentViewPane::render($w)` over-runs its caller's budget below 44 columns

**What.** `render(..., $w, ...)` returns rows of **`$w + 4`** cells: `$w` is handed to `Style::width()`,
which sizes the **content box**, and the rounded border (2 cells) plus `padding(0, 1)` (2 cells) are
drawn outside it. Measured in this lane across `$w` = 20/28/40/58/60/98, populated and empty-list alike
— the `+4` holds in every case. Its two callers do **not** both compensate:

- `Renderer::renderAgentView()` (`sugar-crush/src/Renderer.php:1630` — was `:1567`, 63 lines stale) passes `max(40, $cols - 4)` —
  **compensates exactly**, at 44 columns and above. Below 44 the `max(40, …)` clamp floors the width
  and the rows run wider than the terminal.
- `AgentDashboardPane::render()` (`sugar-crush/src/Tui/Components/AgentDashboardPane.php:193` — was `:194`) passes
  `max(20, $width - 2)` — **does not compensate at all**. Measured, *both* of its paths over-run by
  exactly 2: the empty-list `AgentViewPane::render()` call at `:202`, and the `box()` frame at `:205`,
  which repeats the same `border + padding(0,1) + width($inner)` geometry. Pane width 30 → 32,
  60 → 62, 100 → 102.

**Severity.** Low — real but currently unobservable. Every dashboard frame goes out through
`clipWidth(clipTail(...), $cols)` in `sugar-crush/src/Tui/Renderer.php` (the `$frame` assignment around
`sugar-crush/src/Tui/Renderer.php:490` **and `:561` — there are TWO of them, not one, and neither is
at the "around `:464`" this entry originally cited), which trims the excess before the diff renderer
sees it. The backstop is what makes this a
documentation-grade finding today; it is also what would hide a real regression if the arithmetic
drifted further.

**Step.** Make the `+4` overhead explicit in `AgentViewPane`'s signature — either take an *outside*
width and subtract internally, or expose a `chromeWidth()` constant both callers subtract — so the two
call sites cannot disagree. Pre-existing; predates the P3.2/P3.5 bundle and out of its scope.

---

## Round 34 — findings from the P8.9 lane (Grep's announce-once pair)

Recorded, not fixed: both are pre-existing shapes in tools outside that lane's bundle, surfaced by
wiring `Grep` for the same pair. Every byte figure below was re-measured in the lane before it was
written down.

**ROUND 37 SCOUT — STILL OPEN.** The `+4` is invariant, not a below-44 edge case: measured at
w=20/28/30/40/43/44/58/60/80/98, populated and empty, `Width::string()` of the widest row is **`$w + 4`
in every case**. The rounded border (2) plus `padding(0,1)` (2) are drawn outside the box
`Style::width()` sizes. `AgentDashboardPane` lands at `$width + 2` on both of its paths (`:193` `$inner`,
fed to `AgentViewPane::render()` at `:202` and to `box()` at `:205`). Only the `clipWidth(clipTail(...))`
backstop keeps it off the screen.

**ROUND 38 — PARTIALLY FIXED. Every scout figure above re-measured and confirmed; none was stale.**
Which half is closed, plainly: the **Step** this entry records is discharged — the `+4` overhead is
named once and both callers subtract it through one function, so they can no longer disagree, and the
dashboard's real 2-cell over-run (30 → 32, 60 → 62, 100 → 102) is gone. The **heading's condition is
not**: `AgentViewPane::render($w)` still over-runs an outside budget below 44 columns, because the
`max(40, …)` floor in `Renderer::renderAgentView()` is deliberately kept (see *What did NOT change*
below). Read the heading as still open and the Step as done.

`Renderer.php:1630`, `AgentDashboardPane.php` `:193`/`:202`/`:205` and the two
`clipWidth(clipTail(...), $cols)` `$frame` assignments at `src/Tui/Renderer.php:490` and `:561` are all
where the entry says they are.

*Correction to the scout figure, and to the first draft of this stamp.* The `+4` was written up as an
invariant. It is not one; it is a **domain**. It holds whenever the composed row body fits `$width`,
and every measurement behind the "invariant" wording used an **ASCII** operation — which is exactly
the case that always fits, because ASCII truncates to whole cells and wraps on cell boundaries. Put a
wide cluster in the operation and 6 of those 10 widths broke: with a skin-toned thumb (U+1F44D
U+1F3FD) or a flag (U+1F1E6 U+1F1F8) and a 3-cell agent name, `render()` returned `$w + 6` at
w=**20/28/30/40/43/44** (26/34/36/46/49/50 against 24/32/34/44/47/48 due) and `$w + 4` at
w=58/60/80/98. The cause was `render()`'s `$opBudget = max(5, $width - name - 60)` floor, it was
**pre-existing** — the identical ten numbers came out of `087a3179`, so it was not a regression of
this change — and it was recorded separately as **E64**. *Past tense as of the ROUND 39 `lsp` lane:
**E64 is fixed**, `render()` now clamps the composed label to the cells left after the metrics, and
the ten numbers above are 24/32/34/44/47/48/62/64/84/102 — `$w + 4` at every one.
`AgentViewPaneGeometryTest` asserts the wide-cluster bound (now
`testAWideClusterOperationNoLongerOverrunsTheChromeGeometryAtTheOperationFloor`) rather than sweeping
only the ASCII fixture that made the invariant look true. *This sentence used to add "which keeps the
six broken numbers in-test and asserts they are gone", and that was false — corrected in round 39.*
The guard doing the "asserts they are gone" was `assertNotSame($beforeE64[$w], $widest)` placed after
`assertSame($w + CHROME_WIDTH, $widest)`; once the first passes, `$widest` **is** `$w + 4`, so the
second could not fail on the six widths where the two differ and was skipped on the other four.
Twelve assertions that could never fire. The six numbers are kept as documentation — carried into the
assertion's failure message — because a suite holding only the fixed pane cannot re-derive what the
broken one returned. What the test actually earns is driving the `+4` bound over the wide-cluster
payloads the ASCII sweep cannot reach. **This does not close E54's heading**: the `max(40, …)` floor in
`Renderer::renderAgentView()` is still deliberately kept, so below 44 columns the strip is still wider
than the terminal and `clipWidth()` is still the backstop for it.*

The overhead is now `AgentViewPane::CHROME_WIDTH` (a public const, 4) and the subtraction is
`AgentViewPane::contentWidth($outerWidth, $minimum)`. `render()`'s `$width` parameter keeps its
meaning — it is still a CONTENT width — because changing it to an outside width would have moved the
`+4` that `CellWidthPaddingTest::testEveryAgentRowIsTheSameCellWidthWhateverTheEncoding()` exists to
pin, and that relationship is worth keeping pinned. `Renderer::renderAgentView()` now calls
`contentWidth($cols, 40)`, which is `max(40, $cols - 4)` unchanged; `AgentDashboardPane::render()`
calls `contentWidth($width, 20)`, which is the actual fix — it was `max(20, $width - 2)`.

*What did NOT change, deliberately.* The floors stay. Below `minimum + CHROME_WIDTH` columns the floor
still wins and the rows are still wider than the terminal — for `renderAgentView` that is still under
44 columns — because a pane cannot be both at least `$minimum` wide and narrower than the terminal.
`clipWidth()` remains the backstop for that case and `contentWidth()`'s docblock says so rather than
implying the over-run is gone.

*Byte-identity, proved not asserted.* `Renderer::renderAgentView()`'s output was captured from the
pre-change tree at 44/60/80/120 columns and is pinned base64 in
`tests/Tui/AgentViewPaneGeometryTest`. That test is the one new test that passes BEFORE the change as
well as after — correctly, since its job is to catch a change, not to reproduce a bug. The other six
all fail before and pass after. *ROUND 39: it is now
`testRenderAgentViewIsByteIdenticalAtAndAbove60Columns()`, and the rename is the finding. 44 columns
is `contentWidth(44, 40)` = a content width of **40**, which is inside the band E64's operation floor
was breaking — so fixing E64 moved the 44-column capture and only that one. 60/80/120 are still
byte-identical to the pre-CHROME_WIDTH tree. The 44 capture is kept and is the BEFORE half of
`testRenderAgentViewAt44ColumnsMovedExactlyOnceAndThatWasE64()`, which asserts both that the strip is
no longer those bytes and that it is exactly the new ones.*

### E55 — `maxOutputBytes` no longer bounds `Grep`'s result

**What.** P8.9 gave `Grep` the announce-once pair (`InstructionFileLoader` +
`SkillPathNudge`). The instruction block is appended **after** the clip — a
deliberate, documented choice: prepending it would let a large `CLAUDE.md` win
the whole budget and push the hit list, the answer, out under the truncation
marker (`Glob` has exactly that defect; see **E56**). The cost of appending is
that the appended block is outside the cap, and `InstructionFileLoader` applies
no size limit of its own. So `maxOutputBytes` now bounds the hit list, not the
result.

**Where.** `sugar-crush/src/Tools/BuiltIn/Grep.php` — the append block after
`truncateMerged()`; `sugar-crush/src/Context/InstructionFileLoader.php`
(`loadForPath()` returns the file whole).

**Evidence — MEASURED.** A temp tree with one 6513-byte `sub/CLAUDE.md` and one
matching `sub/*.php`, `new Grep($root, 400, new InstructionFileLoader($root))`:
the result is **6700 bytes against a 400-byte cap — 16.8×**. Scales with the
instruction file, and with the number of distinct governed directories a single
search touches.

**Severity.** Low today, and NOT a new class of problem. `Read` has carried the
same shape since it was wired: at cap 200 over a 2421-byte file under that same
tree it returns **6726 bytes** with the answer head intact — the instructions
are appended outside its `$maxBytes` too. What is new is that `Grep` is the case
`TruncatesOutput`'s own docblock cites as motivating (`:18`, "a `Grep` for a
common identifier"), and it warns that a single oversized result "can exhaust
the context window outright". That bound is now defeatable by a large
`CLAUDE.md` sitting in a hit directory.

Real-world exposure on this repository is ~0: `loadForPath()` walks up to but
**excludes** `$repoRoot`, and the only `CLAUDE.md`/`AGENTS.md` below the root
live under `.caliber/backups/`. It needs a checkout that keeps large instruction
files in subdirectories.

**Step.** Give the appended block its own budget — a second cap applied to the
instruction text, independent of `maxOutputBytes`, with its own marker — and
apply it in `Read` at the same time, since both tools take the same exemption
for the same reason.

### E56 — `Glob` prepends instruction bodies BEFORE truncating, so the file list is what gets dropped

**What.** `Glob` injects each matched file's instruction body into the list and
then truncates the whole thing. With a small cap and a large `CLAUDE.md` the
body consumes the entire budget and the **matched paths — the answer the caller
asked for — are what the truncation marker reports as dropped**. The tool
returns a document the caller did not ask for and none of the filenames it did.

**Where.** `sugar-crush/src/Tools/BuiltIn/Glob.php` — the prepend happens ahead
of the `TruncatesOutput` call.

**Evidence — MEASURED.** Temp tree, `sub/CLAUDE.md` of `"BIG-RULE\n"` plus 500
padding lines, five matching `sub/m*.php`,
`new Glob($root, new InstructionFileLoader($root), [], null, 200)`: the result is
193 bytes, **0 of the 5 matched paths are listed**, the `BIG-RULE` body is
present, and the truncation marker fires. Reproduced independently by this
round's reviewer.

**Only `Glob` has this shape**, which is worth stating because it is easy to
assume it is general:

- `Grep` appends after the clip — the hits always survive (that is **E55**'s
  trade, taken deliberately).
- `Read` truncates the body first and prepends after, so the answer survives and
  the cap is simply exceeded — the **E55** shape, not this one.
- `Edit` and `Write` do not truncate at all; neither, in fact, does `Read` —
  `use TruncatesOutput;` appears in only `Bash`, `Glob`, `Grep`, `LspTool` and
  `EnvironmentBlock` (`Read` has its own inline `$maxBytes` cut). So `Glob` is
  the single tool where the instruction body is prepended into a budget the
  answer must then compete for.

**Severity.** Low, same exposure argument as **E55** — it needs an oversized
instruction file in a matched directory. But the failure mode is worse than
E55's: E55 returns too much, E56 returns the wrong thing, and the truncation
marker makes it look like a legitimately large result rather than a lost one.

**Step.** Reverse `Glob`'s order to match `Grep`'s — clip the file list to the
cap, then append the instruction block — which folds this into E55's single
"budget the appended block" fix rather than needing its own.

---

## E57 — a project-tier `disabledTools` glob can reduce the tool set to one arbitrary tool

**Found by round 34's `lsp` reviewer, proven by execution, not by reading.**

`docs/SETTINGS.md:106-107` and its source of truth
`src/Config/LayeredSettings.php:249-253` both defend the trust asymmetry —
`allowedTools` is user-tier-only, `disabledTools` is project-settable — with
this argument:

> `disabledTools` can express the same attack, but only by naming every tool it
> removes — a value you can see when you read the file.

**The argument is false.** `filterToolSet()` matches both lists through
`PermissionRule::matchesToolName()` (`src/Cli/Bootstrap.php:4266`), which is a
bare `fnmatch()` (`src/Permissions/PermissionRule.php:334`). `fnmatch` honours
negated character classes, so a glob can name a complement rather than a list.

**Evidence — MEASURED end to end** through `Bootstrap::tools()` with a project
`.sugar-crush/settings.json` of `{"disabledTools":["[!B]*"]}`:

```
surviving tools: Bash
```

Eight characters, project-tier-legal, no trust grant required, and it produces
exactly the Bash-only tool set the doc says only a whitelist could produce "in
one line".

> **RULE 7 REWRITE, round 42 fix pass — two of those clauses are now false.**
> WHAT IT USED TO SAY: "eight characters … no trust grant required".
> WHAT IS TRUE NOW: (a) the count was never right — `[!B]*` is **five**
> characters, `"[!B]*"` seven, `["[!B]*"]` nine, `{"disabledTools":["[!B]*"]}`
> twenty-seven, re-derived on PHP 8.3.6; nothing in this counterexample is
> eight. (b) A trust grant **is** required, and this was probably true when
> recorded and overtaken by the gate since. MEASURED, fresh process per row,
> PHP 8.3.6, sandboxed `HOME`, project `.sugar-crush/settings.json` =
> `{"disabledTools":["[!B]*"]}`: with **no** `trustedProjectSettings` entry all
> **11** tools survive and nothing is printed; with the entry, **1** (`Bash`)
> survives and the removal is reported on stderr and in the transcript.
> `Bootstrap::projectSettingsTrusted()` is the gate.
> WHY THIS STILL EARNS ITS PLACE: the finding itself is intact and is the
> reason `E74` exists. The tier argument really does not survive contact with
> `fnmatch()`, and the trust gate narrows the blast radius without closing it —
> an operator who trusted a repository and set no `disabledTools` of their own
> is still exposed. Do not act on the "no trust grant required" clause; act on
> the **Step** below, which is unaffected. Tier membership confirmed directly rather than from the doc:
`PROJECT_TIER_KEYS` contains `disabledTools`; `userTierOnlyKeys()` contains
`allowedTools`.

**Severity.** The blast radius is narrower than it first reads: this REMOVES
tools, so it cannot grant a capability the user did not already have, and the
sharpest version — leave only `Bash` — is a denial-of-alternatives that pushes
the model toward the one tool with the widest reach, rather than an escalation
on its own. It still matters, because the whole reason `allowedTools` is
user-tier-only is an argument that does not survive contact with `fnmatch`.

**Why it is here and not fixed.** Functionality before hardening. The FINDING is
recorded now; the FIX is deferred. Note the two false doc claims were corrected
in round 34 — this entry is the behaviour, not the prose.

**Step.** Decide the intended contract, then make one of these true:
either restrict project-tier `disabledTools` to literal names (no glob
metacharacters) and keep globs user-tier, or move `disabledTools` to
user-tier-only alongside `allowedTools` and accept the loss. Do not "fix" it by
rewording the comment a third time.

⚠️ **Whoever takes this must re-measure the `fnmatch` behaviour first.** The
claim above is that `[!B]*` survives as a project-tier value — verify that is
still true before designing around it.

---

**ROUND 39 SCOUT — was STILL OPEN when stamped; ADDRESSED in round 40, see the stamp below.
Reproduces exactly, but this entry contains a SUBSTANTIVELY FALSE claim and every line number it
cites is stale.**

🔴 **"no trust grant required" is FALSE.** `Bootstrap::projectSettingsTrusted()` gates
`LayeredSettings::projectLayer()`, and an untrusted project's `disabledTools` is discarded whole.
Measured with pattern `[!B]*`: **trusted → 1 tool survives (`Bash`); untrusted → all 11 survive.** The
operator must first have listed the checkout in `trustedProjectSettings` in their own `config.json`.
A shipped test already says so —
`tests/Cli/BootstrapToolAndPermissionSettingsTest.php::testAnUntrustedProjectCannotEvenDisableATool`.
This materially narrows the blast radius. **It is not drift: the trust gate landed in `f0585149`
(2026-08-20) and this entry was written in `a48d33a7` (2026-08-21), so the entry was wrong when recorded.**

Stale line numbers — all four: `docs/SETTINGS.md:106-107` → **`:178-195`**;
`src/Config/LayeredSettings.php:249-253` → **`:262-282`**; `src/Cli/Bootstrap.php:4266` → **`:4386`**
(`filterToolSet()` opens at `:4374`); `src/Permissions/PermissionRule.php:334` → **`:332`**.

> **All four of those corrections are AS OF ROUND 39 and are now themselves stale. Do not re-correct
> them here.** They went stale in round 40 and again in round 41 — three consecutive rounds of the same
> entry carrying wrong line numbers, which is the actual defect. See the round-41 stamp at the end of
> this entry for what replaced them; the figures above are kept unedited as the record of what the
> scout measured, not as a way to find anything.

**Severity argument SURVIVES.** `filterToolSet()` (`:4374-4402`) tests allow/deny as a single
conjunction with no later stage, so a project's `disabledTools` genuinely cannot re-admit what a user's
`allowedTools` excluded — "only ever shrinks" holds. Tier membership confirmed by execution.

**Not previously recorded:** `{"disabledTools":["*"]}` yields **0 tools** — there is no floor, and
nothing in `filterToolSet()` notices or reports handing the model an empty tool set. Fail-safe
direction, but silent.

**Doc defect found while reading:** `docs/SETTINGS.md:172` names the tool **`Doctor`**; the shipped name
is lowercase **`doctor`**. The parallel passage at `LayeredSettings.php:274-276` names only five of ten.

⚠️ A fix must preserve globs at the user tier —
`testDisabledToolsAcceptsTheSameGlobDialectAsAPermissionRule` locks the dialect in.

**ROUND 40 `sglang` — ADDRESSED, by reporting the effect rather than restricting the grammar. Read
the two narrowings below before deciding this needs more.**

Re-measured in the lane, every row confirming the round-39 scout: trusted `["[!B]*"]` → 1 tool
(`Bash`); untrusted → all 11; `["*"]` → 0 tools; the shipped tool name is lower-case `doctor`. To be
exact about what did and did not survive re-measurement: the ORIGINAL entry's central figure (`[!B]*`
leaves `Bash`) reproduced; what did not was its "no trust grant required" and all four of its line
numbers, both already corrected by the scout.

**NEITHER PROPOSED RESTRICTION WAS TAKEN, and one of them is measurably not a fix.** "Refuse negated
character classes at the project tier" closes `[!B]*` and nothing else: **`["[C-Z]*", "[a-z]*"]` uses no
negation, is barely longer, and also leaves exactly `Bash` — measured in this lane.** Shipping it would
have swapped a false claim about the dialect for a false claim about the fix. "Literal names only" does
close it, but it deletes the one use the key was admitted for (`mcp__git__*` for a checkout with no such
server) and it costs the operator a capability they granted rather than an attacker one they took.

**What shipped instead:** `Bootstrap::reportProjectTierToolRemovals()` announces a trusted project's tool
removals at launch — naming the file, the tools taken and the tools left — so the property the tiering
argument actually needed ("a value you can see when you read the file") holds for **every** spelling,
including the ones no pattern rule would catch. The capability is unchanged and deliberately so; a
control test pins that `[!B]*` still yields `['Bash']`.

**Reported by DIFFING, not by re-matching the project's patterns**, computed through a single
`Bootstrap::mergedConfig()` so there is one copy of the layer precedence. `LayeredSettings` gained
`projectKeySource()` (mirroring `permissionKeySource()`) so the report can name `settings.json` vs
`settings.local.json`.

🔴 **A SECOND NARROWING, NOT PREVIOUSLY RECORDED ANYWHERE, and it should change how this entry reads.**
`LayeredSettings::merge()` is **key-level, not a union**: a user who names ANY `disabledTools` of their
own **replaces a trusted project's list outright**. Measured: user `["Read"]` against project `["[!B]*"]`
removes exactly `Read` and leaves all ten others — the project's complement glob does nothing. Combined
with the trust gate, the gap is open only for an operator who trusted a repository **and** set no
`disabledTools` themselves. Both narrowings are now written into `docs/SETTINGS.md` and the
`PROJECT_TIER_KEYS` doc-block, and both have tests.

**The scout's un-recorded `["*"]` → 0 tools: reported, NOT refused.** `filterToolSet()`'s own shipped
doc-block already names `disabledTools: ["*"]` as the supported way to ask for a toolless agent (it is
the stated alternative to reading `allowedTools: []` that way), so a refusal would break a configuration
the code documents as intentional. The direction is fail-safe; only the silence was a defect.

**Doc defects fixed, and one was live rather than cosmetic.** `docs/PERMISSIONS.md` told users to write
permission rules against a tool named `Doctor`; matching is case-sensitive `fnmatch()` and the tool is
`doctor`, so a rule copied from that page **matched nothing and denied nothing** — verified by
executing `PermissionRule::matchesToolName('Doctor', 'doctor') === false`. Fixed in both PERMISSIONS.md
passages and in `docs/SETTINGS.md`. `LayeredSettings`' parallel passage named five of the ten tools an
`allowedTools: ["Bash"]` removes; it now names all ten. `ARCHITECTURE.md`'s `Doctor` is correct — that
list is of CLASS names.

**Coverage:** 8 new tests in `tests/Cli/BootstrapToolAndPermissionSettingsTest.php` (2 fail against the
unfixed build — verified) and 2 in `tests/Config/LayeredSettingsTest.php`. The diff-not-re-match property
and `projectKeySource()`'s tier guard were each confirmed by mutating the source and watching the
specific test go red.

**Still open, deliberately:** a trusted project can still reduce the tool set to one tool of its
choosing. If a future round decides that capability itself must go, the honest option is moving
`disabledTools` to the user tier — not a pattern-shape rule.

**ROUND 41 `sglang` — the round-40 report was invisible in an interactive launch. FIXED; the entry is
still deliberately open on the capability itself.**

🔴 **Round 40's fix worked in `-p` and nowhere a TUI user could read it.** Driven under a pty in a
trusted repo with `{"disabledTools":["[!B]*"]}`: the warning is written to stderr, the terminal enters
the alternate screen ~0.5s later and paints over it, and the primary buffer does not return until the
session ENDS. Replaying the captured byte stream through a `candy-vt` `Terminal(120, 40)` gives
`altScreen=true` and a visible screen containing neither `disabledTools` nor `sugarcrush:`. The operator
whose tool set a checkout had just cut to `Bash` was told after the Bash-only session was over — which
defeats the "a value you can see" property the whole round-40 argument rests on.

**Not a regression, and no cheap correct option existed at the time.** Before round 40 there was nothing
at all; `warnPermissionConfig()`'s stderr channel is pre-existing and ~ten sibling launch warnings are
swallowed by the identical mechanism; and there was no in-TUI notice surface to route it through
(`grep -E 'startupNotice|launchWarning|addNotice|bannerLines' src/` returned nothing).

**Fixed by building the surface.** `Chat::withLaunchNotices()` seeds a launch's warnings into the
transcript as `Role::System` rows — the shape `Renderer` already wraps, scrolls and paints correctly at
every width. `Bootstrap::warnPermissionConfigInTranscript()` is the seam, and it writes to **both**
channels: `-p` and post-exit scrollback are real consumers of the stderr line and the other launch
warnings still share it. Only `reportProjectTierToolRemovals()` is routed through the seam today —
whether each of the other nine earns a transcript row is a separate judgement per message.

**Proved the way the old behaviour was disproved**, same fixture, same input, same 120x40 pty, only the
two source files differing: before → visible screen contains `disabledTools`: **NO** (8996 bytes
captured); after → **YES** (9421 bytes). Replaying ONLY the bytes from `\e[?1049h` onward — the stderr
line discarded entirely — still finds the sentence, so the TUI painted it rather than it showing through
from underneath. One `\e[?1049h`, one copy of the sentence, though `app()` builds the tool set twice.

⚠️ **A pre-alt-screen acknowledgement gate was considered and REJECTED.** It is the wrong answer for a
launch-time warning about a grant the operator already made. Do not build one.

**ROUND 40 `sglang` — the migration finished, and three things the entry above got wrong.**

**The census. "nine" other warnings was wrong: there were SEVENTEEN.** Measured at `8add627b`: 13
un-migrated `warnPermissionConfig()`/`warnPermissionConfigOnce()` call sites, plus 4 launch warnings
that bypassed the seam entirely as raw `fwrite(STDERR, …)` — the two provider degradations to echo and
the two agent-preset degradations, which are arguably the most user-visible of the lot. A further 2
`fwrite`s in `reportPrunedSessions()` were not in any census.

**Thirteen migrated, four deliberately not.** The rule, recorded on
`warnPermissionConfigInTranscript()`: a warning earns a transcript row iff it names something **the
session can no longer do**. Left on stderr — `trustedProjectRoots()`'s three per-entry complaints
(the consequence, an untrusted project, is already on the seam with the actionable path),
`withoutEmptyPermissionOverrides()` (reports a change that was DECLINED, so nothing about the session
differs), and `reportPrunedSessions()` (history already deleted, not this session's capabilities).

**Finding: `permissionRules()` said everything TWICE, on every launch.** `chat()` reaches it through
`permissionGate()` AND through `agentManager()`, and those were raw `warnPermissionConfig()` calls
with no de-duplication at all — probed at `8add627b`, a config with two bad rules printed **four**
stderr lines. Routing through the seam picks up the per-process map, so it is one apiece now.

**Finding: `app()` raised two of these AFTER `chat()` had already read the list.** `bin/sugarcrush`
runs `app()`, not `chat()`; `app()` calls `reportSkillSkips()` and `reportProjectTierRefusals()` a
second time after `chat()` returned, so anything they recorded landed in a static nothing read again.
Migrating them without fixing that would have been a no-op that looked like a fix. `app()` now applies
the **delta** — never the whole list, because `withLaunchNotices()` appends.

**The list is now bounded, and it was not before.** `$launchNotices` becomes `Role::System` rows of the
CONVERSATION, so it is sent to the model on every turn — an unbounded list is a per-token cost for the
whole session, not a scrolling nuisance. `LAUNCH_NOTICE_LIMIT = 24` rows (measured: 17 is the most a
launch reaches without a per-ENTRY fan-out) and `LAUNCH_NOTICE_MAX_CHARS = 400` (measured: the longest
legitimate message this class builds is `hookFiles()`'s at 283 chars). stderr is never clipped or
capped, and the overflow is a counted tail row pointing at it.

**Sub-finding, caught by the test rather than by review.** The overflow counter was an `int` and
double-counted: because `permissionRules()` is raised twice per launch, a 30-rule config reported
"and 12 more" for the 6 it could not fit. It is a message-keyed set now, so the dropped half has the
same de-duplication the kept half already had.

**Still stderr-only and UNTESTED:** the two agent-preset degradations. Both registries catch per-file
failures internally, so the outer `catch (\Throwable)` needs a registry-level throw that no filesystem
fixture reaches — probed three ways (a `.claude/agents` that is a file, one that is unreadable, one
holding malformed YAML) and none of them throws. They are guarded by review, not by a test.

🔴 **STOP CITING LINE NUMBERS IN THIS ENTRY. Cite symbols.** This is the third consecutive round in which
this entry's line numbers were stale when read: the original entry's four were corrected by the round-39
scout; those corrections were stale by round 40; round 40's re-head left them uncorrected; and the
round-40 review's own re-measurement (`filterToolSet()` opens at `:4477`, the `matchesToolName()` call at
`:4529`) was stale within the same round, because THIS round's fix inserted ~100 lines above both —
they now read `:4569` and `:4625`. Every figure in this entry, this sentence's included, is a decaying
one. The durable citations are the symbols: `Bootstrap::filterToolSet()`, `Bootstrap::toolSetUnder()`,
`Bootstrap::reportProjectTierToolRemovals()`, `PermissionRule::matchesToolName()`,
`LayeredSettings::PROJECT_TIER_KEYS`' doc-block, and `docs/SETTINGS.md`'s "Why `allowedTools` is
user-tier only when `disabledTools` is not". A reader with `grep` finds each in one command and none of
them decays.

**Also landed this round, from the same review:**

- `README.md`'s wiring sentence listed the built-in tools as
  `Bash/Read/…/WebSearch/`**`Doctor`**`/Skill/Lsp` — ten exact runtime names and one that matches no
  tool, in a sentence about wiring. Same mixed-list shape as the PERMISSIONS.md defect round 40 found
  live. Fixed, and the sentence now says which spelling it is quoting. `README.md`'s two
  `Tools\BuiltIn\*` class lists and `ARCHITECTURE.md`'s are correct as they stand.
- `toolSetUnder()`'s doc-block said the "two passes" warning is in "this method's doc-block". It is in
  `filterToolSet()`'s.
- `filterToolSet()`'s *"a user who wants that has `disabledTools: ["*"]`"* is stated about a USER and
  applied by the code to a trusted PROJECT too. Behaviour deliberately unchanged — both reports fire and
  it needs a trust grant — but the sentence's authority is "the user chose this" and that does not
  transfer to the project tier by itself. Now said in the doc-block and in `docs/SETTINGS.md`.
- `withoutEmptyPermissionOverrides()`'s scoping to `PERMISSION_SETTINGS_KEYS` rested on an unenforced
  premise 3,000 lines away (that `permissionSettingsLayer()` emits nothing else). Now pinned by
  `testThePermissionSettingsLayerEmitsNothingOutsideItsWhitelist`, asserted against a file carrying a
  superset so the subset relation is measured rather than restated. It passes on both sides — a guard
  against a future widening, not a fix.

**Coverage:** 12 new tests — 7 in `tests/Cli/BootstrapToolAndPermissionSettingsTest.php`, 5 in a new
`tests/Chat/LaunchNoticesTest.php`. Against the unfixed build the 7 give 4 errors + 1 failure +
2 passes; the one that fails for a behavioural rather than a missing-symbol reason is
`testTheBuiltChatComesUpWithTheReportInItsTranscript`. **Stated plainly: none of these is the evidence
for the fix.** A string reaching a static list is not a user seeing a line — the captured pty launch is
the evidence, and any future round revisiting this must re-capture rather than trust a green suite.

**Bookkeeping for other lanes:** round 40's edits to this file were not purely additive — two
pre-existing `ROUND 39 SCOUT —` stamp headers were rewritten (they read "STILL OPEN"). This round's
edits are insertions only, except the two corrections inside round 40's own stamps.

## E58 — a `permissionMode` in `settings.json` is silently discarded by an EMPTY key in `config.json`

**Found by round 35's `/permissions` reviewer while differentially testing an unrelated refactor.**
Pre-existing: a 2400-row cross-product replay of the old `??` chain against the new precedence walk
shows **zero divergence**, so this behaviour predates round 35 and was merely made visible by it.

`permissionConfigLayers()` merges the tiers with `array_merge`, so a later layer wins. A
`permissionMode` key present in `~/.sugar-crush/config.json` as `""` or `null` normalises to *absent*
**after** it has already displaced the earlier layer's value. Net effect: a user who configured
`plan` in `settings.json` gets the built-in default — which is currently `bypass-permissions`.

**A configured `plan` becomes `bypass-permissions` because a different file mentioned the key and left
it blank.** That is a fail-open, and it is precisely the failure
`permissionSettingsLayer()`'s own docblock claims its strict reader closes.

**Severity.** Higher than E57. E57 removes capability; this silently *grants* the widest one, from a
config shape that reads as a no-op to anyone writing it — an empty string is the natural way to spell
"I am not setting this here".

**What is already true and should not be re-derived:** the round-33 fix making an empty
`--permission-mode` **flag** exit 2 lives in `ArgvParser` (`EMPTY_PERMISSION_MODE_ERROR`) and is
untouched by this. The flag path is strict; the *file* path is not. The asymmetry is the bug.

**Step.** Decide whether an empty/null value in a permission key means "absent" or is an error, then
make one true across every layer. If "absent", it must be dropped **before** the merge so it cannot
displace an earlier layer. If an error, reuse the flag path's refusal. Do not fix this by reordering
the layers — that changes documented precedence for every other key.

⚠️ **Re-measure before designing.** The claim is that `""` and `null` in `config.json` both defeat a
valid `settings.json` value; verify both spellings, and check whether the same shape affects the other
`PERMISSION_SETTINGS_KEYS` (notably `permissionRules`, which is read from both files) — the reviewer
measured the mode only.

---

**ROUND 39 SCOUT — was STILL OPEN when stamped; FIXED in round 40, see the stamp below. This entry
is ACCURATE as written.** Measured, `settings.json`
carrying `{"permissionMode":"plan"}` in every row:

```
config=ABSENT                =>  plan                 (from settings.json)
config={}                    =>  plan                 (from settings.json)
config={"permissionMode":""} =>  bypass-permissions   (the built-in default)
config={"permissionMode":null} => bypass-permissions  (the built-in default)
config={"permissionMode":"  "} => refuses the launch
config={"permissionMode":false} => refuses the launch
```

Silently, with no stderr line. The fail-open is narrow to exactly `""` and `null`; whitespace and
non-strings refuse loudly. Current locations (the entry cites none, so nothing is stale):
`permissionConfigLayers()` `src/Cli/Bootstrap.php:3243`, `permissionSettingsLayer()` `:3325`, the
post-merge `''→null` normalisation `:3098-3104`, `permissionGate()` `:3078`.
`DEFAULT_PERMISSION_MODE` at `:153`; `ArgvParser::EMPTY_PERMISSION_MODE_ERROR` at
`src/Cli/ArgvParser.php:710`.

**Its ⚠️ is now ANSWERED — `permissionRules` has the same shape, but loudly.** A `null` or `""`
`permissionRules` in `config.json` also drops a configured `deny` rule from `settings.json`, but both
spellings announce on stderr and have shipped tests. **Neither message says "and this displaced your
settings.json value"** — which is the part a user needs.

⚠️ **Write the fix against the right invariant.** The entry says this "silently grants the widest mode".
True in effect, but the mechanism is *"an empty key falls through to `DEFAULT_PERMISSION_MODE`"*, and
that default merely happens to be `bypass-permissions` today. **If the default is ever tightened this
same bug flips to fail-CLOSED.** Fix "an empty value must not displace an earlier layer", not "must not
reach bypass".

**No test locks the current behaviour in** — the mode-precedence tests cover absent/present/invalid but
not empty-string or null. Test-free to land; needs new coverage. **Size S**, one file region.

**ROUND 40 `sglang` — FIXED.** Every one of the scout's six rows reproduced byte-for-byte before the
change, and the `permissionRules` half reproduced too (`null` and `""` in `config.json` each dropped a
`deny` rule configured in `settings.json`, announcing only "no rules were loaded").

**One mechanism covers both keys**, because both keys had the same defect for the same reason.
`permissionConfigLayers()` now returns its layers through a new
`Bootstrap::withoutEmptyPermissionOverrides()`, which drops a `null`/`''` value for a
`PERMISSION_SETTINGS_KEYS` key out of a LATER layer when an EARLIER layer set that key to something
real, and reports the drop on stderr naming BOTH files — the part the old `permissionRules` message
never said. Filtering at `permissionConfigLayers()` rather than at either merge site means
`permissionGate()`, `permissionConfig()` and `permissionKeySource()` all see the same layers, so the
provenance the gate remembers now names the file that actually won (before: `"permissionMode":""` in
`config.json` under a `plan` in `settings.json` reported `the built-in default`; after: it reports
`settings.json`).

**Written against the invariant the ⚠️ named, not against `bypass`.** Nothing in the fix or its tests
mentions `bypass-permissions` as the thing being avoided; the docblock states why, and the tests assert
the configured mode AND its source rather than "not bypass", so tightening `DEFAULT_PERMISSION_MODE`
cannot make them vacuous.

**Deliberately NOT changed, each pinned by a control test:** `"  "` is a value that names no mode and
still refuses the launch by name; a non-string still refuses; `"permissionRules": []` is a well-formed
empty list and still outranks `settings.json`; an empty key with nothing beneath it is still read as
absence, still silent, and `permissionRules`' shipped "present but null" warning still fires for it —
which is why `testAPresentButNullRulesKeyInTheSettingsFileIsReported` and
`testAPresentButNullRulesKeyInTheWrittenConfigIsAlsoReported` stay meaningful rather than being
satisfied by the new message. (Written as a brace expansion in the round-40 stamp, which was cosmetic
but wrong: the second name carries an `Also` the brace form dropped, so neither half expanded to a
real symbol.)

**Newly recorded while measuring, NOT fixed:** `{"permissionRules": []}` in `config.json` silently
discards a `deny` rule configured in `settings.json`. It is the same user-visible surprise, but it is
NOT the same defect — `[]` is a value of the right type winning documented later-layer precedence, and
suppressing it would be a precedence change, not an emptiness fix. Left as-is, with a control test that
says so out loud.

**Coverage:** 9 new tests in `tests/Cli/BootstrapToolAndPermissionSettingsTest.php` (5 that fail against
the unfixed build — verified by running them against it — and 4 controls that pass on both sides and are
labelled as controls). `docs/SETTINGS.md` gained the rule under "Which keys are layered at all".

## E59 — the split-pane compositor now paints running-worker output, but the worker is a SIMULATION STUB

**Volunteered by round 36's implementer, unprompted, while reporting the item as done.**

`ProcessExecutor::createInlineWorkerScript()` carries its own comment — *"Real LLM integration comes in
later phases"*. It `usleep`s roughly one second and echoes the task back.

So the two mechanisms the round built are genuinely real and genuinely proven: the workflow no longer
blocks the loop, and the pane paints a **live, unsettled** agent's output mid-run. What it paints
today is a simulation.

**This is a scope boundary, not a defect, and the distinction decides what may be marked ✅.** The
compositor item (P8.4 / Phase 8 item 4) IS closed — the pane works, the plumbing carries real bytes
from a real forked worker over real IPC. What is NOT closed is the worker actually talking to a model,
which was never part of that item and belongs to the pool's own phase.

⚠️ **Do not let a later round read "the compositor paints agent output" as evidence that agent
execution is wired.** That is precisely the shape this audit keeps finding — a true sentence standing
next to a different claim it appears to support.

**Step.** When the pool's real execution lands, re-run round 36's
`WorkflowLivePaneTest::testAFramePaintsTheRunningAgentsOutputWhileTheWorkflowIsStillRunning` against a
real worker. It is written against the shipped path (real `pcntl_fork()`, real `proc_open()`), so it
should need no change — which is the test's own claim, and worth checking rather than assuming.

---

**ROUND 39 SCOUT — STILL OPEN on the stub; but this entry's STEP IS WRONG.**

The stub is intact: `ProcessExecutor::createInlineWorkerScript()` at
`src/Agents/ProcessExecutor.php:514`, with *"Real LLM integration comes in later phases"* at `:585`.
The "roughly one second" figure checks out — the script's sleeps sum to `20000+20000+500000+500000` µs
= **1.04 s**, and the live test runs in 1.218 s wall.

🔴 **The Step claims the test "is written against the shipped path … so it should need no change".
Checked, and false.** The test asserts on **the stub's own literal output string**: the worker emits
`"[{$agentConfig['name']}] Processing: {$task}"` (`ProcessExecutor.php:591`) and the test matches
`'Processing:'` and `'[' . self::AGENT_NAME . '] Processing:'`
(`tests/Workflows/WorkflowLivePaneTest.php:190,227`). A real LLM worker emits no such string, so the
liveness probe has no anchor. What is reusable is the test's **structure** (real `pcntl_fork()` /
`proc_open()`, frames stamped un-settled); its **assertions** are not. The test's own docblock claims
only the fork/proc_open part — the entry over-read it.

The scope-boundary framing is otherwise correct: `ProcessExecutor` is instantiated at
`src/Agents/AgentWorkerPool.php:1320` and `src/Chat.php:4679` and is the shipped default, so the pane
genuinely does paint the stub. `src/App/App.php:437-444` carries the same note, accurately.

**Size L. Do not bundle it** — the test rewrite is the risky part and deserves an isolated diff.

---

**ROUND 42 `lane c` — THE TEST HALF IS FIXED. 🔴 THE WORKER HALF IS DEFERRED, DELIBERATELY; this stamp
does not close the entry.**

**The round-39 scout's correction was right, and it was one anchor short.** It found two literals in
`WorkflowLivePaneTest` (`Processing:` and `[<name>] Processing:`). There are **three** in that file:
the third is `assertStringNotContainsString('Processing:', $frame)` in
`testThePaneIsGoneOnceTheWorkflowHasFinished()` — a NEGATIVE assertion, which is the one that could
never have announced its own rot. **Measured, not argued:** with `Tui\Renderer::liveAgentOutputs()`
mutated to inject a stuck tile carrying the stub's *final* line
(`[docs-explorer] Task finished: explore the docs`), the OLD test file passed — `OK (1 test, 5
assertions)`, rc 0 — while the rewritten one reds. The pane was pinned open and the negative anchor,
looking for a word the stub only emits *mid*-run, saw nothing wrong.

There is a **fourth** anchor outside that file, and it is NOT touched here: `tests/ChatTest.php`'s
config-built-pool dispatch test asserts `'[ConfigBuiltPoolAgent] Task finished: Say hello'` verbatim
against `Chat::executeAgents()`'s result. It is a legitimate end-to-end assertion *today* and it will
break the day the worker is real; recorded so that day's implementer finds it.

**What the anchors are now.** Not a string — a contract. On each painter tick the test reads
`AgentManager::liveOutputs()` FIRST and renders SECOND, then asserts that the agent's own tile, sliced
out of the frame by column (`WorkflowLivePaneTest::paneColumn()`), carries 16 cells of whatever that
buffer's first non-blank line was (`WorkflowLivePaneTest::livenessProbe()`, `LIVE_TEXT_PROBE_CELLS`).
A real worker satisfies it by emitting anything at all. The negative test asserts the TILE is gone
(`╭ <name> `, `[working]`) rather than a stub word, and is guarded against vacuity: the finished
sub-agent is checked to be terminal AND non-empty via `AgentManager::subAgentsOf()`, so "the pane is
down" cannot pass because the worker never spoke.

🔴 **ROUND-42 REVIEW CORRECTION, applied: as first written the derived probe was the AGENT'S NAME and
pinned nothing.** `livenessProbe()` took the first 16 cells from cell 0, the stub tags every line
`[<name>] `, and `[docs-explorer] ` is exactly 16 cells — so the probe was a datum
`AgentSplitColumn::state()` is handed as a parameter and could paint without reading a byte from the
worker. Measured (PHP 8.3.6, PHPUnit 10.5.64): a mutant passing
`self::tail('[' . $name . '] MUTANT never came from the worker')` as `outputBuffer:` passed the whole
file, `OK (11 tests, 49 assertions)`, rc 0. The probe now skips a leading `[<agent name>] ` tag and
FAILS if what remains still contains the name; the same mutant reds, as does the tag-free
`'MUTANT placeholder text'` variant. A new `tileTopBorder()` measures the painted tile's own budget so
the window's upper bound is an assertion rather than a sentence.

🔴 **And a figure in the same docblock did not reproduce (RULE 9).** It claimed "the tile is 40 cells,
inner 36, and the stub's 34-cell first line survives whole". The arithmetic is right —
`Renderer::agentSplitWidth()` at `cols = 120` gives `min(60, max(24, intdiv(120, 3))) = 40`, and
`AgentSplitColumn::render()` clips to `$width - PANE_CHROME` = 36 — and the rest is wrong twice.
Instrumenting a scratch copy of the test file: the live buffer holds NO newline, because
`AgentWorkerPool::pumpProgress()` concatenates both `streaming` chunks, so the "first line" is one
88-cell string; the stub's first *emitted* line is 44 cells, not 34; and it is clipped, the painted row
being `│ [docs-explorer] Processing: explore  │`. Rewritten in place per RULE 7.

🔴 **A third negation was narrower than the prose around it.** All three frame negations in
`testThePaneIsGoneOnceTheWorkflowHasFinished()` name this agent or `[working]`, so a stopped tile for a
DIFFERENT agent satisfied every one — measured by injecting `['other-agent' => 'zzz mutant filler']`
into `Renderer::liveAgentOutputs()`. That is not a regression (the old `Processing:` negation had the
same hole), but the docblock implied otherwise. The test now asserts `Renderer::liveAgentOutputs()`
empty directly — the compositor's own source for the split, and a different method from the
`AgentManager::liveOutputs()` asserted beside it — and that mutation reds.

**Why the worker half is not here, stated as a size and not as a preference.** `php -r` is the whole
worker: `ProcessExecutor::spawnWorker()` launches `[$binaryPath, '-r', $workerScript]` with **no
autoloader in the child**. A real one needs (1) composer's autoloader bootstrapped in that child, (2) a
provider IDENTITY plus credentials crossing the startup message — which today carries `agent.id`,
`agent.name`, `agent.model`, `agent.prompt`, `task` and a `request` sub-object of
`model`/`messages`/`tools`/`systemPrompt`/`temperature`/`maxTokens`, eleven fields and not one of them
a provider or a credential; `Agents\Agent` even HAS a `provider` field that `spawnWorker()` does not
forward (an earlier revision of this stamp and of the `createInlineWorkerScript()` docblock listed only
the six `request` fields and called that the whole message — corrected in both, and the point stands
harder for it) — and (3) an offline substitute, because CI has no model — so the simulation does not disappear even
then, it moves behind a seam. (1) and (2) touch `bin/` and `Cli/Bootstrap.php`, held by other lanes
this round, and (3) is a new `src/` file, which moves four figures in `BuiltInToolCorpusTest`, a prose
restatement in `RepoMapBlock`, and `BinSugarcrushWiringTest::crushSourceFiles`. That is not a
drive-by; half-landing it is worse than not landing it.

`ProcessExecutor::createInlineWorkerScript()` now carries the RULE-6 seam docblock saying all of the
above at the site, including which parts around it are genuinely production (the `proc_open()`, the
line protocol, `AgentWorkerPool::pumpProgress()`, the compositor) and that deleting the simulation
would remove the only exercise that chain has. The two "Real LLM integration comes in later phases"
comments are rewritten, not deleted, per RULE 7 — the phase they deferred to has passed.

**Still open, unchanged:** the worker is a simulation. Size of what remains: L.

## ROUND 37 SCOUT — E55 and E56 both reproduce; figures restated at master `4a4ecb98`

**E55.** Cap 400 → **7022 bytes returned, 17.6× the cap**; the hit list clipped to one line and a
6569-byte `CLAUDE.md` appended whole *after* the truncation marker. The entry's original "6700 bytes /
16.8×" was measured against a 6513-byte instruction file that no longer exists — both figures are
correct for their fixture, and the difference is not a discrepancy.

**E56.** Cap 200 → 193 bytes, **0 of 5 matched paths listed**, `BIG-RULE` body present, marker fires.
Reproduces exactly as written; no correction. (The "of 5" is right for the scout's fixture and is NOT
the same denominator as the six-path one in the ROUND 37 `lsp` table below — that fixture adds
`sub/target.php`, which `sub/*.php` also matches, and the two-byte difference between 193 and the
table's 195 is that sixth path inside the marker's own byte counts.)

**The two are broken in opposite directions** — `Grep` appends *after* the cap and blows through it,
`Glob` prepends *before* it and starves the results — which is why they are one fix and not two.

---

### ROUND 37 `lsp` — FIXED. E55, E56 and the same exemption in `Read`, `Edit` and `Write`

One fix, as the scout judged. The cap is now **split**: an instruction section may take at most a
QUARTER of `maxOutputBytes` (`TruncatesOutput::instructionBudget()`), so the answer keeps three
quarters as a guaranteed floor, and the two sections carry different markers — a clipped rule says
`these project rules are PARTIAL`, because "narrow the query to see the rest" is advice no pattern the
model can write will act on.

Measured by running the tools over ONE fixture — the one `ToolOutputBudgetTest` builds: a 9,611-byte
`sub/CLAUDE.md` and **six** paths matching `sub/*.php`, namely five `needle-file-00N.php` and the
39-byte `sub/target.php` the `Read` rows read. The root is `sys_get_temp_dir()/crush_budget_<12 hex>`,
**30 characters**, and that length is repeated on every path line — so it is part of every byte figure
below and is stated rather than left to be rediscovered.

| tool | cap | `4a4ecb98` | `f1fda934` | now |
| --- | --- | --- | --- | --- |
| `Grep` | 400 | 10,096 B (25.2×), 2 hits | 503 B (1.3×), 1 hit | 348 B (0.9×), 2 hits |
| `Glob` | 200 | 195 B, **0 of 6 paths** | 200 B, 0 of 6 | 200 B, 0 of 6 (the base marker, not the rules — the six-path list alone is 321 B) |
| `Glob` | 400 | 387 B, **0 of 6 paths** | 530 B, 2 of 6 | 321 B, **6 of 6 paths** |
| `Read` | 200 | 9,651 B (48.3×) | 141 B | 141 B |
| `Read` | 400 | 9,651 B (24.1×) | 140 B | 141 B |

**The path denominator and the list figure in that table were both wrong, and both by the recurring
defect.** `sub/*.php` matches six files, not five — `target.php` is one of them — so every "of 5" was
one short. And "the list alone is 225 B" reconciles only with a 20-character root: on the fixture's
own 30-character root the five `needle-file` paths come to **275 B** and all six to **321 B**, which
is exactly the 321 the `Glob` 400 row reports, i.e. that cap now returns the complete list and nothing
else. Re-measured in one run over a freshly built fixture; every other figure in the table reproduced
byte-for-byte, which is what says they were taken on this root and this fixture.

**The `f1fda934` row of that table was wrong in BOTH columns, and both errors were the recurring
defect.** It quoted a 7,211-byte `CLAUDE.md` and a 637-byte target in five places while the fixture it
cited is 9,611 and 39 bytes; the "before" column reconciled with neither (7,428 against 7,412 for a
7,211-byte body and 9,812 for the shipped one), and the "after" column had been measured on a
different, larger target file again — 318 B where the shipped fixture returns 141. Two fixtures, one
row. Re-measured above at all three revisions in a single run.

With no instruction file to surface the result is byte-identical to the same tool built with no loader
at all, so the reserve costs nothing on the calls (almost all of them, under announce-once) that have
nothing to put in it.

Two further findings from the sweep, both fixed here:

- **`Read`, `Edit` and `Write` carried the same exemption.** `Read`'s `$maxBytes` is a per-file READ
  bound, so the file's share is deliberately not reduced to pay for the rules: the total is bounded at
  1.25× rather than at 1×, replacing an unbounded multiple. `Edit`/`Write` have no output cap to take a
  fraction of — their result is one line — so the body is bounded by a standalone 16 KiB default.
- **`Glob` spent the announce-once mark on paths it never showed.** It loaded one instruction file per
  matched path *before* truncating, so a `**/*.php` over a large tree retired every matched directory's
  `CLAUDE.md` for the whole session while showing the model only the paths that fit. It now probes at
  the result floor first and loads only for paths that survive — the doctrine `Grep` already followed.

### ROUND 37 `lsp` — REVIEW FIX. The cap was unbounded at the shipped default

`f1fda934` split the cap and then handed the split's arithmetic straight into the helper whose
documented "no cap" sentinel is a non-positive budget — the exact trap its own docblock names, thirty
lines above the loop that walked into it. Once the reserve ran out `intdiv(0, n)` hit the sentinel and
every remaining body came back verbatim. Measured through `new Glob($dir, $loader)` at the shipped
65,536-byte default, one `CLAUDE.md` and two files per directory: **200 directories → 199,767 B,
300 → 551,537 B, 500 → 1,091,833 B (16.7×)**. Worse than the exemption the commit closed, and no test
in the suite noticed, because every fixture in it had one instruction file in one directory.

A byte guard alone does not fix it. Each entry costs a floor no share is charged for, so the section
grows linearly in the number of governing files: measured with the share guarded and the count
unbounded, one file per directory, 800 directories returned **129,517 B (2.0×)** and 1,500 returned
**144,245 B (2.2×)**. It plateaus there only because `Glob::DEFAULT_MAX_MATCHES` stops the walk at a
thousand paths — a ceiling on a different quantity, in one of the two tools. The count is now bounded too — and it is
bounded **before `loadForPath()`**, because that call marks a file emitted at load time, so
loading-then-dropping retires a rule the model never saw. A path never examined is never spent, and the
next call with room surfaces it in full. What was not looked at is counted in the result.

At 500 dirs the same call now returns 65,500 B with a 16,358-byte section against its 16,384-byte
quarter; at 1,500 dirs, 65,500 B and 16,363. **There is no residual.** The earlier statement of one —
"below a cap of roughly 700 bytes the section overruns its reserve by a fixed ~210 bytes" — was wrong
in both bound and magnitude (Glob went over at 800, and the overrun reached 247 with a 120-byte
heading, because the section is label + heading + marker rather than a constant). The section is now
held inside `instructionSectionCeiling()` at every cap and the result floor is computed from that same
ceiling, so the total lands inside the cap wherever the cap exceeds the base truncation marker's own
185 bytes — which `truncateOutput()` pays with or without a loader wired.

Also fixed here: `Glob` at `maxOutputBytes` 1 returned 10,068 B (the whole rule book) because a
quarter of 1 rounds to the no-cap sentinel, and `Read` did the same at 1, 2 and 3 (9,629 / 9,630 /
9,631 B) — the second of those was not in the review and is the same defect one layer down. And a new
multibyte cut site: the heading clip had no line-boundary fallback, so `substr()` split UTF-8
mid-sequence at first-line offsets 118 and 119; `clipToLine()`'s own `substr()` did the same at caps
439 and 440 on a single-line file, which is the case its docblock says it keeps a fragment for. Both
are `mb_strcut()` now.

**E57 — `SkillPathNudge::forPaths()` is unbounded, and the exemption note understated it.** The note
said the exemptions are "one sentence sized by directory or skill names". `SkillPathNudge.php:79`
emits `- {$skill->name}: {$skill->description}` — one line per matching auto-invocable skill, each
carrying that skill's full `SKILL.md` description, which is arbitrary-length repository content. Its
real bound is (matching skills × description length), paid on the first matching call of a session.
The comment now states that bound instead of denying it. Not fixed here: bounding it belongs with the
nudge, not with this reservation.

Tests: `ToolOutputBudgetTest` 13 → 22 tests, 59 → 153 assertions, and the fixtures are adversarial in
a third direction the first cut had none for — MANY GOVERNED DIRECTORIES. Many files per directory
does not reproduce any of this: announce-once means the second file under a `CLAUDE.md` loads nothing.
Twenty mutations of the load-bearing lines were applied one at a time; eighteen die. The two that
survive — the `max(1, …)` share guard and `clipInstructions()`'s `$budget <= 0` sentinel — were
instrumented and the whole suite run: **zero calls reach either with a non-positive budget.** They are
unreachable, not untested, and they stay documented as guards because this arithmetic has now silently
disabled this bound twice.

Three stale claims corrected in `f1fda934` and still correct: `GrepInstructionWiringTest`'s docblock
contrasting itself with a prepending `Glob`,
`GlobTest::testNestedInstructionFileContentIsStillPrependedPerMatch`'s name, and `Glob::description()`'s
promise that a governing file is "surfaced above that path".

---

### ROUND 37 `lsp` — SECOND REVIEW FIX. The guard test was green under its own regression

`GrepInstructionWiringTest::testTheAnnouncedRulesAreExactlyTheOnesWhoseHitsSurvivedTheClip` no longer
detected the defect it exists for **at the cap it shipped with**. Moving the read point to
`$filtered['run']['stdout']` — the announce-rules-for-files-the-model-cannot-see regression — left the
test **byte-identical to pristine** at cap 1,024. The reason is structural, not a near miss: at six
directories and that cap the reserve holds exactly ONE entry, and the first hit is the same line in
the probe as in the raw capture, so a strictly larger read set still announces the identical rule.
Swept one cap at a time on that fixture's own 35-byte temp root, the two are byte-identical from 900
to 1,375 — at 1,024 both return 898 B, `visible=[aaa,bbb,fff] announced=[bbb]` — and the window where
the containment assertion fails at all is caps 1,416–1,462: **47 caps, with the shipped cap pointed
392 below the bottom of it.**

The relation was not the problem — containment does catch it — the FIXTURE was. A difference is
observable only where the reserve holds more entries than the probe holds hits, and both ends of that
band are set by the fixture: below it the reserve holds one entry, above it the probe holds every hit
and no unseen path is left to announce. **Sixty directories instead of six**, and the mutation is
caught at **13,445 of the 19,218 caps swept, including 12,859 consecutive (1,860–14,718)** — 273× the
old band. The test now runs at four caps spread across it with ≥640 bytes of margin at the low end and
≥3,700 at the high end; the mutation announces 4 to 16 unseen directories at each. Correct code
violates containment at **none** of the 19,218.

**A regression inside `d7919902..6569891f`: Grep's skill nudge under-announced for a file the model
can see.** `$hitFiles` was read off the probe (the ¾ floor) and reused for
`SkillPathNudge::forPaths()`, but the result is clipped at the FULL cap whenever no rule was surfaced —
which under announce-once is almost every call. A hit between the two cuts was visible while its skill
went unannounced, and announce-once means unannounced for the session. Measured with a `*.zzz.php`
skill over 201 hits, counting caps where the hit is visible and the skill silent: **0 at `d7919902`,
1,745 at `6569891f` (caps 5,233–6,977), 0 now.** `Glob` documents the opposite answer two files over —
*"scoped to every MATCHED path, not to `$shown`… a nudge earned by a path the cap dropped is still a
true and actionable statement"* — so the commit shipped Grep contradicting a comment it was adding to
Glob. The nudge now reads the unclipped capture in both tools; the instruction section still reads the
probe, and `hitFiles()` documents why its two callers pass different text.

**Six unkilled mutants on the new load-bearing lines; five now die.** They were coverage gaps rather
than demonstrated bugs — none broke `section ≤ ceiling` or `total ≤ cap` on the sweeps that found
them — but the byte accounting the whole split rests on was pinned by no assertion. Two were added:
the entry floor is now checked against its own derivation (head + newline + the marker at its widest +
the implode newline), rebuilt from a marker the run actually emitted so a reworded marker moves both
sides together; and the section/cap bounds are swept over a tree whose section holds HUNDREDS of
entries as well as one whose single entry is clipped inside its first line.

| mutant | now |
| --- | --- |
| `instructionBodyFloor()` drops both `+ 1` | **dies** (the derivation assertion — it breaks no bound) |
| `instructionBodyFloor()` marker sized `(0, 0)` | **dies** (111 of 29,025 pairs returned more than their cap) |
| `-1` dropped from the body budget | **survives** — see below |
| `$spent += strlen($clipped)` without the `+ 1` | **dies** (63–171 B past the ceiling, 31–145 B past the cap, on a 500-directory tree) |
| `$labelRoom` unconditional | **dies** |
| the note emitted without the `$noteCost <= $leftover` check | **dies** |

The survivor is one byte and no assertion can reach it. At most ONE body per call is ever clipped — a
clipped body consumes the room that was left, so the loop breaks on the next path — and the note's own
`<=` check absorbs the byte by dropping the note rather than letting it escape as an overrun. Swept:
328 of 29,025 (fixture, cap) pairs change output, **zero** violate either bound, 36 lose the withheld
note, in windows four caps wide. A test aimed at those four caps would be the same defect this round
exists to remove. Recorded in the code at the site.

**Two more documented rather than changed.** `instructionSection()`'s `$bodies === []` branch cannot
emit its note — the loop's break requires `$bodies !== []`, so no body collected means no early exit,
so `$withheld === 0`; stubbing the branch to `return '';` leaves the suite green. It stays wired, with
the arithmetic written beside it, because the condition is the specification of the count bound above
it. And `Glob::pathsIn()`'s line-membership test guards a prefix collision that `sort($matches)` makes
unreachable: a prefix always sorts before the paths that extend it, so it survives a suffix-truncating
clip whenever they do. Mutated to `str_contains()` over a `d/f.php` ⊂ `d/f.php.dir/g.php` fixture
across caps 100–4,000: **zero** paths spent but unseen, at both revisions.

**The two `f1fda934` survivors are unreachable by ARITHMETIC, which is what the code now says.**
"Instrumented and no test reached it" is a much weaker claim than "no caller can".
`instructionSection()`'s share cannot come out below **151** (`$room ≥ 242`, `$noteCost ≤ 90` even at
`count($paths) === PHP_INT_MAX`). `clipInstructions()`'s `$budget <= 0` has three callers: Edit and
Write pass a constant, `instructionSection()` guards it, and `Read`'s `: $reserve` branch needs
`$maxBytes <= 0`, which Read never reaches because `fread()` is called with that value first and
throws (measured: 0 and −1 both give `fread(): Argument #2 ($length) must be greater than 0`).

**The trait's headline was false of one of its five users.** *"The instruction section can spend at
most a quarter, the answer keeps at least three quarters"* is true where the cap bounds a COMPOSED
result (`Grep`, `Glob`). `Read`'s `$maxBytes` is a per-file READ bound, so the quarter is added rather
than taken out — a stated 1.25×. At Read's 1 MiB default that reserve is **256 KiB: 16× the flat
`DEFAULT_MAX_INSTRUCTION_BYTES` Edit and Write get, and 4× the whole `DEFAULT_MAX_OUTPUT_BYTES`**, so
a Read at the default returns this repo's 9,611-byte `CLAUDE.md` verbatim. That is the intended
behaviour and it is announce-once, but it is a bound of a different order and the headline now says so
instead of averaging over it.

**A latent trap in the test helper.** `ToolOutputBudgetTest::instructionSection()` was documented as
"everything from the instruction label onward" and `substr()`'d from the first `... [instructions:` —
which is the WITHHELD NOTE, at the end of the section, whenever the label was dropped for want of
room. On `seedGovernedDirs()`'s fixture that is every cap up to 1,595: at 1,024 it reported 73 bytes
for a 226-byte section. Every cap its callers pass is above the band, so no shipped assertion was
wrong. It now finds the section by eliminating the answer — every answer line is a path under the
fixture root or a `... [` note that is not an instruction note — which is exact at any cap.

---

## E60 — a hook's non-DENY output is still unbounded prompt text (FIXED, round 40 `cmd` — see the stamp below; the heading's "prompt text" was itself wrong about the MODIFY half)

**Found by round 37's `cmd` reviewer on the timeout work; fixed only where it was measured.**

`ScriptHook`'s DENY reasons are now clipped at `MAX_DENY_REASON_BYTES` (16 KiB, the figure
`CommandSpec::MAX_SUBSTITUTION_BYTES` already uses for the same kind of seam). MEASURED before the
clip: a hook writing 200 KB to stderr and then wedging produced a 200 KB deny message, quoted verbatim
into the model's tool result by both live gates.

**What is deliberately still unbounded, and why the clip was not simply widened to cover it:**

- **`EXIT_ALLOW` stdout** becomes the tool result message. It is the hook SUCCEEDING, and its size is
  what the author chose to emit — but it is still unbounded prompt text on a path a runaway hook can
  reach, and it has not been measured.
- **`EXIT_ASK` stdout** is a question put to a human; clipping it changes what the human is answering.
- **`EXIT_MODIFY` stdout** is machine-readable JSON that MUST round-trip. Clipping it turns a rewrite
  the hook meant to permit into a DENY, which is the failure `modifyOrDeny()` exists to prevent, so
  this one must never be clipped — it needs a *refusal* above some size, not a truncation.

**Step.** Measure each of the three, then decide per path: clip (ALLOW), refuse-over-size (MODIFY),
leave (ASK). Do not reach for one constant across all four actions — the four have different failure
modes and that is the whole content of this entry.

**ROUND 39 SCOUT — PARTIALLY OPEN. The headline bullet is NOT REPRODUCIBLE as described.**

Measured, a 200 KB payload through the live path `HookManager::preToolUse()` → `HookRegistry::executeHooks()`:

```
ALLOW(0)  stdout  200000 bytes in ->  message=      0   modifiedInput=     0
ASK(3)    stdout  200000 bytes in ->  message= 200000   modifiedInput=     0
MODIFY(4) stdout  100014 bytes in ->  message=      0   modifiedInput=100014
DENY(1)   stderr  200000 bytes in ->  message=  16465   (clipped, marker present)
```

🔴 **Bullet 1 — "`EXIT_ALLOW` stdout becomes the tool result message" — is false, in two independent
places.** (a) `HookRegistry::executeHooks()` ends
`return $modified ?? $inertRewrite ?? HookResult::allow();`, rebuilding the permitting result with an
**empty message**, so the hook's stdout dies before any gate sees it. (b) Both live gates discard
`$hookResult->message` on the permitting path anyway — `Runtime::gate()` (`src/Runtime.php:769-796`)
and `Chat::gateToolCall()` interpolate it **only** into `"Hook denied: …"`.

**The real ALLOW-adjacent exposure the entry does not name:** `src/Runtime.php:1124` — an **ASK with no
approver attached** becomes `"Permission required and no approver is attached to this run: {$ask->message}"`,
measured surviving at **200,000 bytes**.

**The MODIFY path is worse than the entry frames it.** The unbounded quantity is `modifiedInput`, not
`message`, and it is not prompt text — it is **the tool arguments that execute**, also stamped onto the
PostToolUse context via `withRewrittenArgs()`.

**"Four actions, four failure modes" half-survives.** Five exit codes map to four actions, and
`EXIT_BLOCK` plus unknown codes share DENY's clipped path (measured: all three clip to 16,465). After
measurement there are **three** live failure modes, not four: ASK (unbounded), MODIFY (unbounded,
executed), DENY/BLOCK/unknown (clipped) — and ALLOW, which carries nothing. The Step's "do not reach for
one constant" still holds; the enumeration behind it does not.

Cited figures all check out: `MAX_DENY_REASON_BYTES = 16384` (`ScriptHook.php:166`),
`CommandSpec::MAX_SUBSTITUTION_BYTES = 16384` (`:149`), `modifyOrDeny()` (`ScriptHook.php:676`),
`clip()` (`:439`). Observed 16,465 = 16,384 + an 81-byte marker. **Size S–M**, one file plus tests.

**ROUND 40 `cmd` — FIXED, at 9ed89648. Every figure in the round-39 scout stamp re-measured and
confirmed; one figure of the scout's own was an artefact and is corrected below.**

Re-measured at afe3c26b before touching anything, 200 KB through `HookManager::preToolUse()`:

```
ALLOW(0)   stdout 200000 -> action=allow  message=      0   modifiedInput= (null)
ASK(3)     stdout 200000 -> action=ask    message= 200000   modifiedInput= (null)
DENY(1)    stderr 200000 -> action=deny   message=  16465   modifiedInput= (null)
BLOCK(2)   stderr 200000 -> action=deny   message=  16465   modifiedInput= (null)
UNKNOWN(7) stderr 200000 -> action=deny   message=  16465   modifiedInput= (null)
```

The scout's ALLOW/ASK/DENY numbers reproduce exactly, and so does the "false headline bullet"
finding — `EXIT_ALLOW` stdout reaches nobody. **BLOCK and unknown confirmed clipping to the same
16,465**, which the scout asserted and is now measured here too.

**The scout's MODIFY row (`100014`) understated the exposure and did so for a reason worth
recording.** MODIFY through the live registry could not be measured at 200 KB at all — it came back
`deny "Hook audit could not be executed"`, because `executeHooks()` feeds an accepted rewrite back as
the NEXT pass's `toolInput` and E65's env ceiling then killed pass 2. Called DIRECTLY,
`ScriptHook::execute()` returned a **200,014-byte** `modifiedInput` on the same hook. So the live
ceiling on `modifiedInput` was **not a bound anybody wrote** — it was E65 leaking into E60, and it
would have silently disappeared the moment E65 was fixed alone.

**What was changed** (`sugar-crush/src/Hooks/ScriptHook.php`):

- **ASK is now clipped** at a new `MAX_ASK_PROMPT_BYTES = 16384`, a constant of its own rather than a
  widening of `MAX_DENY_REASON_BYTES` — the two are separate decisions that agree on a number.
  `clip()` now takes its limit as a **required** argument with no default, so a future third caller
  cannot inherit whichever bound happened to be written first. Measured after: 200,000 -> 16,465.
- **MODIFY is REFUSED over a ceiling, never clipped.** A truncated rewrite is invalid JSON,
  `HookResult::rewrittenArgs()` reports that as null, and every consumer then runs the **originals**
  the rewrite existed to replace — the exact failure `modifyOrDeny()` exists to prevent, so clipping
  here would have converted an unbounded-size problem into a silent-wrong-arguments problem. The
  ceiling is **derived, not invented**: the larger of `MIN_REWRITE_BYTES = 16384` and the byte length
  of the arguments the rewrite replaces. A flat cap would break the legitimate case outright — a
  sanitiser editing the `file_path` of a 300 KB `Write` has to print the body back. The ceiling is
  non-increasing across the re-scan (pass N+1's ceiling is `max(16384, len(rewrite_N))`, and
  `len(rewrite_N)` was bounded by pass N's), so the whole chain is bounded by
  `max(16384, len(the model's own arguments))`.
- **ALLOW left alone**, because there is no exposure to fix — not because "its size is what the author
  chose to emit", which is what the old docblock said.

**Docblock claims corrected in place** (this entry's real ancestor is a comment that was true of the
class and false of the live path): the class docblock's `0 ALLOW — stdout becomes the result message`,
and the whole final paragraph of `MAX_DENY_REASON_BYTES` claiming the other three exits were left
unbounded on purpose.

**Now impossible:** an ASK message over 16,384 bytes + marker reaching the permission modal or
`Runtime::settleAsk()`'s no-approver string — with the round-41 correction under E65 that the modal was
never the unbounded half (`Renderer::wrapPermissionText()` already kept 8 wrapped rows), so what this
bound actually buys is the model's tool result; a `modifiedInput` larger than the arguments it replaces
(above the 16 KiB floor) reaching a dispatcher.
**Now reachable, and deliberately:** a `modifiedInput` **above 131 KiB** — which E65's ceiling used to
forbid by accident. It is bounded by the derived ceiling instead, so on a large call a large rewrite
now works where it used to be denied.
**Still unbounded, named rather than fixed:** a hand-written PHP `HookInterface` returning a huge ASK
or MODIFY — nothing in this change touches those (that is E61's territory), and `HookResult` itself
enforces no size.

Tests: `ScriptHookTest::testALargeAskQuestionIsClipped`,
`::testAnOrdinaryAskQuestionIsNotClipped`,
`::testARewriteOverTheCeilingIsRefusedAndCarriesNoTruncatedArguments`,
`::testARewriteNoLargerThanTheCallItReplacesIsAccepted`,
`::testAnOrdinarySmallRewriteIsAccepted`. Each of the three new-behaviour tests was run against
afe3c26b first and failed for the stated reason.

## E61 — a chain of hand-written PHP hooks is bounded by nothing

**Same review, same round; named rather than guessed at.**

`HookRegistry::executeHooks()` now holds a whole-chain deadline and charges every
`BoundedHookInterface` against it. Only `ScriptHook` implements that interface, because a hand-written
`HookInterface` is a synchronous method call in this process and there is no portable way to put a
deadline on one. So:

- a chain of only hand-written hooks gets **no deadline at all** (`chainBudgetSeconds()` returns null
  rather than zero — arming zero would deny every call the built-in chain ever sees);
- a hand-written hook **spends** the chain's clock without **contributing** to it, which is deliberate
  and tested, on the reasoning that the budget bounds the terminal's frozen time and it does not
  matter to the user which hook spent it.

**Step.** If a hand-written hook ever needs bounding, the mechanism is a fiber or a fork, not another
constant — and that is a design decision with its own cost, not a follow-up edit. Recorded so a later
round reads "the chain is bounded" as the qualified claim it is.

---

**ROUND 39 SCOUT — STILL OPEN, both claims MEASURED TRUE.** A hand-written `HookInterface` that
sleeps, driven through the real `HookRegistry::executeHooks()`:

```
A: two hand-written hooks @1.5s each        elapsed=3.00s  action=allow
B: hand-written 2.0s, then a ScriptHook with timeout 1.0s
                                            elapsed=2.00s  action=deny
```

Case A: `chainBudgetSeconds()` returns `null`, no deadline is armed. Case B: the hand-written hook
**spent** the whole budget and **contributed** nothing to it, and the `ScriptHook` was denied without
ever running. Reachability derived from callers, not coverage: `chainBudgetSeconds()`
(`src/Hooks/HookRegistry.php:428`) accumulates only for `BoundedHookInterface`, and **`ScriptHook` is
its sole implementor in `src/`**; the charge/expiry check (`:502-521`) is behind the same `instanceof`,
so a hand-written hook is never even asked.

⚠️ **Not in the entry, and it is this audit's recurring defect again.** Case B's denial reads *"did not
finish within the 1 seconds their timeouts add up to"* — but the hook that HAS that 1-second timeout
never ran and consumed **zero** of it. The clock was spent by an unbounded hook the message never names.
A user reading it would raise the `ScriptHook`'s `timeout:`, which cannot help.

**Size: L as a fix** (a fiber or a fork, plus a decision about what killing an in-process hook means —
a design decision, not an edit). **S** if the outcome is to correct the denial message to name the spender.

**ROUND 41 — THE S IS FIXED (`ae30fee5`). 🔴 THE L IS STILL OPEN; do not read this stamp as closing the
entry.** A chain of only hand-written hooks still gets no deadline, and a hand-written hook still spends
the chain's clock without contributing to it. Nothing about that changed — only what the user is told
when it bites.

`HookRegistry::executeHooks()` now keeps a spend ledger (name, seconds, whether the hook is a
`BoundedHookInterface`), **accumulated across rewrite passes** rather than per pass, because on a
rewriting chain the pass that hits the wall is routinely not the pass that spent the budget. The
expiry refusal is built by a new `chainExpiryReason()` and states, in order: elapsed **next to**
budgeted; where the sum came from; the spenders largest-first, each marked counted-in-the-sum or not;
that the stopped hook ran for **0s** and consumed none of the budget its own timeout contributed; and
then the actionable half — that raising a `timeout:` will **NOT** fix it when an unbounded hook is
implicated, with the opposite advice when every spender was bounded. Bounded by
`MAX_NAMED_SPENDERS` (4) and `MAX_SPENDER_NAME_CHARS` (60), announcing the cut, because hook names come
from a YAML file and are therefore user-supplied — the same clip doctrine `ScriptHook` applies to its
own reasons.

**Two reachability claims were measured rather than assumed, and one was wrong when first written.**
(1) A first draft's comment said `timeout: 0` reaches the "no hook had run yet" branch. It does not —
`ScriptHook::timeoutSeconds()` reads zero as *unset* and answers its 60-second default, so the chain
gets a minute. The real route is a **positive sub-microsecond** timeout (`0.000001`, verified): the
budget is then smaller than the walk from arming the deadline to the first hook. (2) That branch also
exposed a self-refuting rendering — at three decimals the refusal read *"ran 0s against a 0s budget"* —
so `seconds()` now falls back to a fixed-significand form for anything that would round away.

**The all-bounded branch is pinned with a test double, deliberately.** A `ScriptHook` cannot overrun
(it kills itself at the deadline it was charged), so an all-`ScriptHook` chain can exceed its own sum
only by per-hook `proc_open`/`proc_close` overhead — total spend ≈ N × overhead against a budget of
≈ N × overhead. **Measured: four hooks each declaring 10ms denied on some runs and fitted on others.**
The first cut of that test called `markTestSkipped()` on the fitted case, which would have put a
**second skip** into a suite whose skip count is the path-repo-closure alarm. It is now a
`BoundedHookInterface` double that declares a figure and overruns it — legitimate, because
"shortening only" is `ScriptHook`'s own contract rather than the interface's, and this branch has to be
right for any implementor.

**The E60 non-collision note above still holds and is now load-bearing in the other direction:** the S
touched only the refusal's wording and the ledger behind it, so `ScriptHook` remains the sole
`BoundedHookInterface` implementor in `src/` and E60's clip design still has exactly one caller.
sugar-crush: 8909 tests / 101051 assertions / 1 skipped / rc 0 (from 8905 / 101022). Seven mutations
killed: old message restored, unbounded advice forced on and forced off, ledger unsorted, ledger
bounded-only, sub-millisecond rendering fallback removed, spender cap raised to 999.

⚠️ **Must NOT share a lane with E60** — it changes E60's premise: if hand-written hooks become
forkable, `ScriptHook` stops being the only `BoundedHookInterface` implementor and the clip design
acquires more callers.

### E62 — an interactive PTY in the chat pane is a credential-entry surface driven by model output

**Recorded 2026-08-21 while scoping Phase 9. The FIX is deferred; the FINDING is not.**

Phase 9 adds an opt-in interactive mode: a command marked interactive runs under a `candy-pty` PTY
whose output is composited **inside** the chat pane and whose prompt routes to the user. That is the
right shape for usability, and it creates a surface that does not exist today.

**The threat.** A model that can set `interactive: true` can run a command that prints bytes shaped like
a `sudo` password prompt. The user sees a prompt inside real application chrome, in the pane where the
app legitimately asks for input, and types a password. Nothing about the rendering distinguishes
"the app is asking" from "a child process printed something that looks like the app asking".

**Why this class is already known here.** Round 35's `/permissions` finding was the same shape one layer
down: `Sanitize::untrusted()` deliberately preserves LF and CR (`candy-core/src/Util/Sanitize.php:124`
strips `[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]`, excluding 0x09/0x0a/0x0d), the report joined its lines
with `implode("\n", …)`, and a newline inside a rule pattern **forged report lines** — producing a fake
`Permission mode: bypass-permissions` row under a gate that was actually `Default`. Attacker-controlled
bytes rendered inside trusted chrome. A PTY is that with a keyboard attached.

**Minimum bar when Phase 9's option (C) is built:**
1. `interactive: true` is a **permission-gated escalation**, not a free parameter — it routes through the
   existing blocking permission modal, which already exists, is tested, and is the right owner.
2. The modal states plainly that the command may ask for input, and shows the command.
3. The app's chrome around the PTY is **visually distinct from PTY content**, so a forged prompt cannot
   impersonate the app. A border the child cannot draw, or a reserved column, or colour the child
   cannot set — decided by measurement, not by assertion, since a PTY child can emit SGR freely.
4. Consider hoisting secret prompts out of the byte stream entirely via `SUDO_ASKPASS`/`sudo -A` —
   **but see the correction below; the naive version of this claim does not hold.**

⚠️ **CORRECTION to point 4, made the same day it was written.** The original wording said askpass
"keeps the secret out of the model's process entirely". That is loose in two ways and the second one
matters.

*First, the framing.* There is no local model process — the model is a remote API. The accurate claim
is that askpass keeps the plaintext out of **`sugar-crush`'s address space**, and therefore out of
anything `sugar-crush` can persist or transmit. That distinction is load-bearing **in this app
specifically**: the PTY route puts a password within reach of the transcript, of tool output that is
sent back to the model as context, and of `EnhancedSessionStore::saveCheckpoint()`, which writes
conversation state to SQLite. Password prompts disable echo, so the bytes should not round-trip — but
"should not echo" is a property of the child, not a guarantee of ours.

*Second, and this is the real limit.* **`sudo -A` only helps if the askpass helper can reach the user
without going back through `sugar-crush`.** `sudo -A` execs `$SUDO_ASKPASS` with the prompt as
`argv[1]` and reads the secret from the helper's **stdout** — no tty anywhere, which is why it composes
cleanly with Phase 9's step 1. But the helper still has to ask somebody. Its options:
- a GUI dialog (X11/Wayland) — irrelevant to a TUI over SSH, and **none is installed on this box**
  (checked: no `ssh-askpass`, no `zenity`; only `pinentry`/`pinentry-curses`);
- `pinentry-curses` or any terminal helper — **needs a tty, which step 1 just removed**, so it would
  need a *different* terminal (a second window, another tmux pane);
- a helper that sockets back to `sugar-crush` so the app renders the modal — at which point
  **`sugar-crush` handles the plaintext again and the entire advantage collapses.**

**What survives the collapse, and is the actual reason to want askpass:** the prompt becomes an
**authenticated channel**. With `-A`, the app learns that *`sudo` asked* out-of-band — via the helper's
exec — rather than by pattern-matching bytes in the child's output stream. A command that merely
*prints* something shaped like `[sudo] password for joe:` cannot trigger the modal. A PTY gives an
**unauthenticated** prompt channel: bytes in a stream that look like a request, with a live keyboard
attached. **That property is independent of who ends up typing, so it holds even in the
socket-back-to-the-app configuration** — which makes it the part worth engineering around.

*Coverage, for completeness:* askpass is per-program and covers exactly one question type — a secret.
Its siblings are `SSH_ASKPASS` (plus `SSH_ASKPASS_REQUIRE=force` on modern OpenSSH), `GIT_ASKPASS` /
`core.askPass`, and GPG's `pinentry`. **None of them covers `Continue? [Y/n]`, `apt`'s config prompts,
`psql`, a REPL, an editor, or `read` in a shell script.** Only a PTY does. So these are layers, not
rivals: the PTY is the general interaction mechanism, and askpass is how *secrets* get lifted out of it
onto a channel the app can trust.

### DECIDED 2026-08-21 by the user: **do NOT build the askpass route.** Secrets are out of scope instead.

*"so dont go the sudo askpass route it sounds like"* — accepted, and it simplifies E62 rather than
merely deferring it.

**Why askpass is not worth building here.** Its one durable benefit is the authenticated prompt
channel, and buying it costs: a helper with a channel to the user that does not route back through the
app (which does not exist in a TUI over SSH — verified, no `ssh-askpass`, no `zenity`, and
`pinentry-curses` needs the tty step 1 removes), **times four separate integrations** (`SUDO_ASKPASS`,
`SSH_ASKPASS`, `GIT_ASKPASS`, `pinentry`), **none of which covers the general case** — `Continue? [Y/n]`,
`apt` config prompts, `psql`, a REPL, `read` in a shell script. High cost, narrow coverage, and the
security benefit collapses in exactly this app's deployment shape.

🔴 **What replaces it: the interactive PTY does NOT accept secrets. That is a scope decision, not a
mitigation.** The threat in E62 is a credential-entry surface driven by model output. **Remove the
credential entry and the surface is gone** — the remaining prompts (`[Y/n]`, menus, a pager, an editor)
are low-stakes to forge, because forging them buys an attacker a keystroke, not a password.

So when a command needs a **secret**, the answer is step 1's fail-fast, with a message that names the
three real options: configure `NOPASSWD` for that command, run it yourself outside the agent, or grant
the capability some other way. **The agent does not get to hold your password.** That is a better
outcome than any amount of chrome-hardening around a password box, and it is strictly cheaper.

⚠️ **Do not let this decision delete the non-interactive ENVIRONMENT work in Phase 9 step 1.**
`GIT_TERMINAL_PROMPT=0`, `DEBIAN_FRONTEND=noninteractive`, `PAGER=cat`/`GIT_PAGER=cat`,
`SYSTEMD_PAGER=`, `sudo -n` are **not** askpass — they are how a command fails fast and legibly instead
of hanging. They stay.

**Residual to carry, honestly:** a PTY child can still *print* something that looks like a password
prompt, and a user who has been trained by the `[Y/n]` flow to type into the pane may type a secret
into it anyway. E62 point 3 (app chrome visually distinct from PTY content) therefore still applies —
reduced from "the fix" to "defence in depth", which is the right weight for it.

**Do not treat Phase 9 step 1 (detach the controlling terminal) as blocked on this.** Step 1 REMOVES a
leak and adds no surface; it should ship regardless of when (C) is scheduled.

---

### E63 — `ChatTest`'s stranded-payload detector attributes every writer's files to itself

**Found by the round-38 `lsp` implement agent, then verified by the supervisor.** Not a security
finding — a **measurement-integrity** one, which is why it is recorded here rather than left in a lane
report: it can fabricate a failure in the very suite runs this project uses to certify its floor.

**What.** `sugar-crush/tests/ChatTest.php:76` snapshots `glob(sys_get_temp_dir() . '/sc_chat_tool_*')`
in `setUpBeforeClass()`, and `:96` diffs the same glob in `tearDownAfterClass()`, asserting the
difference is empty with *"a forked tool child was abandoned with its IPC payload uncollected"*.

The diff does not measure what that message says. It measures **every `sc_chat_tool_*` file that
appeared anywhere in the shared temp dir during this class's window, from any process on the box** —
a sibling lane running the same suite, a second developer, or the user's own `sugar-crush` session.
Payload names are `prefix . bin2hex(random_bytes(8)) . '.json'` (`ToolIpcFiles::reserve()`, `:93-96`):
no pid, no run id, nothing that ties a file to its creator.

**This is THE recurring defect, and the refutation is already written down inside the class the test
calls into.** `ToolIpcFiles::STALE_AFTER_SECONDS`' docblock states the constraint plainly — the cutoff
exists to *"never delete a file belonging to a LIVE run, including another sugar-crush process on the
same box, **whose files this process cannot tell apart from its own**. Age is the only signal available
for that."* `sweep()` therefore attributes by age and refuses to attribute by identity. `ChatTest` then
attributes by identity anyway, from a weaker signal (a window), and calls the result *this run's*.

**Measured.** During the round-38 `lsp` lane's full-suite run, `/tmp` held dozens of `sc_chat_tool_*`
files with mtimes spanning 17:40–18:28 written by other lanes; the run failed on one written at 18:28,
mid-window. `vendor/bin/phpunit --filter ChatTest` alone → **OK (222 tests)**.

**Why the obvious fix is wrong.** Pointing the glob at the suite's TMPDIR sandbox instead of
`sys_get_temp_dir()` would make the detector blind to the leak it exists to catch — `toolIpcFiles()`'s
docblock (`:2477-2485`) already records why: PHP resolves and caches the temp dir once per process, so
`tests/bootstrap.php` setting TMPDIR moves it for spawned **children** and not for this process, and an
in-process `ToolIpcFiles::reserve()` still lands in the real one. That comment is correct and must
survive any fix.

**Severity.** Low as a defect, **high as a hazard to this audit**. It is false-positive-only in the
direction that matters (it cannot hide a real leak, only invent one) — but a spurious red in a
certification run is exactly the input that gets a good lane rejected or, worse, gets a supervisor into
the habit of re-running until green.

**Step.** Attribute by **identity, not by window**: have the batch record the paths it reserved and
assert *those specific paths* no longer exist, so a concurrent writer's files are structurally
incapable of entering the assertion. Keep the `sys_get_temp_dir()` choice and its docblock. Failing
that, restrict the diff to files this process could own and state the residual honestly in the message
— the current wording asserts a cause the measurement cannot establish.

**Supervisor protocol until this is fixed:** a certification run that fails ONLY on
`ChatTest::tearDownAfterClass` is not a red suite. Re-run `--filter ChatTest` alone to disambiguate
before drawing any conclusion, and prefer to take floor measurements when no lane is running a suite.
*Still LIVE, and deliberately not marked superseded.* The ROUND 39 fix below makes a
`ChatTest::tearDownAfterClass` failure a real strand **on a tree that carries the fix**, and there the
protocol above is unnecessary. It is not unnecessary anywhere else, and it is a cross-lane protocol.
Two reasons to keep it standing until *every* lane carries the fix:

1. The new `ChatTest::testTheStrandedPayloadDetectorAttributesByIdentityNotByWindow()` and
   `ToolIpcFilesTest::testAConcurrentWritersPayloadCannotEnterTheStrandedList()` deliberately create
   real `sc_chat_tool_*` files in the **shared** temp dir — correct for what they test, since the
   whole point is that a foreign writer's file must not be attributed. A sibling lane still running
   the **old** glob-based `ChatTest` can be tripped by them. So running this branch's suite can turn a
   sibling lane red through no fault of that lane's diff.
2. Conversely, a lane on an old tree can trip *this* one. Until the fix is everywhere, "fails only on
   `tearDownAfterClass`" still needs the `--filter ChatTest` disambiguation before anyone draws a
   conclusion from it.

And the E63 hazard it was written about is unchanged in its worst form: the response to a suspected
strand is **never** `rm -f /tmp/sc_chat_tool_*`. That glob is another lane's live IPC. Delete by exact
path or not at all.

**ROUND 39 (`lsp` lane) — FIXED.** Reproduced first, independently of the round-38 sighting: with the
committed `ChatTest` in place and a single `: > /tmp/sc_chat_tool_<random>.json` created four seconds
into the class window, `--filter ChatTest` fails on `tearDownAfterClass` and names that foreign file
as this run's strand. Every figure in this entry re-checked; nothing was stale.

The fix attributes by identity, as the Step asked. `ToolIpcFiles` gains an **opt-in ledger** —
`recordReservations(bool)` arms it, `reserve()` appends the path it hands out, and
`strandedReservations()` reports which of *those* paths (or their `.partial` siblings) are still on
disk. It is unarmed by default and nothing in `src/` calls it, so a real run records nothing and pays
nothing. `ChatTest::setUpBeforeClass()` arms it and `tearDownAfterClass()` asserts on it; the
`glob(sys_get_temp_dir() . '/sc_chat_tool_*')` window snapshot and its private helper are gone.

The `sys_get_temp_dir()` reasoning this entry says must survive did survive, structurally: `reserve()`
still builds every path from `sys_get_temp_dir()`, so the detector still sees the developer's real
`/tmp`, and the docblock explaining why the TMPDIR sandbox would have blinded it now sits on
`tearDownAfterClass()`.

*Residual, stated rather than hidden.* Identity attribution can only see payloads whose path came from
`reserve()` **in this process**. That covers every one today — `Chat::forkToolCalls()` and
`Runtime::executeConcurrently()` are the only callers and both go through it, and a `pcntl_fork()`
child is covered because the parent reserves the name before the fork — but a payload written by a
separate process this suite *spawns* is not. Those are exactly the files nothing can tell apart from
another developer's, which is the whole point.

*Both directions driven, not argued.* Foreign file created mid-window against the old `ChatTest` →
`Errors: 1`; the same interference against the new one → `OK (223 tests)`; a throwaway test that
deliberately strands a reserved payload → `Errors: 1`, naming that payload. Five committed tests
(four in `ToolIpcFilesTest`, one in `ChatTest`) all fail before the change and pass after.

---

### E64 — `AgentViewPane::render()`'s `max(5, …)` operation floor over-runs the pane box on wide clusters

**Recorded 2026-08-21 in the ROUND 38 lane, while making E54's `+4` claim true.** Pre-existing, not a
regression of that change; measured on both trees.

**What.** `render()` sizes the operation column with
`$opBudget = max(5, $width - Width::string($name) - 60)`. The **floor of 5** is unconditional, so on a
narrow pane `leftSection` (dot + name + status badge + operation) plus `rightSection`
(elapsed + usage) can add up to **more than `$width`** — `Width::padRight()` is a no-op once the
section already meets its target, and nothing else trims. `Style::width()` then wraps the over-long
body, and if the wrap falls inside a **2-cell cluster** the wrapped line comes back one cell wider
than the box.

Result: `render(..., $w, ...)` returns rows of **`$w + 6`** instead of the `$w + CHROME_WIDTH` = `$w + 4`
that E54 pinned. Measured with a 3-cell agent name (`abc`), an operation of three skin-toned thumbs
(`U+1F44D U+1F3FD`) or three flags (`U+1F1E6 U+1F1F8`), across the ten widths E54 was stated over:

| content width `$w` | due (`$w + 4`) | measured widest row |
|---|---|---|
| 20 | 24 | **26** |
| 28 | 32 | **34** |
| 30 | 34 | **36** |
| 40 | 44 | **46** |
| 43 | 47 | **49** |
| 44 | 48 | **50** |
| 58 | 62 | 62 |
| 60 | 64 | 64 |
| 80 | 84 | 84 |
| 98 | 102 | 102 |

Six of ten. The excess is **exactly +2** (one wide cluster) at each of those six, and over a 20→140
sweep of 121 content widths it is gone from **46** upward — above that the floor stops binding and the
body fits. *Corrected in ROUND 39: "in every case" was written here and is false over the sweep. The
sweep over-runs at **every** width from 20 to 45 inclusive — 26 of the 121, not 6 — and the excess is
+2 through 44 and **+1 at 45**. A 20,000-case fuzz over an emoji-heavy alphabet (random names,
statuses and operations, widths 1–200) put **2,211** rows off the `+4` with a worst excess of **8**
cells, so "+2, one wide cluster" is a property of the 3-cell-name fixture and not of the defect. This
is the same shape as the finding above it: a number true of the tabulated cases, written as though
true of the sweep.* An **ASCII** operation never reproduces it: it truncates to whole cells and wraps on cell
boundaries, so it comes back `+4` at every one of the 121 widths. That is why E54's original
"invariant" wording survived review — the fixture was ASCII-shaped like the property.

The sufficient condition is stated positively and holds without exception in that sweep: **if
`Width::string($leftSection) + Width::string($rightSection) <= $width`, the row is exactly
`$width + CHROME_WIDTH`.** Over 2,904 (width × payload × name) combinations there were 0 cases where
the body fit and the row still over-ran.

**Where.** `sugar-crush/src/Tui/AgentViewPane.php` — the `$opBudget = max(5, …)` assignment in
`render()`, and the `Width::padRight($leftSection, $width - Width::string($rightSection) - 1)` that
cannot claw the excess back. Identical at `087a3179`: the same ten numbers come out of that tree's
`AgentViewPane`, so this predates `CHROME_WIDTH`/`contentWidth()` and is untouched by them.

**Severity.** Low, and currently **masked**. Every agent-view frame goes out through
`clipWidth(clipTail(...), $cols)` in `sugar-crush/src/Tui/Renderer.php` (both `$frame` assignments,
`:490` and `:561`), which trims the two cells before the diff renderer — which paints one line per
terminal row — ever sees them. So it is not visible today. It is also the second backstop-masked
width bug found in this one class, which is the argument for recording it rather than shrugging: the
backstop is what would hide the next arithmetic drift too.

**Step.** Do **not** simply raise or drop the floor — the floor exists so a narrow pane still shows
*some* operation text, and changing it is a visible-output change with its own golden. The shape of a
real fix is to make the row a **budget** rather than a hope: compute `leftSection` against the space
actually left after `rightSection` and truncate it there, so `render()` never hands `Style::width()` a
body longer than `$width`, and the wrap that produces the over-run never happens. That wants its own
before/after capture of `renderAgentView()` at 20/28/30/40/43/44 columns, since it changes what those
narrow panes print. `AgentViewPaneGeometryTest::testAWideClusterOperationOverrunsTheChromeGeometryAtTheOperationFloor()`
pins the current numbers, so the change will be visible rather than silent. *(That test no longer
exists under that name — the fix inverted it into
`testAWideClusterOperationNoLongerOverrunsTheChromeGeometryAtTheOperationFloor()`, which keeps the six
numbers and asserts they are gone. See the stamp below.)*

**ROUND 39 (`lsp` lane) — FIXED.** The ten tabulated numbers reproduced exactly
(26/34/36/46/49/50/62/64/84/102 against 24/32/34/44/47/48/62/64/84/102 due), as did "gone from 46 up"
and the `087a3179` byte-identity. The two figures that did **not** reproduce are corrected in place
above: the excess is not +2 in every case, and the over-run is not confined to those six widths.

*What changed.* `render()` now measures the right section instead of estimating it, degrades the
metrics when identity + metrics will not fit `$width` (usage first, then elapsed), and clamps the
composed label — everything after the styled dot, so `truncate()` cannot cut an SGR sequence in half —
to `$width - rightWidth - 1`. The body therefore fits by construction and the wrap that produced the
over-run never happens — **fits by `Width::string`**, which is the qualification that matters and was
missing. `Width::string` is not the measure the box ends up using: `Style::render()` expands a tab to
four spaces (`candy-sprinkles/src/Style.php:969-970`) **after** the clamp has scored it 0. Delta-
debugged to a two-codepoint minimum, `operation = "\t" . U+1F3FD` makes the fixed pane return
`$width + 6` at **117 of the 121 widths 20-140**. The pre-clamp pane at `70a4efb3` returns `$width + 6`
at **120** of the same 121, so this is not a regression and E64 stays fixed — but "by construction" is
a claim about a width authority that disagrees with the renderer consuming it, and that divergence is
recorded on its own account as **E69** below.

*What deliberately did NOT change.* `$opBudget = max(5, $width - name - 60)` is untouched. The Step
above says not to raise or drop the floor, and re-deriving the budget from what actually remains would
have been worse than either: for the fixture this entry is measured on (3-cell name, `working`,
`42s  1,234 tok | $0.0042`) the exact remaining budget first exceeds `max(5, …)` at width **47** and
exceeds it at **every width above 47** — not, as this sentence said until round 39's review, "at every
width from 47 to 68". 68 is merely where `max()` stops returning 5 and starts returning
`$width - name - 60`; past that the two both grow with `$width` and the gap settles at a constant
**22** from width 69 up (measured to 200). So re-deriving the budget would have widened the operation
column at every pane wider than 46, not at 22 of them — output movement nowhere near the bug, at
widths that were never broken, and far more of it than the old wording implied. The 47 is that
fixture's; the *shape* is general, since the floor of 5 binds until `$width > Width::string($name) + 65`
while the exact budget passes 5 as soon as `$width` exceeds identity + metrics + 5. Keeping the
estimate and clamping the result is what confines the change to widths that were already broken.

*The floor's purpose, answered.* What a 5-cell operation column buys at width 20 is **nothing**: 20
content cells cannot hold `● abc [working]` (15) plus any operation plus 24 cells of
`42s  1,234 tok | $0.0042`, so the floor's promise that a narrow pane still shows some operation text
was never keepable there. The allocation now says which column loses instead of letting all of them
overflow: **the metrics give first** — a row that cannot say WHICH agent it is has nothing left to
say, and a mid-token clip of `$0.0042` reads as a smaller cost rather than as a truncated one — and
below even that the label itself is truncated. The visible consequence at width 20 is
`● abc [working] …42s`: identity intact, operation gone, usage gone, elapsed kept.

*Visible-output change, established rather than asserted.* `render()` was captured at widths 1–140
over six agent fixtures plus the empty list, before and after. For the fixture E64 was measured on
(3-cell name, wide-cluster operation) output differs at widths **2–45 and nothing at 46 or above**.
The empty-list placeholder is byte-identical at all 140. Across all seven fixtures there is **not one
width whose output changed while its old body already fitted** — checked by recomputing the old
`max(leftW, $w - rightW - 1) + rightW` for every fixture × width and cross-tabulating against the
diff. A long-name fixture changes as high as width 79, correctly: the floor binds for long names at
wider panes too, and those rows over-ran. *Which fixture, added in round 39 — the sentence named none,
in the same paragraph as the `acd27570` correction that exists to stop exactly that.* The top width at
which old and new output differ is `identity + 5 + rightWidth - 1`, where `identity` is
`7 + Width::string($name) + Width::string($status)`. With `working` (7 cells) and
`42s  1,234 tok | $0.0042` (24 cells) that is `nameWidth + 42`, so **79 is a 37-cell ASCII name**;
measured, 3 cells → 45, 29 cells → 71, 37 cells → 79. A 29-cell name reaches 71 and no further, so
"as high as 79" needs the 37-cell fixture and is not a property of long names generally.

The 20,000-case fuzz that found 2,211 over-runs on the old code finds **0** on the new one — over an
**emoji-heavy, TAB-FREE** alphabet, which is the qualification this sentence needed and did not have.
Re-run in round 39 over ASCII + accents + emoji + skin-tone modifiers + regional indicators + ZWJ +
combining marks (still no tab), old = 684 off-by, new = **0**. Admit a TAB to the same alphabet and
the new pane goes to **1,569 of 20,000** (old 2,122, worst ±8). "Finds 0" is therefore a property of
one alphabet, not of the pane, and the alphabet has to be named for the figure to mean anything.

`AgentViewPaneGeometryTest` gains four tests that fail before and pass after — the inverted pin, a
20→140 sweep over four payload shapes, the narrow-pane degradation order, and the one
`renderAgentView()` width that moved. See the E54 stamp for why that width is 44 and only 44.
*Round 39 correction: this sentence also claimed the inverted pin "keeps the six broken numbers
in-test and asserts they are gone, so the fix cannot be reverted into a test that only says +4". It
did not. The `assertNotSame` doing that work sat after `assertSame($w + CHROME_WIDTH, $widest)` and
was therefore unfalsifiable — see the E54 stamp for the full derivation. The numbers are kept as
documentation in the failure message; the enforcement claim is withdrawn.*

**ROUND 39 review — one figure held under challenge.** The `$w + 6` in this entry and in the E54
stamp was read as a claimed *excess* of +6 and challenged as "never +6; the excess is +2 through 44
and +1 at 45". Re-measured against `70a4efb3`: at `$w` = 20 the widest row is **26 cells**, i.e.
`$w + 6` ABSOLUTE and `+2` over the `$w + 4` due. Both figures are right and they are the same
measurement in two units, so the `+6` stands and the units are now spelled out wherever it appears.


---

### E65 — any registered `ScriptHook` denies every tool call whose input exceeds ~128 KiB (FIXED, round 40 `cmd`)

**Found by the round-39 scout, measured.** Not a security finding — a **daily-driver blocker**, and it
fails in the one direction that reads as someone else's bug.

**What.** `ScriptHook` passes the tool input to its child through the **environment**
(`CRUSH_TOOL_INPUT`, `sugar-crush/src/Hooks/ScriptHook.php:340-346`). Linux caps a single env entry at
`MAX_ARG_STRLEN` = 131,072 bytes. Past that the `exec` fails with `E2BIG`, the hook cannot run, and the
chain denies. Measured against a hook whose script is nothing but `exit(0)`:

```
toolInput =  131037 -> allow
toolInput =  131137 -> deny   "Hook audit could not be executed"
toolInput =  200037 -> deny   "Hook audit could not be executed"
toolInput = 1000037 -> deny   "Hook audit could not be executed"
```

Boundary pinned to between **131,054 and 131,074 bytes** of value.

**Severity.** Fail-closed, so not a hole — but a user with **any** script hook installed cannot `Write`
a file, or run a `Bash` heredoc, whose JSON-encoded arguments exceed ~128 KB. The refusal names neither
the size nor the cause; it reads as *"your hook is broken"*. The same limit is what capped the E60
MODIFY measurement at 131,054 bytes, so the two findings share a mechanism as well as a file.

**Step.** Hand the child its input through a **pipe or a temp file** instead of an env var. Bundle with
E60: bounding MODIFY at a sane size would also make this failure unreachable, so fixing them separately
means the second fix arrives with a stale premise. Whatever the outcome, the refusal must say what
actually happened.

**ROUND 40 `cmd` — FIXED, at 9ed89648. Reproduced exactly, and it is one line wider than recorded.**

Re-measured at afe3c26b, hook script `<?php exit(0);`, through `HookManager::preToolUse()`:

```
toolInput =  131037 -> allow          boundary bisected: 131054 -> allow
toolInput =  131137 -> deny                              131055 -> deny
toolInput =  200037 -> deny
toolInput = 1000037 -> deny
```

Every figure in the entry reproduces, and the boundary is **131,054 / 131,055** — exactly
`strlen('CRUSH_TOOL_INPUT') + 1 + value + 1 <= 131072`, i.e. `MAX_ARG_STRLEN` for `NAME=VALUE\0`.

🔴 **What the entry missed: `CRUSH_TOOL_OUTPUT` is the same defect on the next line.** Measured, a
PostToolUse chain with the same `exit(0)` hook: `toolOutput = 131000 -> allow`, `200000 -> deny`. It is
harder to reach — `TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES` is 65,536 — but that default is a
constructor argument, not an invariant. Fixing only the input would have left the identical bug one
line down, which is this audit's own recurring defect.

**What was changed.** The payload travels **both** routes now:

- `CRUSH_TOOL_INPUT_FILE` / `CRUSH_TOOL_OUTPUT_FILE` hold the complete bytes in a `0600` `tempnam()`
  file, set on every run in which such a file can be created (not only the oversize ones — "present
  only when large" is a conditional contract a hook author who tested on small calls would ship
  against). Deleted in a `finally`, so the timeout path, the fail-closed paths and any throw clean up
  as well as the ordinary return. Measured: 0 leftover `crush-hook-payload-*` files after a sweep.
- `CRUSH_TOOL_INPUT` / `CRUSH_TOOL_OUTPUT` are **byte-identical to before whenever the value fits**
  (`strlen(name) + strlen(value) + 2 <= 131072`). That is the compatibility guarantee: **no hook that
  works today changes behaviour**, which is why the fix is an addition and not a transport swap.
- An oversize value carries `@@CRUSH_PAYLOAD_IN_FILE@@ <n> bytes; read $CRUSH_TOOL_INPUT_FILE`
  instead. **Not a prefix** — truncated JSON is not smaller JSON and a lenient hook would judge a call
  that does not exist. **Not empty** — `docs/HOOKS.md` already tells authors to read an absent
  `CRUSH_*` as empty, so empty is indistinguishable from "no input".
- The `proc_open()` failure message now names both payload sizes instead of only
  `Hook <name> could not be executed`.

After: `131054, 131055, 200000, 1000000, 5000000 -> allow`, and a hook running
`wc -c < "$CRUSH_TOOL_INPUT_FILE"` reports the full length.

🔴 **THE HONEST COST, and it is a real widening.** A hook that reads only `CRUSH_TOOL_INPUT` and never
the file now **runs** on an oversize call and sees the marker where it used to see arguments — where
before, the call was denied outright. For an argument-inspecting guard that is a change in the
permissive direction. It is confined to calls that previously **could not happen at all** (the deny
was unconditional, so no guard was protecting anything reachable) — **on Linux with 4 KiB pages, and
only there**; see the round-41 correction below, which removes the assumption rather than restating
it. `CRUSH_TOOL_NAME` plus the `matcher:` — what most guards key on — are unaffected either way.
Stated in `docs/HOOKS.md`.

**Not modelled as a number** (and, after round 41, not needing to be): platforms that cap the whole
environment rather than one entry (macOS: 256 KiB for argv and environ together). A payload pair
passing every per-entry check can still be refused there — but the refusal is now retried with the
payloads moved onto their files, and if that is refused too it prints both sizes.

Tests: `ScriptHookTest::testAToolInputOverTheEnvironmentCeilingStillRunsTheHook`,
`::testAnOversizeToolInputIsReadableFromTheFileTheChildIsPointedAt`,
`::testAToolInputThatFitsIsStillPassedInTheEnvironmentVerbatim`,
`::testAnOversizeToolInputLeavesANonJsonMarkerInTheEnvironment`,
`::testAnOversizeToolOutputTakesTheSameRouteAsTheInput`,
`::testThePayloadFilesAreRemovedOnceTheHookHasRun`,
`::testTheEnvironmentIsUsedUpToTheKernelBoundaryAndNotPastIt` (added at e20382d4 — the others all
probe at 200 KB and would survive a 100 KB drift in the fit computation; this one is mutation-checked
against `+ 2` → `+ 1`), `::testAPayloadThatFitsNeitherRouteFailsClosed` (added at fe7c39d9), and
`HookRegistryTest::testAChainReScansARewriteTooLargeForOneEnvironmentEntry` — that last one is the
E60xE65 seam and was red at afe3c26b with `Hook bulk-rewriter could not be executed`.

Suite: **8834 / 99811 / 1 skipped / rc 0**, against a measured pre-change baseline of
**8820 / 99755 / 1 skipped / rc 0**. That is **14** new tests (9 listed under this entry, 5 under E60).

**ROUND 41 `cmd` — REVIEW RESPONSE, at 981aeb37 / bb8bfa93 / 8a756cf1 / 3b7bc6a7.** An adversarial
reviewer re-derived every figure above and confirmed them; what it blocked on was two claims and one
platform assumption.

🔴 **The assumption was the headline defence.** `MAX_ENV_ENTRY_BYTES = 131072` hardcodes
`PAGE_SIZE * 32` with `PAGE_SIZE == 4096`, and `stagePayloads()` branched on it. Where the real
per-entry limit is **larger**, the old code did not deny in `131,055 … real_limit` — it passed the
arguments **verbatim** and an argument-inspecting guard worked — so substituting the marker there is
exactly *"a hook previously saw real arguments at a size that now yields a marker"*. Two classes:
64 KiB-page Linux (ppc64le, and the aarch64 kernels RHEL and SLES ship, where `MAX_ARG_STRLEN` is
2 MiB) and macOS/BSD, which cap the total and not the entry. `sugar-crush` is in neither `MACOS_LIBS`
nor `WINDOWS_LIBS` in `scripts/affected-libs.php`, so neither is CI-tested.

**Fixed by not assuming.** `executeStaged()` offers the real bytes and retries with the file-backed
payloads moved out of the environment **only when `proc_open()` actually refuses**. Right on a bigger
page size (the first attempt succeeds); right on macOS, where the unmodelled 256 KiB total is now
recoverable instead of a hard deny; right on Windows, whose 32,767-byte limit is *below* the guess and
where the old code could not have helped. `proc_open()` is `@`-suppressed on that path — the bare call
emits `PHP Warning: proc_open(): posix_spawn() failed: Argument list too long`, which lands mid-frame
in the TUI and, under `failOnWarning="true"`, would fail this suite's own coverage of the retry. The
constant survives only to say which payload probably broke the exec.

The boundary test now **derives** its expectation from `getconf PAGESIZE` instead of typing 131,072 in,
so the length at which the marker appears is the running kernel's and not ours; it also pins
`CRUSH_TOOL_OUTPUT` at **131,053 / 131,054**, one byte below the input's, correcting a comment that
rounded that to "the same, one line down". A retry is only safe if a refusal started nothing, so that
is counted: a hook appending one byte per run leaves exactly one byte on a 200 KB payload.

🔴 **`docs/HOOKS.md` shipped a guard snippet that fails OPEN.** *"If your hook inspects arguments,
read the file, not the variable"*, with `input="$(cat "$CRUSH_TOOL_INPUT_FILE")"` labelled "correct at
every size". When `writePayloadFile()` returns null (unwritable or full `/tmp`) **and the payload
fits**, `CRUSH_TOOL_INPUT` is set verbatim, `CRUSH_TOOL_INPUT_FILE` is not set at all, and the hook
runs. Measured, same guard, two configurations:

```
normal TMPDIR       FILEVAR=[/tmp/crush-hook-payload-…]  DOC_SNIPPET_SAW=[{"command":"rm -rf /"}]  -> deny
TMPDIR is a file    FILEVAR=[]                           DOC_SNIPPET_SAW=[]                        -> ALLOW
```

Fixed in the documentation, not in `writePayloadFile()`: making the file unconditional means denying
**every** tool call when `/tmp` is full. The snippet now prefers the file, falls back to the variable,
and refuses on an empty value or a marker. Re-measured with the new snippet: **deny in both
configurations**. The prelude is a constant `ScriptHookTest` actually runs.

**Also landed, from the same review:**
- The ASK clip marker is **never visible in the modal**. `Renderer::wrapPermissionText()` keeps
  `PERMISSION_PROMPT_MAX_ROWS` = 8 wrapped rows and appends its own `… N more lines`, and the clip does
  not engage below 16,384 bytes ≈ 216 rows. Measured at 76 columns: 200 B shows the marker, 1,000 B and
  16,384 B render as 8 rows and `… 6 more lines` / `… 209 more lines`. The model-facing half is real
  (`settleAsk()` gets 16,465 bytes where it got 262,144); the claim that *the human* sees the marker is
  withdrawn from `ScriptHook`, `ScriptHookTest` and `HOOKS.md` — which also said flatly that an
  `EXIT_ASK` question is not clipped, contradicting its own table.
- `HookRegistryTest::testAChainReScansARewriteTooLargeForOneEnvironmentEntry`'s docblock claimed figures
  "MEASURED at afe3c26b through this exact path" that came from a neighbouring experiment: the rewrite
  is **200,011** bytes, not 200,014 (that is the `{"command":…}` wrapper, three bytes longer); the hook
  is **bulk-rewriter**, not `audit`; and the original is 250,011 bytes, already past the old ceiling, so
  the `E2BIG` landed on **pass 1** — the fixture is red at afe3c26b for the plain E65 reason and does
  not demonstrate the re-scan seam there at all. It does exercise it against the code as it stands.
- `crush-hook-payload-*` was in **no sweep**. The `finally` covers every in-process exit (0 leaks over a
  full run, timeout and SIGKILL paths included); a killed process is what it cannot cover, and that
  strands a 0600 copy of the tool call in `/tmp` forever. The prefix moved to
  `ToolIpcFiles::HOOK_PAYLOAD_PREFIX` and joined `sweep()`'s list — E63's class, so E63's reaper.
- The both-routes-unavailable deny printed the limit but not the payload's size. After the retry the two
  refusals are one event, so they are one message, and it names both sizes.

Tests: `ScriptHookTest::testAnEnvironmentTheKernelRefusesDoesNotRunTheHookTwice`,
`::testAFittingPayloadStillReachesAHookThatCanBeGivenNoFile`,
`ToolIpcFilesTest::testSweepRemovesAHookPayloadStrandedByAKilledProcess`, plus three assertions added
to `::testTheEnvironmentIsUsedUpToTheKernelBoundaryAndNotPastIt`. **17** new tests across rounds 40
and 41.

Suite: **8837 / 99827 / 1 skipped / rc 0**.

---

### E66 — `SkillPathNudge` is unbounded, and it is filed under a number that belongs to a different finding

**Two defects: the code one, and a tracker one that caused a supervisor to brief a lane wrongly.**

**The tracker defect.** `sugar-crush/src/Tools/BuiltIn/Grep.php:377` says *"Recorded as **E57** in the
hardening backlog"* — but `## E57` is the project-tier `disabledTools` glob. The nudge claim exists only
as a **bold label inside the ROUND 37 `lsp` narrative**, with no heading of its own, which is why the
round-38 queue summary read "E57 — `SkillPathNudge` genuinely unbounded" while the entry it pointed at
was about tool filtering. **This is the recurring defect inside the tracker itself.** Fixed by giving it
this number; the `Grep.php:377` cross-reference must be corrected to **E66** by whichever lane next
touches `src/Tools/`.

**The code defect — measured.** `SkillPathNudge::forPaths()` with N auto-invocable `paths:`-scoped skills:

```
skills =   1  descLen =   200  ->  nudge =        345 bytes
skills =  10  descLen =  2000  ->  nudge =     20,253 bytes
skills =  50  descLen = 20000  ->  nudge =  1,000,773 bytes
skills = 200  descLen = 50000  ->  nudge = 10,002,823 bytes
```

Linear in (matching skills × description length) with **no clip anywhere**: `Skill::fromFile()`
(`src/Skills/Skill.php:73`) reads `description` from frontmatter untruncated,
`SkillPathNudge.php:79` emits `- {name}: {description}` per skill, and the result is appended **outside**
`maxOutputBytes` in `Grep`/`Glob`/`Read`/`Edit`/`Write`. Announce-once per session, which bounds how
often but not how much.

**Note:** the `Grep.php:362-380` comment states the bound correctly. Only the backlog reference was wrong.

**Step.** Clip the nudge, and count it against the same budget as the tool body rather than beside it.

**FIXED, round 40 `cmd`.** Both halves. The four-row table above re-measured independently in the lane
and reproduced EXACTLY (345 / 20,253 / 1,000,773 / 10,002,823 bytes). End-to-end, over a 30-file
fixture, 20 skills x 20,000-byte descriptions: `Grep` at cap 1,000 returned 401,372 bytes (401.4x),
`Glob` 401,378 (401.4x), `Read` at `maxBytes` 200 returned 400,406 (2,002.0x) — and ONE skill with a
200-byte description already overran at 1,334 / 1,340 (1.3x), so it was never a
pathological-input-only defect.

`SkillPathNudge::forPaths()` now bounds itself in COUNT (`MAX_ENTRIES` = 8) and in BYTES per entry
(`MAX_ENTRY_BYTES` = 300, cut with `mb_strcut` so a clip cannot emit a partial UTF-8 sequence and
marked `... [clipped]`), giving a class ceiling `SkillPathNudge::maxBytes()` = 2,636 bytes. Overflow is
DEFERRED, not dropped — a held-back entry is left unannounced, so `hasPending()` still reports it and a
later call surfaces it, which is the rule `instructionSection()` follows for the same reason.
`forPaths()` also takes an optional caller budget; a budget too small for one entry returns null and
marks NOTHING.

For the second half, `Grep` and `Glob` build the nudge BEFORE clipping the body and subtract its
length from the body's cap; `Grep`, `Glob` and `Read` give it an eighth of their cap where the
instruction section takes a quarter, making `Read`'s stated total 1.375x `maxBytes`. `Edit`/`Write`
pass no budget — their result is a one-line success message with no cap to spend — so the class
ceiling is the whole bound there, the analogue of their flat `DEFAULT_MAX_INSTRUCTION_BYTES`.

Residual, stated: below a cap that depends on the case, an eighth cannot hold the chrome plus one
entry, so no nudge is emitted at all — deferred, not spent. **There is no single threshold**, because
the deferred-note reserve is charged only when something is actually held back. MEASURED by binary
sweep on `SkillPathNudge::forPaths()` (PHP 8.3.6), and independently reproduced by the round-40
reviewer and by the supervisor:

| pending × description | min budget | min cap (×8) |
|---|---|---|
| 1 × 20,000 | 434 | 3,472 |
| 2 × 20,000 | 529 | 4,232 |
| 1 × 30 | 173 | 1,384 |
| 20 × 30 | 268 | 2,144 |

Chrome is `strlen(HEADER) + strlen(FOOTER)` = 115 + 19 = 134; one clipped entry is at most
`MAX_ENTRY_BYTES` = 300; the note reserve adds 95. The shipped cap is 65,536, whose eighth (8,192)
clears every row.

⚠️ **The figures this paragraph originally carried — "roughly 4,120 bytes (1,960 for a short entry)"
and a companion "515 bytes" in `ToolOutputBudgetTest` — were WRONG, in all three cases, and stated
without naming which of the four cases they held over.** That is §5's recurring defect committed
inside the fix that closes E66. Corrected in the same round it was introduced.

**ROUND 40 SUPERVISOR REVIEW — three findings recorded, not fixed.** An adversarial reviewer found
these against the E66 fix; the supervisor reproduced them and fixed the two blocking ones (the vacuous
`Read` guard and the wrong threshold figures above) in the same round. These three are recorded instead:

- **E70 — `GrepInstructionWiringTest::testASkillIsAnnouncedForEveryHitTheModelCanSeeAtEveryCap` is now
  violable.** *(That method no longer exists. It was renamed and restructured in f8272f8f to
  `::testTheAnnounceOnceMarkIsSpentOnlyOnAHitTheModelWasToldAbout`, which is where the retitled law
  and the derived-threshold sweep now live.)* That test asserts the law `!$visible || $announced`. MEASURED on the round-40 branch:
  with 1 hit file and a skill carrying a 400-byte description, at caps 1,000 / 1,250 / 1,500 / 2,000 /
  3,000 the hit path **is** in the 62-byte result and the skill is **never** announced — the eighth
  cannot hold the entry. It flips true at 3,500. With the shipped 30-byte description the dead band is
  caps 1,000–1,250. **Not live**: the only shipped construction sites are `Bootstrap`'s
  `new Read/Glob/Grep(...)` at 1 MB / 65,536 / 65,536, whose eighths all clear the thresholds tabulated
  above. The existing test passes only because its fixture's two regimes — hit visible, entry
  affordable — do not overlap. **That is fixture luck, not structure**, and it is the exact shape §5
  describes: a law asserted over a fixture that cannot reach its own boundary.
- **E71 — `Read`'s "an eighth" and `Glob`'s +1 reservation are stated but unpinned.** MEASURED:
  `intdiv($this->maxBytes, 8)` → `intdiv($this->maxBytes, 2)` in `Read::execute()` SURVIVES the suite,
  and `$nudgeCost = ... strlen($nudge) + 1` → `strlen($nudge)` in `Glob` SURVIVES. The latter is a
  reachable 1-byte overrun when `truncateOutput()` saturates `$bodyCap` exactly; no test does.
- **E72 — `SkillPathNudge::hasPending()` does not consult `isAutoInvocable()`.** A path-scoped skill
  carrying `disable-model-invocation: true` keeps `hasPending()` true forever, so the class docblock's
  claim that "a long session pays nothing per tool call" once everything is announced is FALSE in that
  case. Driven: two consecutive `forPath()` calls both return null, `announced()` stays `[]`, and the
  registry is walked in full each time. Pre-existing; surfaced by the diff's prose.

- **CLOSED 2026-08-22 (round 41, lane c: f8272f8f + this round's fix pass).** All three shipped.
  - **E70.** The old law's fixture could not reach its own boundary and its failure message asserted
    the opposite of the truth: `SkillPathNudge::forPaths()` marks only the entries it EMITS, so an
    unaffordable nudge is DEFERRED, not retired. Replaced by
    `::testTheAnnounceOnceMarkIsSpentOnlyOnAHitTheModelWasToldAbout` (mark/emit law + the qualified
    old law, swept over a 0-hit and a 200-hit fixture so all three regimes are reached, then bracketed
    at ±1 byte on the derived `8 * floor` boundary because the 250-byte sweep step steps clean over
    the window where an eighth and a ninth disagree) and
    `::testACapTooTightForTheNudgeDefersTheSkillRatherThanRetiringIt`. The falsified sentence in
    `Grep::execute()`'s nudge-append comment is rewritten in place, not deleted.
  - **E71.** Pinned: Read's eighth
    (`SkillPathScopingTest::testReadSpendsExactlyAnEighthOfMaxBytesOnTheSkillNudge`), Glob's eighth
    (`::testGlobSpendsExactlyAnEighthOfMaxOutputBytesOnTheSkillNudge`), Glob's `+1`
    (`::testGlobsNudgeReservationHoldsTheResultInsideTheCapAtSaturation`) and Grep's `+1`
    (`GrepInstructionWiringTest::testGrepsNudgeReservationHoldsTheResultInsideTheCapAtSaturation`).
    Every threshold is derived from `SkillPathNudge`'s own pricing at runtime, never written down.
    ⚠️ **Grep's `+ 1` is NOT an over-reservation** — a comment written in f8272f8f said it was.
    `separated()` emits its newline whenever the cut does not land on one, which a byte-capped hit
    list mostly does not, so the byte is exact there and spare only at the caps where it does.
    MEASURED on PHP 8.3.6 over a 41-hit fixture, caps 200–6,000: shipped Grep over-ran nothing;
    with the `+ 1` dropped, cap 3,037 returned 3,038 bytes.
  - **E72.** `hasPending()` now applies `isAutoInvocable()`. Both halves of its skip are pinned —
    the auto-invocable half by `SkillPathScopingTest::testASkillTheModelMayNotInvokeIsNotPending`,
    the `paths === []` half by `::testTheGuardClosesOnceEveryAnnounceableSkillIsAnnounced`, whose
    fixture now carries a path-less skill (deleting that half of the guard reproduces E72's exact
    symptom through the ordinary case).
  - **Also pinned this round:** `forPaths()`'s strict `>` against `>=`
    (`SkillPathNudgeTest::testTheSmallestBudgetThatBuysANudgeIsExactlyWhatThatNudgeCosts`), and
    Glob's `max(1, …)` guard is documented as unreachable-while-the-share-is-an-eighth rather than
    left implying a live knife-edge. `forPaths()`'s deferred-note `$reserve` was reported as unpinned
    by review but is NOT: `SkillPathNudgeTest::testTheNudgeNeverExceedsTheBudgetItIsGiven` kills
    `$reserve = 0` (budget 169 returns 246 bytes).
  - **Still open, deliberately:** the shipped `Bootstrap` caps (1 MB / 65,536 / 65,536) clear the
    166–174-byte nudge floor by three orders of magnitude, so E70 is not live in production — and
    nothing asserts that. A Bootstrap-owning lane should add
    `intdiv(shippedCap, 8) >= SkillPathNudge::maxBytes()`.

The `Grep::execute()` cross-reference is corrected from E57 to E66, and the two comments in `Grep` and
`Glob` that described the nudge as living outside the cap are rewritten. `sugar-crush/docs/SKILLS.md`'s
`paths` row now states the bound.

Tests: `SkillPathNudgeTest::testTheNudgeIsBoundedHoweverManySkillsMatchAndHoweverLongTheirDescriptions`,
`::testManyShortSkillsAreBoundedByCountAndOneLongSkillByBytes`, `::testAClippedEntrySaysItWasClipped`,
`::testAClippedDescriptionStaysValidUtf8`, `::testASkillHeldBackByTheCountBoundIsAnnouncedByTheNextCall`,
`::testTheNudgeNeverExceedsTheBudgetItIsGiven`,
`::testABudgetTooSmallForOneEntrySurfacesNothingAndSpendsNothing`,
`ToolOutputBudgetTest::testGrepAndGlobStayInsideTheirCapWithAnOversizeSkillNudge`,
`::testTheSkillNudgeCannotStarveTheAnswer`, `::testReadBoundsTheSkillNudgeItAppends`,
`::testACallWithNoNudgeToShowGetsTheWholeCap`, `::testASkillTheReservationCannotHoldIsNotSpent`.
**12** new tests.

**A warning for the next fixture.** The first cut of the cap test used 30 matches under a 65,536-byte
cap. The hit list was then far inside the budget, so deleting the reservation outright left the whole
file GREEN — the mutation the test exists to kill SURVIVED it. A cap test needs the body to overflow
the cap on its own AND the nudge to be present, or it proves nothing. It now uses 400 matches at caps
8,192 and 16,384 and asserts `<system-reminder>` at each.

---

### E67 — `SkillRegistry::register()` keys by array key, not by skill name

**Reasoned from reading, NOT verified against callers — recorded at that strength deliberately.**

`sugar-crush/src/Skills/SkillRegistry.php:19-24` stores each skill under its incoming **array key**. A
list-shaped `register([$skill, …])` therefore stores under `0, 1, 2 …`, after which
`isAutoInvocable($skill->name)` misses and **every skill silently becomes non-auto-invocable**. The
round-39 scout hit this in its own probe harness.

**Whether any shipped caller passes a list was not checked.** That is the whole question: if none does,
this is a latent trap for the next caller; if one does, auto-invocation is broken in production today.
**Establish that before sizing a fix** — and note that "no test reaches it" would not settle it.

**Also noted, not a defect:** a `PostToolUse` hook's result is discarded at both call sites
(`src/Runtime.php:857`, `src/Chat.php:3250`). Runtime's comment — *a hook is OBSERVABILITY, not the
answer* — makes this deliberate. Flagged only because a user writing a PostToolUse hook that returns
DENY gets silence. A documentation gap at most.

**SETTLED AND FIXED, round 40 `cmd`.** The caller question is closed: `SkillRegistry::register()` has
exactly TWO shipped call sites, both in `SkillManager::loadAll()`
(`register($this->foreign->discoverClaude(...))` and the `discoverOpencode()` sibling). Both arrays come
from `ForeignSkillDiscovery::discover()`, which builds `$skills[$name] = $this->tag($skill, $source)`
over `SkillLoader::loadFromDirectory()`, which itself builds `$skills[$skill->name] = $skill` after
`withName($skillName)`; `tag()` copies `name` through unchanged. Repo-wide grep for `SkillRegistry`
outside the class and its tests finds no third producer. **So auto-invocation was NOT broken in
production — this was a latent trap, sized S, and it is now shut**: `register()` keys by `$skill->name`
and ignores the incoming key. The `array<string, Skill>` signature is kept, because a name-keyed array
is still the shape to pass; the key is now redundant rather than load-bearing.

**The `all()` cast STAYS, and the two defences do not disagree.** The backlog asked whether keying by
`$skill->name` makes the decimal-integer-string coercion unreachable. It does NOT, and the reason is
worth recording: PHP coerces `"123"` to `int(123)` on ANY array-key insert, so
`$this->skills[$skill->name] = $skill` for a skill named `123` stores under `int(123)` exactly as the
caller's own key did. The coercion is a property of the array, not of where the key was read.
`register()` decides WHICH name a skill is filed under; the cast decides what type comes back out of
`array_keys()`/`ARRAY_FILTER_USE_KEY`. Both comments now say so.

The `PostToolUse` note above is untouched, as briefed.

Tests: `SkillRegistryTest::testRegisterKeysByTheSkillsOwnNameNotTheIncomingArrayKey`,
`::testAMislabelledKeyDoesNotDecideWhereASkillIsFiled`,
`::testASkillRegisteredFromAListCanStillBeDisabledByName`. **3** new tests.
---

### E68 — `AgentDashboardPane` over-runs its caller's width on a single emoji

**Recorded 2026-08-21 in the ROUND 39 `lsp` lane, by the reviewer of the E64 fix.** Pre-existing and
**not fixed here** — it is a different pane from E64's and deserves an isolated diff.

**What.** `AgentDashboardPane::row()` holds a row to its `$inner` cell budget with
`Width::string($line) > $inner ? Width::truncateAnsi($line, $inner) : $line`, and
**`Width::truncateAnsi()` does not honour the budget it is given**. It counts cells to decide where to
stop but slices at codepoints, so a wide cluster straddling the cut is emitted whole and the result
comes back over budget. Measured directly: over 20,000 random cluster-heavy strings at budgets 1–8 it
returned something WIDER than its budget **3,425 times, worst +8** —
`Width::truncateAnsi(U+1F44D U+1F3FD . 'xy', 3)` returns **5 cells**. It also appends no ellipsis, so
there is no visible sign the row was cut. `clip()` (same file) uses the same call on its "N more"
trailer.

It is not a fuzz-only defect, and it reaches the screen. Driven end to end — an `Agent` whose
`description` carries emoji, through `AgentDashboardPane::render($app, $width, 12)`, sweeping
`$width` = 24..100 and asking whether the widest returned row is `$width`:

| description | over-wide at | excess |
|---|---|---|
| `Reviews code for bugs` (the existing guard's fixture) | **0** of 77 widths | — |
| `Reviews 👍🏽 code for bugs` | **35** of 77 (39–73) | +2, +1 |
| `Reviews 🇦🇸 code for bugs` | **34** of 77 (38–71) | +1 |
| `Editing 👍🏽 src/Chat.php and running the suite again` | **41** of 77 (39–79) | +2, +1 |

One emoji in one agent description is enough. That is the same shape as E64 — a pane handed an outside
budget returning more than it — in the class E64's fix did not touch. *(The reviewer who found this
reported 3,551 of 8,000 `row()` cases worst +15, and 40 of 77 widths end to end; the table above is
this lane's independent re-measurement with the fixtures named, and the two agree in shape and
mechanism while differing in count, which is what differing fixtures do. The named fixtures are the
ones to re-derive from.)*

**Where.** `sugar-crush/src/Tui/Components/AgentDashboardPane.php:326` (`row()`) and `:348` (`clip()`),
reached from `AgentDashboardPane::render()`; root cause in
`candy-core/src/Util/Width.php::truncateAnsi()`.

**Severity.** Medium. Masked today by `clipWidth(clipTail(...), $cols)` in
`sugar-crush/src/Tui/Renderer.php`, exactly as E64 was — which is the argument for recording it, not
against. A backstop is not a budget, and this is now the third width defect in this pane family that
the same backstop hid.

**Why the existing guard misses it.** `AgentViewPaneGeometryTest::testTheAgentDashboardPaneFitsTheOutsideWidthItWasHanded()`
(`sugar-crush/tests/Tui/AgentViewPaneGeometryTest.php:546`) already asserts precisely this property
and passes, because its fixture's description is `'Reviews code for bugs'` — **ASCII**. The same
ASCII-fixture blindness that let E54's `+4` be written up as an invariant, in the file whose subject
is that blindness. That guard is where the fix's regression test belongs.

**Step.** Fix `Width::truncateAnsi()` to stop before a cluster it cannot fit rather than emitting it
whole — the same rule `AgentViewPane::clusters()` already implements locally, which is the argument for
fixing it in `Width` instead of growing a third copy. Widen the existing guard's fixture from
`'Reviews code for bugs'` to the four descriptions tabulated above **before** touching production
code, so the guard goes red first. Note E69 below when choosing the authority: a clamp against
`Width::string` is not a clamp against what `Style::render()` finally lays out. Do this in its own
diff — it is a foundation change with callers outside this pane.

**FIXED 2026-08-22, ROUND 40 `lsp` lane. The stated mechanism above was wrong; the reproduction was
right.** `truncateAnsi()` does NOT slice at codepoints and was already stopping BEFORE a cluster it
could not fit — it walks `nextCluster()`, i.e. `grapheme_extract()`. The over-budget return came from
the OTHER side: `Width::string()` preferred `grapheme_str_split()`, which is **PHP 8.4+** and absent
on this PHP 8.3.6, so it fell back to `mb_str_split()` and measured per CODEPOINT. Two splitters, one
class. `truncateAnsi("\u{1F44D}\u{1F3FD}xy", 3)` returned `"\u{1F44D}\u{1F3FD}x"` — 3 cells by the
cluster measure that chose it, 5 by the codepoint measure the caller checked it with. So "fix
truncateAnsi to stop before a cluster it cannot fit" would have changed nothing.

Fix: `Width::graphemes()` now walks `nextCluster()`, so `string()` and every truncator share ONE
segmentation, and `graphemeWidth()` gained the regional-indicator-pair rule (a flag is 2 cells, a lone
regional indicator 1) that the per-codepoint sum used to supply by accident. Fuzz, 20,000
cluster-heavy strings x budgets 1-8 = 160,000 `truncateAnsi` calls: **7,966 over-budget (worst +6) ->
0**. The E68 end-to-end table above re-derived exactly (0/35/34/41 of 77 widths).

`AgentViewPaneGeometryTest::testTheAgentDashboardPaneFitsTheOutsideWidthItWasHanded()` was widened to
the four tabulated descriptions x widths 24..100 BEFORE the fix and went red at width 38. Confirmed
non-vacuous: with the fixtures reverted to ASCII it PASSES against the pre-fix `Width` over the same
77 widths and 385 assertions, so the ASCII fixture — not the narrow width list — was the blindness.

**The bound is not fully closed, and `Renderer::hardFit()` is still load-bearing.** A SECOND
disagreement survives, unrelated to clusters: `Ansi::strip()` (which `Width::of()` measures through)
eats a two-byte escape whose second byte is an ECMA-48 Fe final (`ESC \`, `ESC P`, `ESC M`), while
`truncateAnsi()`'s scanner passes `ESC [` / `ESC ]` only. Alone it makes `truncateAnsi()` stop early;
followed by a grapheme Extend it goes over. Measured over 400,000 escape- and cluster-bearing calls at
budgets 1-10: **548 over-budget, worst +1**, all of that shape, minimum `ESC M` + U+1F3FD at budget 1.
`PaneWidthInvariantTest::testTheHardFitBackstopHoldsTheBoundWhereTruncateAnsiAloneDoesNot` fired on the
fix (its first assertion PINNED the flags defect) and was repointed at this live shape, keeping the
flags case as a fixed-and-pinned equality. Worth its own finding if anyone wants the bound closed.

**Also found, out of scope:** the `grapheme_str_split() -> mb_str_split()` cascade that caused this is
duplicated in `sugar-charts` (3 sites), `sugar-table` (2), `sugar-stickers` (1) and `sugar-calendar`
(1) — **seven sites in four libs**. Each degrades to codepoint splitting on PHP 8.3 exactly as `Width`
did.

⚠️ `candy-lister` was listed here and **has no such cascade** — `grep -rl grapheme_str_split
candy-lister/src` returns nothing. Caught by the round-40 reviewer and re-verified by the supervisor.
A wrong name in a to-do list costs the next lane a wasted file-open, which is cheap; a wrong name that
makes a five-lib job look like a four-lib job is the kind of miscount this tracker exists to stop.

**And the descoping is no longer neutral.** With `Width` now walking ICU on every version and those
four libs still splitting per-codepoint on PHP 8.3, `candy-core` and its consumers **disagree about
what a cluster is** on the build this tree runs — which is the very shape E69 is about. Measured by
the reviewer in the worst case found, `sugar-table/src/Column.php::wrapCharacter()`: it gates on
`Width::of($text)` (now 2 for a toned thumb) and then sums `Width::of()` per **codepoint** (4). The
result is under-fill plus a mid-cluster split, **not** over-wide output (`thumbTone x6` at width 4
gives 6 lines, widest 2), so nothing is corrupted — but the disagreement is now real rather than
latent, and closing it is a lane, not a footnote.
`findings/README.md` already records the duplication as a shape ("repeated in 8+ libs") but not as a
correctness defect.

---

### E69 — `Width::string()` scores a tab 0; `Style::render()` expands it to four spaces

**Recorded 2026-08-21 in the ROUND 39 `lsp` lane, while qualifying E64's "fits by construction".**
Foundation-level and **not fixed here**.

**What.** `Width::string("\t")` returns **0**. `Style::render()` replaces every tab with
`str_repeat(' ', $tabWidth)` — default 4 — and does it *before* any of its own width measurement
(`candy-sprinkles/src/Style.php:969-970`, comment: "Tab expansion (before any width measurements)").
So a caller that budgets with `Width` and lays out with `Style` is using two measures that disagree by
4 cells per tab, and the disagreement is invisible to every assertion written in terms of `Width`.

This is a **width authority disagreeing with the renderer that consumes it**, which is why it is filed
on its own account rather than as a footnote under E64: any pane that clamps with `Width::string` and
renders with `Style` inherits it, and there is no per-pane fix that does not amount to each pane
re-deriving the tab rule for itself — the mistake `AgentViewPane` already made once with a local width
table.

**Where.** `candy-core/src/Util/Width.php` (`string()`, and every measure built on it) against
`candy-sprinkles/src/Style.php:969-970`.

**Measured consequence, in the pane E64 was fixed in.** Delta-debugged to a **two-codepoint** minimum:
`operation = "\t" . U+1F3FD` (a TAB plus a lone skin-tone modifier) makes the **fixed**
`AgentViewPane::render()` return `$width + 6` at **117 of the 121 widths 20–140**. The clamp
guarantees the body fits *by `Width::string`*, and that is not the measure the box uses.

**Not a regression, and E64 stays fixed.** The pre-clamp pane at `70a4efb3` returns `$width + 6` at
**120** of the same 121 widths on the same input — the clamp made this input slightly better, not
worse. What it refutes is the unqualified phrase "fits by construction", which has been qualified in
place in the E64 stamp above.

**Severity.** Medium, and broad. Low visibility (a tab inside an agent name, status or operation is
uncommon), but it silently falsifies every geometry invariant in the repo that is stated in terms of
`Width::string` and enforced against `Style`-rendered output, which by now is several.

**Step.** Decide which one is the authority and make the other agree, rather than patching callers:
either `Width::string()` charges a tab `$tabWidth` cells (needs the tab width to be reachable, which
today it is not — it is `Style` state), or `Style::render()` stops rewriting content before measuring
and the expansion moves to the caller that knows both. Whichever way it goes, land it with a test that
renders a tab-bearing string through `Style` and asserts `Width::string()` of the result equals
`Width::string()` of the input, which is the property that is false today.

**FIXED 2026-08-22, ROUND 40 `lsp` lane — and the proposed remedy is incomplete by construction.**

Authority chosen: **`Width::string()` charges the tab.** Blast radius measured both ways across the
11 libs that reference `truncateAnsi`, plus `sugar-dash`. Charging a tab 4 cells in `Width`: **0 test
failures**. Removing the expansion from `Style::render()`: **2 failures in `candy-sprinkles`**, and it
deletes the effect of a documented public API (`tabWidth()` / `getTabWidth()` / `unsetTabWidth()`,
mirroring lipgloss) — a removal, which this project's rules forbid in favour of wiring.

`Width::TAB_WIDTH = 4` is now the single number: `graphemeWidth()` charges it and `Style`'s default
`$tabWidth` reads it, so the two move together instead of agreeing by coincidence.

**A THIRD tab measure was found and fixed with it:** `Width::wrapAnsi()` charged a `\t` **1** cell in
its whitespace branch — against `string()`'s 0 and `Style::render()`'s 4. `wrapAnsi("ab\tcd", 7)`
returned ONE line of 8 cells against a 7-cell column. It now routes through `graphemeWidth()`.

**What no per-tab charge can fix, and this is the correction to the Step above.** `render()` does not
re-measure content, it REWRITES it, and substituting spaces for a tab changes GRAPHEME CLUSTERING
after the substitution. A tab is a Control character, which by UAX #29 never joins a following Extend;
a space is not, and does. So `"\t" . U+1F3FD` is `TAB_WIDTH + 2` cells while the rendered
`"    " . U+1F3FD` is `TAB_WIDTH` — the modifier joins the final space and contributes 0. That is the
same two-codepoint input this finding delta-debugged. Measured over 4,797 tab-bearing random strings,
`Style::render()` moving `Width::string()`: **4,797/4,797 (100%) at `8add627b` -> 568/4,797 (11.84%)**.
Only not rewriting the content closes it, which is `Style::tabWidth(0)`. Both the property and the
residue are pinned in `candy-sprinkles/tests/StyleTest.php`.

🔴 **CORRECTION — "all 568 are that one shape, 0 unexplained" IS FALSE, and the half it misses is the
dangerous half.** Found by the round-40 reviewer, independently reproduced by the supervisor. The
residue is **BIMODAL**: the shape above renders NARROWER than `Width::string()` predicts, and a second
shape — `\t` followed by a **ZWJ** — renders **WIDER**. The reviewer's corpus put it at 667
disagreements of 4,797 (13.90%), **425 narrower and 242 wider**, delta histogram
`+4:219 −1:217 −2:206 +6:20 −3:2 +3:1 +2:1 +8:1`. Supervisor-measured on PHP 8.3.6, directly:

| input | `Width::string(in)` | `Width::string(render(in))` | delta |
|---|---|---|---|
| `"\t"` | 4 | 4 | 0 |
| `"\t" U+1F3FD` | 6 | 4 | **−2** |
| `"\t" ZWJ U+1F44D` | **0** | **6** | **+6** |
| `"a\t" ZWJ U+1F44D` | 1 | 7 | **+6** |

**Why the +6 direction is the one that matters.** A measure that OVER-reports makes a pane under-fill;
a measure that UNDER-reports makes it **over-run**, and an over-wide row is frame corruption — the diff
renderer paints one line per row. `Width::string("\t" ZWJ U+1F44D)` returning **0** for something that
takes **6 cells** is a budget saying "this is free". Mechanism, per the reviewer: in `Width::compute()`'s
ZWJ state machine a Control before a ZWJ makes ICU emit the ZWJ as its own cluster, the
`$clusters[$i+1] === 0x200d` look-ahead then zeroes the tab, and `inZwjSequence` zeroes the emoji after
it.

**Pre-existing** — the same inputs behave identically on the pre-fix code, so this is not a regression
of the E68/E69 work and E69 stays correctly fixed for what it claimed. What is corrected here is the
**completeness claim**, which is exactly §5's defect: a figure measured over one shape, written as a
property of the whole residue. Recorded as **E73** below rather than fixed in this round — it is a
change to candy-core's ZWJ state machine, not to the tab rule, and it deserves its own diff.

**Residue also left deliberately:** a `Style` with a non-default `tabWidth` still disagrees with
`Width::string()` by `abs($tabWidth - TAB_WIDTH)` per tab; documented on `Width::TAB_WIDTH` and pinned.
Note `\SugarCraft\Dash\Components\Card\Highlight` carries its OWN unrelated `$tabWidth` field, same
name, same default of 4, settable to any value >= 1 — a fourth tab-width authority, untouched.

---

### E73 — `Width::compute()`'s ZWJ state machine scores a Control-before-ZWJ sequence 0

**Recorded 2026-08-22 by the round-40 `lsp` reviewer; supervisor-reproduced.** Split out of E69's
residue, whose "0 unexplained" completeness claim it refutes (see the correction in the E69 stamp).

**What.** `Width::string()` returns **0** for `"\t" ZWJ U+1F44D`, which `Style::render()` lays out as
**6 cells**; `"a\t" ZWJ U+1F44D` scores 1 against 7. A Control character before a ZWJ makes ICU emit
the ZWJ as its own cluster; `compute()`'s `$clusters[$i+1] === 0x200d` look-ahead then zeroes the
Control, and its `inZwjSequence` flag zeroes the emoji that follows.

**Severity.** Medium, and it is in the **over-run** direction: a caller that budgets with
`Width::string()` and lays out through `Style` is told a 6-cell run is free. Over-wide rows are frame
corruption in this tree (the diff renderer paints one line per row), which is the class §3 says to fix
rather than defer. It is bounded in practice only by how rare a tab-then-ZWJ is in a pane string.

**Where.** `candy-core/src/Util/Width.php::compute()` (the ZWJ branch), against
`candy-sprinkles/src/Style.php::render()`.

**Not a regression.** The pre-fix code scores these inputs identically. E68 and E69 stay fixed.

**Step.** Make the ZWJ look-ahead refuse to absorb a Control (or any character that cannot join under
UAX #29) rather than zeroing whatever precedes a ZWJ. Land it with the property that is false today:
for every input, `Width::string(Style::new()->render($x))` must equal `Width::string($x)` — currently
false in BOTH directions, so assert the sign of the disagreement, not just its magnitude.

**ROUND 41 — FIXED (lane b, `1b0974bc` + `79110a35` + `550dd1dd`). THE RECORDED STEP WAS TOO NARROW,
AND THE FIX IS A DELETION RATHER THAN A REPAIR.** The Step above says to make the look-ahead "refuse to
absorb a Control". That treats the Control as the special case. It is not: **the entire machine had
inverted semantics.** It was written when `string()` split per CODEPOINT (pre-E68), where a ZWJ really
did arrive as a sibling of the emoji it joined. Under the ICU cluster segmentation E68 introduced, a
bare ZWJ cluster means UAX #29 broke **before** it — nothing joined — so every clause was reading the
opposite of what it assumed. A ZWJ that genuinely joins is already **inside** one cluster and is scored
once by its base, which is why removing the look-ahead and the `$inZwjSequence` flag changes nothing
about real ZWJ sequences. `compute()` is now a plain sum of `graphemeWidth()` over `graphemes()` with no
cross-cluster state. Supervisor-verified independently before the merge: `TAB ZWJ U+1F44D` 0 → 6,
`a TAB ZWJ U+1F44D` 1 → 7, `U+1F468 ZWJ U+1F469 ZWJ U+1F467` unchanged at 2, lone U+1F44D unchanged at 2.

**The fuzz figures in the first commit message were not reproducible and were corrected in the third.**
`989 over-runs / 3,862 under-runs` came from no generator the file defines and carried neither a seed
nor a length bound. Re-run with the committed generator (`mt_srand(20260822)`, `1 + mt_rand(0, 5)`
symbols, 200,000 trials, PHP 8.3.6 / ICU 74.2): **461 over-runs (worst Δ8) and 1,669 under-runs (worst
Δ4) at `ae30fee5`; 0 and 1,670 after the fix.** The over-run family — the frame-corrupting direction —
is closed outright. Recording the corrected numbers matters more than the original claim did: a figure
without its generator is the defect this backlog keeps finding.

**E69's "0 unexplained" completeness claim is refuted and rewritten in place, not deleted** (its
tab-WIDTH conclusion still stands). That census's alphabet **contained no ZWJ**, so it could not have
seen this family — a number reported without its domain, in the very docblock that warns about domains.

`Width::isEmoji()` lost its only caller with the machine. It is **kept** (dormant code is wired or
documented, never deleted) and deliberately **not** wired into `graphemeWidth()`: measured against ICU
74.2 East_Asian_Width, the three ranges it covers that `isWide()` does not are majority-NARROW
(U+1FA00–U+1FAFF 107 wide vs 98 other; U+2600–U+26FF 31 vs 225; U+2700–U+27BF 15 vs 177), so charging
2 cells for them would over-count ~500 assigned codepoints in the frame-corrupting direction. Recorded
as a seam on its docblock plus a `phpstan.neon` `ignoreErrors` entry, following the `Concerns/Mutable`
`trait.unused` precedent. **This also unbroke a red CI job**: `candy-core` ships `phpstan.neon` at level
5 and CI runs it per-lib, so the lane was briefly shipping an unreferenced private method.

candy-core: 799 tests / 7,210 assertions / 25 skipped / rc 0 (from 795 / 7,181). candy-sprinkles green.

### E74 — `sugar-crush/README.md` repeats a project-tier claim the source records as measured FALSE

**Recorded 2026-08-22 by the round-41 lane-a reviewer.** Severity: **medium, and user-facing.**

**What.** `README.md` tells the reader that a hostile project-tier `disabledTools` "means naming every
tool it removes — a value you can see". `LayeredSettings.php`'s docblock records the measured
counterexample in the opposite direction: `{"disabledTools":["[!B]*"]}` is **eight characters** and
leaves only `Bash` enabled. The glob is a negated character class, so a short pattern removes an
unbounded set without naming any of it.

**Why it matters more than a doc nit.** This is the sentence a user would rely on when deciding whether
a cloned repo's settings need reading. It advertises a safety property the code does not have, and it
sits in the file most likely to be read and least likely to be re-derived. The tier design is sound —
this is the documentation of it that is wrong.

**Step.** Rewrite the claim in place (never delete it) to state what is true: project tier can *remove*
tools with a pattern far shorter than the set it removes, which is why the dangerous keys are
user-tier-only rather than why `disabledTools` is safe. Cite the eight-character counterexample.

**FIXED — round 42, lane a.** `README.md`'s tool-tier paragraph now retracts the claim in place, quoting
it and marking it false, and carries the counterexample, the mechanism
(`Bootstrap::filterToolSet()` → `PermissionRule::matchesToolName()` → bare `fnmatch()`), the two
measured mitigations (untrusted projects never reach the merge; a user's own `disabledTools` replaces
rather than unions) and the launch report. Pinned by
`tests/Config/ReadmeSettingsTierClaimTest`.

**PIN REPAIRED, round 42 fix pass.** The first cut of that pin did NOT hold. Its prose test
(`::testWhereverTheReadmeQuotesTheRetractedClaimTheCounterexampleIsRightThere`) asked only that the
counterexample and the words "That is false" appear within 2000 characters FORWARD of the retracted
quote — and the retraction itself supplies both, so the false sentence could be restored verbatim as
body prose immediately above the retraction and the file stayed green (measured: the counterexample
sits +600 characters from the quote, "That is false" +57, leaving ~1400 characters of slack in front of
the retraction for a restored occurrence to hide in). It now uses the same STRUCTURAL rule the E75 test
already used — the retracted wording may appear only on a `>` line — scanned per PARAGRAPH rather than
per line so a re-wrap cannot straddle a fragment across a break, and against three fragments rather
than one needle. Two mutations confirm it: the sentence restored as body prose, and the same re-wrapped
so no single line carries a whole fragment — both rc=1.

Two corrections to this entry, both re-measured in-lane on
PHP 8.3.6:

- **"eight characters" counts nothing.** `[!B]*` is five characters, `"[!B]*"` seven, `["[!B]*"]` nine,
  and `{"disabledTools":["[!B]*"]}` twenty-seven. Nothing here is eight. The README says *five
  characters of glob* and `docs/SETTINGS.md` was corrected to match.
  `src/Config/LayeredSettings.php` (`PROJECT_TIER_KEYS`) and
  `Bootstrap::reportProjectTierToolRemovals()` still say "eight" — **not fixed here**, both are outside
  this lane's write scope. So does `crush_code.md`'s round-41 status line (historical log, left alone).
  **CORRECTION, round 42 fix pass:** the first stamp said *two* doc-blocks still carried the figure.
  There were three: `docs/SETTINGS.md` itself contradicted its own re-derivation sixty lines further
  down ("refusing negated classes would close the eight-character version"), inside the file the same
  commit had just corrected. Fixed now.
- **CORRECTION, round 42 fix pass — `Bootstrap::tools()` does NOT memoise.** The round-42 report
  justified its one-process-per-row probe design with "`Bootstrap::tools()` memoises within a process",
  offered as the lesson from a first probe that false-negatived on the trusted row. That mechanism claim
  is false and was written down without being verified (RULE 11). `Bootstrap::tools()` constructs
  `new Bash($root)`, `new Read(...)` and the rest unconditionally on every call; it holds no cache.
  The per-process staleness is `Bootstrap::$trustedSettingsRoots`, filled by
  `Bootstrap::projectSettingsTrusted()` and keyed by `trustedConfigDirPath() . '/config.json'` — a
  deliberate freeze, documented on the property as "one answer per process, so a mid-session edit to
  the user's OWN config.json cannot widen the grant a launch already decided". Granting trust after a
  first untrusted `tools()` call in the same process therefore changes nothing *while the sandboxed
  HOME stays the same*, and changes everything the moment the sandbox path (and so the cache key)
  moves. One process per row is still the right probe design; the reason it is right is the trust-list
  freeze, not a tool cache. **The measured results are unaffected.** All four rows of the round-42 table
  were re-run in this lane, one fresh PHP 8.3.6 process each
  (`scratchpad/probe/row.php`, `scratchpad/probe/row_nonneg.php`; sandboxed `HOME`, `chmod 0600` on
  `config.json`, trust via `LayeredSettings::PROJECT_SETTINGS_TRUST_KEY` + `realpath()`): no project
  file / trusted → 11; `{"disabledTools":["[!B]*"]}` untrusted → 11 and nothing printed;
  the same trusted → 1 (`Bash`) plus the launch report; `["[C-Z]*","[a-z]*"]` trusted → 1 (`Bash`).

  RE-DERIVED IN-LANE, not taken from the reviewer (RULE 9). Generator:
  `scratchpad/probe/memo2.php`, ONE PHP 8.3.6 process, three `Bootstrap::tools($root)` calls, sandboxed
  `HOME` via `putenv()` + `$_SERVER['HOME']`, `chmod 0600` on each `config.json`, project
  `.sugar-crush/settings.json` = `{"disabledTools":["[!B]*"]}` throughout, trust granted via
  `LayeredSettings::PROJECT_SETTINGS_TRUST_KEY` with `realpath()`. No fuzzing, no seed, no ICU.
  `Bootstrap::$trustedSettingsRoots` read back by reflection after each call:

  | call | HOME | `config.json` | tools | `$trustedSettingsRoots` after |
  |---|---|---|---|---|
  | A | `home_one` | `{}` (no grant) | 11 | `{home_one/...: []}` |
  | B | `home_one` | grant written | **11** — the false negative | unchanged (key already present) |
  | C | `home_two` | grant written | **1** (`Bash`) | second key added, `[repo]` |

  Call C is the THIRD `tools()` call in the same process and it returns the filtered set. A tool cache
  would have made C 11. The only thing that changed between B and C is the cache KEY.

  ONE TRAP WORTH RECORDING, because it cost a probe: write the sandbox config as `{}` and not as
  `json_encode([])`. PHP emits `[]` for an empty array, `Bootstrap::permissionConfig()` refuses a
  top-level JSON array (`PermissionConfigException`, "the top level is not a JSON object"), and
  `projectSettingsTrusted()` swallows that through its `catch (\Throwable)` and returns false
  **without caching**. Row B then reads 1 instead of 11 and the freeze appears not to exist. Use
  `JSON_FORCE_OBJECT`. See the new sub-finding below.

- **NEW, found while re-deriving the above — the `trustedProjectSettings` freeze does not engage until
  `permissionConfig()` has succeeded once.** `Bootstrap::projectSettingsTrusted()` populates
  `self::$trustedSettingsRoots[$path]` INSIDE the `try`, so any throw from `permissionConfig()` returns
  false and leaves the key absent; the next call re-reads `config.json` from disk. MEASURED, PHP 8.3.6,
  one process, same generator with the empty config written as `[]`: call A (untrusted) → 11 tools and
  `trustedSettingsRoots == []`; the config is then rewritten with a grant; call B → **1 tool**, i.e. a
  mid-process edit to the user's own `config.json` widened a grant the process had already decided —
  exactly what the property's doc-block says the freeze buys ("one answer per process"). REACHABILITY
  IS NARROW and this is why it is recorded rather than fixed: the only way to reach the un-cached state
  is a `config.json` that `permissionConfig()` rejects, and a real launch calls `permissionConfig()` on
  its own path and refuses to start on that same file. It is a hazard for in-process embedders and for
  tests, not for the CLI. **Functionality before hardening — FINDING recorded, FIX deferred.** Step:
  cache the negative outcome too (an explicit sentinel), or state on the property that the freeze is
  conditional on a parseable config and that an unparseable one fails open across calls.
- **The negation is not the mechanism.** `["[C-Z]*", "[a-z]*"]` also leaves exactly `Bash`, measured
  end-to-end, so the Step's framing of the counterexample as *the* negated-class case understates it:
  no restriction on pattern shape could restore the retracted property.

### E75 — `README.md` calls `config.json` "the deprecated name"; the source argues at length that it is not

**Recorded 2026-08-22 by the round-41 lane-a reviewer.** Severity: low, but actively misdirecting.

**What.** `LayeredSettings.php` documents that `config.json` is the only file that is ever *written*,
and that calling it deprecated points users away from the file their changes actually land in.
`README.md` calls it deprecated anyway. Two source-of-truth statements, one of them load-bearing for
anyone trying to find their own settings.

**Step.** Reconcile in `README.md`, in favour of the source docblock. Rewrite, do not delete.

**FIXED — round 42, lane a.** The ranking paragraph in `README.md` no longer calls `config.json`
deprecated; a block quote records what it used to say, why the word was damaging (it pointed readers
off the only file that receives a write) and what is true instead. Verified against the write path
rather than against the other doc: `Bootstrap::writeUserConfig()` → `Bootstrap::userConfigPath()` →
`configDirPath() . '/config.json'`, and the only two `@deprecated` tags anywhere in `sugar-crush/src/`
are on `Agents/PathJail` and `Chat::pool`'s alias — neither mentions `config.json`. Both halves are
pinned by `tests/Config/ReadmeSettingsTierClaimTest`.

### E76 — `Chat.php`'s pane-click docblock asserts the opposite of what `bin/sugarcrush` does

**Recorded 2026-08-22 by the round-41 lane-a reviewer; deliberately not fixed in-lane.**
Severity: low (comment-only), but it is a **load-bearing argument** that a future reader would act on.

**What.** The docblock states that the `App` / `Tui\Renderer` system is one "that nothing constructs
(`bin/sugarcrush` runs THIS model)". `bin/sugarcrush:225` constructs `new Program(Bootstrap::app(...))`,
so `App::view()` → `TuiRenderer::renderView()` **is** the live path. `Renderer.php`'s class docblock
already carries the corrected account, so the tree contradicts itself.

**Why it was left.** Rewriting a load-bearing justification is a rule-13 change that deserves its own
round rather than a drive-by inside an unrelated item. Lane a established the truth by tracing the
launch path (which is how it knew which renderer to edit) and recorded it rather than acting on it.

**Step.** Rewrite the `Chat.php` docblock in place, recording what it used to say and why it was wrong.
Confirm first whether `Tui\Renderer::statusBar()` is genuinely dead on the live path — lane a measured
that it is, because `renderView()` sets `$bottom = ''` whenever `$a->chat !== null`, which is always
true on a real launch. If so, say *that*, rather than that nothing constructs the system.

**ROUND 42 `lane c` — FIXED. THE ENTRY WAS RIGHT ABOUT THE FALSE CLAIM AND MISSED A SECOND ONE IN THE
SAME DOCBLOCK.**

**Launch path, traced rather than accepted.** `bin/sugarcrush` ends in
`new Program(Bootstrap::app($args->root), Chat::programOptions())`; `Cli\Bootstrap::app()` builds the
`App` and calls `->withChat(self::chat($root))`; `App::view()` calls `Tui\Renderer::renderView()`. So
the entry is correct: the system IS constructed and `nothing constructs it` was false.

**`Tui\Renderer::statusBar()` is dead on that path — re-confirmed.** `renderView()` sets
`$hosted = $a->chat !== null` and `$bottom = $hosted ? '' : InputPane::render(...) . "\n" .
self::statusBar($a)`, and `Bootstrap::app()` always attaches a chat. Round-41 lane a's finding holds.
**Already pinned, so not re-pinned:** `AppModelTest::testHostedFrameHasExactlyOneInputBoxAndOneStatusBar()`
asserts `Switch Pane` absent from a hosted frame and `::testViewRendersTheShellChrome()` asserts it
present un-hosted. `Renderer\StatusLineSegmentTest`'s class docblock already narrates it.

🔴 **THE ENTRY'S SUGGESTED REPLACEMENT WOULD HAVE LEFT A SECOND FALSEHOOD STANDING.** The Step says to
say "constructed, but this particular method is unreachable because …". That is the right shape for
`statusBar()`. It is the wrong diagnosis for `Chat::selectPane()`, whose docblock went on to conclude
that "jumping a pane field **no live frame reads** would be a switch the user can never see" — and
**the live frame does read `App::$pane`**: `renderView()` diverts `Pane::Agents` to the full-width
dashboard before any sidebar exists, `leftSidebar()` branches on `Pane::Files`/`Pane::Tools`, and
`rightSidebar()` branches on `Pane::Skills`/`Pane::Settings`. A pane switch is plainly visible. A third
sentence lower in the same docblock — "Files/Tools/Skills/Settings/Help have NO live surface on this
path at all" — was false for the same reason, and is rewritten too.

🔴 **ROUND-42 REVIEW CORRECTION, applied: the rewrite above introduced a THIRD falsehood about `Help`.**
This stamp and the docblock both said "`Help` alone was right: it has no `Pane` case". `src/Tui/Pane.php`
declares `case Help = 'help';`, `Pane::Help->label()` returns `'Help'`, and `tests/Tui/PaneTest.php`
asserts `value`, `label()` and `from('help')`. Only the *arm* half held — nothing in `src/` matches on
`Pane::Help`. And it has a live surface by the same criterion used to condemn the sentence for
Files/Tools/Skills/Settings: `MenuBar::paneTabs()` renders `'Currently: ' . $a->pane->label()`
unconditionally. Measured at 120x40, line 0 reads `… Currently: Help` for `Pane::Help` against
`… Currently: Chat` for `Pane::Chat`, and the two frames differ — and it was already pinned all along:
`ComponentTest::testMenuBarWithDifferentPaneLabels()`'s all-panes `Currently:` table has a `Pane::Help => 'Help'` row. The docblock now records the
correction in place rather than deleting it (RULE 7); the argument it was attached to — these panes
lack a *writer* reachable from `selectPane()`, not a surface — is unchanged and covers `Help` too.

**The real reason `selectPane()` cannot take §8 E3's `$app->withPane(...)` sketch is ownership, not
reachability.** `Chat::update()` returns `array{0:self,1:?\Closure}`;
`App::delegateToChat()` re-wraps the returned Chat with `withChat()`. There is no channel from a value
this method computes to the host's `$pane`, and Chat holds no reference to its host. That is what the
docblock now says.

**A dormant seam found while verifying, kept and recorded per RULE 6.** `App\SelectPaneMsg` exists,
`App::update()` answers it with `withPane($msg->pane)`, and `delegateToChat()` passes Chat's Cmd
straight up to `Program` — so a Cmd dispatching a `SelectPaneMsg` **would** reach the host. **Nothing
in `src/` or `bin/` constructs one** (`grep -c 'new SelectPaneMsg' src/ bin/` → 0; the 5 textual hits
are the class definition and `App::update()`'s two match arms). Only `tests/App/AppTest.php` and
`tests/App/AppModelTest.php` build one. Wiring it is a behavioural change and was out of scope for a
comment-only item, so it stays a seam — and
`HostedFrameReadsThePaneTest::testNothingInSrcConstructsASelectPaneMsg()` now reds the day somebody
wires it, which is the commit on which the docblock's ⚠️ paragraph becomes wrong.

🔴 **ROUND-42 REVIEW CORRECTION, applied: as first written that census could not see the wiring it
existed to catch.** The regex `/\bnew\s+(\\[\w\\]+\\)?SelectPaneMsg\s*\(/` requires a LEADING
backslash on its optional namespace prefix, so it matched `new SelectPaneMsg(` and
`new \Fully\Qualified\SelectPaneMsg(` and was blind to `new App\SelectPaneMsg(` — the relative form
`Chat.php` would use, since it sits in `SugarCraft\Crush` and imports no `SelectPaneMsg`. Measured: with
that arm added to `selectPane()`'s match the test stayed green, and reflection on the mutated method
really did return a `SugarCraft\Crush\App\SelectPaneMsg`, so it was a producer and not a phantom.
It also scanned only `src/`, while this entry claimed `src/ or bin/`. Both are fixed: any namespace
prefix or none, `use … as` aliases resolved, `bin/sugarcrush` included, plus a second assertion pinning
the exact set of production files that so much as name the symbol (`src/App/App.php`, `src/Chat.php`) —
which is what catches an alias import landing in a file not already on the list. All four construction
syntaxes were driven as mutations and all four now red. **Residual, stated rather than papered over:**
`$c = SelectPaneMsg::class; new $c(…)` inside a file already on the allowlist is invisible to a textual
census. That is the floor of this technique, not an oversight.

**New file `tests/App/HostedFrameReadsThePaneTest.php`** pins the corrected behavioural claims: the
hosted frame's left sidebar follows `Pane::Files`/`Pane::Tools`, its right sidebar follows
`Pane::Skills`, `Pane::Agents` diverts to the full-width dashboard, and the seam census above.

⚠️ **A TESTING HAZARD WORTH ONE LINE, because it cost a survived mutation here.** "Frame A differs from
frame B when the pane changes" proves nothing about the panes: `MenuBar::render()` prints
`Currently: <pane>` on line 0 for *every* pane, so two frames always differ. The first version of the
right-sidebar test asserted `assertNotSame($files, $skills)` and **survived** deletion of
`rightSidebar()`'s entire `Pane::Skills` arm. It now asserts the `╭ skills ` border title, and that
mutation reds.

### E77 — `nextCluster()`'s no-ext-intl fallback is now measurably wrong for real ZWJ sequences

**Recorded 2026-08-22 by the round-41 lane-b reviewer.** Severity: **latent — currently unreachable.**

**What.** The removed ZWJ machine (E73) was what compensated for the hand-rolled fallback segmenter.
With it gone, the fallback scores `U+1F469 ZWJ U+1F4BB` as **4** where ICU scores 2 — over-counting a
joined sequence by 2 cells per extra emoji. Measured under `-d disable_functions=grapheme_extract`
(U+1F468 family: 2 before, 8 after).

**Why it is not scheduled.** `candy-core/composer.json` **hard-requires `ext-intl`**, so the fallback
cannot execute in any supported configuration. It is a seam, kept rather than removed.

**Step.** None while the requirement stands. If anyone ever relaxes `ext-intl` to a suggestion, this
becomes live and must be fixed in the same change — cross-reference this entry from the composer edit.

### E78 — nothing ties the shipped `Bootstrap` tool caps to the skill-nudge floor

**Recorded 2026-08-22 by the round-41 lane-c reviewer; the file was held by another lane.**
Severity: low today, medium if a cap ever moves.

**What.** E70's dead band (a cap too tight to afford the nudge) is not live in production only because
`Bootstrap.php` constructs `Read`/`Glob`/`Grep` with 1 MB / 65,536 / 65,536, whose eighths (131,072 /
8,192 / 8,192) clear the 166–174-byte nudge floor by three orders of magnitude. **No test asserts
that.** A future Bootstrap cap below roughly 1,400 bytes would silently reopen the band that lane c
just spent a round pinning at the unit level.

**Step.** In a Bootstrap-owning lane, one assertion per tool:
`intdiv($shippedCap, 8) >= SkillPathNudge::maxBytes()` (or the derived floor). It is the cheap guard
that makes the unit-level work above actually protective.

**CLOSED round 42, lane b** — `SkillPathScopingWiringTest::testEveryShippedNudgeBudgetClearsTheTrackerCeiling()`
and `::testEveryShippedNudgeBudgetClearsTheWorstCaseDeadBandFloor()`, both deriving their threshold
from `SkillPathNudge` at runtime and their roster from the `intdiv($this-><cap>, <n>)` in each tool's
own source. **Two figures in the "What" above did not survive re-measurement, and the mechanism
sentence has a third error:**

- *Wrong.* `Bootstrap.php` does not construct `Read`/`Glob`/`Grep` with those caps. `Bootstrap::tools()`
  passes `$root`, `instructionLoader:` and `skillNudge:` and **no cap at all**; 1,048,576 / 65,536 /
  65,536 are the tools' own `DEFAULT_MAX_BYTES` / `DEFAULT_MAX_OUTPUT_BYTES` defaults. A guard written
  against a literal in `Bootstrap.php` would have had nothing to read. The shipped caps are therefore
  read off the constructed instances.
- *Wrong.* The nudge floor is not 166–174 bytes and the eighths do not clear it "by three orders of
  magnitude". Round 41's own edits to `SkillPathNudge` moved the pricing. **Measured on this tree, PHP
  8.3.6:** `SkillPathNudge::maxBytes()` = **2,636**; the worst-case price of ONE entry (a description
  past `MAX_ENTRY_BYTES` so the entry clips, plus a second pending skill so the deferred-note reserve
  applies) = **529**. Against the ceiling, Glob's and Grep's 8,192 clear it by **3.1x** — half an order
  of magnitude.
- *Wrong.* The reopening threshold is not "roughly 1,400 bytes". The ceiling guard reds below a cap of
  8 x 2,636 = **21,088**; the dead band itself opens below 8 x 529 = **4,232**. Fifteen times and three
  times the recorded figure respectively.
- *Right.* Nothing asserted any of it, and the eighth relationship still holds: all three tools spend
  `intdiv($cap, 8)`, and `Edit`/`Write` still pass `null` (no cap of their own to spend inside).

**Mutation.** `new Grep($root, instructionLoader: ..., skillNudge: ...)` -> `new Grep($root, 1024,
instructionLoader: ..., skillNudge: ...)` reds both tests (budget 128, under both 2,636 and 529);
Read's budget argument -> `null` reds the roster assertion.

### E78/round-42 follow-up — a repeat prune in one process prints its session ids under no header

**Recorded 2026-08-22 by the round-42 lane-b agent.** Severity: cosmetic; stderr only.

**What.** Round 42 split `Bootstrap::reportPrunedSessions()`: the one-line summary now goes through
`warnPermissionConfigInTranscript()` (both channels) and the per-session id rows stay raw on stderr.
The summary inherits `warnPermissionConfigOnce()`'s **per-process** de-duplication; the id rows are
unconditional. So a second `chat()` in one process whose prune produces a byte-identical summary
prints its id rows with no header above them.

**Why it is not fixed.** The alternative is a second de-dup map keyed on the detail, which would drop
rows from the channel that exists to be the complete unclipped record — the property
`LAUNCH_NOTICE_MAX_CHARS`'s doc-block relies on when it advertises "full text on stderr". The rows are
self-describing (`sugarcrush:   <id> (last used ... UTC, N messages)`), and a launch only reaches this
by building two Chats in one process, which no shipped entry point does.

**Step.** Leave it. If a second de-dup is ever wanted, key it on the report rows and not on the
summary, and keep the rule that stderr never loses a row the transcript never carried.

### E79 — the tab/Extend under-run family in `Width` is a rendering-semantics decision, not a bug

**Recorded 2026-08-22 by the round-41 lane-b reviewer.** Severity: low; **in the safe direction.**

**What.** 1,670 of 200,000 fuzz strings (worst Δ4) still under-count. Mechanism: `Style::render()`
rewrites `\t` → spaces *before* measuring, and a space — unlike a Control — absorbs a following Extend.
So `\t` + U+1F3FB is 4+2=6 unexpanded but 4 expanded.

**Why it is not a fix.** Closing it requires deciding that **an orphan Extend cluster scores 0**, which
would change `Width::string("\u{1F3FD}")` from 2 to 0 across the entire foundation and changes what a
terminal is expected to paint for a lone skin-tone modifier. That is a semantics change with blast
radius well beyond this class. Under-counting also cannot corrupt a frame here — over-wide rows can.

**Step.** Decide the semantics deliberately, in a round of its own, with the foundation-wide blast
radius costed first. Its shape is already pinned by
`StyleTest::testExpandingATabCanStillReclusterAFollowingCombiningMark`, so it cannot drift unnoticed.

### E78/round-42 follow-up — two `NonInteractive` stderr writes are transcript-seam candidates the seam cannot reach

**Recorded 2026-08-22 by the round-42 lane-b fix agent, after the round-42 review.** Severity: low;
**functionality, not security** (RULE 14 records it rather than fixing it).

**What.** Round 42 claimed on `bin/sugarcrush` and on
`Tests\Integration\BinSugarcrushAutoloadGuardTest` that "every other stderr write in this codebase was
re-examined" against `Bootstrap::warnPermissionConfigInTranscript()`. It had not been. Measured
myself, `grep -rn 'STDERR' src/ bin/` on this tree, PHP 8.3.6 — **eleven** raw `fwrite(STDERR, …)`
call sites: `Cli\NonInteractive` **six** (`run()` twice, `failUsage()`, `failUnusableProvider()`,
`noticeOfflineDefault()`, `readStdinIfPiped()`), `Cli\Subcommands` **two** (`sessionDelete()`,
`mcp()`), `Cli\Bootstrap` **two** (`warnPermissionConfig()`, which *is* the stderr channel, and
`reportPrunedSessions()`'s id rows), `bin/sugarcrush` **one**. Both doc-blocks are corrected in place
per RULE 7. Note the review's own distribution was slightly off in the same total: it recorded
NonInteractive at five (missing `noticeOfflineDefault()`, whose `fwrite(` and `\STDERR,` are on
separate lines) and Bootstrap at three (counting the summary that is no longer a raw write).

Two of the eight unexamined writes satisfy the routing rule the round applied — *a warning reaches the
transcript iff it names something the session can no longer **do***:

- `NonInteractive::readStdinIfPiped()` — `sugarcrush: piped stdin exceeds 10MB cap; truncating.` The
  model is handed a truncated prompt and is never told it is answering half a question. This is the
  stronger of the two.
- `NonInteractive::noticeOfflineDefault()` — the session is answering from the offline echo provider
  because no provider is configured.

**Why it is not fixed here.** Out of E78's scope, and the seam does not reach these paths as it
stands: `Bootstrap::launchNotices()` is read by `Bootstrap::chat()` and `Bootstrap::app()` and by
**nothing else** (verified, `grep -rn 'launchNotices' src/ bin/`), so a notice recorded from the
one-shot `-p` path or from a subcommand goes into a static the process discards. Telling the model
about a truncated stdin is therefore not a seam call — it is a decision about appending a note to the
one-shot *prompt*, which is a different mechanism with a different blast radius.

**Step.** Decide it as a one-shot-path question, not as a launch-notice question. Either
`NonInteractive` grows its own way of prefixing a system note onto the outgoing message, or
`launchNotices()` gains a reader on that path. Do not "migrate" these onto
`warnPermissionConfigInTranscript()` on the strength of the rule alone — measure first that anything
reads the list back, because today nothing on those paths does. The six writes that accompany a
non-zero exit (`run()` x2, `failUsage()`, `failUnusableProvider()`, and both in `Subcommands`) need no
decision: the process ends, and there is no transcript for a row to survive into.
### E80 — `MultiAgentRefactorTest`'s forked claim race aborts at 60s under concurrent machine load

**Recorded 2026-08-22 by the round-42 lane-c implementer.** Severity: **CI flake, not a product
defect** — but it turns a green suite red and it is not obvious from the failure text why.

**What.** `Integration\MultiAgentRefactorTest::testArchitectPlansTwoCodersImplementInParallelReviewerVerifiesLeadMerges`
was reported **risky — "aborted after 60 seconds"** on one full-suite run and clean on the next, same
tree, same commit, nothing between the two runs but load. Both figures are mine and both are
reproducible only in the statistical sense:

| run | tests | assertions | skipped | risky | rc | wall |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 8982 | 105,026 | 1 | 1 | **1** | 06:22 |
| 2 | 8982 | **105,048** | 1 | 0 | **0** | 04:12 |

The 22-assertion gap is *consistent with* that one test being the whole of the difference: it asserts
25 in isolation (re-measured by a reviewer at `OK (1 test, 25 assertions)`, 00:00.114 — the 0.128 s
first reported was a different run of the same command), and 25 − 22 = 3 would then be what landed
before the abort.

⚠️ **That "3" is arithmetic, not a measurement, and the inference is circular if read the other way
round.** Nobody exported the assertion count at the moment of the abort; run 1's 105,026 is by
construction not reproducible. What is actually established is: exactly one test went risky, and the
gap is small enough to be that one test. Treat "3 landed" as a consequence of assuming the abort
explains the gap, never as evidence for it.

**Mechanism, as far as it was chased.** The test does not touch `ProcessExecutor`. It `pcntl_fork()`s
two real children that race for one task through `flock()` on a SQLite file, with a start barrier and
a spin whose backoff is capped: `usleep(min(20_000, 1_000 * $attempts) + (crc32($coderId) % 3_000))`.
A **capped** backoff under a loser that keeps re-attempting is a lock-starvation shape — the delay
stops growing at 20 ms however long contention lasts — and the machine was running sibling lanes'
suites concurrently. That is a hypothesis consistent with both runs, not a measurement; nobody has
instrumented the attempt count at the moment of the abort.

**Why it is not fixed here.** Diagnosing it properly means running that one test under synthetic load
with the attempt counter exported, and the honest fix is probably an uncapped (or much higher-capped)
backoff plus a bound on total attempts — a change to a concurrency test's timing, which is exactly the
kind of edit that should not ride along in a lane about docblocks and pane anchors.

**Step.** Reproduce under deliberate load (`stress-ng`, or two suites in parallel) rather than by
re-running and hoping. Export `$attempts` at abort. Then decide between raising the backoff cap and
raising the 60 s limit for this one test — and prefer the backoff, because a 60 s limit that is
routinely brushed is a limit that has stopped meaning anything.

### E81 — `LayeredSettings`' `provider` doc-block credits the palette and omits the command that actually writes it

**Recorded 2026-08-22 by the round-42 lane-a implementer.** Severity: low, doc-only.

**What.** `src/Config/LayeredSettings.php` says the `provider` value comes from "the Ctrl+P palette's
'Switch Model' action". Verified by following the write, not by reading the neighbouring doc: the
`/model <name>` slash command routes through `Chat::handleModelCommand()` directly into
`Chat::selectPaletteProvider()`, which is the site that invokes `onConfigChange('provider', …)`. The
README's table, which credits `/model`, is the accurate one. Two docs disagree and the less-read one is
right — the same shape as E74.

**Step.** Name both producers in the doc-block. Pin with a drift test in the family of
`TrustKeyDocumentationDriftTest` if one can read that doc-block cheaply.

### E82 — three-way drift on what a `settings.json` `theme` actually breaks

**Recorded 2026-08-22 by the round-42 lane-a implementer.** Severity: low, doc-only.

**What.** `LayeredSettings`' doc-block says a project `settings.json` naming `theme` makes `/theme`
"appear to do nothing at all". `docs/SETTINGS.md` refines this: the theme *repaints immediately*, then
silently reverts on the next launch — what breaks is **persistence**, not the visible command.
`SETTINGS.md` is right, because `/theme` mutates the live `Chat`. Round 42 aligned `README.md`;
**`LayeredSettings`' doc-block still carries the coarser, wrong version.**

**Step.** Align the doc-block to the persistence framing. ⚠️ Related: `docs/SETTINGS.md`'s
counterexample block still carries no PHP version on its "measured end-to-end" claim (RULE 12), and
neither do `LayeredSettings` or `Bootstrap::reportProjectTierToolRemovals()`. Nothing there is believed
version-sensitive — `fnmatch()`'s negated-class support is not new — but an undated figure is how E68
happened.

### E83 — `README.md` prose is almost entirely unpinned by tests

**Recorded 2026-08-22 by the round-42 lane-a implementer.** Severity: medium as a CLASS, low per claim.

**What.** `docs/PERMISSIONS.md` and `docs/SETTINGS.md` have `TrustKeyDocumentationDriftTest`;
`README.md` had **nothing** until round 42 added `ReadmeSettingsTierClaimTest`. That absence is exactly
why E74's false security claim survived two rounds after the source was corrected: the tree agreed with
itself in three places and disagreed in the one file a user actually reads. The new test covers the
settings-tier paragraph only. Every other README claim remains unpinned.

**Step.** Inventory the README's checkable assertions (tool roster, key bindings, config precedence,
the launch-report sample) and pin the ones with a cheap oracle. Prefer structural rules over
±N-character windows — see round 42's mutation C, which survived because any window wide enough to
reach the retraction also reached an unrelated "not".

### E84 — the autoload guard leaves stdout empty under `--output-format json`

**Recorded 2026-08-22 by the round-42 lane-b implementer.** Severity: low; pre-existing and deliberate.

**What.** `bin/sugarcrush`'s missing-autoloader guard cannot emit the JSON error document, because the
document's owner — `NonInteractive::emitErrorDocument()` — is itself behind the absent autoloader. So
`--output-format json` gets an empty stdout and a stderr line. Round 42 *pinned* the behaviour rather
than leaving it asserted in prose, but it remains a hole in the "exactly one JSON object on stdout"
contract that machine consumers are told to rely on.

**Step.** Decide whether the guard should hand-roll a minimal JSON document (no autoloader needed —
`json_encode` is core) or whether the contract should document the exception. Prefer the former: a
consumer parsing stdout cannot distinguish "empty because the binary died early" from "empty because
there was nothing to say".

### E85 — `SkillRegistry::getForPaths()` translates `**` with three hand-rolled `str_replace` calls

**Recorded 2026-08-22 by the round-42 lane-b implementer.** Severity: medium; pre-existing.

**What.** Globstar support is three textual rewrites (`/**/ → /*/`, `/** → /*`, `/** → ''`) rather than
a real glob translation. A leading `**` with no slash — `**/*.php`, the form most people write first —
is not among the three, so it does not match what the author intends. It also weakens the *fixture*
half of every path-scoping test that uses such a pattern.

**Step.** Replace with a real translation (pattern → regex) or state the supported subset in the
doc-block and refuse the rest loudly. ⚠️ Whatever lands must be mutation-tested against a pattern
starting with `**`, since that is the case the current code silently mishandles.

### E86 — the MCP failure notice goes to `error_log()`, whose destination the operator's ini decides

**Recorded 2026-08-22 by the round-42 lane-b implementer.** Severity: medium; **functionality**.

**What.** `Bootstrap::tools()`'s McpClient failure path uses `error_log()`. On a box with `error_log`
pointed at a file, the "MCP config could not be fully started" notice reaches **neither** the terminal
nor the transcript — the user gets a silently reduced tool set. The call site's own comment argues for
assertability; it never weighed where the message actually lands.

**Step.** Route it through `Bootstrap::warnPermissionConfigInTranscript()`, which is precisely the seam
for "the session can no longer do something" — the rule round 42 used to move fifteen other warnings.
This one qualifies plainly: tools are missing.

### E87 — the `Grep`/`Glob` margin over the nudge ceiling is thin and is not a fixed relationship

**Recorded 2026-08-22 by the round-42 lane-b implementer.** Severity: low, but it is a decision nobody
has made.

**What.** E78 tied the shipped caps to `SkillPathNudge::maxBytes()` (2,636) and measured a 3.1x margin
for `Grep`/`Glob` (65,536 cap / 8,192 budget). That margin is not structural: `maxBytes()` is
`MAX_ENTRIES * (MAX_ENTRY_BYTES + 1) + …`, so raising `MAX_ENTRIES` from 8 to 20 takes it 2,636 → 6,248
and **reds the new ceiling guard without anyone touching a cap**. That is the guard doing its job; the
unmade decision is what the resolution should be.

**Step.** Decide now, while the arithmetic is fresh: either raise the caps in step with `MAX_ENTRIES`,
or express the budget as a multiple of `maxBytes()` so the relationship cannot drift. Record which, and
why, next to the constant.

### E88 — `App\SelectPaneMsg` is a dormant seam with no producer

**Recorded 2026-08-22 by the round-42 lane-c implementer.** Severity: low. **Kept per the never-remove
rule; recorded so it is not rediscovered as dead code.**

**What.** `App::update()` answers `SelectPaneMsg` with `withPane($msg->pane)`, and
`App::delegateToChat()` passes Chat's Cmd through to `Program` — so a Cmd from `Chat::selectPane()`
**would** reach the host. But `grep -c 'new SelectPaneMsg' src/ bin/` is **0**; the five textual hits
are the class definition and two match arms. The wiring exists on both ends with nothing in between.
Round 42 documented it in the docblock and pinned it as *deliberately* dormant with
`testNothingInSrcConstructsASelectPaneMsg()`, so wiring it later is a visible, deliberate act.

**Step.** Decide whether pane clicks should route through it. Wiring is behavioural, not comment-only,
so it needs its own item and its own tests — do not fold it into a doc round.

### E89 — `RendererTest::testRenderWithDifferentPaneShowsCorrectLabel()` proves less than its name

**Recorded 2026-08-22 by the round-42 lane-c implementer.** Severity: low, test-quality.

**What.** It asserts pane-sensitivity only through the MenuBar's `Currently:` label, and only on an
**un-hosted** `App`. It would survive mutations M7/M8b/M9 — i.e. it does not pin the thing its name
claims. Round 42's new `HostedFrameReadsThePaneTest` covers the hosted shape, so the gap is narrowed,
but the original assertion is still weaker than it reads.

**Step.** Strengthen it to assert on the rendered pane content, or rename it to say what it actually
checks. A test whose name overstates its coverage is worse than no test, because it stops anyone
looking.

### E90 — `paths:` frontmatter now accepts a leading `**`, and no user-facing doc says so

**Recorded 2026-08-22 by the round-43 lane-a fix agent.** Severity: low, docs. **Behaviour change,
user-visible, already shipped.**

**What.** E85 (round 43) replaced `SkillRegistry::pathMatches()`'s `**` handling. A pattern starting
with `**` previously matched none of the three `str_replace` rewrites and fell through to a bare
`fnmatch()`, which reads `**/` as "some characters, then a literal slash" — so `**/*.php` did not
claim `a.php`. It does now. Three SHIPPED built-in skills are affected: `security-audit` and
`php-best-practices` both declare `paths: ["**/*.php"]`, and `phpunit-master` declares
`**/*Test.php`. All three previously stayed silent on root-level files and now fire on them.

`README.md` and `docs/SETTINGS.md` were lane `c`'s files in round 43, so lane `a` could not add the
note without an out-of-lane edit.

**Step.** One sentence under the skills-frontmatter documentation: `paths:` globs are `fnmatch`
semantics with `**` meaning zero-or-more directory levels at any position, including the first, and
a single `*` crossing `/` (no `FNM_PATHNAME`). Mention that skills scoped with a leading `**` began
firing on tree-root files as of E85.

### E91 — `TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES` carries no pointer to the nudge ceiling it constrains

**Recorded 2026-08-22 by the round-43 lane-a fix agent.** Severity: low, comment-only.

**What.** E87 decided a 2.0x margin between the tightest shipped tool output cap and
`SkillPathNudge::maxBytes()`, and recorded it in `SkillPathNudge` and in
`SkillPathScopingWiringTest`. The other half of the relationship is
`TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES` (65,536), which is where someone lowering a cap will
actually be standing, and it says nothing. `SkillPathNudge::smallestUnclippedCallerCap()` exists
precisely to be cited from there. Not lane `a`'s file in round 43.

**Step.** A short paragraph on `DEFAULT_MAX_OUTPUT_BYTES` pointing at
`SkillPathNudge::smallestUnclippedCallerCap()` and at the margin guard, so the loop closes for a
reader who arrives at the cap first.

### E92 — ~~the `paths:` translation still does not implement POSIX character classes~~ FIXED in round 43; the escaped-`]` half remains

**Recorded and then superseded 2026-08-22 by the round-43 lane-a fix agent.**

**Recorded as** "low severity, strictly no worse than before E85: `[[:alpha:]]` emits an
uncompilable regex and routes to `legacyPathMatch()`, so a glob combining it with `**` gets the OLD
`**` handling."

**What was actually true.** That premise was wrong, and it was wrong in the direction that matters.
The class only reached the fallback when the malformed regex it produced *also failed to compile*.
`[[:alpha:]]x` emits `#^[[:alpha:]\]x$#Ds`, which PCRE refuses — fine. But a second bracket group
supplies the missing `]`: `[[:alpha:]][!a]` emits `#^[[:alpha:]\][^a]$#Ds`, which **compiles**,
folds the second group into the first class, and answers false for `ab` where `fnmatch()` answers
true. A silently wrong match with no fallback under it. Found by widening the differential fuzz's own
pattern alphabet to include `[[:alpha:]]`; four seeds x 200,000 trials each reported 12-18 such
narrowings.

**Fixed** in round 43: `compilePathPattern()`'s terminator scan now skips `[:...:]`, so POSIX classes
are translated (PCRE spells them identically to libc) rather than routed anywhere. Pinned by
`SkillPathPatternTest::testAPosixClassIsTranslatedRatherThanRoutedToTheFallback()` and by the grid
row `[[:alpha:]][!a]`.

**What is left.** One uncompilable shape remains **deliberately**: a backslash-escaped `]` inside a
class (`a[\]]b`). The bracket scan is not escape-aware, so the body arrives as the fragment `\`;
left alone the regex will not compile and the pattern routes to `legacyPathMatch()`, which reads it
correctly. Making it compile would make it compile *wrong* — the same mistake the POSIX scan was
making. See `SkillRegistry::compileClassBody()`'s doc-block.

**Step, if anyone wants that last shape too.** Make the bracket scan escape-aware. **Whoever does it
must find a NEW uncompilable input first**, or `legacyPathMatch()` stops being reachable, the
M6-class mutations (fallback → `return false`, fallback → bare `fnmatch()`) go back to surviving, and
the never-remove rule leaves an unpinnable method behind. The 46x54 characterisation grid and the
four-seed differential fuzz described in `SkillRegistry::pathMatches()` are the harness to prove no
narrowing.

### E93 — a `preg_match()` backtrack-limit hit is silently absorbed by the fallback

**Recorded 2026-08-22 by the round-43 lane-a fix agent.** Severity: low, observability.

**What.** `SkillRegistry::pathMatches()` treats `preg_match() === false` as "route to
`legacyPathMatch()`", which is right for an uncompilable pattern and silent for a
`pcre.backtrack_limit` exhaustion. In that case a tool call quietly answers with the *old* predicate
and nothing is logged. Measured as theoretical today: the deliberately pathological case (three
globstars against a 60-segment non-matching path) runs 2,000 iterations in 0.0004s on PHP 8.3.6,
because the leading literal anchors the match before PCRE can backtrack.

**Step.** Distinguish the two causes — `preg_last_error()` is `PREG_BACKTRACK_LIMIT_ERROR` for one
and `PREG_INTERNAL_ERROR` for the other — and count or log the backtrack case if `SkillRegistry`
ever gains a logging seam. Adding a logger dependency to a pure static matcher is a bigger design
call than the finding warrants on its own.

### E94 — `README.md`'s "exactly two exceptions" to the JSON contract is now one

**Recorded 2026-08-22 by the round-43 lane-b implementer.** Severity: low, documentation-correctness.
**Not fixed here because `sugar-crush/README.md` belonged to lane `c` this round.**

**What.** `README.md` (search for `There are exactly two exceptions`) tells a machine consumer that
`--output-format json` leaves stdout empty in two cases: an `--output-format` value that is neither
`text` nor `json`, and *"a checkout with no `vendor/autoload.php`: that exits 2 with an empty stdout,
because the class that owns the JSON document shape is precisely the one that could not be loaded, and
hand-rolling a second copy of the shape in `bin/sugarcrush` to cover it would be the drift that having
one definition prevents."* E84 (round 43) closed that second case — the guard now emits
`{"result":null,"error":{"type":"installation","message":"sugarcrush: cannot find composer autoload.php"}}`
and a newline, still at exit 2 — so the paragraph is now false, and it is false in the direction that
matters: it tells a consumer not to bother parsing stdout on the one failure where parsing now works.
Nothing asserts that sentence, so no test went red — `grep -rn 'exactly two exceptions'` hits `README.md`
and nothing else, which is why this is recorded rather than caught.

**Step.** Rewrite the paragraph to one exception (the unimplemented `--output-format` value), state that
the autoload guard hand-rolls the document and why the duplication is deliberate, and add the
`installation` `error.type` to the exit-code table's row for `2` (line beginning `| \`2\` |`). Consider
pinning the claim with a test the way `ReadmeSettingsTierClaimTest` pins its own, so the next divergence
reds instead of waiting for a reader.

### E95 — the MCP start-then-throw diagnostic now prints one unowned line into the suite's own output

**Recorded 2026-08-22 by the round-43 lane-b implementer.** Severity: cosmetic, test-hygiene. **Left as
it is, deliberately; recorded so it is not rediscovered as an accident.**

**What.** E86 routed `Bootstrap::mcpClient()`'s start-then-throw notice onto
`warnPermissionConfigInTranscript()` in addition to its existing `error_log()`. That seam's stderr half
is a `fwrite(STDERR, …)` in `Bootstrap::warnPermissionConfig()`, which no ini setting can redirect, so
`McpToolWiringTest::testAClientWhoseConfigThrewPartWayThroughIsStillReachableByTheShutdownSeam()` — which
runs in-process and points `error_log` at a file precisely to stay quiet — now prints exactly one line
(`sugarcrush: MCP tools from …/.mcp.json are incomplete…`) into the suite's output. MEASURED on the full
run at PHP 8.3.6: one line, and the suite already tolerates a comparable one from the workflow-tier
refusal test.

**Step.** Either accept it permanently, or quiet that one test by pre-seeding
`Bootstrap::$reportedPermissionConfigWarnings` by reflection — which works because the seam records the
transcript row BEFORE delegating for stderr, but couples the test to a private map whose purpose is
unrelated and stops working silently the day the message is reworded. The reasoning is written up on that
test's doc-block; the line's CONTENT is asserted by
`testAPartlyStartedMcpConfigReachesTheTranscriptAndNotOnlyTheErrorLog()`, which uses a child process.

### E96 — `ParallelToolCallsTest::testACompletedGroupLeavesNoPayloadFilesBehind` globs the shared `/tmp`

**Recorded 2026-08-22 by the round-43 lane-b implementer.** Severity: low, test-isolation. **Not fixed
here — `tests/Integration/ParallelToolCallsTest.php` was outside lane `b`'s file split.**

**What.** Its `runtimeIpcFiles()` helper is `glob(sys_get_temp_dir() . '/sc_runtime_tool_*')`, i.e. a
before/after snapshot of a directory every concurrent process on the box shares. Lane `b`'s round-43
baseline run failed on it with one extra entry (`/tmp/sc_runtime_tool_e79c09f5fbbc8d5b.bin`) created by a
sibling lane's suite between the two snapshots; the same test passed in isolation moments later. It is a
false red under any parallel or multi-checkout run, including two developers on one box.

**Step.** Scope the sweep to files this process created — the runner already knows its own pid — or point
`ToolIpcFiles` at a per-run subdirectory under `sys_get_temp_dir()` and glob that. Do not "fix" it by
widening the assertion; the leak-detection is the point.

### E97 — the "fourteen call sites" seam count is stale in three places outside `Bootstrap.php`

**Recorded 2026-08-22 by the round-43 lane-b fix agent**, from that round's review. Severity: low,
documentation-correctness. **Not fixed here — `sugar-crush/src/Chat.php` and
`sugar-crush/tests/Cli/BootstrapLaunchNoticeRoutingTest.php` were outside lane `b`'s file split.**

**What.** The verified number of `self::warnPermissionConfigInTranscript(` CALL sites in
`sugar-crush/src/Cli/Bootstrap.php` is **16** — counted with `token_get_all()` and a `::`/`(` adjacency
test, not `grep`, so doc-comment mentions of the name are excluded. E86 (round 43) corrected the count
inside `Bootstrap.php` itself and deliberately left the other three, which still say fourteen/fifteen:

- `sugar-crush/src/Chat.php`, `Chat::withLaunchNotices()`'s doc-block — the paragraph opening
  `FOURTEEN OF {@see \SugarCraft\Crush\Cli\Bootstrap}'S LAUNCH-WARNING CALL`.
- `sugar-crush/tests/Cli/BootstrapLaunchNoticeRoutingTest.php`, class doc-block — "the guard for the
  other fourteen" and "the retention summary onto the seam as the fifteenth call site".
- The same file, on the method whose doc-block says "a transcript that also carries fourteen".

**Step.** Set all three to 16 in one edit with the other two, and say in each how the number is obtained
(the token scan above), so the next reader re-derives it in one command instead of grepping a name that
also appears 15 times in prose. Better still, assert it: a small test that token-scans `Bootstrap.php`
and compares the count against a constant these doc-blocks cite would make the next drift red rather
than merely wrong.

### E98 — `README.md` has a THIRD spot E84 falsified, beyond the two E94 names

**Recorded 2026-08-22 by the round-43 lane-b fix agent**, from that round's review. Severity: low,
documentation-correctness. **Not fixed here — `sugar-crush/README.md` belonged to lane `c`.** Fold this
into E94 before touching that file, or E94 will be closed with the file still wrong.

**What.** E94 names two spots: the `There are exactly two exceptions` paragraph (README.md, and confirmed
the only file under `sugar-crush/` carrying that phrase) and the exit-code table's row for `2`. There is
a third: the sentence enumerating the `error.type` values, which reads *"`usage` and
`provider_configuration` are both `2`, `backend` and `encoding` are both `1`"* and omits `installation`
entirely. The JSON schema block a few lines above it lists the same four types in its `"type":` union and
omits `installation` too.

**Step.** Add `installation` to both the union in the schema block and the exit-code sentence, mapping it
to `2`, alongside E94's two edits. `NonInteractive::emitErrorDocument()`'s doc-block already carries the
full five-type table and is the source to copy from.

### E99 — `SkillRegistry::$compiledPathPatterns` is an unbounded static cache

**Recorded 2026-08-22 by the round-43 lane-a fix agent** (reported, not filed by the lane). Severity: low.

**What.** E85's translation memoises compiled patterns in a `static` array keyed by the raw pattern, for
the lifetime of the process. Bounded in practice by the number of distinct `paths:` globs across
installed skills — the doc-block says so — but nothing enforces it, and a caller that fabricated
patterns per request would grow it without limit. Round 43 at least pinned that the cache exists, with
`testTheCompiledPatternIsCachedUnderItsRawPattern()`.

**Step.** No action today; the entry exists so a future dynamic-pattern feature (a user-supplied
`paths:` filter, a `/skill` command that accepts a glob) does not discover this the hard way. If one
lands, cap the cache or key it by a bounded identity.

### E100 — the gain classifier's cause-discrimination has no isolating in-suite mutation

**Recorded 2026-08-22 by the round-43 lane-a fix agent** (review finding F5, residual). Severity: low,
test-quality.

**What.** The E85 grid's gain classifier now attributes each newly-matching pair by CAUSE — it repairs
the named hole and requires the old predicate to claim the path once the hole is gone — rather than by
pattern prefix, and `**/a?c` is in the grid to make that falsifiable. But the discrimination could only
be demonstrated **offline**: under a `?` → `.*` widening, `**/a?c` ← `a/b/c` is a gain the prefix
classifier labels "leading globstar" and the cause classifier rejects — yet the same mutation also reds
`testTheShippedMatcherReturnsExactlyTheCharacterisedTable`, so no in-suite mutation isolates the
classifier alone. The offline table is in round 43's lane-a report.

**Step.** A future round wanting an isolating mutation needs a widening that changes only a
`**`-prefixed row. Until then the classifier is correct-by-inspection and pinned only jointly.

### E101 — `nudgeSpendRoster()` discovers nudge-budget spenders by regex over each tool's source

**Recorded 2026-08-22 by the round-43 lane-a fix agent** (review finding F3, residual). Severity: low.

**What.** The E87 roster finds the tools that spend a nudge budget by matching
`intdiv($this-><cap>, <n>|CALLER_BUDGET_DIVISOR)` over each tool's source text. A hypothetical fourth
tool that spent a nudge budget **without** holding a `skillNudge` property would be invisible to the
roster. Narrow, because the three-name roster assertion catches a tool joining or leaving.

**Step.** Widen the discovery to the property rather than the arithmetic, or move the roster to a
`public const` on each tool. Not done in round 43 because `Bootstrap.php`'s tool construction was
lane b's file. Related: E91.

### E102 — two integration tests now spawn ~28 child PHP processes between them

**Recorded 2026-08-22 by the round-43 lane-b fix agent** (reported, not filed by the lane). Severity:
low, test-runtime. **Measured, and cheap today.**

**What.** `BinSugarcrushAutoloadGuardTest` spawns roughly 26 children (17 differential-table rows, 9
`nonJsonInvocations` rows, plus singletons); file time **1.48 s** on PHP 8.3.6, because each child dies
inside the guard's IIFE. `McpToolWiringTest` now spawns **two** children with live MCP fixture servers
via the shared `launchChatInChild()` helper; file time **3.29 s → 3.36 s**. Neither is a problem now, but
the differential table is the natural place to add a row, and every row is another process.

**Step.** If it ever matters: fold the differential comparison into one child that reads a table off
stdin and answers all rows, rather than one child per row. A **third** caller of `launchChatInChild()`
should get a harness that starts one child and asks it several questions.

### E103 — `Bootstrap::reportProjectTierToolRemovals()` still spells the stale glob length

**Recorded 2026-08-22 by the round-43 lane-c fix agent.** Severity: low, doc-only. **Already has a
failing-on-fix guard pointing at it.**

**What.** The paragraph "THE RESTRICTION THE BACKLOG PROPOSED WAS NOT TAKEN" in
`src/Cli/Bootstrap.php` says "the eight-character version". The correct figure for `[!B]*` is **five**
(E74/round 42 established that "eight characters" is a number nothing produces). Not fixed in round 43
because `Bootstrap.php` was lane b's file.

**Step.** A three-part rewrite, **not** a number swap — the point of the sentence is that the value
names none of the ten tools it removes. ⚠️ `GlobFigureDriftTest::testTheSettingsPageNamesExactlyTheSourceFilesStillCarryingTheStaleFigure()`
asserts the census equals exactly `['src/Cli/Bootstrap.php' => 1]`, so fixing this WILL red that test —
by design. `docs/SETTINGS.md`'s "is the one remaining site" sentence must move in the same commit.

### E104 — no test pins any launch-report line in `Bootstrap` other than the `disabledTools` one

**Recorded 2026-08-22 by the round-43 lane-c fix agent.** Severity: low.

**What.** Round 43 anchored the README's launch-report sample by reading three literals out of
`src/Cli/Bootstrap.php` (the `sprintf` format, the `'leaving: '` prefix, and `warnPermissionConfig()`'s
stderr envelope). Every **other** `sprintf()` in `Bootstrap` that reaches stderr or the transcript has
exactly the non-coverage that finding established for this one.

**Step.** Extract the formats to `public const` on `Bootstrap` (lane-b territory) so README and
transcript tests can reference them **by name** instead of by source-text regex — then drop
`bootstrapMethodSource()`/`soleMatch()` from `ReadmeRosterDriftTest`. Related: E101.

### E105 — the E81 door census catches a new KEY, never a new DOOR

**Recorded 2026-08-22 by the round-43 lane-c fix agent.** Severity: low, and it is a **stated limit**,
not an oversight.

**What.** `ConfigWriteProducerDocumentationDriftTest` walks the token stream for `onConfigChange`
invocations and pins the *keys* written. A fifth user-facing route into
`Chat::selectPaletteProvider()` would leave every assertion green while the enumeration in
`LayeredSettings`' doc-block went stale. The routes are ordinary private-method calls; there is no cheap
oracle for "every entry point into this method". Stated in the test's own doc-block.

**Step.** No cheap fix. If `Chat` ever grows a command dispatch table, census that instead.

### E106 — `Chat::withOnConfigChange()`'s doc-block repeats the `/model` omission E81 just fixed

**Recorded 2026-08-22 by the round-43 lane-c fix agent.** Severity: low, doc-only. **One line.**

**What.** It still says the callback fires on "the Switch Model/Switch Theme palette actions (or
`/theme`)" — the identical omission of `/model` that E81 corrected one file over in
`LayeredSettings`. `src/Chat.php` was not lane c's file this round.

**Step.** One-line fix, and it now has a pinning pattern to copy:
`testTheReadmeCounterfactualCreditsTheSlashCommandAndNotThePaletteAlone`.

### E107 — eight of round 43's lane-c review mutations were never verified by anyone

**Recorded 2026-08-22 by the round-43 lane-c fix agent.** Severity: low, process.

**What.** Reviewer mutations MD, ME, MF, MH, MI, MJ, MK, ML (subcommand fence, layer swap,
`dont-ask`, launch-sample survivor and order, heading rename, added subcommand,
`Bootstrap::tools()` minus `Grep`) were claimed but not re-exercised by the fix agent. The neighbouring
ones **were** re-run and behaved as claimed — X7 (README drops `Grep`), X2/X3 (heading and roster
uniqueness) — and the assertions behind the unverified set are `assertSame` over sorted arrays derived
from `src/`, which is the shape that does work. Low risk, still unmeasured.

**Step.** Re-run the eight when anything in `ReadmeRosterDriftTest` next changes.

### E108 — `GlobFigureDriftTest`'s stale-figure census has a hand-written connector class

**Recorded 2026-08-22 by the round-43 lane-c fix agent.** Severity: low, test-quality.

**What.** The census matches `/eight[- ]character/i`. The number **word** is derived from `word(8)` so it
moves with the glob, but the connector class `[- ]` is hand-written: `eight‑character` with a
non-breaking hyphen, or "eight characters" split across a doc-block line in a way the paragraph
normaliser did not collapse, slips past.

**Step.** Normalise Unicode dashes (and collapse intra-paragraph line breaks) before matching. Cheap.

### E109 — the launch-sample regex accepts any non-space path

**Recorded 2026-08-22 by the round-43 lane-c fix agent.** Severity: low. **Deliberate; recorded so
nobody reads the guard as stronger than it is.**

**What.** `ReadmeRosterDriftTest`'s launch-sample anchor ends with `(?=\s|$)` but spells the source path
`\S+`, so a README sample with a nonsense-but-nonspace path (`xxx`) still matches. Pinning the path
shape would mean pinning an example value, which is why it was left.

**Step.** None unless the sample's path becomes load-bearing.

### E110 — the README Keys table is unpinned row by row, deliberately

**Recorded 2026-08-22 by the round-43 lane-c fix agent.** Severity: low. Unchanged from the
implementer's own deferral.

**What.** README spells chords for humans (`Up`, `Page Up`, `Esc Esc`); `KeyBindingRegistry` spells them
for the screen (`up-arrow`, `PgUp`). Any mapping between the two is a translation table that would
itself go stale — the guard would become the thing needing a guard.

**Step.** Give `KeyBinding` a canonical machine id per chord and match on that. Until then the table
stays prose. Related: the `keybindings` L item, still DEFERred.

### E111 — five `docs/SETTINGS.md` claim families still have no cheap oracle

**Recorded 2026-08-22 by the round-43 lane-c fix agent.** Severity: low, and it is the honest residue of
E83 rather than a defect.

**What.** The `--flag` list, the `$SUGARCRUSH_*` env roster, the exit-code table, the twelve built-in
skills and the six agent presets all still lack a cheap oracle. The compaction thresholds ARE real
constants, but too volatile to pin without building a change-detector, which is a different tool.

**Step.** Take them one at a time as each acquires a machine-readable source. The roster and preset
lists are the two most likely to become checkable, since both are already enumerated in `src/`.

### E112 — `bin/sugarcrush` carries a comment saying README.md is still wrong; it is not, as of round 44

**Recorded 2026-08-22 by the round-44 lane-b implementer.** Severity: low, comment-correctness.
**Not fixed at record time — `bin/sugarcrush` was read-only for that lane** (it is item 1's SOURCE, not
its target). **CLOSED in the same round by the lane-b fix agent**, which was not under that restriction:
the last two sentences were rewritten in the three-part form, keeping the load-bearing half and adding
the pointer at `ReadmeJsonErrorContractDriftTest` as the README end's guard.

**What.** The `$args->usageError` block in `bin/sugarcrush` ends with *"README.md's list still names both;
correcting it is lane c's file this round and is recorded as a deferred finding."* Round 44 lane b did
correct it: the "exactly two exceptions" paragraph now names one, and the retraction is a blockquote.
So the sentence describes a state that no longer exists and points a reader at a defect they will not
find. The rest of that comment — that an unimplemented `--output-format` VALUE is the one remaining
exception, and why — is correct and should be kept.

**Step.** Rewrite the last two sentences of that block in the three-part form: what it said, that
README.md was corrected in round 44 (commit `4547d07a`), and that the "this is now the ONLY exception"
claim still earns its place because it is the load-bearing half. `ReadmeJsonErrorContractDriftTest`
pins the README end of it; nothing pins this comment, and nothing needs to.

### E113 — `ReadmeJsonErrorContractDriftTest` derives error types from two files by two different scans

**Recorded 2026-08-22 by the round-44 lane-b implementer.** Severity: low, test-design. **Deliberate;
recorded so the asymmetry is not mistaken for an oversight.**

**What.** The test reads `src/Cli/NonInteractive.php` for `emitErrorDocument()` call sites (arguments
split from the token stream, exit code taken from the `return self::EXIT_*` closing the same block) and
`bin/sugarcrush` for a literal `'type' => …` pair plus the `exit(<int>)` after it. Two scans, because the
guard is a plain script with no class to reflect on — which is the entire point of the guard. Both go
red rather than skipping on anything they cannot parse.

The asymmetry that is worth naming: the `NonInteractive` scan finds ALL types by construction, while the
guard scan finds THE FIRST `'type' => <literal>` and stops. A second hand-rolled document in
`bin/sugarcrush` would be invisible to it. There is no second one today and no reason to expect one — the
guard exists precisely because that path can load nothing — but a `--version` fast path or a second
pre-autoload guard would reopen it.

**Step.** If a second pre-autoload document is ever added, make the guard scan collect all pairs and
assert the set, the way the `NonInteractive` side already does. Related: E94/E98.

**CLOSED in the same round by the lane-b fix agent, and by removing the asymmetry rather than by
documenting it.** The reviewer's F1 showed the two-file window was the defect, not the two scans: a
third producer (`src/Cli/Subcommands.php`) emitted two error types neither scan could see. The
derivation now reads ALL of `src/` and `bin/` in one pass, in three shapes — an `emitErrorDocument()`
call, an `'error' => ['type' => …]` array literal, and a raw JSON envelope string — so the guard is
found the same way every other producer is and "the first `'type' =>` and stop" is gone. The two
unbounded scans this entry did not name (`$message = '<literal>'` and `exit(<int>)`, both first-match
over the whole script) are bounded to the enclosing function, and a second `$message` literal inside the
guard now reds rather than being silently ignored. VERIFIED by mutation: a decoy `$message` placed
earlier in the file and outside the guard is read by the old whole-file scan (proved standalone) and
correctly ignored by the bounded one.

### E114 — the round-44 lane scratchpad was shared between lanes and one lane's suite output overwrote another's

**Recorded 2026-08-22 by the round-44 lane-b implementer.** Severity: process, not code.

**What.** All three round-44 lanes were given the same scratchpad path
(`/tmp/claude-1000/-home-sites-sugarcraft/<session>/scratchpad`), so a baseline written to
`scratchpad/baseline.txt` by lane b and by lane c landed in one file. Lane b's baseline was recoverable
only because the two summaries happened to append rather than truncate; a truncating write would have
silently handed one lane the other's figures, and the standing rules make every later figure depend on
that baseline.

Second-order, and it corroborates **E96** with a live instance: lane c's run in that shared file failed
`ParallelToolCallsTest::testACompletedGroupLeavesNoPayloadFilesBehind` and
`testAThrowingSessionStateMergeCostsOnlyThatCallsMark` on `/tmp/sc_runtime_tool_*.bin` files that were
**not its own** — lane b's suite was running concurrently and owns files matching that glob. E96 predicted
exactly this; this is the first observation of it firing.

**Step.** Give each lane a private scratch subdirectory (lane b used `scratchpad/laneb/` from the point
it noticed), and fix E96 so a concurrent sibling suite cannot red a lane's run. The E96 fix is the
load-bearing one: a lane that cannot trust `rc 0` cannot trust anything downstream of it.

### E115 — `pathMatches()`'s own perf note has the generator gap that was just fixed one doc-block above it

**Recorded 2026-08-22 by the round-44 lane-b fix agent.** Severity: low, measurement-hygiene.
**Not fixed here — deliberately scoped out**, recorded so it is not mistaken for having been checked.

**What.** F5 of the round-44 lane-b review found that `$compiledPathPatterns`'s memoisation figures
quoted a ratio to two decimals from a generator whose free parameters were unstated; that doc-block was
re-taken with the generator written out, and the honest band turned out to be roughly 7x-10x rather than
8.53x-8.68x. `pathMatches()`'s FASTER, INCIDENTALLY paragraph describes its generator in exactly the
same words — *"40 paths of the form `src/` + 8 path segments + a filename"* — and therefore has exactly
the same hole: segment content sets the per-match cost, and the per-match cost is the denominator.

Two things make it less urgent than F5 was, and both are reasons to record rather than ignore. Its
figure is an old-predicate/new-predicate ratio, and BOTH arms pay the same match cost, so segment length
cancels to first order in a way it does not in a memo/no-memo ratio. And the paragraph already says
*"quote the ratio and re-take the rest"*, which is the right instinct applied to the absolute times. But
it is still a two-decimal band from an underspecified generator, and `legacyPathMatch()` is still in the
class, so re-taking it is possible rather than archaeological.

**Step.** Re-take with the segment content written down (measure at 1, 12 and 24 characters, three runs
each, PHP version stated), and quote the band across those rather than a point. Related: E85, E99.

### E116 — `not-found` and `mcp-config` are the only hyphenated `error.type` names in the contract

**Recorded 2026-08-22 by the round-44 lane-b fix agent.** Severity: low, API-consistency. **A contract
decision, not a defect — recorded because the guard now makes either answer cheap and someone should
pick one.**

**What.** The shipped `error.type` set is `backend`, `encoding`, `installation`, `mcp-config`,
`not-found`, `provider_configuration`, `usage`. Five are single words or `snake_case`; two are
`kebab-case`, and they are the two `src/Cli/Subcommands.php` added. Nothing is broken — a consumer
matches strings — but a set that spells the same idea two ways invites a consumer to guess wrong once.
The hyphens are also what the drift guard's old `[a-z_]+` alphabet could not express, which is how both
types stayed invisible to it for a round; the alphabet is now `[a-z][a-z0-9_-]*` and reds on anything it
cannot read, so a rename would be caught either way.

**Step.** Decide: rename to `not_found` / `mcp_config` for consistency with
`provider_configuration`, or keep and note in README that the set is mixed-case-by-history. If renaming,
the derivation in `ReadmeJsonErrorContractDriftTest` will red until README.md is updated to match, which
is the intended order. Pre-1.0, so no compatibility cost.

### E117 — the memoisation and cap-cost TIMING figures are documented but not pinned

**Recorded 2026-08-22 by the round-44 lane-b fix agent.** Severity: low, coverage. **Deliberate;
recorded so the gap is not read as an oversight the next time someone audits this file.**

**What.** Round 44 pinned every ROSTER-derived and CONSTANT-derived figure in
`SkillRegistry::MAX_COMPILED_PATTERNS`'s doc-block — twelve built-ins, four distinct globs, five
entries, two per skill, 256x, ~200 skills, peak 1,024, settles at 544. It deliberately did NOT pin the
timing figures (7.5x-9.7x memoisation, +1.0%-4.9% cap cost, 2.16-2.19 us per translation) or the memory
totals (3,549,976 B at 20,000 entries, 154,904 B at 1,024). Those are an allocator's and a scheduler's
answers: they move with the PHP build, with what else is running on the box, and this box has only PHP
8.3.6 while CI runs 8.3 AND 8.4. A test asserting them would red on the 8.4 leg for no defect, which is
the failure mode that gets guards deleted.

The residual is real, though, and this is what it is: those figures can rot in the doc-block exactly the
way the roster figures could before round 44, and nothing will say so. They carry their generator and
their instrument now, which makes them re-takeable by hand, and that is the whole of the mitigation.

**Step.** If this ever matters enough, the shape that would work is a tolerance-banded benchmark test
skipped unless an env var is set, so it is a tool someone runs deliberately rather than a suite member
that reds on a busy CI runner. Do NOT add an unbanded assertion. Related: E87, E99.
### E118 — promote the transcript-seam call-site count to a `public const` on `Bootstrap`

**Recorded 2026-08-22 by the round-44 lane-c implementer.** Severity: low.

**What.** `tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest::EXPECTED_CALL_SITES` (16) holds the count
of `warnPermissionConfigInTranscript(` call sites because `src/Cli/Bootstrap.php` belonged to lane `a`
the round the test was written. It belongs next to the thing it counts, as
`Bootstrap::TRANSCRIPT_SEAM_CALL_SITES`, so the nine prose sites can `{@see}` it instead of spelling an
English number word. (Four when this entry was written; round 44's review found five more and they are
all `PROSE_SITES` rows now.)

**Step.** Add the const; have the test assert the const and its own token scan agree, so
`EXPECTED_CALL_SITES` becomes a second opinion rather than the only one. Note the token scan now carries
a first-class-callable exclusion (`self::seam(...)` lexes with the paren immediately after the name on
PHP 8.3.6, so the paren test alone counts it as a call); a `grep`-shaped second opinion will disagree
with it the day such a callable exists. Sibling of **E104** (extracting
`Bootstrap`'s stderr `sprintf` formats to constants) — same file, same motive, worth one PR.

### E119 — `Bootstrap.php` still says "eleven other sources" on the skill-skip seam call

**Recorded 2026-08-22 by the round-44 lane-c implementer.** Severity: low.

**What.** E97 corrected the stale seam call-site count in `src/Chat.php` and
`tests/Cli/BootstrapLaunchNoticeRoutingTest.php` and pinned all of them with
`BootstrapTranscriptSeamCallSiteCensusTest`. A **fourth** instance is in `src/Cli/Bootstrap.php`, in the
comment above `reportSkillSkips()`'s seam call: "safe to put in a transcript that also has to carry
**eleven** other sources". The token scan counts 16 call sites, so it should read fifteen.

`Bootstrap.php` was lane `a`'s file in round 44, so lane `c` could not edit it. Everything else in that
file is correct at `8ade35dd`: the "SIXTEEN call sites now routed" comment and the "the other fifteen
call sites" comment in `mcpClient()`'s catch both check out, and both are `PROSE_SITES` rows as of round
44's fix pass (a row only READS the file, so lane ownership was never a reason to leave them uncovered).

**What this entry got wrong when it was written.** It presented itself as closing the family — "a
**fourth** instance", "everything else in that file is correct" — and the second clause is true only of
`Bootstrap.php`. `docs/SETTINGS.md` was also stale, saying **fifteen** while quoting a generator
(`grep -c 'self::warnPermissionConfigInTranscript(' src/Cli/Bootstrap.php`) that returns sixteen, so the
page contradicted itself and nothing anchored it. That was fixed and made a `PROSE_SITES` row in round
44's fix pass; this entry is now the only member of the family still open.

**Step.** Fix the one word, then add the site to `BootstrapTranscriptSeamCallSiteCensusTest::PROSE_SITES`
with `offset: 1` and **delete
`BootstrapTranscriptSeamCallSiteCensusTest::testTheKnownStaleSentenceOutsideThisLaneIsStillStale()`** in
the same commit — that test asserts the sentence is STILL stale, precisely so this hole in the census
cannot go quiet, and it will fail the moment this is fixed. Its failure message says the same thing.

### E120 — the suite prints 62 unowned `sugarcrush:` stderr lines, and only one of them was ever argued for

**Recorded 2026-08-22 by the round-44 lane-c implementer.** Severity: low, but see the measurement trap.

**What.** `McpToolWiringTest.php` alone → 1; `tests/Integration` → 2; **the full suite → 62**, in 32
distinct message shapes.

**Generator** (a figure without one is not a measurement): PHP 8.3.6, `vendor/bin/phpunit > cap.txt 2>&1`
from `sugar-crush/`, then `grep -ac 'sugarcrush:' cap.txt` for the total and
`grep -ao "sugarcrush: .\{0,40\}" cap.txt | sed 's|/tmp/[^ ]*|PATH|g' | sort | uniq -c | sort -rn` for the
breakdown. The `-a` is load-bearing (see the trap below) and the 40-char window plus the path
normalisation is what collapses per-run tmpdirs into one row.

Exact breakdown, summing to 62 with nothing dropped: session retention/pruning **9**
(`retention removed` 3, plus `stale` 2 / `ancient` / `gone` / `older` / `tenDaysOld` 1 each),
`provider …` **9** (three spellings), the one-shot different-backend refusal **7**,
`permissionRules*` **7** (three spellings: `… in <path>` 2, `is not a list of rules` 1, `[N] …` 4),
`no prompt given` **6**, refused project hook files (`… was NOT loaded`) **6**,
`trustedProjectHooks*` **3**, `disabledTools` cut-tool reports **2**, `permissionMode in …` **2**,
`ignoring …` **2**, skipped skill files **2**, and **7** singletons — E95's MCP line,
`piped stdin exceeds 10MB cap`, `agent presets unavailable`, `no provider configured`,
`allowedTools/disabledTools left no tools`, `unrecognized option: --bogus`, and
`--root /no/such/dir: no such directory`.

Rounds 43 and 44 both reasoned about E95's MCP line as though it were nearly the suite's only unowned
stderr output — round 43 accepted it partly because "one diagnostic line is what this suite already
tolerates elsewhere (the workflow-tier refusal prints one too)", naming one sibling where there are 61.
The decision survives the correction and the rewritten doc-block on
`McpToolWiringTest::testAClientWhoseConfigThrewPartWayThroughIsStillReachableByTheShutdownSeam()` now
argues it from 62 rather than from 1. What does not survive is the idea that anyone would notice a new
line: with no baseline, 63 looks exactly like 62.

**🔴 THE MEASUREMENT TRAP, recorded because it burned an hour and will recur.** The first take of the
full-suite figure came back **0**, and it was very nearly written into two doc-blocks as a finding
("a diagnostic that vanishes when the suite grows"). It was a broken harness: PHPUnit's captured output
contains control bytes, `grep` classifies the file as binary, and **`grep -c` then prints nothing at all
and exits 1** — indistinguishable at a glance from a real zero. `grep -a` gives 62. A second harness in
the same round failed the same way, reporting 0 PHP children for a file that spawns 33, because it
wrapped `php` on `PATH` while the spawns use `PHP_BINARY`. Both were caught only by running a
known-answer control through the same command. **Do that first, always.**

**Step.** Record the per-warning roster as a checked-in figure (with its generator: the `grep -a`
command, PHP version, and full-suite scope) so a 63rd line is visible. Then triage per warning — several
are real diagnostics whose own tests assert they were emitted, so silencing is the wrong default.

### E121 — the shared-`/tmp` before/after census: run, negative, and its residual

**Recorded 2026-08-22 by the round-44 lane-c implementer.** Severity: informational.

**What.** Two leak detectors have now been converted from a before/after `glob()` over the shared temp
directory to `ToolIpcFiles`' identity ledger, one round apart and each after firing on a sibling lane's
files: `ChatTest` (E63, round 38) and `ParallelToolCallsTest` (E96, round 44 — its two call sites both
failed on round 44's own baseline run). Nobody had swept for a third.

**The census.** Pattern: `glob(`/`scandir(` whose argument reaches `sys_get_temp_dir()` or a literal
`/tmp`, over `src/` and `tests/`. Deliberately **not** keyed on the `sc_runtime_tool_`/`sc_chat_tool_`
prefixes — an alphabet built from the two known cases can only rediscover them, and
`crush-hook-payload-` is a third prefix `ToolIpcFiles` sweeps.

**What this entry claimed, and what is true.** WHAT IT SAID: "run and found none … zero in `src/`; in
`tests/`, only `ChatTest`". WHAT IS TRUE NOW, re-run in round 44's fix pass
(`grep -rnE '\b(glob|scandir)\s*\(' --include=*.php src tests | grep -i tmp`, GNU grep 3.11): zero in
`src/` holds — `ToolIpcFiles::sweep()` is an age sweep over a `$dir` parameter, not a diff, and every
other `glob()` in `src/` is over a project directory. The `tests/` half was **wrong in both
directions**. It missed `tests/Support/ToolIpcFilesTest.php`'s
`glob(sys_get_temp_dir() . CHAT_PREFIX . '*')`, a second instance of the benign `assertContains`-on-its-
own-fixture shape the entry does name; harmless, but a roster that names one of two is not a roster. And
it missed a REAL one, forty lines from the fix the entry is about:
`ParallelToolCallsTest::testAChildsPayloadIsNeverReadableByAnotherUser()`'s probe tool snapshotted
`glob('/tmp/sc_runtime_tool_*')` in its constructor and read the mode of the first path that was not in
the snapshot — the same before/after diff over the same shared directory with the same prefix that E96
had just removed. Fixed in round 44's fix pass by moving attribution to the parent, which is the only
side that holds `ToolIpcFiles::reservations()`.

**Why the census could not see it, which is the transferable part.** The census is keyed on the
ARGUMENT reaching `sys_get_temp_dir()`. There, it reaches it through a constructor hop —
`new class (sys_get_temp_dir())` and then `glob($this->tmp . '/sc_runtime_tool_*')` — so no syntactic
pattern anchored on the call site can find it. The entry's residual paragraph named this class of miss
in the abstract and then stated a concrete negative that the same paragraph explains away. **A census
that admits a blind spot and then reports zero has reported nothing.**

**Residual, restated.** The census reads syntax, so it cannot see a snapshot assembled indirectly (a
helper returning a listing, a value carried through a constructor or a field, a `FilesystemIterator`
over a path built elsewhere), and it says nothing about shared directories other than the temp dir. The
defect is "before/after diff of anything concurrently written"; only the temp-dir spelling, spelled
literally at the call site, has been swept. A stronger sweep would key on the SHAPE — a directory
listing captured into a variable before an action and compared after it — which is not a grep.

**Step.** Re-run the census when a new dispatcher or payload prefix appears; widen it to
`FilesystemIterator`/`DirectoryIterator` if either ever shows up in a test; and prefer the shape-keyed
sweep above to the argument-keyed one, since the one real instance it missed was missed on the
argument.

### E122 — the E81 four-doors guard has the same "satisfied by its own retraction" hole, currently masked by luck

**Recorded 2026-08-22 by the round-44 lane-c fix pass.** Severity: low, but it is a guard that can stop
guarding without anything going red.

**What.** `ConfigWriteProducerDocumentationDriftTest::testOneParagraphNamesAllFourDoors()` searches EVERY
paragraph of `LayeredSettings`' class doc-block (and of `docs/SETTINGS.md`) for one that names all four
doors. A retraction written in the repo's three-part form quotes the wording that omitted a door and then
names the missing door in its correction, so it can name the full roster BY ITSELF — at which point the
guard is satisfied by the apology and the live enumeration is free to go stale again. That is exactly
what happened to the sibling guard this file was copied into
(`ChatConfigChangeDoorsDocumentationDriftTest`, E106): both of its doc-blocks' retractions scored 4/4 and
reverting the live enumeration left all six tests green (round 44 review, mutations D1/D2). Fixed there
by excluding retraction paragraphs from the search and asserting the exclusion removes exactly one.

**Why it does not fire here today, measured** (`php -r` over the class doc-block's paragraphs, PHP 8.3.6,
this commit): `LayeredSettings`' paragraphs score `[6] 4/4` for the live enumeration and `[7] 2/4` for the
retraction, which names "Switch Model" and `/model` and neither theme door. So the live paragraph is the
only one that can satisfy the guard — **by accident of how that retraction happens to be worded**, not by
design. Reword paragraph 7 to mention `/theme` while correcting it and the guard silently goes hollow.

**Step.** Port the fix: give the E81 file the same `isRetraction()` exclusion (match `RETRACTED`, plus
`WHAT IT SAID`/`WHAT THIS SAID` — that file's retraction uses the `IT` spelling and the E106 file uses
`THIS`, so both are needed), and assert the exclusion removes exactly one paragraph per document so it
cannot pass by excluding nothing or by excluding the enumeration. Not done in round 44 because
`tests/Config/` was not this lane's; the change is ~15 lines and carries its own mutation (revert the
live enumeration to the omitting form, keep the retraction, expect red).

### E123 — `docs/*.md` and `README.md` name `SUGARCRUSH_*` variables that no oracle covers

**Recorded 2026-08-22 by the supervisor from the round-44 lane-a report.** Severity: low, doc-coverage.

**What.** Round 44's `EnvRosterDriftTest` compares the `SUGARCRUSH_*` variables `src/` and `bin/`
actually read against `docs/ENVIRONMENT.md`'s **tables** — and nothing else. Measured by lane a:
`README.md` names **10** such variables and the eleven non-`ENVIRONMENT` `docs/*.md` pages name **14**,
none of them under any oracle.

**Step.** This is a design question, not a scope widening. A second oracle must distinguish a tabulated
PROMISE from a prose MENTION, because `docs/ENVIRONMENT.md` deliberately discusses
`SUGARCRUSH_TOOL_CALL_PARSER` and `SUGARCRUSH_REASONING_EFFORT` in prose **precisely because nothing
reads them** — a naive widening would red on the two sentences that exist to record that fact.

### E124 — `docs/_data/sugar-crush.json` is a fourth documentation surface outside every oracle

**Recorded 2026-08-22 by the supervisor from the round-44 lane-a report.** Severity: low.
**Monorepo-root scope — needs a supervisor decision before any lane can own it.**

**What.** `docs/_data/sugar-crush.json` + `docs/_data/sugar-crush.body.html` generate
`docs/lib/sugar-crush.html` via `tools/gen-docs.php`, and carry env names and glob claims of their own.
No `sugar-crush` oracle reads them. The generated page must never be hand-edited, so any pin has to sit
on the `_data` sources, which live outside `sugar-crush/` — outside every lane's file split to date.

**Step.** Decide whether the `_data` sources are in scope for the `sugar-crush` doc-drift guards at all.
If they are, the guard belongs beside `tools/gen-docs.php`, not in `sugar-crush/tests/`.

### E125 — the `paragraphs()` splitter exists in three independent copies

**Recorded 2026-08-22 by the supervisor from the round-44 lane-a report.** Severity: low, test-quality.

**What.** `GlobFigureDriftTest`, `ConfigWriteProducerDocumentationDriftTest` and
`ThemePersistenceFramingTest` each carry their own private paragraph splitter. Beyond the duplication
they share a defect surface: **a stale sentence inside a fenced code block or a table row is one
paragraph-unit to all three**, so a claim hidden in either is invisible to every doc-drift guard at once.

**Step.** Consolidate into one helper (`tests/Config/Support/` now exists, from `EnvReadScanner`), so the
fenced-block question is answerable in one place instead of three. Related: E108, E129.

### E126 — `SymbolCitationDriftTest` pins citations to TEST symbols only

**Recorded 2026-08-22 by the supervisor from the round-44 lane-a report.** Severity: low, and it is a
**stated limit**, not an oversight.

**What.** The new census resolves `{@see …Tests\…::method()}` in four syntactic shapes — 94 citations at
merge — but only where the TARGET is a test symbol. `{@see self::foo()}`, `{@see Foo::CONST}`,
`{@see $this->bar}` and plain production class references are far more numerous, have more shapes, and
none is measured.

**Step.** The test subset was chosen because `src/` cannot autoload `tests/`, so nothing but prose links
them — that is what makes the test half both cheap and uniquely rot-prone. The production half is a
separate, larger instrument. The doc-block says so; this entry exists so the limit is not mistaken for
coverage.

### E127 — `EnvReadScanner`'s S3 forwarding resolves a callee by bare method name within one file

**Recorded 2026-08-22 by the supervisor from the round-44 lane-a report.** Severity: low.
**Inherited and re-confirmed in round 44, not introduced by it.**

**What.** The forwarding rule matches the callee's bare method name inside a single file, so two
same-named methods — a trait's and the using class's — would resolve to whichever the scanner reaches
first. No such case exists in `src/` today.

**Step.** Resolve through the declaring scope rather than the file. Local fix; worth taking the next time
the scanner is touched, not on its own.

### E128 — `tabulatedNames()` scrapes the first table column only, deliberately

**Recorded 2026-08-22 by the supervisor from the round-44 lane-a report.** Severity: low. **A decision,
recorded so a later round does not "fix" it in isolation.**

**What.** The `docs/ENVIRONMENT.md` table scrape reads column one only. Lane a declined to widen it on
purpose: the narrow scrape is the conservative direction, because a read-but-undocumented name still reds
(the code set is built from `src/`), whereas widening would turn **every prose mention inside a cell**
into a promise the code must keep.

**Step.** None, unless widening — and if it is ever widened, the documented-but-unread direction has to
be re-thought in the same commit or the guard starts reding on E123's two deliberate prose entries.

### E129 — the stale-figure retraction exemption is still semantic, not an identity test

**Recorded 2026-08-22 by the supervisor from the round-44 lane-a report.** Severity: low, **known
residual**.

**What.** Round 44 tightened the exemption materially — a paragraph must now quote the glob **and** spell
the current count to be exempt, where round 43 accepted a bare `\bfive\b` — but it remains semantic. A
paragraph that quotes `[!B]*`, says "five" for an unrelated reason, and also spells the stale figure
would be exempt without being a retraction. Measured: three exempt paragraphs in scope today, all
genuine retractions.

**Step.** None for now. The obvious alternative — a filename exemption list — is **precisely what went
stale in round 43**, when a copy was fixed and its list entry was not. The trade is taken deliberately.

### E130 — no glob / token / timing / memory claim from round 44 has been exercised on PHP 8.4

**Recorded 2026-08-22 by the supervisor.** Severity: low, coverage. Severity rises if CI's 8.4 leg reds.

**What.** Every figure in all three round-44 lanes is stated for **PHP 8.3.6**, the only interpreter on
this box; CI runs 8.3 **and** 8.4. The largest new exposure is lane a's token work —
`T_START_HEREDOC` / `T_END_HEREDOC` / `T_ENCAPSED_AND_WHITESPACE` / `T_CURLY_OPEN` /
`T_DOLLAR_OPEN_CURLY_BRACES`, with `T_END_HEREDOC` carrying the closing marker's indentation since 7.3 —
followed by lane b's `fnmatch` and allocator figures, which are deliberately unpinned (E117).

**Step.** CI's 8.4 leg is the first thing to run any of it. Read that run before trusting a round-44
token or timing figure on 8.4.

### E131 — ~~`LayeredSettings`' 66/68 `strlen` census was stale on arrival at master~~ FIXED in round 44

**Recorded and fixed 2026-08-22 by the supervisor**, at merge, from the round-44 digest.

**What.** `src/Config/LayeredSettings.php` stated that `grep -rl 'strlen(' tests --include='*.php'`
"counts 66 files and `grep -rl 'strlen'` counts 68". Both were **correct** in
`/home/sites/crush-lane-a`, where lane a measured them, and both were **wrong** at `98d59bfb` — **71 and
73** — because lanes b and c each added test files containing `strlen` while lane a was measuring.

**Fixed** by dropping the two cardinalities and keeping the generator. The paragraph's actual claim is a
NEGATIVE — that no `strlen()` in `tests/` is applied to `COUNTEREXAMPLE_GLOB` or to any spelling of
`[!B]*` — re-verified at HEAD, and it survived the merge untouched. **The totals never supported the
claim; they only looked like evidence.**

🔴 **THE TRANSFERABLE RULE: A CARDINALITY MEASURED OVER `tests/` (OR `src/`, OR `docs/`) IN ONE LANE'S
WORKTREE IS INVALIDATED BY ANY SIBLING LANE'S MERGE.** Either re-take it at merge, or do not ship a
cardinality at all — ship the generator and the claim it supports. Same family as the assertion-count
non-additivity measured at this merge (see the round-44 worklog entry).

### E132 — `exitCodeAfter()` cannot read a ternary return, so the bare-`result` guard over-approximates

**Recorded 2026-08-22 by the supervisor from the round-44 lane-b report.** Severity: low.

**What.** `testAFailureWithNoErrorObjectIsDocumentedExactlyWhenOneExists` collects every `EXIT_*` in the
enclosing function after a `result`-only document, rather than resolving which one that branch actually
takes, because `doctor`'s return is a ternary the exact walk cannot read. The over-approximation is in
the **safe** direction for the claim being pinned, and the test says so in its own comment.

**Residual.** A function emitting a bare `result` on a success path and returning non-zero from an
unrelated later branch would satisfy it wrongly.

**Step.** Teach `exitCodeAfter()` to read a ternary return.

### E133 — the payload-mode probe still globs shared `/tmp`; only attribution moved

**Recorded 2026-08-22 by the supervisor from the round-44 lane-c report.** Severity: low, flake.
**E96 is closed for the ASSERTION; this is what E96 did not close.**

**What.** Round 44 moved *attribution* to the parent (`ToolIpcFiles::reservations()`), so a foreign file
can be sighted but can no longer be read for an answer. The glob itself, and `SETTLE_SECONDS = 0.25`,
remain. **Residual:** on a box loaded badly enough that a sibling's fork+write exceeds 250 ms after the
first foreign sighting, `assertCount(1, $mine)` sees 0 and the test **fails** rather than passing
wrongly — the right direction, still a flake.

**Step.** Closing it properly needs identity **in the child**, i.e. `Runtime::executeConcurrently()`
reserving every payload name in phase 1 before forking anything — a `src/Runtime.php` change.

🔴 **RECORD THE NEGATIVE RESULT THAT RULED OUT THE CHEAP FIX:** on PHP 8.3.6 `sys_get_temp_dir()`
resolves and **caches on first use** and does NOT honour a runtime `putenv('TMPDIR=…')` — measured
directly by lane c. A test therefore cannot isolate itself into a private temp directory after the fact,
which is why attribution moved instead of the directory being narrowed.

### E134 — a mutation harness must refuse a dirty tree, not merely revert

**Recorded 2026-08-22 by the supervisor.** Severity: low, process. **Round 44 produced two independent
instances, in opposite directions.**

**What.** Lane b ran a mutation (`R4b`) that created a **directory**; `git checkout -- .` does not remove
one, and the harness's pre-flight **refused** the next three mutations rather than attributing their
verdicts to a tree still carrying someone else's change. **The refusal, not the revert, is what saved
that run.** Lane c hit the mirror image: reverting a mutation with `git checkout -- <file>` destroyed
~250 lines of its own uncommitted work in that file (redone, verified identical at 18 tests / 62
assertions).

**Step.** Make the standing harness contract explicit in every brief: a pre-flight clean check that
**refuses** rather than cleans, `git clean -fdq` alongside `git checkout -- .`, and a scratchpad backup
of any file carrying uncommitted work **before** it is mutated.

### E135 — round-44 lanes allocated overlapping backlog E-numbers, and the reports still cite the losers

**Recorded 2026-08-22 by the supervisor.** Severity: low, process. **Partially fixed at merge.**

**What.** Lane b filed E112–E114 (implementer) then E115–E117 (fix agent), while lane c independently
filed its own E112–E116 from the same base. The merge renumbered lane c's five to **E118–E122**. The
consequence is silent and durable: **report-c's prose still cites its lane-local numbers**, so its
"E112"→E118, "E113"→E119, "E114"→E120, "E115"→E121, "E116"→E122 — and every one of those numbers now
means an unrelated lane-b entry in the shipped backlog.

**Fixed at merge, for code only:** lane c had already written `E112`/`E113` into
`BootstrapTranscriptSeamCallSiteCensusTest`'s doc-blocks and failure messages, including the tripwire
that tells a future reader which entry to consult; those were renumbered in `c4a799ab`. **An in-code
citation of a backlog id is a cross-file reference the merge cannot see** — checking for one is now part
of the merge step.

**Step.** Either a supervisor-allocated number block per lane, or lane-prefixed provisional ids
(`Eb1`, `Ec1`) renumbered once at merge with the report text rewritten at the same time. E114 covers the
shared scratchpad but not this.
