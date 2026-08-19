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
- **F5 — the tmux/iTerm2 split-pane compositor.**
  `src/Tui/Renderer.php:63` (`renderWithSplit()`) and `:109`
  (`renderForCurrentEnvironment()`); the only reference to the latter outside its
  own definition is a docblock mention at `src/Renderer.php:134`, so it has
  **zero production callers**. Deferred per its own docblock pending a public
  live-output-buffer accessor on `AgentManager` (`src/Agents/AgentManager.php:329`
  describes what it needs). That accessor is Phase 1 item 1's remaining
  follow-up, and it also gates Phase 8 item 4.
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
